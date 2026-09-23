<?php

namespace Tests\Shared\Feature;

use App\Application\NationCreationService;
use App\Application\Ver440RulesetUpgrade;
use App\Models\MapCell;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class Ver440RulesetUpgradeTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_live_v26_world_keeps_queued_command_and_nation_when_activating_v27(): void
    {
        $source = RulesetVersion::query()->where('key', Ver440RulesetUpgrade::SOURCE_KEY)->sole();
        $target = RulesetVersion::query()->where('key', Ver440RulesetUpgrade::TARGET_KEY)->sole();
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v26.php')]);
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '移行検証島', '検証島主');
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)->firstOrFail();
        $queue = NationCommandQueue::query()->firstOrCreate([
            'nation_id' => $nation->id,
            'map_space_id' => $cell->map_space_id,
        ], ['version' => 1]);
        $queued = NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => DB::table('command_definitions')
                ->where('ruleset_version_id', $source->id)->where('key', 'land_clear')->value('id'),
            'request_ruleset_version_id' => $source->id,
            'queue_position' => 1,
            'target_context' => 'surface_cell',
            'target_x' => $cell->x,
            'target_y' => $cell->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => DB::table('nation_memberships')
                ->where('nation_id', $nation->id)->value('id'),
            'request_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', Str::uuid()->toString()),
            'queued_at' => now(),
            'failure_metadata' => [],
        ]);
        $beforePopulation = $nation->fresh()->population;

        $this->assertSame('production_v26_to_v27', app(Ver440RulesetUpgrade::class)->run());
        $this->assertSame($target->id, $world->fresh()->ruleset_version_id);
        $this->assertSame($beforePopulation, $nation->fresh()->population);
        $this->assertSame($target->id, $queued->fresh()->definition->ruleset_version_id);
        $this->assertSame('queued', $queued->fresh()->status);
    }
}
