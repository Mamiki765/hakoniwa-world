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

final class RulesetV5MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    public function test_v4_world_is_forward_migrated_without_reinterpreting_queue_or_turn_history(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v5移行国',
            '移行島主',
        );
        [$v4, $v5, $item] = $this->moveWorldAndQueueToV4($world, $nation->id);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v4->id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $monsterDefinition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 3,
            'version' => 2,
        ]);
        $killStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $monsterDefinition->id,
            'kill_count' => 1,
            'first_killed_turn' => 2,
            'last_killed_turn' => 2,
            'version' => 1,
        ]);
        $preservedItem = Arr::except($item->fresh()->getAttributes(), ['command_definition_id']);
        $preservedMonster = Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']);
        $preservedKillStat = Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']);
        $historicalRun = $this->turnRun($world, $v4, TurnRun::STATUS_COMPLETED, false);
        $historicalSeed = $historicalRun->random_seed;
        $frozen = RulesetVersion::query()->whereIn('key', [
            'hakoniwa-2s-plus-v1',
            'hakoniwa-2s-plus-v2',
            'hakoniwa-2s-plus-v3',
            'hakoniwa-2s-plus-v4',
        ])->orderBy('key')->pluck('settings', 'key')->all();

        $this->migration()->up();

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v5->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('finance', $item->fresh()->definition()->value('key'));
        $this->assertSame($preservedItem, Arr::except($item->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertSame($v5->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('inora', $monster->fresh()->definition()->value('key'));
        $this->assertSame($preservedMonster, Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v5->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('inora', $killStat->fresh()->definition()->value('key'));
        $this->assertSame($preservedKillStat, Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame('O', DB::table('pg_trigger')->where('tgname', 'nation_monster_kill_stat_guard')->value('tgenabled'));
        $this->assertSame($v4->id, $historicalRun->fresh()->ruleset_version_id);
        $this->assertSame($historicalSeed, $historicalRun->fresh()->random_seed);
        $this->assertSame($frozen, RulesetVersion::query()->whereIn('key', array_keys($frozen))
            ->orderBy('key')->pluck('settings', 'key')->all());

        $snapshot = [
            $world->fresh()->getAttributes(),
            $item->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(),
            $killStat->fresh()->getAttributes(),
            $historicalRun->fresh()->getAttributes(),
        ];
        $this->migration()->up();
        $this->assertSame($snapshot, [
            $world->fresh()->getAttributes(),
            $item->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(),
            $killStat->fresh()->getAttributes(),
            $historicalRun->fresh()->getAttributes(),
        ]);
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_unresolved_next_turn_fails_closed_without_partial_live_reference_changes(string $status): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "v5拒否{$status}国",
            '拒否島主',
        );
        [$v4, $v5, $item] = $this->moveWorldAndQueueToV4($world, $nation->id);
        $run = $this->turnRun($world, $v4, $status, false);
        $before = [
            $world->fresh()->getAttributes(),
            $item->fresh()->getAttributes(),
            $run->fresh()->getAttributes(),
        ];

        try {
            $this->migration()->up();
            $this->fail("Expected {$status} next TurnRun to block v5 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($v4->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($v5->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($before, [
            $world->fresh()->getAttributes(),
            $item->fresh()->getAttributes(),
            $run->fresh()->getAttributes(),
        ]);
    }

    public function test_failed_dry_run_history_does_not_block_migration_or_change_its_snapshot(): void
    {
        $world = $this->lightweightWorld();
        $v4 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v4')->firstOrFail();
        $v5 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v5')->firstOrFail();
        $world->update(['ruleset_version_id' => $v4->id]);
        $run = $this->turnRun($world, $v4, TurnRun::STATUS_FAILED, true);
        $before = $run->fresh()->getAttributes();

        $this->migration()->up();

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($before, $run->fresh()->getAttributes());
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

    /** @return array{RulesetVersion, RulesetVersion, NationCommandQueueItem} */
    private function moveWorldAndQueueToV4(World $world, int $nationId): array
    {
        $v4 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v4')->firstOrFail();
        $v5 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v5')->firstOrFail();
        $space = $this->surfaceMapSpace($world);
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nationId,
            'map_space_id' => $space->id,
            'version' => 1,
        ]);
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v4->id)
            ->where('key', 'finance')->firstOrFail();
        $membershipId = NationMembership::query()->where('nation_id', $nationId)->valueOrFail('id');
        $item = DB::transaction(function () use ($world, $v4, $queue, $definition, $membershipId): NationCommandQueueItem {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v4->id]);
            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => 1,
                'target_x' => 7,
                'target_y' => 8,
                'quantity' => 9,
                'parameters' => ['preserve' => 'exactly'],
                'status' => 'queued',
                'queued_by_membership_id' => $membershipId,
                'request_key' => (string) Str::uuid(),
                'queued_at' => now()->subMinute(),
                'failure_metadata' => ['existing' => 'metadata'],
            ]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');

            return $item;
        });

        return [$v4, $v5, $item];
    }

    private function turnRun(World $world, RulesetVersion $ruleset, string $status, bool $dryRun): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => str_repeat('5', 64),
            'source' => 'manual',
            'is_dry_run' => $dryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_14_000000_publish_hakoniwa_2s_plus_v5.php');
    }
}
