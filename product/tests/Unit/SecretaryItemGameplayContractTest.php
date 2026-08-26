<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CurrentRulesetFixture;
use Tests\TestCase;

final class SecretaryItemGameplayContractTest extends TestCase
{
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
