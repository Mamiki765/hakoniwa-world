<?php

namespace Tests\Underground\Unit;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentCatalog;
use App\Application\Underground\UndergroundRuntimeCatalog;
use InvalidArgumentException;
use Tests\TestCase;

final class UndergroundRuntimeCatalogPartyAuthoringTest extends TestCase
{
    public function test_catalogs_accept_safe_authored_values_without_requiring_current_balance_literals(): void
    {
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
        $player = app(UndergroundAlphaV1PlayerCatalog::class);
        $equipment = app(UndergroundEquipmentCatalog::class);

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

        $this->assertSame([1, 2, 3, 4], [
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 1),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 2),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 3),
            $catalog->explorationEnemyCountForPartySize('shallow_caves', 4),
        ]);
        $this->assertSame(4, $catalog->explorationEnemyCountForPartySize('black_crystal_cave', 4));
        $this->assertSame(
            ['xp' => 36, 'shards' => 10],
            array_intersect_key(
                $catalog->explorationEncounter('subterranean_rat', 'shallow_caves'),
                ['xp' => true, 'shards' => true],
            ),
        );
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
}
