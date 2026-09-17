<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Monster\MonsterTurnBatch;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Ship\SurfaceShipDefinition;
use App\Domain\Ship\SurfaceShipTurnBatch;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\BuriedTreasure;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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
        private readonly SurfaceShipCombatService $combat,
        private readonly MonsterDamageService $monsterDamage,
        private readonly MapCellStateService $cells,
        private readonly SurfaceVisibilityService $visibility,
        private readonly BuriedTreasureService $buriedTreasures,
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
            ->whereIn('owner_nation_id', $ships->pluck('nation_id')->filter()->unique()->values()->all())
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

        $definition = $this->definitions[$ship->ship_type_key]
            ?? throw new DomainException('Active Ship type is unavailable from the current Ruleset.');
        $isNpc = $ship->nation_id === null;
        if ($isNpc === $definition->playerBuildable) {
            throw new DomainException('Active Ship ownership differs from its Ruleset definition.');
        }
        if (! $isNpc) {
            $owner = $ship->nation;
            if (! $owner instanceof Nation || $owner->state !== 'active') {
                return;
            }
            if (! $ships->hasPort((int) $ship->nation_id)) {
                $ships->count('ship_no_port');

                return;
            }
        }

        $candidates = $this->movementCandidates($space, $origin, $cellsByCoordinate, $monsters, $ships);
        $heading = $isNpc ? null : $ship->heading;
        $destination = $heading === null ? null : ($candidates[$heading] ?? null);
        $headingBlocked = $heading !== null && ! $destination instanceof MapCell;
        if ($headingBlocked) {
            $ship->heading = null;
        }
        if (! $destination instanceof MapCell && $candidates !== [] && $heading === null
            && $definition->movementMode === 'sparkle_or_random') {
            $destination = $this->explorationDestination(
                $context, $space, $origin, $candidates, $cellsByCoordinate, (int) $ship->nation_id,
            );
        }
        if (! $destination instanceof MapCell && $candidates !== []
            && in_array($definition->movementMode, ['heading_or_random', 'sparkle_or_random', 'random_drift'], true)) {
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
            if (! $isNpc && $definition->combatRole === 'warship') {
                $nation = Nation::query()->whereKey($ship->nation_id)->where('state', 'active')->lockForUpdate()->first();
                if ($nation instanceof Nation) {
                    $this->warshipAttack($context, $origin, $ship, $nation, $cellsByCoordinate, $monsters, $ships);
                }
            }
            if ($isNpc && $definition->combatRole === 'pirate') {
                $this->pirateAttack($context, $origin, $ship, $cellsByCoordinate, $ships);
            }

            return;
        }

        if ($isNpc) {
            $this->persistMovement($context, $origin, $destination, $ship, $ships);
            $ships->count('ship_moves');
            $this->events->record($context, 'ship.moved', $ship, [
                'ship_id' => (int) $ship->id,
                'ship_type_key' => $ship->ship_type_key,
                'ship_name' => $definition->name,
                'from_x' => (int) $origin->x,
                'from_y' => (int) $origin->y,
                'x' => (int) $destination->x,
                'y' => (int) $destination->y,
                'heading' => null,
                'heading_reset' => false,
                'oil_consumed' => 0,
                'resource_key' => null,
                'resource_requested' => 0,
                'resource_applied' => 0,
                'resource_overflow' => 0,
                'money_requested' => 0,
                'money_applied' => 0,
                'money_overflow' => 0,
            ], 'public');

            if ($definition->combatRole === 'pirate') {
                $this->pirateAttack($context, $destination, $ship, $cellsByCoordinate, $ships);
            }

            return;
        }

        $nation = Nation::query()->whereKey($ship->nation_id)->where('state', 'active')->lockForUpdate()->first();
        if (! $nation instanceof Nation) {
            return;
        }
        $oil = $this->lockedBalance($nation, $this->resources[$this->movement['fuel_resource_key']]);
        if ((int) $oil->amount < $definition->movementOilUnits) {
            if ($headingBlocked) {
                $ship->version++;
                $ship->save();
            }
            $this->fuelShortage($context, $origin, $ship, $definition, $ships);

            return;
        }

        $oil->decrement('amount', $definition->movementOilUnits);
        $this->persistMovement($context, $origin, $destination, $ship, $ships);

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
        if ($definition->key === 'exploration') {
            $this->buriedTreasures->collectAtCell($context, $destination, $nation, 'exploration_ship');
        }
        if ($definition->combatRole === 'warship') {
            $this->warshipAttack($context, $destination, $ship, $nation, $cellsByCoordinate, $monsters, $ships);
        }
    }

    /** @param array<int, MapCell> $candidates
     * @param  array<string, MapCell>  $cellsByCoordinate
     */
    private function explorationDestination(
        TurnContext $context,
        MapSpace $space,
        MapCell $origin,
        array $candidates,
        array $cellsByCoordinate,
        int $nationId,
    ): ?MapCell {
        /** @var EloquentCollection<int, MapCell> $allCells */
        $allCells = new EloquentCollection(array_values($cellsByCoordinate));
        $visible = $this->visibility->visibleCoordinates($space, $allCells, $nationId);
        $treasures = BuriedTreasure::query()->where('world_id', $context->world->id)
            ->where('state', BuriedTreasure::STATE_ACTIVE)->with('cell')->orderBy('id')->get()
            ->filter(fn (BuriedTreasure $treasure): bool => isset(
                $visible[$treasure->cell->x.':'.$treasure->cell->y],
            ))
            ->sortBy(fn (BuriedTreasure $treasure): array => [
                (new GridCoordinate((int) $origin->x, (int) $origin->y))->distanceTo(
                    new GridCoordinate((int) $treasure->cell->x, (int) $treasure->cell->y),
                ),
                (int) $treasure->id,
            ]);
        /** @var BuriedTreasure|null $target */
        $target = $treasures->first();
        if (! $target instanceof BuriedTreasure) {
            return null;
        }
        $targetCoordinate = new GridCoordinate((int) $target->cell->x, (int) $target->cell->y);
        uasort($candidates, static fn (MapCell $left, MapCell $right): int => [
            (new GridCoordinate((int) $left->x, (int) $left->y))->distanceTo($targetCoordinate), (int) $left->id,
        ] <=> [
            (new GridCoordinate((int) $right->x, (int) $right->y))->distanceTo($targetCoordinate), (int) $right->id,
        ]);

        return reset($candidates) ?: null;
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    private function pirateAttack(
        TurnContext $context,
        MapCell $origin,
        Ship $pirate,
        array $cellsByCoordinate,
        SurfaceShipTurnBatch $ships,
    ): void {
        $settings = $context->ruleset->settings['ocean_loop']['pirate_attack'];
        $probability = $settings['probability'];
        $version = (int) $settings['stream_version'];
        $draw = $context->random->stream(TurnRandomStreamFactory::pirateAttack(
            (int) $pirate->id, 'trigger', $version,
        ))->integer(0, (int) $probability['denominator'] - 1);
        if ($draw >= (int) $probability['numerator']) {
            return;
        }
        $originCoordinate = new GridCoordinate((int) $origin->x, (int) $origin->y);
        $cellsById = collect($cellsByCoordinate)->keyBy('id');
        $targets = [];
        foreach ($context->state->surfaceCellIds() as $cellId) {
            $cell = $cellsById->get($cellId);
            if (! $cell instanceof MapCell || $originCoordinate->distanceTo(new GridCoordinate($cell->x, $cell->y)) > (int) $settings['range']) {
                continue;
            }
            $ship = $ships->shipAt((int) $cell->id);
            if ($ship instanceof Ship && $ship->nation_id !== null) {
                $targets[] = ['type' => 'ship', 'cell' => $cell, 'ship' => $ship];
            }
            if ($cell->id === $origin->id && in_array($cell->facility?->key, $settings['seabed_facility_keys'], true)) {
                $targets[] = ['type' => 'seabed', 'cell' => $cell];
            } elseif ($cell->population > 0 && in_array($cell->facility?->key, $settings['settlement_facility_keys'], true)) {
                $targets[] = ['type' => 'settlement', 'cell' => $cell];
            }
        }
        if ($targets === []) {
            return;
        }
        $target = $targets[$context->random->stream(TurnRandomStreamFactory::pirateAttack(
            (int) $pirate->id, 'target', $version,
        ))->integer(0, count($targets) - 1)];
        /** @var MapCell $cell */
        $cell = $target['cell'];
        $targetNationId = $target['type'] === 'ship'
            ? (int) $target['ship']->nation_id
            : ($cell->owner_nation_id === null ? null : (int) $cell->owner_nation_id);
        $stolen = 0;
        if ($target['type'] === 'settlement') {
            $before = (int) $cell->population;
            $minimum = $cell->facility?->key === 'capital'
                ? min($before, (int) $context->ruleset->settings['capital_minimum_population'])
                : 0;
            $after = max($minimum, $before - intdiv($before, 2));
            $stolen = $before - $after;
            $cell->population = $after;
            $this->syncSettlementFacility($context, $cell);
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
            $pirate->population = (int) $pirate->population + $stolen;
            $pirate->version++;
            $pirate->save();
        } elseif ($target['type'] === 'ship') {
            /** @var Ship $victim */
            $victim = $target['ship'];
            if ((int) $victim->current_hp <= (int) $settings['player_ship_damage']) {
                $this->removal->sinkLockedAtCell($context, $cell, $victim, 'pirate_attack', [
                    'pirate_ship_id' => (int) $pirate->id,
                ]);
                $ships->forget($victim, (int) $cell->id);
            } else {
                $victim->current_hp -= (int) $settings['player_ship_damage'];
                $victim->version++;
                $victim->save();
                $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
            }
        } else {
            $facilityKey = $cell->facility?->key;
            $this->cells->setFacility($cell, null);
            $cell->owner_nation_id = null;
            $cell->population = 0;
            $cell->version++;
            $cell->save();
            $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
            $target['facility_key'] = $facilityKey;
        }
        $this->events->record($context, 'ship.pirate_attacked', $pirate, [
            'nation_id' => $targetNationId,
            'ship_id' => (int) $pirate->id, 'target_type' => $target['type'],
            'x' => (int) $cell->x, 'y' => (int) $cell->y,
            'stolen_population' => $stolen, 'pirate_population' => (int) $pirate->population,
            'facility_key' => $target['facility_key'] ?? null,
        ], 'public', 'warning');
    }

    /** @param array<string, MapCell> $cellsByCoordinate */
    private function warshipAttack(
        TurnContext $context,
        MapCell $origin,
        Ship $warship,
        Nation $nation,
        array $cellsByCoordinate,
        MonsterTurnBatch $monsters,
        SurfaceShipTurnBatch $ships,
    ): void {
        $settings = $context->ruleset->settings['ocean_loop']['warship_attack'];
        $originCoordinate = new GridCoordinate((int) $origin->x, (int) $origin->y);
        $cellsById = collect($cellsByCoordinate)->keyBy('id');
        $target = null;
        foreach ($context->state->surfaceCellIds() as $cellId) {
            $cell = $cellsById->get($cellId);
            if (! $cell instanceof MapCell
                || $originCoordinate->distanceTo(new GridCoordinate($cell->x, $cell->y)) > (int) $settings['range']) {
                continue;
            }
            $targetShip = $ships->shipAt((int) $cell->id);
            if ($targetShip instanceof Ship && in_array($targetShip->ship_type_key, $settings['target_ship_type_keys'], true)) {
                $target = ['type' => 'ship', 'cell' => $cell, 'actor' => $targetShip];
                break;
            }
            $occupancy = $monsters->occupancyAt((int) $cell->id);
            $monster = $occupancy?->monster;
            if ($monster !== null && ($monster->definition->key === 'aoi_inora' || $cell->owner_nation_id === $nation->id)) {
                $target = ['type' => 'monster', 'cell' => $cell, 'actor' => $monster];
                break;
            }
        }
        if ($target === null || (int) $nation->money < (int) $settings['cost_money_per_shot']) {
            return;
        }
        $nation->money -= (int) $settings['cost_money_per_shot'];
        $nation->save();
        $experience = 0;
        if ($target['type'] === 'ship') {
            /** @var Ship $targetShip */
            $targetShip = $target['actor'];
            $result = $this->combat->damage(
                $context, $target['cell'], $targetShip, (int) $settings['damage'], $nation, 'warship',
            );
            foreach ($result['changed_cell_ids'] as $changedCellId) {
                $changedCell = $cellsById->get($changedCellId);
                if ($changedCell instanceof MapCell) {
                    $changedCell->refresh()->load(['terrain', 'facility', 'ownerNation']);
                }
            }
            if ($result['sunk']) {
                $ships->forget($targetShip, (int) $target['cell']->id);
            }
            $experience = $result['experience'];
        } else {
            $result = $this->monsterDamage->applyDamage(
                $target['actor'], (int) $settings['damage'], 'warship', $nation, null, $target['cell'], $context,
            );
            $experience = $result->actualDamage * $result->experiencePerDamage;
            if ($experience > 0) {
                $this->secretaryExperience->awardSkill(
                    $context, (int) $nation->id, SecretarySkillCatalog::NAVY, $experience,
                );
            }
        }
        $this->events->record($context, 'ship.warship_attacked', $warship, [
            'nation_id' => (int) $nation->id, 'ship_id' => (int) $warship->id,
            'target_type' => $target['type'], 'x' => (int) $target['cell']->x, 'y' => (int) $target['cell']->y,
            'damage' => (int) $settings['damage'], 'money_spent' => (int) $settings['cost_money_per_shot'],
            'navy_experience' => $experience,
        ], 'public');
    }

    private function syncSettlementFacility(TurnContext $context, MapCell $cell): void
    {
        if ($cell->facility?->key === 'capital') {
            return;
        }
        foreach ($context->ruleset->settings['turn_processing']['settlement']['stages'] as $stage) {
            if ($cell->population >= $stage['minimum_population'] && $cell->population <= $stage['maximum_population']) {
                $this->cells->setFacility($cell, FacilityDefinition::query()->where('key', $stage['facility_key'])->firstOrFail());

                return;
            }
        }
        $this->cells->setFacility($cell, FacilityDefinition::query()->where('key', 'city')->firstOrFail());
    }

    private function persistMovement(
        TurnContext $context,
        MapCell $origin,
        MapCell $destination,
        Ship $ship,
        SurfaceShipTurnBatch $ships,
    ): void {
        $fromCellId = (int) $origin->id;
        $ship->map_cell_id = $destination->id;
        $ship->version++;
        $ship->save();
        $ships->move($ship, $fromCellId, (int) $destination->id);
        $context->state->markMapChunkChanged((int) $origin->map_chunk_id);
        $context->state->markMapChunkChanged((int) $destination->map_chunk_id);
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
