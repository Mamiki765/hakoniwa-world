<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeCatalog;
use App\Application\Underground\UndergroundRuntimeEquipmentGenerator;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundRuntimeCatalogPartyAuthoringTest extends TestCase
{
    public function test_catalogs_accept_safe_authored_values_without_requiring_current_balance_literals(): void
    {
        $player = app(UndergroundAlphaV1PlayerCatalog::class);
        $foundation = $player->laboratoryCatalog();
        $player->trialCatalog('trial_01');
        $trial = config('underground-runtime.trials.trial_01');
        array_pop($trial['encounters']);
        array_pop($trial['rewards']);
        $trial['content_identity'] = 'test-configured-trial';
        $trial['first_clear_skill_points'] = 30;
        $trial['unlocked_area_layers'] = 3;
        config([
            'underground-runtime.combat.max_rounds' => 80,
            'underground-runtime.combat.battle_log_retention_hours' => 2,
            'underground-runtime.trials.configured_trial' => $trial,
            'underground-alpha-v1.initial_skill_points' => 30,
            'underground-alpha-v1.exploration.max_rounds' => 80,
            'underground-alpha-v1.shop.inn.cost_shards' => 20,
            'underground-alpha-v1.shop.bank.transfer_unit_shards' => 2_000,
            'underground-equipment.vault_capacity' => 1_000,
            'underground-equipment.page_size' => 20,
        ]);

        $runtime = app(UndergroundRuntimeCatalog::class);
        $equipment = app(UndergroundEquipmentCatalog::class);

        // Reuse is the performance contract; authored values still come from config.
        $this->assertSame($player, app(UndergroundAlphaV1PlayerCatalog::class));
        $this->assertSame($foundation, $player->laboratoryCatalog());
        $this->assertSame([80, 2], [$runtime->maxRounds(), $runtime->battleLogRetentionHours()]);
        $this->assertContains('configured_trial', $runtime->trialKeys());
        $this->assertSame([9, 30, 3], [
            count($runtime->trial('configured_trial')['encounters']),
            $runtime->trial('configured_trial')['first_clear_skill_points'],
            $runtime->trial('configured_trial')['unlocked_area_layers'],
        ]);
        $this->assertNotEmpty($player->trialCatalog('configured_trial')->manifest()['enemies']);
        $this->assertSame([30, 80, 20, 2_000], [
            $player->initialSkillPoints(),
            $player->explorationMaxRounds(),
            $player->innCost(),
            $player->bankTransferUnit(),
        ]);
        $this->assertSame([1_000, 20], [$equipment->vaultCapacity(), $equipment->pageSize()]);

        $fixedReward = $equipment->definition('iron_breastplate');
        $fixedReward['shop_sold'] = false;
        $fixedReward['modifiers'] = ['healing_bps' => 180];
        $equipment->assertDefinition($fixedReward, false);
        $this->addToAssertionCount(1);
    }

    public function test_hunting_ground_enemy_counts_are_authored_per_party_size_without_touching_rewards(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $exploration = $catalog->explorationCatalog();
        $this->assertSame($exploration, $catalog->explorationCatalog());
        $before = $catalog->explorationEncounter('subterranean_rat', 'shallow_caves');
        config(['underground-alpha-v1.exploration.grounds.shallow_caves.enemy_count_by_party_size' => [1 => 1, 2 => 1, 3 => 2, 4 => 3]]);

        $this->assertNotSame($exploration, $catalog->explorationCatalog());
        $this->assertSame([1, 1, 2, 3], array_map(
            fn (int $size): int => $catalog->explorationEnemyCountForPartySize('shallow_caves', $size),
            [1, 2, 3, 4],
        ));
        $this->assertSame(
            array_intersect_key($before, ['xp' => true, 'shards' => true]),
            array_intersect_key(
                $catalog->explorationEncounter('subterranean_rat', 'shallow_caves'),
                ['xp' => true, 'shards' => true],
            ),
        );
        $this->expectException(InvalidArgumentException::class);
        $catalog->explorationEnemyCountForPartySize('shallow_caves', 5);
    }

    public function test_catalog_reuse_refreshes_manifest_bytes_and_container_scope(): void
    {
        $originalConfigPath = $this->app->configPath();
        $foundationPath = 'underground/balance/foundation-v4.json';
        $trialPath = config('underground-runtime.trials.trial_01.balance_manifest');
        $foundation = json_decode(file_get_contents(config_path($foundationPath)), true, flags: JSON_THROW_ON_ERROR);
        $trial = json_decode(file_get_contents(config_path($trialPath)), true, flags: JSON_THROW_ON_ERROR);
        $enemyKey = array_key_first($foundation['enemies']);
        $trialEnemyKey = array_key_first($trial['enemies']);
        $foundation['enemies'][$enemyKey]['label'] = 'fixture-before';
        $directory = sys_get_temp_dir().'/underground-catalog-'.bin2hex(random_bytes(8));
        mkdir($directory.'/underground/balance', 0700, true);
        $encode = static fn (array $manifest): string => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        try {
            $this->app->useConfigPath($directory);
            file_put_contents(config_path($foundationPath), $encode($foundation));
            file_put_contents(config_path($trialPath), $encode($trial));
            $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
            $first = $catalog->laboratoryCatalog();
            $firstTrial = $catalog->trialCatalog('trial_01');
            $this->assertSame($firstTrial, $catalog->trialCatalog('trial_01'));

            $model = app(AlphaV1CombatModel::class);
            $fight = $model->fight($first, 'balanced', $enemyKey, 'late', 17, 4);
            $this->assertEquals($fight, $model->fight($catalog->laboratoryCatalog(), 'balanced', $enemyKey, 'late', 17, 4));
            $copy = $first->manifest();
            $copy['enemies'][$enemyKey]['label'] = 'local mutation';
            $this->assertSame($foundation['enemies'][$enemyKey]['label'], $first->enemy($enemyKey)['label']);

            $mtime = filemtime(config_path($foundationPath));
            $size = filesize(config_path($foundationPath));
            $foundation['enemies'][$enemyKey]['label'] = 'fixture-after!';
            file_put_contents(config_path($foundationPath), $encode($foundation));
            touch(config_path($foundationPath), $mtime);
            clearstatcache(true, config_path($foundationPath));
            $this->assertSame($size, filesize(config_path($foundationPath)));
            $this->assertSame($mtime, filemtime(config_path($foundationPath)));
            $refreshed = $catalog->laboratoryCatalog();
            $this->assertNotSame($first, $refreshed);
            $this->assertSame($foundation['enemies'][$enemyKey]['label'], $refreshed->enemy($enemyKey)['label']);
            $this->assertSame('fixture-before', $first->enemy($enemyKey)['label']);
            $this->assertNotSame($firstTrial, $catalog->trialCatalog('trial_01'));

            $trial['enemies'][$trialEnemyKey]['label'] = 'updated trial fixture';
            file_put_contents(config_path($trialPath), $encode($trial));
            $this->assertSame($trial['enemies'][$trialEnemyKey]['label'], $catalog->trialCatalog('trial_01')->enemy($trialEnemyKey)['label']);

            file_put_contents(config_path($foundationPath), '{invalid');
            try {
                $catalog->laboratoryCatalog();
                $this->fail('Changed invalid input must not reuse a previously valid catalog.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('manifest is invalid', $exception->getMessage());
            }
            file_put_contents(config_path($foundationPath), $encode($foundation));
            $this->app->forgetScopedInstances();
            $nextScope = app(UndergroundAlphaV1PlayerCatalog::class);
            $this->assertNotSame($catalog, $nextScope);
            $this->assertEquals($refreshed->manifest(), $nextScope->laboratoryCatalog()->manifest());
        } finally {
            $this->app->useConfigPath($originalConfigPath);
            unlink($directory.'/'.$foundationPath);
            unlink($directory.'/'.$trialPath);
            rmdir($directory.'/underground/balance');
            rmdir($directory.'/underground');
            rmdir($directory);
        }
    }

    public function test_growth_path_stp_per_level_is_authored_without_a_persisted_balance_literal(): void
    {
        config(['underground-alpha-v1.growth_paths.martial_red.unspent_stp_per_level' => 6]);

        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);

        $this->assertSame(6, $catalog->growthPath('martial_red')['unspent_stp_per_level']);
        $this->assertSame(12, $catalog->stpEntitlement('martial_red', 3));
    }

    public function test_party_boss_scaling_supports_none_and_content_authored_tables(): void
    {
        $catalog = app(UndergroundRuntimeCatalog::class);

        $this->assertSame(
            ['mode' => 'none', 'hp_bps' => 10_000, 'attack_bps' => 10_000],
            $catalog->resolvePartyBossScaling(['mode' => 'none'], 4),
        );
        $this->assertSame(
            ['mode' => 'table', 'hp_bps' => 13_000, 'attack_bps' => 11_000],
            $catalog->resolvePartyBossScaling([
                'mode' => 'table',
                'hp_bps' => [1 => 10_000, 2 => 11_000, 3 => 12_000, 4 => 13_000],
                'attack_bps' => [1 => 10_000, 2 => 10_000, 3 => 10_500, 4 => 11_000],
            ], 4),
        );
    }

    public function test_party_boss_scaling_rejects_missing_or_malformed_table(): void
    {
        $catalog = app(UndergroundRuntimeCatalog::class);

        $this->expectException(InvalidArgumentException::class);
        $catalog->resolvePartyBossScaling([
            'mode' => 'table',
            'hp_bps' => [1 => 10_000],
            'attack_bps' => [1 => 10_000, 2 => 10_000, 3 => 10_000, 4 => 10_000],
        ], 1);
    }

    public function test_yunagi_harbor_wiring_and_catalog_bound_equipment_reaches_combat(): void
    {
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $ground = $catalog->explorationHuntingGround('yunagi_harbor');
        $rare = $ground['rare_encounter'];

        $this->assertSame('yunagi_harbor_key_balance', $ground['key_balance_field']);
        $this->assertSame('yunagi_harbor', $catalog->explorationHuntingGround('yunagi_harbor_vault')['drop_tier_key']);

        $item = app(UndergroundRuntimeEquipmentGenerator::class)->generate(
            $ground['item_level_max'], 'yunagi_harbor', 'epic', 'weapon', 'dagger', null, 31000, 'yunagi-harbor-top-tier',
        );
        $this->assertSame($ground['item_level_max'], $item['item_level']);

        $loadout = app(UndergroundEquipmentCatalog::class)->combatLoadout([[
            'slot' => 'weapon', 'definition' => $item,
            'catalog_identity' => $item['generator_identity'], 'instance_identity' => $item['instance_identity'],
        ]]);
        $snapshot = $catalog->explorationCombatDefinition('martial_red', 700, [
            'vitality' => 0, 'might' => $catalog->stpEntitlement('martial_red', 700),
            'finesse' => 0, 'spirit' => 0, 'agility' => 0,
        ], $loadout, '代表戦技')['player_snapshot'];
        $battle = app(AlphaV1CombatModel::class)->fightPlayerSnapshot(
            $catalog->explorationCatalog(), $snapshot, $rare['key'], 31_000, 100, 300,
        );
        // Wiring, not balance: the rare enemy is fought with the catalog-bound generated weapon.
        $this->assertSame($rare['key'], $battle->enemyKey);
        $this->assertSame($item['weapon_power'], $loadout['weapon_power']);
        $this->assertSame($loadout['weapon_power'], $snapshot['equipment']['weapon_power']);
        $this->assertGreaterThan(0, $battle->damageDealt);
    }
}
