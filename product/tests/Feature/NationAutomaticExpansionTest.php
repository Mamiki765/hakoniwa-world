<?php

namespace Tests\Feature;

use App\Application\CapitalPlacementService;
use App\Application\MapSpaceCoveragePreflight;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\WorldExpansionService;
use App\Domain\Map\ChunkCoordinateService;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\World\MapBounds;
use App\Domain\World\WorldMutationLock;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class NationAutomaticExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_with_a_candidate_does_not_expand_the_world(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $space = $this->space($world->id);
        $beforeRevision = $space->boundsRevision();

        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '通常登録国',
            '通常島主',
        );

        $this->assertNotNull($nation->capital);
        $this->assertSame($beforeRevision, $space->fresh()->boundsRevision());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
    }

    public function test_zero_candidates_expand_64_left_once_and_registration_succeeds(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $space = app(WorldExpansionService::class)->expand(
            $world,
            new MapBounds(0, 59, 0, 59, 16),
            new MapBounds(0, 63, 0, 63, 16),
        );
        DB::table('audit_events')->whereIn('event_type', ['world.expanded', 'world.expanded_public'])->delete();
        $wastelandId = TerrainDefinition::query()->where('key', 'wasteland')->valueOrFail('id');
        MapCell::query()->where('map_space_id', $space->id)->update(['terrain_definition_id' => $wastelandId]);
        $this->assertSame([], app(CapitalPlacementService::class)->candidates($space->fresh(), 1));
        $existing = $this->rawCells($space);
        $beforeRevision = $space->boundsRevision();

        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world->fresh(),
            '自動拡張国',
            '自動拡張島主',
        );
        $expanded = $space->fresh();

        $this->assertSame(
            ['min_x' => -16, 'max_x' => 63, 'min_y' => 0, 'max_y' => 63],
            $expanded->only(['min_x', 'max_x', 'min_y', 'max_y']),
        );
        $this->assertSame(5_120, $expanded->cells()->count());
        $this->assertSame(20, $expanded->chunks()->count());
        $this->assertSame(1_024, $expanded->cells()->where('x', '<', 0)->count());
        $this->assertSame($existing, $this->rawCells($expanded, 0, 63));
        $this->assertLessThan(0, $nation->capital->x);
        $this->assertNotSame($beforeRevision, $expanded->boundsRevision());
        $this->assertSame((new MapBounds(-16, 63, 0, 63, 16))->revision(), $expanded->boundsRevision());
        app(MapSpaceCoveragePreflight::class)->assertComplete($expanded);
        $this->addToAssertionCount(1);
        $event = DB::table('audit_events')->where('event_type', 'world.expanded')->sole();
        $metadata = json_decode((string) $event->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1_024, $metadata['added_cell_count']);
        $this->assertSame(4, $metadata['created_chunk_count']);
        $this->assertSame('nation_registration_capacity', $metadata['reason']);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
        $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")
            ->assertOk()
            ->assertJsonPath(
                'data.groups.0.events.1.message',
                '大きな地響きが鳴り響き、世界がより広くなりました',
            );
        $this->assertGreaterThanOrEqual(1, count(app(CapitalPlacementService::class)->candidates($expanded, 1)));
    }

    public function test_same_registration_request_returns_the_created_nation_without_expanding_again(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $space = app(WorldExpansionService::class)->expand(
            $world,
            new MapBounds(0, 59, 0, 59, 16),
            new MapBounds(0, 63, 0, 63, 16),
        );
        DB::table('audit_events')->whereIn('event_type', ['world.expanded', 'world.expanded_public'])->delete();
        $wastelandId = TerrainDefinition::query()->where('key', 'wasteland')->valueOrFail('id');
        MapCell::query()->where('map_space_id', $space->id)->update(['terrain_definition_id' => $wastelandId]);
        $user = User::factory()->create();
        $requestKey = (string) Str::uuid();
        $service = app(NationCreationService::class);

        $first = $service->create($user, $world->fresh(), '再試行拡張国', '再試行島主', '', $requestKey);
        $second = $service->create($user, $world->fresh(), '変更しても再試行', '別島主', '', $requestKey);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Nation::query()->count());
        $this->assertSame(1, DB::table('nation_creation_requests')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
        $this->assertSame([-16, 63, 0, 63], [
            $space->fresh()->min_x, $space->fresh()->max_x, $space->fresh()->min_y, $space->fresh()->max_y,
        ]);
    }

    public function test_zero_candidates_on_60_complete_partial_chunks_and_add_left_capacity_atomically(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $space = $this->space($world->id);
        $wastelandId = TerrainDefinition::query()->where('key', 'wasteland')->valueOrFail('id');
        MapCell::query()->where('map_space_id', $space->id)->update(['terrain_definition_id' => $wastelandId]);
        $this->assertSame([], app(CapitalPlacementService::class)->candidates($space->fresh(), 1));

        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '60互換自動拡張国',
            '60互換島主',
        );
        $expanded = $space->fresh();

        $this->assertSame([-16, 63, 0, 63], [
            $expanded->min_x, $expanded->max_x, $expanded->min_y, $expanded->max_y,
        ]);
        $this->assertSame(5_120, $expanded->cells()->count());
        $this->assertSame(20, $expanded->chunks()->count());
        $this->assertLessThan(0, $nation->capital->x);
        $events = DB::table('audit_events')->where('event_type', 'world.expanded')->get();
        $this->assertCount(1, $events);
        $metadata = json_decode((string) $events->first()->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1_520, $metadata['added_cell_count']);
    }

    public function test_zero_candidates_after_one_chunk_fails_closed_and_rolls_back_expansion(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $space = app(WorldExpansionService::class)->expand(
            $world,
            new MapBounds(0, 59, 0, 59, 16),
            new MapBounds(0, 63, 0, 63, 16),
        );
        DB::table('audit_events')->whereIn('event_type', ['world.expanded', 'world.expanded_public'])->delete();
        $wastelandId = TerrainDefinition::query()->where('key', 'wasteland')->valueOrFail('id');
        MapCell::query()->where('map_space_id', $space->id)->update(['terrain_definition_id' => $wastelandId]);
        $this->app->bind(WorldExpansionService::class, fn (): WorldExpansionService => new class(app(ChunkCoordinateService::class), app(CurrentRulesetGuard::class), app(WorldMutationLock::class), app(MapSpaceCoveragePreflight::class)) extends WorldExpansionService
        {
            protected function afterCellsGenerated(MapSpace $mapSpace, int $inserted): void
            {
                MapCell::query()->where('map_space_id', $mapSpace->id)->where('x', '<', 0)->update([
                    'terrain_definition_id' => TerrainDefinition::query()->where('key', 'wasteland')->valueOrFail('id'),
                ]);
            }
        });

        try {
            app(NationCreationService::class)->create(
                User::factory()->create(),
                $world->fresh(),
                '閉鎖失敗国',
                '閉鎖島主',
            );
            $this->fail('Expected registration to fail after exactly one automatic expansion.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('1chunk', $exception->getMessage());
        }

        $space->refresh();
        $this->assertSame([0, 63, 0, 63], [$space->min_x, $space->max_x, $space->min_y, $space->max_y]);
        $this->assertSame(4_096, $space->cells()->count());
        $this->assertSame(16, $space->chunks()->count());
        $this->assertSame(0, Nation::query()->count());
        $this->assertSame(0, DB::table('nation_creation_requests')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded')->count());
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'world.expanded_public')->count());
    }

    /** @return list<array<string, mixed>> */
    private function rawCells(MapSpace $space, int $minimumX = -2_147_483_648, int $maximumX = 2_147_483_647): array
    {
        return $space->cells()->whereBetween('x', [$minimumX, $maximumX])->orderBy('id')->get()
            ->map(static fn (MapCell $cell): array => $cell->getRawOriginal())->all();
    }

    private function space(int $worldId): MapSpace
    {
        return MapSpace::query()->where('world_id', $worldId)->where('key', 'surface')->firstOrFail();
    }
}
