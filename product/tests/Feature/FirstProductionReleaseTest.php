<?php

namespace Tests\Feature;

use App\Application\ModerationRecordService;
use App\Application\NationCreationService;
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

    public function test_current_schema_and_v16_are_the_fresh_install_baseline(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);

        $this->assertTrue(Schema::hasColumn('nations', 'registered_turn'));
        $this->assertTrue(Schema::hasTable('moderation_records'));
        $this->assertFalse(Schema::hasColumn('users', 'moderation_suspended_at'));
        $this->assertFalse(Schema::hasColumn('nations', 'moderation_suspended_at'));

        $published = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v16')->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v16', config('hakoniwa.ruleset.key'));
        $this->assertSame(['hakoniwa-2s-plus-v16'], array_keys(config('hakoniwa.published_rulesets')));
        $this->assertSame(['hakoniwa-2s-plus-v16'], RulesetVersion::query()->pluck('key')->all());
        $this->assertSame($published->id, $this->lightweightWorld()->ruleset_version_id);
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

        $this->get('/manual')->assertOk()
            ->assertSee('箱庭諸島２S＋マニュアル')
            ->assertSeeInOrder([
                'href="/manual/advanced"',
                '>上級編</a>',
                'href="/manual/trading-post"',
                '>交易場</a>',
                'href="/manual/secretary"',
                '>秘書について</a>',
            ], false)
            ->assertSee('href="/credits"', false)
            ->assertSee('href="/community-guidelines"', false);
        $this->get('/manual/beginner')->assertOk()
            ->assertSee('人口と食料')
            ->assertSee('資源売却')
            ->assertSee('指定数を残して売却')
            ->assertSee('小麦は「すべて売却」を選べません')
            ->assertSee('工業品：1,000ユニット')
            ->assertSee('鉱物：1,000トン');
        $this->get('/manual/intermediate')->assertOk()
            ->assertSee('ミサイル')
            ->assertSee('領土拡張')
            ->assertSee('浅瀬全て埋め立て＋整地');
        $this->get('/manual/advanced')->assertOk()
            ->assertSee('地盤沈下')
            ->assertSee('島の破棄')
            ->assertSee('通常のKARMA下限は-10です')
            ->assertSee('Lv20では-30になります')
            ->assertSee('すぐに有効下限へ丸めず、ターンごとに1ずつ回復します');
        $this->get('/manual/trading-post')->assertOk()
            ->assertSee('<title>交易場 | 箱庭諸島２S＋</title>', false)
            ->assertSee('最高入札中の預託資金は、資金上限の使用量に含まれます')
            ->assertSee('出品中の数量も保管容量の使用量に含まれる')
            ->assertSee('入札がなければ休眠中でもキャンセルでき')
            ->assertSee('売れない超過分は破棄されます');
        $this->get('/manual/secretary')->assertOk()
            ->assertSee('<title>秘書について | 箱庭諸島２S＋</title>', false)
            ->assertSee('<h1>秘書について</h1>', false)
            ->assertSee('農業政策')
            ->assertSee('Lv1ごとに小麦生産が0.1%増えます')
            ->assertSee('農場建設が1回成功するごとに経験値を1獲得します')
            ->assertSee('特産品開発')
            ->assertSee('Lv1ごとに工場生産が0.1%増えます')
            ->assertSee('工場建設が1回成功するごとに経験値を1獲得します')
            ->assertSee('金鉱脈調査')
            ->assertSee('Lv1ごとに採掘場生産が0.1%増えます')
            ->assertSee('採掘場建設が1回成功するごとに経験値を1獲得します')
            ->assertSee('最終防衛ライン')
            ->assertSee('自領のマスへミサイルが1発到達するごとに経験値を1獲得します')
            ->assertSee('倉庫には最大50個')
            ->assertSee('装備スロットが5個')
            ->assertSee('弓カテゴリと衣服カテゴリは、それぞれカテゴリ全体で1個までです')
            ->assertSee('種類の異なるアクセサリーは同時に装備できます')
            ->assertSee('箱庭連合が指輪と次の7種類のノービス装備を期間限定で出品します')
            ->assertSee('10%の確率で、自領の地上にいる怪獣に1ダメージを与える。')
            ->assertSee('次に開始できるターンから反映')
            ->assertSee('自動の資金繰り')
            ->assertSee('Lv3の指輪を装備していれば、追加分は3億円です')
            ->assertSee('島を破棄しても、秘書と倉庫のアイテム、装備状態は保持されます。活動中の島がない間は装備効果は発生せず、再参加後に必要に応じて装備を変更できます。')
            ->assertSee('アイテムと装備状態は秘書に対して共通で、対象マスや画面ごとに別管理されません。')
            ->assertDontSee('現在活動中の島がない間は、装備を変えることはできます')
            ->assertDontSee('複数の海域や島を持っていても、装備セットは一つです');
        $this->get('/community-guidelines')->assertOk()
            ->assertSee('利用ルール')
            ->assertSee('通報・異議申立て窓口を開く');

        foreach (glob(base_path('docs/manual/*.md')) ?: [] as $path) {
            $manual = file_get_contents($path);
            $this->assertIsString($manual);
            $this->assertDoesNotMatchRegularExpression('/\b(?:source|legacy|ruleset)\b/i', $manual);
            $this->assertDoesNotMatchRegularExpression('/自爆|飛翔|とてつもない/i', $manual);
        }
        $css = file_get_contents(resource_path('css/hakoniwa.css'));
        $this->assertIsString($css);
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
    }
}
