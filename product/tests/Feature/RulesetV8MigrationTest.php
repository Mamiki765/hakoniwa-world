<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RulesetV8MigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

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

    /** @return array{World, NationCommandQueueItem, RulesetVersion, RulesetVersion} */
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

        return [$world, $item, $v7, $v8];
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_16_040000_publish_hakoniwa_2s_plus_v8.php');
    }
}
