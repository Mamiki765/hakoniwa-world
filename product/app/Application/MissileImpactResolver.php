<?php

namespace App\Application;

use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\LaunchIntent;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use DomainException;

final class MissileImpactResolver
{
    /** @var list<string> */
    public const MISSILE_KEYS = ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'];

    /**
     * @var array<int, array{
     *     intent: LaunchIntent,
     *     nation: Nation,
     *     fired: int,
     *     cost: int,
     *     ineffective: int,
     *     impacts: list<array<string, mixed>>
     * }>
     */
    private array $launches = [];

    /** @var array<int, true> */
    private array $changedCellIds = [];

    public function __construct(
        private readonly MissileBaseRules $baseRules,
        private readonly MapCellStateService $cells,
        private readonly MonsterDamageService $monsterDamage,
        private readonly MonsterRemovalService $monsterRemoval,
        private readonly TurnEventRecorder $events,
    ) {}

    public function begin(): void
    {
        $this->launches = [];
        $this->changedCellIds = [];
    }

    /**
     * @return array{shots_fired: int, money_spent: int, meaningful_impacts: int, ineffective_impacts: int, changed_cell_ids: list<int>}
     */
    public function processBase(TurnContext $context, MapSpace $space, MapCell $candidate): array
    {
        $metrics = [
            'shots_fired' => 0, 'money_spent' => 0,
            'meaningful_impacts' => 0, 'ineffective_impacts' => 0,
        ];
        $base = MapCell::query()->whereKey($candidate->id)->with(['terrain', 'facility'])
            ->lockForUpdate()->firstOrFail();
        if (! in_array($base->facility?->key, $context->ruleset->settings['military']['launch_base_facility_keys'] ?? [], true)
            || $base->owner_nation_id === null) {
            return [...$metrics, 'changed_cell_ids' => []];
        }
        $nation = Nation::query()->whereKey($base->owner_nation_id)->lockForUpdate()->first();
        if ($nation === null || $nation->state !== 'active') {
            return [...$metrics, 'changed_cell_ids' => []];
        }
        $capacity = $base->facility->key === 'missile_base'
            ? $this->baseRules->launchCapacity($base->facility, (int) ($base->facility_experience ?? 0))
            : 1;
        $remainingCapacity = $capacity;

        foreach ($context->state->launchIntentsForNation($nation->id) as $intent) {
            if ($remainingCapacity < 1 || $intent->remainingShots() < 1) {
                continue;
            }
            if (! in_array($intent->definitionKey, self::MISSILE_KEYS, true) || $intent->queueItemId === null) {
                throw new DomainException('The turn contains an invalid PR22 missile launch intent.');
            }
            $settings = $context->ruleset->settings['military']['missiles'][$intent->definitionKey] ?? null;
            if (! is_array($settings) || ! is_int($settings['cost_money_per_shot'] ?? null)) {
                throw new DomainException('The active ruleset has invalid missile settings.');
            }
            $launch = &$this->launch($intent, $nation);
            while ($remainingCapacity > 0 && $intent->remainingShots() > 0) {
                $base->refresh()->load(['terrain', 'facility']);
                if ($base->owner_nation_id !== $nation->id
                    || ! in_array($base->facility?->key, $context->ruleset->settings['military']['launch_base_facility_keys'], true)) {
                    break;
                }
                $cost = $settings['cost_money_per_shot'];
                $nation->refresh();
                if ((int) $nation->money < $cost) {
                    break;
                }
                $nation->decrement('money', $cost);
                $nation->refresh();
                $impact = $this->impact($context, $space, $nation, $base, $intent, $settings);
                $context->state->consumeLaunchIntentShots($intent, 1);
                $remainingCapacity--;
                $launch['fired']++;
                $launch['cost'] += $cost;
                $launch['impacts'][] = $impact;
                $metrics['shots_fired']++;
                $metrics['money_spent'] += $cost;
                if ($impact['meaningful']) {
                    $metrics['meaningful_impacts']++;
                } else {
                    $launch['ineffective']++;
                    $metrics['ineffective_impacts']++;
                }
            }
        }

        $changed = array_map('intval', array_keys($this->changedCellIds));
        sort($changed, SORT_NUMERIC);
        $this->changedCellIds = [];

        return [...$metrics, 'changed_cell_ids' => $changed];
    }

    /** @return array{launches: int, shots_fired: int, ineffective_impacts: int} */
    public function finalize(TurnContext $context): array
    {
        $metrics = ['launches' => 0, 'shots_fired' => 0, 'ineffective_impacts' => 0];
        foreach ($context->state->launchIntents() as $intent) {
            if (! in_array($intent->definitionKey, self::MISSILE_KEYS, true) || $intent->queueItemId === null) {
                continue;
            }
            $nation = Nation::query()->findOrFail($intent->nationId);
            $launch = $this->launches[$intent->queueItemId] ?? [
                'intent' => $intent, 'nation' => $nation, 'fired' => 0,
                'cost' => 0, 'ineffective' => 0, 'impacts' => [],
            ];
            if ($launch['fired'] === 0) {
                $this->events->record($context, 'missile.launch_failed', $nation, [
                    'nation_id' => $nation->id,
                    'command_key' => $intent->definitionKey,
                    'queue_item_id' => $intent->queueItemId,
                    'requested_shots' => $intent->requestedShots,
                    'failure_reason' => 'no_launch_capacity_or_funds_at_base_processing',
                ], 'nation', 'warning');

                continue;
            }
            $metrics['launches']++;
            $metrics['shots_fired'] += $launch['fired'];
            $metrics['ineffective_impacts'] += $launch['ineffective'];
            $public = [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'command_key' => $intent->definitionKey,
                'queue_item_id' => $intent->queueItemId,
                'fired_shots' => $launch['fired'],
            ];
            $this->events->record($context, 'missile.launched', $nation, $public, 'public');
            if ($launch['ineffective'] > 0) {
                $this->events->record($context, 'missile.ineffective_aggregated', $nation, [
                    ...$public, 'ineffective_impacts' => $launch['ineffective'],
                ], 'public');
            }
            $this->events->record($context, 'missile.launch_detail', $nation, [
                ...$public,
                'target_x' => $intent->targetX,
                'target_y' => $intent->targetY,
                'requested_shots' => $intent->requestedShots,
                'remaining_shots' => $intent->remainingShots(),
                'cost_money' => $launch['cost'],
                'impacts' => $launch['impacts'],
            ], 'private');
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function impact(
        TurnContext $context,
        MapSpace $space,
        Nation $firingNation,
        MapCell $firingBase,
        LaunchIntent $intent,
        array $settings,
    ): array {
        $radius = $settings['deviation_radius'] ?? null;
        if (! is_int($radius) || $radius < 0) {
            throw new DomainException('Missile deviation radius is invalid.');
        }
        $candidates = (new GridCoordinate($intent->targetX, $intent->targetY))->radius($radius);
        $stream = $context->random->stream(TurnRandomStreamFactory::missileImpact($intent->queueItemId));
        $coordinate = $candidates[$stream->integer(0, count($candidates) - 1)];
        $base = [
            'x' => $coordinate->x, 'y' => $coordinate->y,
            'meaningful' => false, 'effect' => 'ineffective_sea',
        ];
        if ($coordinate->x < $space->min_x || $coordinate->x > $space->max_x
            || $coordinate->y < $space->min_y || $coordinate->y > $space->max_y) {
            return [...$base, 'effect' => 'out_of_bounds_sea'];
        }
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->with(['terrain', 'facility', 'ownerNation'])->lockForUpdate()->firstOrFail();
        $ownerState = $cell->ownerNation?->state;
        if (in_array($ownerState, ['dormant_frozen', 'dormant_contestable', 'sunken_archived'], true)) {
            return [...$base, 'effect' => 'dormant_owner_protected', 'owner_state' => $ownerState];
        }
        if ($intent->definitionKey === 'land_destruction_missile') {
            return $this->landDestructionImpact($context, $firingNation, $cell, $base);
        }

        return $this->ordinaryImpact(
            $context,
            $firingNation,
            $firingBase,
            $cell,
            $base,
            $intent->definitionKey,
            $intent->queueItemId,
        );
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function ordinaryImpact(
        TurnContext $context,
        Nation $firingNation,
        MapCell $firingBase,
        MapCell $cell,
        array $base,
        string $missileKey,
        int $queueItemId,
    ): array {
        $occupancy = MonsterOccupancy::query()->where('map_cell_id', $cell->id)
            ->with('monster.definition')->lockForUpdate()->first();
        if ($occupancy !== null) {
            $result = $this->monsterDamage->applyDamage(
                $occupancy->monster,
                1,
                $missileKey,
                $firingNation,
                $firingBase->facility?->key === 'missile_base' ? $firingBase : null,
                $cell,
                $context,
            );
            $this->recordMeaningfulImpact($context, $firingNation, $cell, $missileKey, 'monster_hit', [
                'monster_key' => $occupancy->monster->definition->key,
                'damage_status' => $result->status,
                'before_hp' => $result->beforeHp,
                'after_hp' => $result->afterHp,
            ]);

            return [
                ...$base, 'meaningful' => true, 'effect' => $result->status,
                'monster_key' => $occupancy->monster->definition->key,
                'before_hp' => $result->beforeHp, 'after_hp' => $result->afterHp,
            ];
        }
        $beforeTerrain = $cell->terrain->key;
        $beforeFacility = $cell->facility?->key;
        $beforePopulation = $cell->population;
        if ($beforeFacility === 'capital') {
            $loss = $this->damageCapital($context, $firingNation, $cell, $missileKey, 10);
            $refugees = $this->generateAndReceiveRefugees(
                $context,
                $firingNation,
                $cell,
                intdiv($loss, 2),
                $missileKey,
                $queueItemId,
            );

            return [
                ...$base, 'meaningful' => $loss > 0,
                'effect' => $loss > 0 ? 'capital_damaged' : 'capital_at_minimum',
                'before_population' => $beforePopulation, 'after_population' => $cell->population,
                'refugees' => $refugees,
            ];
        }
        $isWater = in_array($beforeTerrain, ['sea', 'shallow'], true);
        if ($isWater && $beforeFacility === null) {
            return $base;
        }
        if (in_array($beforeTerrain, ['wasteland', 'scorched'], true)
            && $beforeFacility === null && $beforePopulation === 0) {
            return [...$base, 'effect' => 'ineffective_barren_land'];
        }
        $settlement = in_array($beforeFacility, ['village', 'town', 'city'], true);
        $this->cells->setFacility($cell, null);
        if (! $isWater) {
            $this->cells->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'scorched')->firstOrFail());
        }
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $this->markCellChanged($context, $cell);
        $refugees = $settlement && $beforePopulation > 0
            ? $this->generateAndReceiveRefugees(
                $context,
                $firingNation,
                $cell,
                intdiv($beforePopulation, 2),
                $missileKey,
                $queueItemId,
            )
            : 0;
        $effect = $isWater ? 'water_facility_destroyed' : 'land_scorched';
        $this->recordMeaningfulImpact($context, $firingNation, $cell, $missileKey, $effect, [
            'from_terrain_key' => $beforeTerrain,
            'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility,
            'before_population' => $beforePopulation,
            'after_population' => 0,
            'refugees_generated' => $refugees,
        ]);

        return [
            ...$base, 'meaningful' => true, 'effect' => $effect,
            'from_terrain_key' => $beforeTerrain, 'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility, 'before_population' => $beforePopulation,
            'refugees' => $refugees,
        ];
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function landDestructionImpact(
        TurnContext $context,
        Nation $firingNation,
        MapCell $cell,
        array $base,
    ): array {
        $beforeTerrain = $cell->terrain->key;
        $beforeFacility = $cell->facility?->key;
        $beforePopulation = $cell->population;
        if ($beforeFacility === 'capital') {
            $loss = $this->damageCapital($context, $firingNation, $cell, 'land_destruction_missile', 30);

            return [
                ...$base, 'meaningful' => $loss > 0,
                'effect' => $loss > 0 ? 'capital_damaged' : 'capital_at_minimum',
                'before_population' => $beforePopulation, 'after_population' => $cell->population,
                'refugees' => 0,
            ];
        }
        $monsterRemoved = $this->monsterRemoval->removeAtCell(
            $context,
            $cell,
            'terrain_destruction_missile',
            'monster.removed_by_terrain_event',
            ['terrain_event_key' => 'terrain_destruction_missile', 'hardening_ignored' => true],
        );
        $targetTerrain = match ($beforeTerrain) {
            'sea' => null,
            'shallow' => 'sea',
            default => 'shallow',
        };
        if ($targetTerrain === null && $beforeFacility === null && ! $monsterRemoved) {
            return $base;
        }
        $this->cells->setFacility($cell, null);
        if ($targetTerrain !== null) {
            $this->cells->transitionTerrain($cell, TerrainDefinition::query()->where('key', $targetTerrain)->firstOrFail());
        }
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $this->markCellChanged($context, $cell);
        $this->recordMeaningfulImpact($context, $firingNation, $cell, 'land_destruction_missile', 'terrain_destroyed', [
            'from_terrain_key' => $beforeTerrain,
            'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility,
            'before_population' => $beforePopulation,
            'after_population' => 0,
            'monster_removed' => $monsterRemoved,
            'refugees_generated' => 0,
        ]);

        return [
            ...$base, 'meaningful' => true, 'effect' => 'terrain_destroyed',
            'from_terrain_key' => $beforeTerrain, 'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility, 'before_population' => $beforePopulation,
            'monster_removed' => $monsterRemoved, 'refugees' => 0,
        ];
    }

    private function damageCapital(
        TurnContext $context,
        Nation $firingNation,
        MapCell $cell,
        string $missileKey,
        int $percentage,
    ): int {
        $minimum = $context->ruleset->settings['capital_minimum_population'] ?? null;
        if (! is_int($minimum) || $minimum < 1) {
            throw new DomainException('Missile Capital damage requires the existing minimum-population contract.');
        }
        $before = $cell->population;
        $after = max($minimum, intdiv($before * (100 - $percentage), 100));
        if ($after === $before) {
            return 0;
        }
        $cell->population = $after;
        $cell->version++;
        $cell->save();
        $this->markCellChanged($context, $cell);
        $this->recordMeaningfulImpact($context, $firingNation, $cell, $missileKey, 'capital_damaged', [
            'damage_percent' => $percentage,
            'before_population' => $before,
            'after_population' => $cell->population,
            'capital_identity_preserved' => true,
            'minimum_population' => $minimum,
        ]);

        return max(0, $before - $after);
    }

    private function generateAndReceiveRefugees(
        TurnContext $context,
        Nation $recipient,
        MapCell $source,
        int $generated,
        string $missileKey,
        int $queueItemId,
    ): int {
        if ($generated < 1) {
            return 0;
        }
        $this->events->record($context, 'refugee_generated', $source, [
            'nation_id' => $source->owner_nation_id,
            'recipient_nation_id' => $recipient->id,
            'x' => $source->x, 'y' => $source->y,
            'missile_key' => $missileKey,
            'queue_item_id' => $queueItemId,
            'generated_population' => $generated,
        ], 'public');
        $settlementKeys = $context->ruleset->settings['military']['refugees']['settlement_facility_keys'] ?? [];
        $cells = MapCell::query()->where('owner_nation_id', $recipient->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $settlementKeys))
            ->with(['terrain', 'facility'])->orderBy('id')->lockForUpdate()->get()
            ->sortByDesc(fn (MapCell $cell): bool => $cell->facility?->key === 'capital');
        $remaining = $generated;
        foreach ($cells as $cell) {
            $maximum = $cell->facility?->key === 'capital'
                ? $context->ruleset->settings['capital_growth_maximum_population']
                : $context->ruleset->settings['turn_processing']['settlement']['attraction_maximum_population'];
            $applied = min($remaining, max(0, $maximum - $cell->population));
            if ($applied < 1) {
                continue;
            }
            $cell->population += $applied;
            $this->syncSettlementFacility($context, $cell);
            $cell->version++;
            $cell->save();
            $this->markCellChanged($context, $cell);
            $remaining -= $applied;
            if ($remaining === 0) {
                break;
            }
        }
        $received = $generated - $remaining;
        $this->events->record($context, 'refugee_received', $recipient, [
            'nation_id' => $recipient->id,
            'source_nation_id' => $source->owner_nation_id,
            'missile_key' => $missileKey,
            'queue_item_id' => $queueItemId,
            'generated_population' => $generated,
            'received_population' => $received,
            'unreceived_population' => $remaining,
        ], 'nation');

        return $received;
    }

    private function syncSettlementFacility(TurnContext $context, MapCell $cell): void
    {
        if ($cell->facility?->key === 'capital') {
            return;
        }
        foreach ($context->ruleset->settings['turn_processing']['settlement']['stages'] as $stage) {
            if ($cell->population >= $stage['minimum_population'] && $cell->population <= $stage['maximum_population']) {
                $facility = FacilityDefinition::query()->where('key', $stage['facility_key'])->firstOrFail();
                $this->cells->setFacility($cell, $facility);

                return;
            }
        }
        $this->cells->setFacility($cell, FacilityDefinition::query()->where('key', 'city')->firstOrFail());
    }

    /** @param array<string, mixed> $metadata */
    private function recordMeaningfulImpact(
        TurnContext $context,
        ?Nation $firingNation,
        MapCell $cell,
        string $missileKey,
        string $effect,
        array $metadata,
    ): void {
        $this->events->record($context, 'missile.impact', $cell, [
            'nation_id' => $cell->owner_nation_id,
            'target_nation_name' => $cell->ownerNation?->name,
            'firing_nation_id' => $firingNation?->id,
            'firing_nation_name' => $firingNation?->name,
            'missile_key' => $missileKey,
            'x' => $cell->x, 'y' => $cell->y,
            'effect' => $effect,
            ...$metadata,
        ], 'public');
    }

    private function markCellChanged(TurnContext $context, MapCell $cell): void
    {
        $context->state->markMapChunkChanged($cell->map_chunk_id);
        $this->changedCellIds[$cell->id] = true;
    }

    /**
     * @return array{
     *     intent: LaunchIntent,
     *     nation: Nation,
     *     fired: int,
     *     cost: int,
     *     ineffective: int,
     *     impacts: list<array<string, mixed>>
     * }
     */
    private function &launch(LaunchIntent $intent, Nation $nation): array
    {
        if ($intent->queueItemId === null) {
            throw new DomainException('A PR22 missile intent requires its queue item identity.');
        }
        if (! isset($this->launches[$intent->queueItemId])) {
            $this->launches[$intent->queueItemId] = [
                'intent' => $intent, 'nation' => $nation,
                'fired' => 0, 'cost' => 0, 'ineffective' => 0, 'impacts' => [],
            ];
        }

        return $this->launches[$intent->queueItemId];
    }
}
