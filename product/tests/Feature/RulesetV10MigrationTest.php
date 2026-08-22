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
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

final class RulesetV10MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_v10_migration_rebinds_only_live_references_and_is_idempotent(): void
    {
        [$world, $queued, $historical, $monster, $killStat, $v9, $v10] = $this->v9Fixture();
        $historicalMonsters = collect([
            MonsterInstance::query()->create([
                'world_id' => $world->id,
                'monster_definition_id' => $monster->monster_definition_id,
                'current_hp' => 0,
                'spawned_max_hp' => 1,
                'state' => 'killed',
                'spawned_target_turn' => 1,
                'version' => 2,
                'removal_reason' => 'migration-history-test-killed',
                'removed_at' => now(),
            ]),
            MonsterInstance::query()->create([
                'world_id' => $world->id,
                'monster_definition_id' => $monster->monster_definition_id,
                'current_hp' => 1,
                'spawned_max_hp' => 1,
                'state' => 'removed',
                'spawned_target_turn' => 1,
                'version' => 2,
                'removal_reason' => 'migration-history-test-removed',
                'removed_at' => now(),
            ]),
        ]);
        $historicalMonsterPayloads = $historicalMonsters->map->fresh()->map->getAttributes()->all();
        $queuedPayload = Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']);
        $monsterPayload = Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']);
        $killPayload = Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']);
        $historyPayload = $historical->fresh()->getAttributes();
        $publishedBefore = $this->publishedV1ThroughV9Checksums();

        $this->migration()->up();

        $this->assertSame($v10->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v10->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($queuedPayload, Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertSame($v10->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($monsterPayload, Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v10->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($killPayload, Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v9->id, $historical->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($historyPayload, $historical->fresh()->getAttributes());
        foreach ($historicalMonsters as $index => $historicalMonster) {
            $this->assertSame($v9->id, $historicalMonster->fresh()->definition()->value('ruleset_version_id'));
            $this->assertSame($historicalMonsterPayloads[$index], $historicalMonster->fresh()->getAttributes());
        }
        $this->assertSame($publishedBefore, $this->publishedV1ThroughV9Checksums());
        $this->assertIntegrityGuardsEnabled();

        $successful = [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $monster->fresh()->getAttributes(),
            ...$historicalMonsters->map->fresh()->map->getAttributes()->all(),
        ];
        $this->migration()->up();
        $this->assertSame($successful, [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $monster->fresh()->getAttributes(),
            ...$historicalMonsters->map->fresh()->map->getAttributes()->all(),
        ]);
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_unresolved_next_turn_run_blocks_without_partial_rebinding(string $status): void
    {
        [$world, $queued, , $monster, $killStat, $v9] = $this->v9Fixture();
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v9->id,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
        $before = [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(), $killStat->fresh()->getAttributes(), $run->fresh()->getAttributes(),
        ];

        try {
            $this->migration()->up();
            $this->fail("Expected {$status} TurnRun to block v10 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v10 migration', $exception->getMessage());
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($before, [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(), $killStat->fresh()->getAttributes(), $run->fresh()->getAttributes(),
        ]);
        $this->assertIntegrityGuardsEnabled();
    }

    public function test_unresolved_turn_run_rolls_back_a_new_v10_publication(): void
    {
        [$world, , , , , $v9, $v10] = $this->v9Fixture();
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v9->id,
            'random_seed' => str_repeat('b', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        CommandDefinition::query()->where('ruleset_version_id', $v10->id)->delete();
        DB::table('production_definitions')->where('ruleset_version_id', $v10->id)->delete();
        MonsterDefinition::query()->where('ruleset_version_id', $v10->id)->delete();
        $v10->delete();
        $this->assertDatabaseMissing('ruleset_versions', ['key' => 'hakoniwa-2s-plus-v10']);

        try {
            $this->migration()->up();
            $this->fail('Expected the pending TurnRun to roll back v10 publication.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v10 migration', $exception->getMessage());
        }

        $this->assertDatabaseMissing('ruleset_versions', ['key' => 'hakoniwa-2s-plus-v10']);
        $this->assertSame($v9->id, $world->fresh()->ruleset_version_id);
        $this->assertIntegrityGuardsEnabled();
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

    /** @return array{World, NationCommandQueueItem, NationCommandQueueItem, MonsterInstance, NationMonsterKillStat, RulesetVersion, RulesetVersion} */
    private function v9Fixture(): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v10移行島', '移行島主');
        $v9 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v9')->firstOrFail();
        $v10 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v10')->firstOrFail();
        $world->update(['ruleset_version_id' => $v9->id]);
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');
        $queued = $this->queueItem($queue, $membershipId, $v9, 'pp_missile', 'queued', 1);
        $historical = $this->queueItem($queue, $membershipId, $v9, 'build_farm', 'completed', null);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v9->id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $monsterDefinition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 2,
            'version' => 1,
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $monsterDefinition->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);

        return [$world, $queued, $historical, $monster, $killStat, $v9, $v10];
    }

    private function queueItem(
        NationCommandQueue $queue,
        int $membershipId,
        RulesetVersion $ruleset,
        string $key,
        string $status,
        ?int $position,
    ): NationCommandQueueItem {
        $definition = CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => $position,
            'target_x' => 8,
            'target_y' => 9,
            'quantity' => 1,
            'parameters' => ['preserve' => $status],
            'status' => $status,
            'queued_by_membership_id' => $membershipId,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => ['preserve' => $status],
        ]);
    }

    /** @return array<string, string> */
    private function publishedV1ThroughV9Checksums(): array
    {
        return RulesetVersion::query()->whereIn('key', array_map(
            static fn (int $version): string => "hakoniwa-2s-plus-v{$version}", range(1, 9),
        ))->orderBy('key')->get()->mapWithKeys(static fn (RulesetVersion $ruleset): array => [
            $ruleset->key => hash('sha256', (string) $ruleset->getRawOriginal('settings')),
        ])->all();
    }

    private function assertIntegrityGuardsEnabled(): void
    {
        foreach (['nation_command_queue_items_world_ruleset_match', 'nation_monster_kill_stat_guard'] as $trigger) {
            $this->assertSame('O', DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled'), $trigger);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_19_010000_publish_hakoniwa_2s_plus_v10.php');
    }
}
