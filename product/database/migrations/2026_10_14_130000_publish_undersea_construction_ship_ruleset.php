<?php

use App\Application\NextProductionTurnRunGuard;
use App\Application\RulesetPublisher;
use App\Domain\World\WorldMutationLock;
use App\Models\World;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $previous = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php');
        $next = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v37.php');
        $publisher = app(RulesetPublisher::class);
        $worlds = World::query()->whereHas('rulesetVersion', fn ($query) => $query->where('key', $previous['key']))
            ->orderBy('id')->get();
        $lock = app(WorldMutationLock::class);
        $locked = [];
        try {
            foreach ($worlds as $world) {
                $lock->acquire($world);
                $locked[] = $world;
            }
            // Keep the session World locks until this complete transaction commits or rolls back.
            DB::transaction(function () use ($worlds, $previous, $publisher, $next): void {
                foreach ($worlds as $world) {
                    $current = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                    if ($current->rulesetVersion->key !== $previous['key']) {
                        throw new RuntimeException('The World Ruleset changed before the reviewed upgrade acquired its lock.');
                    }
                    app(NextProductionTurnRunGuard::class)->assertClear($current);
                }
                if ($worlds->isNotEmpty()) {
                    $publisher->assertPublishedSnapshot($previous);
                }
                $published = $publisher->publish($next);
                DB::unprepared(file_get_contents(database_path('sql/5_2_0_surface_ship_guards.sql')));
                foreach ($worlds as $world) {
                    $world->update(['ruleset_version_id' => $published->id]);
                    // Keep original request identity, fingerprints, historical commands and Ship provenance.
                    DB::statement(<<<'SQL'
UPDATE nation_command_queue_items item
SET command_definition_id = replacement.id
FROM nation_command_queues queue, nations nation,
     command_definitions original, command_definitions replacement
WHERE item.nation_command_queue_id = queue.id
  AND queue.nation_id = nation.id AND nation.world_id = ?
  AND item.status = 'queued' AND item.target_context = 'surface_cell'
  AND original.id = item.command_definition_id
  AND replacement.ruleset_version_id = ? AND replacement.key = original.key
SQL, [$world->id, $published->id]);
                }
            });
        } finally {
            foreach (array_reverse($locked) as $world) {
                $lock->release($world);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Published Rulesets and Ship data are forward-only. Restore a reviewed backup instead.');
    }
};
