<?php

namespace Tests\Feature;

use App\Domain\Turn\TurnOrderService;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapCell;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class TurnOrderServiceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const SEED_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SEED_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function test_stable_inputs_and_labelled_shuffles_are_reproducible_and_seed_specific(): void
    {
        $world = $this->lightweightWorld();
        foreach (range(1, 10) as $index) {
            Nation::query()->create([
                'world_id' => $world->id, 'nation_number' => $index, 'name' => "Inserted {$index}",
            ]);
        }

        $orders = app(TurnOrderService::class);
        $stableCellIds = $orders->stableSurfaceCellIds($world);
        $abandoned = Nation::query()->where('world_id', $world->id)->orderBy('id')->firstOrFail();
        $abandoned->update(['state' => 'abandoned']);
        $stableNationIds = $orders->stableNationIds($world);
        $expectedNationIds = Nation::query()->where('world_id', $world->id)
            ->where('state', 'active')->orderBy('id')->pluck('id')->all();
        $expectedCellIds = MapCell::query()
            ->select('map_cells.id')
            ->join('map_spaces', 'map_spaces.id', '=', 'map_cells.map_space_id')
            ->where('map_spaces.world_id', $world->id)
            ->where('map_spaces.key', 'surface')
            ->orderBy('map_spaces.id')
            ->orderBy('map_cells.x')
            ->orderBy('map_cells.y')
            ->orderBy('map_cells.id')
            ->pluck('map_cells.id')
            ->all();

        $this->assertSame($expectedNationIds, $stableNationIds);
        $this->assertNotContains($abandoned->id, $stableNationIds);
        $this->assertSame($expectedCellIds, $stableCellIds);

        $firstAttempt = new TurnRandomStreamFactory(self::SEED_A);
        $retry = new TurnRandomStreamFactory(self::SEED_A);
        $differentTurn = new TurnRandomStreamFactory(self::SEED_B);
        $firstNationOrder = $orders->shuffledNationIds($world, $firstAttempt);
        $firstCellOrder = $orders->shuffledSurfaceCellIds($world, $firstAttempt);

        $this->assertSame($firstNationOrder, $orders->shuffledNationIds($world, $retry));
        $this->assertSame($firstCellOrder, $orders->shuffledSurfaceCellIds($world, $retry));
        $this->assertNotSame($firstNationOrder, $orders->shuffledNationIds($world, $differentTurn));
        $this->assertNotSame($firstCellOrder, $orders->shuffledSurfaceCellIds($world, $differentTurn));

        $sortedNationOrder = $firstNationOrder;
        $sortedCellOrder = $firstCellOrder;
        $sortedStableCellIds = $stableCellIds;
        sort($sortedNationOrder);
        sort($sortedCellOrder);
        sort($sortedStableCellIds);
        $this->assertSame($stableNationIds, $sortedNationOrder);
        $this->assertSame($sortedStableCellIds, $sortedCellOrder);
    }
}
