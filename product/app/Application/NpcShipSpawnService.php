<?php

namespace App\Application;

use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\Ship;
use DomainException;

final class NpcShipSpawnService
{
    public function __construct(
        private readonly WorldDisasterOpportunityService $opportunities,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array<string, int> */
    public function spawn(TurnContext $context, MapSpace $space): array
    {
        $settings = $context->ruleset->settings['ocean_loop']['npc_ship_spawn'] ?? null;
        if (! is_array($settings)) {
            return [];
        }
        $metrics = ['npc_ship_spawn_draws' => 0, 'npc_ships_spawned' => 0, 'npc_ship_spawn_blocked' => 0];
        $opportunities = $this->opportunities->resolve(
            $context,
            $space,
            'npc_ship',
            $settings['world_area_scale'] ?? null,
        );
        $version = (int) $settings['stream_version'];
        for ($opportunity = 1; $opportunity <= $opportunities['count']; $opportunity++) {
            $probability = $settings['probability'];
            $draw = $context->random->stream(TurnRandomStreamFactory::npcShipSpawn(
                $opportunity, 'trigger', $version,
            ))->integer(0, (int) $probability['denominator'] - 1);
            $metrics['npc_ship_spawn_draws']++;
            if ($draw >= (int) $probability['numerator']) {
                continue;
            }
            $nations = Nation::query()->where('world_id', $context->world->id)->where('state', 'active')
                ->whereHas('territoryCells', fn ($query) => $query
                    ->where('map_space_id', $space->id)
                    ->whereHas('facility', fn ($facility) => $facility->where('key', $settings['required_port_facility_key'])))
                ->orderBy('id')->lockForUpdate()->get();
            if ($nations->isEmpty()) {
                $metrics['npc_ship_spawn_blocked']++;

                continue;
            }
            /** @var Nation $nation */
            $nation = $nations->get($context->random->stream(TurnRandomStreamFactory::npcShipSpawn(
                $opportunity, 'nation', $version,
            ))->integer(0, $nations->count() - 1));
            $ports = MapCell::query()->where('map_space_id', $space->id)->where('owner_nation_id', $nation->id)
                ->whereHas('facility', fn ($query) => $query->where('key', $settings['required_port_facility_key']))
                ->with(['terrain', 'facility'])->orderBy('id')->lockForUpdate()->get();
            /** @var MapCell $port */
            $port = $ports->get($context->random->stream(TurnRandomStreamFactory::npcShipSpawn(
                $opportunity, 'port', $version,
            ))->integer(0, $ports->count() - 1));
            $origin = new GridCoordinate((int) $port->x, (int) $port->y);
            $coordinates = [];
            for ($distance = (int) $settings['minimum_origin_distance']; $distance <= (int) $settings['maximum_origin_distance']; $distance++) {
                foreach ($origin->ring($distance) as $coordinate) {
                    $coordinates[$coordinate->x.':'.$coordinate->y] = true;
                }
            }
            $candidates = MapCell::query()->where('map_space_id', $space->id)
                ->where(function ($query) use ($coordinates): void {
                    foreach (array_keys($coordinates) as $index => $key) {
                        [$x, $y] = array_map('intval', explode(':', $key));
                        $method = $index === 0 ? 'where' : 'orWhere';
                        $query->{$method}(fn ($coordinate) => $coordinate->where('x', $x)->where('y', $y));
                    }
                })
                ->with(['terrain', 'facility', 'ship'])->orderBy('id')->lockForUpdate()->get();
            $occupiedByMonster = MonsterOccupancy::query()->whereIn('map_cell_id', $candidates->modelKeys())
                ->pluck('map_cell_id')->map(static fn ($id): int => (int) $id)->flip();
            $candidates = $candidates->filter(fn (MapCell $cell): bool => $cell->terrain->key === 'sea'
                && $cell->ship === null && ! $occupiedByMonster->has($cell->id)
                && ($cell->facility === null
                    || ($cell->facility->visibility_policy === FacilityVisibilityPolicy::Disguised->value
                        && $cell->facility->disguise_terrain_key === 'sea')))->values();
            if ($candidates->isEmpty()) {
                $metrics['npc_ship_spawn_blocked']++;

                continue;
            }
            /** @var MapCell $cell */
            $cell = $candidates->get($context->random->stream(TurnRandomStreamFactory::npcShipSpawn(
                $opportunity, 'candidate', $version,
            ))->integer(0, $candidates->count() - 1));
            $typeDraw = $context->random->stream(TurnRandomStreamFactory::npcShipSpawn(
                $opportunity, 'type', $version,
            ))->integer(1, array_sum($settings['ship_type_weights']));
            $type = $typeDraw <= (int) $settings['ship_type_weights']['pirate'] ? 'pirate' : 'treasure';
            $definition = $context->ruleset->settings['surface_ships']['definitions'][$type] ?? null;
            if (! is_array($definition) || ($definition['player_buildable'] ?? true) !== false) {
                throw new DomainException('NPC Ship spawn references an invalid Ship definition.');
            }
            $hp = $type === 'pirate'
                ? $context->random->stream(TurnRandomStreamFactory::npcShipSpawn($opportunity, 'hp', $version))
                    ->integer((int) $settings['pirate_initial_hp']['minimum'], (int) $settings['pirate_initial_hp']['maximum'])
                : 1;
            $population = $type === 'pirate'
                ? $context->random->stream(TurnRandomStreamFactory::npcShipSpawn($opportunity, 'population', $version))
                    ->integer((int) $settings['pirate_initial_population']['minimum'], (int) $settings['pirate_initial_population']['maximum'])
                : null;
            $ship = Ship::query()->create([
                'world_id' => $context->world->id, 'ruleset_version_id' => $context->ruleset->id,
                'nation_id' => null, 'map_cell_id' => $cell->id, 'ship_type_key' => $type,
                'current_hp' => $hp, 'max_hp' => (int) $definition['maximum_hp'], 'population' => $population,
                'heading' => null, 'state' => Ship::STATE_ACTIVE, 'version' => 1,
            ]);
            $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
            $this->events->record($context, 'ship.npc_spawned', $ship, [
                'ship_id' => (int) $ship->id, 'ship_type_key' => $type,
                'origin_nation_id' => (int) $nation->id, 'origin_port_id' => (int) $port->id,
                'x' => (int) $cell->x, 'y' => (int) $cell->y, 'current_hp' => $hp,
                'population' => $population, 'world_opportunity_index' => $opportunity,
            ], 'public');
            $metrics['npc_ships_spawned']++;
        }

        return $metrics;
    }
}
