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
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Concerns\UsesHistoricalRulesetDatabaseFixtures;
use Tests\TestCase;

final class RulesetV8MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;
    use UsesHistoricalRulesetDatabaseFixtures;

    private const REVIEWED_MISSILE_REBIND_ENV = 'HAKONIWA_V8_REBIND_REVIEWED_MISSILE_ITEMS';

    private const REVIEWED_MISSILE_REBIND_VALUE = 'CONFIRM_REVIEWED_V7_MISSILES_TO_V8';

    protected function tearDown(): void
    {
        putenv(self::REVIEWED_MISSILE_REBIND_ENV);

        parent::tearDown();
    }

    public function test_non_missile_queue_is_rebound_without_changing_payload(): void
    {
        [$world, $item, $v7, $v8] = $this->v7WorldWithQueuedCommand('build_farm');
        $before = $item->fresh()->only(['target_x', 'target_y', 'quantity', 'parameters', 'status', 'request_key']);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($v8->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v8->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('build_farm', $item->fresh()->definition()->value('key'));
        $this->assertSame($before, $item->fresh()->only(array_keys($before)));
        $this->assertNotSame($v7->id, $item->fresh()->definition()->value('ruleset_version_id'));
    }

    public function test_queued_missile_fails_closed_without_reviewed_rebind_confirmation(): void
    {
        [$world, $item, $v7] = $this->v7WorldWithQueuedCommand('pp_missile');

        try {
            $this->migration()->up();
            $this->fail('Expected queued missile semantics to require explicit review.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changes missile interception', $exception->getMessage());
            $this->assertStringContainsString('HAKONIWA_V8_REBIND_REVIEWED_MISSILE_ITEMS', $exception->getMessage());
        }

        $this->assertSame($v7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v7->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('queued', $item->fresh()->status);
    }

    public function test_failed_missile_guard_preserves_full_v7_state_and_reviewed_retry_rebinds_only_live_references(): void
    {
        [$world, $queued, $v7, $v8, $nation] = $this->v7WorldWithQueuedCommand('pp_missile');
        $historicalItems = $this->historicalQueueItems($queued, $v7);
        $monsterDefinition = MonsterDefinition::query()->where('ruleset_version_id', $v7->id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $monsterDefinition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 2,
            'version' => 3,
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
        $killStatPayload = Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']);
        $historicalSnapshots = collect($historicalItems)
            ->mapWithKeys(static fn (NationCommandQueueItem $item): array => [
                $item->status => $item->fresh()->getAttributes(),
            ])->all();
        $publishedSnapshots = $this->publishedProductionSnapshots();

        try {
            $this->migration()->up();
            $this->fail('Expected the queued v7 missile guard to fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changes missile interception', $exception->getMessage());
        }

        $this->assertSame($v7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v7->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v7->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v7->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($historicalSnapshots, collect($historicalItems)
            ->mapWithKeys(static fn (NationCommandQueueItem $item): array => [
                $item->status => $item->fresh()->getAttributes(),
            ])->all());
        $this->assertIntegrityGuardsEnabled();
        $this->assertSame($publishedSnapshots, $this->publishedProductionSnapshots());

        putenv(self::REVIEWED_MISSILE_REBIND_ENV.'='.self::REVIEWED_MISSILE_REBIND_VALUE);
        $this->migration()->up();

        $this->assertSame($v8->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v8->id, $queued->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('pp_missile', $queued->fresh()->definition()->value('key'));
        $this->assertSame($queuedPayload, Arr::except($queued->fresh()->getAttributes(), ['command_definition_id']));
        $this->assertSame($v8->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($monsterPayload, Arr::except($monster->fresh()->getAttributes(), ['monster_definition_id']));
        $this->assertSame($v8->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($killStatPayload, Arr::except($killStat->fresh()->getAttributes(), ['monster_definition_id']));
        foreach ($historicalItems as $item) {
            $this->assertSame($v7->id, $item->fresh()->definition()->value('ruleset_version_id'));
            $this->assertSame($historicalSnapshots[$item->status], $item->fresh()->getAttributes());
        }
        $this->assertIntegrityGuardsEnabled();
        $this->assertSame($publishedSnapshots, $this->publishedProductionSnapshots());

        $successfulSnapshot = [
            $world->fresh()->getAttributes(),
            $queued->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(),
            $killStat->fresh()->getAttributes(),
        ];
        $this->migration()->up();
        $this->assertSame($successfulSnapshot, [
            $world->fresh()->getAttributes(),
            $queued->fresh()->getAttributes(),
            $monster->fresh()->getAttributes(),
            $killStat->fresh()->getAttributes(),
        ]);
    }

    public function test_review_confirmation_does_not_bypass_unresolved_turn_run_guard(): void
    {
        [$world, $item, $v7] = $this->v7WorldWithQueuedCommand('spp_missile');
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v7->id,
            'random_seed' => str_repeat('8', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
        $before = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes(), $run->fresh()->getAttributes()];
        putenv(self::REVIEWED_MISSILE_REBIND_ENV.'='.self::REVIEWED_MISSILE_REBIND_VALUE);

        try {
            $this->migration()->up();
            $this->fail('Expected the unresolved TurnRun to block v8 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v8 migration', $exception->getMessage());
            $this->assertStringContainsString('status=pending', $exception->getMessage());
        }

        $this->assertSame($before, [
            $world->fresh()->getAttributes(),
            $item->fresh()->getAttributes(),
            $run->fresh()->getAttributes(),
        ]);
        $this->assertIntegrityGuardsEnabled();
    }

    public function test_v6_to_v7_to_v8_chain_exposes_a_consistent_v7_checkpoint(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(), $world, '段階移行島', '段階移行島主',
        );
        $v6 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v6')->firstOrFail();
        $v7 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v7')->firstOrFail();
        $v8 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v8')->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');
        $v6Command = CommandDefinition::query()->where('ruleset_version_id', $v6->id)
            ->where('key', 'build_farm')->firstOrFail();
        $v6Monster = MonsterDefinition::query()->where('ruleset_version_id', $v6->id)
            ->where('key', 'inora')->firstOrFail();
        [$item, $monster, $killStat] = DB::transaction(function () use (
            $world, $nation, $v6, $queue, $membershipId, $v6Command, $v6Monster,
        ): array {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v6->id]);
            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $v6Command->id,
                'queue_position' => 1,
                'target_x' => 8,
                'target_y' => 9,
                'quantity' => 3,
                'parameters' => ['chain' => true],
                'status' => 'queued',
                'queued_by_membership_id' => $membershipId,
                'request_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            $monster = MonsterInstance::query()->create([
                'world_id' => $world->id,
                'monster_definition_id' => $v6Monster->id,
                'current_hp' => 1,
                'spawned_max_hp' => 1,
                'state' => 'alive',
                'spawned_target_turn' => 1,
                'version' => 1,
            ]);
            $killStat = NationMonsterKillStat::query()->create([
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                'monster_definition_id' => $v6Monster->id,
                'kill_count' => 1,
                'first_killed_turn' => 1,
                'last_killed_turn' => 1,
                'version' => 1,
            ]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');

            return [$item, $monster, $killStat];
        });

        $this->secretaryMigration()->up();
        $this->v7Migration()->up();

        $this->assertSame($v7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v7->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v7->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v7->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertIntegrityGuardsEnabled();

        $this->migration()->up();

        $this->assertSame($v8->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v8->id, $item->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v8->id, $monster->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame($v8->id, $killStat->fresh()->definition()->value('ruleset_version_id'));
        $this->assertIntegrityGuardsEnabled();
    }

    /** @return array{World, NationCommandQueueItem, RulesetVersion, RulesetVersion, Nation} */
    private function v7WorldWithQueuedCommand(string $commandKey): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v8移行島', 'v8移行島主');
        $v7 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v7')->firstOrFail();
        $v8 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v8')->firstOrFail();
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v7->id)
            ->where('key', $commandKey)->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');
        $item = DB::transaction(function () use ($world, $v7, $definition, $queue, $membershipId): NationCommandQueueItem {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v7->id]);
            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => 1,
                'target_x' => 8,
                'target_y' => 9,
                'quantity' => 3,
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

        return [$world, $item, $v7, $v8, $nation];
    }

    /** @return list<NationCommandQueueItem> */
    private function historicalQueueItems(NationCommandQueueItem $queued, RulesetVersion $v7): array
    {
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v7->id)
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
                'queued_at' => now()->subMinutes(3 - $index),
                'execution_started_at' => in_array($status, ['completed', 'failed'], true) ? now()->subMinute() : null,
                'execution_completed_at' => in_array($status, ['completed', 'failed'], true) ? now() : null,
                'cancelled_at' => $status === 'cancelled' ? now() : null,
                'failure_code' => $status === 'failed' ? 'preserved_failure' : null,
                'failure_metadata' => ['preserve' => $status],
            ]);
        }

        return $items;
    }

    /** @return array<string, array{settings: mixed, checksum: string}> */
    private function publishedProductionSnapshots(): array
    {
        return RulesetVersion::query()->whereIn('key', array_map(
            static fn (int $version): string => "hakoniwa-2s-plus-v{$version}",
            range(1, 8),
        ))->orderBy('key')->get()->mapWithKeys(static function (RulesetVersion $ruleset): array {
            $settings = $ruleset->getRawOriginal('settings');

            return [$ruleset->key => [
                'settings' => $settings,
                'checksum' => hash('sha256', (string) $settings),
            ]];
        })->all();
    }

    private function assertIntegrityGuardsEnabled(): void
    {
        foreach ([
            'nation_command_queue_items_world_ruleset_match',
            'nation_monster_kill_stat_guard',
        ] as $trigger) {
            $this->assertSame('O', DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled'), $trigger);
        }
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_16_040000_publish_hakoniwa_2s_plus_v8.php');
    }

    private function secretaryMigration(): Migration
    {
        return require database_path('migrations/2026_08_16_020000_create_secretary_system.php');
    }

    private function v7Migration(): Migration
    {
        return require database_path('migrations/2026_08_16_030000_publish_hakoniwa_2s_plus_v7.php');
    }
}
