<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RulesetV7MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_only_queued_live_items_are_rebound_and_all_historical_statuses_keep_v6_definitions(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v7移行島', 'v7移行島主');
        $v6 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v6')->firstOrFail();
        $v7 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v7')->firstOrFail();
        $definition = CommandDefinition::query()->where('ruleset_version_id', $v6->id)
            ->where('key', 'build_farm')->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $this->surfaceMapSpace($world)->id,
            'version' => 1,
        ]);
        $membershipId = NationMembership::query()->where('nation_id', $nation->id)->valueOrFail('id');

        $items = DB::transaction(function () use ($world, $v6, $definition, $queue, $membershipId): array {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v6->id]);
            $result = [];
            foreach (['queued', 'completed', 'failed', 'cancelled'] as $index => $status) {
                $result[$status] = NationCommandQueueItem::query()->create([
                    'nation_command_queue_id' => $queue->id,
                    'command_definition_id' => $definition->id,
                    'queue_position' => $status === 'queued' ? 1 : null,
                    'target_x' => 8,
                    'target_y' => 9,
                    'quantity' => 25,
                    'parameters' => ['preserve' => $status],
                    'status' => $status,
                    'queued_by_membership_id' => $membershipId,
                    'request_key' => (string) Str::uuid(),
                    'queued_at' => now()->subMinutes(4 - $index),
                    'execution_started_at' => in_array($status, ['completed', 'failed'], true) ? now()->subMinute() : null,
                    'execution_completed_at' => in_array($status, ['completed', 'failed'], true) ? now() : null,
                    'cancelled_at' => $status === 'cancelled' ? now() : null,
                    'failure_code' => $status === 'failed' ? 'invalid_terrain' : null,
                    'failure_metadata' => ['preserve' => $status],
                ]);
            }
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');

            return $result;
        });
        $beforeHistorical = collect($items)->except('queued')
            ->map(fn (NationCommandQueueItem $item): array => $item->fresh()->getAttributes())->all();

        $this->migration()->up();
        $this->migration()->up();

        $this->assertSame($v7->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($v7->id, $items['queued']->fresh()->definition()->value('ruleset_version_id'));
        $this->assertSame('build_farm', $items['queued']->fresh()->definition()->value('key'));
        $this->assertSame(25, $items['queued']->fresh()->quantity);
        $this->assertSame(['preserve' => 'queued'], $items['queued']->fresh()->parameters);
        foreach (['completed', 'failed', 'cancelled'] as $status) {
            $item = $items[$status]->fresh();
            $this->assertSame($v6->id, $item->definition()->value('ruleset_version_id'), $status);
            $this->assertSame($beforeHistorical[$status], $item->getAttributes(), $status);
        }
    }

    public function test_v7_migration_rechecks_the_turn_guard_after_secretary_migration_succeeds(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, 'v7再検証島', 'v7再検証島主');
        $v6 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v6')->firstOrFail();
        $world->update(['ruleset_version_id' => $v6->id]);
        Schema::drop('secretary_item_instances');
        Schema::drop('secretary_skills');
        Schema::drop('secretaries');
        $this->secretaryMigration()->up();
        $this->assertDatabaseHas('secretaries', ['user_id' => $user->id]);
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v6->id,
            'random_seed' => str_repeat('7', 64),
            'source' => 'cron',
            'is_dry_run' => false,
            'status' => TurnRun::STATUS_PENDING,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => ['preserve' => true],
        ]);
        $runBefore = $run->fresh()->getAttributes();

        try {
            $this->migration()->up();
            $this->fail('Expected 030000 to recheck the unresolved next TurnRun guard.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Refusing v7 migration', $exception->getMessage());
            $this->assertStringContainsString('status=pending', $exception->getMessage());
        }

        $this->assertSame($v6->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($runBefore, $run->fresh()->getAttributes());
        $this->assertDatabaseHas('secretaries', ['user_id' => $user->id]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_16_030000_publish_hakoniwa_2s_plus_v7.php');
    }

    private function secretaryMigration(): Migration
    {
        return require database_path('migrations/2026_08_16_020000_create_secretary_system.php');
    }
}
