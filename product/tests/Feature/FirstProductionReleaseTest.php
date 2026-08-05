<?php

namespace Tests\Feature;

use App\Application\ModerationRecordService;
use App\Application\NationCreationService;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class FirstProductionReleaseTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_first_production_schema_and_ruleset_are_the_fresh_install_baseline(): void
    {
        $this->assertTrue(Schema::hasColumn('nations', 'registered_turn'));
        $this->assertTrue(Schema::hasTable('moderation_records'));
        $this->assertFalse(Schema::hasColumn('users', 'moderation_suspended_at'));
        $this->assertFalse(Schema::hasColumn('nations', 'moderation_suspended_at'));

        $published = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        $previous = RulesetVersion::query()->where('key', 'roadmap-pr22-v1')->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v1', config('hakoniwa.ruleset.key'));
        $this->assertEquals(
            config('hakoniwa.published_rulesets.roadmap-pr22-v1'),
            $previous->settings,
        );
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

    public function test_release_preflight_requires_contact_and_rejects_an_unresolved_next_turn(): void
    {
        $world = $this->lightweightWorld();
        config(['hakoniwa.community.contact_url' => null]);
        $this->artisan('hakoniwa:release:preflight')->assertFailed();

        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);
        $this->artisan('hakoniwa:release:preflight')->assertSuccessful();

        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_code' => 'test_failure',
            'failure_message' => 'test',
            'failure_context' => [],
        ]);

        $this->artisan('hakoniwa:release:preflight')
            ->expectsOutputToContain('Deploy blocked')
            ->assertFailed();
    }

    public function test_manual_and_player_facing_policy_are_available(): void
    {
        $this->withoutVite();
        config(['hakoniwa.community.contact_url' => 'https://example.test/contact']);

        $this->get('/manual')->assertOk()->assertSee('箱庭諸島２S＋マニュアル');
        $this->get('/manual/beginner')->assertOk()
            ->assertSee('食料と資源')
            ->assertSee('工業品・鉱物');
        $this->get('/manual/intermediate')->assertOk()->assertSee('ミサイル');
        $this->get('/manual/advanced')->assertOk()->assertSee('地盤沈下');
        $this->get('/community-guidelines')->assertOk()
            ->assertSee('利用ルール')
            ->assertSee('通報・異議申立て窓口を開く');

        foreach (glob(base_path('docs/manual/*.md')) ?: [] as $path) {
            $manual = file_get_contents($path);
            $this->assertIsString($manual);
            $this->assertDoesNotMatchRegularExpression('/\b(?:source|legacy|ruleset)\b/i', $manual);
        }
    }
}
