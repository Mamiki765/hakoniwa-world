<?php

namespace Tests\Unit;

use App\Domain\Monster\MonsterDispatchOptionResolver;
use App\Domain\Monster\MonsterDisplayOrderResolver;
use App\Domain\Monster\MonsterRewardPolicyResolver;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Services\AssetManifestResolver;
use DomainException;
use Tests\Support\V11SecretaryItemRulesetFixture;
use Tests\TestCase;

final class MonsterFoundationContractTest extends TestCase
{
    public function test_unpublished_v11_fixture_has_the_exact_ten_species_and_no_new_natural_spawn_members(): void
    {
        $settings = $this->authoringSettings();

        $this->assertSame(10, app(RulesetAuthoringValidator::class)->validate($settings)['monsters']);
        $this->assertSame([
            'mecha_inora',
            'mecha_inora_zero',
            'inora',
            'sanjira',
            'red_inora',
            'dark_inora',
            'aoi_inora',
            'inora_ghost',
            'whale',
            'king_inora',
        ], array_column($settings['monster_definitions'], 'key'));
        $this->assertSame([0, 50, 100, 200, 300, 400, 450, 500, 600, 700],
            array_column($settings['monster_definitions'], 'display_order'));
        $poolKeys = array_merge(...array_column(
            $settings['monster_system']['natural_spawn']['population_tiers'],
            'monster_keys',
        ));
        $this->assertNotContains('mecha_inora_zero', $poolKeys);
        $this->assertNotContains('aoi_inora', $poolKeys);
        foreach (array_slice($settings['monster_definitions'], 0, 10) as $definition) {
            if (in_array($definition['key'], ['mecha_inora_zero', 'aoi_inora'], true)) {
                $this->assertArrayNotHasKey('kind', $definition['source_metadata']);
                $this->assertArrayNotHasKey('skill_code', $definition['source_metadata']);
                $this->assertArrayNotHasKey('filename', $definition['source_metadata']);
            }
        }
    }

    public function test_v11_monster_dispatch_is_one_exact_two_option_contract(): void
    {
        $settings = $this->authoringSettings();
        $dispatches = array_values(array_filter(
            $settings['command_definitions'],
            static fn (array $definition): bool => $definition['key'] === 'monster_dispatch',
        ));

        $this->assertCount(1, $dispatches);
        $this->assertSame(3_000, $dispatches[0]['cost_money']);
        $this->assertSame(MonsterDispatchOptionResolver::CATALOG, $dispatches[0]['metadata']['quantity_selects_catalog']);
        $this->assertSame(1, $dispatches[0]['metadata']['default_selector_value']);
        $this->assertSame([
            ['value' => 1, 'monster_key' => 'mecha_inora', 'label' => 'メカいのら', 'cost_money' => 3_000, 'enabled' => true],
            ['value' => 2, 'monster_key' => 'mecha_inora_zero', 'label' => 'メカいのら零式', 'cost_money' => 9_999, 'enabled' => true],
        ], $dispatches[0]['metadata'][MonsterDispatchOptionResolver::OPTIONS_METADATA_KEY]);
    }

    public function test_authoring_rejects_monster_values_that_the_database_constraints_reject(): void
    {
        foreach ([['hp_variation', 19], ['natural_spawn_tier', 4]] as [$field, $value]) {
            $settings = $this->authoringSettings();
            $settings['monster_definitions'][0][$field] = $value;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("Authoring accepted {$field} outside the persisted database range.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString($field, $exception->getMessage());
            }
        }
    }

    public function test_display_order_prefers_explicit_values_and_falls_back_to_audited_legacy_kinds(): void
    {
        $resolver = app(MonsterDisplayOrderResolver::class);

        $this->assertSame(50, $resolver->resolve(50, ['kind' => 'not-used']));
        foreach (range(0, 7) as $kind) {
            $this->assertSame($kind * 100, $resolver->resolve(null, ['kind' => $kind]));
        }
    }

    public function test_display_order_rejects_malformed_explicit_fallback_and_duplicate_values(): void
    {
        $resolver = app(MonsterDisplayOrderResolver::class);
        foreach ([-1, '50', 1.5, true] as $value) {
            try {
                $resolver->resolve($value, ['kind' => 0]);
                $this->fail('Malformed explicit display order was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
        foreach ([[], ['kind' => -1], ['kind' => 8], ['kind' => '1']] as $source) {
            try {
                $resolver->resolve(null, $source);
                $this->fail('Malformed legacy source kind was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $settings = V11SecretaryItemRulesetFixture::settings();
        $settings['monster_definitions'][1]['display_order'] = 0;
        $this->expectException(DomainException::class);
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_authored_display_order_fits_the_postgresql_integer_range(): void
    {
        $validator = app(RulesetAuthoringValidator::class);
        $settings = V11SecretaryItemRulesetFixture::settings();
        $settings['monster_definitions'][0]['display_order'] = 2_147_483_647;
        $this->assertSame(10, $validator->validate($settings)['monsters']);

        $settings['monster_definitions'][0]['display_order'] = 2_147_483_648;
        $this->expectException(DomainException::class);
        $validator->validate($settings);
    }

    public function test_extended_monster_shape_is_available_only_to_matching_v11_identity_and_version(): void
    {
        $validator = app(RulesetAuthoringValidator::class);
        $v11 = V11SecretaryItemRulesetFixture::settings();
        $this->assertSame(10, $validator->validate($v11)['monsters']);

        $legacyIdentity = $v11;
        $legacyIdentity['key'] = 'hakoniwa-2s-plus-v10';
        $legacyIdentity['version'] = 10;
        try {
            $validator->validate($legacyIdentity);
            $this->fail('A v10 identity accepted the extended monster shape.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $missingOrder = $v11;
        unset($missingOrder['monster_definitions'][0]['display_order']);
        try {
            $validator->validate($missingOrder);
            $this->fail('A v11 identity accepted a monster definition without explicit display order.');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        foreach ([
            ['key' => 'hakoniwa-2s-plus-v11', 'version' => 10],
            ['key' => 'hakoniwa-2s-plus-v10', 'version' => 11],
        ] as $identity) {
            $mismatch = $v11;
            $mismatch['key'] = $identity['key'];
            $mismatch['version'] = $identity['version'];
            try {
                $validator->validate($mismatch);
                $this->fail('A mismatched ruleset identity/version accepted the v11 monster contract.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_v11_authoring_supports_more_than_ten_and_fails_closed_for_pool_policy_and_safety_drift(): void
    {
        $base = $this->authoringSettings();
        $eleventh = $base['monster_definitions'][1];
        $eleventh['key'] = 'future_fixture_monster';
        $eleventh['name'] = '将来試験怪獣';
        $eleventh['asset_key'] = 'hakoniwa_custom.monster.future_fixture_monster';
        $eleventh['display_order'] = 800;
        unset($eleventh['source_metadata']['secretary_item_target_safety']);
        $eleventh['source_metadata']['behavior'] = [
            'movement' => 'legacy_land',
            'dispatchable' => false,
            'can_act_on_spawn_turn' => false,
            'special_action' => 'none',
            'island_creation_displaceable' => false,
        ];
        $base['monster_definitions'][] = $eleventh;
        $this->assertSame(11, app(RulesetAuthoringValidator::class)->validate($base)['monsters']);

        $mutations = [
            static function (array $settings): array {
                $settings['monster_definitions'][1]['source_metadata']['kind'] = 8;

                return $settings;
            },
            static function (array $settings): array {
                $settings['monster_definitions'][1]['source_metadata']['reward_policy'] = 'unknown';

                return $settings;
            },
            static function (array $settings): array {
                $settings['monster_definitions'][1]['source_metadata']['secretary_item_target_safety']['remaining_hp'] = 0;

                return $settings;
            },
            static function (array $settings): array {
                $settings['monster_system']['natural_spawn']['population_tiers'][0]['monster_keys'][] = 'missing';

                return $settings;
            },
            static function (array $settings): array {
                $settings['monster_system']['natural_spawn']['population_tiers'][0]['monster_keys'][] = 'inora';

                return $settings;
            },
        ];
        foreach ($mutations as $mutate) {
            try {
                app(RulesetAuthoringValidator::class)->validate($mutate($this->authoringSettings()));
                $this->fail('Malformed v11 monster authoring was accepted.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_reward_policies_are_ruleset_owned_and_preserve_standard_split(): void
    {
        $resolver = app(MonsterRewardPolicyResolver::class);
        $v10 = config('hakoniwa.ruleset');
        $v11 = V11SecretaryItemRulesetFixture::settings();

        $this->assertSame([
            'policy' => 'standard_split', 'explicitly_authored' => false,
            'killer_share' => 600, 'host_share' => 0, 'unclaimed_share' => 600,
        ], $resolver->shares($v10, 'inora', 1_200, false));
        $this->assertSame([
            'policy' => 'standard_split', 'explicitly_authored' => true,
            'killer_share' => 600, 'host_share' => 600, 'unclaimed_share' => 0,
        ], $resolver->shares($v11, 'inora', 1_200, true));
        $this->assertSame([
            'policy' => 'hostless_full_killer_money', 'explicitly_authored' => true,
            'killer_share' => 1_200, 'host_share' => 0, 'unclaimed_share' => 0,
        ], $resolver->shares($v11, 'aoi_inora', 1_200, false));
        $this->assertSame([
            'policy' => 'hostless_full_killer_money', 'explicitly_authored' => true,
            'killer_share' => 600, 'host_share' => 600, 'unclaimed_share' => 0,
        ], $resolver->shares($v11, 'aoi_inora', 1_200, true));
    }

    public function test_custom_asset_contracts_and_missing_binary_fallback_are_exact(): void
    {
        $assets = app(AssetManifestResolver::class);
        $expected = [
            'hakoniwa_custom.monster.aoi_inora' => 'monster-aoi-inora.gif',
            'hakoniwa_custom.monster.mecha_inora_zero' => 'monster-mecha-inora-zero.gif',
        ];
        foreach ($expected as $key => $filename) {
            $this->assertSame($filename, $assets->filenameForAssetKey($key));
            $resolved = $assets->resolve($key, '怪獣');
            $this->assertFalse($resolved['available']);
            $this->assertNull($resolved['url']);
            $this->assertSame(str_replace(['.', '_'], '-', $key), $resolved['fallback_style']);
        }
    }

    public function test_advanced_manual_table_is_derived_from_the_shared_v11_fixture(): void
    {
        $document = file_get_contents(base_path('docs/manual/advanced.md'));
        $this->assertIsString($document);
        preg_match('/<!-- 怪獣一覧:start -->\R(.+?)\R<!-- 怪獣一覧:end -->/su', $document, $match);
        $this->assertArrayHasKey(1, $match);
        $lines = preg_split('/\R/u', trim($match[1]));
        $this->assertIsArray($lines);
        $actualRows = array_slice($lines, 2);
        $expectedRows = [];
        foreach (V11SecretaryItemRulesetFixture::settings()['monster_definitions'] as $definition) {
            $maximumHp = $definition['base_hp'] + $definition['hp_variation'];
            $hp = $maximumHp === $definition['base_hp']
                ? (string) $definition['base_hp']
                : $definition['base_hp'].'～'.$maximumHp;
            $expectedRows[] = sprintf(
                '| %s | %s | %s | %s億円 | %d | %s |',
                $definition['name'],
                $hp,
                $definition['source_metadata']['manual']['appearance'],
                number_format($definition['wreckage_value_money']),
                $definition['missile_base_experience'],
                $definition['source_metadata']['manual']['special'],
            );
        }
        $this->assertSame($expectedRows, $actualRows);
        foreach (['display_order', 'source kind', 'reward_policy', 'random stream', 'migration', 'checkpoint', '.gif'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $match[1]);
        }
    }

    /** @return array<string, mixed> */
    private function authoringSettings(): array
    {
        return V11SecretaryItemRulesetFixture::settings();
    }
}
