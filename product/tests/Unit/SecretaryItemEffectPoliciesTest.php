<?php

namespace Tests\Unit;

use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemProbability;
use App\Domain\Secretary\SecretaryItemTargetSafetyPolicy;
use App\Domain\Secretary\SecretaryRingFinanceBonus;
use App\Domain\Turn\TurnState;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SecretaryItemEffectPoliciesTest extends TestCase
{
    public function test_basis_point_boundary_is_exact_and_integer_only(): void
    {
        $probability = app(SecretaryItemProbability::class);

        $this->assertTrue($probability->passesBasisPointDraw(999, 1_000));
        $this->assertFalse($probability->passesBasisPointDraw(1_000, 1_000));
    }

    #[DataProvider('hazardCases')]
    public function test_target_safety_uses_ruleset_owned_hazard_metadata(
        int $hp,
        int $damage,
        bool $expected,
    ): void {
        $monster = $this->monster($hp, 'none', [
            SecretaryItemTargetSafetyPolicy::METADATA_KEY => [
                'policy' => SecretaryItemTargetSafetyPolicy::CERTAIN_SELF_ACTION_AT_REMAINING_HP,
                'remaining_hp' => 1,
            ],
        ]);

        $this->assertSame($expected, app(SecretaryItemTargetSafetyPolicy::class)->allows($monster, $damage, 2));
    }

    public function test_target_safety_excludes_hardened_monsters_without_a_damage_event(): void
    {
        $monster = $this->monster(2, 'harden_odd', []);

        $this->assertFalse(app(SecretaryItemTargetSafetyPolicy::class)->allows($monster, 1, 1));
    }

    public function test_target_safety_metadata_field_order_has_no_meaning(): void
    {
        $monster = $this->monster(2, 'none', [
            SecretaryItemTargetSafetyPolicy::METADATA_KEY => [
                'remaining_hp' => 1,
                'policy' => SecretaryItemTargetSafetyPolicy::CERTAIN_SELF_ACTION_AT_REMAINING_HP,
            ],
        ]);

        $this->assertFalse(app(SecretaryItemTargetSafetyPolicy::class)->allows($monster, 1, 2));
    }

    public function test_target_safety_rejects_malformed_ruleset_metadata(): void
    {
        $monster = $this->monster(2, 'none', [
            SecretaryItemTargetSafetyPolicy::METADATA_KEY => [
                'policy' => SecretaryItemTargetSafetyPolicy::CERTAIN_SELF_ACTION_AT_REMAINING_HP,
                'remaining_hp' => 1,
                'unknown' => true,
            ],
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('target-safety metadata is invalid');
        app(SecretaryItemTargetSafetyPolicy::class)->allows($monster, 1, 2);
    }

    public function test_ring_bonus_uses_the_equipped_level_from_the_immutable_snapshot(): void
    {
        $state = new TurnState;
        $state->setSecretaryItemEffectSnapshot(4, 9, 3, [
            $this->ringSnapshot(21, 4, 2),
        ]);

        $this->assertSame(
            ['equipped_level_sum' => 4, 'requested' => 4],
            app(SecretaryRingFinanceBonus::class)->resolve($state, 4),
        );
        $this->assertSame(
            ['equipped_level_sum' => 0, 'requested' => 0],
            app(SecretaryRingFinanceBonus::class)->resolve(new TurnState, 4),
        );
    }

    public function test_natural_spawn_item_percentages_add_signed_values_within_the_item_genre(): void
    {
        $state = new TurnState;
        $state->setSecretaryItemEffectSnapshot(4, 9, 3, [
            $this->percentageSnapshot(21, 'inora_bracelet', 5, 2, 10),
            $this->percentageSnapshot(22, 'monster_repellent_incense', 5, 3, -1),
        ]);

        $this->assertSame(45, app(SecretaryItemEffectAggregator::class)->snapshotPercentage(
            $state,
            4,
            SecretaryItemGameplayContract::NATURAL_MONSTER_SPAWN_PERCENT,
            'normal_nation_natural_spawn',
        ));
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function hazardCases(): iterable
    {
        yield 'HP2 damage1 leaves certain hazard' => [2, 1, false];
        yield 'HP1 damage1 kills before hazard' => [1, 1, true];
        yield 'HP3 damage1 remains outside hazard' => [3, 1, true];
        yield 'future damage2 kills HP2' => [2, 2, true];
    }

    /** @param array<string, mixed> $sourceMetadata */
    private function monster(int $hp, string $skillKey, array $sourceMetadata): MonsterInstance
    {
        $definition = new MonsterDefinition([
            'skill_key' => $skillKey,
            'source_metadata' => $sourceMetadata,
        ]);
        $monster = new MonsterInstance(['current_hp' => $hp, 'state' => 'alive']);
        $monster->setRelation('definition', $definition);

        return $monster;
    }

    /** @return array<string, mixed> */
    private function ringSnapshot(int $id, int $level, int $slot): array
    {
        return [
            'item_instance_id' => $id,
            'item_key' => 'ring',
            'category' => 'accessory',
            'level' => $level,
            'equipped_slot' => $slot,
            'effects' => [[
                'type' => SecretaryItemGameplayContract::FINANCE_INCOME_BONUS,
                'timing' => 'finance_resolution',
                'parameters' => [
                    'bonus_money_per_level' => 1,
                    'stacking' => SecretaryItemGameplayContract::RING_STACKING,
                ],
                'target_map_space_keys' => [],
                'random_stream_version' => null,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function percentageSnapshot(
        int $id,
        string $itemKey,
        int $level,
        int $slot,
        int $percentPerLevel,
    ): array {
        return [
            'item_instance_id' => $id,
            'item_key' => $itemKey,
            'category' => 'accessory',
            'level' => $level,
            'equipped_slot' => $slot,
            'effects' => [[
                'type' => SecretaryItemGameplayContract::NATURAL_MONSTER_SPAWN_PERCENT,
                'timing' => 'normal_monster_natural_spawn',
                'parameters' => [
                    'source_genre' => SecretaryItemGameplayContract::SOURCE_GENRE_ITEM,
                    'target' => 'normal_nation_natural_spawn',
                    'percent_per_level' => $percentPerLevel,
                    'minimum_final_probability' => 0,
                ],
                'target_map_space_keys' => [],
                'random_stream_version' => null,
            ]],
        ];
    }
}
