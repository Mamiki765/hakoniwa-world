<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\Ver370RulesetUpgrade;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\Ship;
use App\Models\TurnRun;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Ver370RulesetUpgradeTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_exact_v20_world_upgrades_to_v21_without_changing_v20_payload_or_business_state(): void
    {
        $sourceSettings = $this->prepareExactV20Source();
        $source = RulesetVersion::query()->where('key', Ver370RulesetUpgrade::SOURCE_KEY)->sole();
        $sourcePayload = $source->settings;
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '三七移行国', '三七島主');
        $space = $this->surfaceMapSpace($world);
        $targetCell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $queued = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $space,
            commandKey: 'land_clear',
            targetX: $targetCell->x,
            targetY: $targetCell->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $sourceMonster = MonsterDefinition::query()
            ->where('ruleset_version_id', $source->id)
            ->where('key', 'inora')
            ->sole();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $sourceMonster->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
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
        $requestRulesetId = $queued->request_ruleset_version_id;
        $requestFingerprint = $queued->request_fingerprint;
        $shipAttributes = (array) DB::table('ships')->find($ship->id);

        $this->restoreCurrentV21Config();
        $this->assertSame('production_v20_to_v21', app(Ver370RulesetUpgrade::class)->run());

        $target = RulesetVersion::query()->where('key', Ver370RulesetUpgrade::TARGET_KEY)->sole();
        $this->assertSame($target->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($sourcePayload, $source->fresh()->settings);
        $this->assertSame($target->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('land_clear', $queued->fresh()->definition()->value('key'));
        $this->assertSame($requestRulesetId, $queued->fresh()->request_ruleset_version_id);
        $this->assertSame($requestFingerprint, $queued->fresh()->request_fingerprint);
        $this->assertSame($target->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('inora', $monster->fresh()->definition()->value('key'));
        $this->assertSame($target->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame(1, $killStat->fresh()->kill_count);
        $this->assertSame($shipAttributes, (array) DB::table('ships')->find($ship->id));
        $this->assertSame(0, MonsterInstance::query()->whereHas(
            'definition',
            fn ($query) => $query->where('key', 'nyowamiya'),
        )->count());
        $this->assertSame(0, NationMonsterKillStat::query()->whereHas(
            'definition',
            fn ($query) => $query->where('key', 'nyowamiya'),
        )->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v21_activated')->count());
        $this->assertSame('already_current_v21', app(Ver370RulesetUpgrade::class)->run());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ruleset.v21_activated')->count());
    }

    public function test_unresolved_next_turn_blocks_v20_to_v21_before_target_publication(): void
    {
        $this->prepareExactV20Source();
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
        $this->restoreCurrentV21Config();

        try {
            app(Ver370RulesetUpgrade::class)->run();
            $this->fail('Expected the unresolved next TurnRun to block the v21 release migration.');
        } catch (DomainException $exception) {
            $this->assertSame('The next production TurnRun is unresolved.', $exception->getMessage());
        }

        $this->assertSame($sourceRulesetId, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('ruleset_versions', ['key' => Ver370RulesetUpgrade::TARGET_KEY]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ruleset.v21_activated')->count());
    }

    /** @return array<string, mixed> */
    private function prepareExactV20Source(): array
    {
        RulesetVersion::query()->where('key', Ver370RulesetUpgrade::TARGET_KEY)->delete();
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v20.php');
        config([
            'hakoniwa.ruleset' => $sourceSettings,
            'hakoniwa.published_rulesets' => [$sourceSettings['key'] => $sourceSettings],
        ]);

        return $sourceSettings;
    }

    private function restoreCurrentV21Config(): void
    {
        config(['hakoniwa' => require config_path('hakoniwa.php')]);
    }
}
