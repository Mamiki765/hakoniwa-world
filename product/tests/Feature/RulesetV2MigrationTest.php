<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

final class RulesetV2MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_v1_world_and_every_queue_semantic_are_forward_migrated_by_command_key(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v2移行元国', '試験島主');
        $targetUser = User::factory()->create();
        $targetNation = app(NationCreationService::class)->create($targetUser, $world, 'v2援助先国', '試験島主');
        $space = MapSpace::query()->where('world_id', $world->id)->where('key', 'surface')->firstOrFail();
        $plain = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();
        $service = app(CommandQueueService::class);
        $first = $service->add(
            $user, $nation, $space, 'build_farm', $plain->x, $plain->y,
            '10000000-0000-4000-8000-000000000001', 1, 5, [], 1,
        )['item'];
        $second = $service->add(
            $user, $nation, $space, 'money_aid', null, null,
            '10000000-0000-4000-8000-000000000002', 2, 2,
            ['target_nation_id' => $targetNation->id], 2,
        )['item'];
        $third = $service->add(
            $user, $nation, $space, 'land_clear', $plain->x, $plain->y,
            '10000000-0000-4000-8000-000000000003', 3, 1, [], 3,
        )['item'];
        $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->firstOrFail();
        DB::transaction(function () use ($first, $second, $third, $queue): void {
            $first->update(['queue_position' => null]);
            $second->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
            $third->update(['queue_position' => 2]);
            $first->update(['queue_position' => 1001]);
            $queue->update(['version' => 17]);
        });
        $this->moveWorldToV1($world);
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('d', 64),
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $before = $this->queueSnapshot($queue);

        $this->migration()->up();

        $v2 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v2')->firstOrFail();
        $this->assertSame($v2->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($before, $this->queueSnapshot($queue));
        $this->assertSame([1001, null, 2], collect($before['items'])->pluck('queue_position')->all());
        $this->assertSame(['queued', 'cancelled', 'queued'], collect($before['items'])->pluck('status')->all());

        $this->migration()->up();
        $this->assertSame($before, $this->queueSnapshot($queue));
        $this->assertSame($v2->id, $world->fresh()->ruleset_version_id);
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_v2_migration_rejects_an_unresolved_next_turn_without_partial_repoint(string $status): void
    {
        $world = $this->lightweightWorld();
        $this->moveWorldToV1($world);
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('e', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $v1 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();

        try {
            $this->migration()->up();
            $this->fail('Expected the unresolved next TurnRun to block the v2 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($v1->id, $world->fresh()->ruleset_version_id);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    private function moveWorldToV1(World $world): void
    {
        $v1 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        DB::transaction(function () use ($world, $v1): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $items = NationCommandQueueItem::query()
                ->whereIn('nation_command_queue_id', NationCommandQueue::query()
                    ->whereIn('nation_id', DB::table('nations')->where('world_id', $world->id)->select('id'))
                    ->select('id'))
                ->with('definition')->get();
            foreach ($items as $item) {
                $v1Definition = CommandDefinition::query()->where('ruleset_version_id', $v1->id)
                    ->where('key', $item->definition->key)->firstOrFail();
                $item->update(['command_definition_id' => $v1Definition->id]);
            }
            $world->update(['ruleset_version_id' => $v1->id]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
        $world->refresh();
    }

    /** @return array<string, mixed> */
    private function queueSnapshot(NationCommandQueue $queue): array
    {
        $queue->refresh();
        $items = NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)
            ->with('definition')->orderBy('id')->get()->map(static function (NationCommandQueueItem $item): array {
                return [
                    ...Arr::except($item->getAttributes(), ['command_definition_id', 'created_at', 'updated_at']),
                    'command_key' => $item->definition->key,
                ];
            })->all();

        return [
            'queue' => Arr::except($queue->getAttributes(), ['created_at', 'updated_at']),
            'items' => $items,
        ];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_09_000000_publish_hakoniwa_2s_plus_v2.php');
    }
}
