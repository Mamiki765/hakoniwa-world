<?php

namespace Tests\Feature;

use App\Application\MapSpaceCoveragePreflight;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\WorldExpansionService;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldMutationLock;
use App\Models\MapCell;
use App\Models\MapChunk;
use App\Models\MapSpace;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class WorldExpansionServiceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_60_by_60_expands_to_64_by_64_without_mutating_existing_cells_or_chunks(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $before = $this->bounds(0, 59, 0, 59);
        $target = $this->bounds(0, 63, 0, 63);
        $beforeRevision = $mapSpace->boundsRevision();
        $lastExistingCellId = (int) $mapSpace->cells()->max('id');
        $existingCells = $this->rawCells($mapSpace);
        $existingChunks = $this->rawChunks($mapSpace);
        $rulesets = $this->publishedRulesetPayloads();
        $actor = User::factory()->create();
        $this->assertSame(144, $mapSpace->cells()->where('chunk_x', 3)->where('chunk_y', 3)->count());

        $expanded = app(WorldExpansionService::class)->expand(
            $world,
            $before,
            $target,
            $actor,
            'ver 1.5.0 expansion fixture',
        );

        $this->assertSame($mapSpace->id, $expanded->id);
        $this->assertSame(
            ['min_x' => 0, 'max_x' => 63, 'min_y' => 0, 'max_y' => 63],
            $expanded->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(4096, $expanded->cells()->count());
        $this->assertSame(496, $expanded->cells()->where('id', '>', $lastExistingCellId)->count());
        $this->assertSame(16, $expanded->chunks()->count());
        $this->assertSame($existingCells, $this->rawCells($expanded, $lastExistingCellId));
        $this->assertSame($existingChunks, $this->rawChunks($expanded));
        $this->assertSame(256, $expanded->cells()->where('chunk_x', 3)->where('chunk_y', 3)->count());
        $this->assertSame(7, $expanded->cells()->where('id', '>', $lastExistingCellId)
            ->distinct()->count('map_chunk_id'));

        $newCells = $expanded->cells()->where('id', '>', $lastExistingCellId);
        $this->assertSame(496, (clone $newCells)->where('terrain_definition_id',
            TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id'))->count());
        $this->assertSame(0, (clone $newCells)->whereNotNull('owner_nation_id')->count());
        $this->assertSame(0, (clone $newCells)->whereNotNull('facility_definition_id')->count());
        $this->assertSame(0, (clone $newCells)->where('population', '!=', 0)->count());
        $this->assertSame(0, (clone $newCells)->where('state', '!=', 'generated')->count());
        $this->assertSame(0, DB::table('monster_occupancies')->count());

        $this->assertNotSame($beforeRevision, $expanded->boundsRevision());
        $this->assertSame($target->revision(), $expanded->boundsRevision());
        app(MapSpaceCoveragePreflight::class)->assertComplete($expanded);
        $this->addToAssertionCount(1);

        $event = DB::table('audit_events')->where('event_type', 'world.expanded')->sole();
        $metadata = json_decode((string) $event->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($actor->id, $event->actor_user_id);
        $this->assertSame('admin', $event->visibility);
        $this->assertEquals([
            'before_bounds' => ['min_x' => 0, 'max_x' => 59, 'min_y' => 0, 'max_y' => 59],
            'after_bounds' => ['min_x' => 0, 'max_x' => 63, 'min_y' => 0, 'max_y' => 63],
            'added_cell_count' => 496,
            'created_chunk_count' => 0,
            'touched_existing_chunk_count' => 7,
            'actor_user_id' => $actor->id,
            'reason' => 'ver 1.5.0 expansion fixture',
        ], $metadata);
        $publicEvent = DB::table('audit_events')->where('event_type', 'world.expanded_public')->sole();
        $this->assertNull($publicEvent->actor_user_id);
        $this->assertSame('public', $publicEvent->visibility);
        $this->assertSame('{}', (string) $publicEvent->metadata);
        $this->assertSame([], json_decode((string) $publicEvent->metadata, true, flags: JSON_THROW_ON_ERROR));
        $publicNews = $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")
            ->assertOk()
            ->assertJsonPath('data.groups.0.target_turn', $world->current_turn)
            ->assertJsonPath('data.groups.0.events.0.type', 'world.expanded_public')
            ->assertJsonPath(
                'data.groups.0.events.0.message',
                '大きな地響きが鳴り響き、世界がより広くなりました',
            );
        foreach (['before_bounds', 'after_bounds', 'actor_user_id', 'reason', 'metadata'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $publicNews->getContent());
        }
        $this->assertSame($rulesets, $this->publishedRulesetPayloads());

        $sameRevision = $expanded->boundsRevision();
        $retried = app(WorldExpansionService::class)->expand($world->fresh(), $before, $target, $actor);
        $this->assertSame(4096, $retried->cells()->count());
        $this->assertSame($sameRevision, $retried->boundsRevision());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
    }

    public function test_explicit_left_down_right_and_up_expansions_generate_signed_chunk_coordinates(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $service = app(WorldExpansionService::class);
        $steps = [
            '60 to 64' => [$this->bounds(0, 59, 0, 59), $this->bounds(0, 63, 0, 63), 4096, 16],
            'left' => [$this->bounds(0, 63, 0, 63), $this->bounds(-16, 63, 0, 63), 5120, 20],
            'down' => [$this->bounds(-16, 63, 0, 63), $this->bounds(-16, 63, 0, 79), 6400, 25],
            'right' => [$this->bounds(-16, 63, 0, 79), $this->bounds(-16, 79, 0, 79), 7680, 30],
            'up' => [$this->bounds(-16, 79, 0, 79), $this->bounds(-16, 79, -16, 79), 9216, 36],
        ];

        foreach ($steps as $name => [$before, $target, $cellCount, $chunkCount]) {
            $mapSpace = $service->expand($world->fresh(), $before, $target);
            $this->assertSame($cellCount, $mapSpace->cells()->count(), $name);
            $this->assertSame($chunkCount, $mapSpace->chunks()->count(), $name);
            app(MapSpaceCoveragePreflight::class)->assertComplete($mapSpace);
            $this->addToAssertionCount(1);
        }

        $this->assertCellLocation($world, -16, 0, -1, 0, 0, 0);
        $this->assertCellLocation($world, -1, 63, -1, 3, 15, 15);
        $this->assertCellLocation($world, -16, 79, -1, 4, 0, 15);
        $this->assertCellLocation($world, 79, 79, 4, 4, 15, 15);
        $this->assertCellLocation($world, -16, -16, -1, -1, 0, 0);
        $this->assertCellLocation($world, 79, -1, 4, -1, 15, 15);

        $events = DB::table('audit_events')->where('event_type', 'world.expanded')->orderBy('id')->get();
        $this->assertSame(5, $events->count());
        $this->assertSame(5, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
        $this->assertSame(
            [0, 4, 5, 5, 6],
            $events->map(static fn (object $event): int => (int) json_decode(
                (string) $event->metadata,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['created_chunk_count'])->all(),
        );
        $this->assertSame(
            [7, 0, 0, 0, 0],
            $events->map(static fn (object $event): int => (int) json_decode(
                (string) $event->metadata,
                true,
                flags: JSON_THROW_ON_ERROR,
            )['touched_existing_chunk_count'])->all(),
        );
    }

    #[DataProvider('invalidTargetBounds')]
    public function test_shrink_and_non_superset_targets_are_rejected(
        int $minX,
        int $maxX,
        int $minY,
        int $maxY,
    ): void {
        $world = app(OceanWorldGenerator::class)->initialize();

        try {
            app(WorldExpansionService::class)->expand(
                $world,
                $this->bounds(0, 59, 0, 59),
                $this->bounds($minX, $maxX, $minY, $maxY),
            );
            $this->fail('Expected invalid expansion target rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('must completely contain', $exception->getMessage());
        }

        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
    }

    public static function invalidTargetBounds(): array
    {
        return [
            'shrink' => [0, 58, 0, 59],
            'translated non-superset' => [-1, 59, 1, 60],
        ];
    }

    public function test_current_bounds_matching_neither_before_nor_target_fail_closed(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('match neither');

        app(WorldExpansionService::class)->expand(
            $world,
            $this->bounds(0, 31, 0, 31),
            $this->bounds(0, 63, 0, 63),
        );
    }

    public function test_unexpected_preexisting_target_only_cell_is_corruption_not_an_ignored_duplicate(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $location = app(ChunkCoordinateService::class)->locate(60, 0);
        $chunkId = (int) $mapSpace->chunks()->where('chunk_x', 3)->where('chunk_y', 0)->valueOrFail('id');
        $now = now();
        DB::table('map_cells')->insert([
            'map_space_id' => $mapSpace->id,
            'map_chunk_id' => $chunkId,
            'x' => 60,
            'y' => 0,
            ...$location,
            'terrain_definition_id' => TerrainDefinition::query()->where('key', 'sea')->valueOrFail('id'),
            'facility_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
            'state' => 'generated',
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        try {
            app(WorldExpansionService::class)->expand(
                $world,
                $this->bounds(0, 59, 0, 59),
                $this->bounds(0, 63, 0, 63),
            );
            $this->fail('Expected corrupt target-only cell rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('outside current MapSpace bounds', $exception->getMessage());
        }

        $this->assertSame(3601, $mapSpace->cells()->count());
        $this->assertSame(59, $mapSpace->fresh()->max_x);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_each_unresolved_non_dry_turn_run_blocks_expansion(string $status): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->turnRun($world, $status, false);

        try {
            app(WorldExpansionService::class)->expand(
                $world,
                $this->bounds(0, 59, 0, 59),
                $this->bounds(0, 63, 0, 63),
            );
            $this->fail('Expected unresolved TurnRun rejection.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString("({$status})", $exception->getMessage());
        }

        $this->assertSame(3600, MapCell::query()->count());
        $this->assertSame(59, $this->surfaceMapSpace($world)->max_x);
    }

    public static function unresolvedTurnStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    public function test_dry_run_records_do_not_block_expansion(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $this->turnRun($world, TurnRun::STATUS_FAILED, true);

        $expanded = app(WorldExpansionService::class)->expand(
            $world,
            $this->bounds(0, 59, 0, 59),
            $this->bounds(0, 63, 0, 63),
        );

        $this->assertSame(4096, $expanded->cells()->count());
    }

    public function test_failure_after_cell_generation_rolls_back_cells_chunks_bounds_and_audit(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $mapSpace = $this->surfaceMapSpace($world);
        $chunks = $this->rawChunks($mapSpace);
        $service = new class(app(ChunkCoordinateService::class), app(CurrentRulesetGuard::class), app(WorldMutationLock::class), app(MapSpaceCoveragePreflight::class)) extends WorldExpansionService
        {
            protected function afterCellsGenerated(MapSpace $mapSpace, int $inserted): void
            {
                throw new RuntimeException('injected after generated cells');
            }
        };

        try {
            $service->expand(
                $world,
                $this->bounds(0, 59, 0, 59),
                $this->bounds(0, 63, 0, 63),
            );
            $this->fail('Expected injected expansion failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('injected after generated cells', $exception->getMessage());
        }

        $mapSpace->refresh();
        $this->assertSame(
            ['min_x' => 0, 'max_x' => 59, 'min_y' => 0, 'max_y' => 59],
            $mapSpace->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(3600, $mapSpace->cells()->count());
        $this->assertSame($chunks, $this->rawChunks($mapSpace));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        app(MapSpaceCoveragePreflight::class)->assertComplete($mapSpace);
        $this->addToAssertionCount(1);
    }

    public function test_nation_registration_still_succeeds_after_expansion(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        app(WorldExpansionService::class)->expand(
            $world,
            $this->bounds(0, 59, 0, 59),
            $this->bounds(0, 63, 0, 63),
        );

        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world->fresh(),
            '拡張後登録島',
            '拡張後島主',
        );

        $this->assertNotNull($nation->capital);
        $this->assertSame(19, MapCell::query()->where('owner_nation_id', $nation->id)->count());
        $this->assertSame(4096, $this->surfaceMapSpace($world)->cells()->count());
    }

    private function bounds(int $minX, int $maxX, int $minY, int $maxY): MapBounds
    {
        return new MapBounds($minX, $maxX, $minY, $maxY, 16);
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

    /** @return array<string, string> */
    private function publishedRulesetPayloads(): array
    {
        return RulesetVersion::query()
            ->whereIn('key', ['hakoniwa-2s-plus-v1', 'hakoniwa-2s-plus-v2', 'hakoniwa-2s-plus-v3'])
            ->orderBy('key')
            ->get()
            ->mapWithKeys(static fn (RulesetVersion $ruleset): array => [
                $ruleset->key => (string) $ruleset->getRawOriginal('settings'),
            ])->all();
    }

    private function assertCellLocation(
        World $world,
        int $x,
        int $y,
        int $chunkX,
        int $chunkY,
        int $localX,
        int $localY,
    ): void {
        $cell = $this->surfaceMapSpace($world)->cells()->where('x', $x)->where('y', $y)->firstOrFail();
        $this->assertSame([
            'chunk_x' => $chunkX,
            'chunk_y' => $chunkY,
            'local_x' => $localX,
            'local_y' => $localY,
        ], $cell->only(['chunk_x', 'chunk_y', 'local_x', 'local_y']));
    }

    private function turnRun(World $world, string $status, bool $dryRun): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('e', 64),
            'source' => 'manual',
            'is_dry_run' => $dryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }
}
