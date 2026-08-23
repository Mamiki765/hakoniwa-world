<?php

namespace App\Domain\Ruleset;

use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Command\MissileTargetPolicy;
use App\Domain\Economy\SalePolicy;
use App\Domain\Facility\FacilityVisibilityPolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Monster\MonsterBehaviorResolver;
use App\Domain\Monster\MonsterDispatchOptionResolver;
use App\Domain\Monster\MonsterDisplayOrderResolver;
use App\Domain\Monster\MonsterNaturalSpawnPolicy;
use App\Domain\Monster\MonsterRewardPolicyResolver;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemTargetSafetyPolicy;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\DeterministicRandomStream;
use DomainException;
use JsonException;

final class RulesetAuthoringValidator
{
    private const UNPUBLISHED_V11_FIXTURE_KEY = 'test-hakoniwa-2s-plus-v11-secretary-items';

    private const FORMAL_V11_KEY = 'hakoniwa-2s-plus-v11';

    private const FORMAL_V12_KEY = 'hakoniwa-2s-plus-v12';

    private const FORMAL_V13_KEY = 'hakoniwa-2s-plus-v13';

    private const CURRENT_PUBLISHED_BASELINE_KEY = 'hakoniwa-2s-plus-v10';

    private const ARCHITECTURE_CHUNK_SIZE = 16;

    private const INITIAL_X_MIN = 0;

    private const INITIAL_X_MAX = 59;

    private const INITIAL_Y_MIN = 0;

    private const INITIAL_Y_MAX = 59;

    private const POSTGRESQL_INTEGER_MAX = 2_147_483_647;

    private const DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX = 2_147_483_648;

    private const PRODUCTION_DECIMAL_INTEGER_DIGITS = 12;

    private const PRODUCTION_DECIMAL_SCALE = 4;

    private const NUTRITION_DECIMAL_MAX_INTEGER = 99_999_999;

    private const POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS = 255;

    /** @var list<string> */
    private const FACILITY_SCALE_FIELDS = [
        'scale_unit_people',
        'initial_scale',
        'scale_increment',
        'maximum_scale',
        'workforce_per_scale_people',
    ];

    /** @var list<string> */
    private const REQUIRED_INITIAL_ISLAND_FACILITY_KEYS = ['village', 'missile_base', 'capital'];

    public function __construct(
        private readonly MonsterDisplayOrderResolver $monsterDisplayOrders,
        private readonly MonsterBehaviorResolver $monsterBehaviors,
        private readonly MonsterNaturalSpawnPolicy $monsterSpawnPolicy,
        private readonly MonsterRewardPolicyResolver $monsterRewardPolicies,
        private readonly MonsterDispatchOptionResolver $monsterDispatchOptions,
        private readonly SecretaryItemTargetSafetyPolicy $secretaryItemTargetSafety,
    ) {}

    /** @var list<string> */
    private const TERRAIN_KEYS = ['sea', 'shallow', 'wasteland', 'scorched', 'plain', 'forest', 'mountain'];

    /** @var list<string> */
    private const LEGACY_FUTURE_FACILITY_KEYS = ['decoy', 'monument', 'defense', 'seabed_base'];

    /** @var list<string> */
    private const REQUIRED_TOP_LEVEL_KEYS = [
        'key',
        'version',
        'chunk_size',
        'initial_x_min',
        'initial_x_max',
        'initial_y_min',
        'initial_y_max',
        'minimum_capital_distance',
        'capital_initial_population',
        'capital_minimum_population',
        'initial_money',
        'resource_definitions',
        'resource_sale_prices',
        'initial_resources',
        'default_sale_policy',
        'command_queue_limit',
        'terrain_quantities',
        'facility_definitions',
        'command_definitions',
        'production_definitions',
        'initial_territory_radius',
        'initial_island_land_radius',
        'initial_island_growth_radius',
        'initial_island_reservation_radius',
        'initial_island_growth_steps',
    ];

    /**
     * @param  array<string, mixed>  $settings
     * @return array{key: string, version: int, resources: int, facilities: int, commands: int, production: int, monsters: int}
     */
    public function validate(array $settings): array
    {
        $this->validateJsonAuthoredValue($settings, 'ruleset');
        try {
            json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new DomainException('ruleset must be JSON encodable.', previous: $exception);
        }

        $this->requireKeys($settings, self::REQUIRED_TOP_LEVEL_KEYS, 'ruleset');

        $authoredKey = $this->persistedString($settings['key'], 'ruleset.key');
        $version = $this->integer($settings['version'], 'ruleset.version', 1);
        if ($version > self::POSTGRESQL_INTEGER_MAX) {
            throw new DomainException(
                'ruleset.version must fit the PostgreSQL integer range 1..'
                .self::POSTGRESQL_INTEGER_MAX.'.',
            );
        }
        $key = $this->nonMonsterValidationKey($authoredKey, $version);
        $settings['key'] = $key;
        if (in_array($key, ['hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'], true)) {
            $turnResolution = $this->map($settings['turn_resolution'] ?? null, 'ruleset.turn_resolution');
            if ($turnResolution !== [
                'normal_monster_stage' => 'after_ordinary_surface_cell_events',
            ]) {
                throw new DomainException('ruleset.turn_resolution differs from the v9 normal monster stage contract.');
            }
        }
        if ($key === 'hakoniwa-2s-plus-v10'
            && ($settings['turn_processing']['food']['production_overflow_resolution_stage'] ?? null)
                !== 'after_population_nutrition_consumption') {
            throw new DomainException('ruleset food overflow resolution differs from the v10 contract.');
        }
        $chunkSize = $this->integer($settings['chunk_size'], 'ruleset.chunk_size', 1);
        if ($chunkSize !== self::ARCHITECTURE_CHUNK_SIZE) {
            throw new DomainException('ruleset.chunk_size must be exactly 16.');
        }
        $xMin = $this->integer($settings['initial_x_min'], 'ruleset.initial_x_min');
        $xMax = $this->integer($settings['initial_x_max'], 'ruleset.initial_x_max');
        $yMin = $this->integer($settings['initial_y_min'], 'ruleset.initial_y_min');
        $yMax = $this->integer($settings['initial_y_max'], 'ruleset.initial_y_max');
        if ($xMin !== self::INITIAL_X_MIN
            || $xMax !== self::INITIAL_X_MAX
            || $yMin !== self::INITIAL_Y_MIN
            || $yMax !== self::INITIAL_Y_MAX) {
            throw new DomainException('Ruleset initial bounds must be x=0..59 and y=0..59.');
        }

        $minimumCapitalDistance = $this->integer(
            $settings['minimum_capital_distance'],
            'ruleset.minimum_capital_distance',
            0,
        );
        $initialPopulation = $this->integer(
            $settings['capital_initial_population'],
            'ruleset.capital_initial_population',
            1,
        );
        $minimumPopulation = $this->integer(
            $settings['capital_minimum_population'],
            'ruleset.capital_minimum_population',
            1,
        );
        if ($minimumPopulation > $initialPopulation) {
            throw new DomainException('Ruleset minimum Capital population cannot exceed its initial population.');
        }
        $hasCapitalDisasterSettings = array_key_exists('capital_growth_maximum_population', $settings)
            || array_key_exists('capital_damage_percentages', $settings);
        if ($hasCapitalDisasterSettings) {
            $this->requireKeys(
                $settings,
                ['capital_growth_maximum_population', 'capital_damage_percentages'],
                'ruleset',
            );
            $growthMaximum = $this->integer(
                $settings['capital_growth_maximum_population'],
                'ruleset.capital_growth_maximum_population',
                $minimumPopulation,
            );
            if ($growthMaximum > self::POSTGRESQL_INTEGER_MAX) {
                throw new DomainException('ruleset.capital_growth_maximum_population must fit PostgreSQL integer.');
            }
            $percentages = $this->map($settings['capital_damage_percentages'], 'ruleset.capital_damage_percentages');
            $this->requireKeys($percentages, [
                'facility_or_wasteland', 'excavation_or_shallow', 'deep_sea', 'eruption_center',
            ], 'ruleset.capital_damage_percentages');
            foreach ($percentages as $percentageKey => $percentage) {
                $validated = $this->integer(
                    $percentage,
                    "ruleset.capital_damage_percentages.{$percentageKey}",
                    0,
                );
                if ($validated > 100) {
                    throw new DomainException(
                        "ruleset.capital_damage_percentages.{$percentageKey} cannot exceed 100.",
                    );
                }
            }
        }

        $this->integer($settings['initial_money'], 'ruleset.initial_money', 0);
        if (array_key_exists('capital_relocation_cost_money', $settings)) {
            $capitalRelocationCost = $this->integer(
                $settings['capital_relocation_cost_money'],
                'ruleset.capital_relocation_cost_money',
                1_000,
            );
            if ($capitalRelocationCost > 9_999) {
                throw new DomainException('ruleset.capital_relocation_cost_money must be between 1000 and 9999.');
            }
        }
        $defaultSalePolicy = $this->string($settings['default_sale_policy'], 'ruleset.default_sale_policy');
        if (! SalePolicy::isSupportedRulesetDefault($defaultSalePolicy)) {
            throw new DomainException(
                'ruleset.default_sale_policy must be one of '
                .implode(', ', SalePolicy::rulesetDefaultValues()).'.',
            );
        }
        $commandQueueLimit = $this->integer(
            $settings['command_queue_limit'],
            'ruleset.command_queue_limit',
            1,
        );
        if ($commandQueueLimit > CommandQueueLimit::MAXIMUM) {
            throw new DomainException(
                'ruleset.command_queue_limit must be between 1 and '
                .CommandQueueLimit::MAXIMUM.'.',
            );
        }
        $territoryRadius = $this->integer(
            $settings['initial_territory_radius'],
            'ruleset.initial_territory_radius',
            0,
        );
        $landRadius = $this->integer(
            $settings['initial_island_land_radius'],
            'ruleset.initial_island_land_radius',
            0,
        );
        $growthRadius = $this->integer(
            $settings['initial_island_growth_radius'],
            'ruleset.initial_island_growth_radius',
            0,
        );
        $reservationRadius = $this->integer(
            $settings['initial_island_reservation_radius'],
            'ruleset.initial_island_reservation_radius',
            0,
        );
        $this->integer($settings['initial_island_growth_steps'], 'ruleset.initial_island_growth_steps', 0);
        if ($landRadius < 2) {
            throw new DomainException(
                'ruleset.initial_island_land_radius must be at least 2 to contain starter cells.',
            );
        }
        if ($landRadius < $territoryRadius) {
            throw new DomainException(
                'ruleset.initial_island_land_radius must be at least initial_territory_radius.',
            );
        }
        if ($reservationRadius < max($landRadius, $growthRadius, 2)) {
            throw new DomainException(
                'ruleset.initial_island_reservation_radius must be at least '
                .'max(initial_island_land_radius, initial_island_growth_radius, 2).',
            );
        }
        $maximumReservationRadius = min(
            intdiv($xMax - $xMin, 2),
            intdiv($yMax - $yMin, 2),
        );
        if ($reservationRadius > $maximumReservationRadius) {
            throw new DomainException(
                'ruleset.initial_island_reservation_radius must be at most '
                ."{$maximumReservationRadius} so the initial bounds contain a Capital candidate.",
            );
        }
        $maximumCapitalDistance = $this->maximumCapitalDistance(
            $xMin,
            $xMax,
            $yMin,
            $yMax,
            $reservationRadius,
        );
        if ($minimumCapitalDistance > $maximumCapitalDistance) {
            throw new DomainException(
                'ruleset.minimum_capital_distance must be at most '
                ."{$maximumCapitalDistance} for the initial bounds and reservation radius.",
            );
        }

        $resourceKeys = $this->validateResources($settings);
        $commandKeys = $this->definitionKeys(
            $this->list($settings['command_definitions'], 'ruleset.command_definitions'),
            'ruleset.command_definitions',
        );
        $productionKeys = $this->definitionKeys(
            $this->list($settings['production_definitions'], 'ruleset.production_definitions'),
            'ruleset.production_definitions',
        );
        $facilityKeys = $this->definitionKeys(
            $this->map($settings['facility_definitions'], 'ruleset.facility_definitions'),
            'ruleset.facility_definitions',
            true,
        );

        $this->validateInitialIslandFacilities($settings, $facilityKeys);
        $this->validateTerrainQuantities($settings);
        $this->validateFacilities($settings, $commandKeys, $productionKeys);
        $this->validateCommands($settings, $resourceKeys, $facilityKeys, $authoredKey, $version);
        $this->validateProduction($settings, $resourceKeys, $facilityKeys);
        $this->validateVersionAdditions(
            $settings,
            $resourceKeys,
            $facilityKeys,
            $reservationRadius,
            $landRadius,
        );
        $this->validateNationLifecycle(
            $settings,
            $authoredKey,
            $version,
            $resourceKeys,
            $facilityKeys,
            $commandKeys,
        );
        $this->validateKarma($settings, $authoredKey, $version);
        $monsterCount = $this->validateMonsterSystem(
            $settings,
            $resourceKeys,
            $facilityKeys,
            $authoredKey,
            $version,
        );
        $this->validateMilitary($settings, $facilityKeys, $version);
        $this->validateSecretary($settings, $resourceKeys, $commandKeys);

        return [
            'key' => $authoredKey,
            'version' => $version,
            'resources' => count($resourceKeys),
            'facilities' => count($facilityKeys),
            'commands' => count($commandKeys),
            'production' => count($productionKeys),
            'monsters' => $monsterCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $commandKeys
     */
    private function validateSecretary(array $settings, array $resourceKeys, array $commandKeys): void
    {
        if (! array_key_exists('secretary', $settings)) {
            return;
        }
        $definitions = (new SecretarySkillCatalog)->definitions($settings);
        $productionSkills = [
            SecretarySkillCatalog::AGRICULTURAL_POLICY => ['wheat', 'build_farm'],
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => ['industrial_goods', 'build_factory'],
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => ['minerals', 'build_mine'],
        ];
        foreach ($definitions as $key => $definition) {
            $path = "ruleset.secretary.skills.{$key}";
            $this->requireKeys(
                $definition,
                ['key', 'name', 'initial_level', 'level_requirement', 'effect', 'experience_source'],
                $path,
            );
            $this->persistedString($definition['name'], "{$path}.name");
            $initialLevel = $this->integer($definition['initial_level'], "{$path}.initial_level", 0);
            $requirement = $this->map($definition['level_requirement'], "{$path}.level_requirement");
            $this->requireKeys($requirement, ['basis', 'multiplier'], "{$path}.level_requirement");
            $basis = $requirement['basis'] ?? null;
            if (! in_array($basis, ['next_level_squared', 'current_level_squared'], true)) {
                throw new DomainException("{$path}.level_requirement.basis is invalid.");
            }
            $multiplier = $this->integer(
                $requirement['multiplier'],
                "{$path}.level_requirement.multiplier",
                1,
            );
            $effect = $this->map($definition['effect'], "{$path}.effect");
            $source = $this->map($definition['experience_source'], "{$path}.experience_source");

            if (isset($productionSkills[$key])) {
                [$resourceKey, $commandKey] = $productionSkills[$key];
                if ($initialLevel !== 0 || $basis !== 'next_level_squared' || $multiplier !== 1
                    || $effect !== [
                        'type' => 'production_multiplier',
                        'resource_key' => $resourceKey,
                        'per_mille_per_level' => 1,
                    ]
                    || $source !== [
                        'type' => 'successful_command_execution',
                        'command_key' => $commandKey,
                        'points_per_execution' => 1,
                        'quantity_multiplier' => false,
                    ]
                    || ! in_array($resourceKey, $resourceKeys, true)
                    || ! in_array($commandKey, $commandKeys, true)) {
                    throw new DomainException("{$path} does not match the Secretary v1 production-skill contract.");
                }

                continue;
            }

            if ($key !== SecretarySkillCatalog::FINAL_DEFENSE_LINE
                || $initialLevel !== 1
                || $basis !== 'current_level_squared'
                || $multiplier !== 100
                || $effect !== [
                    'type' => 'final_defense_line',
                    'interceptions_per_level_per_turn' => 1,
                    'normal_defense_resolves_first' => true,
                    'exclude_monster_occupied_cells' => true,
                ]
                || $source !== [
                    'type' => 'owned_cell_missile_arrival',
                    'points_per_missile' => 1,
                    'include_normal_defense_interception' => true,
                    'include_secretary_interception' => true,
                    'include_actual_impact' => true,
                    'include_self_fired_collateral' => true,
                    'independent_from_interception_eligibility' => true,
                ]) {
                throw new DomainException("{$path} does not match the Secretary v1 final-defense contract.");
            }
        }

        (new SecretaryItemGameplayContract(new SecretaryItemCatalog))->validate($settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     * @param  list<string>  $commandKeys
     */
    private function validateNationLifecycle(
        array $settings,
        string $authoredKey,
        int $version,
        array $resourceKeys,
        array $facilityKeys,
        array $commandKeys,
    ): void {
        $hasLifecycle = array_key_exists('nation_lifecycle', $settings);
        if ($version < 12) {
            if ($hasLifecycle) {
                throw new DomainException('Rulesets before v12 cannot author the ver 2.4.0 Nation lifecycle contract.');
            }

            return;
        }
        $expectedKey = match ($version) {
            12 => self::FORMAL_V12_KEY,
            13 => self::FORMAL_V13_KEY,
            default => null,
        };
        if ($expectedKey === null || $authoredKey !== $expectedKey || ! $hasLifecycle) {
            throw new DomainException('The v12/v13 Ruleset identity requires the ver 2.4.0 Nation lifecycle contract.');
        }

        $path = 'ruleset.nation_lifecycle';
        $lifecycle = $this->map($settings['nation_lifecycle'], $path);
        $requiredKeys = [
            'states', 'runtime_entry_states', 'recovery_entry_enabled', 'dormant_reasons',
            'initial_idle_counter', 'dormant_idle_threshold', 'abandonment_idle_threshold',
            'turns_per_day', 'manual_dormancy_min_days', 'manual_dormancy_max_days',
            'dormant_finance_money', 'dormant_protection_radius', 'dormant_visual_theme',
            'territory_influence_target_states', 'territory_influence_source_states',
            'initial_food_resource_key', 'finance_command_key', 'emergency_farm',
        ];
        if ($version === 13) {
            $requiredKeys[] = 'recovery_duration_turns';
        }
        $this->requireKeys($lifecycle, $requiredKeys, $path);
        $expectedRuntimeStates = $version === 13
            ? ['active', 'dormant', 'recovery', 'abandoned']
            : ['active', 'dormant', 'abandoned'];
        $recoveryEnabled = $version === 13;
        if ($this->list($lifecycle['states'], "{$path}.states") !== ['active', 'dormant', 'recovery', 'abandoned']
            || $this->list($lifecycle['runtime_entry_states'], "{$path}.runtime_entry_states")
                !== $expectedRuntimeStates
            || $this->boolean($lifecycle['recovery_entry_enabled'], "{$path}.recovery_entry_enabled") !== $recoveryEnabled
            || ($version === 13
                && $this->integer($lifecycle['recovery_duration_turns'], "{$path}.recovery_duration_turns", 1) !== 84)
            || $this->list($lifecycle['dormant_reasons'], "{$path}.dormant_reasons")
                !== ['idle', 'collapse', 'manual']
            || $this->integer($lifecycle['initial_idle_counter'], "{$path}.initial_idle_counter", 0) !== 2000
            || $this->integer($lifecycle['dormant_idle_threshold'], "{$path}.dormant_idle_threshold", 1) !== 360
            || $this->integer($lifecycle['abandonment_idle_threshold'], "{$path}.abandonment_idle_threshold", 1) !== 2160
            || $this->integer($lifecycle['turns_per_day'], "{$path}.turns_per_day", 1) !== 12
            || $this->integer($lifecycle['manual_dormancy_min_days'], "{$path}.manual_dormancy_min_days", 1) !== 1
            || $this->integer($lifecycle['manual_dormancy_max_days'], "{$path}.manual_dormancy_max_days", 1) !== 7
            || $this->integer($lifecycle['dormant_finance_money'], "{$path}.dormant_finance_money", 0) !== 10
            || $this->integer($lifecycle['dormant_protection_radius'], "{$path}.dormant_protection_radius", 0) !== 2
            || $this->persistedString($lifecycle['dormant_visual_theme'], "{$path}.dormant_visual_theme") !== 'snow'
            || $this->list($lifecycle['territory_influence_target_states'], "{$path}.territory_influence_target_states")
                !== ['active', 'dormant']
            || $this->list($lifecycle['territory_influence_source_states'], "{$path}.territory_influence_source_states")
                !== ['active']) {
            throw new DomainException("{$path} differs from the ver 2.4.0 Owner decision.");
        }
        if (($settings['turn_processing']['automatic_finance_money'] ?? null)
            !== $lifecycle['dormant_finance_money']) {
            throw new DomainException("{$path}.dormant_finance_money must reuse the canonical finance amount.");
        }

        $foodKey = $this->persistedString($lifecycle['initial_food_resource_key'], "{$path}.initial_food_resource_key");
        $financeKey = $this->persistedString($lifecycle['finance_command_key'], "{$path}.finance_command_key");
        $this->reference($foodKey, $resourceKeys, "{$path}.initial_food_resource_key");
        $this->reference($financeKey, $commandKeys, "{$path}.finance_command_key");
        if (! is_int($settings['initial_resources'][$foodKey] ?? null)) {
            throw new DomainException("{$path}.initial_food_resource_key must reference the canonical initial food value.");
        }

        $farmPath = "{$path}.emergency_farm";
        $farm = $this->map($lifecycle['emergency_farm'], $farmPath);
        $this->requireKeys($farm, [
            'facility_key', 'result_terrain_key', 'candidate_terrain_keys', 'selection',
        ], $farmPath);
        $this->reference($farm['facility_key'], $facilityKeys, "{$farmPath}.facility_key");
        $this->reference($farm['result_terrain_key'], self::TERRAIN_KEYS, "{$farmPath}.result_terrain_key");
        foreach ($this->list($farm['candidate_terrain_keys'], "{$farmPath}.candidate_terrain_keys") as $terrainKey) {
            $this->reference($terrainKey, self::TERRAIN_KEYS, "{$farmPath}.candidate_terrain_keys");
        }
        if ($farm !== [
            'facility_key' => 'farm',
            'result_terrain_key' => 'plain',
            'candidate_terrain_keys' => ['plain', 'wasteland', 'scorched', 'shallow', 'sea'],
            'selection' => ['distance', 'y', 'x'],
        ]) {
            throw new DomainException("{$farmPath} differs from the deterministic emergency farm contract.");
        }
    }

    /** @param array<string, mixed> $settings */
    private function validateKarma(array $settings, string $authoredKey, int $version): void
    {
        $authored = $settings['karma'] ?? null;
        if ($version < 13) {
            if ($authored !== null) {
                throw new DomainException('Rulesets before v13 cannot author KARMA.');
            }

            return;
        }
        if ($version !== 13 || $authoredKey !== self::FORMAL_V13_KEY || ! is_array($authored)) {
            throw new DomainException('The v13 Ruleset identity requires the KARMA contract.');
        }
        $expected = [
            'minimum' => -10,
            'ordinary' => 0,
            'maximum' => 100,
            'impact_points' => [
                'terrain' => 1,
                'settlement_or_facility' => 2,
                'capital_above_minimum' => 2,
                'capital_at_minimum' => 0,
                'seabed_oil_field_destroyed' => 10,
                'land_destroyed' => 10,
                'seabed_base_destroyed' => 3,
            ],
            'anti_monster_missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
            'spp_self_destruct_setup_points' => 20,
            'hostile_monument_points' => 15,
            'foreign_monster_kill_reduction' => 1,
            'victim_reduction_per_impact' => 1,
            'decay_interval_turns' => 6,
            'decay_amount' => 1,
            'recovery_entry_reduction' => 3,
            'alliance_reward_money_per_karma_per_impact' => 1,
            'sanction' => [
                'overflow_points_per_shot' => 1,
                'target_selection' => 'owned_surface_territory_with_replacement',
                'interception' => ['defense', 'secretary'],
                'impact' => 'canonical_scorch_or_destruction',
                'random_stream_version' => 1,
            ],
        ];
        if ($authored !== $expected) {
            throw new DomainException('ruleset.karma differs from the v13 Owner decision.');
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $facilityKeys
     */
    private function validateMilitary(array $settings, array $facilityKeys, int $rulesetVersion): void
    {
        if (! array_key_exists('military', $settings)) {
            return;
        }

        $path = 'ruleset.military';
        $military = $this->map($settings['military'], $path);
        $this->requireKeys($military, [
            'launch_base_facility_keys', 'missiles', 'visibility', 'dormant_impact', 'refugees',
        ], $path);
        foreach ($this->list($military['launch_base_facility_keys'], "{$path}.launch_base_facility_keys") as $key) {
            $this->reference($key, $facilityKeys, "{$path}.launch_base_facility_keys");
        }

        $missiles = $this->map($military['missiles'], "{$path}.missiles");
        $expected = [
            'missile' => [20, 2, 'scorched', true],
            'pp_missile' => [50, 1, 'scorched', true],
            'land_destruction_missile' => [100, 2, null, false],
            'spp_missile' => [500, 0, 'scorched', true],
        ];
        if (array_keys($missiles) !== array_keys($expected)) {
            throw new DomainException("{$path}.missiles must contain the canonical PR22 missile keys.");
        }
        foreach ($expected as $key => [$cost, $deviation, $terrain, $refugees]) {
            $definitionPath = "{$path}.missiles.{$key}";
            $definition = $this->map($missiles[$key], $definitionPath);
            $this->requireKeys($definition, [
                'cost_money_per_shot', 'deviation_radius', 'creates_terrain', 'refugees',
            ], $definitionPath);
            $validatedDeviation = $this->integer(
                $definition['deviation_radius'],
                "{$definitionPath}.deviation_radius",
                0,
            );
            if ($validatedDeviation > 2
                || $this->integer($definition['cost_money_per_shot'], "{$definitionPath}.cost_money_per_shot", 1) !== $cost
                || $validatedDeviation !== $deviation
                || $definition['creates_terrain'] !== $terrain
                || $this->boolean($definition['refugees'], "{$definitionPath}.refugees") !== $refugees) {
                throw new DomainException("{$definitionPath} differs from the approved PR22 missile contract.");
            }
        }

        $visibility = $this->map($military['visibility'], "{$path}.visibility");
        $this->requireKeys($visibility, [
            'launch_summary', 'meaningful_impacts', 'ineffective_impacts',
            'firing_nation_details', 'anonymous_missile_keys',
        ], "{$path}.visibility");
        if ($visibility !== [
            'launch_summary' => 'public',
            'meaningful_impacts' => 'public',
            'ineffective_impacts' => 'aggregate_per_launch',
            'firing_nation_details' => 'private',
            'anonymous_missile_keys' => [],
        ]) {
            throw new DomainException("{$path}.visibility differs from owner decision B-10.");
        }

        $dormant = $this->map($military['dormant_impact'], "{$path}.dormant_impact");
        $this->requireKeys($dormant, [
            'explicit_target_state', 'no_effect_owner_states', 'preserve', 'monster_exception',
        ], "{$path}.dormant_impact");
        $explicitTargetState = in_array(
            $settings['key'] ?? null,
            ['hakoniwa-2s-plus-v2', 'hakoniwa-2s-plus-v3', 'hakoniwa-2s-plus-v4', 'hakoniwa-2s-plus-v5', 'hakoniwa-2s-plus-v6', 'hakoniwa-2s-plus-v7', 'hakoniwa-2s-plus-v8', 'hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'],
            true,
        )
            ? MissileTargetPolicy::ANY_EXISTING_COORDINATE
            : MissileTargetPolicy::ACTIVE_NATION;
        $noEffectOwnerStates = $rulesetVersion >= 12
            ? []
            : ['dormant_frozen', 'dormant_contestable', 'sunken_archived'];
        if ($dormant !== [
            'explicit_target_state' => $explicitTargetState,
            'no_effect_owner_states' => $noEffectOwnerStates,
            'preserve' => ['cell', 'facility', 'population', 'monster_occupancy'],
            'monster_exception' => false,
        ]) {
            throw new DomainException("{$path}.dormant_impact differs from owner decision B-12.");
        }

        $refugees = $this->map($military['refugees'], "{$path}.refugees");
        $this->requireKeys($refugees, [
            'settlement_facility_keys', 'recipient', 'generated_fraction', 'event_types',
        ], "{$path}.refugees");
        foreach ($this->list($refugees['settlement_facility_keys'], "{$path}.refugees.settlement_facility_keys") as $key) {
            $this->reference($key, $facilityKeys, "{$path}.refugees.settlement_facility_keys");
        }
        if ($refugees['recipient'] !== 'firing_nation'
            || $refugees['event_types'] !== ['refugee_generated', 'refugee_received']) {
            throw new DomainException("{$path}.refugees must use the approved recipient and structured event keys.");
        }
        $fraction = $this->map($refugees['generated_fraction'], "{$path}.refugees.generated_fraction");
        if ($fraction !== ['numerator' => 1, 'denominator' => 2]) {
            throw new DomainException("{$path}.refugees.generated_fraction must be one half.");
        }

        if (in_array($settings['key'] ?? null, ['hakoniwa-2s-plus-v4', 'hakoniwa-2s-plus-v5', 'hakoniwa-2s-plus-v6', 'hakoniwa-2s-plus-v7', 'hakoniwa-2s-plus-v8', 'hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'], true)) {
            $this->validateLaunchBaseExperience($settings, $military, $facilityKeys, $path);
        }

        if (in_array($settings['key'] ?? null, ['hakoniwa-2s-plus-v6', 'hakoniwa-2s-plus-v7', 'hakoniwa-2s-plus-v8', 'hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'], true)) {
            $defenseResistance = $this->map(
                $military['defense_spp_resistance'] ?? null,
                "{$path}.defense_spp_resistance",
            );
            if ($defenseResistance !== [
                'facility_key' => 'defense',
                'ineffective_missile_keys' => ['spp_missile'],
            ]) {
                throw new DomainException("{$path}.defense_spp_resistance differs from the v6 owner decision.");
            }
            $this->reference(
                $defenseResistance['facility_key'],
                $facilityKeys,
                "{$path}.defense_spp_resistance.facility_key",
            );
        }

        if (in_array($settings['key'] ?? null, ['hakoniwa-2s-plus-v8', 'hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'], true)) {
            $interception = $this->map(
                $military['defense_interception'] ?? null,
                "{$path}.defense_interception",
            );
            if ($interception !== [
                'facility_key' => 'defense',
                'radius' => 2,
                'exclude_center' => true,
                'defense_target_cells' => 'exclude',
                'missile_keys' => ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'],
                'facility_owner_scope' => 'any',
                'monster_occupied_cells' => 'include',
                'self_fired_missiles' => 'include',
                'overlap_resolution' => 'single_interception',
                'resolve_before' => 'secretary',
            ]) {
                throw new DomainException("{$path}.defense_interception differs from the v8 source-audited contract.");
            }
            $this->reference('defense', $facilityKeys, "{$path}.defense_interception.facility_key");
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $military
     * @param  list<string>  $facilityKeys
     */
    private function validateLaunchBaseExperience(
        array $settings,
        array $military,
        array $facilityKeys,
        string $path,
    ): void {
        $experience = $this->map($military['launch_base_experience'] ?? null, "{$path}.launch_base_experience");
        $resistance = $this->map($military['seabed_base_resistance'] ?? null, "{$path}.seabed_base_resistance");
        $expectedExperience = [
            'facility_keys' => ['missile_base', 'seabed_base'],
            'settlement_hit' => [
                'missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
                'population_divisor' => 2_000,
                'capital_population_loss_multiplier' => 2,
            ],
            'monster_damage_experience' => 0,
            'monster_final_blow_experience' => 'monster_definition.missile_base_experience',
        ];
        $expectedResistance = [
            'facility_key' => 'seabed_base',
            'ineffective_missile_keys' => ['missile', 'pp_missile', 'spp_missile'],
            'destructive_missile_keys' => ['land_destruction_missile'],
        ];
        if ($experience !== $expectedExperience || $resistance !== $expectedResistance) {
            throw new DomainException("{$path} differs from the approved H2+ launch-base experience or seabed resistance contract.");
        }
        foreach ($experience['facility_keys'] as $facilityKey) {
            $this->reference($facilityKey, $facilityKeys, "{$path}.launch_base_experience.facility_keys");
        }

        $seabedPath = 'ruleset.facility_definitions.seabed_base';
        $seabed = $this->map($settings['facility_definitions']['seabed_base'] ?? null, $seabedPath);
        foreach ([
            'initial_experience' => 0,
            'maximum_experience' => 200,
            'level_thresholds' => [50, 200],
            'launch_capacity_by_level' => [1 => 1, 2 => 2, 3 => 3],
        ] as $key => $expected) {
            if (($seabed[$key] ?? null) !== $expected) {
                throw new DomainException("{$seabedPath}.{$key} differs from the approved H2+ contract.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateMonsterSystem(
        array $settings,
        array $resourceKeys,
        array $facilityKeys,
        string $rulesetKey,
        int $rulesetVersion,
    ): int {
        $extended = $this->usesExtendedMonsterContract($rulesetKey, $rulesetVersion);
        $hasDefinitions = array_key_exists('monster_definitions', $settings);
        $hasSystem = array_key_exists('monster_system', $settings);
        if ($hasDefinitions !== $hasSystem) {
            throw new DomainException(
                'ruleset.monster_definitions and ruleset.monster_system must be published together.',
            );
        }
        if (! $hasDefinitions) {
            if ($extended) {
                throw new DomainException('A v11 ruleset requires the extended monster contract.');
            }

            return 0;
        }

        $definitions = $this->list($settings['monster_definitions'], 'ruleset.monster_definitions');
        $keys = $this->definitionKeys($definitions, 'ruleset.monster_definitions');
        $expected = [
            'mecha_inora' => [2, 0, 'none', 1, null, 0, 5, 'hakoniwa_original.monster.mecha_inora', null, 0, 0, 'monster7.gif'],
            'inora' => [1, 1, 'none', 1, 1, 400, 5, 'hakoniwa_original.monster.inora', null, 1, 0, 'monster0.gif'],
            'sanjira' => [1, 1, 'harden_odd', 1, 1, 500, 7, 'hakoniwa_original.monster.sanjira', 'hakoniwa_original.monster.hardened', 2, 3, 'monster5.gif'],
            'red_inora' => [3, 1, 'none', 1, 2, 1_000, 12, 'hakoniwa_original.monster.red_inora', null, 3, 0, 'monster1.gif'],
            'dark_inora' => [2, 1, 'move_2', 2, 2, 800, 15, 'hakoniwa_original.monster.dark_inora', null, 4, 1, 'monster2.gif'],
            'inora_ghost' => [1, 0, 'move_9999', 9_999, 2, 300, 10, 'hakoniwa_original.monster.inora_ghost', null, 5, 2, 'monster8.gif'],
            'whale' => [4, 1, 'harden_even', 1, 3, 1_500, 20, 'hakoniwa_original.monster.kujira', 'hakoniwa_original.monster.hardened', 6, 4, 'monster6.gif'],
            'king_inora' => [5, 1, 'none', 1, 3, 2_000, 30, 'hakoniwa_original.monster.king_inora', null, 7, 0, 'monster3.gif'],
        ];
        if (! $extended && $keys !== array_keys($expected)) {
            throw new DomainException('ruleset.monster_definitions must contain the exact eight PR21 monster keys in canonical order.');
        }
        if ($extended) {
            foreach (array_keys($expected) as $historicalKey) {
                if (! in_array($historicalKey, $keys, true)) {
                    throw new DomainException("ruleset.monster_definitions is missing historical monster {$historicalKey}.");
                }
            }
            foreach (['mecha_inora_zero', 'aoi_inora'] as $requiredC4Key) {
                if (! in_array($requiredC4Key, $keys, true)) {
                    throw new DomainException("ruleset.monster_definitions is missing required C4 monster {$requiredC4Key}.");
                }
            }
        }

        $assetKeys = [];
        $displayOrders = [];
        $authoredBehaviors = [];
        $knownSkills = ['none', 'move_2', 'move_9999', 'harden_odd', 'harden_even'];
        foreach ($definitions as $index => $definitionValue) {
            $path = "ruleset.monster_definitions.{$index}";
            $definition = $this->map($definitionValue, $path);
            $this->requireKeys($definition, [
                'key', 'name', 'asset_key', 'hardened_asset_key', 'base_hp', 'hp_variation',
                'skill_key', 'movement_limit', 'natural_spawn_tier', 'wreckage_value_money',
                'missile_base_experience', 'skill_description', 'visibility',
                'movement_terrain_contract', 'trample_contract', 'hardening_contract', 'source_metadata',
            ], $path);
            if ($extended) {
                $this->requireKeys($definition, ['display_order'], $path);
            } elseif (array_key_exists('display_order', $definition)) {
                throw new DomainException("{$path}.display_order is not authored in historical rulesets.");
            }
            $key = $this->persistedString($definition['key'], "{$path}.key");
            $this->persistedString($definition['name'], "{$path}.name");
            $assetKey = $this->persistedString($definition['asset_key'], "{$path}.asset_key");
            if (preg_match('/^hakoniwa_(?:original|custom)\.monster\.[a-z0-9_]+$/', $assetKey) !== 1) {
                throw new DomainException("{$path}.asset_key is not a valid monster asset identity.");
            }
            if (in_array($assetKey, $assetKeys, true)) {
                throw new DomainException("{$path}.asset_key duplicates a normal monster asset key.");
            }
            $assetKeys[] = $assetKey;
            $hardenedAsset = $definition['hardened_asset_key'] === null
                ? null
                : $this->persistedString($definition['hardened_asset_key'], "{$path}.hardened_asset_key");
            if ($hardenedAsset !== null
                && preg_match('/^hakoniwa_(?:original|custom)\.monster\.[a-z0-9_]+$/', $hardenedAsset) !== 1) {
                throw new DomainException("{$path}.hardened_asset_key is not a valid monster asset identity.");
            }
            $baseHp = $this->integer($definition['base_hp'], "{$path}.base_hp", 1);
            $variation = $this->integer($definition['hp_variation'], "{$path}.hp_variation", 0);
            if ($variation > 18) {
                throw new DomainException("{$path}.hp_variation must be at most 18.");
            }
            if ($baseHp + $variation > 65_535) {
                throw new DomainException("{$path} HP range must fit an unsigned small integer.");
            }
            $skill = $this->persistedString($definition['skill_key'], "{$path}.skill_key");
            if (! in_array($skill, $knownSkills, true)) {
                throw new DomainException("{$path}.skill_key is not a supported exclusive skill.");
            }
            $movementLimit = $this->integer($definition['movement_limit'], "{$path}.movement_limit", 1);
            $tier = $definition['natural_spawn_tier'] === null
                ? null
                : $this->integer($definition['natural_spawn_tier'], "{$path}.natural_spawn_tier", 1);
            if ($tier !== null && $tier > 3) {
                throw new DomainException("{$path}.natural_spawn_tier must be at most 3.");
            }
            $value = $this->integer($definition['wreckage_value_money'], "{$path}.wreckage_value_money", 0);
            $experience = $this->integer($definition['missile_base_experience'], "{$path}.missile_base_experience", 0);
            $this->persistedString($definition['skill_description'], "{$path}.skill_description");
            if ($this->persistedString($definition['visibility'], "{$path}.visibility") !== 'public') {
                throw new DomainException("{$path}.visibility must be public.");
            }
            $movement = $this->map($definition['movement_terrain_contract'], "{$path}.movement_terrain_contract");
            $trample = $this->map($definition['trample_contract'], "{$path}.trample_contract");
            $hardening = $this->map($definition['hardening_contract'], "{$path}.hardening_contract");
            $source = $this->map($definition['source_metadata'], "{$path}.source_metadata");
            if (array_key_exists(SecretaryItemTargetSafetyPolicy::METADATA_KEY, $source)) {
                $this->secretaryItemTargetSafety->validateMetadata(
                    $source[SecretaryItemTargetSafetyPolicy::METADATA_KEY],
                );
            }
            if ($extended) {
                if (! array_key_exists(MonsterRewardPolicyResolver::METADATA_KEY, $source)) {
                    throw new DomainException("{$path}.source_metadata requires an explicit reward policy.");
                }
                $this->monsterRewardPolicies->validate($source[MonsterRewardPolicyResolver::METADATA_KEY]);
                if (! array_key_exists(MonsterBehaviorResolver::METADATA_KEY, $source)) {
                    throw new DomainException("{$path}.source_metadata requires explicit monster behavior.");
                }
                $authoredBehaviors[$key] = $this->monsterBehaviors->validate(
                    $source[MonsterBehaviorResolver::METADATA_KEY],
                    $key,
                );
            } elseif (array_key_exists(MonsterRewardPolicyResolver::METADATA_KEY, $source)) {
                $this->monsterRewardPolicies->validate($source[MonsterRewardPolicyResolver::METADATA_KEY]);
            }

            if (array_key_exists($key, $expected)) {
                $this->requireKeys($source, ['kind', 'skill_code', 'filename'], "{$path}.source_metadata");
                $contract = $expected[$key];
                if ([$baseHp, $variation, $skill, $movementLimit, $tier, $value, $experience, $assetKey,
                    $hardenedAsset, $source['kind'], $source['skill_code'], $source['filename']] !== $contract) {
                    throw new DomainException("{$path} differs from the audited Hakoniwa 2+ PR21 contract.");
                }
            } else {
                foreach (['kind', 'skill_code', 'filename'] as $legacyField) {
                    if (array_key_exists($legacyField, $source)) {
                        throw new DomainException("{$path}.source_metadata must not invent legacy {$legacyField}.");
                    }
                }
            }
            $displayOrder = $this->monsterDisplayOrders->resolve(
                $definition['display_order'] ?? null,
                $source,
            );
            if (isset($displayOrders[$displayOrder])) {
                throw new DomainException("{$path}.display_order duplicates another effective monster order.");
            }
            $displayOrders[$displayOrder] = true;
            if ($key === 'aoi_inora') {
                $expectedAoiMovement = [
                    'candidate_attempts_per_action' => 3,
                    'blocked_terrain_keys' => ['mountain'],
                    'blocked_facility_keys' => ['mine', 'monument', 'capital'],
                    'defense_facility_key' => 'defense',
                    'destination_terrain_key' => 'sea',
                    'clear_owner' => true,
                ];
                if ($movement !== $expectedAoiMovement) {
                    throw new DomainException("{$path}.movement_terrain_contract differs from the Aoi land-invasion contract.");
                }
                foreach ($movement['blocked_terrain_keys'] as $terrainKey) {
                    $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.movement_terrain_contract.blocked_terrain_keys");
                }
                foreach ($movement['blocked_facility_keys'] as $facilityKey) {
                    $this->facilityReferenceOrFuture(
                        $facilityKey,
                        $facilityKeys,
                        "{$path}.movement_terrain_contract.blocked_facility_keys",
                    );
                }
                $this->facilityReferenceOrFuture(
                    $movement['defense_facility_key'],
                    $facilityKeys,
                    "{$path}.movement_terrain_contract.defense_facility_key",
                );
                $this->reference(
                    $movement['destination_terrain_key'],
                    self::TERRAIN_KEYS,
                    "{$path}.movement_terrain_contract.destination_terrain_key",
                );
            } else {
                $this->validateMonsterMovementContract($movement, $facilityKeys, "{$path}.movement_terrain_contract");
            }
            if ($trample !== ['population_after' => 0, 'remove_facility' => true, 'restore_previous_terrain' => false]) {
                throw new DomainException("{$path}.trample_contract differs from the PR21 owner decision.");
            }
            $expectedHardening = match ($skill) {
                'harden_odd' => ['type' => 'target_turn_parity', 'parity' => 'odd'],
                'harden_even' => ['type' => 'target_turn_parity', 'parity' => 'even'],
                default => ['type' => 'none'],
            };
            if ($hardening !== $expectedHardening) {
                throw new DomainException("{$path}.hardening_contract does not match skill_key.");
            }
        }
        if ($extended) {
            $this->validateMonsterDispatchDefinitionReferences($settings, $definitions, $authoredBehaviors);
        }

        $systemPath = 'ruleset.monster_system';
        $system = $this->map($settings['monster_system'], $systemPath);
        $this->requireKeys($system, ['footprint_cells', 'natural_spawn', 'movement', 'reward', 'terrain_events', 'kill_stats'], $systemPath);
        if ($this->integer($system['footprint_cells'], "{$systemPath}.footprint_cells", 1) !== 1) {
            throw new DomainException("{$systemPath}.footprint_cells must be exactly 1 for PR21.");
        }
        $this->validateMonsterMovementContract(
            $this->map($system['movement'], "{$systemPath}.movement"),
            $facilityKeys,
            "{$systemPath}.movement",
        );

        $spawnPath = "{$systemPath}.natural_spawn";
        $spawn = $this->map($system['natural_spawn'], $spawnPath);
        $this->requireKeys($spawn, [
            'probability_per_land_cell', 'maximum_probability_numerator', 'one_draw_per_active_nation',
            'eligible_nation_state', 'minimum_population', 'population_tiers', 'settlement_facility_keys',
            'exclude_capital', 'maximum_per_nation_per_turn', 'selection', 'stream_version',
        ], $spawnPath);
        $probability = $this->map($spawn['probability_per_land_cell'], "{$spawnPath}.probability_per_land_cell");
        $minimumPopulation = $this->integer(
            $spawn['minimum_population'],
            "{$spawnPath}.minimum_population",
            1,
        );
        if ($probability !== ['numerator' => 2, 'denominator' => 10_000]
            || $this->integer($spawn['maximum_probability_numerator'], "{$spawnPath}.maximum_probability_numerator", 0) !== 10_000
            || $this->boolean($spawn['one_draw_per_active_nation'], "{$spawnPath}.one_draw_per_active_nation") !== true
            || $this->persistedString($spawn['eligible_nation_state'], "{$spawnPath}.eligible_nation_state") !== 'active'
            || $minimumPopulation !== 100_000
            || $this->boolean($spawn['exclude_capital'], "{$spawnPath}.exclude_capital") !== true
            || $this->integer($spawn['maximum_per_nation_per_turn'], "{$spawnPath}.maximum_per_nation_per_turn", 1) !== 1
            || $this->persistedString($spawn['selection'], "{$spawnPath}.selection") !== 'uniform_source_pool'
            || $this->integer($spawn['stream_version'], "{$spawnPath}.stream_version", 1) !== 1) {
            throw new DomainException("{$spawnPath} differs from the Nation-scoped PR21 spawn contract.");
        }
        $settlements = $this->list($spawn['settlement_facility_keys'], "{$spawnPath}.settlement_facility_keys");
        foreach ($settlements as $facilityKey) {
            $this->reference($facilityKey, $facilityKeys, "{$spawnPath}.settlement_facility_keys");
        }
        if ($settlements !== ['village', 'town', 'city']) {
            throw new DomainException("{$spawnPath}.settlement_facility_keys must exclude Capital and non-settlements.");
        }
        $tiers = $this->list($spawn['population_tiers'], "{$spawnPath}.population_tiers");
        $this->monsterSpawnPolicy->validatePoolReferences($spawn, $keys);
        $expectedTiers = [
            [100_000, ['inora', 'sanjira']],
            [250_000, ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost']],
            [400_000, ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost', 'whale', 'king_inora']],
        ];
        $actualTiers = [];
        foreach ($tiers as $index => $tierValue) {
            $tier = $this->map($tierValue, "{$spawnPath}.population_tiers.{$index}");
            $this->requireKeys($tier, ['minimum_population', 'monster_keys'], "{$spawnPath}.population_tiers.{$index}");
            $monsterKeys = $this->list($tier['monster_keys'], "{$spawnPath}.population_tiers.{$index}.monster_keys");
            foreach ($monsterKeys as $monsterKey) {
                $this->reference($monsterKey, $keys, "{$spawnPath}.population_tiers.{$index}.monster_keys");
            }
            $actualTiers[] = [
                $this->integer($tier['minimum_population'], "{$spawnPath}.population_tiers.{$index}.minimum_population", 1),
                $monsterKeys,
            ];
        }
        if (! $extended && $actualTiers !== $expectedTiers) {
            throw new DomainException("{$spawnPath}.population_tiers must match the audited uniform source pools.");
        }
        if ($extended) {
            $previousMinimum = null;
            foreach ($actualTiers as [$minimum, $monsterKeys]) {
                if ($monsterKeys === [] || $minimum < $minimumPopulation
                    || ($previousMinimum !== null && $minimum <= $previousMinimum)) {
                    throw new DomainException("{$spawnPath}.population_tiers must be non-empty and strictly increasing.");
                }
                $previousMinimum = $minimum;
            }
        }

        $reward = $this->map($system['reward'], "{$systemPath}.reward");
        $this->requireKeys($reward, [
            'killer_money_share', 'host_remainder_share', 'host_food_resource_key',
            'food_tons_per_money_unit', 'missile_base_experience_maximum',
        ], "{$systemPath}.reward");
        $this->reference($reward['host_food_resource_key'], $resourceKeys, "{$systemPath}.reward.host_food_resource_key");
        $saleRates = $this->map($settings['inventory_sale_rates'] ?? null, 'ruleset.inventory_sale_rates');
        $meatRate = $this->map($saleRates['monster_meat'] ?? null, 'ruleset.inventory_sale_rates.monster_meat');
        $inventoryUnits = $this->integer(
            $meatRate['inventory_units'] ?? null,
            'ruleset.inventory_sale_rates.monster_meat.inventory_units',
            1,
        );
        $moneyUnits = $this->integer(
            $meatRate['money_units'] ?? null,
            'ruleset.inventory_sale_rates.monster_meat.money_units',
            1,
        );
        if ($inventoryUnits % $moneyUnits !== 0) {
            throw new DomainException('ruleset.inventory_sale_rates.monster_meat must convert one money unit to exact integer tons.');
        }
        $foodTonsPerMoneyUnit = intdiv($inventoryUnits, $moneyUnits);
        if ($foodTonsPerMoneyUnit !== 500 || $reward !== [
            'killer_money_share' => 'floor_half', 'host_remainder_share' => true,
            'host_food_resource_key' => 'monster_meat', 'food_tons_per_money_unit' => $foodTonsPerMoneyUnit,
            'missile_base_experience_maximum' => 200,
        ]) {
            throw new DomainException("{$systemPath}.reward differs from the PR21 split contract.");
        }

        $terrainEvents = $this->map($system['terrain_events'], "{$systemPath}.terrain_events");
        if ($terrainEvents !== [
            'preserve_occupancy' => ['earthquake', 'tsunami', 'typhoon'],
            'remove_without_rewards' => ['meteor_shower', 'huge_meteor', 'eruption', 'land_subsidence', 'defense_self_destruct', 'terrain_destruction_missile'],
        ]) {
            throw new DomainException("{$systemPath}.terrain_events differs from MONSTER-03.");
        }
        $killStats = $this->map($system['kill_stats'], "{$systemPath}.kill_stats");
        $expectedKillStats = [
            'scope' => 'nation_monster_definition',
            'increment_on_attributed_final_blow' => true,
            'authoritative_for_final_blow_count' => true,
            'authoritative_for_kill_marks' => true,
        ];
        if (! $extended) {
            $expectedKillStats['maximum_species_rows_per_nation'] = 8;
        }
        if ($killStats !== $expectedKillStats) {
            throw new DomainException("{$systemPath}.kill_stats differs from the PR21 aggregate contract.");
        }

        return count($keys);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<mixed>  $monsterDefinitions
     * @param  array<string, array<string, mixed>>  $authoredBehaviors
     */
    private function validateMonsterDispatchDefinitionReferences(
        array $settings,
        array $monsterDefinitions,
        array $authoredBehaviors,
    ): void {
        $dispatchMetadata = null;
        foreach ($this->list($settings['command_definitions'], 'ruleset.command_definitions') as $index => $value) {
            $definition = $this->map($value, "ruleset.command_definitions.{$index}");
            if (($definition['key'] ?? null) === 'monster_dispatch') {
                $dispatchMetadata = $this->map(
                    $definition['metadata'] ?? null,
                    "ruleset.command_definitions.{$index}.metadata",
                );
                break;
            }
        }
        if ($dispatchMetadata === null) {
            throw new DomainException('A v11 ruleset requires monster_dispatch authoring.');
        }

        foreach ($this->monsterDispatchOptions->validateMetadata($dispatchMetadata) as $option) {
            $matches = array_values(array_filter(
                $monsterDefinitions,
                static fn (mixed $value): bool => is_array($value)
                    && ($value['key'] ?? null) === $option['monster_key'],
            ));
            if (count($matches) !== 1) {
                throw new DomainException(
                    "monster_dispatch option {$option['value']} must reference exactly one authored monster definition.",
                );
            }
            if (($authoredBehaviors[$option['monster_key']]['dispatchable'] ?? null) !== true) {
                throw new DomainException(
                    "monster_dispatch option {$option['value']} must reference a dispatchable monster definition.",
                );
            }
        }
    }

    private function usesExtendedMonsterContract(string $key, int $version): bool
    {
        if ($version === 11 && $key === self::UNPUBLISHED_V11_FIXTURE_KEY) {
            return true;
        }
        $expectedKey = match ($version) {
            11 => self::FORMAL_V11_KEY,
            12 => self::FORMAL_V12_KEY,
            13 => self::FORMAL_V13_KEY,
            default => null,
        };
        if (($expectedKey !== null && $key !== $expectedKey)
            || ($expectedKey === null && in_array($key, [self::FORMAL_V11_KEY, self::FORMAL_V12_KEY, self::FORMAL_V13_KEY], true))) {
            throw new DomainException('The v11/v12/v13 ruleset identity and version must be authored together.');
        }

        return $version >= 11;
    }

    private function nonMonsterValidationKey(string $key, int $version): string
    {
        // v11 composes the approved ver 2.3.0 additions with the immutable v10
        // non-monster contracts. This alias validates inherited closed decisions such
        // as B-12 without changing their authored values.
        if ($version === 11 && in_array($key, [
            self::UNPUBLISHED_V11_FIXTURE_KEY,
            self::FORMAL_V11_KEY,
        ], true)) {
            return self::CURRENT_PUBLISHED_BASELINE_KEY;
        }
        if (in_array($version, [12, 13], true)
            && in_array($key, [self::FORMAL_V12_KEY, self::FORMAL_V13_KEY], true)) {
            return self::CURRENT_PUBLISHED_BASELINE_KEY;
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $movement
     * @param  list<string>  $facilityKeys
     */
    private function validateMonsterMovementContract(array $movement, array $facilityKeys, string $path): void
    {
        $expected = [
            'candidate_attempts_per_action' => 3,
            'blocked_terrain_keys' => ['sea', 'shallow', 'mountain'],
            'blocked_facility_keys' => ['seabed_oil_field', 'seabed_base', 'mine', 'monument', 'capital'],
            'defense_facility_key' => 'defense',
            'destination_terrain_key' => 'wasteland',
            'preserve_owner' => true,
        ];
        if ($movement !== $expected) {
            throw new DomainException("{$path} differs from the audited one-cell movement contract.");
        }
        foreach ($movement['blocked_terrain_keys'] as $terrainKey) {
            $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.blocked_terrain_keys");
        }
        $this->reference($movement['destination_terrain_key'], self::TERRAIN_KEYS, "{$path}.destination_terrain_key");
        foreach ($movement['blocked_facility_keys'] as $facilityKey) {
            $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$path}.blocked_facility_keys");
        }
        $this->facilityReferenceOrFuture($movement['defense_facility_key'], $facilityKeys, "{$path}.defense_facility_key");
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function validateResources(array $settings): array
    {
        $definitions = $this->list($settings['resource_definitions'], 'ruleset.resource_definitions');
        $keys = $this->definitionKeys($definitions, 'ruleset.resource_definitions');
        $salePrices = $this->map($settings['resource_sale_prices'], 'ruleset.resource_sale_prices');

        foreach ($salePrices as $priceKey => $price) {
            $this->string($priceKey, 'ruleset.resource_sale_prices key');
            $this->integer($price, "ruleset.resource_sale_prices.{$priceKey}", 0);
        }

        foreach ($definitions as $index => $definition) {
            $path = "ruleset.resource_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'name', 'category', 'unit', 'nutrition_per_unit', 'storable', 'tradable',
                'sale_price_key', 'sort_order', 'metadata',
            ], $path);
            $this->persistedString($definition['key'], "{$path}.key");
            $this->persistedString($definition['name'], "{$path}.name");
            $this->persistedString($definition['category'], "{$path}.category");
            $this->persistedString($definition['unit'], "{$path}.unit");
            if ($definition['nutrition_per_unit'] !== null) {
                $nutrition = $this->integer(
                    $definition['nutrition_per_unit'],
                    "{$path}.nutrition_per_unit",
                    0,
                );
                if ($nutrition > self::NUTRITION_DECIMAL_MAX_INTEGER) {
                    throw new DomainException(
                        "{$path}.nutrition_per_unit must fit decimal(12,4) without rounding.",
                    );
                }
            }
            $this->boolean($definition['storable'], "{$path}.storable");
            $this->boolean($definition['tradable'], "{$path}.tradable");
            $priceKey = $this->persistedString($definition['sale_price_key'], "{$path}.sale_price_key");
            if (! array_key_exists($priceKey, $salePrices)) {
                throw new DomainException("{$path}.sale_price_key references missing price {$priceKey}.");
            }
            $this->persistedNonNegativeInteger($definition['sort_order'], "{$path}.sort_order");
            $this->map($definition['metadata'], "{$path}.metadata");
            if (array_key_exists('unit_label', $definition) && $definition['unit_label'] !== null) {
                $this->persistedString($definition['unit_label'], "{$path}.unit_label");
            }
        }

        $initial = $this->map($settings['initial_resources'], 'ruleset.initial_resources');
        foreach ($initial as $resourceKey => $amount) {
            $this->reference($resourceKey, $keys, 'ruleset.initial_resources');
            $this->integer($amount, "ruleset.initial_resources.{$resourceKey}", 0);
        }
        foreach ($keys as $resourceKey) {
            if (! array_key_exists($resourceKey, $initial)) {
                throw new DomainException("ruleset.initial_resources is missing {$resourceKey}.");
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $settings */
    private function validateTerrainQuantities(array $settings): void
    {
        $quantities = $this->map($settings['terrain_quantities'], 'ruleset.terrain_quantities');
        if (! array_key_exists('forest', $quantities)) {
            throw new DomainException('ruleset.terrain_quantities must include forest.');
        }
        foreach ($quantities as $terrainKey => $quantity) {
            $this->reference($terrainKey, self::TERRAIN_KEYS, 'ruleset.terrain_quantities');
            $path = "ruleset.terrain_quantities.{$terrainKey}";
            $quantity = $this->map($quantity, $path);
            $this->requireKeys($quantity, [
                'key', 'label', 'unit', 'initial_quantity', 'minimum_quantity',
                'maximum_quantity', 'growth_increment', 'growth_rule_key',
            ], $path);
            $this->persistedString($quantity['key'], "{$path}.key");
            $this->persistedString($quantity['label'], "{$path}.label");
            $this->persistedString($quantity['unit'], "{$path}.unit");
            $initial = $this->integer($quantity['initial_quantity'], "{$path}.initial_quantity", 0);
            $minimum = $this->integer($quantity['minimum_quantity'], "{$path}.minimum_quantity", 0);
            $maximum = $this->integer($quantity['maximum_quantity'], "{$path}.maximum_quantity", 0);
            $this->integer($quantity['growth_increment'], "{$path}.growth_increment", 0);
            $this->persistedString($quantity['growth_rule_key'], "{$path}.growth_rule_key");
            if ($minimum > $initial || $initial > $maximum) {
                throw new DomainException("{$path} requires minimum <= initial <= maximum.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $facilityKeys
     */
    private function validateInitialIslandFacilities(array $settings, array $facilityKeys): void
    {
        foreach (self::REQUIRED_INITIAL_ISLAND_FACILITY_KEYS as $facilityKey) {
            if (! in_array($facilityKey, $facilityKeys, true)) {
                throw new DomainException(
                    "ruleset.facility_definitions must include {$facilityKey} for initial island generation.",
                );
            }
        }

        $path = 'ruleset.facility_definitions.missile_base';
        $missileBase = $this->map($settings['facility_definitions']['missile_base'], $path);
        $this->requireKeys(
            $missileBase,
            ['visibility_policy', 'initial_experience', 'maximum_experience'],
            $path,
        );
        $visibilityPolicy = $this->persistedString(
            $missileBase['visibility_policy'],
            "{$path}.visibility_policy",
        );
        if ($visibilityPolicy !== FacilityVisibilityPolicy::Disguised->value) {
            throw new DomainException("{$path}.visibility_policy must be disguised.");
        }
        $initialExperience = $this->persistedNonNegativeInteger(
            $missileBase['initial_experience'],
            "{$path}.initial_experience",
        );
        $maximumExperience = $this->persistedNonNegativeInteger(
            $missileBase['maximum_experience'],
            "{$path}.maximum_experience",
        );
        if ($initialExperience > $maximumExperience) {
            throw new DomainException(
                "{$path}.initial_experience cannot exceed maximum_experience.",
            );
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $commandKeys
     * @param  list<string>  $productionKeys
     */
    private function validateFacilities(array $settings, array $commandKeys, array $productionKeys): void
    {
        $assetKeys = [];
        foreach ($this->map($settings['facility_definitions'], 'ruleset.facility_definitions') as $key => $definition) {
            $path = "ruleset.facility_definitions.{$key}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'name', 'asset_key', 'visibility_policy', 'build_command_key', 'scale_unit_people',
                'initial_scale', 'scale_increment', 'maximum_scale', 'workforce_per_scale_people',
                'production_definition_key', 'buildable_terrain_keys',
            ], $path);
            $this->persistedString($key, "{$path} key");
            $this->persistedString($definition['name'], "{$path}.name");
            $assetKey = $this->persistedString($definition['asset_key'], "{$path}.asset_key");
            if (in_array($assetKey, $assetKeys, true)) {
                throw new DomainException(
                    "{$path}.asset_key duplicates facility asset key {$assetKey}.",
                );
            }
            $assetKeys[] = $assetKey;
            $visibilityPolicy = $this->persistedString(
                $definition['visibility_policy'],
                "{$path}.visibility_policy",
            );
            if (! FacilityVisibilityPolicy::isSupported($visibilityPolicy)) {
                throw new DomainException(
                    "{$path}.visibility_policy must be one of "
                    .implode(', ', FacilityVisibilityPolicy::values()).'.',
                );
            }
            $this->nullableReference($definition['build_command_key'], $commandKeys, "{$path}.build_command_key");
            $this->nullableReference(
                $definition['production_definition_key'],
                $productionKeys,
                "{$path}.production_definition_key",
            );
            $this->validateFacilityScaleTuple($definition, $path);
            foreach ([
                'initial_experience', 'maximum_experience',
            ] as $field) {
                if (array_key_exists($field, $definition) && $definition[$field] !== null) {
                    $this->integer($definition[$field], "{$path}.{$field}", 0);
                }
            }
            foreach ($this->list($definition['buildable_terrain_keys'], "{$path}.buildable_terrain_keys") as $terrainKey) {
                $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.buildable_terrain_keys");
            }
            if (array_key_exists('disguise_terrain_key', $definition)) {
                $this->nullableReference(
                    $definition['disguise_terrain_key'],
                    self::TERRAIN_KEYS,
                    "{$path}.disguise_terrain_key",
                );
            }
            if (array_key_exists('disguise_asset_key', $definition) && $definition['disguise_asset_key'] !== null) {
                $this->persistedString($definition['disguise_asset_key'], "{$path}.disguise_asset_key");
            }
            if (array_key_exists('disguise_ownership_policy', $definition)
                && $definition['disguise_ownership_policy'] !== null) {
                $ownershipPolicy = $this->persistedString(
                    $definition['disguise_ownership_policy'],
                    "{$path}.disguise_ownership_policy",
                );
                if ($ownershipPolicy !== 'neutral') {
                    throw new DomainException("{$path}.disguise_ownership_policy must be neutral or null.");
                }
                if ($visibilityPolicy !== FacilityVisibilityPolicy::Disguised->value
                    || ! in_array($definition['disguise_terrain_key'] ?? null, ['sea', 'shallow'], true)) {
                    throw new DomainException(
                        "{$path}.disguise_ownership_policy neutral requires a disguised sea or shallow representation.",
                    );
                }
            }
            if (array_key_exists('level_thresholds', $definition)) {
                foreach ($this->list($definition['level_thresholds'], "{$path}.level_thresholds") as $index => $value) {
                    $this->integer($value, "{$path}.level_thresholds.{$index}", 0);
                }
            }
            if (array_key_exists('launch_capacity_by_level', $definition)) {
                foreach ($this->map($definition['launch_capacity_by_level'], "{$path}.launch_capacity_by_level") as $level => $capacity) {
                    if (! is_int($level) || $level < 1) {
                        throw new DomainException("{$path}.launch_capacity_by_level keys must be positive integers.");
                    }
                    $this->integer($capacity, "{$path}.launch_capacity_by_level.{$level}", 0);
                }
            }
        }
    }

    private function maximumCapitalDistance(
        int $xMin,
        int $xMax,
        int $yMin,
        int $yMax,
        int $reservationRadius,
    ): int {
        $candidateXMin = $xMin + $reservationRadius;
        $candidateXMax = $xMax - $reservationRadius;
        $candidateYMin = $yMin + $reservationRadius;
        $candidateYMax = $yMax - $reservationRadius;
        $corners = [
            new GridCoordinate($candidateXMin, $candidateYMin),
            new GridCoordinate($candidateXMin, $candidateYMax),
            new GridCoordinate($candidateXMax, $candidateYMin),
            new GridCoordinate($candidateXMax, $candidateYMax),
        ];
        $maximumDistance = 0;

        foreach ($corners as $index => $corner) {
            foreach (array_slice($corners, $index + 1) as $otherCorner) {
                $maximumDistance = max($maximumDistance, $corner->distanceTo($otherCorner));
            }
        }

        return $maximumDistance;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateCommands(
        array $settings,
        array $resourceKeys,
        array $facilityKeys,
        string $authoredRulesetKey,
        int $authoredRulesetVersion,
    ): void {
        foreach ($this->list($settings['command_definitions'], 'ruleset.command_definitions') as $index => $definition) {
            $path = "ruleset.command_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'name', 'description', 'target_type', 'target_terrain_keys',
                'target_facility_keys', 'requires_empty_facility', 'cost_money', 'required_resources',
                'execution_phase', 'result_terrain_key', 'result_facility_key', 'sort_order', 'metadata',
            ], $path);
            $commandKey = $this->persistedString($definition['key'], "{$path}.key");
            $this->persistedString($definition['name'], "{$path}.name");
            $this->string($definition['description'], "{$path}.description");
            $this->persistedString($definition['target_type'], "{$path}.target_type");
            $this->persistedString($definition['execution_phase'], "{$path}.execution_phase");
            foreach ($this->list($definition['target_terrain_keys'], "{$path}.target_terrain_keys") as $terrainKey) {
                $this->reference($terrainKey, self::TERRAIN_KEYS, "{$path}.target_terrain_keys");
            }
            foreach ($this->list($definition['target_facility_keys'], "{$path}.target_facility_keys") as $facilityKey) {
                $this->reference($facilityKey, $facilityKeys, "{$path}.target_facility_keys");
            }
            $this->nullableReference($definition['result_terrain_key'], self::TERRAIN_KEYS, "{$path}.result_terrain_key");
            $this->nullableReference($definition['result_facility_key'], $facilityKeys, "{$path}.result_facility_key");
            $this->boolean($definition['requires_empty_facility'], "{$path}.requires_empty_facility");
            $this->integer($definition['cost_money'], "{$path}.cost_money", 0);
            foreach ($this->map($definition['required_resources'], "{$path}.required_resources") as $resourceKey => $amount) {
                $this->reference($resourceKey, $resourceKeys, "{$path}.required_resources");
                $this->integer($amount, "{$path}.required_resources.{$resourceKey}", 0);
            }
            $this->persistedNonNegativeInteger($definition['sort_order'], "{$path}.sort_order");
            $metadata = $this->map($definition['metadata'], "{$path}.metadata");
            if ($commandKey === 'monster_dispatch'
                && $this->usesExtendedMonsterContract($authoredRulesetKey, $authoredRulesetVersion)) {
                if ($definition['cost_money'] !== 3_000) {
                    throw new DomainException("{$path}.cost_money must remain 3000 for the default dispatch option.");
                }
                $this->monsterDispatchOptions->validateMetadata($metadata);
            }
            if ($commandKey === 'reclaim' && array_key_exists('adjacent_water_spread_maximum', $metadata)) {
                $this->integer(
                    $metadata['adjacent_water_spread_maximum'],
                    "{$path}.metadata.adjacent_water_spread_maximum",
                    0,
                );
            }
            if ($commandKey === 'excavate' && array_key_exists('oil_search_effect_key', $metadata)) {
                $this->persistedString(
                    $metadata['oil_search_effect_key'],
                    "{$path}.metadata.oil_search_effect_key",
                );
            }
            if (in_array($settings['key'] ?? null, ['hakoniwa-2s-plus-v6', 'hakoniwa-2s-plus-v7', 'hakoniwa-2s-plus-v8', 'hakoniwa-2s-plus-v9', 'hakoniwa-2s-plus-v10'], true)
                && in_array($commandKey, ['build_defense_facility', 'build_monument'], true)) {
                $expectedEffect = $commandKey === 'build_defense_facility'
                    ? 'defense_self_destruct'
                    : 'monument_flight';
                if (($metadata['owner_overbuild_effect'] ?? null) !== $expectedEffect) {
                    throw new DomainException("{$path}.metadata.owner_overbuild_effect differs from the v6 owner decision.");
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateProduction(array $settings, array $resourceKeys, array $facilityKeys): void
    {
        $salePrices = $this->map($settings['resource_sale_prices'], 'ruleset.resource_sale_prices');
        $productionFacilityKeys = [];
        foreach ($this->list($settings['production_definitions'], 'ruleset.production_definitions') as $index => $definition) {
            $path = "ruleset.production_definitions.{$index}";
            $definition = $this->map($definition, $path);
            $this->requireKeys($definition, [
                'key', 'facility_key', 'output_resource_key', 'production_per_scale',
                'required_workforce_per_scale', 'operating_condition', 'price_reference', 'metadata',
            ], $path);
            $this->persistedString($definition['key'], "{$path}.key");
            $facilityKey = $this->reference(
                $definition['facility_key'],
                $facilityKeys,
                "{$path}.facility_key",
            );
            if (in_array($facilityKey, $productionFacilityKeys, true)) {
                throw new DomainException(
                    "{$path}.facility_key duplicates production facility {$facilityKey}.",
                );
            }
            $productionFacilityKeys[] = $facilityKey;
            $this->reference($definition['output_resource_key'], $resourceKeys, "{$path}.output_resource_key");
            $this->productionDecimal($definition['production_per_scale'], "{$path}.production_per_scale");
            $this->persistedNonNegativeInteger(
                $definition['required_workforce_per_scale'],
                "{$path}.required_workforce_per_scale",
            );
            $this->persistedString($definition['operating_condition'], "{$path}.operating_condition");
            $priceReference = $this->persistedString(
                $definition['price_reference'],
                "{$path}.price_reference",
            );
            if (! array_key_exists($priceReference, $salePrices)) {
                throw new DomainException("{$path}.price_reference references missing price {$priceReference}.");
            }
            $this->map($definition['metadata'], "{$path}.metadata");
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     */
    private function validateVersionAdditions(
        array $settings,
        array $resourceKeys,
        array $facilityKeys,
        int $reservationRadius,
        int $landRadius,
    ): void {
        $this->validateTerritoryContracts($settings);

        if (array_key_exists('development_plan_quantity', $settings)
            && ! DevelopmentPlanQuantity::matchesContract($settings['development_plan_quantity'])) {
            throw new DomainException('ruleset.development_plan_quantity does not match the canonical quantity contract.');
        }
        if (array_key_exists('initial_island_minimum_shallow_cells', $settings)) {
            $minimumShallowCells = $this->integer(
                $settings['initial_island_minimum_shallow_cells'],
                'ruleset.initial_island_minimum_shallow_cells',
                0,
            );
            $reservationCellCount = 1 + (3 * $reservationRadius * ($reservationRadius + 1));
            $permanentLandCellCount = 1 + (3 * $landRadius * ($landRadius + 1));
            $reservationWaterCellCapacity = $reservationCellCount - $permanentLandCellCount;
            $coastalCandidateCapacity = $reservationRadius > $landRadius
                ? 6 * ($landRadius + 1)
                : 0;
            $maximumShallowCells = min($reservationWaterCellCapacity, $coastalCandidateCapacity);
            if ($minimumShallowCells > $maximumShallowCells) {
                throw new DomainException(
                    'ruleset.initial_island_minimum_shallow_cells cannot exceed '
                    ."the guaranteed coastal candidate capacity {$maximumShallowCells}.",
                );
            }
        }
        if (array_key_exists('base_money_capacity', $settings)) {
            $moneyCapacity = $this->integer(
                $settings['base_money_capacity'],
                'ruleset.base_money_capacity',
                0,
            );
            $initialMoney = $this->integer($settings['initial_money'], 'ruleset.initial_money', 0);
            if ($initialMoney > $moneyCapacity) {
                throw new DomainException(
                    'ruleset.initial_money cannot exceed ruleset.base_money_capacity.',
                );
            }
        }
        if (array_key_exists('base_food_capacity_tons', $settings)) {
            $foodCapacity = $this->integer(
                $settings['base_food_capacity_tons'],
                'ruleset.base_food_capacity_tons',
                0,
            );
            $initialResources = $this->map($settings['initial_resources'], 'ruleset.initial_resources');
            foreach ($this->list($settings['resource_definitions'], 'ruleset.resource_definitions') as $definition) {
                $definition = $this->map($definition, 'ruleset.resource_definitions entry');
                if ($definition['category'] !== 'food') {
                    continue;
                }
                $resourceKey = $this->string(
                    $definition['key'],
                    'ruleset.resource_definitions entry.key',
                );
                $amount = $this->integer(
                    $initialResources[$resourceKey],
                    "ruleset.initial_resources.{$resourceKey}",
                    0,
                );
                if ($amount > $foodCapacity) {
                    throw new DomainException(
                        'ruleset initial food total cannot exceed ruleset.base_food_capacity_tons.',
                    );
                }
                $foodCapacity -= $amount;
            }
        }
        $hasResourceCapacities = array_key_exists('resource_capacities', $settings);
        $hasResourceOverflow = array_key_exists('resource_capacity_overflow', $settings);
        if ($hasResourceCapacities !== $hasResourceOverflow) {
            throw new DomainException(
                'ruleset.resource_capacities and ruleset.resource_capacity_overflow must be published together.',
            );
        }
        if ($hasResourceCapacities) {
            $definitionsByKey = [];
            foreach ($this->list($settings['resource_definitions'], 'ruleset.resource_definitions') as $definitionValue) {
                $definition = $this->map($definitionValue, 'ruleset.resource_definitions entry');
                $definitionKey = $this->string($definition['key'], 'ruleset.resource_definitions entry.key');
                $definitionsByKey[$definitionKey] = $definition;
            }
            $resourceCapacities = $this->map($settings['resource_capacities'], 'ruleset.resource_capacities');
            if ($resourceCapacities === []) {
                throw new DomainException('ruleset.resource_capacities must not be empty.');
            }
            $initialResources = $this->map($settings['initial_resources'], 'ruleset.initial_resources');
            foreach ($resourceCapacities as $resourceKey => $capacityValue) {
                $this->reference($resourceKey, $resourceKeys, 'ruleset.resource_capacities');
                $capacity = $this->integer(
                    $capacityValue,
                    "ruleset.resource_capacities.{$resourceKey}",
                    0,
                );
                $definition = $definitionsByKey[$resourceKey];
                if (($definition['category'] ?? null) === 'food') {
                    throw new DomainException(
                        "ruleset.resource_capacities.{$resourceKey} must not replace aggregate food capacity.",
                    );
                }
                if (($definition['storable'] ?? null) !== true) {
                    throw new DomainException(
                        "ruleset.resource_capacities.{$resourceKey} requires a storable resource.",
                    );
                }
                if (($definition['tradable'] ?? null) !== true) {
                    throw new DomainException(
                        "ruleset.resource_capacities.{$resourceKey} requires a tradable resource for stockpile overflow sale.",
                    );
                }
                $initial = $this->integer(
                    $initialResources[$resourceKey] ?? null,
                    "ruleset.initial_resources.{$resourceKey}",
                    0,
                );
                if ($initial > $capacity) {
                    throw new DomainException(
                        "ruleset.initial_resources.{$resourceKey} cannot exceed its resource capacity.",
                    );
                }
            }

            $overflowPath = 'ruleset.resource_capacity_overflow';
            $overflow = $this->map($settings['resource_capacity_overflow'], $overflowPath);
            $this->requireKeys($overflow, [
                'behavior', 'applies_after_sale_policy', 'converts_unsold_to_money', 'event_type',
            ], $overflowPath);
            if ($this->persistedString($overflow['behavior'], "{$overflowPath}.behavior") !== 'sell_stockpile_overflow_then_discard_unsold'
                || $this->boolean($overflow['applies_after_sale_policy'], "{$overflowPath}.applies_after_sale_policy") !== true
                || $this->boolean($overflow['converts_unsold_to_money'], "{$overflowPath}.converts_unsold_to_money") !== false
                || $this->persistedString($overflow['event_type'], "{$overflowPath}.event_type") !== 'capacity.overflow') {
                throw new DomainException(
                    'ruleset.resource_capacity_overflow must sell stockpile overflow and discard unsold excess.',
                );
            }
        }
        if (array_key_exists('inventory_sale_rates', $settings)) {
            foreach ($this->map($settings['inventory_sale_rates'], 'ruleset.inventory_sale_rates') as $resourceKey => $rate) {
                $this->reference($resourceKey, $resourceKeys, 'ruleset.inventory_sale_rates');
                $path = "ruleset.inventory_sale_rates.{$resourceKey}";
                $rate = $this->map($rate, $path);
                $this->requireKeys($rate, ['inventory_units', 'money_units'], $path);
                $this->integer($rate['inventory_units'], "{$path}.inventory_units", 1);
                $this->integer($rate['money_units'], "{$path}.money_units", 1);
            }
        }
        if (array_key_exists('turn_processing', $settings)) {
            $this->validateTurnProcessing($settings['turn_processing'], $resourceKeys, $facilityKeys, $settings);
        }
    }

    /** @param array<string, mixed> $settings */
    private function validateTerritoryContracts(array $settings): void
    {
        if (! in_array($settings['key'] ?? null, ['hakoniwa-2s-plus-v3', 'hakoniwa-2s-plus-v4', 'hakoniwa-2s-plus-v5'], true)) {
            return;
        }

        $expectedTransfer = [
            'capital_core' => [
                'ownership_transfer_protected' => true,
                'owner_states' => ['active'],
                'radius' => 2,
            ],
        ];
        if (($settings['territory_transfer'] ?? null) !== $expectedTransfer) {
            throw new DomainException('ruleset.territory_transfer differs from the ver 1.4.0 Capital core contract.');
        }

        $expectedInfluence = [
            'enabled' => true,
            'policy_version' => 1,
            'owner_states' => ['active'],
            'target' => [
                'unfacilitated_terrain_keys' => ['forest', 'mountain'],
                'facility_keys' => [
                    'village', 'town', 'city',
                    'farm', 'factory', 'mine', 'missile_base', 'defense',
                ],
                'excluded_terrain_keys' => ['sea', 'shallow', 'wasteland', 'scorched'],
                'excluded_facility_keys' => ['seabed_base', 'seabed_oil_field', 'monument'],
                'monster_occupancy' => 'exclude',
                'capital_core' => 'exclude',
            ],
            'source' => [
                'excluded_terrain_keys' => ['sea', 'shallow', 'wasteland', 'scorched'],
                'excluded_facility_keys' => ['seabed_base', 'seabed_oil_field'],
                'monster_occupancy' => 'exclude',
                'monument' => 'allowed',
            ],
            'neighbor' => [
                'directions' => 6,
                'selection' => 'uniform_one',
                'reroll_on_missing_or_ineligible' => false,
            ],
            'resolution' => [
                'cell_visit_order' => 'shared_surface_shuffle_once',
                'attempts_per_eligible_target' => 1,
                'source_state' => 'evaluate_at_visit',
                'mutation_timing' => 'immediate',
                'direction_stream' => 'territory_influence:direction:v1',
            ],
            'effect' => [
                'owner' => 'source_owner',
                'terrain' => 'preserve',
                'population' => 'preserve',
                'facility' => 'preserve',
                'facility_scale' => 'preserve',
                'resource_and_state' => 'preserve',
            ],
        ];
        if (($settings['turn_processing']['territory_influence'] ?? null) !== $expectedInfluence) {
            throw new DomainException('ruleset.turn_processing.territory_influence differs from the ver 1.4.0 contract.');
        }

        $territoryCommand = null;
        foreach ($settings['command_definitions'] ?? [] as $definition) {
            if (is_array($definition) && ($definition['key'] ?? null) === 'territory_expand') {
                $territoryCommand = $definition;
                break;
            }
        }
        $hasDormancy = array_key_exists('nation_lifecycle', $settings);
        $expectedMetadata = [
            'consumes_turn' => true,
            'parameters' => [],
            'legacy_command' => 'Widen',
            'policy_version' => 3,
            'actor_states' => ['active'],
            'adjacency' => ['source_owner' => 'actor', 'directions' => 6],
            'neutral_target' => [
                'allowed' => true,
                'terrain_keys' => ['wasteland', 'scorched', 'plain', 'forest', 'mountain'],
                'requires_empty_facility' => true,
            ],
            'foreign_target' => [
                'owner_states' => $hasDormancy ? ['active', 'dormant'] : ['active'],
                'terrain_keys' => ['wasteland', 'scorched'],
                'requires_empty_facility' => true,
            ],
            'monster_occupancy' => 'reject',
            'capital_core' => 'reject',
            'effect' => [
                'owner' => 'actor',
                'terrain' => 'preserve',
                'population' => 'preserve',
                'facility' => 'preserve',
                'facility_scale' => 'preserve',
                'resource_and_state' => 'preserve',
            ],
        ];
        $expectedTerritoryCommand = [
            'key' => 'territory_expand',
            'name' => '領土拡張',
            'description' => $hasDormancy
                ? '自国領に隣接する中立陸地、または他国の荒地・焼け野原を領有します。休止中の首都周辺は保護されます。'
                : '自国領に隣接する中立陸地、またはactiveな他国の荒地・焼け野原を領有します。',
            'target_type' => 'cell',
            'target_terrain_keys' => ['wasteland', 'scorched', 'plain', 'forest', 'mountain'],
            'target_facility_keys' => [],
            'requires_empty_facility' => true,
            'cost_money' => 100,
            'required_resources' => [],
            'execution_phase' => 'territory',
            'result_terrain_key' => null,
            'result_facility_key' => null,
            'sort_order' => 90,
            'metadata' => $expectedMetadata,
        ];
        if ($territoryCommand !== $expectedTerritoryCommand) {
            throw new DomainException('territory_expand differs from the ver 1.4.0 manual expansion contract.');
        }
    }

    /**
     * @param  list<string>  $resourceKeys
     * @param  list<string>  $facilityKeys
     * @param  array<string, mixed>  $settings
     */
    private function validateTurnProcessing(
        mixed $authored,
        array $resourceKeys,
        array $facilityKeys,
        array $settings,
    ): void {
        $path = 'ruleset.turn_processing';
        $turn = $this->map($authored, $path);
        $this->requireKeys($turn, [
            'automatic_finance_money', 'food', 'workforce', 'settlement', 'famine', 'riot',
            'command_random_effects', 'sale_policy',
        ], $path);
        $this->integer($turn['automatic_finance_money'], "{$path}.automatic_finance_money", 0);

        $food = $this->map($turn['food'], "{$path}.food");
        $this->requireKeys($food, ['population_per_nutrition', 'consumption_priority'], "{$path}.food");
        $this->integer($food['population_per_nutrition'], "{$path}.food.population_per_nutrition", 1);
        $priority = $this->list($food['consumption_priority'], "{$path}.food.consumption_priority");
        if ($priority !== ['wheat', 'fish', 'monster_meat']) {
            throw new DomainException("{$path}.food.consumption_priority must be wheat, fish, monster_meat.");
        }
        foreach ($priority as $resourceKey) {
            $this->reference($resourceKey, $resourceKeys, "{$path}.food.consumption_priority");
        }
        $resourceDefinitions = [];
        foreach ($this->list($settings['resource_definitions'], 'ruleset.resource_definitions') as $definitionValue) {
            $definition = $this->map($definitionValue, 'ruleset.resource_definitions entry');
            $resourceDefinitions[$this->string($definition['key'], 'ruleset.resource_definitions entry.key')] = $definition;
        }
        foreach ($priority as $resourceKey) {
            $definition = $resourceDefinitions[$resourceKey];
            if ($definition['category'] !== 'food' || $definition['unit'] !== 'ton') {
                throw new DomainException("{$path}.food resource {$resourceKey} must use category food and canonical ton units.");
            }
            $this->integer(
                $definition['nutrition_per_unit'],
                "ruleset.resource_definitions.{$resourceKey}.nutrition_per_unit",
                1,
            );
        }

        $workforce = $this->map($turn['workforce'], "{$path}.workforce");
        $this->requireKeys($workforce, [
            'priority', 'farm_output_per_worker', 'factory_output_per_worker',
            'mine_output_per_worker', 'allocation_rule',
        ], "{$path}.workforce");
        if ($this->list($workforce['priority'], "{$path}.workforce.priority") !== ['farm', 'factory_mine']) {
            throw new DomainException("{$path}.workforce.priority must be farm, factory_mine.");
        }
        foreach (['farm_output_per_worker', 'factory_output_per_worker', 'mine_output_per_worker'] as $key) {
            $this->integer($workforce[$key], "{$path}.workforce.{$key}", 1);
        }
        if ($this->persistedString($workforce['allocation_rule'], "{$path}.workforce.allocation_rule") !== 'capacity_proportional_largest_remainder') {
            throw new DomainException("{$path}.workforce.allocation_rule must use deterministic largest remainder allocation.");
        }
        $productionByFacility = [];
        foreach ($this->list($settings['production_definitions'], 'ruleset.production_definitions') as $definitionValue) {
            $definition = $this->map($definitionValue, 'ruleset.production_definitions entry');
            $productionByFacility[$this->string($definition['facility_key'], 'ruleset.production_definitions entry.facility_key')] = $definition;
        }
        foreach (['farm' => 'wheat', 'factory' => 'industrial_goods', 'mine' => 'minerals'] as $facilityKey => $resourceKey) {
            $definition = $productionByFacility[$facilityKey] ?? null;
            if (! is_array($definition)) {
                throw new DomainException("{$path}.workforce requires a production definition for {$facilityKey}.");
            }
            $workersPerScale = $this->integer(
                $definition['required_workforce_per_scale'],
                "ruleset.production_definitions.{$facilityKey}.required_workforce_per_scale",
                1,
            );
            $outputPerWorker = $this->integer(
                $workforce[$facilityKey === 'farm' ? 'farm_output_per_worker' : $facilityKey.'_output_per_worker'],
                "{$path}.workforce.{$facilityKey}_output_per_worker",
                1,
            );
            $productionPerScale = $this->integer(
                $definition['production_per_scale'],
                "ruleset.production_definitions.{$facilityKey}.production_per_scale",
                1,
            );
            if ($productionPerScale !== $workersPerScale * $outputPerWorker) {
                throw new DomainException("{$path}.workforce {$facilityKey} output must match production per scale.");
            }
            if ($definition['output_resource_key'] !== $resourceKey
                || $definition['operating_condition'] !== 'turn_start_workforce_allocation') {
                throw new DomainException("{$path}.workforce {$facilityKey} production mapping is incompatible.");
            }
            $facilityDefinition = $this->map(
                $settings['facility_definitions'][$facilityKey],
                "ruleset.facility_definitions.{$facilityKey}",
            );
            if ($workersPerScale !== $facilityDefinition['scale_unit_people']
                || $workersPerScale !== $facilityDefinition['workforce_per_scale_people']) {
                throw new DomainException("{$path}.workforce {$facilityKey} scale and workforce units must match.");
            }
        }

        $settlement = $this->map($turn['settlement'], "{$path}.settlement");
        $settlementKeys = [
            'appearance_probability', 'initial_population', 'eligible_terrain_key',
            'adjacent_facility_key', 'stages', 'ordinary_growth',
            'attraction_growth', 'attraction_maximum_population',
        ];
        $usesLegacySeaEdgeBands = array_key_exists('sea_edge_bands', $settlement);
        $usesUniformOrdinaryMaximum = array_key_exists('ordinary_maximum_population', $settlement);
        if ($usesLegacySeaEdgeBands === $usesUniformOrdinaryMaximum) {
            throw new DomainException(
                "{$path}.settlement must define exactly one of legacy sea_edge_bands or ordinary_maximum_population.",
            );
        }
        $settlementKeys[] = $usesLegacySeaEdgeBands
            ? 'sea_edge_bands'
            : 'ordinary_maximum_population';
        if (in_array($settings['key'] ?? null, [
            'roadmap-pr22-v1', 'hakoniwa-2s-plus-v1', 'hakoniwa-2s-plus-v2', 'hakoniwa-2s-plus-v3',
            'hakoniwa-2s-plus-v4', 'hakoniwa-2s-plus-v5',
        ], true)) {
            $settlementKeys[] = 'post_ordinary_attraction_growth';
        }
        $this->requireKeys($settlement, $settlementKeys, "{$path}.settlement");
        $this->probability($settlement['appearance_probability'], "{$path}.settlement.appearance_probability");
        $this->integer($settlement['initial_population'], "{$path}.settlement.initial_population", 1);
        $this->reference($settlement['eligible_terrain_key'], self::TERRAIN_KEYS, "{$path}.settlement.eligible_terrain_key");
        $this->reference($settlement['adjacent_facility_key'], $facilityKeys, "{$path}.settlement.adjacent_facility_key");
        $stages = $this->map($settlement['stages'], "{$path}.settlement.stages");
        $this->requireKeys($stages, ['village', 'town', 'city'], "{$path}.settlement.stages");
        $facilityDefinitions = $this->map($settings['facility_definitions'], 'ruleset.facility_definitions');
        $previousMaximum = 0;
        foreach (['village', 'town', 'city'] as $stageKey) {
            $stage = $this->map($stages[$stageKey], "{$path}.settlement.stages.{$stageKey}");
            $this->requireKeys($stage, ['facility_key', 'minimum_population', 'maximum_population'], "{$path}.settlement.stages.{$stageKey}");
            $stageFacilityKey = $this->reference($stage['facility_key'], $facilityKeys, "{$path}.settlement.stages.{$stageKey}.facility_key");
            $stageFacility = $this->map(
                $facilityDefinitions[$stageFacilityKey],
                "ruleset.facility_definitions.{$stageFacilityKey}",
            );
            if ($stageFacility['build_command_key'] !== null || $stageFacility['scale_unit_people'] !== null) {
                throw new DomainException("{$path}.settlement stage facility {$stageFacilityKey} must be population-derived.");
            }
            $minimum = $this->integer($stage['minimum_population'], "{$path}.settlement.stages.{$stageKey}.minimum_population", 1);
            $maximum = $this->integer($stage['maximum_population'], "{$path}.settlement.stages.{$stageKey}.maximum_population", $minimum);
            if ($minimum !== $previousMaximum + 1) {
                throw new DomainException("{$path}.settlement.stages must use contiguous population thresholds.");
            }
            $previousMaximum = $maximum;
        }
        $village = $this->map($stages['village'], "{$path}.settlement.stages.village");
        $initialSettlementPopulation = $this->integer(
            $settlement['initial_population'],
            "{$path}.settlement.initial_population",
            1,
        );
        if ($initialSettlementPopulation < $village['minimum_population']
            || $initialSettlementPopulation > $village['maximum_population']) {
            throw new DomainException("{$path}.settlement initial population must be in the village stage.");
        }
        $largestOrdinaryMaximum = 0;
        if ($usesLegacySeaEdgeBands) {
            $previousMinimumSeaCells = null;
            $lastMinimumSeaCells = null;
            foreach ($this->list($settlement['sea_edge_bands'], "{$path}.settlement.sea_edge_bands") as $index => $bandValue) {
                $band = $this->map($bandValue, "{$path}.settlement.sea_edge_bands.{$index}");
                $this->requireKeys($band, ['minimum_sea_cells', 'maximum_population', 'growth_multiplier'], "{$path}.settlement.sea_edge_bands.{$index}");
                $minimumSeaCells = $this->integer($band['minimum_sea_cells'], "{$path}.settlement.sea_edge_bands.{$index}.minimum_sea_cells", 0);
                if ($previousMinimumSeaCells !== null && $minimumSeaCells >= $previousMinimumSeaCells) {
                    throw new DomainException("{$path}.settlement.sea_edge_bands must use descending minimums.");
                }
                $previousMinimumSeaCells = $minimumSeaCells;
                $lastMinimumSeaCells = $minimumSeaCells;
                $maximumPopulation = $this->integer($band['maximum_population'], "{$path}.settlement.sea_edge_bands.{$index}.maximum_population", 1);
                $largestOrdinaryMaximum = max($largestOrdinaryMaximum, $maximumPopulation);
                $this->integer($band['growth_multiplier'], "{$path}.settlement.sea_edge_bands.{$index}.growth_multiplier", 1);
            }
            if ($lastMinimumSeaCells !== 0) {
                throw new DomainException("{$path}.settlement.sea_edge_bands must end at minimum zero.");
            }
        } else {
            $largestOrdinaryMaximum = $this->integer(
                $settlement['ordinary_maximum_population'],
                "{$path}.settlement.ordinary_maximum_population",
                1,
            );
        }
        $growthKeys = ['ordinary_growth', 'attraction_growth'];
        if (array_key_exists('post_ordinary_attraction_growth', $settlement)) {
            $growthKeys[] = 'post_ordinary_attraction_growth';
        }
        foreach ($growthKeys as $growthKey) {
            $growth = $this->map($settlement[$growthKey], "{$path}.settlement.{$growthKey}");
            $this->requireKeys($growth, ['minimum', 'maximum', 'unit_people'], "{$path}.settlement.{$growthKey}");
            $minimum = $this->integer($growth['minimum'], "{$path}.settlement.{$growthKey}.minimum", 0);
            $maximum = $this->integer($growth['maximum'], "{$path}.settlement.{$growthKey}.maximum", $minimum);
            $this->integer($growth['unit_people'], "{$path}.settlement.{$growthKey}.unit_people", 1);
        }
        $attractionMaximum = $this->integer($settlement['attraction_maximum_population'], "{$path}.settlement.attraction_maximum_population", 1);
        if ($attractionMaximum < $largestOrdinaryMaximum) {
            throw new DomainException("{$path}.settlement attraction maximum cannot be below an ordinary maximum.");
        }

        $famine = $this->map($turn['famine'], "{$path}.famine");
        $this->requireKeys($famine, ['loss_minimum', 'loss_maximum', 'loss_unit_people'], "{$path}.famine");
        $lossMinimum = $this->integer($famine['loss_minimum'], "{$path}.famine.loss_minimum", 0);
        $this->integer($famine['loss_maximum'], "{$path}.famine.loss_maximum", $lossMinimum);
        $this->integer($famine['loss_unit_people'], "{$path}.famine.loss_unit_people", 1);

        $riot = $this->map($turn['riot'], "{$path}.riot");
        $this->requireKeys($riot, ['probability', 'facility_keys'], "{$path}.riot");
        $this->probability($riot['probability'], "{$path}.riot.probability");
        foreach ($this->list($riot['facility_keys'], "{$path}.riot.facility_keys") as $facilityKey) {
            $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$path}.riot.facility_keys");
        }

        $effects = $this->map($turn['command_random_effects'], "{$path}.command_random_effects");
        $this->requireKeys($effects, ['land_clear_buried_treasure'], "{$path}.command_random_effects");
        $treasure = $this->map($effects['land_clear_buried_treasure'], "{$path}.command_random_effects.land_clear_buried_treasure");
        $this->requireKeys($treasure, ['probability', 'reward_minimum_money', 'reward_maximum_money'], "{$path}.command_random_effects.land_clear_buried_treasure");
        $this->probability($treasure['probability'], "{$path}.command_random_effects.land_clear_buried_treasure.probability");
        $rewardMinimum = $this->integer($treasure['reward_minimum_money'], "{$path}.command_random_effects.land_clear_buried_treasure.reward_minimum_money", 0);
        $this->integer($treasure['reward_maximum_money'], "{$path}.command_random_effects.land_clear_buried_treasure.reward_maximum_money", $rewardMinimum);

        foreach ($this->list($settings['command_definitions'], 'ruleset.command_definitions') as $index => $definitionValue) {
            $commandPath = "ruleset.command_definitions.{$index}";
            $definition = $this->map($definitionValue, $commandPath);
            if (($definition['key'] ?? null) !== 'excavate') {
                continue;
            }
            $metadata = $this->map($definition['metadata'] ?? null, "{$commandPath}.metadata");
            if (! array_key_exists('oil_search_effect_key', $metadata)) {
                break;
            }
            $effectKey = $this->persistedString(
                $metadata['oil_search_effect_key'],
                "{$commandPath}.metadata.oil_search_effect_key",
            );
            if (! array_key_exists($effectKey, $effects)) {
                throw new DomainException(
                    "{$commandPath}.metadata.oil_search_effect_key references missing command random effect {$effectKey}.",
                );
            }
            if ($effectKey !== 'seabed_oil_search') {
                throw new DomainException(
                    "{$commandPath}.metadata.oil_search_effect_key must reference the validated seabed_oil_search effect.",
                );
            }
            $this->integer($definition['cost_money'] ?? null, "{$commandPath}.cost_money", 1);
            break;
        }

        if (array_key_exists('seabed_oil_search', $effects)) {
            $oilPath = "{$path}.command_random_effects.seabed_oil_search";
            $oil = $this->map($effects['seabed_oil_search'], $oilPath);
            $this->requireKeys(
                $oil,
                ['facility_key', 'draw_denominator', 'success_threshold_per_cost_unit'],
                $oilPath,
            );
            $facilityKey = $this->reference($oil['facility_key'], $facilityKeys, "{$oilPath}.facility_key");
            $facility = $this->map(
                $settings['facility_definitions'][$facilityKey],
                "ruleset.facility_definitions.{$facilityKey}",
            );
            $buildableTerrainKeys = $this->list(
                $facility['buildable_terrain_keys'] ?? null,
                "ruleset.facility_definitions.{$facilityKey}.buildable_terrain_keys",
            );
            if (! in_array('sea', $buildableTerrainKeys, true)) {
                throw new DomainException(
                    "{$oilPath}.facility_key must reference a facility buildable on sea terrain.",
                );
            }
            $denominator = $this->integer($oil['draw_denominator'], "{$oilPath}.draw_denominator", 1);
            if ($denominator > self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX) {
                throw new DomainException(
                    "{$oilPath}.draw_denominator must be at most ".self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX.'.',
                );
            }
            $thresholdPerUnit = $this->integer(
                $oil['success_threshold_per_cost_unit'],
                "{$oilPath}.success_threshold_per_cost_unit",
                1,
            );
            $quantity = $this->map($settings['development_plan_quantity'], 'ruleset.development_plan_quantity');
            $maximumQuantity = $this->integer(
                $quantity['maximum'] ?? null,
                'ruleset.development_plan_quantity.maximum',
                1,
            );
            if ($maximumQuantity * $thresholdPerUnit > $denominator) {
                throw new DomainException(
                    "{$oilPath} maximum quantity threshold cannot exceed draw_denominator.",
                );
            }
        }

        if (array_key_exists('disasters', $turn) || array_key_exists('oil_field', $turn)
            || array_key_exists('land_level_earthquake', $effects)) {
            $this->validateDisasterProcessing($turn, $effects, $facilityKeys, $settings, $path);
        }

        $salePolicy = $this->map($turn['sale_policy'], "{$path}.sale_policy");
        $this->requireKeys($salePolicy, ['sell_all_forbidden_resource_keys'], "{$path}.sale_policy");
        foreach ($this->list($salePolicy['sell_all_forbidden_resource_keys'], "{$path}.sale_policy.sell_all_forbidden_resource_keys") as $resourceKey) {
            $this->reference($resourceKey, $resourceKeys, "{$path}.sale_policy.sell_all_forbidden_resource_keys");
        }
        if (! in_array('wheat', $salePolicy['sell_all_forbidden_resource_keys'], true)) {
            throw new DomainException("{$path}.sale_policy must forbid sell_all for wheat.");
        }
        if ($settings['default_sale_policy'] !== 'stockpile') {
            throw new DomainException("{$path}.sale_policy requires stockpile as the wheat-safe default.");
        }

        $rates = $this->map($settings['inventory_sale_rates'] ?? null, 'ruleset.inventory_sale_rates');
        foreach ($resourceDefinitions as $resourceKey => $definition) {
            if ($definition['tradable'] === true && ! array_key_exists($resourceKey, $rates)) {
                throw new DomainException("ruleset.inventory_sale_rates is missing tradable resource {$resourceKey}.");
            }
        }
    }

    private function probability(mixed $authored, string $path): void
    {
        $probability = $this->map($authored, $path);
        $this->requireKeys($probability, ['numerator', 'denominator'], $path);
        $numerator = $this->integer($probability['numerator'], "{$path}.numerator", 0);
        $denominator = $this->integer($probability['denominator'], "{$path}.denominator", 1);
        if ($numerator > $denominator) {
            throw new DomainException("{$path}.numerator cannot exceed denominator.");
        }
        if ($denominator > self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX) {
            throw new DomainException("{$path}.denominator exceeds the deterministic random draw range.");
        }
    }

    /**
     * @param  array<string, mixed>  $turn
     * @param  array<string, mixed>  $effects
     * @param  list<string>  $facilityKeys
     * @param  array<string, mixed>  $settings
     */
    private function validateDisasterProcessing(
        array $turn,
        array $effects,
        array $facilityKeys,
        array $settings,
        string $path,
    ): void {
        $this->requireKeys($turn, ['disasters', 'oil_field'], $path);
        $this->requireKeys($effects, ['land_level_earthquake'], "{$path}.command_random_effects");
        $this->requireKeys(
            $settings,
            ['capital_growth_maximum_population', 'capital_damage_percentages'],
            'ruleset',
        );

        $disasters = $this->map($turn['disasters'], "{$path}.disasters");
        $this->requireKeys($disasters, [
            'earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption', 'fire',
        ], "{$path}.disasters");
        foreach ([
            'earthquake' => 10,
            'tsunami' => 10,
            'typhoon' => 10,
            'meteor_shower' => 10,
            'huge_meteor' => 2,
            'eruption' => 1,
        ] as $key => $expectedRadius) {
            $eventPath = "{$path}.disasters.{$key}";
            $event = $this->map($disasters[$key], $eventPath);
            $this->requireKeys($event, ['probability', 'center_padding', 'radius'], $eventPath);
            $this->probability($event['probability'], "{$eventPath}.probability");
            $centerPadding = $this->integer($event['center_padding'], "{$eventPath}.center_padding", 0);
            $maximumCenterPadding = min(
                self::INITIAL_X_MIN - DeterministicRandomStream::MINIMUM_INTEGER,
                DeterministicRandomStream::MAXIMUM_INTEGER - self::INITIAL_X_MAX,
                self::INITIAL_Y_MIN - DeterministicRandomStream::MINIMUM_INTEGER,
                DeterministicRandomStream::MAXIMUM_INTEGER - self::INITIAL_Y_MAX,
            );
            if ($centerPadding > $maximumCenterPadding) {
                throw new DomainException(
                    "{$eventPath}.center_padding must be at most {$maximumCenterPadding} so initial center draws fit signed 32-bit bounds.",
                );
            }
            $radius = $this->integer($event['radius'], "{$eventPath}.radius", 0);
            if ($radius !== $expectedRadius) {
                throw new DomainException("{$eventPath}.radius must be {$expectedRadius} for the implemented damage contract.");
            }
        }

        $earthquake = $this->map($disasters['earthquake'], "{$path}.disasters.earthquake");
        $this->validateEarthquakeSettings($earthquake, $facilityKeys, "{$path}.disasters.earthquake");

        $tsunamiPath = "{$path}.disasters.tsunami";
        $tsunami = $this->map($disasters['tsunami'], $tsunamiPath);
        $this->requireKeys($tsunami, [
            'settlement_facility_keys', 'facility_keys', 'excluded_facility_keys', 'water_facility_keys',
            'internal_denominator', 'adjacent_water_offset',
        ], $tsunamiPath);
        foreach (['settlement_facility_keys', 'facility_keys', 'excluded_facility_keys', 'water_facility_keys'] as $listKey) {
            foreach ($this->list($tsunami[$listKey], "{$tsunamiPath}.{$listKey}") as $facilityKey) {
                $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$tsunamiPath}.{$listKey}");
            }
        }
        $tsunamiDenominator = $this->integer(
            $tsunami['internal_denominator'],
            "{$tsunamiPath}.internal_denominator",
            1,
        );
        if ($tsunamiDenominator > self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX) {
            throw new DomainException(
                "{$tsunamiPath}.internal_denominator must be at most ".self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX.'.',
            );
        }
        $this->integer($tsunami['adjacent_water_offset'], "{$tsunamiPath}.adjacent_water_offset", 0);

        $typhoonPath = "{$path}.disasters.typhoon";
        $typhoon = $this->map($disasters['typhoon'], $typhoonPath);
        $this->requireKeys($typhoon, [
            'facility_keys', 'internal_denominator', 'base_damage_threshold', 'protection_facility_keys',
        ], $typhoonPath);
        foreach (['facility_keys', 'protection_facility_keys'] as $listKey) {
            foreach ($this->list($typhoon[$listKey], "{$typhoonPath}.{$listKey}") as $facilityKey) {
                $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$typhoonPath}.{$listKey}");
            }
        }
        $typhoonDenominator = $this->integer(
            $typhoon['internal_denominator'],
            "{$typhoonPath}.internal_denominator",
            1,
        );
        if ($typhoonDenominator > self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX) {
            throw new DomainException(
                "{$typhoonPath}.internal_denominator must be at most ".self::DETERMINISTIC_RANDOM_DRAW_DENOMINATOR_MAX.'.',
            );
        }
        $this->integer($typhoon['base_damage_threshold'], "{$typhoonPath}.base_damage_threshold", 0);

        $meteorPath = "{$path}.disasters.meteor_shower";
        $meteor = $this->map($disasters['meteor_shower'], $meteorPath);
        $this->requireKeys($meteor, ['continuation_probability', 'seabed_facility_keys'], $meteorPath);
        $continuationPath = "{$meteorPath}.continuation_probability";
        $continuation = $this->map($meteor['continuation_probability'], $continuationPath);
        $this->probability($continuation, $continuationPath);
        if ((int) $continuation['numerator'] >= (int) $continuation['denominator']) {
            throw new DomainException("{$continuationPath} must allow the meteor shower to terminate.");
        }
        foreach ($this->list($meteor['seabed_facility_keys'], "{$meteorPath}.seabed_facility_keys") as $facilityKey) {
            $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$meteorPath}.seabed_facility_keys");
        }

        foreach (['huge_meteor', 'eruption'] as $key) {
            $eventPath = "{$path}.disasters.{$key}";
            $event = $this->map($disasters[$key], $eventPath);
            $this->requireKeys($event, ['seabed_facility_keys'], $eventPath);
            foreach ($this->list($event['seabed_facility_keys'], "{$eventPath}.seabed_facility_keys") as $facilityKey) {
                $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$eventPath}.seabed_facility_keys");
            }
        }

        $firePath = "{$path}.disasters.fire";
        $fire = $this->map($disasters['fire'], $firePath);
        $this->requireKeys($fire, [
            'probability', 'minimum_city_population', 'facility_keys', 'protection_facility_keys',
        ], $firePath);
        $this->probability($fire['probability'], "{$firePath}.probability");
        $this->integer($fire['minimum_city_population'], "{$firePath}.minimum_city_population", 1);
        foreach (['facility_keys', 'protection_facility_keys'] as $listKey) {
            foreach ($this->list($fire[$listKey], "{$firePath}.{$listKey}") as $facilityKey) {
                $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$firePath}.{$listKey}");
            }
        }

        if (array_key_exists('land_subsidence', $disasters)) {
            $subsidencePath = "{$path}.disasters.land_subsidence";
            $subsidence = $this->map($disasters['land_subsidence'], $subsidencePath);
            $this->requireKeys($subsidence, [
                'enabled', 'base_safe_land_cells', 'probability',
                'affected_shallow_result', 'affected_coastal_land_result',
                'mountain_immune', 'capital_damage_percentage',
                'out_of_bounds_is_water', 'stream_version',
            ], $subsidencePath);
            $this->boolean($subsidence['enabled'], "{$subsidencePath}.enabled");
            $this->integer($subsidence['base_safe_land_cells'], "{$subsidencePath}.base_safe_land_cells", 0);
            $this->probability($subsidence['probability'], "{$subsidencePath}.probability");
            if ($this->string($subsidence['affected_shallow_result'], "{$subsidencePath}.affected_shallow_result") !== 'sea') {
                throw new DomainException("{$subsidencePath}.affected_shallow_result must be sea.");
            }
            if ($this->string(
                $subsidence['affected_coastal_land_result'],
                "{$subsidencePath}.affected_coastal_land_result",
            ) !== 'shallow') {
                throw new DomainException("{$subsidencePath}.affected_coastal_land_result must be shallow.");
            }
            if (! $this->boolean($subsidence['mountain_immune'], "{$subsidencePath}.mountain_immune")) {
                throw new DomainException("{$subsidencePath}.mountain_immune must be true.");
            }
            $capitalDamage = $this->integer(
                $subsidence['capital_damage_percentage'],
                "{$subsidencePath}.capital_damage_percentage",
                0,
            );
            if ($capitalDamage > 100) {
                throw new DomainException("{$subsidencePath}.capital_damage_percentage cannot exceed 100.");
            }
            if (! $this->boolean(
                $subsidence['out_of_bounds_is_water'],
                "{$subsidencePath}.out_of_bounds_is_water",
            )) {
                throw new DomainException("{$subsidencePath}.out_of_bounds_is_water must be true.");
            }
            $this->integer($subsidence['stream_version'], "{$subsidencePath}.stream_version", 1);
        }

        $landPath = "{$path}.command_random_effects.land_level_earthquake";
        $landEarthquake = $this->map($effects['land_level_earthquake'], $landPath);
        $this->requireKeys($landEarthquake, [
            'probability', 'radius', 'minimum_city_population', 'facility_keys', 'damage_probability',
        ], $landPath);
        $this->probability($landEarthquake['probability'], "{$landPath}.probability");
        $landRadius = $this->integer($landEarthquake['radius'], "{$landPath}.radius", 0);
        if ($landRadius !== 10) {
            throw new DomainException("{$landPath}.radius must be 10 for the implemented damage contract.");
        }
        $this->validateEarthquakeSettings($landEarthquake, $facilityKeys, $landPath);

        $oilPath = "{$path}.oil_field";
        $oil = $this->map($turn['oil_field'], $oilPath);
        $this->requireKeys($oil, [
            'facility_key', 'income_money', 'depletion_probability', 'depleted_terrain_key',
        ], $oilPath);
        $this->reference($oil['facility_key'], $facilityKeys, "{$oilPath}.facility_key");
        $this->integer($oil['income_money'], "{$oilPath}.income_money", 0);
        $this->probability($oil['depletion_probability'], "{$oilPath}.depletion_probability");
        $this->reference($oil['depleted_terrain_key'], self::TERRAIN_KEYS, "{$oilPath}.depleted_terrain_key");
    }

    /** @param array<string, mixed> $settings
     * @param  list<string>  $facilityKeys
     */
    private function validateEarthquakeSettings(array $settings, array $facilityKeys, string $path): void
    {
        $this->requireKeys($settings, [
            'minimum_city_population', 'facility_keys', 'damage_probability',
        ], $path);
        $this->integer($settings['minimum_city_population'], "{$path}.minimum_city_population", 1);
        $this->probability($settings['damage_probability'], "{$path}.damage_probability");
        foreach ($this->list($settings['facility_keys'], "{$path}.facility_keys") as $facilityKey) {
            $this->facilityReferenceOrFuture($facilityKey, $facilityKeys, "{$path}.facility_keys");
        }
    }

    /** @param list<string> $facilityKeys */
    private function facilityReferenceOrFuture(mixed $value, array $facilityKeys, string $path): string
    {
        $key = $this->persistedString($value, $path);
        if (! in_array($key, $facilityKeys, true)
            && ! in_array($key, self::LEGACY_FUTURE_FACILITY_KEYS, true)) {
            throw new DomainException("{$path} references unknown facility semantic key {$key}.");
        }

        return $key;
    }

    /**
     * @param  list<array<string, mixed>>|array<string, array<string, mixed>>  $definitions
     * @return list<string>
     */
    private function definitionKeys(array $definitions, string $path, bool $associative = false): array
    {
        $keys = [];
        foreach ($definitions as $index => $definition) {
            if ($associative) {
                $key = $this->persistedString($index, "{$path} key");
                $this->map($definition, "{$path}.{$key}");
            } else {
                $definition = $this->map($definition, "{$path}.{$index}");
                if (! array_key_exists('key', $definition)) {
                    throw new DomainException("{$path}.{$index} is missing required key.");
                }
                $key = $this->persistedString($definition['key'], "{$path}.{$index}.key");
            }
            if (in_array($key, $keys, true)) {
                throw new DomainException("{$path} contains duplicate definition key {$key}.");
            }
            $keys[] = $key;
        }

        return $keys;
    }

    /** @param list<string> $allowed */
    private function reference(mixed $value, array $allowed, string $path): string
    {
        $reference = $this->string($value, $path);
        if (! in_array($reference, $allowed, true)) {
            throw new DomainException("{$path} references missing catalog or definition {$reference}.");
        }

        return $reference;
    }

    /** @param list<string> $allowed */
    private function nullableReference(mixed $value, array $allowed, string $path): ?string
    {
        return $value === null ? null : $this->reference($value, $allowed, $path);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $required
     */
    private function requireKeys(array $values, array $required, string $path): void
    {
        foreach ($required as $key) {
            if (! array_key_exists($key, $values)) {
                throw new DomainException("{$path} is missing required key {$key}.");
            }
        }
    }

    /** @param array<string, mixed> $definition */
    private function validateFacilityScaleTuple(array $definition, string $path): void
    {
        $nonNullFields = array_values(array_filter(
            self::FACILITY_SCALE_FIELDS,
            static fn (string $field): bool => $definition[$field] !== null,
        ));
        if ($nonNullFields === []) {
            return;
        }
        if (count($nonNullFields) !== count(self::FACILITY_SCALE_FIELDS)) {
            throw new DomainException(
                "{$path} scale fields must either all be null or all be non-null.",
            );
        }

        /** @var array<string, int> $values */
        $values = [];
        foreach (self::FACILITY_SCALE_FIELDS as $field) {
            $values[$field] = $this->persistedNonNegativeInteger(
                $definition[$field],
                "{$path}.{$field}",
            );
        }
        if ($values['initial_scale'] > $values['maximum_scale']) {
            throw new DomainException(
                "{$path}.initial_scale cannot exceed maximum_scale.",
            );
        }
    }

    private function persistedNonNegativeInteger(mixed $value, string $path): int
    {
        $value = $this->integer($value, $path, 0);
        if ($value > self::POSTGRESQL_INTEGER_MAX) {
            throw new DomainException(
                "{$path} must fit the PostgreSQL integer range 0..".self::POSTGRESQL_INTEGER_MAX.'.',
            );
        }

        return $value;
    }

    private function productionDecimal(mixed $value, string $path): int|float
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new DomainException(
                "{$path} must fit decimal(16,4) without rounding.",
            );
        }
        if (is_float($value) && ! is_finite($value)) {
            throw new DomainException(
                "{$path} must be finite and fit decimal(16,4) without rounding.",
            );
        }

        $serialized = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (str_starts_with($serialized, '-')) {
            throw new DomainException(
                "{$path} must be non-negative with at most "
                .self::PRODUCTION_DECIMAL_INTEGER_DIGITS.' integer digits.',
            );
        }
        if (! preg_match('/^(\d+)(?:\.(\d+))?(?:[eE]([+-]?\d+))?$/', $serialized, $parts)) {
            throw new DomainException(
                "{$path} must fit decimal(16,4) without rounding.",
            );
        }

        $integer = $parts[1];
        $fraction = $parts[2] ?? '';
        $digits = $integer.$fraction;
        $decimalPosition = strlen($integer) + (int) ($parts[3] ?? 0);
        if ($decimalPosition <= 0) {
            $integer = '0';
            $fraction = str_repeat('0', -$decimalPosition).$digits;
        } elseif ($decimalPosition >= strlen($digits)) {
            $integer = $digits.str_repeat('0', $decimalPosition - strlen($digits));
            $fraction = '';
        } else {
            $integer = substr($digits, 0, $decimalPosition);
            $fraction = substr($digits, $decimalPosition);
        }

        $integerDigits = strlen(ltrim($integer, '0'));
        if ($integerDigits > self::PRODUCTION_DECIMAL_INTEGER_DIGITS) {
            throw new DomainException(
                "{$path} must be non-negative with at most "
                .self::PRODUCTION_DECIMAL_INTEGER_DIGITS.' integer digits.',
            );
        }
        if (strlen(rtrim($fraction, '0')) > self::PRODUCTION_DECIMAL_SCALE) {
            throw new DomainException(
                "{$path} must have at most "
                .self::PRODUCTION_DECIMAL_SCALE
                .' fractional digits and fit decimal(16,4) without rounding.',
            );
        }

        return $value;
    }

    private function persistedString(mixed $value, string $path): string
    {
        $value = $this->string($value, $path);
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters > self::POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS) {
            throw new DomainException(
                "{$path} must be at most "
                .self::POSTGRESQL_DEFAULT_VARCHAR_MAX_CHARACTERS
                .' characters.',
            );
        }

        return $value;
    }

    private function validateJsonAuthoredValue(mixed $value, string $path): void
    {
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new DomainException("{$path} must contain valid UTF-8.");
            }
            if (str_contains($value, "\0")) {
                throw new DomainException("{$path} must not contain U+0000.");
            }

            return;
        }
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $key => $nested) {
            if (is_string($key) && preg_match('//u', $key) !== 1) {
                throw new DomainException("{$path} contains a key that must contain valid UTF-8.");
            }
            if (is_string($key) && str_contains($key, "\0")) {
                throw new DomainException("{$path} contains a key that must not contain U+0000.");
            }
            $this->validateJsonAuthoredValue($nested, "{$path}.{$key}");
        }
    }

    private function string(mixed $value, string $path): string
    {
        if (! is_string($value) || $value === '') {
            throw new DomainException("{$path} must be a non-empty string.");
        }
        if (preg_match('//u', $value) !== 1) {
            throw new DomainException("{$path} must contain valid UTF-8.");
        }

        return $value;
    }

    private function integer(mixed $value, string $path, ?int $minimum = null): int
    {
        if (! is_int($value)) {
            throw new DomainException("{$path} must be an integer.");
        }
        if ($minimum !== null && $value < $minimum) {
            throw new DomainException("{$path} must be at least {$minimum}.");
        }

        return $value;
    }

    private function boolean(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new DomainException("{$path} must be a boolean.");
        }

        return $value;
    }

    /** @return list<mixed> */
    private function list(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new DomainException("{$path} must be a list.");
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private function map(mixed $value, string $path): array
    {
        if (! is_array($value) || array_is_list($value) && $value !== []) {
            throw new DomainException("{$path} must be an associative array.");
        }

        return $value;
    }
}
