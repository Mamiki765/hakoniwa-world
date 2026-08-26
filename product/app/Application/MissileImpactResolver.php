<?php

namespace App\Application;

use App\Domain\Facility\MissileBaseRules;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Monster\MonsterBehaviorResolver;
use App\Domain\Nation\NationProtectionPolicy;
use App\Domain\Secretary\SecretaryDemographicPolicy;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemProbability;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\LaunchIntent;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Support\Collection;

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
     *     impacts: list<array<string, mixed>>,
     *     firing_bases: array<int, array{x: int, y: int, facility_key: string, fired_shots: int}>,
     *     prepared: bool,
     *     footprint: list<string>,
     *     turn_start_monster: bool,
     *     missile_boundary_monster: bool,
     *     population_start: array<int, int>,
     *     population_remaining: array<int, int>,
     *     population_sync_base_id: int|null,
     *     spp_candidates: array<int, array{start_hp: int, host_nation_id: int}>,
     *     spp_qualified_monster_ids: array<int, true>,
     *     spp_evaluated: bool
     * }>
     */
    private array $launches = [];

    /** @var array<int, true> */
    private array $changedCellIds = [];

    /** @var array<string, MapCell>|null */
    private ?array $surfaceCellsByCoordinate = null;

    public function __construct(
        private readonly MissileBaseRules $baseRules,
        private readonly LaunchBaseExperienceService $baseExperience,
        private readonly MapCellStateService $cells,
        private readonly MonsterDamageService $monsterDamage,
        private readonly MonsterRemovalService $monsterRemoval,
        private readonly TurnEventRecorder $events,
        private readonly NationIdleCounterFinalizer $idleCounters,
        private readonly NationProtectionPolicy $nationProtection,
        private readonly MonsterBehaviorResolver $monsterBehaviors,
        private readonly KarmaTurnService $karma,
        private readonly NationLifecycleService $nationLifecycle,
        private readonly SecretaryExperienceAwardService $secretaryExperience,
        private readonly SecretaryItemProbability $itemProbability,
        private readonly SecretaryDemographicPolicy $demographics,
    ) {}

    /** @param array<string, MapCell>|null $surfaceCellsByCoordinate */
    public function begin(?array $surfaceCellsByCoordinate = null): void
    {
        $this->launches = [];
        $this->changedCellIds = [];
        $this->surfaceCellsByCoordinate = $surfaceCellsByCoordinate;
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
        if ($nation === null || ! in_array($nation->state, ['active', 'recovery'], true)) {
            return [...$metrics, 'changed_cell_ids' => []];
        }
        $capacity = $base->facility_experience !== null
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
                if (! $launch['prepared']) {
                    $this->prepareLaunchContext($context, $space, $nation, $intent, $settings, $launch);
                    $launch['population_sync_base_id'] = $base->id;
                } elseif ($launch['population_sync_base_id'] !== $base->id) {
                    $this->synchronizeLaunchPopulation($space, $launch);
                    $launch['population_sync_base_id'] = $base->id;
                }
                $nation->decrement('money', $cost);
                $nation->refresh();
                $impact = $this->impact($context, $space, $nation, $base, $intent, $settings);
                $this->recordKarmaForImpact($context, $nation, $intent, $launch, $impact);
                $context->state->consumeLaunchIntentShots($intent, 1);
                $remainingCapacity--;
                $launch['fired']++;
                $launch['cost'] += $cost;
                $launch['impacts'][] = $impact;
                $launch['firing_bases'][$base->id] ??= [
                    'x' => $base->x,
                    'y' => $base->y,
                    'facility_key' => (string) $base->facility->key,
                    'fired_shots' => 0,
                ];
                $launch['firing_bases'][$base->id]['fired_shots']++;
                $metrics['shots_fired']++;
                $metrics['money_spent'] += $cost;
                if ($impact['meaningful']) {
                    $metrics['meaningful_impacts']++;
                } elseif (! in_array($impact['effect'], ['defense_intercepted', 'secretary_intercepted'], true)) {
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

    /** @return array{launches: int, shots_fired: int, ineffective_impacts: int, idle_counter_resets: int} */
    public function finalize(TurnContext $context): array
    {
        $metrics = ['launches' => 0, 'shots_fired' => 0, 'ineffective_impacts' => 0, 'idle_counter_resets' => 0];
        /** @var array<int, int> $shotsFiredByNation */
        $shotsFiredByNation = [];
        foreach ($context->state->launchIntents() as $intent) {
            if (! in_array($intent->definitionKey, self::MISSILE_KEYS, true) || $intent->queueItemId === null) {
                continue;
            }
            $nation = Nation::query()->findOrFail($intent->nationId);
            $launch = $this->launches[$intent->queueItemId] ?? [
                'intent' => $intent, 'nation' => $nation, 'fired' => 0,
                'cost' => 0, 'ineffective' => 0, 'impacts' => [], 'firing_bases' => [],
                'prepared' => false, 'footprint' => [],
                'turn_start_monster' => false, 'missile_boundary_monster' => false,
                'population_start' => [], 'population_remaining' => [],
                'population_sync_base_id' => null,
                'spp_candidates' => [], 'spp_qualified_monster_ids' => [], 'spp_evaluated' => false,
            ];
            $this->evaluateSppSelfDestructSetup($context, $launch);
            $shotsFiredByNation[$nation->id] = ($shotsFiredByNation[$nation->id] ?? 0) + $launch['fired'];
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
                'firing_bases' => array_values($launch['firing_bases']),
                'impacts' => $launch['impacts'],
            ], 'private');
        }

        foreach ($shotsFiredByNation as $nationId => $shotsFired) {
            $context->state->recordMissileShotsFired($nationId, $shotsFired);
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            if ($this->idleCounters->finalize($context, $nation) === 'reset') {
                $metrics['idle_counter_resets']++;
            }
        }

        return $metrics;
    }

    /** @return array{karma_sanction_nations: int, karma_sanction_shots: int, karma_sanction_intercepted: int, karma_sanction_impacts: int} */
    public function resolveSanctions(TurnContext $context): array
    {
        $metrics = [
            'karma_sanction_nations' => 0,
            'karma_sanction_shots' => 0,
            'karma_sanction_intercepted' => 0,
            'karma_sanction_impacts' => 0,
        ];
        $space = MapSpace::query()->where('world_id', $context->world->id)
            ->where('key', 'surface')->firstOrFail();
        $streamVersion = $context->ruleset->settings['karma']['sanction']['random_stream_version'] ?? null;
        if ($streamVersion !== 1) {
            throw new DomainException('The active ruleset has an invalid KARMA sanction RNG contract.');
        }

        foreach (array_keys($context->state->karmaLedgers()) as $nationId) {
            $shots = $this->karma->sanctionCount($context, $nationId);
            $context->state->recordKarmaSanctions($nationId, $shots);
            if ($shots < 1) {
                continue;
            }
            $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->firstOrFail();
            $territory = collect($this->surfaceCellsByCoordinate ?? [])
                ->filter(static fn (MapCell $cell): bool => (int) $cell->map_space_id === (int) $space->id
                    && $cell->owner_nation_id === $nationId)
                ->sort(static fn (MapCell $left, MapCell $right): int => [$left->x, $left->y, $left->id] <=> [$right->x, $right->y, $right->id])
                ->values();
            $this->events->record($context, 'karma.sanction_decided', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'sanction_shots' => $shots,
                'territory_coordinate_count' => $territory->count(),
            ], 'public', 'warning', "箱庭連合は、{$nation->name}への制裁を決議しました。");
            $this->events->record($context, 'karma.sanction_launched', $nation, [
                'nation_id' => $nation->id,
                'nation_name' => $nation->name,
                'sanction_shots' => $shots,
            ], 'public', 'warning', "{$nation->name}に箱庭連合の制裁ミサイルが{$shots}発発射されました。");
            $metrics['karma_sanction_nations']++;
            $metrics['karma_sanction_shots'] += $shots;
            if ($territory->isEmpty()) {
                continue;
            }
            $stream = $context->random->stream(TurnRandomStreamFactory::karmaSanction($nationId, $streamVersion));
            for ($shot = 1; $shot <= $shots; $shot++) {
                $cell = $territory->get($stream->integer(0, $territory->count() - 1));
                if (! $cell instanceof MapCell) {
                    throw new DomainException('KARMA sanction selected an invalid territory cell.');
                }
                $cell->refresh()->load(['terrain', 'facility', 'ownerNation']);
                $base = [
                    'x' => $cell->x,
                    'y' => $cell->y,
                    'terrain_key' => $cell->terrain->key,
                    'meaningful' => false,
                    'effect' => 'ineffective_sea',
                ];
                $this->awardFinalDefenseArrivalExperience($context, $cell);
                $impact = $this->defenseInterception($context, $space, $cell, $base, 'missile')
                    ?? $this->ordinaryImpact($context, null, null, $cell, $base, 'missile', null);
                if (in_array($impact['effect'], ['defense_intercepted', 'secretary_intercepted'], true)) {
                    $metrics['karma_sanction_intercepted']++;
                } elseif ($impact['meaningful']) {
                    $metrics['karma_sanction_impacts']++;
                }
                $this->events->record($context, 'karma.sanction_impact', $cell, [
                    'nation_id' => $nation->id,
                    'nation_name' => $nation->name,
                    'sanction_shot' => $shot,
                    'x' => $cell->x,
                    'y' => $cell->y,
                    'meaningful' => $impact['meaningful'],
                    'effect' => $impact['effect'],
                ], 'admin');
            }
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $launch
     */
    private function prepareLaunchContext(
        TurnContext $context,
        MapSpace $space,
        Nation $firingNation,
        LaunchIntent $intent,
        array $settings,
        array &$launch,
    ): void {
        $radius = $settings['deviation_radius'] ?? null;
        if (! is_int($radius) || $radius < 0) {
            throw new DomainException('Missile deviation radius is invalid.');
        }
        $footprint = [];
        $cellIds = [];
        $targetNationIds = [];
        foreach ((new GridCoordinate($intent->targetX, $intent->targetY))->radius($radius) as $coordinate) {
            if ($coordinate->x < $space->min_x || $coordinate->x > $space->max_x
                || $coordinate->y < $space->min_y || $coordinate->y > $space->max_y) {
                continue;
            }
            $key = $coordinate->x.':'.$coordinate->y;
            $footprint[] = $key;
            $cell = $this->surfaceCellsByCoordinate[$key] ?? null;
            if (! $cell instanceof MapCell) {
                throw new DomainException('The locked surface-cell index is missing a launch footprint coordinate.');
            }
            $cellIds[] = $cell->id;
            if ($cell->owner_nation_id !== null) {
                $targetNationIds[$cell->owner_nation_id] = true;
            }
        }
        $turnStartMonster = $context->state->monsterSnapshotIntersects('turn_start', $footprint);
        $missileBoundaryMonster = $context->state->monsterSnapshotIntersects('missile_boundary', $footprint);
        $eligible = in_array(
            $intent->definitionKey,
            $context->ruleset->settings['karma']['anti_monster_missile_keys'] ?? [],
            true,
        );
        $intent->classifyAntiMonsterContext($eligible && ($turnStartMonster || $missileBoundaryMonster));
        $populationStart = [];
        if ($targetNationIds !== []) {
            $rows = MapCell::query()->whereIn('owner_nation_id', array_keys($targetNationIds))
                ->selectRaw('owner_nation_id, SUM(population) AS aggregate')
                ->groupBy('owner_nation_id')->pluck('aggregate', 'owner_nation_id');
            foreach ($targetNationIds as $nationId => $_present) {
                $populationStart[$nationId] = (int) ($rows[$nationId] ?? 0);
            }
        }
        $sppCandidates = [];
        if ($intent->definitionKey === 'spp_missile' && $cellIds !== []) {
            $occupancies = MonsterOccupancy::query()->whereIn('map_cell_id', $cellIds)
                ->with(['cell', 'monster.definition'])->orderBy('monster_instance_id')->lockForUpdate()->get();
            foreach ($occupancies as $occupancy) {
                $monster = $occupancy->monster;
                $hostNationId = $occupancy->cell->owner_nation_id;
                if ($monster->state !== 'alive' || $monster->current_hp <= 1
                    || $hostNationId === null || $hostNationId === $firingNation->id
                    || $this->monsterBehaviors->forDefinition($monster->definition)->specialAction
                        !== MonsterBehaviorResolver::NUCLEAR_AT_HP_ONE) {
                    continue;
                }
                $sppCandidates[$monster->id] = [
                    'start_hp' => (int) $monster->current_hp,
                    'host_nation_id' => (int) $hostNationId,
                ];
            }
        }
        $launch['prepared'] = true;
        $launch['footprint'] = $footprint;
        $launch['turn_start_monster'] = $turnStartMonster;
        $launch['missile_boundary_monster'] = $missileBoundaryMonster;
        $launch['population_start'] = $populationStart;
        $launch['population_remaining'] = $populationStart;
        $launch['spp_candidates'] = $sppCandidates;
        $this->events->record($context, 'karma.anti_monster_classified', $firingNation, [
            'nation_id' => $firingNation->id,
            'queue_item_id' => $intent->queueItemId,
            'missile_key' => $intent->definitionKey,
            'footprint_coordinate_count' => count($footprint),
            'turn_start_monster' => $turnStartMonster,
            'missile_boundary_monster' => $missileBoundaryMonster,
            'anti_monster_context' => $intent->antiMonsterContext(),
        ], 'admin');
    }

    /** @param array<string, mixed> $launch */
    private function synchronizeLaunchPopulation(MapSpace $space, array &$launch): void
    {
        $nationIds = array_map('intval', array_keys($launch['population_remaining']));
        if ($nationIds === []) {
            return;
        }
        $rows = MapCell::query()->where('map_space_id', $space->id)
            ->whereIn('owner_nation_id', $nationIds)
            ->groupBy('owner_nation_id')
            ->selectRaw('owner_nation_id, SUM(population) AS aggregate')
            ->pluck('aggregate', 'owner_nation_id');
        foreach ($nationIds as $nationId) {
            $launch['population_remaining'][$nationId] = (int) ($rows[$nationId] ?? 0);
        }
    }

    /** @param array<string, mixed> $launch
     * @param  array<string, mixed>  $impact
     */
    private function recordKarmaForImpact(
        TurnContext $context,
        Nation $firingNation,
        LaunchIntent $intent,
        array &$launch,
        array $impact,
    ): void {
        if (($impact['meaningful'] ?? false) !== true) {
            return;
        }
        $monsterId = $impact['monster_instance_id'] ?? null;
        if ($intent->definitionKey === 'spp_missile' && is_int($monsterId)
            && isset($launch['spp_candidates'][$monsterId])
            && ($impact['before_hp'] ?? null) > 1 && ($impact['after_hp'] ?? null) === 1) {
            $launch['spp_qualified_monster_ids'][$monsterId] = true;
        }
        $targetNationId = $impact['target_nation_id'] ?? null;
        if (! is_int($targetNationId) || $targetNationId === $intent->nationId
            || ! array_key_exists($targetNationId, $context->state->karmaStartSnapshots())) {
            return;
        }
        $points = $this->impactCrimePoints($context, $impact);
        $targetStartKarma = $context->state->karmaStartSnapshot($targetNationId);
        $attackerStartKarma = $context->state->karmaStartSnapshot($intent->nationId);
        $antiMonsterExempt = $intent->definitionKey !== 'land_destruction_missile'
            && $intent->antiMonsterContext();
        $baseCrimePoints = $targetStartKarma <= 0 && ! $antiMonsterExempt ? $points : 0;
        $collarTriggered = false;
        $crimePoints = $baseCrimePoints;
        $collar = $this->collarEffect($context, (int) $intent->nationId, SecretaryItemGameplayContract::KARMA_CRIME_DOUBLE_CHANCE);
        if ($baseCrimePoints > 0 && $collar !== null && $attackerStartKarma >= 1
            && $this->collarQualifyingImpact($impact)) {
            $impactIndex = (int) $launch['fired'];
            $draw = $context->random->stream(TurnRandomStreamFactory::secretaryCollar(
                (int) $intent->nationId,
                (int) $intent->queueItemId,
                $impactIndex,
                (int) $collar['effect']['random_stream_version'],
            ))->integer(0, 9_999);
            $chance = ((int) $collar['effect']['parameters']['base_percent']
                + (int) $collar['item']['level'] * (int) $collar['effect']['parameters']['percent_per_level']) * 100;
            $collarTriggered = $this->itemProbability->passesBasisPointDraw($draw, $chance);
            if ($collarTriggered) {
                $crimePoints = $baseCrimePoints * (int) $collar['effect']['parameters']['multiplier'];
            }
        }
        if ($crimePoints > 0) {
            $context->state->addKarmaCrime($intent->nationId, $crimePoints);
        }
        $context->state->recordHostileImpactReceived($targetNationId);
        $allianceMoney = 0;
        if ($attackerStartKarma <= 0 && $targetStartKarma > 0) {
            $perKarma = $context->ruleset->settings['karma']['alliance_reward_money_per_karma_per_impact'] ?? null;
            if ($perKarma !== 1) {
                throw new DomainException('The active ruleset has an invalid KARMA alliance reward contract.');
            }
            $allianceMoney = $targetStartKarma * $perKarma;
            $context->state->addAllianceMoney($intent->nationId, $allianceMoney);
        }
        $beforePopulation = $impact['before_population'] ?? null;
        $afterPopulation = $impact['after_population'] ?? null;
        if (is_int($beforePopulation) && is_int($afterPopulation) && $beforePopulation > $afterPopulation
            && isset($launch['population_remaining'][$targetNationId])) {
            $launch['population_remaining'][$targetNationId] = max(
                0,
                $launch['population_remaining'][$targetNationId] - ($beforePopulation - $afterPopulation),
            );
            if (($launch['population_start'][$targetNationId] ?? 0) > 100
                && $launch['population_remaining'][$targetNationId] === 100
                && ! $context->state->karmaLedgerForNation($targetNationId)['recovery_entry']) {
                $context->state->markRecoveryEntry($targetNationId);
                $this->events->record($context, 'recovery.entry_qualified', null, [
                    'nation_id' => $targetNationId,
                    'firing_nation_id' => $intent->nationId,
                    'queue_item_id' => $intent->queueItemId,
                    'sequence_start_population' => $launch['population_start'][$targetNationId],
                    'population_after_impact' => 100,
                ], 'admin');
            }
        }
        $this->events->record($context, 'karma.missile_impact', null, [
            'nation_id' => $intent->nationId,
            'target_nation_id' => $targetNationId,
            'queue_item_id' => $intent->queueItemId,
            'missile_key' => $intent->definitionKey,
            'effect' => $impact['effect'],
            'impact_category_points' => $points,
            'crime_points' => $crimePoints,
            'base_crime_points' => $baseCrimePoints,
            'collar_triggered' => $collarTriggered,
            'final_crime_points' => $crimePoints,
            'attacker_start_karma' => $attackerStartKarma,
            'target_start_karma' => $targetStartKarma,
            'turn_start_monster' => $launch['turn_start_monster'],
            'missile_boundary_monster' => $launch['missile_boundary_monster'],
            'anti_monster_context' => $intent->antiMonsterContext(),
            'anti_monster_exempt' => $antiMonsterExempt,
            'alliance_money' => $allianceMoney,
        ], 'admin');
        if ($crimePoints > 0 && $firingNation->state === 'recovery') {
            $this->nationLifecycle->exitRecoveryForCrime(
                $context,
                $firingNation,
                $crimePoints,
                (int) $intent->queueItemId,
            );
        }
    }

    /** @param array<string, mixed> $impact */
    private function impactCrimePoints(TurnContext $context, array $impact): int
    {
        $points = $context->ruleset->settings['karma']['impact_points'] ?? null;
        if (! is_array($points)) {
            throw new DomainException('The active ruleset has no KARMA impact categories.');
        }
        $removedFacility = $impact['removed_facility_key'] ?? null;
        if ($removedFacility === 'seabed_oil_field') {
            return $points['seabed_oil_field_destroyed'];
        }
        if ($removedFacility === 'seabed_base') {
            return $points['seabed_base_destroyed'];
        }
        if (($impact['effect'] ?? null) === 'terrain_destroyed') {
            return $points['land_destroyed'];
        }
        if (($impact['effect'] ?? null) === 'capital_damaged') {
            return $points['capital_above_minimum'];
        }
        if (($impact['effect'] ?? null) === 'capital_at_minimum') {
            return $points['capital_at_minimum'];
        }
        if (is_string($removedFacility) && $removedFacility !== '') {
            return $points['settlement_or_facility'];
        }
        if (in_array($impact['effect'] ?? null, ['land_scorched', 'water_facility_destroyed'], true)) {
            return $points['terrain'];
        }

        return 0;
    }

    /** @param array<string, mixed> $launch */
    private function evaluateSppSelfDestructSetup(TurnContext $context, array &$launch): void
    {
        if ($launch['spp_evaluated'] || ! $launch['prepared']
            || $launch['intent']->definitionKey !== 'spp_missile'
            || $launch['spp_qualified_monster_ids'] === []
            || ! array_key_exists($launch['nation']->id, $context->state->karmaStartSnapshots())) {
            $launch['spp_evaluated'] = true;

            return;
        }
        $launch['spp_evaluated'] = true;
        $monsters = MonsterInstance::query()->whereIn('id', array_keys($launch['spp_qualified_monster_ids']))
            ->orderBy('id')->lockForUpdate()->get(['id', 'current_hp', 'state'])->keyBy('id');
        foreach ($launch['spp_candidates'] as $monsterId => $candidate) {
            if (! isset($launch['spp_qualified_monster_ids'][$monsterId])) {
                continue;
            }
            $monster = $monsters->get($monsterId);
            if (! $monster instanceof MonsterInstance || $monster->state !== 'alive'
                || $monster->current_hp !== 1 || $candidate['start_hp'] <= 1) {
                continue;
            }
            $nation = $launch['nation'];
            $points = $context->ruleset->settings['karma']['spp_self_destruct_setup_points'] ?? null;
            if ($points !== 20) {
                throw new DomainException('The active ruleset has an invalid deliberate SPP KARMA contract.');
            }
            $context->state->addKarmaCrime($nation->id, $points);
            $snapshot = $context->state->secretarySnapshot($nation->id);
            $speaker = $snapshot['name'] ?? '秘書';
            $message = $speaker.'「'.$nation->owner_name.'様……先ほどのSPPミサイルの本数ですが……」（カルマ +20）';
            $this->events->record($context, 'karma.spp_self_destruct_setup', $nation, [
                'nation_id' => $nation->id,
                'player_address' => $nation->owner_name,
                'queue_item_id' => $launch['intent']->queueItemId,
                'monster_instance_id' => $monsterId,
                'host_nation_id' => $candidate['host_nation_id'],
                'start_hp' => $candidate['start_hp'],
                'final_hp' => 1,
                'crime_points' => $points,
                'secretary_name' => $snapshot['name'],
            ], 'private', 'warning', $message);

            break;
        }
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
        $cell = $this->surfaceCellAt($space, $coordinate);
        $base['terrain_key'] = $cell->terrain->key;
        $protectedNationId = $this->nationProtection->protectedNationId(
            $context,
            $coordinate->x,
            $coordinate->y,
            $firingNation->id,
        );
        if ($protectedNationId !== null) {
            $protectedNation = Nation::query()->findOrFail($protectedNationId);
            $recoveryProtection = $context->state->recoveryTerritoryNationId(
                $coordinate->x,
                $coordinate->y,
            ) === $protectedNationId;
            $missileName = match ($intent->definitionKey) {
                'missile' => 'ミサイル', 'pp_missile' => 'PPミサイル',
                'land_destruction_missile' => '陸地破壊弾', 'spp_missile' => 'SPPミサイル',
                default => $intent->definitionKey,
            };
            $this->events->record($context, $recoveryProtection
                ? 'missile.recovery_protected'
                : 'missile.dormancy_protected', $cell, [
                    'nation_id' => $protectedNationId,
                    'nation_name' => $protectedNation->name,
                    'x' => $coordinate->x,
                    'y' => $coordinate->y,
                    'missile_key' => $intent->definitionKey,
                    'missile_name' => $missileName,
                ], 'public', 'info', $recoveryProtection
                ? "{$protectedNation->name}({$coordinate->x},{$coordinate->y})への{$missileName}攻撃は箱庭協定によって禁じられ、空中で自爆しました"
                : "{$protectedNation->name}({$coordinate->x},{$coordinate->y})に{$missileName}が落下しましたが、まるで時間が止まったかのように動かなくなった後、空中で自爆しました",
            );

            return [
                ...$base,
                'effect' => $recoveryProtection ? 'recovery_protected' : 'dormant_capital_protected',
                'protected_nation_id' => $protectedNationId,
            ];
        }
        $this->awardFinalDefenseArrivalExperience($context, $cell);
        $defense = $this->defenseInterception($context, $space, $cell, $base, $intent->definitionKey);
        if ($defense !== null) {
            return $defense;
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

    private function awardFinalDefenseArrivalExperience(TurnContext $context, MapCell $cell): void
    {
        $targetNationId = $cell->owner_nation_id;
        if ($targetNationId === null || ! $context->state->hasSecretarySnapshot($targetNationId)) {
            return;
        }
        $this->secretaryExperience->awardSkill(
            $context,
            $targetNationId,
            SecretarySkillCatalog::FINAL_DEFENSE_LINE,
        );
    }

    /**
     * Resolve the source-audited Hakoniwa 2 / 2+ defense ring before monster,
     * terrain, direct-SPP resistance, or Secretary effects. The impact cell
     * itself is deliberately excluded.
     *
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function defenseInterception(
        TurnContext $context,
        MapSpace $space,
        MapCell $cell,
        array $base,
        string $missileKey,
    ): ?array {
        $contract = $context->ruleset->settings['military']['defense_interception'] ?? null;
        if ($contract === null) {
            return null;
        }
        if (! is_array($contract)
            || ($contract['facility_key'] ?? null) !== 'defense'
            || ($contract['radius'] ?? null) !== 2
            || ($contract['exclude_center'] ?? null) !== true
            || ($contract['defense_target_cells'] ?? null) !== 'exclude'
            || ($contract['facility_owner_scope'] ?? null) !== 'any'
            || ($contract['monster_occupied_cells'] ?? null) !== 'include'
            || ($contract['self_fired_missiles'] ?? null) !== 'include'
            || ($contract['overlap_resolution'] ?? null) !== 'single_interception'
            || ($contract['resolve_before'] ?? null) !== 'secretary'
            || ! in_array($missileKey, $contract['missile_keys'] ?? [], true)) {
            throw new DomainException('The active ruleset has an invalid defense interception contract.');
        }
        if ($cell->facility?->key === 'defense') {
            return null;
        }

        $center = new GridCoordinate($cell->x, $cell->y);
        $defenses = $this->coveringDefenses($space, $center);
        if ($defenses->isEmpty()) {
            return null;
        }

        $nationId = $cell->owner_nation_id;
        if ($nationId !== null) {
            $this->events->record($context, 'missile.defense_intercepted', $cell, [
                'nation_id' => $nationId,
                'nation_name' => $cell->ownerNation?->name,
                'x' => $cell->x,
                'y' => $cell->y,
                'missile_key' => $missileKey,
                'covering_defense_count' => $defenses->count(),
                'covering_defense_cell_ids' => $defenses->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            ], 'nation');
        }

        return [
            ...$base,
            'effect' => 'defense_intercepted',
            'target_nation_id' => $nationId,
            'target_nation_name' => $cell->ownerNation?->name,
            'covering_defense_count' => $defenses->count(),
        ];
    }

    private function surfaceCellAt(MapSpace $space, GridCoordinate $coordinate): MapCell
    {
        if ($this->surfaceCellsByCoordinate !== null) {
            $cell = $this->surfaceCellsByCoordinate[$coordinate->x.':'.$coordinate->y] ?? null;
            if (! $cell instanceof MapCell || (int) $cell->map_space_id !== (int) $space->id) {
                throw new DomainException('The locked surface-cell index is missing a missile impact coordinate.');
            }

            return $cell;
        }

        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)
            ->with(['terrain', 'facility', 'ownerNation'])->lockForUpdate()->firstOrFail();
    }

    /** @return Collection<int, MapCell> */
    private function coveringDefenses(MapSpace $space, GridCoordinate $center): Collection
    {
        if ($this->surfaceCellsByCoordinate !== null) {
            return collect($center->radius(2))
                ->filter(static fn (GridCoordinate $coordinate): bool => $center->distanceTo($coordinate) >= 1)
                ->map(fn (GridCoordinate $coordinate): ?MapCell => $this->surfaceCellsByCoordinate[
                    $coordinate->x.':'.$coordinate->y
                ] ?? null)
                ->filter(static fn (?MapCell $candidate): bool => $candidate instanceof MapCell
                    && (int) $candidate->map_space_id === (int) $space->id
                    && $candidate->facility?->key === 'defense')
                ->sortBy('id')
                ->values();
        }

        return MapCell::query()
            ->where('map_space_id', $space->id)
            ->whereBetween('x', [$center->x - 2, $center->x + 2])
            ->whereBetween('y', [$center->y - 2, $center->y + 2])
            ->whereHas('facility', fn ($query) => $query->where('key', 'defense'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'x', 'y', 'owner_nation_id'])
            ->filter(static function (MapCell $candidate) use ($center): bool {
                $distance = $center->distanceTo(new GridCoordinate($candidate->x, $candidate->y));

                return $distance >= 1 && $distance <= 2;
            })
            ->values();
    }

    /** @param array<string, mixed> $base
     * @return array<string, mixed>
     */
    private function ordinaryImpact(
        TurnContext $context,
        ?Nation $firingNation,
        ?MapCell $firingBase,
        MapCell $cell,
        array $base,
        string $missileKey,
        ?int $queueItemId,
    ): array {
        $resistance = $context->ruleset->settings['military']['seabed_base_resistance'] ?? null;
        if (is_array($resistance)
            && $cell->facility?->key === ($resistance['facility_key'] ?? null)
            && in_array($missileKey, $resistance['ineffective_missile_keys'] ?? [], true)) {
            return [...$base, 'effect' => 'seabed_base_resisted'];
        }
        $defenseResistance = $context->ruleset->settings['military']['defense_spp_resistance'] ?? null;
        if (is_array($defenseResistance)
            && $cell->facility?->key === ($defenseResistance['facility_key'] ?? null)
            && in_array($missileKey, $defenseResistance['ineffective_missile_keys'] ?? [], true)) {
            return [...$base, 'effect' => 'defense_resisted'];
        }

        $occupancy = MonsterOccupancy::query()->where('map_cell_id', $cell->id)
            ->with('monster.definition')->lockForUpdate()->first();
        if ($occupancy !== null) {
            $result = $this->monsterDamage->applyDamage(
                $occupancy->monster,
                1,
                $missileKey,
                $firingNation,
                $firingBase?->facility_experience !== null ? $firingBase : null,
                $cell,
                $context,
            );
            $terrainScorched = $result->killed
                ? $this->scorchWasteland($context, $cell)
                : false;
            $scorchMetadata = $terrainScorched
                ? [
                    'terrain_scorched' => true,
                    'from_terrain_key' => 'wasteland',
                    'to_terrain_key' => 'scorched',
                ]
                : ['terrain_scorched' => false];
            $this->recordMeaningfulImpact($context, $firingNation, $cell, $missileKey, 'monster_hit', [
                'monster_key' => $occupancy->monster->definition->key,
                'damage_status' => $result->status,
                'before_hp' => $result->beforeHp,
                'after_hp' => $result->afterHp,
                ...$scorchMetadata,
            ]);

            return [
                ...$base, 'meaningful' => true, 'effect' => $result->status,
                'target_nation_id' => $cell->owner_nation_id,
                'target_nation_name' => $cell->ownerNation?->name,
                'monster_instance_id' => $occupancy->monster->id,
                'monster_key' => $occupancy->monster->definition->key,
                'before_hp' => $result->beforeHp, 'after_hp' => $result->afterHp,
                ...$scorchMetadata,
            ];
        }
        $interception = $this->secretaryInterception($context, $cell, $base, $missileKey);
        if ($interception !== null) {
            return $interception;
        }
        $beforeTerrain = $cell->terrain->key;
        $beforeFacility = $cell->facility?->key;
        $beforePopulation = $cell->population;
        $targetNationId = $cell->owner_nation_id;
        $targetNationName = $cell->ownerNation?->name;
        if ($beforeTerrain === 'wasteland') {
            $this->scorchWasteland($context, $cell);
            $this->recordMeaningfulImpact($context, $firingNation, $cell, $missileKey, 'land_scorched', [
                'from_terrain_key' => $beforeTerrain,
                'to_terrain_key' => $cell->terrain->key,
                'terrain_only' => true,
            ], $targetNationId, $targetNationName);

            return [
                ...$base, 'meaningful' => true, 'effect' => 'land_scorched',
                'target_nation_id' => $targetNationId, 'target_nation_name' => $targetNationName,
                'from_terrain_key' => $beforeTerrain, 'to_terrain_key' => $cell->terrain->key,
                'preserved_facility_key' => $beforeFacility,
                'before_population' => $beforePopulation, 'after_population' => $cell->population,
                'terrain_only' => true,
            ];
        }
        if ($beforeFacility === 'capital') {
            $loss = $this->damageCapital($context, $firingNation, $cell, $missileKey, 10);
            $experienceContract = $context->ruleset->settings['military']['launch_base_experience'] ?? null;
            $capitalMultiplier = is_array($experienceContract)
                ? ($experienceContract['settlement_hit']['capital_population_loss_multiplier'] ?? null)
                : null;
            if (is_array($experienceContract) && (! is_int($capitalMultiplier) || $capitalMultiplier < 1)) {
                throw new DomainException('The active ruleset has an invalid Capital experience multiplier.');
            }
            $experience = $firingNation !== null && $firingBase !== null
                ? $this->creditSettlementExperience(
                    $context,
                    $firingNation,
                    $firingBase,
                    $missileKey,
                    $capitalMultiplier === null ? $loss : $loss * $capitalMultiplier,
                )
                : 0;
            $refugees = $firingNation !== null && $queueItemId !== null
                ? $this->generateAndReceiveRefugees(
                    $context,
                    $firingNation,
                    $cell,
                    intdiv($loss, 2),
                    $missileKey,
                    $queueItemId,
                    $targetNationId,
                )
                : 0;

            return [
                ...$base, 'meaningful' => $loss > 0,
                'effect' => $loss > 0 ? 'capital_damaged' : 'capital_at_minimum',
                'target_nation_id' => $targetNationId, 'target_nation_name' => $targetNationName,
                'before_population' => $beforePopulation, 'after_population' => $cell->population,
                'refugees' => $refugees,
                'firing_base_experience_applied' => $experience,
            ];
        }
        $isWater = in_array($beforeTerrain, ['sea', 'shallow'], true);
        if ($isWater && $beforeFacility === null) {
            return $base;
        }
        if ($beforeTerrain === 'scorched' && $beforeFacility === null && $beforePopulation === 0) {
            return [...$base, 'effect' => 'ineffective_barren_land'];
        }
        $settlement = in_array($beforeFacility, ['village', 'town', 'city'], true);
        $this->cells->setFacility($cell, null);
        if (! $isWater) {
            $this->cells->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'scorched')->firstOrFail());
        } else {
            $cell->owner_nation_id = null;
            $cell->setRelation('ownerNation', null);
        }
        $cell->population = 0;
        $cell->version++;
        $cell->save();
        $this->markCellChanged($context, $cell);
        $refugees = $settlement && $beforePopulation > 0 && $firingNation !== null && $queueItemId !== null
            ? $this->generateAndReceiveRefugees(
                $context,
                $firingNation,
                $cell,
                intdiv($beforePopulation, 2),
                $missileKey,
                $queueItemId,
                $targetNationId,
            )
            : 0;
        $experience = $settlement && $firingNation !== null && $firingBase !== null
            ? $this->creditSettlementExperience(
                $context,
                $firingNation,
                $firingBase,
                $missileKey,
                $beforePopulation,
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
            'firing_base_experience_applied' => $experience,
        ], $targetNationId, $targetNationName);

        return [
            ...$base, 'meaningful' => true, 'effect' => $effect,
            'target_nation_id' => $targetNationId, 'target_nation_name' => $targetNationName,
            'from_terrain_key' => $beforeTerrain, 'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility, 'before_population' => $beforePopulation,
            'refugees' => $refugees, 'firing_base_experience_applied' => $experience,
        ];
    }

    private function creditSettlementExperience(
        TurnContext $context,
        Nation $firingNation,
        MapCell $firingBase,
        string $missileKey,
        int $populationBasis,
    ): int {
        $settings = $context->ruleset->settings['military']['launch_base_experience']['settlement_hit'] ?? null;
        if (! is_array($settings)
            || ! in_array($missileKey, $settings['missile_keys'] ?? [], true)
            || $firingBase->facility_experience === null) {
            return 0;
        }
        $divisor = $settings['population_divisor'] ?? null;
        if (! is_int($divisor) || $divisor < 1) {
            throw new DomainException('The active ruleset has an invalid settlement-hit experience divisor.');
        }

        return $this->baseExperience->credit(
            $firingBase,
            $firingNation,
            intdiv(max(0, $populationBasis), $divisor),
            $context,
        );
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
        $occupancy = MonsterOccupancy::query()->where('map_cell_id', $cell->id)
            ->lockForUpdate()->first();
        if ($occupancy === null) {
            $interception = $this->secretaryInterception(
                $context,
                $cell,
                $base,
                'land_destruction_missile',
            );
            if ($interception !== null) {
                return $interception;
            }
        }
        $beforeTerrain = $cell->terrain->key;
        $beforeFacility = $cell->facility?->key;
        $beforePopulation = $cell->population;
        $targetNationId = $cell->owner_nation_id;
        $targetNationName = $cell->ownerNation?->name;
        if ($beforeFacility === 'capital') {
            $loss = $this->damageCapital($context, $firingNation, $cell, 'land_destruction_missile', 30);

            return [
                ...$base, 'meaningful' => $loss > 0,
                'effect' => $loss > 0 ? 'capital_damaged' : 'capital_at_minimum',
                'target_nation_id' => $targetNationId, 'target_nation_name' => $targetNationName,
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
        if (in_array($beforeTerrain, ['sea', 'shallow'], true) && $beforeFacility !== null) {
            $cell->owner_nation_id = null;
            $cell->setRelation('ownerNation', null);
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
        ], $targetNationId, $targetNationName);

        return [
            ...$base, 'meaningful' => true, 'effect' => 'terrain_destroyed',
            'target_nation_id' => $targetNationId, 'target_nation_name' => $targetNationName,
            'from_terrain_key' => $beforeTerrain, 'to_terrain_key' => $cell->terrain->key,
            'removed_facility_key' => $beforeFacility, 'before_population' => $beforePopulation,
            'after_population' => 0, 'monster_removed' => $monsterRemoved, 'refugees' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>|null
     */
    private function secretaryInterception(
        TurnContext $context,
        MapCell $cell,
        array $base,
        string $missileKey,
    ): ?array {
        $nationId = $cell->owner_nation_id;
        if ($nationId === null || ! $context->state->hasSecretarySnapshot($nationId)) {
            return null;
        }
        $effect = $context->ruleset->settings['secretary']['skills'][SecretarySkillCatalog::FINAL_DEFENSE_LINE]['effect'] ?? null;
        if (! is_array($effect)
            || ($effect['type'] ?? null) !== 'final_defense_line'
            || ($effect['interceptions_per_level_per_turn'] ?? null) !== 1
            || ($effect['normal_defense_resolves_first'] ?? null) !== true
            || ($effect['exclude_monster_occupied_cells'] ?? null) !== true) {
            throw new DomainException('The active ruleset has an invalid Secretary final-defense effect.');
        }
        if (! $context->state->consumeFinalDefenseInterception($nationId)) {
            return null;
        }

        $snapshot = $context->state->secretarySnapshot($nationId);
        $secretaryLabel = $snapshot['name'] === null ? '秘書' : '秘書の'.$snapshot['name'];
        $this->events->record($context, 'secretary.missile_intercepted', $cell, [
            'nation_id' => $nationId,
            'nation_name' => $cell->ownerNation?->name,
            'x' => $cell->x,
            'y' => $cell->y,
            'missile_key' => $missileKey,
            'secretary_name' => $snapshot['name'],
            'secretary_label' => $secretaryLabel,
            'interception_number' => $context->state->finalDefenseInterceptionsUsed($nationId),
        ], 'nation');

        return [
            ...$base,
            'effect' => 'secretary_intercepted',
            'target_nation_id' => $nationId,
            'target_nation_name' => $cell->ownerNation?->name,
            'secretary_name' => $snapshot['name'],
        ];
    }

    private function damageCapital(
        TurnContext $context,
        ?Nation $firingNation,
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
        ?int $sourceNationId = null,
    ): int {
        if ($generated < 1) {
            return 0;
        }
        $sourceNationId ??= $source->owner_nation_id;
        $baseGenerated = $generated;
        if ($sourceNationId !== null && $sourceNationId !== $recipient->id
            && array_key_exists($sourceNationId, $context->state->karmaStartSnapshots())
            && $context->state->karmaStartSnapshot($recipient->id) <= 0) {
            $targetKarma = $context->state->karmaStartSnapshot($sourceNationId);
            if ($targetKarma > 0) {
                $bonus = intdiv($baseGenerated * $targetKarma, 100);
                $generated += $bonus;
                $this->events->record($context, 'karma.refugee_bonus', $recipient, [
                    'nation_id' => $recipient->id,
                    'source_nation_id' => $sourceNationId,
                    'queue_item_id' => $queueItemId,
                    'missile_key' => $missileKey,
                    'target_start_karma' => $targetKarma,
                    'base_refugees' => $baseGenerated,
                    'bonus_refugees' => $bonus,
                    'total_refugees' => $generated,
                ], 'admin');
            }
        }
        $collar = $this->collarEffect($context, (int) $recipient->id, SecretaryItemGameplayContract::REFUGEE_GENERATION_PERCENT);
        if ($collar !== null && $context->state->karmaStartSnapshot((int) $recipient->id) >= 1) {
            $percent = (int) $collar['effect']['parameters']['base_percent']
                + (int) $collar['item']['level'] * (int) $collar['effect']['parameters']['percent_per_level'];
            $generated += intdiv($generated * $percent, 100);
        }
        $this->events->record($context, 'refugee_generated', $source, [
            'nation_id' => $sourceNationId,
            'recipient_nation_id' => $recipient->id,
            'x' => $source->x, 'y' => $source->y,
            'missile_key' => $missileKey,
            'queue_item_id' => $queueItemId,
            'generated_population' => $generated,
        ], 'public');
        $settlementKeys = $context->ruleset->settings['military']['refugees']['settlement_facility_keys'] ?? [];
        $attractionMaximum = $context->ruleset->settings['turn_processing']['settlement']['attraction_maximum_population'];
        if ($this->demographics->enabled($context->ruleset->settings)
            && $context->state->hasSecretarySnapshot($recipient->id)) {
            $attractionMaximum = $this->demographics->attractionMaximum(
                $context->ruleset->settings,
                $attractionMaximum,
                $context->state->secretarySkillLevel(
                    $recipient->id,
                    SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
                ),
            );
        }
        $cells = MapCell::query()->where('owner_nation_id', $recipient->id)
            ->whereHas('facility', fn ($query) => $query->whereIn('key', $settlementKeys))
            ->with(['terrain', 'facility'])->orderBy('id')->lockForUpdate()->get()
            ->sortByDesc(fn (MapCell $cell): bool => $cell->facility?->key === 'capital');
        $remaining = $generated;
        foreach ($cells as $cell) {
            $maximum = $cell->facility?->key === 'capital'
                ? $context->ruleset->settings['capital_growth_maximum_population']
                : $attractionMaximum;
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
        $context->state->addRefugeesReceived($recipient->id, $received);
        $this->events->record($context, 'refugee_received', $recipient, [
            'nation_id' => $recipient->id,
            'source_nation_id' => $sourceNationId,
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

    /** @return array{item: array<string, mixed>, effect: array<string, mixed>}|null */
    private function collarEffect(TurnContext $context, int $nationId, string $effectType): ?array
    {
        if (! $context->state->hasSecretaryItemEffectSnapshot($nationId)) {
            return null;
        }
        $found = null;
        foreach ($context->state->secretaryItemEffectSnapshot($nationId)['items'] as $item) {
            if ($item['item_key'] !== 'collar') {
                continue;
            }
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] !== $effectType) {
                    continue;
                }
                if ($found !== null) {
                    throw new DomainException('Secretary Collar snapshot contains a duplicate effect.');
                }
                $found = ['item' => $item, 'effect' => $effect];
            }
        }

        return $found;
    }

    /** @param array<string, mixed> $impact */
    private function collarQualifyingImpact(array $impact): bool
    {
        return in_array($impact['removed_facility_key'] ?? null, ['village', 'town', 'city'], true)
            || in_array($impact['effect'] ?? null, ['capital_damaged', 'capital_at_minimum'], true);
    }

    /** @param array<string, mixed> $metadata */
    private function recordMeaningfulImpact(
        TurnContext $context,
        ?Nation $firingNation,
        MapCell $cell,
        string $missileKey,
        string $effect,
        array $metadata,
        ?int $targetNationId = null,
        ?string $targetNationName = null,
    ): void {
        $this->events->record($context, 'missile.impact', $cell, [
            'nation_id' => $targetNationId ?? $cell->owner_nation_id,
            'target_nation_name' => $targetNationName ?? $cell->ownerNation?->name,
            'firing_nation_id' => $firingNation?->id,
            'firing_nation_name' => $firingNation === null ? '箱庭連合' : $firingNation->name,
            'firing_source' => $firingNation === null ? 'hakoniwa_alliance' : 'player_nation',
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

    private function scorchWasteland(TurnContext $context, MapCell $cell): bool
    {
        if ($cell->terrain->key !== 'wasteland') {
            return false;
        }

        $scorched = TerrainDefinition::query()->where('key', 'scorched')->firstOrFail();
        if (! $this->cells->scorchWasteland($cell, $scorched)) {
            throw new DomainException('Wasteland missile impact could not transition to scorched terrain.');
        }
        $cell->version++;
        $cell->save();
        $this->markCellChanged($context, $cell);

        return true;
    }

    /**
     * @return array{
     *     intent: LaunchIntent,
     *     nation: Nation,
     *     fired: int,
     *     cost: int,
     *     ineffective: int,
     *     impacts: list<array<string, mixed>>,
     *     firing_bases: array<int, array{x: int, y: int, facility_key: string, fired_shots: int}>,
     *     prepared: bool,
     *     footprint: list<string>,
     *     turn_start_monster: bool,
     *     missile_boundary_monster: bool,
     *     population_start: array<int, int>,
     *     population_remaining: array<int, int>,
     *     population_sync_base_id: int|null,
     *     spp_candidates: array<int, array{start_hp: int, host_nation_id: int}>,
     *     spp_qualified_monster_ids: array<int, true>,
     *     spp_evaluated: bool
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
                'fired' => 0, 'cost' => 0, 'ineffective' => 0,
                'impacts' => [], 'firing_bases' => [],
                'prepared' => false, 'footprint' => [],
                'turn_start_monster' => false, 'missile_boundary_monster' => false,
                'population_start' => [], 'population_remaining' => [],
                'population_sync_base_id' => null,
                'spp_candidates' => [], 'spp_qualified_monster_ids' => [], 'spp_evaluated' => false,
            ];
        }

        return $this->launches[$intent->queueItemId];
    }
}
