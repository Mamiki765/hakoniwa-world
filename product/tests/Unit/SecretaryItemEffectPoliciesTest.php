<?php

namespace Tests\Unit;

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

    public function test_ring_bonus_sums_equipped_levels_from_the_immutable_snapshot(): void
    {
        $state = new TurnState;
        $state->setSecretaryItemEffectSnapshot(4, 9, 3, [
            $this->ringSnapshot(21, 2, 1),
            $this->ringSnapshot(22, 4, 2),
        ]);

        $this->assertSame(
            ['equipped_level_sum' => 6, 'requested' => 6],
            app(SecretaryRingFinanceBonus::class)->resolve($state, 4),
        );
        $this->assertSame(
            ['equipped_level_sum' => 0, 'requested' => 0],
            app(SecretaryRingFinanceBonus::class)->resolve(new TurnState, 4),
        );
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
            'category' => 'ring',
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
}
