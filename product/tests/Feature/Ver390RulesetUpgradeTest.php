<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\Ver390RulesetUpgrade;
use App\Application\Ver392RulesetUpgrade;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TurnRun;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Ver390RulesetUpgradeTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const MIGRATIONS = [
        '2026_09_09_030000_add_surface_paradox_and_daily_rewards',
        '2026_09_09_040000_add_compensation_warehouse',
        '2026_09_09_050000_add_guide_conversation_topics',
        '2026_09_09_060000_add_shining_kingdom_key_balance',
    ];

    private const VER392_MIGRATION = '2026_09_12_000000_publish_v24_3_9_2_release';

    public function test_exact_v22_world_upgrades_through_v23_to_v24_without_losing_live_or_historical_state(): void
    {
        $this->returnSchemaToExact390Source();
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v22.php');
        $source = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::SOURCE_KEY)->sole();
        $sourcePayload = $source->settings;
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '三九移行国', '三九島主');
        $space = $this->surfaceMapSpace($world);
        $targetCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $queued = $this->commandItem($nation->id, $source->id, $targetCell, 1, 'queued');
        $terminal = $this->commandItem($nation->id, $source->id, $targetCell, null, 'completed');

        $sourceMonster = MonsterDefinition::query()
            ->where('ruleset_version_id', $source->id)
            ->where('key', 'inora')
            ->sole();
        $aliveMonster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $sourceMonster->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        $historicalMonster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $sourceMonster->id,
            'current_hp' => 0,
            'spawned_max_hp' => 1,
            'state' => 'killed',
            'spawned_target_turn' => 1,
            'version' => 2,
            'removal_reason' => 'defeated',
            'removed_at' => now(),
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $sourceMonster->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        $shipCell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('facility_definition_id')
            ->whereNull('owner_nation_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')
            ->firstOrFail();
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $source->id,
            'nation_id' => $nation->id,
            'map_cell_id' => $shipCell->id,
            'ship_type_key' => 'fishing',
            'current_hp' => $sourceSettings['surface_ships']['definitions']['fishing']['maximum_hp'],
            'max_hp' => $sourceSettings['surface_ships']['definitions']['fishing']['maximum_hp'],
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
        $terminalBefore = (array) DB::table('nation_command_queue_items')->find($terminal->id);
        $terminalBefore['paradox_execution_count'] = 0;
        $historicalMonsterBefore = (array) DB::table('monster_instances')->find($historicalMonster->id);
        $shipBefore = (array) DB::table('ships')->find($ship->id);
        $secretaryBefore = (array) DB::table('secretaries')->where('user_id', $user->id)->sole();
        $skillsBefore = DB::table('secretary_skills')->where('secretary_id', $secretaryBefore['id'])
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $legacyUndergroundProfileId = DB::table('underground_profiles')->insertGetId([
            'secretary_id' => $secretaryBefore['id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestRulesetId = $queued->request_ruleset_version_id;
        $requestFingerprint = $queued->request_fingerprint;

        foreach (self::MIGRATIONS as $migration) {
            $this->artisan('migrate', [
                '--path' => "database/migrations/{$migration}.php",
                '--force' => true,
                '--no-interaction' => true,
            ])->assertSuccessful();
        }

        $target = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::TARGET_KEY)->sole();
        $this->assertSame($target->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($sourcePayload, $source->fresh()->settings);
        $this->assertSame(Ver390RulesetUpgrade::SOURCE_CHECKSUM, $this->payloadChecksum($sourceSettings));
        $this->assertSame($target->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('land_clear', $queued->fresh()->definition()->value('key'));
        $this->assertSame($requestRulesetId, $queued->fresh()->request_ruleset_version_id);
        $this->assertSame($requestFingerprint, $queued->fresh()->request_fingerprint);
        $this->assertSame($terminalBefore, (array) DB::table('nation_command_queue_items')->find($terminal->id));
        $this->assertSame($target->id, $aliveMonster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($historicalMonsterBefore, (array) DB::table('monster_instances')->find($historicalMonster->id));
        $this->assertSame($target->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame(1, $killStat->fresh()->kill_count);
        $this->assertSame($shipBefore, (array) DB::table('ships')->find($ship->id));
        $this->assertSame($secretaryBefore, (array) DB::table('secretaries')->where('user_id', $user->id)->sole());
        $this->assertSame($skillsBefore, DB::table('secretary_skills')->where('secretary_id', $secretaryBefore['id'])
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        foreach (self::MIGRATIONS as $migration) {
            $this->assertDatabaseHas('migrations', ['migration' => $migration]);
        }
        foreach ([
            'compensation_grants',
            'compensation_grant_items',
            'compensation_grant_claims',
            'guide_conversation_topics',
            'secretary_guide_conversation_totals',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing v3.9 upgrade table {$table}.");
        }
        $this->assertTrue(Schema::hasColumn('underground_profiles', 'shining_kingdom_key_balance'));
        $this->assertSame(0, (int) DB::table('underground_profiles')
            ->where('id', $legacyUndergroundProfileId)
            ->value('shining_kingdom_key_balance'));
        $this->assertDatabaseHas('facility_definitions', ['key' => 'central_bank', 'asset_key' => 'tile.central_bank']);
        $this->assertDatabaseHas('facility_definitions', ['key' => 'central_granary', 'asset_key' => 'tile.central_granary']);
        $this->assertDatabaseHas('audit_events', [
            'world_id' => $world->id,
            'event_type' => 'ruleset.v23_activated',
            'visibility' => 'admin',
        ]);
        $this->assertSame('already_current_v23', app(Ver390RulesetUpgrade::class)->run());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v23_activated')->count());

        $v23Payload = $target->settings;
        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::VER392_MIGRATION.'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $v24 = RulesetVersion::query()->where('key', Ver392RulesetUpgrade::TARGET_KEY)->sole();
        $this->assertSame($v24->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v23Payload, $target->fresh()->settings);
        $this->assertSame($v24->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('land_clear', $queued->fresh()->definition()->value('key'));
        $this->assertSame($requestRulesetId, $queued->fresh()->request_ruleset_version_id);
        $this->assertSame($requestFingerprint, $queued->fresh()->request_fingerprint);
        $this->assertSame($terminalBefore, (array) DB::table('nation_command_queue_items')->find($terminal->id));
        $this->assertSame($v24->id, $aliveMonster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($historicalMonsterBefore, (array) DB::table('monster_instances')->find($historicalMonster->id));
        $this->assertSame($v24->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($shipBefore, (array) DB::table('ships')->find($ship->id));
        $this->assertSame($secretaryBefore, (array) DB::table('secretaries')->where('user_id', $user->id)->sole());
        $this->assertSame($skillsBefore, DB::table('secretary_skills')->where('secretary_id', $secretaryBefore['id'])
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
        $this->assertDatabaseHas('audit_events', [
            'world_id' => $world->id,
            'event_type' => 'ruleset.v24_activated',
            'visibility' => 'admin',
        ]);
        $this->assertSame('already_current_v24', app(Ver392RulesetUpgrade::class)->run());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v24_activated')->count());
    }

    public function test_v22_to_v23_rolls_back_after_a_failure_during_world_activation(): void
    {
        $this->returnSchemaToExact390Source();
        $source = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::SOURCE_KEY)->sole();
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '三九巻戻国', '三九巻戻主');
        $targetCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $queued = $this->commandItem($nation->id, $source->id, $targetCell, 1, 'queued');

        DB::unprepared(<<<'SQL'
CREATE FUNCTION fail_test_v23_world_activation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION 'injected v23 activation failure';
END;
$$;
CREATE TRIGGER fail_test_v23_world_activation
BEFORE UPDATE OF ruleset_version_id ON worlds
FOR EACH ROW
WHEN (OLD.ruleset_version_id IS DISTINCT FROM NEW.ruleset_version_id)
EXECUTE FUNCTION fail_test_v23_world_activation();
SQL);

        try {
            app(Ver390RulesetUpgrade::class)->run();
            $this->fail('Expected the injected failure to roll back v23 activation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected v23 activation failure', $exception->getMessage());
        }

        $this->assertSame($source->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($source->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertDatabaseMissing('ruleset_versions', ['key' => Ver390RulesetUpgrade::TARGET_KEY]);
        $this->assertDatabaseMissing('facility_definitions', ['key' => 'central_bank']);
        $this->assertDatabaseMissing('facility_definitions', ['key' => 'central_granary']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ruleset.v23_activated')->count());
    }

    public function test_unresolved_next_turn_blocks_v22_to_v23_before_target_publication(): void
    {
        $this->returnSchemaToExact390Source();
        $world = $this->lightweightWorld();
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('7', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $sourceRulesetId = $world->ruleset_version_id;

        try {
            app(Ver390RulesetUpgrade::class)->run();
            $this->fail('Expected the unresolved next TurnRun to block v23 activation.');
        } catch (DomainException $exception) {
            $this->assertSame('The next production TurnRun is unresolved.', $exception->getMessage());
        }

        $this->assertSame($sourceRulesetId, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('ruleset_versions', ['key' => Ver390RulesetUpgrade::TARGET_KEY]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ruleset.v23_activated')->count());
    }

    public function test_fresh_install_publishes_v23_without_creating_a_world(): void
    {
        $this->returnSchemaToExact390Source();

        $this->artisan('migrate', [
            '--path' => 'database/migrations/'.self::MIGRATIONS[0].'.php',
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('ruleset_versions', [
            'key' => Ver390RulesetUpgrade::TARGET_KEY,
            'version' => Ver390RulesetUpgrade::TARGET_VERSION,
        ]);
        $published = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::TARGET_KEY)->sole();
        $this->assertSame(
            $this->canonicalChecksum(require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v23.php')),
            $this->canonicalChecksum($published->settings),
        );
        $this->assertDatabaseCount('worlds', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function returnSchemaToExact390Source(): void
    {
        $v24Row = RulesetVersion::query()->where('key', Ver392RulesetUpgrade::TARGET_KEY)->first();
        if ($v24Row instanceof RulesetVersion) {
            DB::table('worlds')->where('ruleset_version_id', $v24Row->id)->update([
                'ruleset_version_id' => RulesetVersion::query()
                    ->where('key', Ver392RulesetUpgrade::SOURCE_KEY)->valueOrFail('id'),
                'updated_at' => now(),
            ]);
            $v24Row->delete();
        }
        DB::table('migrations')->where('migration', self::VER392_MIGRATION)->delete();

        if (Schema::hasColumn('underground_profiles', 'shining_kingdom_key_balance')) {
            Schema::table('underground_profiles', function (Blueprint $table): void {
                $table->dropColumn('shining_kingdom_key_balance');
            });
        }
        Schema::dropIfExists('secretary_guide_conversation_totals');
        Schema::dropIfExists('guide_conversation_topics');
        Schema::dropIfExists('compensation_grant_claims');
        Schema::dropIfExists('compensation_grant_items');
        Schema::dropIfExists('compensation_grants');
        Schema::dropIfExists('user_daily_quest_activities');
        Schema::dropIfExists('user_daily_quest_progress');
        Schema::dropIfExists('user_daily_login_claims');
        Schema::dropIfExists('user_paradox_ledger');
        Schema::dropIfExists('user_paradox_balances');
        if (Schema::hasColumn('nation_command_queue_items', 'paradox_execution_count')) {
            Schema::table('nation_command_queue_items', function (Blueprint $table): void {
                $table->dropColumn('paradox_execution_count');
            });
        }

        $v22 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v22.php');
        config([
            'hakoniwa.ruleset' => $v22,
            'hakoniwa.published_rulesets' => [$v22['key'] => $v22],
        ]);
        $v22Row = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::SOURCE_KEY)->sole();
        $v23Row = RulesetVersion::query()->where('key', Ver390RulesetUpgrade::TARGET_KEY)->first();
        if ($v23Row instanceof RulesetVersion) {
            DB::table('worlds')->where('ruleset_version_id', $v23Row->id)->update([
                'ruleset_version_id' => $v22Row->id,
                'updated_at' => now(),
            ]);
            $v23Row->delete();
        }
        FacilityDefinition::query()->whereIn('key', ['central_bank', 'central_granary'])->delete();
        DB::table('migrations')->whereIn('migration', self::MIGRATIONS)->delete();
    }

    private function commandItem(
        int $nationId,
        int $rulesetId,
        MapCell $target,
        ?int $position,
        string $status,
    ): NationCommandQueueItem {
        $queueId = NationCommandQueue::query()->firstOrCreate([
            'nation_id' => $nationId,
            'map_space_id' => $target->map_space_id,
        ], ['version' => 1])->id;
        $membershipId = (int) DB::table('nation_memberships')->where('nation_id', $nationId)->value('id');
        $definitionId = (int) DB::table('command_definitions')
            ->where('ruleset_version_id', $rulesetId)
            ->where('key', 'land_clear')
            ->value('id');

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queueId,
            'command_definition_id' => $definitionId,
            'request_ruleset_version_id' => $rulesetId,
            'queue_position' => $position,
            'target_context' => 'surface_cell',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => $status,
            'queued_by_membership_id' => $membershipId,
            'request_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'queued_at' => now(),
            'execution_completed_at' => $status === 'completed' ? now() : null,
            'failure_metadata' => [],
        ]);
    }

    /** @param array<string, mixed> $settings */
    private function payloadChecksum(array $settings): string
    {
        return hash('sha256', json_encode(
            $settings,
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @param array<string, mixed> $settings */
    private function canonicalChecksum(array $settings): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($settings),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
}
