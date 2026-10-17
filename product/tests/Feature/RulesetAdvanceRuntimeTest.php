<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\CurrentCatalogInstaller;
use App\Application\CurrentMonsterDefinitionResolver;
use App\Application\DomesticCommandExecutor;
use App\Application\MissileImpactResolver;
use App\Application\MonsterTurnService;
use App\Application\NationBasicStatusProjection;
use App\Application\PublicMonsterKillProjection;
use App\Application\QueuedCommandDefinitionResolver;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Application\Underground\UndergroundFacilityBenefits;
use App\Application\Underground\UndergroundProfileService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnSeedGenerator;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\NationMonsterKillStat;
use App\Models\NationUndergroundFacility;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\UndergroundTrialProgress;
use App\Models\World;
use App\Services\MapCellPresenter;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\Support\CommandAndMissileTestCase;

final class RulesetAdvanceRuntimeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_world_pointer_advance_runs_old_state_for_three_turns_and_replays_the_first_turn(): void
    {
        $fixture = $this->upgradeFixture();
        $history = $this->history($fixture);
        $next = $this->publishNext($this->nextSettings());
        $this->advanceWorld($fixture, $next);
        $this->assertEffectiveState($fixture, $next);
        $startTurn = (int) $fixture['world']->current_turn;
        $before = $this->gameplay();
        $sequences = $this->sequences();
        $level = DB::transactionLevel();

        DB::beginTransaction();
        try {
            $first = $this->officialTurn($fixture['world']);
            $firstState = $this->gameplay();
            $firstMetrics = $this->phaseMetrics($first);
            $firstSeed = $first->random_seed;
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            $this->restoreSequences($sequences);
        }
        $this->assertSame($before, $this->gameplay(), 'Rehearsal rollback must restore balances, queue, cells and occupancy.');

        $replay = $this->officialTurn($fixture['world']);
        $this->assertSame($firstSeed, $replay->random_seed);
        $this->assertSame($firstMetrics, $this->phaseMetrics($replay));
        $this->assertSame($firstState, $this->gameplay(), 'The same version, seed and state must reproduce the first turn.');
        $this->officialTurn($fixture['world']);
        $last = $this->officialTurn($fixture['world']);

        $this->assertSame($startTurn + 3, $last->target_turn);
        foreach (['surface', 'undergroundItem', 'missile'] as $key) {
            $this->assertSame('completed', $fixture[$key]->fresh()->status);
        }
        $this->assertDatabaseHas('nation_underground_facilities', [
            'nation_id' => $fixture['nation']->id, 'layer' => 1, 'slot_index' => 1,
            'facility_key' => 'underground_factory', 'ruleset_version_id' => $next->id,
        ]);
        $this->assertSame($history, $this->history($fixture));
    }

    /** @return array<string, array{string}> */
    public static function acceptedChanges(): array
    {
        return [
            'monster base HP' => ['monster_hp'],
            'underground farm capacity' => ['underground_capacity'],
            'land clear cost' => ['command_cost'],
            'underground factory build cost' => ['underground_command_cost'],
            'military per-shot cost' => ['missile_shot_cost'],
        ];
    }

    #[DataProvider('acceptedChanges')]
    public function test_one_field_changes_reach_current_presenters_and_real_cost_consumers(string $change): void
    {
        $fixture = $this->upgradeFixture();
        $history = $this->history($fixture);
        $nextSettings = $this->nextSettings();
        $this->changeOneField($nextSettings, $change);
        $next = $this->publishNext($nextSettings);
        $this->advanceWorld($fixture, $next);

        $this->assertEffectiveState($fixture, $next);
        if ($change === 'monster_hp') {
            $this->assertGreaterThan($fixture['monster']->spawned_max_hp, $this->monsterRow($nextSettings)['base_hp']);
        }
        $before = $this->gameplay();
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            // Public phase consumers isolate real debits from unrelated economy income.
            // Full official-pipeline and retry coverage is provided by the core case above.
            foreach (['surface', 'undergroundItem'] as $offset => $key) {
                $context = $this->context($fixture['world']->fresh(), $fixture['world']->current_turn + $offset + 1,
                    hash('sha256', 'current command debit '.$offset), [$fixture['nation']->id]);
                app(CompleteTurnEngine::class)->execute('prepare_turn', $context);
                $money = (int) $fixture['nation']->fresh()->money;
                $metrics = app(DomesticCommandExecutor::class)->execute($context);
                $this->assertSame(1, $metrics['successes']);
                $this->assertSame(0, $metrics['failures']);
                $this->assertSame($this->expectedQueueCost($nextSettings, $key), $money - (int) $fixture['nation']->fresh()->money);
                $this->assertSame('completed', $fixture[$key]->fresh()->status);
            }
            $this->assertDatabaseHas('nation_underground_facilities', [
                'nation_id' => $fixture['nation']->id, 'layer' => 1, 'slot_index' => 1,
                'facility_key' => 'underground_factory', 'ruleset_version_id' => $next->id,
            ]);
            $this->assertMissileDebit($fixture, $next);
            if ($change === 'monster_hp') {
                $beforeMonster = $fixture['monster']->fresh()->only(['monster_definition_id', 'current_hp', 'spawned_max_hp']);
                $context = $this->context($fixture['world']->fresh(), $fixture['world']->current_turn + 4,
                    hash('sha256', 'old spawn below new HP range'), [$fixture['nation']->id]);
                app(CompleteTurnEngine::class)->execute('prepare_turn', $context);
                $service = app(MonsterTurnService::class);
                $batch = $service->load($context);
                $occupancy = $fixture['monster']->occupancy()->sole();
                $this->assertSame($next->id, $batch->occupancyAt($occupancy->map_cell_id)->monster->definition->ruleset_version_id);
                $cells = $this->missileCellIndex($fixture['world']);
                $cell = $occupancy->cell()->with(['terrain', 'facility', 'ownerNation'])->sole();
                $this->assertTrue($service->processCell($context, $fixture['space'], $cell, $cells, $batch));
                $this->assertSame($beforeMonster, $fixture['monster']->fresh()->only(array_keys($beforeMonster)));
            }
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
        }
        $this->assertSame($before, $this->gameplay());
        $this->assertSame($history, $this->history($fixture));
    }

    public function test_one_leaf_surface_capacity_change_is_rejected_before_publishing_or_advancing(): void
    {
        $fixture = $this->upgradeFixture();
        $before = $this->gameplay();
        $settings = $this->nextSettings();
        $settings['facility_definitions']['mine']['scale_unit_people']++;

        try {
            $this->publishNext($settings);
            $this->fail('A mine capacity leaf cannot disagree with workforce and production units.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('workforce', $exception->getMessage());
        }

        $this->assertSame($before, $this->gameplay());
        $this->assertSame($fixture['world']->ruleset_version_id, $fixture['world']->fresh()->ruleset_version_id);
    }

    public function test_legal_surface_capacity_change_requires_an_explicit_catalog_migration(): void
    {
        $fixture = $this->upgradeFixture();
        $settings = $this->nextSettings();
        $unit = $settings['facility_definitions']['mine']['scale_unit_people'] + 1;
        $settings['facility_definitions']['mine']['scale_unit_people'] = $unit;
        $settings['facility_definitions']['mine']['workforce_per_scale_people'] = $unit;
        foreach ($settings['production_definitions'] as &$production) {
            if ($production['facility_key'] === 'mine') {
                $production['required_workforce_per_scale'] = $unit;
                $production['production_per_scale'] = $unit * $settings['turn_processing']['workforce']['mine_output_per_worker'];
            }
        }
        unset($production);
        $next = $this->publishNext($settings);
        $this->advanceWorld($fixture, $next);
        $mineDefinition = $fixture['mine']->fresh(['facility'])->facility;
        $oldCatalog = $mineDefinition->getAttributes();

        try {
            app(CurrentCatalogInstaller::class)->install($settings);
            $this->fail('Publishing must not implicitly overwrite an unversioned surface catalog.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explicit data migration', $exception->getMessage());
        }
        $this->assertSame($oldCatalog, $mineDefinition->fresh()->getAttributes());

        // Explicit fixture-only forward data migration, separate from World pointer advance.
        $mineDefinition->update(['scale_unit_people' => $unit, 'workforce_per_scale_people' => $unit]);
        app(CurrentCatalogInstaller::class)->assertInstalled($settings);
        $mine = $fixture['mine']->fresh(['terrain', 'facility', 'ownerNation']);
        $presented = app(MapCellPresenter::class)->present($mine, $fixture['nation']->id,
            $fixture['world']->current_turn, withinViewerVisibility: true, rulesetSettings: $settings);
        $detail = collect($presented['details'])->firstWhere('key', 'facility_capacity');
        $this->assertSame($unit * $mine->facility_scale, $detail['value']);
        $mineScales = MapCell::query()->where('owner_nation_id', $fixture['nation']->id)
            ->where('facility_definition_id', $mineDefinition->id)->sum('facility_scale');
        $status = app(NationBasicStatusProjection::class)->forNation($fixture['nation']->fresh());
        $this->assertSame($unit * (int) $mineScales, $status['mine_capacity_people']);
        $this->officialTurn($fixture['world']);
    }

    public function test_missing_and_renamed_monster_keys_are_rejected_before_any_state_change(): void
    {
        $this->upgradeFixture();
        $before = $this->gameplay();
        $candidate = $this->nextSettings();
        foreach (['missing', 'renamed'] as $change) {
            $settings = $candidate;
            if ($change === 'missing') {
                $settings['monster_definitions'] = array_values(array_filter($settings['monster_definitions'],
                    static fn (array $monster): bool => $monster['key'] !== 'inora'));
            } else {
                foreach ($settings['monster_definitions'] as &$monster) {
                    if ($monster['key'] === 'inora') {
                        $monster['key'] = 'missing_stable_monster_key';
                    }
                }
                unset($monster);
            }
            try {
                $this->publishNext($settings);
                $this->fail('Removing or renaming a required stable monster key must stop publication.');
            } catch (DomainException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame($before, $this->gameplay(), $change.' key rejection must not mutate state.');
        }
    }

    public function test_only_the_exact_frozen_predecessor_can_replay_after_a_future_publish(): void
    {
        $this->lightweightWorld();
        // This exact predecessor is an append-only migration contract, not a future generation constant.
        $frozen = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v38.php');
        $original = RulesetVersion::query()->where('key', $frozen['key'])->sole();
        $checksum = hash('sha256', json_encode($original->settings, JSON_THROW_ON_ERROR));
        $this->publishNext($this->nextSettings());
        $before = $this->gameplay();
        $replayed = app(RulesetPublisher::class)->publish($frozen);
        $this->assertSame($original->id, $replayed->id);
        $this->assertSame($checksum, hash('sha256', json_encode($replayed->settings, JSON_THROW_ON_ERROR)));
        $this->assertSame($before, $this->gameplay());
        $modified = $frozen;
        $modified['command_definitions'][0]['cost_money']++;
        try {
            app(RulesetPublisher::class)->publish($modified);
            $this->fail('The frozen predecessor exception must reject modified historical authoring.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->gameplay());
    }

    /** @return array<string, mixed> */
    private function upgradeFixture(): array
    {
        $this->freezeTime();
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, 'Ruleset advance fixture');
        $settings = $world->rulesetVersion()->sole()->settings;
        // Keep isolated debit probes above capacity so random treasure credits cannot hide a cost.
        $funding = app(NationCapacityResolver::class)->resolve($nation)->money
            + $this->expectedQueueCost($settings, 'surface') + $this->expectedQueueCost($settings, 'undergroundItem')
            + $settings['military']['missiles']['missile']['cost_money_per_shot'] + 10;
        $nation->update(['money' => $funding]);
        $space = $this->surfaceMapSpace($world);
        $arena = $this->monsterArena($world, $nation);
        $monster = $this->monster($world, $arena);
        $dead = $monster->replicate();
        $dead->fill(['current_hp' => 0, 'state' => 'killed', 'removal_reason' => 'missile', 'removed_at' => now()])->save();
        $stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id, 'nation_id' => $nation->id, 'monster_definition_id' => $monster->monster_definition_id,
            'kill_count' => 1, 'first_killed_turn' => $world->current_turn, 'last_killed_turn' => $world->current_turn, 'version' => 1,
        ]);
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->orderBy('id')->take(4)->get();
        $this->assertCount(4, $owned);
        foreach (['farm', 'mine', 'missile_base'] as $index => $key) {
            app(MapCellStateService::class)->setFacility($owned[$index], FacilityDefinition::query()->where('key', $key)->sole());
            $owned[$index]->save();
        }
        $underground = NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'layer' => 1, 'slot_index' => 0, 'facility_key' => 'underground_farm',
        ]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($user->secretary()->sole());
        $profile->update(['unlocked_area_layers' => 1]);
        UndergroundTrialProgress::query()->updateOrCreate([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_01',
        ], ['unlocked_at' => now(), 'first_cleared_at' => now()]);
        $service = app(CommandQueueService::class);
        $surface = $this->queue($service, $user, $nation->fresh(), $space, 'land_clear', $owned[3]);
        $undergroundItem = $service->add(user: $user, nation: $nation->fresh(), mapSpace: $space,
            commandKey: 'build_underground_factory', targetX: null, targetY: null, requestKey: (string) Str::uuid(),
            expectedVersion: $nation->commandQueue()->sole()->version, targetLayer: 1, targetSlotIndex: 1)['item'];
        $missileTarget = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $space->min_x)->where('y', $space->min_y)->sole();
        $missile = $this->queue($service, $user, $nation->fresh(), $space, 'missile', $missileTarget);
        $this->app->instance(TurnSeedGenerator::class, new class implements TurnSeedGenerator
        {
            public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
            {
                return hash('sha256', 'ruleset advance runtime:'.$ruleset->version.':'.$targetTurn);
            }
        });

        return compact('world', 'user', 'nation', 'space', 'monster', 'dead', 'stat', 'underground', 'surface', 'undergroundItem', 'missile')
            + ['farm' => $owned[0], 'mine' => $owned[1], 'base' => $owned[2]];
    }

    /** @param array<string, mixed> $fixture */
    private function assertEffectiveState(array $fixture, RulesetVersion $next): void
    {
        $settings = $next->settings;
        $source = $fixture['monster']->fresh(['definition', 'occupancy']);
        $oldSpawn = $source->only(['monster_definition_id', 'current_hp', 'spawned_max_hp']);
        $effective = app(CurrentMonsterDefinitionResolver::class)->resolve($source, $next);
        $this->assertSame($next->id, $effective->ruleset_version_id);
        $this->assertSame($oldSpawn, $source->fresh()->only(array_keys($oldSpawn)));
        $cell = $source->occupancy->cell()->with(['terrain', 'facility', 'ownerNation', 'monsterOccupancy.monster.definition'])->sole();
        $shown = app(MapCellPresenter::class)->present($cell, $fixture['nation']->id,
            $fixture['world']->current_turn, withinViewerVisibility: true, rulesetSettings: $settings)['monster'];
        $monster = $this->monsterRow($settings);
        $this->assertSame(['min' => $monster['base_hp'], 'max' => $monster['base_hp'] + $monster['hp_variation']], $shown['hp_range']);
        $this->assertSame($oldSpawn['current_hp'], $shown['current_hp']);
        $this->assertSame($oldSpawn['spawned_max_hp'], $shown['spawned_max_hp']);

        $capacity = $settings['underground_facility_development']['facility_definitions']['underground_farm']['effect']['farm_capacity_people'];
        $benefits = app(UndergroundFacilityBenefits::class);
        $this->assertSame($capacity, $benefits->farmCapacityBonus($fixture['nation']->id));
        $snapshot = $benefits->loadTurnSnapshots([$fixture['nation']->id])[$fixture['nation']->id][0];
        $this->assertSame($next->id, $snapshot['ruleset_version_id']);
        $this->assertSame($capacity, $snapshot['effect']['farm_capacity_people']);
        $status = app(NationBasicStatusProjection::class)->forNation($fixture['nation']->fresh());
        $surfaceFarm = $fixture['farm']->fresh(['facility']);
        $farmScales = MapCell::query()->where('owner_nation_id', $fixture['nation']->id)
            ->where('facility_definition_id', $surfaceFarm->facility_definition_id)->sum('facility_scale');
        $this->assertSame((int) $farmScales * $surfaceFarm->facility->scale_unit_people + $capacity, $status['farm_capacity_people']);
        $rows = $this->actingAs($fixture['user'])->getJson('/api/v1/nations/'.$fixture['nation']->id
            .'/map-spaces/'.$fixture['space']->id.'/command-queue')->assertOk()->json('data.items');
        $rows = collect($rows)->keyBy('id');
        foreach (['surface', 'undergroundItem'] as $key) {
            $definition = app(QueuedCommandDefinitionResolver::class)->resolve($fixture[$key]->fresh());
            $expected = $this->expectedQueueCost($settings, $key);
            $this->assertSame($expected, $definition->cost_money);
            $this->assertSame($expected, $rows[$fixture[$key]->id]['effective_cost_money']);
        }
        $stats = app(PublicMonsterKillProjection::class)->forWorld($fixture['world']->fresh(), [$fixture['nation']->id]);
        $this->assertSame($fixture['stat']->kill_count, collect($stats[$fixture['nation']->id])->firstWhere('key', 'inora')['kill_count']);
    }

    /** @param array<string, mixed> $fixture */
    private function assertMissileDebit(array $fixture, RulesetVersion $next): void
    {
        $space = $fixture['space'];
        $settings = $next->settings['military']['missiles']['missile'];
        $candidates = (new GridCoordinate($fixture['missile']->target_x, $fixture['missile']->target_y))->radius($settings['deviation_radius']);
        $index = array_key_first(array_filter($candidates, fn (GridCoordinate $coordinate): bool =>
            $coordinate->x < $space->min_x || $coordinate->y < $space->min_y));
        $this->assertIsInt($index, 'The fixture aims at a map corner so a legal deviation can miss the map.');
        $context = $this->context($fixture['world']->fresh(), $fixture['world']->current_turn + 3,
            $this->seedForDrawIndex($fixture['missile'], count($candidates), $index), [$fixture['nation']->id]);
        app(CompleteTurnEngine::class)->execute('prepare_turn', $context);
        $metrics = app(DomesticCommandExecutor::class)->execute($context);
        $this->assertSame(1, $metrics['successes']);
        $this->assertSame(0, $metrics['failures']);
        $resolver = app(MissileImpactResolver::class);
        $resolver->begin($this->missileCellIndex($fixture['world']));
        $money = (int) $fixture['nation']->fresh()->money;
        $shots = $resolver->processBase($context, $space, $fixture['base']->fresh(['terrain', 'facility']));
        $this->assertSame(1, $shots['shots_fired']);
        $this->assertSame($settings['cost_money_per_shot'], $shots['money_spent']);
        $this->assertSame($shots['money_spent'], $money - (int) $fixture['nation']->fresh()->money);
        $resolver->finalize($context);
    }

    /** @return array<string, mixed> */
    private function nextSettings(): array
    {
        $settings = config('hakoniwa.ruleset');
        $settings['version']++;
        $settings['key'] = 'hakoniwa-2s-plus-v'.$settings['version'];

        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function publishNext(array $settings): RulesetVersion
    {
        config(['hakoniwa.ruleset' => $settings, 'hakoniwa.published_rulesets' => [$settings['key'] => $settings]]);

        return app(RulesetPublisher::class)->publish($settings);
    }

    /** @param array<string, mixed> $fixture */
    private function advanceWorld(array $fixture, RulesetVersion $next): void
    {
        // No queue identity, living/dead monster or old facility row is rebound here.
        $fixture['world']->update(['ruleset_version_id' => $next->id]);
        $fixture['world']->refresh()->unsetRelation('rulesetVersion');
    }

    /** @param array<string, mixed> $settings */
    private function changeOneField(array &$settings, string $change): void
    {
        if ($change === 'underground_capacity') {
            $settings['underground_facility_development']['facility_definitions']['underground_farm']['effect']['farm_capacity_people']++;
        } elseif ($change === 'missile_shot_cost') {
            $settings['military']['missiles']['missile']['cost_money_per_shot']++;
        } else {
            $section = $change === 'underground_command_cost' ? 'underground_facility_development' : null;
            $list = $change === 'monster_hp' ? 'monster_definitions' : 'command_definitions';
            $key = match ($change) {
                'monster_hp' => 'inora', 'command_cost' => 'land_clear',
                'underground_command_cost' => 'build_underground_factory',
            };
            $rows = $section === null ? $settings[$list] : $settings[$section][$list];
            $index = array_search($key, array_column($rows, 'key'), true);
            $this->assertIsInt($index);
            $rows[$index][$change === 'monster_hp' ? 'base_hp' : 'cost_money']++;
            if ($section === null) {
                $settings[$list] = $rows;
            } else {
                $settings[$section][$list] = $rows;
            }
        }
    }

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private function monsterRow(array $settings): array
    {
        return collect($settings['monster_definitions'])->firstWhere('key', 'inora');
    }

    /** @param array<string, mixed> $settings */
    private function expectedQueueCost(array $settings, string $fixtureKey): int
    {
        $rows = $fixtureKey === 'undergroundItem'
            ? $settings['underground_facility_development']['command_definitions']
            : $settings['command_definitions'];
        $key = $fixtureKey === 'undergroundItem' ? 'build_underground_factory' : 'land_clear';

        return collect($rows)->firstWhere('key', $key)['cost_money'];
    }

    private function officialTurn(World $world): TurnRun
    {
        $run = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status, $run->failure_message ?? '');
        $this->assertSame($run->target_turn, $world->fresh()->current_turn);

        return $run;
    }

    /** @return list<array{phase: string, metrics: array<string, mixed>}> */
    private function phaseMetrics(TurnRun $run): array
    {
        return array_map(static fn (array $phase): array => [
            'phase' => $phase['phase'], 'metrics' => $phase['metrics'],
        ], $run->phase_results);
    }

    /** @param array<string, mixed> $fixture @return array<string, mixed> */
    private function history(array $fixture): array
    {
        $items = [];
        foreach (['surface', 'undergroundItem', 'missile'] as $key) {
            $items[$key] = $fixture[$key]->fresh()->only([
                'id', 'command_definition_id', 'underground_command_key', 'request_ruleset_version_id', 'request_key', 'request_fingerprint',
            ]);
        }

        return [
            'dead' => $fixture['dead']->fresh()->getAttributes(), 'stat' => $fixture['stat']->fresh()->getAttributes(),
            'spawn' => $fixture['monster']->fresh()->only(['monster_definition_id', 'spawned_max_hp', 'spawned_target_turn']),
            'definition' => $fixture['monster']->definition()->sole()->getAttributes(),
            'ruleset' => $fixture['monster']->definition()->sole()->rulesetVersion()->sole()->getAttributes(),
            'old_underground' => $fixture['underground']->fresh()->getAttributes(), 'requests' => $items,
        ];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function gameplay(): array
    {
        $snapshot = [];
        foreach (['worlds', 'nations', 'nation_resources', 'map_cells', 'map_chunks', 'monster_instances',
            'monster_occupancies', 'nation_monster_kill_stats', 'nation_underground_facilities',
            'nation_command_queues', 'nation_command_queue_items', 'ruleset_versions', 'command_definitions',
            'production_definitions', 'monster_definitions', 'facility_definitions'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(static fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    /** @return list<array{name: string, value: int, called: bool}> */
    private function sequences(): array
    {
        $sequences = [];
        foreach (DB::select("SELECT schemaname, sequencename FROM pg_sequences WHERE schemaname = current_schema()") as $row) {
            $name = '"'.str_replace('"', '""', $row->schemaname).'"."'.str_replace('"', '""', $row->sequencename).'"';
            $value = DB::selectOne('SELECT last_value, is_called FROM '.$name);
            $sequences[] = ['name' => $name, 'value' => (int) $value->last_value, 'called' => (bool) $value->is_called];
        }

        return $sequences;
    }

    /** @param list<array{name: string, value: int, called: bool}> $sequences */
    private function restoreSequences(array $sequences): void
    {
        foreach ($sequences as $sequence) {
            DB::selectOne('SELECT setval(CAST(? AS regclass), ?, CAST(? AS boolean))', [
                $sequence['name'], $sequence['value'], $sequence['called'] ? 'true' : 'false',
            ]);
        }
    }
}
