<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Models\Secretary;
use App\Models\SecretarySkill;
use App\Models\User;
use DomainException;

final class SecretaryPresenter
{
    public function __construct(
        private readonly SecretarySkillCatalog $catalog,
        private readonly SecretarySkillProgression $progression,
        private readonly SecretaryItemPresenter $items,
        private readonly SecretaryProfilePresenter $profiles,
    ) {}

    /** @return array<string, mixed> */
    public function present(
        Secretary $secretary,
        ?SecretaryItemEffectProjection $projection = null,
        ?User $viewer = null,
    ): array {
        $definitions = $this->catalog->definitions(config('hakoniwa.ruleset'));
        $rows = $secretary->skills->keyBy('skill_key');
        $skills = [];
        foreach ($definitions as $key => $definition) {
            $row = $rows->get($key);
            if (! $row instanceof SecretarySkill) {
                throw new DomainException("Secretary {$secretary->id} is missing skill {$key}.");
            }
            $required = $this->progression->requiredExperience($definition, $row->level);
            $skills[] = [
                'key' => $key,
                'name' => $definition['name'],
                'level' => $row->level,
                'experience' => $row->experience,
                'required_experience' => $required,
                'remaining_experience' => max(0, $required - $row->experience),
                'effect' => $this->effect($key, $row->level, $definition),
                'experience_condition' => $this->experienceCondition($key),
            ];
        }
        if ($rows->count() !== count($skills)) {
            throw new DomainException("Secretary {$secretary->id} has an unexpected skill outside the current catalog.");
        }

        return [
            'id' => $secretary->id,
            'name' => $secretary->name,
            'named_at' => $secretary->named_at?->toIso8601String(),
            'header_label' => $this->profiles->battleDisplayName($secretary),
            'profile' => $this->profiles->present($secretary, $viewer, $projection),
            'skills' => $skills,
            ...$this->items->present($secretary, $projection),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function effect(string $skillKey, int $level, array $definition): string
    {
        return match ($skillKey) {
            SecretarySkillCatalog::AGRICULTURAL_POLICY => sprintf('小麦生産＋%.1f%%', $level / 10),
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => sprintf('工場生産＋%.1f%%', $level / 10),
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => sprintf('採掘場生産＋%.1f%%', $level / 10),
            SecretarySkillCatalog::FOREST_MANAGEMENT => "伐採資金・森林増加＋{$level}%",
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => "防衛されなかったミサイルを1ターンにつき{$level}発まで迎撃",
            SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY => $this->birthratePolicyEffect($definition, $level),
            SecretarySkillCatalog::INDOMITABLE => $this->indomitableEffect($definition, $level),
            SecretarySkillCatalog::SHIP_OPERATIONS => '準備中',
            SecretarySkillCatalog::NAVY => '効果なし',
            SecretarySkillCatalog::ENERGY_SAVING => sprintf('電力消費 %.2f%%',
                100 * ($definition['effect']['base'] + $definition['effect']['numerator_per_level'] * $level)
                / ($definition['effect']['base'] + $definition['effect']['denominator_per_level'] * $level)),
            default => throw new DomainException("Unknown Secretary skill {$skillKey}."),
        };
    }

    private function experienceCondition(string $skillKey): string
    {
        return match ($skillKey) {
            SecretarySkillCatalog::AGRICULTURAL_POLICY => '農場整備を行う',
            SecretarySkillCatalog::SPECIALTY_DEVELOPMENT => '工場整備を行う',
            SecretarySkillCatalog::GOLD_VEIN_SURVEY => '採掘場整備を行う',
            SecretarySkillCatalog::FOREST_MANAGEMENT => '植林・伐採を行う',
            SecretarySkillCatalog::FINAL_DEFENSE_LINE => '自領土にミサイルが飛来する',
            SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY => '過去最大人口を更新する（経験値補正無効）',
            SecretarySkillCatalog::INDOMITABLE => '1ターンで人口が減少する',
            SecretarySkillCatalog::SHIP_OPERATIONS => '船がマップ上を移動する',
            SecretarySkillCatalog::NAVY => '軍艦の射撃が敵に命中する',
            SecretarySkillCatalog::ENERGY_SAVING => '電力を消費する',
            default => throw new DomainException("Unknown Secretary skill {$skillKey}."),
        };
    }

    /** @param array<string, mixed> $definition */
    private function birthratePolicyEffect(array $definition, int $level): string
    {
        $effect = $definition['effect'] ?? null;
        $naturalPerLevel = is_array($effect) ? ($effect['natural_maximum_per_level'] ?? null) : null;
        $attractionPerLevel = is_array($effect) ? ($effect['attraction_maximum_per_level'] ?? null) : null;
        if (! is_int($naturalPerLevel) || ! is_int($attractionPerLevel)) {
            throw new DomainException('Declining birthrate policy presentation contract is invalid.');
        }

        return sprintf(
            '自然人口上限 +%s人 / 誘致人口上限 +%s人',
            number_format($level * $naturalPerLevel),
            number_format($level * $attractionPerLevel),
        );
    }

    /** @param array<string, mixed> $definition */
    private function indomitableEffect(array $definition, int $level): string
    {
        $effect = $definition['effect'] ?? null;
        $basisPointsPerLevel = is_array($effect) ? ($effect['basis_points_per_level'] ?? null) : null;
        if (! is_int($basisPointsPerLevel)) {
            throw new DomainException('Indomitable presentation contract is invalid.');
        }

        return sprintf('自然人口増加 +%.2f%%', ($level * $basisPointsPerLevel) / 100);
    }
}
