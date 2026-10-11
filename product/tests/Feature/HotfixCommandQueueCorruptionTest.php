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

    public function test_normal_queue_mutations_never_create_legacy_staged_positions(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('正常配置国');
        [$path, $ids] = $this->queuedPlans($owner, $nation, $mapSpace, 3);

        $this->putJson($path.'/reorder', [
            'placements' => [
                ['id' => $ids[2], 'position' => 3],
                ['id' => $ids[0], 'position' => 10],
                ['id' => $ids[1], 'position' => 30],
            ],
            'expected_version' => 4,
        ])->assertOk();

        $this->assertSame([3, 10, 30], $this->queuedPositions());
        $this->putJson($path.'/reorder', [
            'ordered_ids' => array_reverse($ids),
            'expected_version' => 5,
        ])->assertOk();
        $this->deleteJson($path.'/'.$ids[0], ['expected_version' => 6])
            ->assertOk()
            ->assertJsonPath('data.items.0.queue_position', 1)
            ->assertJsonPath('data.items.1.queue_position', 2);

        $this->assertSame([1, 2], $this->queuedPositions());
        $this->assertSame(0, NationCommandQueueItem::query()->where('status', 'queued')
            ->where('queue_position', '>', 1000)->count());
    }

    public function test_out_of_limit_position_is_compacted_but_not_automatically_discarded(): void
    {
        [$owner, $nation, $mapSpace] = $this->nation('上限外位置国');
        [$path, $ids, $target] = $this->queuedPlans($owner, $nation, $mapSpace, 2);
        NationCommandQueueItem::query()->whereKey($ids[1])->update(['queue_position' => 31]);

        $this->postJson($path, [
            'command_key' => 'land_level',
            'target_x' => $target->x,
            'target_y' => $target->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 3,
        ])->assertCreated();

        $this->assertSame('queued', NationCommandQueueItem::query()->findOrFail($ids[1])->status);
        $this->assertSame([1, 2, 3], $this->queuedPositions());
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
        return $this->queuedPlansWithCommands(
            $owner,
            $nation,
            $mapSpace,
            array_fill(0, $count, 'land_clear'),
        );
    }

    /**
     * @param  array<int, string>  $commandKeys
     * @return array{string, array<int, int>, MapCell}
     */
    private function queuedPlansWithCommands(
        User $owner,
        Nation $nation,
        MapSpace $mapSpace,
        array $commandKeys,
    ): array {
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $path = "/api/v1/nations/{$nation->id}/map-spaces/{$mapSpace->id}/command-queue";
        $ids = [];
        $this->actingAs($owner);
        foreach ($commandKeys as $index => $commandKey) {
            $ids[] = (int) $this->postJson($path, [
                'command_key' => $commandKey,
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
