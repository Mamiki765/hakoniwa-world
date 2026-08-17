<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\CommandDefinition;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\Nation;
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

final class RulesetV9MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_migration_preserves_history_and_rebinds_only_live_references_idempotently(): void
    {
        [$world, $queued, $v8, $v9, $nation] = $this->v8WorldWithQueuedCommand('pp_missile');
        $historical = $this->historicalQueueItems($queued, $v8);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v8->id)
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
        $killStat->update(['kill_count' => 2, 'last_killed_turn' => 2, 'version' => 2]);
        $queuedPayload = Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']);
        $monsterPayload = Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']);
        $killPayload = Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']);
        $historyBefore = collect($historical)->mapWithKeys(static fn (NationCommandQueueItem $item): array => [
            $item->id => $item->fresh()->getAttributes(),
        ])->all();
        $publishedBefore = $this->publishedV1ThroughV8Snapshots();

        $this->migration()->up();

        $this->assertSame($v9->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v9->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($queuedPayload, Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertSame($v9->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($monsterPayload, Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v9->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($killPayload, Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($historyBefore, collect($historical)->mapWithKeys(
            static fn (NationCommandQueueItem $item): array => [$item->id => $item->fresh()->getAttributes()],
        )->all());
        $this->assertSame($publishedBefore, $this->publishedV1ThroughV8Snapshots());
        $this->assertIntegrityGuardsEnabled();

        $successful = [$world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $monster->fresh()->getAttributes()];
        $this->migration()->up();
        $this->assertSame($successful, [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $monster->fresh()->getAttributes(),
        ]);
    }

    #[DataProvider('queuedMissileKeys')]
    public function test_existing_queued_missiles_rebind_directly_to_v9_new_ordering(string $commandKey): void
    {
        [$world, $queued, $v8, $v9] = $this->v8WorldWithQueuedCommand($commandKey);
        $queuedPayload = Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']);

        $this->assertSame($v8->id, $queued->fresh()->definition()->value('ruleset_version_id'));

        $this->migration()->up();

        $this->assertSame($v9->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v9->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($commandKey, $queued->fresh()->definition()->value('key'));
        $this->assertSame($queuedPayload, Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']));
    }

    /** @return iterable<string, array{string}> */
    public static function queuedMissileKeys(): iterable
    {
        yield 'ordinary missile' => ['missile'];
        yield 'PP missile' => ['pp_missile'];
        yield 'SPP missile' => ['spp_missile'];
        yield 'land destruction missile' => ['land_destruction_missile'];
    }

    public function test_unresolved_next_turn_run_blocks_with_queued_missile_and_preserves_state(): void
    {
        [$world, $queued, $v8] = $this->v8WorldWithQueuedCommand('missile');
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v8->id,
            'random_seed' => str_repeat('9', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
        $before = [$world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $run->fresh()->getAttributes()];

        try {
            $this->migration()->up();
            $this->fail('Expected unresolved TurnRun to block v9 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v9 migration', $exception->getMessage());
            $this->assertStringContainsString('status=pending', $exception->getMessage());
        }
        $this->assertSame($before, [
            $world->fresh()->getAttributes(), $queued->fresh()->getAttributes(), $run->fresh()->getAttributes(),
        ]);
        $this->assertIntegrityGuardsEnabled();
    }

    /** @return array{World, NationCommandQueueItem, RulesetVersion, RulesetVersion, Nation} */
    private function v8WorldWithQueuedCommand(string $commandKey): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v9移行島', 'v9移行島主');
        $v8 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v8')->firstOrFail();
        $v9 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v9')->firstOrFail();
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v8->id)
            ->where('key', $commandKey)->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');
        $item = DB::transaction(function () use ($world, $v8, $definition, $queue, $membershipId): NationCommandQueueItem {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v8->id]);
            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => 1,
                'target_x' => 8,
                'target_y' => 9,
                'quantity' => 1,
                'parameters' => ['preserve' => true],
                'status' => 'queued',
                'queued_by_membership_id' => $membershipId,
                'request_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');

            return $item;
        });

        return [$world, $item, $v8, $v9, $nation];
    }

    /** @return list<NationCommandQueueItem> */
    private function historicalQueueItems(NationCommandQueueItem $queued, RulesetVersion $v8): array
    {
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v8->id)
            ->where('key', 'build_farm')->firstOrFail();
        $items = [];
        foreach (['completed', 'failed', 'cancelled'] as $index => $status) {
            $items[] = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queued->nation_command_queue_id,
                'command_definition_id' => $definition->id,
                'queue_position' => null,
                'target_x' => 10 + $index,
                'target_y' => 11 + $index,
                'quantity' => 2,
                'parameters' => ['preserve' => $status],
                'status' => $status,
                'queued_by_membership_id' => $queued->queued_by_membership_id,
                'request_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'failure_code' => $status === 'failed' ? 'preserved_failure' : null,
                'failure_metadata' => ['preserve' => $status],
            ]);
        }

        return $items;
    }

    /** @return array<string, array{settings: mixed, checksum: string}> */
    private function publishedV1ThroughV8Snapshots(): array
    {
        return RulesetVersion::query()->whereIn('key', array_map(
            static fn (int $version): string => "hakoniwa-2s-plus-v{$version}", range(1, 8),
        ))->orderBy('key')->get()->mapWithKeys(static function (RulesetVersion $ruleset): array {
            $settings = $ruleset->getRawOriginal('settings');

            return [$ruleset->key => ['settings' => $settings, 'checksum' => hash('sha256', (string) $settings)]];
        })->all();
    }

    private function assertIntegrityGuardsEnabled(): void
    {
        foreach (['nation_command_queue_items_world_ruleset_match', 'nation_monster_kill_stat_guard'] as $trigger) {
            $this->assertSame('O', DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled'), $trigger);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_17_000000_publish_hakoniwa_2s_plus_v9.php');
    }
}
