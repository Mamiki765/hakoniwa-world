<?php

namespace Tests\Unit;

use App\Domain\Monster\MonsterDispatchOptionResolver;
use App\Domain\Monster\MonsterDisplayOrderResolver;
use App\Domain\Monster\MonsterRewardPolicyResolver;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Services\AssetManifestResolver;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CurrentRulesetFixture;
use Tests\TestCase;

final class MonsterFoundationContractTest extends TestCase
{
    public function test_current_fixture_preserves_special_spawn_membership(): void
    {
        $settings = $this->authoringSettings();

        app(RulesetAuthoringValidator::class)->validate($settings);
        $poolKeys = array_merge(...array_column(
            $settings['monster_system']['natural_spawn']['population_tiers'],
            'monster_keys',
        ));
        $this->assertContains('mecha_inora_zero', $poolKeys);
        $this->assertContains('nyowamiya', $poolKeys);
        $this->assertNotContains('aoi_inora', $poolKeys);
    }

    public function test_current_monster_dispatch_uses_the_registered_catalog_and_resolves_options(): void
    {
        $settings = $this->authoringSettings();
        $dispatches = array_values(array_filter(
            $settings['command_definitions'],
            static fn (array $definition): bool => $definition['key'] === 'monster_dispatch',
        ));

        $this->assertCount(1, $dispatches);
        $this->assertSame(MonsterDispatchOptionResolver::CATALOG, $dispatches[0]['metadata']['quantity_selects_catalog']);
        $this->assertSame(1, $dispatches[0]['metadata']['default_selector_value']);
        $this->assertNotEmpty($dispatches[0]['metadata'][MonsterDispatchOptionResolver::OPTIONS_METADATA_KEY]);
    }

    public function test_authoring_rejects_monster_values_that_the_database_constraints_reject(): void
    {
        foreach ([['hp_variation', 65_535, 'HP range'], ['natural_spawn_tier', 5, 'natural_spawn_tier']] as [$field, $value, $message]) {
            $settings = $this->authoringSettings();
            $settings['monster_definitions'][0][$field] = $value;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("Authoring accepted {$field} outside the persisted database range.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage());
            }
        }
    }

    public function test_display_order_prefers_explicit_values_and_falls_back_to_audited_legacy_kinds(): void
    {
        $resolver = app(MonsterDisplayOrderResolver::class);

        $this->assertSame(50, $resolver->resolve(50, ['kind' => 'not-used']));
        $this->assertSame(0, $resolver->resolve(null, ['kind' => 0]));
        $this->assertSame(700, $resolver->resolve(null, ['kind' => 7]));
    }

    public function test_display_order_rejects_malformed_explicit_and_fallback_values(): void
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
    }

    #[DataProvider('requiredC4MonsterProvider')]
    public function test_current_authoring_requires_each_c4_baseline_monster(string $monsterKey): void
    {
        $settings = $this->authoringSettings();
        $settings['monster_definitions'] = array_values(array_filter(
            $settings['monster_definitions'],
            static fn (array $definition): bool => $definition['key'] !== $monsterKey,
        ));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("required C4 monster {$monsterKey}");
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    /** @return array<string, array{string}> */
    public static function requiredC4MonsterProvider(): array
    {
        return [
            'aoi inora' => ['aoi_inora'],
            'mecha inora zero' => ['mecha_inora_zero'],
        ];
    }

    public function test_current_dispatch_options_require_dispatchable_authored_behaviors(): void
    {
        $settings = $this->authoringSettings();
        foreach ($settings['monster_definitions'] as &$definition) {
            if ($definition['key'] === 'mecha_inora_zero') {
                $definition['source_metadata']['behavior']['dispatchable'] = false;
            }
        }
        unset($definition);

        $this->expectException(DomainException::class);
        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    public function test_reward_policies_are_ruleset_owned_and_preserve_standard_split(): void
    {
        $resolver = app(MonsterRewardPolicyResolver::class);
        $current = CurrentRulesetFixture::settings();

        $this->assertSame([
            'policy' => 'standard_split', 'explicitly_authored' => true,
            'killer_share' => 600, 'host_share' => 600, 'unclaimed_share' => 0,
        ], $resolver->shares($current, 'inora', 1_200, true));
        $this->assertSame([
            'policy' => 'hostless_full_killer_money', 'explicitly_authored' => true,
            'killer_share' => 1_200, 'host_share' => 0, 'unclaimed_share' => 0,
        ], $resolver->shares($current, 'aoi_inora', 1_200, false));
        $this->assertSame([
            'policy' => 'hostless_full_killer_money', 'explicitly_authored' => true,
            'killer_share' => 600, 'host_share' => 600, 'unclaimed_share' => 0,
        ], $resolver->shares($current, 'aoi_inora', 1_200, true));
    }

    public function test_custom_asset_contracts_resolve_present_binaries_and_fall_back_for_missing_ones(): void
    {
        $directory = sys_get_temp_dir().'/hakoniwa-monster-assets-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $present = 'hakoniwa_custom.monster.aoi_inora';
        $missing = 'hakoniwa_custom.monster.mecha_inora_zero';
        config([
            'hakoniwa.assets.path' => $directory,
            'hakoniwa.assets.allowed_extensions' => ['gif'],
        ]);
        $assets = new AssetManifestResolver;
        $presentFilename = $assets->filenameForAssetKey($present);
        $missingFilename = $assets->filenameForAssetKey($missing);
        $this->assertIsString($presentFilename);
        $this->assertIsString($missingFilename);
        file_put_contents(
            $directory.'/'.$presentFilename,
            base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true),
        );

        try {
            $resolved = $assets->resolve($present, '怪獣');
            $this->assertTrue($resolved['available']);
            $this->assertIsString($resolved['url']);
            $this->assertStringContainsString($presentFilename, $resolved['url']);

            $fallback = $assets->resolve($missing, '怪獣');
            $this->assertFalse($fallback['available']);
            $this->assertNull($fallback['url']);
            $this->assertNotSame($resolved['fallback_style'], $fallback['fallback_style']);
        } finally {
            unlink($directory.'/'.$presentFilename);
            rmdir($directory);
        }
    }

    /** @return array<string, mixed> */
    private function authoringSettings(): array
    {
        return CurrentRulesetFixture::settings();
    }
}
