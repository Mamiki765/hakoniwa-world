<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DisasterTurnService;
use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\DeterministicRandomStream;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class DisasterAndOilTurnTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const GLOBAL_KEYS = [
        'earthquake', 'tsunami', 'typhoon', 'meteor_shower', 'huge_meteor', 'eruption',
    ];

    public function test_each_global_disaster_applies_its_normal_cell_contract_at_a_fixed_center(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('通常災害国');
        $target = $this->cellAt($space, 30, 30);
        $cases = [
            'earthquake' => ['plain', 'city', 10_000, 'wasteland', $nation->id],
            'tsunami' => ['plain', 'factory', 0, 'wasteland', $nation->id],
            'typhoon' => ['plain', 'farm', 0, 'plain', $nation->id],
            'meteor_shower' => ['shallow', null, 0, 'sea', $nation->id],
            'huge_meteor' => ['plain', null, 0, 'sea', null],
            'eruption' => ['plain', null, 0, 'mountain', $nation->id],
        ];

        foreach ($cases as $key => [$terrain, $facility, $population, $expectedTerrain, $expectedOwner]) {
            $this->setCell($target, $terrain, $facility, $nation->id, $population);
            $ruleset = $this->forceGlobal($ruleset, $key);
            $seed = $this->seedForCenter($this->centerLabel($key), 30, 30);
            [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id]);

            $result = app(DisasterTurnService::class)->executeGlobal($context);
            $changed = $target->fresh(['terrain', 'facility']);

            $this->assertSame(1, $result['executed_disasters'], $key);
            $this->assertSame($expectedTerrain, $changed->terrain->key, $key);
            $this->assertNull($changed->facility_definition_id, $key);
            $this->assertSame($expectedOwner, $changed->owner_nation_id, $key);
            $this->assertSame(1, DB::table('audit_events')->where('event_type', 'disaster.triggered')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count(), $key);
            $metadata = $this->event($run, 'disaster.triggered');
            $this->assertSame($key, $metadata['disaster_key']);
            $this->assertSame(30, $metadata['center_x']);
            $this->assertSame(30, $metadata['center_y']);
        }
    }

    public function test_eruption_uses_adr_directions_and_never_creates_world_outside_cells(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('端災害国');
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        foreach ([[0, 0], [1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $this->setCell($this->cellAt($space, $x, $y), 'sea', null, null, 0);
        }
        [$context] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, 0, 0),
            [$nation->id],
        );

        app(DisasterTurnService::class)->executeGlobal($context);

        $this->assertSame('mountain', $this->cellAt($space, 0, 0)->terrain()->value('key'));
        foreach ([[1, 0], [0, 1], [1, 1]] as [$x, $y]) {
            $this->assertSame('shallow', $this->cellAt($space, $x, $y)->terrain()->value('key'));
        }
        $this->assertSame(3_600, MapCell::query()->where('map_space_id', $space->id)->count());
        $this->assertFalse(MapCell::query()->where('map_space_id', $space->id)
            ->where(fn ($query) => $query->where('x', '<', 0)->orWhere('y', '<', 0)
                ->orWhere('x', '>', 59)->orWhere('y', '>', 59))->exists());
        $this->assertSame(
            [
                GridCoordinate::EAST => [1, 0],
                GridCoordinate::NORTH_EAST => [1, -1],
                GridCoordinate::NORTH_WEST => [0, -1],
                GridCoordinate::WEST => [-1, 0],
                GridCoordinate::SOUTH_WEST => [0, 1],
                GridCoordinate::SOUTH_EAST => [1, 1],
            ],
            collect(array_keys(GridCoordinate::DIRECTION_NAMES))->mapWithKeys(static function (int $direction): array {
                $neighbor = (new GridCoordinate(0, 0))->neighbor($direction);

                return [$direction => [$neighbor->x, $neighbor->y]];
            })->all(),
        );
    }

    public function test_expanded_world_center_bounds_must_fit_the_deterministic_stream_range(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('拡張境界国');
        $ruleset = $this->forceGlobal($ruleset, 'earthquake');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['earthquake']['center_padding'] = 1;
        });
        $space->update(['max_x' => DeterministicRandomStream::MAXIMUM_INTEGER]);
        [$context] = $this->context($world, $ruleset, hash('sha256', 'expanded-center-bound'), [$nation->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Disaster center draw bounds must fit signed 32-bit integers after World expansion.');

        app(DisasterTurnService::class)->executeGlobal($context);
    }

    public function test_capital_damage_is_sequential_clamped_and_identity_preserving(): void
    {
        [$world, $nation, $ruleset] = $this->worldAndNation('首都災害国');
        $capitalRecord = $nation->capital()->firstOrFail();
        $capital = $capitalRecord->cell()->with(['terrain', 'facility'])->firstOrFail();
        $capital->update(['population' => 10_000]);
        $identity = [
            $capital->id,
            $capital->facility_definition_id,
            $capital->owner_nation_id,
            $capital->terrain_definition_id,
            $capitalRecord->x,
            $capitalRecord->y,
        ];
        $expected = [
            'earthquake' => 9_000,
            'eruption' => 6_300,
            'meteor_shower' => 630,
            'meteor_shower-second' => 100,
        ];

        foreach ($expected as $step => $population) {
            $key = str_starts_with($step, 'meteor_shower') ? 'meteor_shower' : $step;
            $ruleset = $this->forceGlobal($ruleset, $key);
            [$context] = $this->context(
                $world,
                $ruleset,
                $this->seedForCenter($this->centerLabel($key), $capital->x, $capital->y),
                [$nation->id],
            );
            app(DisasterTurnService::class)->executeGlobal($context);
            $this->assertSame($population, $capital->fresh()->population, $step);
        }

        $capital = $capital->fresh(['terrain', 'facility']);
        $capitalRecord->refresh();
        $this->assertSame($identity, [
            $capital->id,
            $capital->facility_definition_id,
            $capital->owner_nation_id,
            $capital->terrain_definition_id,
            $capitalRecord->x,
            $capitalRecord->y,
        ]);
        $this->assertSame('capital', $capital->facility?->key);

        $capital->update(['population' => 50]);
        $ruleset = $this->forceGlobal($ruleset, 'eruption');
        [$minimumContext, $minimumRun] = $this->context(
            $world,
            $ruleset,
            $this->seedForCenter(TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER, $capital->x, $capital->y),
            [$nation->id],
        );
        app(DisasterTurnService::class)->executeGlobal($minimumContext);
        $minimumDamage = $this->event($minimumRun, 'capital.disaster_damaged');
        $this->assertSame(100, $capital->fresh()->population);
        $this->assertTrue($minimumDamage['minimum_population_applied']);
        $this->assertSame(50, $minimumDamage['minimum_population_adjustment']);
        $this->assertSame(5, DB::table('audit_events')->where('event_type', 'capital.disaster_damaged')->count());
    }

    public function test_fire_is_prevented_by_forest_then_damages_factory_and_capital(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('火災国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['disasters']['fire']['probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $factory = $this->cellAt($space, 30, 30);
        $forest = $this->cellAt($space, 31, 30);
        $this->setCell($factory, 'plain', 'factory', $nation->id, 0);
        $this->setCell($forest, 'forest', null, $nation->id, 0);
        [$context, $run] = $this->context($world, $ruleset, hash('sha256', 'fire-protection'), [$nation->id]);

        $this->assertFalse(app(DisasterTurnService::class)->processFire($context, $factory->fresh(['terrain', 'facility'])));
        $this->assertSame('factory', $factory->fresh()->facility()->value('key'));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'fire.prevented')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());

        $this->setCell($forest, 'sea', null, null, 0);
        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $factory->fresh(['terrain', 'facility'])));
        $this->assertSame('wasteland', $factory->fresh()->terrain()->value('key'));
        $this->assertNull($factory->fresh()->facility_definition_id);

        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $capital->update(['population' => 10_000]);
        $this->assertTrue(app(DisasterTurnService::class)->processFire($context, $capital->fresh(['terrain', 'facility'])));
        $this->assertSame(9_000, $capital->fresh()->population);
        $this->assertSame('capital', $capital->fresh()->facility()->value('key'));
    }

    public function test_oil_income_precedes_depletion_obeys_capacity_rolls_back_and_is_retry_idempotent(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('油田稼働国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $settings['turn_processing']['oil_field']['depletion_probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $oil = $this->cellAt($space, 30, 30);
        $this->setCell($oil, 'sea', 'seabed_oil_field', $nation->id, 0);
        $nation->update(['money' => 9_500]);
        $seed = hash('sha256', 'oil-rollback-replay');
        [$rollbackContext] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);

        try {
            DB::transaction(function () use ($rollbackContext): void {
                app(CompleteTurnEngine::class)->execute('process_cells', $rollbackContext);
                throw new RuntimeException('rollback probe');
            });
            $this->fail('Expected the rollback probe to abort the World transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback probe', $exception->getMessage());
        }
        $this->assertSame(9_500, (int) $nation->fresh()->money);
        $this->assertSame('seabed_oil_field', $oil->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $oil->fresh()->owner_nation_id);

        [$context, $run] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);
        $result = app(CompleteTurnEngine::class)->execute('process_cells', $context);
        $income = $this->event($run, 'oil.income');
        $depleted = $this->event($run, 'oil.depleted');
        $oil = $oil->fresh(['terrain', 'facility']);

        $this->assertSame(499, $result->metrics['oil_income']);
        $this->assertSame(1, $result->metrics['oil_depleted']);
        $this->assertSame(1_000, $income['requested_money']);
        $this->assertSame(499, $income['applied_money']);
        $this->assertSame(501, $income['overflow_money']);
        $this->assertSame(9_999, $income['money_capacity']);
        $this->assertTrue($depleted['income_applied_first']);
        $this->assertSame(9_999, (int) $nation->fresh()->money);
        $this->assertNull($oil->facility_definition_id);
        $this->assertNull($oil->owner_nation_id);
        $this->assertSame('sea', $oil->terrain->key);
        $this->assertLessThan(
            DB::table('audit_events')->where('event_type', 'oil.depleted')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('id'),
            DB::table('audit_events')->where('event_type', 'oil.income')
                ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('id'),
        );

        [$retryContext] = $this->context($world, $ruleset, $seed, [$nation->id], [$oil->id]);
        $retry = app(CompleteTurnEngine::class)->execute('process_cells', $retryContext);
        $this->assertSame(0, $retry->metrics['oil_income']);
        $this->assertSame(0, $retry->metrics['oil_depleted']);
        $this->assertSame(9_999, (int) $nation->fresh()->money);
    }

    public function test_land_level_draws_only_after_success_and_applies_the_immediate_event(): void
    {
        [$world, $nation, $ruleset, $space, $user] = $this->worldAndNation('地ならし地震国');
        $ruleset = $this->updateRuleset($ruleset, static function (array &$settings): void {
            $earthquake = &$settings['turn_processing']['command_random_effects']['land_level_earthquake'];
            $earthquake['probability'] = ['numerator' => 1, 'denominator' => 1];
            $earthquake['damage_probability'] = ['numerator' => 1, 'denominator' => 1];
        });
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'wasteland'))->firstOrFail();
        $capitalCellId = $nation->capital()->value('map_cell_id');
        $victim = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', [$target->id, $capitalCellId])->firstOrFail();
        $this->setCell($victim, 'plain', 'factory', $nation->id, 0);
        $valid = $this->queueItem($user, $nation, $space, $target, 'land_level');
        [$successContext, $successRun] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'land-level-success'),
            [$nation->id],
        );

        app(DomesticCommandExecutor::class)->execute($successContext);
        $this->assertSame('completed', $valid->fresh()->status);
        $this->assertSame('wasteland', $victim->fresh()->terrain()->value('key'));
        $this->assertNull($victim->fresh()->facility_definition_id);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.land_level_earthquake')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $successRun->id])->count());
        $damage = $this->event($successRun, 'disaster.cell_damaged');
        $this->assertSame('land_level', $damage['source']);
        $this->assertSame($victim->x, $damage['x']);
        $this->assertSame($victim->y, $damage['y']);

        $invalidTarget = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', [$target->id, $victim->id, $capitalCellId])->firstOrFail();
        $invalid = $this->queueItem($user, $nation, $space, $invalidTarget, 'land_level');
        $this->setCell($invalidTarget, 'sea', null, $nation->id, 0);
        [$failureContext, $failureRun] = $this->context(
            $world,
            $ruleset,
            hash('sha256', 'land-level-failure'),
            [$nation->id],
        );

        app(DomesticCommandExecutor::class)->execute($failureContext);
        $this->assertSame('failed', $invalid->fresh()->status);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.land_level_earthquake')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $failureRun->id])->count());
    }

    /** @return array{World, Nation, RulesetVersion, MapSpace, User} */
    private function worldAndNation(string $name): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();

        return [$world, $nation, $ruleset, $space, $user];
    }

    private function forceGlobal(RulesetVersion $ruleset, string $selected): RulesetVersion
    {
        return $this->updateRuleset($ruleset, static function (array &$settings) use ($selected): void {
            foreach (self::GLOBAL_KEYS as $key) {
                $settings['turn_processing']['disasters'][$key]['probability'] = [
                    'numerator' => $key === $selected ? 1 : 0,
                    'denominator' => 1,
                ];
                $settings['turn_processing']['disasters'][$key]['center_padding'] = 0;
            }
            $settings['turn_processing']['disasters'][$selected]['radius'] = $selected === 'eruption' ? 1 : 0;
            $settings['turn_processing']['disasters']['earthquake']['damage_probability'] = [
                'numerator' => 1, 'denominator' => 1,
            ];
            $settings['turn_processing']['disasters']['tsunami']['internal_denominator'] = 1;
            $settings['turn_processing']['disasters']['tsunami']['adjacent_water_offset'] = 0;
            $settings['turn_processing']['disasters']['typhoon']['internal_denominator'] = 1;
            $settings['turn_processing']['disasters']['typhoon']['base_damage_threshold'] = 1;
            $settings['turn_processing']['disasters']['meteor_shower']['continuation_probability'] = [
                'numerator' => 0, 'denominator' => 1,
            ];
        });
    }

    /** @param callable(array<string, mixed>&): void $mutate */
    private function updateRuleset(RulesetVersion $ruleset, callable $mutate): RulesetVersion
    {
        $settings = $ruleset->settings;
        $mutate($settings);
        $ruleset->settings = $settings;
        $ruleset->save();

        return $ruleset->fresh();
    }

    /**
     * @param  list<int>  $nationIds
     * @param  list<int>  $cellIds
     * @return array{TurnContext, TurnRun}
     */
    private function context(
        World $world,
        RulesetVersion $ruleset,
        string $seed,
        array $nationIds,
        array $cellIds = [],
    ): array {
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 2,
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
        $state->setSurfaceCellIds($cellIds);

        return [
            new TurnContext($world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), $state),
            $run,
        ];
    }

    private function setCell(
        MapCell $cell,
        string $terrainKey,
        ?string $facilityKey,
        ?int $ownerNationId,
        int $population,
    ): void {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        if ($facilityKey !== null) {
            $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        }
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = $population;
        $cell->save();
    }

    private function cellAt(MapSpace $space, int $x, int $y): MapCell
    {
        return MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $x)->where('y', $y)->with(['terrain', 'facility'])->firstOrFail();
    }

    private function centerLabel(string $key): string
    {
        return match ($key) {
            'earthquake' => TurnRandomStreamFactory::GLOBAL_EARTHQUAKE_CENTER,
            'tsunami' => TurnRandomStreamFactory::GLOBAL_TSUNAMI_CENTER,
            'typhoon' => TurnRandomStreamFactory::GLOBAL_TYPHOON_CENTER,
            'meteor_shower' => TurnRandomStreamFactory::GLOBAL_METEOR_SHOWER_CENTER,
            'huge_meteor' => TurnRandomStreamFactory::GLOBAL_HUGE_METEOR_CENTER,
            'eruption' => TurnRandomStreamFactory::GLOBAL_ERUPTION_CENTER,
            default => throw new RuntimeException("Unknown disaster {$key}."),
        };
    }

    private function seedForCenter(string $label, int $x, int $y): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "{$label}:{$x}:{$y}:{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream($label);
            if ($stream->integer(0, 59) === $x && $stream->integer(0, 59) === $y) {
                return $seed;
            }
        }

        $this->fail("Unable to find center seed for {$label} at {$x},{$y}.");
    }

    private function queueItem(
        User $user,
        Nation $nation,
        MapSpace $space,
        MapCell $target,
        string $commandKey,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', $commandKey)->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)
            ->where('nation_id', $nation->id)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => 1,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ])->load('definition');
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType): array
    {
        $metadata = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->value('metadata');

        return json_decode((string) $metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
