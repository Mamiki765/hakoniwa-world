<?php

namespace Tests\Underground\Fixtures;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatModel;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\CanonicalCombatOrchestrator;
use App\Domain\Underground\Combat\DeterministicEquipmentGenerator;
use App\Domain\Underground\Combat\PartyCombatResult;
use App\Domain\Underground\Combat\PriorityCombatAi;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Domain\Underground\Combat\UndergroundBuildValidator;

/**
 * Small deterministic party fixture for projection and frontend tests.
 * It deliberately enters the real party model so decision, MP cost, recovery,
 * and revival rows cannot drift away from the runtime envelope.
 */
final class PartyPresentationFixture
{
    /**
     * @return array{
     *     catalog: AlphaV1BuildCatalog,
     *     result: PartyCombatResult,
     *     member_snapshots: array<string, array<string, mixed>>
     * }
     */
    public static function create(): array
    {
        $manifestJson = file_get_contents(dirname(__DIR__, 3).'/config/underground/balance/foundation-v1.json');
        if (! is_string($manifestJson)) {
            throw new \RuntimeException('Party presentation fixture manifest is unavailable.');
        }
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new \RuntimeException('Party presentation fixture manifest is invalid.');
        }
        $manifest['enemies']['party_presentation_fixture'] = [
            'label' => 'PT表示試験体',
            'boss' => false,
            'base_stats' => [
                'vitality' => 10,
                'might' => 80,
                'finesse' => 5,
                'spirit' => 5,
                'agility' => 1_000,
            ],
            'max_hp' => 100_000_000,
            'physical_defense' => 100,
            'magical_defense' => 100,
            'weapon_power' => 500_000,
            'normal_attack' => [
                'type' => 'damage',
                'category' => 'physical',
                'potency_bps' => 10_000,
                'stat_coefficients' => ['might' => 10_000],
                'weapon_coefficient_bps' => 10_000,
                'fixed' => 0,
                'target_max_hp_bps' => 0,
                'can_crit' => false,
                'dodgeable' => false,
                'hits' => 1,
            ],
            'skills' => [],
            'ai_rules' => [[
                'conditions' => [['type' => 'always']],
                'action' => 'normal_attack',
            ]],
            'modifiers' => [],
        ];
        $catalog = new AlphaV1BuildCatalog($manifest);
        $configuration = require dirname(__DIR__, 3).'/config/underground-alpha-v1.php';
        $equipment = $configuration['exploration']['starter_weapon'];
        $leader = self::player('secretary:1', 1, false, true, $equipment);
        $healer = self::player('borrowed:2', 100, true, false, $equipment);
        $healer['awakening']['growth_path'] = 'blessing_green';
        $healer['awakening']['technique_key'] = 'life_requiem';
        $attacker = self::player('secretary:3', 1_000, false, false, $equipment);
        $attacker['active_skills'] = ['holy_bolt'];
        $attacker['ai_rules'] = [[
            'conditions' => [['type' => 'skill_ready', 'skill' => 'holy_bolt']],
            'action' => 'skill:holy_bolt',
        ]];
        $fourthMember = self::player('borrowed:4', 1_000, false, false, $equipment);

        $model = new AlphaV1CombatModel(
            new AlphaV1CombatRules,
            new UndergroundBuildValidator(new AlphaV1CombatRules),
            new DeterministicEquipmentGenerator(new AlphaV1CombatRules),
            new PriorityCombatAi,
            new CanonicalCombatOrchestrator,
            new UndergroundAwakening,
        );
        $result = $model->fightPartySnapshots(
            $catalog,
            [$leader, $healer, $attacker, $fourthMember],
            ['party_presentation_fixture'],
            38_000,
            1,
            0,
        );

        return [
            'catalog' => $catalog,
            'result' => $result,
            'member_snapshots' => [
                'secretary:1' => self::member('Leader', 'leader.webp', 'leader-bust.webp'),
                'borrowed:2' => self::member('Healer', 'healer.webp', 'healer-bust.webp'),
                'secretary:3' => self::member('Attacker', 'attacker.webp', 'attacker-bust.webp'),
                'borrowed:4' => self::member('Support', 'support.webp', 'support-bust.webp'),
                'enemy:1' => ['team' => 'enemy', 'label' => 'PT表示試験体', 'image_references' => []],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function player(
        string $combatantId,
        int $currentHp,
        bool $awakening,
        bool $defend,
        array $equipment,
    ): array {
        return [
            'combatant_id' => $combatantId,
            'key' => 'party_secretary',
            'label' => $combatantId,
            'stats' => ['vitality' => 100, 'might' => 40, 'finesse' => 1, 'spirit' => 40, 'agility' => 200],
            'active_skills' => [],
            'ai_rules' => $awakening
                ? [
                    ['conditions' => [['type' => 'own_hp_lte', 'percent' => 20]], 'action' => 'awakening'],
                    ['conditions' => [['type' => 'always']], 'action' => 'normal_attack'],
                ]
                : [[
                    'conditions' => [['type' => 'always']],
                    'action' => $defend ? 'defend' : 'normal_attack',
                ]],
            'modifiers' => [],
            'equipment' => $equipment,
            'current_hp' => $currentHp,
            'natural_recovery' => 0,
            'awakening' => [
                'unlocked' => $awakening,
                'gauge' => $awakening ? UndergroundAwakening::GAUGE_MAX : 0,
                'message' => '覚醒する。',
                'growth_path' => 'martial_red',
                'technique_key' => 'decisive_heavenrend',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function member(string $displayName, string $icon, string $normal): array
    {
        $iconReference = self::imageReference($icon);
        $normalReference = self::imageReference($normal);
        $awakeningIconReference = self::imageReference('awakening-'.$icon);
        $awakeningReference = self::imageReference('awakening-'.$normal);

        return [
            'team' => 'player',
            'display_name' => $displayName,
            'image_references' => [
                'compact' => $iconReference,
                'awakening_compact' => $awakeningIconReference,
                'normal' => $normalReference,
                'awakening' => $awakeningReference,
            ],
        ];
    }

    /** @return array{display: string, url: string, path: string, creation_method: string, creation_method_label: string, credit: string} */
    private static function imageReference(string $path): array
    {
        return [
            'display' => 'uploaded',
            'url' => '/fixtures/'.$path,
            'path' => 'fixtures/'.$path,
            'creation_method' => 'fixture',
            'creation_method_label' => 'テスト用fixture',
            'credit' => 'Hakoniwa fixture credit',
        ];
    }
}
