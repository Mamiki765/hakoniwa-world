<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class HotfixCommandQueueCorruptionTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_cancelling_the_tail_keeps_the_unchanged_prefix_visible(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('末尾取消国');
        [$path, $ids] = $this->queuedPlans($owner, $nation, $mapSpace, 3);

        $this->deleteJson($path.'/'.$ids[2], ['expected_version' => 4])
            ->assertOk()
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.items.1.queue_position', 2)
            ->assertJsonPath('data.plan.0.kind', 'explicit')
            ->assertJsonPath('data.plan.1.kind', 'explicit');

        $this->assertSame([1, 2], $this->queuedPositions());
    }

    public function test_insertion_repairs_a_split_queue_without_colliding_with_hidden_items(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('挿入復旧国');
        [$path, $ids, $target] = $this->queuedPlans($owner, $nation, $mapSpace, 3);
        NationCommandQueueItem::query()->whereKey($ids[2])->update(['queue_position' => 1002]);

        $this->postJson($path, [
            'command_key' => 'land_clear',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'position' => 1,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.queue.items.0.queue_position', 1)
            ->assertJsonPath('data.queue.items.3.queue_position', 4)
            ->assertJsonPath('data.queue.explicit_count', 4);

        $this->assertSame([1, 2, 3, 4], $this->queuedPositions());
    }

    public function test_reposition_repairs_a_split_queue_without_a_unique_collision(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('配置復旧国');
        [$path, $ids] = $this->queuedPlans($owner, $nation, $mapSpace, 3);
        NationCommandQueueItem::query()->whereKey($ids[2])->update(['queue_position' => 1002]);

        $this->putJson($path.'/reorder', [
            'placements' => [
                ['id' => $ids[2], 'position' => 3],
                ['id' => $ids[0], 'position' => 10],
                ['id' => $ids[1], 'position' => 30],
            ],
            'expected_version' => 4,
        ])->assertOk();

        $this->assertSame([3, 10, 30], $this->queuedPositions());
    }

    public function test_reorder_repairs_a_split_queue_without_a_unique_collision(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('順序復旧国');
        [$path, $ids] = $this->queuedPlans($owner, $nation, $mapSpace, 3);
        NationCommandQueueItem::query()->whereKey($ids[2])->update(['queue_position' => 1002]);

        $this->putJson($path.'/reorder', [
            'ordered_ids' => array_reverse($ids),
            'expected_version' => 4,
        ])->assertOk();

        $this->assertSame(array_reverse($ids), NationCommandQueueItem::query()->where('status', 'queued')
            ->orderBy('queue_position')->pluck('id')->all());
        $this->assertSame([1, 2, 3], $this->queuedPositions());
    }

    public function test_cancellation_repairs_a_split_queue_without_a_unique_collision(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('取消復旧国');
        [$path, $ids] = $this->queuedPlans($owner, $nation, $mapSpace, 3);
        NationCommandQueueItem::query()->whereKey($ids[2])->update(['queue_position' => 1002]);

        $this->deleteJson($path.'/'.$ids[0], ['expected_version' => 4])
            ->assertOk()
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.items.1.queue_position', 2);

        $this->assertSame([1, 2], $this->queuedPositions());
    }

    /** @return array{User, Nation, MapSpace} */
    private function nation(string $name): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$user, $nation, MapSpace::query()->where('world_id', $world->id)->firstOrFail()];
    }

    /**
     * @return array{string, array<int, int>, MapCell}
     */
    private function queuedPlans(User $owner, Nation $nation, MapSpace $mapSpace, int $count): array
    {
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";
        $ids = [];
        $this->actingAs($owner);
        for ($index = 0; $index < $count; $index++) {
            $ids[] = (int) $this->postJson($path, [
                'command_key' => 'land_clear',
                'target_x' => $target->x,
                'target_y' => $target->y,
                'request_key' => (string) Str::uuid(),
                'expected_version' => $index + 1,
            ])->assertCreated()->json('data.item_id');
        }

        return [$path, $ids, $target];
    }

    /** @return array<int, int> */
    private function queuedPositions(): array
    {
        return NationCommandQueueItem::query()->where('status', 'queued')->orderBy('queue_position')
            ->pluck('queue_position')->all();
    }
}
