<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentDatabaseBaseline;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Models\MapCell;
use App\Models\Ship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CommandAndMissileTestCase;

final class UnderseaConstructionShipUpgradeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_upgrade_keeps_assets_ship_provenance_and_original_queue_request_identity(): void
    {
        $next = config('hakoniwa.ruleset');
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php')]);
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '前方移行');
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'finance', null);
        $before = $item->fresh()->getAttributes();
        $money = $nation->money;
        $cell = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('facility_definition_id')->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))->firstOrFail();
        $ship = Ship::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id, 'map_cell_id' => $cell->id, 'ship_type_key' => 'fishing',
            'current_hp' => 1, 'max_hp' => 1, 'heading' => null, 'state' => Ship::STATE_ACTIVE, 'version' => 1,
        ]);
        $shipBefore = $ship->fresh()->getAttributes();
        config(['hakoniwa.ruleset' => $next]);
        $migration = require database_path('migrations/2026_10_14_130000_publish_undersea_construction_ship_ruleset.php');
        $migration->up();
        $item->refresh();
        $this->assertSame($next['key'], $world->fresh()->rulesetVersion->key);
        $this->assertSame($world->fresh()->ruleset_version_id, $item->definition->ruleset_version_id);
        $this->assertNotSame($before['command_definition_id'], $item->command_definition_id);
        $after = $item->getAttributes();
        unset($before['command_definition_id'], $after['command_definition_id']);
        $this->assertSame($before, $after);
        $this->assertSame($shipBefore, $ship->fresh()->getAttributes());
        $this->assertSame($money, $nation->fresh()->money);
        $migration->up();
        $this->assertSame($after['request_ruleset_version_id'], $item->fresh()->request_ruleset_version_id);
        app(CurrentDatabaseBaseline::class)->assertExisting();
    }

    public function test_upgrade_refuses_an_unresolved_production_turn(): void
    {
        $next = config('hakoniwa.ruleset');
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php')]);
        $world = $this->lightweightWorld();
        $context = $this->context($world, $world->current_turn + 1, hash('sha256', 'unresolved before upgrade'), []);
        $context->run->update(['status' => 'pending', 'is_dry_run' => false]);
        config(['hakoniwa.ruleset' => $next]);
        try {
            (require database_path('migrations/2026_10_14_130000_publish_undersea_construction_ship_ruleset.php'))->up();
            $this->fail('Upgrade must not cross an unresolved production turn.');
        } catch (UnresolvedNextTurnRunException) {
            $this->assertSame($world->ruleset_version_id, $world->fresh()->ruleset_version_id);
        }
    }
}
