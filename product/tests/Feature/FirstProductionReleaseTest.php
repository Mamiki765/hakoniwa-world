<?php

namespace Tests\Feature;

use App\Application\ModerationRecordService;
use App\Application\NationCreationService;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FirstProductionReleaseTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

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

    public function test_release_preflight_rejects_each_unresolved_next_turn_status(): void
    {
        $world = $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        foreach ([
            TurnRun::STATUS_PENDING,
            TurnRun::STATUS_RUNNING,
            TurnRun::STATUS_FAILED,
            TurnRun::STATUS_BLOCKED,
        ] as $status) {
            $run = $this->turnRun($world, $status, false, 'a');
            $this->artisan('hakoniwa:release:preflight')
                ->expectsOutputToContain('Deploy blocked')
                ->expectsOutputToContain($status)
                ->assertFailed();
            $run->delete();
        }
    }

    public function test_release_preflight_allows_completed_or_dry_run_records(): void
    {
        $world = $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        foreach ([
            [TurnRun::STATUS_COMPLETED, false],
            [TurnRun::STATUS_RUNNING, true],
            [TurnRun::STATUS_FAILED, true],
        ] as [$status, $isDryRun]) {
            $run = $this->turnRun($world, $status, $isDryRun, 'b');
            $this->artisan('hakoniwa:release:preflight')
                ->expectsOutputToContain('release_preflight=ok')
                ->assertSuccessful();
            $run->delete();
        }
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
    }

    private function turnRun(World $world, string $status, bool $isDryRun, string $seed): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat($seed, 64),
            'source' => $isDryRun ? 'manual' : 'cron',
            'is_dry_run' => $isDryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }
}
