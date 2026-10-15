<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CurrentDatabaseBaseline;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\NationMonsterKillStat;
use App\Models\Ship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CommandAndMissileTestCase;

final class UnderseaConstructionShipUpgradeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public static function publicAchievementEndpoints(): array
    {
        return ['rankings' => [true], 'nation detail' => [false]];
    }

    #[DataProvider('publicAchievementEndpoints')]
    public function test_public_achievements_survive_upgrade_and_combine_kills_of_the_same_species(bool $rankings): void
    {
        $next = config('hakoniwa.ruleset');
        config(['hakoniwa.ruleset' => require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php')]);
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '討伐実績移行');
        $oldRuleset = $world->rulesetVersion;
        $snapshot = $oldRuleset->getAttributes();
        $oldDefinition = MonsterDefinition::query()->where('ruleset_version_id', $oldRuleset->id)->firstOrFail();
        $oldDefinitionBefore = $oldDefinition->getAttributes();
        $oldStat = NationMonsterKillStat::query()->create([
            'world_id' => $world->id, 'nation_id' => $nation->id,
            'monster_definition_id' => $oldDefinition->id, 'kill_count' => 1,
            'first_killed_turn' => 3, 'last_killed_turn' => 3, 'version' => 1,
        ]);
        $oldStat->update(['kill_count' => 2, 'last_killed_turn' => 7, 'version' => 2]);
        $oldStatBefore = $oldStat->fresh()->getAttributes();
        config(['hakoniwa.ruleset' => $next]);
        (require database_path('migrations/2026_10_14_130000_publish_undersea_construction_ship_ruleset.php'))->up();
        $world->refresh();
        $this->assertNotSame($oldRuleset->id, $world->ruleset_version_id);
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', $oldDefinition->key)->firstOrFail();
        $url = $rankings ? "/api/v1/public/worlds/{$world->id}/rankings" : "/api/v1/public/nations/{$nation->id}";

        foreach ([false, true] as $withNewKills) {
            if ($withNewKills) {
                NationMonsterKillStat::query()->create([
                    'world_id' => $world->id, 'nation_id' => $nation->id,
                    'monster_definition_id' => $definition->id, 'kill_count' => 1,
                    'first_killed_turn' => 9, 'last_killed_turn' => 9, 'version' => 1,
                ]);
            }
            $count = $oldStat->kill_count + ($withNewKills ? 1 : 0);
            $response = $this->getJson($url)->assertOk();
            if ($rankings) {
                $response->assertJsonPath('data.0.achievements.monster_kills.total_count', $count)
                    ->assertJsonPath('data.0.achievements.monster_kills.asset.key', $definition->asset_key)
                    ->assertJsonPath('data.0.achievements.monster_kills.species', [[
                        'key' => $definition->key, 'name' => $definition->name, 'kill_count' => $count,
                    ]]);
            } else {
                $response->assertJsonPath('data.monster_final_blow_count', $count)
                    ->assertJsonPath('data.monster_kill_stats', [[
                        'key' => $definition->key, 'name' => $definition->name, 'kill_count' => $count,
                        'first_killed_turn' => $oldStat->first_killed_turn,
                        'last_killed_turn' => $withNewKills ? 9 : $oldStat->last_killed_turn,
                    ]]);
            }
            $this->assertSame($oldStatBefore, $oldStat->fresh()->getAttributes());
            $this->assertSame($oldDefinitionBefore, $oldDefinition->fresh()->getAttributes());
            $this->assertSame($snapshot, $oldRuleset->fresh()->getAttributes());
        }
    }

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
