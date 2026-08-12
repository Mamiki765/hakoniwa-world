<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\CommandDefinition;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RulesetV3MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_v2_live_references_are_stable_key_mapped_without_rewriting_history_or_queue_semantics(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v3移行国', '試験島主');
        $this->moveWorldToV2($world);
        $v2 = $world->rulesetVersion()->firstOrFail();
        $target = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $target->map_space_id, 'version' => 7],
        );
        $item = NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => CommandDefinition::query()
                ->where('ruleset_version_id', $v2->id)->where('key', 'land_clear')->value('id'),
            'queue_position' => 4,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 9,
            'parameters' => ['preserve' => 'all'],
            'status' => 'queued',
            'queued_by_membership_id' => NationMembership::query()->where('nation_id', $nation->id)->value('id'),
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => ['preserve' => true],
        ]);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v2->id)
            ->where('key', 'inora')->firstOrFail();
        $instances = collect(['alive', 'killed', 'removed'])->map(fn (string $state): MonsterInstance => $this->monster(
            $world,
            $monsterDefinition,
            $state,
        ));
        $stat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $monsterDefinition->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        foreach ([2, 3] as $turn) {
            $stat->update([
                'kill_count' => $stat->kill_count + 1,
                'last_killed_turn' => $turn,
                'version' => $stat->version + 1,
            ]);
        }
        $stat->refresh();
        $historical = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 1,
            'ruleset_version_id' => $v2->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1,
            'pipeline' => ['historical'],
            'phase_results' => ['preserve' => true],
            'failure_context' => [],
        ]);
        $itemSnapshot = Arr::except($item->fresh()->getAttributes(), ['command_definition_id']);
        $instanceSnapshots = $instances->mapWithKeys(static fn (MonsterInstance $instance): array => [
            $instance->id => Arr::except($instance->fresh()->getAttributes(), ['monster_definition_id']),
        ])->all();
        $statSnapshot = Arr::except($stat->fresh()->getAttributes(), ['monster_definition_id']);
        $historySnapshot = $historical->fresh()->getAttributes();

        $this->migration()->up();
        $v3 = $world->fresh()->rulesetVersion()->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v3', $v3->key);
        $this->assertSame('land_clear', $item->fresh()->definition()->value('key'));
        $this->assertSame($v3->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($itemSnapshot, Arr::except($item->fresh()->getAttributes(), ['command_definition_id']));
        foreach ($instances as $instance) {
            $this->assertSame($v3->id, $instance->fresh()->definition()->value('ruleset_version_id'));
            $this->assertSame(
                $instanceSnapshots[$instance->id],
                Arr::except($instance->fresh()->getAttributes(), ['monster_definition_id']),
            );
        }
        $this->assertSame($v3->id, $stat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($statSnapshot, Arr::except($stat->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($historySnapshot, $historical->fresh()->getAttributes());
        $this->assertSame('O', DB::table('pg_trigger')->where('tgname', 'nation_monster_kill_stat_guard')->value('tgenabled'));

        $after = [
            'world' => $world->fresh()->getAttributes(),
            'item' => $item->fresh()->getAttributes(),
            'instances' => $instances->map->fresh()->map->getAttributes()->all(),
            'stat' => $stat->fresh()->getAttributes(),
            'history' => $historical->fresh()->getAttributes(),
        ];
        $this->migration()->up();
        $this->assertSame($after['world'], $world->fresh()->getAttributes());
        $this->assertSame($after['item'], $item->fresh()->getAttributes());
        $this->assertSame($after['instances'], $instances->map->fresh()->map->getAttributes()->all());
        $this->assertSame($after['stat'], $stat->fresh()->getAttributes());
        $this->assertSame($after['history'], $historical->fresh()->getAttributes());
    }

    public function test_queued_v2_territory_expansion_is_stable_key_mapped_without_rewriting_the_queue_item(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v2領土拡張残存国', '移行前島主');
        $this->moveWorldToV2($world);
        $v2Id = $world->ruleset_version_id;
        $v2Definition = CommandDefinition::query()
            ->where('ruleset_version_id', $v2Id)->where('key', 'territory_expand')->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $item = NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $v2Definition->id,
            'queue_position' => 6,
            'target_x' => 8,
            'target_y' => 52,
            'quantity' => 3,
            'parameters' => ['preserve' => 'territory_expand'],
            'status' => 'queued',
            'queued_by_membership_id' => NationMembership::query()->where('nation_id', $nation->id)->value('id'),
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ]);
        $preserved = Arr::only($item->fresh()->getAttributes(), [
            'id',
            'nation_command_queue_id',
            'queue_position',
            'target_x',
            'target_y',
            'quantity',
            'parameters',
            'status',
            'queued_by_membership_id',
            'request_key',
            'queued_at',
        ]);

        $this->migration()->up();

        $v3 = $world->fresh()->rulesetVersion()->firstOrFail();
        $v3Definition = CommandDefinition::query()
            ->where('ruleset_version_id', $v3->id)->where('key', 'territory_expand')->firstOrFail();
        $migrated = $item->fresh();
        $this->assertSame('hakoniwa-2s-plus-v3', $v3->key);
        $this->assertNotSame($v2Definition->id, $v3Definition->id);
        $this->assertSame($v3Definition->id, $migrated->command_definition_id);
        $this->assertSame($preserved, Arr::only($migrated->getAttributes(), array_keys($preserved)));
    }

    public function test_production_v2_territory_queue_migrates_through_v3_then_message_board(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '連続移行島', '連続移行島主');
        $this->moveWorldToV2($world);
        $v2Definition = CommandDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'territory_expand')
            ->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 14,
        ]);
        $item = NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $v2Definition->id,
            'queue_position' => 9,
            'target_x' => 51,
            'target_y' => 8,
            'quantity' => 7,
            'parameters' => ['production_shape' => true],
            'status' => 'queued',
            'queued_by_membership_id' => NationMembership::query()->where('nation_id', $nation->id)->value('id'),
            'request_key' => (string) Str::uuid(),
            'queued_at' => now()->subMinutes(5),
            'failure_metadata' => ['preserve' => 'exactly'],
        ]);
        $preserved = Arr::except($item->fresh()->getAttributes(), ['command_definition_id']);
        $messageBoardMigration = $this->messageBoardMigration();
        $messageBoardMigration->down();
        $this->assertFalse(Schema::hasTable('island_messages'));

        $this->migration()->up();

        $v3 = $world->fresh()->rulesetVersion()->firstOrFail();
        $this->assertSame('hakoniwa-2s-plus-v3', $v3->key);
        $this->assertSame($v3->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('territory_expand', $item->fresh()->definition()->value('key'));
        $this->assertSame($preserved, Arr::except($item->fresh()->getAttributes(), ['command_definition_id']));

        $messageBoardMigration->up();
        $this->assertTrue(Schema::hasTable('island_messages'));
        $this->assertTrue(Schema::hasColumn('users', 'visitor_code'));
        $this->actingAs($user)->postJson("/api/v1/nations/{$nation->id}/message-board", [
            'body' => '連続移行後の伝言',
        ])->assertCreated()
            ->assertJsonPath('data.entries.0.body', '連続移行後の伝言');
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_v3_migration_rejects_unresolved_next_turn_without_partial_changes(string $status): void
    {
        $world = $this->lightweightWorld();
        $this->moveWorldToV2($world);
        $v2Id = $world->ruleset_version_id;
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v2Id,
            'random_seed' => str_repeat('b', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);

        try {
            $this->migration()->up();
            $this->fail('Expected unresolved next TurnRun to block v3 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }
        $this->assertSame($v2Id, $world->fresh()->ruleset_version_id);
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

    public function test_unknown_live_definition_reference_fails_closed(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '不整合国', '試験島主');
        $this->moveWorldToV2($world);
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $v1Definition = CommandDefinition::query()
            ->where('ruleset_version_id', RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->value('id'))
            ->where('key', 'territory_expand')->firstOrFail();
        DB::transaction(function () use ($queue, $v1Definition, $nation): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $v1Definition->id,
                'queue_position' => 1,
                'target_x' => 1,
                'target_y' => 1,
                'quantity' => 1,
                'parameters' => [],
                'status' => 'queued',
                'queued_by_membership_id' => NationMembership::query()->where('nation_id', $nation->id)->value('id'),
                'request_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
        });

        try {
            $this->migration()->up();
            $this->fail('Expected an unknown live ruleset reference to stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('before migration', $exception->getMessage());
        }
        $this->assertSame('hakoniwa-2s-plus-v2', $world->fresh()->rulesetVersion()->value('key'));
    }

    private function moveWorldToV2(World $world): void
    {
        $v2 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v2')->firstOrFail();
        DB::transaction(function () use ($world, $v2): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $items = NationCommandQueueItem::query()
                ->whereIn('nation_command_queue_id', NationCommandQueue::query()
                    ->whereIn('nation_id', DB::table('nations')->where('world_id', $world->id)->select('id'))
                    ->select('id'))
                ->with('definition')->get();
            foreach ($items as $item) {
                $definition = CommandDefinition::query()->where('ruleset_version_id', $v2->id)
                    ->where('key', $item->definition->key)->firstOrFail();
                $item->update(['command_definition_id' => $definition->id]);
            }
            $world->update(['ruleset_version_id' => $v2->id]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
        $world->refresh();
    }

    private function monster(World $world, MonsterDefinition $definition, string $state): MonsterInstance
    {
        $removed = $state !== 'alive';

        return MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $state === 'killed' ? 0 : 1,
            'spawned_max_hp' => 1,
            'state' => $state,
            'spawned_target_turn' => 1,
            'version' => 3,
            'removal_reason' => $removed ? "test_{$state}" : null,
            'removed_at' => $removed ? now()->subMinute() : null,
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_08_10_000000_publish_hakoniwa_2s_plus_v3.php');
    }

    private function messageBoardMigration(): object
    {
        return require database_path('migrations/2026_08_11_000000_create_island_messages.php');
    }
}
