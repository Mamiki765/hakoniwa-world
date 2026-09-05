<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Monster\MonsterTurnBatch;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Domain\Ship\SurfaceShipTurnBatch;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use DomainException;

final class SurfaceShipTurnService
{
    /** @var array<string, SurfaceShipDefinition> */
    private array $definitions = [];

    /** @var array<string, ResourceDefinition> */
    private array $resources = [];

    /** @var array<string, mixed> */
    private array $movement = [];

    public function __construct(
        private readonly SurfaceShipCatalog $catalog,
        private readonly CapacityBoundedAssetService $boundedAssets,
        private readonly FoodOverflowResolver $foodOverflow,
        private readonly SecretaryExperienceAwardService $secretaryExperience,
        private readonly SurfaceShipRemovalService $removal,
        private readonly TurnEventRecorder $events,
    ) {}

    public function load(TurnContext $context, MapSpace $space): SurfaceShipTurnBatch
    {
        $settings = $context->ruleset->settings['surface_ships']['movement'] ?? null;
        if ($settings === null) {
            $this->movement = [];
            $this->definitions = [];
            $this->resources = [];

            return new SurfaceShipTurnBatch([], []);
        }
        $this->movement = $this->movementSettings($context);
        $ships = Ship::query()->where('world_id', $context->world->id)
            ->where('state', Ship::STATE_ACTIVE)
            ->with('nation:id,name,state')
            ->orderBy('id')->lockForUpdate()->get();
        if ($ships->isEmpty()) {
            $this->definitions = [];
            $this->resources = [];

            return new SurfaceShipTurnBatch([], []);
        }
        $this->definitions = [];
        $resourceKeys = [$this->movement['fuel_resource_key']];
        foreach ($this->catalog->definitions($context->ruleset->settings) as $definition) {
            $this->definitions[$definition->key] = $definition;
            if ($definition->movementRewardResourceKey !== null) {
                $resourceKeys[] = $definition->movementRewardResourceKey;
            }
        }
        $this->resources = ResourceDefinition::query()->whereIn('key', array_values(array_unique($resourceKeys)))
            ->get()->keyBy('key')->all();
        foreach (array_unique($resourceKeys) as $resourceKey) {
            if (! isset($this->resources[$resourceKey])) {
                throw new DomainException("Surface Ship resource {$resourceKey} is unavailable.");
            }
        }

        // Port availability is fixed when randomized Surface cell processing starts.
        // A port lost later in this phase stops its Nation's Ships from the next turn.
        $portNationIds = MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $ships->pluck('nation_id')->unique()->values()->all())
            ->whereHas('facility', fn ($query) => $query->where('key', $this->movement['required_port_facility_key']))
            ->pluck('owner_nation_id')->map(static fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return new SurfaceShipTurnBatch($ships, $portNationIds);
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    public function processCell(
        TurnContext $context,
        MapSpace $space,
        MapCell $origin,
        array $cellsByCoordinate,
        MonsterTurnBatch $monsters,
        SurfaceShipTurnBatch $ships,
    ): void {
        $ship = $ships->shipAt((int) $origin->id);
        if (! $ship instanceof Ship || $context->state->shipProcessed((int) $ship->id)) {
            return;
        }
        $context->state->markShipProcessed((int) $ship->id);
        $ships->count('ship_events');

        if ($ship->nation->state !== 'active') {
            return;
        }
        if (! $ships->hasPort((int) $ship->nation_id)) {
            $ships->count('ship_no_port');

            return;
        }

        $candidates = $this->movementCandidates($space, $origin, $cellsByCoordinate, $monsters, $ships);
        $heading = $ship->heading;
        $destination = $heading === null ? null : ($candidates[$heading] ?? null);
        $headingBlocked = $heading !== null && ! $destination instanceof MapCell;
        if ($headingBlocked) {
            $ship->heading = null;
        }
        if (! $destination instanceof MapCell && $candidates !== []) {
            $candidateList = array_values($candidates);
            $stream = $context->random->stream(TurnRandomStreamFactory::shipMovement(
                (int) $ship->id,
                'candidate',
                (int) $this->movement['random_stream_version'],
            ));
            $destination = $candidateList[$stream->integer(0, count($candidateList) - 1)];
        }
        if (! $destination instanceof MapCell) {
            if ($headingBlocked) {
                $ship->version++;
                $ship->save();
            }
            $ships->count('ship_blocked');

            return;
        }

        $nation = Nation::query()->whereKey($ship->nation_id)->where('state', 'active')->lockForUpdate()->first();
        if (! $nation instanceof Nation) {
            return;
        }
        $oil = $this->lockedBalance($nation, $this->resources[$this->movement['fuel_resource_key']]);
        $definition = $this->definitions[$ship->ship_type_key]
            ?? throw new DomainException('Active Ship type is unavailable from the current Ruleset.');
        if ((int) $oil->amount < $definition->movementOilUnits) {
            if ($headingBlocked) {
                $ship->version++;
                $ship->save();
            }
            $this->fuelShortage($context, $origin, $ship, $definition, $ships);

            return;
        }

        $oil->decrement('amount', $definition->movementOilUnits);
        $fromCellId = (int) $origin->id;
        $ship->map_cell_id = $destination->id;
        $ship->version++;
        $ship->save();
        $ships->move($ship, $fromCellId, (int) $destination->id);
        $context->state->markMapChunkChanged((int) $origin->map_chunk_id);
        $context->state->markMapChunkChanged((int) $destination->map_chunk_id);

        $reward = $this->settleReward($context, $nation, $definition);
        $experience = (int) $this->movement['secretary_experience_per_successful_move'];
        $this->secretaryExperience->awardSkill(
            $context,
            (int) $nation->id,
            SecretarySkillCatalog::SHIP_OPERATIONS,
            $experience,
        );
        $ships->count('ship_moves');
        $ships->count('ship_oil_consumed', $definition->movementOilUnits);
        $ships->count('ship_fish_applied', $reward['resource_applied']);
        $ships->count('ship_money_applied', $reward['money_applied']);
        $ships->count('ship_secretary_experience', $experience);
        $this->events->record($context, 'ship.moved', $ship, [
            'nation_id' => (int) $nation->id,
            'nation_name' => $nation->name,
            'ship_id' => (int) $ship->id,
            'ship_type_key' => $ship->ship_type_key,
            'ship_name' => $definition->name,
            'from_x' => (int) $origin->x,
            'from_y' => (int) $origin->y,
            'x' => (int) $destination->x,
            'y' => (int) $destination->y,
            'heading' => $ship->heading,
            'heading_reset' => $headingBlocked,
            'oil_consumed' => $definition->movementOilUnits,
            ...$reward,
            'secretary_skill_key' => SecretarySkillCatalog::SHIP_OPERATIONS,
            'secretary_experience_requested' => $experience,
        ], 'nation');
    }

    /** @param array<string, MapCell> $cellsByCoordinate
     * @return array<int, MapCell>
     */
    private function movementCandidates(
        MapSpace $space,
        MapCell $origin,
        array $cellsByCoordinate,
        MonsterTurnBatch $monsters,
        SurfaceShipTurnBatch $ships,
    ): array {
        $result = [];
        $coordinate = new GridCoordinate((int) $origin->x, (int) $origin->y);
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $heading) {
            $neighbor = $coordinate->neighbor($heading);
            if ($neighbor->x < $space->min_x || $neighbor->x > $space->max_x
                || $neighbor->y < $space->min_y || $neighbor->y > $space->max_y) {
                continue;
            }
            $cell = $cellsByCoordinate[$neighbor->x.':'.$neighbor->y] ?? null;
            if ($cell instanceof MapCell
                && $this->canOccupy($cell, $ships->shipAt((int) $cell->id) !== null, $monsters->occupancyAt((int) $cell->id) !== null)) {
                $result[$heading] = $cell;
            }
        }

        return $result;
    }

    private function canOccupy(MapCell $cell, bool $hasShip, bool $hasMonster): bool
    {
        if ($cell->terrain->key !== $this->movement['terrain_key'] || $hasShip || $hasMonster) {
            return false;
        }
        $facility = $cell->facility;

        return $facility === null
            || ($facility->visibility_policy === FacilityVisibilityPolicy::Disguised->value
                && $facility->disguise_terrain_key === $this->movement['terrain_key']);
    }

    private function fuelShortage(
        TurnContext $context,
        MapCell $cell,
        Ship $ship,
        SurfaceShipDefinition $definition,
        SurfaceShipTurnBatch $ships,
    ): void {
        $ships->count('ship_fuel_shortages');
        $draw = $context->random->stream(TurnRandomStreamFactory::shipMovement(
            (int) $ship->id,
            'fuel_shortage_damage',
            (int) $this->movement['random_stream_version'],
        ))->integer(1, 100);
        $damaged = $draw <= (int) $this->movement['fuel_shortage_damage_chance_percent'];
        if (! $damaged) {
            return;
        }
        $damage = (int) $this->movement['fuel_shortage_damage'];
        $ships->count('ship_fuel_damage', $damage);
        if ((int) $ship->current_hp <= $damage) {
            $ships->forget($ship, (int) $cell->id);
            $this->removal->sinkLockedAtCell($context, $cell, $ship, 'fuel_exhaustion', [
                'damage' => $damage,
                'draw' => $draw,
                'chance_percent' => (int) $this->movement['fuel_shortage_damage_chance_percent'],
            ]);
            $ships->count('ship_fuel_sunk');

            return;
        }
        $ship->current_hp -= $damage;
        $ship->version++;
        $ship->save();
        $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
        $this->events->record($context, 'ship.fuel_shortage_damaged', $ship, [
            'nation_id' => (int) $ship->nation_id,
            'ship_id' => (int) $ship->id,
            'ship_type_key' => $ship->ship_type_key,
            'ship_name' => $definition->name,
            'x' => (int) $cell->x,
            'y' => (int) $cell->y,
            'damage' => $damage,
            'current_hp' => (int) $ship->current_hp,
            'draw' => $draw,
            'chance_percent' => (int) $this->movement['fuel_shortage_damage_chance_percent'],
        ], 'nation', 'warning');
    }

    /** @return array{resource_key: string|null, resource_requested: int, resource_applied: int, resource_overflow: int, money_requested: int, money_applied: int, money_overflow: int} */
    private function settleReward(
        TurnContext $context,
        Nation $nation,
        SurfaceShipDefinition $definition,
    ): array {
        $resource = null;
        if ($definition->movementRewardResourceKey !== null) {
            $rewardResource = $this->resources[$definition->movementRewardResourceKey];
            $resource = $this->boundedAssets->creditFood(
                $nation,
                $rewardResource,
                $definition->movementRewardResourceUnits,
                $context->ruleset,
            );
            if ($resource->overflow > 0) {
                $this->foodOverflow->resolve($context, $nation, $rewardResource, $resource);
            }
        }
        $money = $definition->movementRewardMoney > 0
            ? $this->boundedAssets->creditMoney($nation, $definition->movementRewardMoney, $context->ruleset)
            : null;

        return [
            'resource_key' => $definition->movementRewardResourceKey,
            'resource_requested' => $definition->movementRewardResourceUnits,
            'resource_applied' => $resource === null ? 0 : $resource->applied,
            'resource_overflow' => $resource === null ? 0 : $resource->overflow,
            'money_requested' => $definition->movementRewardMoney,
            'money_applied' => $money === null ? 0 : $money->applied,
            'money_overflow' => $money === null ? 0 : $money->overflow,
        ];
    }

    private function lockedBalance(Nation $nation, ResourceDefinition $resource): NationResource
    {
        $balance = NationResource::query()->firstOrCreate([
            'nation_id' => $nation->id,
            'resource_definition_id' => $resource->id,
        ], ['amount' => 0]);

        return NationResource::query()->whereKey($balance->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function movementSettings(TurnContext $context): array
    {
        $settings = $context->ruleset->settings['surface_ships']['movement'] ?? null;
        if (! is_array($settings)
            || ($settings['terrain_key'] ?? null) !== 'sea'
            || ($settings['required_port_facility_key'] ?? null) !== 'port'
            || ($settings['fuel_resource_key'] ?? null) !== 'oil'
            || ($settings['normal_event_limit_per_turn'] ?? null) !== 1
            || ($settings['fuel_shortage_damage_chance_percent'] ?? null) !== 1
            || ($settings['fuel_shortage_damage'] ?? null) !== 1
            || ($settings['random_stream_version'] ?? null) !== 1
            || ($settings['secretary_skill_key'] ?? null) !== SecretarySkillCatalog::SHIP_OPERATIONS
            || ($settings['secretary_experience_per_successful_move'] ?? null) !== 1) {
            throw new DomainException('The active Ruleset has no supported Surface Ship movement contract.');
        }

        return $settings;
    }
}
