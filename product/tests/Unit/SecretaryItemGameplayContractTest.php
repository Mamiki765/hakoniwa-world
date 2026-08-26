<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretaryDemographicPolicy;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryMonsterDropContract;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CurrentRulesetFixture;
use Tests\TestCase;

final class SecretaryItemGameplayContractTest extends TestCase
{
    public function test_v17_catalog_fixes_rarities_prices_player_trading_and_npc_exclusion(): void
    {
        $settings = config('hakoniwa.ruleset');
        $contract = app(SecretaryItemGameplayContract::class);
        $catalog = app(SecretaryItemCatalog::class);
        $contract->validate($settings);

        $this->assertSame([
            'novice' => ['key' => 'novice', 'name' => 'ノービス', 'fixed_sale_price_money' => 100],
            'regular' => ['key' => 'regular', 'name' => 'レギュラー', 'fixed_sale_price_money' => 500],
            'cursed' => ['key' => 'cursed', 'name' => 'カースド', 'fixed_sale_price_money' => 1],
        ], $catalog->rarities());
        foreach ([SecretaryItemCatalog::ELF_BOW, SecretaryItemCatalog::LONGSHOT_BOW, SecretaryItemCatalog::MECHANICAL_BOW] as $itemKey) {
            $definition = $catalog->definition($itemKey);
            $this->assertSame('regular', $definition['rarity']);
            $this->assertTrue($definition['tradable']);
            $this->assertFalse($definition['npc_tradable']);
            $this->assertSame(500, $definition['fixed_sale_price_money']);
        }
        $collar = $catalog->definition(SecretaryItemCatalog::COLLAR);
        $this->assertSame('cursed', $collar['rarity']);
        $this->assertTrue($collar['tradable']);
        $this->assertFalse($collar['npc_tradable']);
        $this->assertSame(1, $collar['fixed_sale_price_money']);
        $this->assertSame(1, $settings['secretary']['items'][SecretaryItemCatalog::COLLAR]['effects'][0]['minimum_start_karma']);
        $this->assertArrayNotHasKey(
            'minimum_start_karma',
            $settings['secretary']['items'][SecretaryItemCatalog::COLLAR]['effects'][1],
        );
        $oldBow = $catalog->definition(SecretaryItemCatalog::OLD_BOW);
        $this->assertFalse($oldBow['tradable']);
        $this->assertFalse($oldBow['npc_tradable']);
        $this->assertSame(100, $oldBow['fixed_sale_price_money']);
        $this->assertSame(
            [SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY],
            $settings['secretary']['items'][SecretaryItemCatalog::SECRETARY_SUIT]['effects'][0]['excluded_skill_keys'],
        );
        $demographics = app(SecretaryDemographicPolicy::class);
        $this->assertSame(10_500, $demographics->naturalMaximum($settings, 10_000, 10));
        $this->assertSame(21_000, $demographics->attractionMaximum($settings, 20_000, 10));
        $this->assertSame(225, $demographics->indomitableBonus($settings, 9_000, 10));

        $this->assertSame(
            '12%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            $contract->effectText($settings, SecretaryItemCatalog::ELF_BOW, 1),
        );
        $this->assertSame(
            '21%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            $contract->effectText($settings, SecretaryItemCatalog::ELF_BOW, 10),
        );
        $this->assertSame(
            'secretary_item:bow:nation:7:item:elf_bow:trigger:v1',
            TurnRandomStreamFactory::secretaryBow(7, SecretaryItemCatalog::ELF_BOW, 'trigger', 1),
        );
    }

    public function test_v17_monster_drop_tables_and_pools_are_closed_and_exclude_old_bow_and_mecha(): void
    {
        $settings = config('hakoniwa.ruleset');
        app(SecretaryMonsterDropContract::class)->validate($settings);
        $drop = $settings['monster_system']['item_drop'];

        $this->assertSame(['mecha_inora', 'mecha_inora_zero'], $drop['excluded_monster_keys']);
        $this->assertSame(75, $drop['recipient']['killer_percent_when_foreign_host']);
        $this->assertSame(25, $drop['recipient']['host_percent_when_foreign_host']);
        $this->assertSame([
            'elf_bow', 'longshot_bow', 'mechanical_bow',
        ], $drop['rarity_pools']['regular']);
        $this->assertSame(['collar'], $drop['rarity_pools']['cursed']);
        $this->assertNotContains('old_bow', $drop['rarity_pools']['novice']);
        $this->assertSame(
            ['novice' => 40, 'regular' => 40, 'cursed' => 20],
            $drop['monster_tables']['king_inora']['rarity_weights'],
        );
        $this->assertSame(100, $drop['monster_tables']['king_inora']['level_cap_percent']);
    }

    public function test_current_contract_validates_and_resolves_exact_player_text(): void
    {
        $settings = CurrentRulesetFixture::settings();
        $contract = app(SecretaryItemGameplayContract::class);
        $effectCatalog = $contract->validatedEffectCatalog($settings);

        $this->assertSame(
            '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            $contract->effectText($settings, SecretaryItemCatalog::OLD_BOW, 1),
        );
        $this->assertSame(
            '資金繰りの際、追加で3億円を得る。',
            $contract->effectText($settings, SecretaryItemCatalog::RING, 3),
        );
        $this->assertSame(['surface'], $contract->resolvedEffects(
            $settings,
            SecretaryItemCatalog::OLD_BOW,
            1,
        )[0]['target_map_space_keys']);
        $this->assertSame(
            $contract->resolvedEffects($settings, SecretaryItemCatalog::OLD_BOW, 1),
            $effectCatalog[SecretaryItemCatalog::OLD_BOW],
        );
        $this->assertSame(
            $contract->resolvedEffects($settings, SecretaryItemCatalog::RING, 3),
            $effectCatalog[SecretaryItemCatalog::RING],
        );
        $this->assertSame(
            'secretary_item:old_bow:nation:7:trigger:v1',
            TurnRandomStreamFactory::secretaryOldBow(7, 'trigger', 1),
        );
        $this->assertSame(
            'secretary_item:old_bow:nation:7:target:v1',
            TurnRandomStreamFactory::secretaryOldBow(7, 'target', 1),
        );
    }

    public function test_final_v16_reuses_novice_defaults_and_resolves_the_seven_new_item_effects(): void
    {
        $settings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
        $contract = app(SecretaryItemGameplayContract::class);
        $catalog = app(SecretaryItemCatalog::class);

        $contract->validate($settings);
        $this->assertSame(['accessory', 'bow', 'clothing'], array_keys($settings['secretary']['item_categories']));
        $this->assertSame(99, $catalog->maximumEquipped('accessory'));
        $this->assertSame(1, $catalog->maximumEquipped('clothing'));
        $this->assertSame(1, $catalog->sameItemMaximum(SecretaryItemCatalog::RING));
        $this->assertSame(1, $catalog->sameItemMaximum(SecretaryItemCatalog::VAULT_KEY));
        $this->assertCount(9, $settings['secretary']['items']);
        foreach ($settings['secretary']['items'] as $item) {
            $this->assertArrayNotHasKey('same_item_max_equipped', $item);
        }
        $this->assertSame(
            '秘書本人が経験値を得る際、10%の確率でその獲得経験値を2倍にする。',
            $contract->effectText($settings, SecretaryItemCatalog::SECRETARY_SUIT, 10),
        );
        $this->assertArrayNotHasKey(
            'excluded_skill_keys',
            $settings['secretary']['items'][SecretaryItemCatalog::SECRETARY_SUIT]['effects'][0],
        );
        $this->assertSame(
            '自島の通常怪獣自然出現率 +50%',
            $contract->effectText($settings, SecretaryItemCatalog::INORA_BRACELET, 5),
        );
        $this->assertSame(
            '食料最大値 +2%',
            $contract->effectText($settings, SecretaryItemCatalog::FULLNESS_HERB, 1),
        );
        $this->assertSame(
            '食料最大値 +6%',
            $contract->effectText($settings, SecretaryItemCatalog::FULLNESS_HERB, 3),
        );
        $this->assertSame(
            'secretary_item:secretary_suit:nation:7:monster_experience:v1',
            TurnRandomStreamFactory::secretaryExperience(7, 'monster_experience', 1),
        );
    }

    #[DataProvider('invalidContracts')]
    public function test_invalid_or_open_ended_contracts_fail_closed(callable $mutate): void
    {
        $settings = $mutate(CurrentRulesetFixture::settings());

        $this->expectException(DomainException::class);
        app(SecretaryItemGameplayContract::class)->validate($settings);
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>} */
    public static function invalidContracts(): iterable
    {
        yield 'missing effect' => [static function (array $settings): array {
            $settings['secretary']['items']['old_bow']['effects'] = [];

            return $settings;
        }];
        yield 'unknown effect' => [static function (array $settings): array {
            $settings['secretary']['items']['old_bow']['effects'][0]['type'] = 'arbitrary_modifier';

            return $settings;
        }];
        yield 'float probability' => [static function (array $settings): array {
            $settings['secretary']['items']['old_bow']['effects'][0]['chance_basis_points'] = 1000.0;

            return $settings;
        }];
        yield 'unknown MapSpace' => [static function (array $settings): array {
            $settings['secretary']['items']['old_bow']['effects'][0]['target_map_space_keys'] = ['underground'];

            return $settings;
        }];
        yield 'catalog drift' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['max_level'] = 11;

            return $settings;
        }];
        yield 'category limit drift' => [static function (array $settings): array {
            $settings['secretary']['item_categories']['ring']['max_equipped'] = 6;

            return $settings;
        }];
        yield 'same item limit drift' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['same_item_max_equipped'] = 6;

            return $settings;
        }];
        yield 'float finance bonus' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['effects'][0]['bonus_money_per_level'] = 1.0;

            return $settings;
        }];
        yield 'unsupported finance bonus' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['effects'][0]['bonus_money_per_level'] = 2;

            return $settings;
        }];
        yield 'unknown field' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['effects'][0]['expression'] = 'level * 1';

            return $settings;
        }];
        yield 'missing required normal monster stage' => [static function (array $settings): array {
            unset($settings['turn_resolution']);

            return $settings;
        }];
        yield 'incompatible normal monster stage' => [static function (array $settings): array {
            $settings['turn_resolution']['normal_monster_stage'] = 'during_ordinary_surface_cell_events';

            return $settings;
        }];
    }
}
