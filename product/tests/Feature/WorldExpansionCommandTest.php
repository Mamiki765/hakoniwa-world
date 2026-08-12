<?php

namespace Tests\Feature;

use App\Application\MapSpaceCoveragePreflight;
use App\Application\OceanWorldGenerator;
use App\Application\WorldExpansionService;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldMutationLock;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class WorldExpansionCommandTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_dry_run_reports_complete_preflight_without_changing_the_60_by_60_world(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $beforeRevision = $mapSpace->boundsRevision();

        $this->artisan('hakoniwa:world:expand', [
            ...$this->productionExpansionOptions(),
            '--dry-run' => true,
        ])->expectsOutputToContain('current_bounds=x=0..59,y=0..59')
            ->expectsOutputToContain('target_bounds=x=0..63,y=0..63')
            ->expectsOutputToContain('current_cells=3600 target_cells=4096 expected_added_cells=496')
            ->expectsOutputToContain(
                'existing_chunks=16 target_chunks=16 predicted_created_chunks=0 predicted_touched_existing_chunks=7',
            )
            ->expectsOutputToContain('operation_contract=ok production_guard=not-required-dry-run')
            ->expectsOutputToContain('ruleset=ok coverage=ok chunk_coverage=ok')
            ->expectsOutputToContain('unresolved_turn_runs=0')
            ->expectsOutputToContain('preflight=ok')
            ->expectsOutputToContain('execution=not_started dry_run=true')
            ->assertSuccessful();

        $mapSpace->refresh();
        $this->assertSame([0, 59, 0, 59], [
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        ]);
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame(16, $mapSpace->chunks()->count());
        $this->assertSame($beforeRevision, $mapSpace->boundsRevision());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_wrong_world_is_rejected_before_any_expansion(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();

        $options = $this->productionExpansionOptions();
        $options['--world'] = 'another-world';
        $this->artisan('hakoniwa:world:expand', $options)
            ->expectsOutputToContain("Only the configured World key 'shared-world' can be expanded.")
            ->assertFailed();

        $this->assertSame(3600, $this->surfaceMapSpace($world)->cells()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_production_execution_requires_the_exact_reported_confirmation_without_mutation(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->setEnvironment('production');

        try {
            $this->artisan('hakoniwa:world:expand', $this->productionExpansionOptions())
                ->expectsOutputToContain('app_env=production')
                ->expectsOutputToContain(
                    'confirmation_token=EXPAND:shared-world:0:59:0:59:TO:0:63:0:63',
                )
                ->expectsOutputToContain('Confirmation must exactly equal')
                ->assertFailed();
        } finally {
            $this->setEnvironment('testing');
        }

        $mapSpace = $this->surfaceMapSpace($world);
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame(16, $mapSpace->chunks()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_non_production_execution_is_rejected_even_with_the_exact_confirmation(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();

        $this->artisan('hakoniwa:world:expand', [
            ...$this->productionExpansionOptions(),
            '--confirm' => 'EXPAND:shared-world:0:59:0:59:TO:0:63:0:63',
        ])->expectsOutputToContain('operation_contract=ok production_guard=failed')
            ->expectsOutputToContain('World expansion execution is allowed only when APP_ENV is production')
            ->expectsOutputToContain('preflight=failed execution=not_started')
            ->assertFailed();

        $mapSpace = $this->surfaceMapSpace($world);
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame(16, $mapSpace->chunks()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_production_command_rejects_an_unreviewed_superset_expansion(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $options = $this->productionExpansionOptions();
        $options['--target-max-x'] = '79';
        $options['--target-max-y'] = '79';
        $options['--confirm'] = 'EXPAND:shared-world:0:59:0:59:TO:0:79:0:79';
        $this->setEnvironment('production');

        try {
            $this->artisan('hakoniwa:world:expand', $options)
                ->expectsOutputToContain('operation_contract=failed')
                ->expectsOutputToContain('approved only for shared-world x=0..59,y=0..59 to x=0..63,y=0..63')
                ->expectsOutputToContain('preflight=failed execution=not_started')
                ->assertFailed();
        } finally {
            $this->setEnvironment('testing');
        }

        $mapSpace = $this->surfaceMapSpace($world);
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame(16, $mapSpace->chunks()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_unapproved_huge_target_is_rejected_before_prediction_enumeration(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $options = $this->productionExpansionOptions();
        $options['--target-max-x'] = (string) PHP_INT_MAX;

        $this->artisan('hakoniwa:world:expand', [
            ...$options,
            '--dry-run' => true,
        ])->expectsOutputToContain('operation_contract=failed')
            ->expectsOutputToContain('preflight=failed execution=not_started')
            ->assertFailed();

        $mapSpace = $this->surfaceMapSpace($world);
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame(16, $mapSpace->chunks()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_command_passes_the_exact_operator_contract_to_the_service_once(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $service = Mockery::mock(WorldExpansionService::class);
        $service->shouldReceive('expand')
            ->once()
            ->withArgs(static function (
                World $actualWorld,
                MapBounds $expected,
                MapBounds $target,
                mixed $actor,
                mixed $reason,
            ) use ($world): bool {
                return $actualWorld->is($world)
                    && $expected->equals(new MapBounds(0, 59, 0, 59, 16))
                    && $target->equals(new MapBounds(0, 63, 0, 63, 16))
                    && $actor === null
                    && $reason === 'operator-approved expansion';
            })
            ->andReturn($mapSpace);
        $this->app->instance(WorldExpansionService::class, $service);
        $this->setEnvironment('production');

        try {
            $this->artisan('hakoniwa:world:expand', [
                ...$this->productionExpansionOptions(),
                '--reason' => 'operator-approved expansion',
                '--confirm' => 'EXPAND:shared-world:0:59:0:59:TO:0:63:0:63',
            ])->expectsOutputToContain('preflight=ok')
                ->expectsOutputToContain('execution=complete requested_operation=expand')
                ->assertSuccessful();
        } finally {
            $this->setEnvironment('testing');
        }
    }

    public function test_production_shaped_command_expands_60_to_64_and_same_command_retry_is_a_no_op(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $beforeRevision = $mapSpace->boundsRevision();
        $lastExistingCellId = (int) $mapSpace->cells()->max('id');
        $existingCells = $this->rawCells($mapSpace);
        $existingChunks = $this->rawChunks($mapSpace);
        foreach ([0, 1, 2] as $index) {
            $this->assertSame(192, $mapSpace->cells()->where('chunk_x', 3)->where('chunk_y', $index)->count());
            $this->assertSame(192, $mapSpace->cells()->where('chunk_x', $index)->where('chunk_y', 3)->count());
        }
        $this->assertSame(144, $mapSpace->cells()->where('chunk_x', 3)->where('chunk_y', 3)->count());

        $options = [
            ...$this->productionExpansionOptions(),
            '--confirm' => 'EXPAND:shared-world:0:59:0:59:TO:0:63:0:63',
        ];
        $this->setEnvironment('production');
        try {
            $this->artisan('hakoniwa:world:expand', $options)
                ->expectsOutputToContain(
                    'existing_chunks=16 target_chunks=16 predicted_created_chunks=0 predicted_touched_existing_chunks=7',
                )
                ->expectsOutputToContain(
                    'execution=complete requested_operation=expand result_bounds=x=0..63,y=0..63 cells=4096 chunks=16',
                )
                ->assertSuccessful();

            $mapSpace->refresh();
            $this->assertSame(4096, $mapSpace->cells()->count());
            $this->assertSame(496, $mapSpace->cells()->where('id', '>', $lastExistingCellId)->count());
            $this->assertSame(16, $mapSpace->chunks()->count());
            $this->assertSame($existingCells, $this->rawCells($mapSpace, $lastExistingCellId));
            $this->assertSame($existingChunks, $this->rawChunks($mapSpace));
            foreach ([0, 1, 2] as $index) {
                $this->assertSame(256, $mapSpace->cells()->where('chunk_x', 3)->where('chunk_y', $index)->count());
                $this->assertSame(256, $mapSpace->cells()->where('chunk_x', $index)->where('chunk_y', 3)->count());
            }
            $this->assertSame(256, $mapSpace->cells()->where('chunk_x', 3)->where('chunk_y', 3)->count());
            $this->assertSame(7, $mapSpace->cells()->where('id', '>', $lastExistingCellId)
                ->distinct()->count('map_chunk_id'));
            $this->assertNotSame($beforeRevision, $mapSpace->boundsRevision());
            $this->assertSame((new MapBounds(0, 63, 0, 63, 16))->revision(), $mapSpace->boundsRevision());
            app(MapSpaceCoveragePreflight::class)->assertComplete($mapSpace);
            $this->addToAssertionCount(1);

            $event = DB::table('audit_events')->where('event_type', 'world.expanded')->sole();
            $metadata = json_decode((string) $event->metadata, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(496, $metadata['added_cell_count']);
            $this->assertSame(0, $metadata['created_chunk_count']);
            $this->assertSame(7, $metadata['touched_existing_chunk_count']);
            $this->assertSame('ver 1.5.0 production 60x60 to 64x64', $metadata['reason']);
            $expandedRevision = $mapSpace->boundsRevision();

            $this->artisan('hakoniwa:world:expand', $options)
                ->expectsOutputToContain('current_state=target requested_operation=no-op')
                ->expectsOutputToContain('current_cells=4096 target_cells=4096 expected_added_cells=0')
                ->expectsOutputToContain('execution=complete requested_operation=no-op')
                ->assertSuccessful();
        } finally {
            $this->setEnvironment('testing');
        }
        $this->assertSame(4096, $mapSpace->cells()->count());
        $this->assertSame($expandedRevision, $mapSpace->fresh()->boundsRevision());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_service_failure_after_cell_generation_rolls_the_command_operation_back(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $beforeRevision = $mapSpace->boundsRevision();
        $beforeCells = $this->rawCells($mapSpace);
        $beforeChunks = $this->rawChunks($mapSpace);
        $this->app->bind(WorldExpansionService::class, fn (): WorldExpansionService => new class(app(ChunkCoordinateService::class), app(CurrentRulesetGuard::class), app(WorldMutationLock::class), app(MapSpaceCoveragePreflight::class)) extends WorldExpansionService
        {
            protected function afterCellsGenerated(MapSpace $mapSpace, int $inserted): void
            {
                throw new RuntimeException('injected operator expansion failure');
            }
        });
        $this->setEnvironment('production');

        try {
            $this->artisan('hakoniwa:world:expand', [
                ...$this->productionExpansionOptions(),
                '--reason' => 'rollback fixture',
                '--confirm' => 'EXPAND:shared-world:0:59:0:59:TO:0:63:0:63',
            ])->expectsOutputToContain('World expansion failed: injected operator expansion failure')
                ->assertFailed();
        } finally {
            $this->setEnvironment('testing');
        }

        $mapSpace->refresh();
        $this->assertSame([0, 59, 0, 59], [
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        ]);
        $this->assertSame($beforeRevision, $mapSpace->boundsRevision());
        $this->assertSame($beforeCells, $this->rawCells($mapSpace));
        $this->assertSame($beforeChunks, $this->rawChunks($mapSpace));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_expected_bounds_mismatch_fails_closed_without_guessing_an_intermediate_state(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $options = $this->productionExpansionOptions();
        $options['--expected-max-x'] = '31';

        $this->artisan('hakoniwa:world:expand', [
            ...$options,
            '--dry-run' => true,
        ])->expectsOutputToContain('current_state=unexpected')
            ->expectsOutputToContain('Current bounds match neither expected-before nor target bounds.')
            ->expectsOutputToContain('preflight=failed execution=not_started')
            ->assertFailed();

        $this->assertSame(3600, $this->surfaceMapSpace($world)->cells()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    public function test_unresolved_production_turn_run_blocks_command_preflight(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->turnRun($world, TurnRun::STATUS_BLOCKED);

        $this->artisan('hakoniwa:world:expand', [
            ...$this->productionExpansionOptions(),
            '--dry-run' => true,
        ])->expectsOutputToContain('unresolved_turn_runs=1')
            ->expectsOutputToContain('status=blocked')
            ->expectsOutputToContain('preflight=failed execution=not_started')
            ->assertFailed();

        $this->assertSame(3600, $this->surfaceMapSpace($world)->cells()->count());
    }

    public function test_corrupted_current_coverage_blocks_command_preflight(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $mapSpace->cells()->where('x', 59)->where('y', 59)->delete();

        $this->artisan('hakoniwa:world:expand', [
            ...$this->productionExpansionOptions(),
            '--dry-run' => true,
        ])->expectsOutputToContain('coverage=failed')
            ->expectsOutputToContain('preflight=failed execution=not_started')
            ->assertFailed();

        $mapSpace->refresh();
        $this->assertSame([0, 59, 0, 59], [
            $mapSpace->min_x,
            $mapSpace->max_x,
            $mapSpace->min_y,
            $mapSpace->max_y,
        ]);
        $this->assertSame(3599, $mapSpace->cells()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    /** @return array<string, string> */
    private function productionExpansionOptions(): array
    {
        return [
            '--world' => 'shared-world',
            '--expected-min-x' => '0',
            '--expected-max-x' => '59',
            '--expected-min-y' => '0',
            '--expected-max-y' => '59',
            '--target-min-x' => '0',
            '--target-max-x' => '63',
            '--target-min-y' => '0',
            '--target-max-y' => '63',
            '--reason' => 'ver 1.5.0 production 60x60 to 64x64',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function rawCells(MapSpace $mapSpace, ?int $maximumId = null): array
    {
        $query = $mapSpace->cells()->orderBy('id');
        if ($maximumId !== null) {
            $query->where('id', '<=', $maximumId);
        }

        return $query->get()->map(static fn (MapCell $cell): array => $cell->getRawOriginal())->all();
    }

    /** @return list<array<string, mixed>> */
    private function rawChunks(MapSpace $mapSpace): array
    {
        return $mapSpace->chunks()->orderBy('id')->get()
            ->map(static fn (MapChunk $chunk): array => $chunk->getRawOriginal())->all();
    }

    private function turnRun(World $world, string $status): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('f', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }

    private function setEnvironment(string $environment): void
    {
        $this->app['env'] = $environment;
        $this->app['config']->set('app.env', $environment);
    }
}
