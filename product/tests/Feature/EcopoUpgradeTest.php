<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentDatabaseBaseline;
use App\Application\RulesetPublisher;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Models\NationResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CommandAndMissileTestCase;

final class EcopoUpgradeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_upgrade_initializes_points_without_reinterpreting_assets_and_preserves_queue_identity(): void
    {
        // This append-only migration always upgrades v37 to the frozen v38,
        // even after the application's current Ruleset advances.
        $next = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v38.php');
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v37.php')]);
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, 'エコポ前方移行');
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'finance', null);
        $before = $item->fresh()->getAttributes();
        $money = $nation->money;
        $inventory = NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->pluck('amount', 'resource_definition_id')->all();
        $snapshot = $world->rulesetVersion->getAttributes();
        DB::statement('ALTER TABLE nations DROP CONSTRAINT nations_ecopo_nonnegative, DROP COLUMN ecopo');
        DB::table('migrations')->where('migration', CurrentDatabaseBaseline::ECOPO_MIGRATION)->delete();
        (require database_path('migrations/2026_10_15_000000_add_nation_ecopo.php'))->up();
        DB::table('migrations')->insert(['migration' => CurrentDatabaseBaseline::ECOPO_MIGRATION, 'batch' => 2]);
        $this->assertSame(0, (int) $nation->fresh()->ecopo);

        config(['hakoniwa.ruleset' => $next]);
        $pending = $this->context($world, $world->current_turn + 1, hash('sha256', 'ecopo upgrade pending'), []);
        $pending->run->update(['is_dry_run' => false, 'status' => 'pending']);
        $migration = require database_path('migrations/2026_10_15_010000_publish_ecopo_ruleset.php');
        try {
            $migration->up();
            $this->fail('Upgrade must refuse unresolved official Turn state.');
        } catch (UnresolvedNextTurnRunException) {
            $this->assertSame($snapshot['id'], $world->fresh()->ruleset_version_id);
        }
        $pending->run->update(['is_dry_run' => true, 'status' => 'dry_run']);
        $migration->up();
        $this->assertSame(
            app(RulesetPublisher::class)->assertPublishedSnapshot($next)->id,
            $world->fresh()->ruleset_version_id,
        );
        $this->assertSame($snapshot, $world->rulesetVersion->fresh()->getAttributes());
        $this->assertSame($money, $nation->fresh()->money);
        $this->assertSame($inventory, NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->pluck('amount', 'resource_definition_id')->all());
        $after = $item->fresh()->getAttributes();
        $this->assertNotSame($before['command_definition_id'], $after['command_definition_id']);
        unset($before['command_definition_id'], $after['command_definition_id']);
        $this->assertSame($before, $after);
        $migration->up();
        $this->assertSame(0, (int) $nation->fresh()->ecopo);
        // Check this schema extension independently of the latest runtime catalog.
        app(CurrentDatabaseBaseline::class)->assertStructure();
        DB::statement('ALTER TABLE nations DROP CONSTRAINT nations_ecopo_nonnegative');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database differs');
        app(CurrentDatabaseBaseline::class)->assertStructure();
    }
}
