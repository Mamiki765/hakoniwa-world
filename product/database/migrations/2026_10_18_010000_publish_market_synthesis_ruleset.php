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
        $previous = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v39.php');
        $next = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v40.php');
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
                foreach ($worlds as $world) {
                    // Existing queue and Monster rows retain their source identities and provenance.
                    $world->update(['ruleset_version_id' => $published->id]);
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
        throw new RuntimeException('Published Rulesets are forward-only. Restore a reviewed backup instead.');
    }
};
