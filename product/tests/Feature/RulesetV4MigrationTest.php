<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Map\MapCellStateService;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RulesetV4MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_v3_world_is_forward_migrated_with_seabed_experience_and_stable_live_references(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v4移行国', '移行島主');
        $seabed = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($nation->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $seabed,
            FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail(),
            experience: 0,
        );
        $seabed->facility_experience = null;
        $seabed->save();
        $facility = FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail();
        $facility->update(['metadata' => []]);
        $v3 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v3')->firstOrFail();
        $v4 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v4')->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $seabed->map_space_id,
            'version' => 1,
        ]);
        $v3Definition = CommandDefinition::query()->where('ruleset_version_id', $v3->id)
            ->where('key', 'finance')->firstOrFail();
        DB::transaction(function () use ($world, $v3, $queue, $v3Definition, $nation, $seabed): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            $world->update(['ruleset_version_id' => $v3->id]);
            NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $v3Definition->id,
                'queue_position' => 1,
                'target_x' => $seabed->x,
                'target_y' => $seabed->y,
                'quantity' => 1,
                'parameters' => [],
                'status' => 'queued',
                'queued_by_membership_id' => NationMembership::query()->where('nation_id', $nation->id)->value('id'),
                'request_key' => (string) Str::uuid(),
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
        $frozen = RulesetVersion::query()->whereIn('key', [
            'hakoniwa-2s-plus-v1', 'hakoniwa-2s-plus-v2', 'hakoniwa-2s-plus-v3',
        ])->orderBy('key')->pluck('settings', 'key')->all();

        $this->migration()->up();

        $this->assertSame($v4->id, $world->fresh()->ruleset_version_id);
        $this->assertSame(0, $seabed->fresh()->facility_experience);
        $this->assertSame([50, 200], $facility->fresh()->metadata['level_thresholds']);
        $item = NationCommandQueueItem::query()->where('nation_command_queue_id', $queue->id)->sole();
        $this->assertSame($v4->id, $item->definition()->value('ruleset_version_id'));
        $this->assertSame('finance', $item->definition()->value('key'));
        $this->assertEquals($frozen, RulesetVersion::query()->whereIn('key', array_keys($frozen))
            ->orderBy('key')->pluck('settings', 'key')->all());

        $seabed->update(['facility_experience' => 50]);
        $snapshot = [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()];
        $this->migration()->up();
        $this->assertSame(50, $seabed->fresh()->facility_experience);
        $this->assertSame($snapshot, [$world->fresh()->getAttributes(), $item->fresh()->getAttributes()]);
    }

    public function test_unexpected_pre_v4_seabed_experience_fails_closed_without_partial_migration(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'v4拒否国', '拒否島主');
        $seabed = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($nation->capital()->value('map_cell_id'))->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $seabed,
            FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail(),
            experience: 1,
        );
        $seabed->save();
        FacilityDefinition::query()->where('key', 'seabed_base')->update(['metadata' => []]);
        $v3 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v3')->firstOrFail();
        $world->update(['ruleset_version_id' => $v3->id]);

        try {
            $this->migration()->up();
            $this->fail('Expected unexpected seabed experience to block v4 migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('unexpected before v4 migration', $exception->getMessage());
        }

        $this->assertSame($v3->id, $world->fresh()->ruleset_version_id);
        $this->assertSame(1, $seabed->fresh()->facility_experience);
        $this->assertSame([], FacilityDefinition::query()->where('key', 'seabed_base')->value('metadata'));
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_unresolved_next_turn_blocks_v4_migration_without_partial_changes(string $status): void
    {
        $world = $this->lightweightWorld();
        $v3 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v3')->firstOrFail();
        $world->update(['ruleset_version_id' => $v3->id]);
        FacilityDefinition::query()->where('key', 'seabed_base')->update(['metadata' => []]);
        TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $v3->id,
            'random_seed' => str_repeat('4', 64),
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
            $this->fail("Expected {$status} next TurnRun to block v4 migration.");
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString("status={$status}", $exception->getMessage());
        }

        $this->assertSame($v3->id, $world->fresh()->ruleset_version_id);
        $this->assertSame([], FacilityDefinition::query()->where('key', 'seabed_base')->value('metadata'));
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

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_13_000000_publish_hakoniwa_2s_plus_v4.php');
    }
}
