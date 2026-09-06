<?php

namespace Tests\Feature;

use App\Application\ModerationRecordService;
use App\Application\NationCreationService;
use App\Application\Ver370RulesetUpgrade;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FirstProductionReleaseTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_current_schema_and_v21_are_the_fresh_install_baseline(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);

        $this->assertTrue(Schema::hasColumn('nations', 'registered_turn'));
        $this->assertTrue(Schema::hasColumn('nations', 'population_high_water'));
        $this->assertTrue(Schema::hasTable('moderation_records'));
        $this->assertFalse(Schema::hasColumn('users', 'moderation_suspended_at'));
        $this->assertFalse(Schema::hasColumn('nations', 'moderation_suspended_at'));

        $published = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v21')->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v21', config('hakoniwa.ruleset.key'));
        $this->assertSame(['hakoniwa-2s-plus-v21'], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame(
            ['hakoniwa-2s-plus-v19', 'hakoniwa-2s-plus-v20', 'hakoniwa-2s-plus-v21'],
            RulesetVersion::query()->orderBy('version')->pluck('key')->all(),
        );
        $this->assertSame($published->id, $this->lightweightWorld()->ruleset_version_id);
        $this->assertSame('already_current_v21', app(Ver370RulesetUpgrade::class)->run());
    }

    public function test_moderation_record_is_admin_only_and_changes_no_gameplay_state(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '記録対象島', '記録対象島主');
        $before = $nation->only(['state', 'money', 'owner_name', 'profile_comment', 'idle_counter']);

        $result = app(ModerationRecordService::class)->record(
            'policy-violation',
            'nation',
            $nation->id,
            'operator-1',
            '外部窓口から連絡を受領',
        );

        $this->assertSame($before, $nation->fresh()->only(array_keys($before)));
        $this->assertDatabaseHas('moderation_records', [
            'id' => $result['record_id'],
            'category' => 'policy-violation',
            'target_type' => 'nation',
            'target_id' => $nation->id,
            'operator_identifier' => 'operator-1',
            'summary' => '外部窓口から連絡を受領',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'moderation.recorded',
            'visibility' => 'admin',
            'nation_id' => $nation->id,
        ]);

        $public = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk()->getContent();
        $this->assertStringNotContainsString('policy-violation', $public);
        $this->assertStringNotContainsString('operator-1', $public);
        $this->assertStringNotContainsString('外部窓口から連絡を受領', $public);
    }

    public function test_release_preflight_requires_contact(): void
    {
        $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => null]);
        $this->artisan('hakoniwa:release:preflight')->assertFailed();

        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        $this->artisan('hakoniwa:release:preflight')->assertSuccessful();
    }

    #[DataProvider('unsafeNextTurnStatuses')]
    public function test_release_preflight_rejects_each_unresolved_next_turn_status(string $status): void
    {
        $world = $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);

        $this->artisan('hakoniwa:release:preflight')
            ->expectsOutputToContain('Deploy blocked')
            ->expectsOutputToContain($status)
            ->assertFailed();
    }

    /** @return array<string, array{string}> */
    public static function unsafeNextTurnStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    #[DataProvider('safeNextTurnRuns')]
    public function test_release_preflight_allows_completed_or_dry_run_records(
        string $status,
        bool $isDryRun,
    ): void {
        $world = $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('b', 64),
            'source' => $isDryRun ? 'manual' : 'cron',
            'is_dry_run' => $isDryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);

        $this->artisan('hakoniwa:release:preflight')
            ->expectsOutputToContain('release_preflight=ok')
            ->assertSuccessful();
    }

    /** @return array<string, array{string, bool}> */
    public static function safeNextTurnRuns(): array
    {
        return [
            'completed production run' => [TurnRun::STATUS_COMPLETED, false],
            'dry-run running record' => [TurnRun::STATUS_RUNNING, true],
            'dry-run failed record' => [TurnRun::STATUS_FAILED, true],
        ];
    }

    public function test_manual_and_player_facing_policy_are_available(): void
    {
        $this->withoutVite();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);

        $themePaths = ['/', '/manual', '/community-guidelines'];
        foreach ($themePaths as $path) {
            $this->get($path)->assertOk()
                ->assertSee('<html lang="ja" data-theme="system">', false);
        }
        foreach (['system' => 'system', 'light' => 'light', 'dark' => 'dark', 'wat' => 'system'] as $cookie => $expected) {
            foreach ($themePaths as $path) {
                $response = $this->withUnencryptedCookie('hakoniwa_theme', $cookie)->get($path);
                $response->assertOk()
                    ->assertSee("<html lang=\"ja\" data-theme=\"{$expected}\">", false)
                    ->assertDontSee('<html lang="ja" data-theme="wat">', false);
            }
        }

        $sections = [
            'index' => 'マニュアルの入口',
            'beginner' => 'はじめの一歩',
            'intermediate' => '土地と施設',
            'economy' => '人口と資源',
            'advanced' => 'ミサイルと怪獣',
            'disasters' => '災害と防災',
            'ships' => '港と船',
            'trading-post' => '交易場',
            'secretary' => '地上の秘書',
            'underground' => '地底の探索',
            'combat' => '育成と戦闘',
            'equipment' => '地底装備',
            'faq' => '島の状態と困ったとき',
        ];
        $navigation = [];
        foreach ($sections as $key => $label) {
            $path = $key === 'index' ? '/manual' : "/manual/{$key}";
            $navigation[] = "href=\"{$path}\"";
            $navigation[] = ">{$label}</a>";
        }
        $linkedPaths = [];
        foreach ($sections as $key => $label) {
            $path = $key === 'index' ? '/manual' : "/manual/{$key}";
            $heading = $key === 'index' ? '箱庭諸島２S＋マニュアル' : $label;
            $response = $this->get($path)->assertOk()
                ->assertSee("<title>{$label} | 箱庭諸島２S＋</title>", false)
                ->assertSee("<h1>{$heading}</h1>", false)
                ->assertSeeInOrder($navigation, false)
                ->assertSee("href=\"{$path}\" class=\"current\"", false)
                ->assertSee('class="manual-table-scroll"', false)
                ->assertSee('href="/credits"', false)
                ->assertSee('href="/community-guidelines"', false);
            $html = $response->getContent();
            $this->assertIsString($html);
            preg_match('/<main class="manual-content">(.*?)<\/main>/s', $html, $main);
            $this->assertArrayHasKey(1, $main);
            preg_match_all('/href="([^"]+)"/', $main[1], $links);
            foreach ($links[1] as $link) {
                $this->assertStringStartsWith('/', $link);
                $linkedPaths[$link] = true;
            }
        }
        foreach (array_keys($linkedPaths) as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/manual/underground')->assertSee('試練2をクリアすると……？')
            ->assertDontSee('案内人の過去を問う話')
            ->assertDontSee('5まで読むと');
        $this->get('/community-guidelines')->assertOk()
            ->assertSee('利用ルール')
            ->assertSee('通報・異議申立て窓口を開く');

        foreach (glob(base_path('docs/manual/*.md')) ?: [] as $path) {
            $manual = file_get_contents($path);
            $this->assertIsString($manual);
            $this->assertDoesNotMatchRegularExpression('/\b(?:source|legacy|ruleset)\b/i', $manual);
        }
        $css = file_get_contents(resource_path('css/hakoniwa.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('html[data-theme="light"]', $css);
        $this->assertStringContainsString('html[data-theme="dark"]', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-color-scheme: dark\)\s*\{\s*html\[data-theme="system"\]\s*\{[^}]*color-scheme: dark;/s',
            $css,
        );
        $this->assertStringNotContainsString('filter: invert(', $css);
        $this->assertStringContainsString(
            '.secretary-equipment { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr));',
            $css,
        );
        $this->assertStringContainsString(
            '.secretary-profile-hero { grid-template-columns: minmax(0, 38%) minmax(0, 1fr); grid-template-areas: "portrait summary" "biography biography";',
            $css,
        );
        $this->assertStringContainsString(
            '.secretary-equipment { grid-template-columns: repeat(2, minmax(0, 1fr)); }',
            $css,
        );
        $this->assertStringContainsString(
            '.secretary-warehouse .item-flavor { color: var(--muted); font-style: italic;',
            $css,
        );
        $this->assertStringContainsString(
            '.hud-more-grid { display: grid; grid-template-columns: minmax(0, 1.35fr) minmax(260px, 1fr);',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 820px\)\s*\{.*\.hud-more-grid \{ grid-template-columns: minmax\(0, 1fr\); \}/s',
            $css,
        );
        $manualCss = file_get_contents(resource_path('css/manual.css'));
        $this->assertIsString($manualCss);
        $this->assertStringContainsString('html[data-theme="dark"]', $manualCss);
        $this->assertMatchesRegularExpression(
            '/@media \(prefers-color-scheme: dark\)\s*\{\s*html\[data-theme="system"\]\s*\{[^}]*color-scheme: dark;/s',
            $manualCss,
        );
    }
}
