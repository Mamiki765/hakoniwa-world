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
use Tests\TestCase;

final class RulesetV6MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const REVIEWED_QUEUE_REBIND_OVERRIDE_ENV = 'HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS';

    private const REVIEWED_QUEUE_REBIND_OVERRIDE_VALUE = 'CONFIRM_REVIEWED_V5_QUEUE_ITEMS_TO_V6';

    protected function tearDown(): void
    {
        putenv(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV);

        parent::tearDown();
    }

    public function test_v5_world_is_forward_migrated_idempotently_without_changing_queue_payload_or_turn_history(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v6移行国',
            '移行島主',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v5->id)
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
        $killStat->update(['kill_count' => 2, 'last_killed_turn' => 4, 'version' => 2]);
        $preservedItem = Arr::except($item->fresh()->getAttributes(), ['command_definition_id']);
        $preservedMonster = Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']);
        $preservedKillStat = Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']);
        $historicalRun = $this->turnRun($world, $v5, TurnRun::STATUS_COMPLETED, false);
        $historicalSnapshot = $historicalRun->fresh()->getAttributes();
        $frozen = RulesetVersion::query()->whereIn('key', [
            'hakoniwa-2s-plus-v1', 'hakoniwa-2s-plus-v2', 'hakoniwa-2s-plus-v3',
            'hakoniwa-2s-plus-v4', 'hakoniwa-2s-plus-v5',
        ])->orderBy('key')->pluck('settings', 'key')->all();

        $this->migration()->up();

        $this->assertSame($v6->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('finance', $item->fresh()->definition()->value('key'));
        $this->assertSame($preservedItem, Arr::except($item->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertSame($v6->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('inora', $monster->fresh()->definition()->value('key'));
        $this->assertSame($preservedMonster, Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v6->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('inora', $killStat->fresh()->definition()->value('key'));
        $this->assertSame($preservedKillStat, Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame('O', DB::table('pg_trigger')->where('tgname', 'nation_monster_kill_stat_guard')->value('tgenabled'));
        $this->assertSame($historicalSnapshot, $historicalRun->fresh()->getAttributes());
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
    public function test_unresolved_next_turn_fails_closed_without_partial_reference_changes(string $status): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "v6拒否{$status}国",
            '拒否島主',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id);
        $run = $this->turnRun($world, $v5, $status, false);
        $before = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes(), $run->fresh()->getAttributes()];

        try {
            $this->migration()->up();
            $this->fail("Expected {$status} next TurnRun to block v6 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($before, [$world->fresh()->getAttributes(), $item->fresh()->getAttributes(), $run->fresh()->getAttributes()]);
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

    #[DataProvider('behaviorChangingV5CommandKeys')]
    public function test_queued_v5_command_with_changed_v6_behavior_fails_closed(string $commandKey): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "v6 queue guard {$commandKey}",
            'migration owner',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id, $commandKey);
        $before = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()];

        try {
            $this->migration()->up();
            $this->fail("Expected queued v5 {$commandKey} to block v6 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($commandKey, $exception->getMessage());
            $this->assertStringContainsString('whose behavior changes in v6', $exception->getMessage());
            $this->assertStringContainsString('"nation_id":'.$nation->id, $exception->getMessage());
            $this->assertStringContainsString('"nation_name":"v6 queue guard '.$commandKey.'"', $exception->getMessage());
            $this->assertStringContainsString('"queue_position":1', $exception->getMessage());
            $this->assertStringContainsString('"command_key":"'.$commandKey.'"', $exception->getMessage());
            $this->assertStringContainsString('"target_x":7', $exception->getMessage());
            $this->assertStringContainsString('"target_y":8', $exception->getMessage());
            $this->assertStringContainsString(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV, $exception->getMessage());
        }

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($commandKey, $item->fresh()->definition()->value('key'));
        $this->assertSame($before, [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()]);
    }

    #[DataProvider('behaviorChangingV5CommandKeys')]
    public function test_reviewed_one_shot_override_rebinds_affected_v5_queue_item(string $commandKey): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            "v6 reviewed override {$commandKey}",
            'migration owner',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id, $commandKey);
        $before = Arr::except($item->fresh()->getAttributes(), ['command_definition_id']);
        putenv(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV.'='.self::REVIEWED_QUEUE_REBIND_OVERRIDE_VALUE);

        $this->migration()->up();

        $this->assertSame($v6->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($commandKey, $item->fresh()->definition()->value('key'));
        $this->assertSame($before, Arr::except($item->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertNotSame($v5->id, $item->fresh()->definition()->value('ruleset_version_id'));
    }

    public function test_non_exact_queue_override_value_does_not_bypass_guard(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v6 invalid override',
            'migration owner',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id, 'logging');
        $before = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()];
        putenv(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV.'=true');

        try {
            $this->migration()->up();
            $this->fail('Expected a non-exact v6 queue override value to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Affected queued items:', $exception->getMessage());
        }

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($before, [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()]);
    }

    public function test_reviewed_queue_override_does_not_bypass_unresolved_turn_run_guard(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v6 override turn guard',
            'migration owner',
        );
        [$v5, $v6, $item] = $this->moveWorldAndQueueToV5($world, $nation->id, 'logging');
        $run = $this->turnRun($world, $v5, TurnRun::STATUS_PENDING, false);
        $before = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes(), $run->fresh()->getAttributes()];
        putenv(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV.'='.self::REVIEWED_QUEUE_REBIND_OVERRIDE_VALUE);

        try {
            $this->migration()->up();
            $this->fail('Expected unresolved next TurnRun to block the reviewed queue override.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('status=pending', $exception->getMessage());
        }

        $this->assertSame($v5->id, $world->fresh()->ruleset_version_id);
        $this->assertNotSame($v6->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($before, [$world->fresh()->getAttributes(), $item->fresh()->getAttributes(), $run->fresh()->getAttributes()]);
    }

    public static function behaviorChangingV5CommandKeys(): array
    {
        return [
            'logging' => ['logging'],
            'defense overbuild' => ['build_defense_facility'],
            'monument overbuild' => ['build_monument'],
        ];
    }

    /** @return array{RulesetVersion, RulesetVersion, NationCommandQueueItem} */
    private function moveWorldAndQueueToV5(World $world, int $nationId, string $commandKey = 'finance'): array
    {
        $v5 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v5')->firstOrFail();
        $v6 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v6')->firstOrFail();
        $space = $this->surfaceMapSpace($world);
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nationId,
            'map_space_id' => $space->id,
            'version' => 1,
        ]);
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v5->id)
            ->where('key', $commandKey)->firstOrFail();
        $membershipId = NationMembership::query()->where('nation_id', $nationId)->valueOrFail('id');
        $item = DB::transaction(function () use ($world, $v5, $queue, $definition, $membershipId): NationCommandQueueItem {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v5->id]);
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

        return [$v5, $v6, $item];
    }

    private function turnRun(World $world, RulesetVersion $ruleset, string $status, bool $dryRun): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => str_repeat('6', 64),
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
        return require database_path('migrations/2026_08_16_000000_publish_hakoniwa_2s_plus_v6.php');
    }
}
