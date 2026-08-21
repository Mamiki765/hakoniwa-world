<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Turn\TurnRandomStreamFactory;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class SecretaryItemGameplayContractTest extends TestCase
{
    public function test_formal_v11_contract_validates_and_resolves_exact_player_text(): void
    {
        $settings = V11SecretaryItemRulesetFixture::settings();
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

    #[DataProvider('invalidContracts')]
    public function test_invalid_or_open_ended_contracts_fail_closed(callable $mutate): void
    {
        $settings = $mutate(V11SecretaryItemRulesetFixture::settings());

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
