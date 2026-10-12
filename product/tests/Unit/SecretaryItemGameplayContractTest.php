<?php

namespace Tests\Unit;

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
    public function test_v17_catalog_fixes_player_trading_and_npc_exclusion(): void
    {
        $settings = config('hakoniwa.ruleset');
        $contract = app(SecretaryItemGameplayContract::class);
        $catalog = app(SecretaryItemCatalog::class);
        $contract->validate($settings);

        foreach ([SecretaryItemCatalog::ELF_BOW, SecretaryItemCatalog::LONGSHOT_BOW, SecretaryItemCatalog::MECHANICAL_BOW] as $itemKey) {
            $definition = $catalog->definition($itemKey);
            $this->assertTrue($definition['tradable']);
            $this->assertFalse($definition['npc_tradable']);
        }
        $collar = $catalog->definition(SecretaryItemCatalog::COLLAR);
        $this->assertTrue($collar['tradable']);
        $this->assertFalse($collar['npc_tradable']);
        $oldBow = $catalog->definition(SecretaryItemCatalog::OLD_BOW);
        $this->assertFalse($oldBow['tradable']);
        $this->assertFalse($oldBow['npc_tradable']);
        $dokidoki = $catalog->definition(SecretaryItemCatalog::DOKIDOKI_TICKET);
        $this->assertSame('ticket', $dokidoki['category']);
        $this->assertSame(
            [SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY],
            $settings['secretary']['items'][SecretaryItemCatalog::SECRETARY_SUIT]['effects'][0]['excluded_skill_keys'],
        );
        // Validate the saved v34 item contract without a removed publication file.
        $historical = $settings;
        $historical['key'] = 'hakoniwa-2s-plus-v34';
        $historical['version'] = 34;
        unset($historical['secretary']['items']['attraction_towel']);
        unset($historical['turn_processing']['settlement']['capital_maximum_basis'],
            $historical['turn_processing']['settlement']['capital_maximum_attraction_bonus']);
        $historical['capital_growth_maximum_population'] = 25000;
        $contract->validate($historical);

        $this->assertSame(
            'secretary_item:bow:nation:7:item:elf_bow:trigger:v1',
            TurnRandomStreamFactory::secretaryBow(7, SecretaryItemCatalog::ELF_BOW, 'trigger', 1),
        );
        $this->assertSame(
            'secretary_item:bow:nation:7:item:gem_bow:target:v1',
            TurnRandomStreamFactory::secretaryBow(7, 'gem_bow', 'target', 1),
        );
    }

    public function test_current_monster_drop_pools_are_closed_and_exclude_old_bow(): void
    {
        $settings = config('hakoniwa.ruleset');
        app(SecretaryMonsterDropContract::class)->validate($settings);

        $this->assertNotContains('old_bow', $settings['monster_system']['item_drop']['rarity_pools']['novice']);
    }

    public function test_current_contract_validates_and_resolves_effects(): void
    {
        $settings = CurrentRulesetFixture::settings();
        $contract = app(SecretaryItemGameplayContract::class);
        $effectCatalog = $contract->validatedEffectCatalog($settings);

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
        yield 'float finance bonus' => [static function (array $settings): array {
            $settings['secretary']['items']['ring']['effects'][0]['bonus_money_per_level'] = 1.0;

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
