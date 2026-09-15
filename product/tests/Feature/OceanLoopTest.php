<?php

namespace Tests\Feature;

use App\Application\BuriedTreasureService;
use App\Application\MapChunkService;
use App\Application\NationCreationService;
use App\Application\NpcShipSpawnService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryTurnService;
use App\Application\SurfaceShipCombatService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\BuriedTreasure;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Ship;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class OceanLoopTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    public function test_buried_treasure_is_hidden_until_visible_or_turn_revealed_and_full_inventory_preserves_it(): void
    {
        $world = $this->lightweightWorld();
        $firstUser = User::factory()->create();
        $first = app(NationCreationService::class)->create($firstUser, $world, '宝探し国', '宝探し島主');
        $second = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '遠見国',
            '遠見島主',
        );
        $space = $this->surfaceMapSpace($world);
        $cell = $this->remoteSea($space, [$first->id, $second->id]);
        $context = $this->context($world, [$first->id, $second->id], 2, 'buried-treasure-visibility');
        $this->assertSame(
            ['numerator' => 1, 'denominator' => 100],
            $context->ruleset->settings['ocean_loop']['buried_treasure']['natural_spawn']['probability'],
        );
        $treasure = app(BuriedTreasureService::class)->create($context, $cell, 'huge_meteor', true);

        $hidden = $this->presentedCell($space, $cell, $second->id);
        $this->assertFalse($hidden['buried_treasure_visible']);
        $this->assertSame([], $hidden['overlays']);

        $settings = $context->ruleset->settings;
        $settings['ocean_loop']['buried_treasure']['remote_reveal_probability'] = [
            'numerator' => 1,
            'denominator' => 1,
        ];
        $context->ruleset->settings = $settings;
        $context->ruleset->save();
        $context->run->update([
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        $world->update(['current_turn' => 2]);

        $revealed = $this->presentedCell($space, $cell, $second->id);
        $this->assertTrue($revealed['buried_treasure_visible']);
        $this->assertSame(
            ['map.buried_treasure.sparkle'],
            array_column($revealed['overlays'], 'key'),
        );
        $this->assertSame($revealed, $this->presentedCell($space, $cell, $second->id));
        $publicEvent = collect(app(PlayerIslandEventService::class)->publicWorldPage($world->fresh(), 1, 2)['groups'])
            ->flatMap(static fn (array $group): array => $group['events'])
            ->firstWhere('type', 'buried_treasure.created');
        $this->assertIsArray($publicEvent);
        $this->assertSame(
            '巨大隕石が落下し、どこかに埋蔵宝が残されたようです。',
            $publicEvent['message'],
        );

        $stackCell = $this->remoteSea($space, [$first->id, $second->id], [$cell->id]);
        $oldest = app(BuriedTreasureService::class)->create($context, $stackCell, 'natural', false);
        foreach (range(2, 6) as $index) {
            app(BuriedTreasureService::class)->create($context, $stackCell, 'natural', false);
        }
        $this->assertSame(5, BuriedTreasure::query()->where('map_cell_id', $stackCell->id)
            ->where('state', BuriedTreasure::STATE_ACTIVE)->count());
        $this->assertSame(
            [BuriedTreasure::STATE_REMOVED, 'stack_overflow'],
            [$oldest->fresh()->state, $oldest->fresh()->resolution_reason],
        );

        $secretary = $firstUser->secretary()->firstOrFail();
        $used = $secretary->itemInstances()->count();
        foreach (range($used + 1, SecretaryItemGrantService::INVENTORY_CAPACITY) as $index) {
            $secretary->itemInstances()->create([
                'item_key' => SecretaryItemCatalog::RING,
                'level' => 1,
                'equipped_slot' => null,
                'grant_key' => "test:ocean-full:{$index}",
                'obtained_at' => now(),
            ]);
        }
        $this->assertSame(SecretaryItemGrantService::INVENTORY_CAPACITY, $secretary->itemInstances()->count());
        $this->assertSame(0, app(BuriedTreasureService::class)->collectAtCell(
            $context,
            $cell,
            $first,
            'exploration_ship',
        ));
        $this->assertSame(BuriedTreasure::STATE_ACTIVE, $treasure->fresh()->state);
        $this->assertFalse($secretary->itemInstances()
            ->where('item_key', SecretaryItemCatalog::DOKIDOKI_TICKET)->exists());

        $secretary->itemInstances()->where('item_key', SecretaryItemCatalog::RING)->delete();
        $this->assertSame(1, app(BuriedTreasureService::class)->collectAtCell(
            $context,
            $cell,
            $first,
            'exploration_ship',
        ));
        $ticket = $secretary->itemInstances()
            ->where('item_key', SecretaryItemCatalog::DOKIDOKI_TICKET)->sole();
        $this->assertSame(
            [SecretaryItemCatalog::RARITY_HIGH_QUALITY, 1500],
            [$ticket->resolved_rarity, $ticket->resolved_fixed_sale_price_money],
        );
    }

    public function test_npc_ship_world_disaster_selects_an_eligible_nation_then_spawns_near_its_port(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '港湾国',
            '港湾島主',
        );
        $space = $this->surfaceMapSpace($world);
        $port = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->orderBy('id')->firstOrFail();
        $this->setFacility($port, 'port');
        $context = $this->context($world, [$nation->id], 2, 'npc-ship-spawn');
        $settings = $context->ruleset->settings;
        $settings['ocean_loop']['npc_ship_spawn']['probability'] = ['numerator' => 1, 'denominator' => 1];
        $settings['ocean_loop']['npc_ship_spawn']['world_area_scale'] = [
            'base_chunks' => $space->currentBounds()->chunkCount(),
            'opportunities_per_base' => 1,
        ];
        $settings['ocean_loop']['npc_ship_spawn']['ship_type_weights'] = ['pirate' => 1, 'treasure' => 0];
        $context->ruleset->settings = $settings;

        $metrics = app(NpcShipSpawnService::class)->spawn($context, $space);

        $this->assertSame([1, 1, 0], [
            $metrics['npc_ship_spawn_draws'],
            $metrics['npc_ships_spawned'],
            $metrics['npc_ship_spawn_blocked'],
        ]);
        $ship = Ship::query()->whereNull('nation_id')->sole();
        $cell = $ship->cell()->with(['terrain', 'facility'])->firstOrFail();
        $distance = (new GridCoordinate($port->x, $port->y))->distanceTo(
            new GridCoordinate($cell->x, $cell->y),
        );
        $this->assertSame('pirate', $ship->ship_type_key);
        $this->assertContains($ship->current_hp, [1, 2, 3]);
        $this->assertGreaterThanOrEqual(5_000, $ship->population);
        $this->assertLessThanOrEqual(10_000, $ship->population);
        $this->assertGreaterThanOrEqual(4, $distance);
        $this->assertLessThanOrEqual(6, $distance);
        $this->assertSame('sea', $cell->terrain->key);
        $this->assertFalse(MonsterOccupancy::query()->where('map_cell_id', $cell->id)->exists());
    }

    public function test_missile_sinks_npc_ships_with_population_experience_refugees_and_snapshotted_treasure(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '迎撃国', '迎撃島主');
        $space = $this->surfaceMapSpace($world);
        $base = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->orderBy('id')->firstOrFail();
        $this->setFacility($base, 'missile_base');
        $base->facility_experience = 0;
        $base->save();
        $pirateCell = $this->remoteSea($space, [$nation->id]);
        $treasureCell = $this->remoteSea($space, [$nation->id], [$pirateCell->id]);
        $pirate = $this->npcShip($world, $pirateCell, 'pirate', 1, 8_000);
        $treasureShip = $this->npcShip($world, $treasureCell, 'treasure', 1, null);
        $populationBefore = (int) MapCell::query()->where('owner_nation_id', $nation->id)->sum('population');
        $context = $this->context($world, [$nation->id], 2, 'npc-missile-combat');
        $combat = app(SurfaceShipCombatService::class);

        $pirateResult = $combat->damage($context, $pirateCell, $pirate, 1, $nation, 'missile', $base);
        $treasureResult = $combat->damage(
            $context,
            $treasureCell,
            $treasureShip,
            1,
            $nation,
            'missile',
            $base,
        );

        $this->assertSame([true, 4, 4_000], [
            $pirateResult['sunk'], $pirateResult['experience'], $pirateResult['refugees'],
        ]);
        $this->assertSame([true, 7, 0], [
            $treasureResult['sunk'], $treasureResult['experience'], $treasureResult['refugees'],
        ]);
        $this->assertSame($populationBefore + 4_000, (int) MapCell::query()
            ->where('owner_nation_id', $nation->id)->sum('population'));
        $this->assertSame(11, $base->fresh()->facility_experience);
        $this->assertSame([
            SecretaryItemCatalog::WAKUWAKU_TICKET,
            SecretaryItemCatalog::DOKIDOKI_TICKET,
        ], BuriedTreasure::query()->where('state', BuriedTreasure::STATE_ACTIVE)->orderBy('id')
            ->get()->pluck('reward_snapshot')->map(static fn (array $snapshot): string => $snapshot['item_key'])->all());
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'buried_treasure.created')
            ->where('visibility', 'public')->count());
    }

    /** @param list<int> $nationIds */
    private function context(World $world, array $nationIds, int $targetTurn, string $label): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $seed = hash('sha256', $label);
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);
        foreach ($nationIds as $nationId) {
            $state->setKarmaStartSnapshot($nationId, 0);
        }
        $context = new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
        if ($nationIds !== []) {
            app(SecretaryTurnService::class)->loadAttemptSnapshots($context, $nationIds);
        }

        return $context;
    }

    /** @param list<int> $nationIds @param list<int> $excludedIds */
    private function remoteSea(MapSpace $space, array $nationIds, array $excludedIds = []): MapCell
    {
        $land = MapCell::query()->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $nationIds)
            ->whereHas('terrain', static fn ($query) => $query->where('is_water', false))
            ->get(['x', 'y']);
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNotIn('id', $excludedIds)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')->where('population', 0)
            ->whereDoesntHave('ship')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', static fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->get()
            ->first(static function (MapCell $candidate) use ($land): bool {
                $coordinate = new GridCoordinate($candidate->x, $candidate->y);

                return $land->every(static fn (MapCell $owned): bool => $coordinate->distanceTo(
                    new GridCoordinate($owned->x, $owned->y),
                ) > 3);
            });
        if (! $cell instanceof MapCell) {
            $this->fail('No remote empty deep-sea cell is available for the ocean-loop fixture.');
        }

        return $cell;
    }

    /** @return array<string, mixed> */
    private function presentedCell(MapSpace $space, MapCell $cell, int $viewerNationId): array
    {
        $presented = collect(app(MapChunkService::class)->present(
            $space,
            (int) $cell->chunk_x,
            (int) $cell->chunk_y,
            $viewerNationId,
        )['cells'])->first(static fn (array $candidate): bool => $candidate['x'] === $cell->x
            && $candidate['y'] === $cell->y);
        if (! is_array($presented)) {
            $this->fail('The treasure cell was not present in its map chunk response.');
        }

        return $presented;
    }

    private function setFacility(MapCell $cell, string $key): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $key)->firstOrFail(),
        );
        $cell->version++;
        $cell->save();
    }

    private function npcShip(
        World $world,
        MapCell $cell,
        string $type,
        int $hp,
        ?int $population,
    ): Ship {
        return Ship::query()->create([
            'world_id' => $world->id,
            'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => null,
            'map_cell_id' => $cell->id,
            'ship_type_key' => $type,
            'current_hp' => $hp,
            'max_hp' => $type === 'pirate' ? 3 : 1,
            'population' => $population,
            'heading' => null,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);
    }
}
