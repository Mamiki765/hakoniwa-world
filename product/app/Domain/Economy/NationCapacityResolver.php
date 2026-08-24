<?php

namespace App\Domain\Economy;

use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

final class NationCapacityResolver
{
    /** @var list<CapacityModifier> */
    private array $modifiers;

    /** @param iterable<CapacityModifier> $modifiers */
    public function __construct(iterable $modifiers = [])
    {
        $this->modifiers = [...$modifiers];
    }

    public function resolve(Nation $nation, ?RulesetVersion $ruleset = null): NationCapacities
    {
        $world = $nation->world()->firstOrFail();
        $ruleset ??= $world->rulesetVersion()->firstOrFail();
        if ($ruleset->id !== $world->ruleset_version_id) {
            throw new DomainException('Capacity ruleset does not match the Nation World snapshot.');
        }

        $baseMoney = $ruleset->settings['base_money_capacity'] ?? null;
        $baseFood = $ruleset->settings['base_food_capacity_tons'] ?? null;
        if (! is_int($baseMoney) || $baseMoney < 0 || ! is_int($baseFood) || $baseFood < 0) {
            throw new DomainException('Published ruleset capacity settings are invalid.');
        }
        $resourceCapacities = $ruleset->settings['resource_capacities'] ?? [];
        $definitions = $ruleset->settings['resource_definitions'] ?? null;
        if (! is_array($resourceCapacities) || ! is_array($definitions)) {
            throw new DomainException('Published ruleset resource capacity settings are invalid.');
        }
        $definitionsByKey = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                throw new DomainException('Published ruleset resource definitions are invalid.');
            }
            $definitionsByKey[$definition['key']] = $definition;
        }
        foreach ($resourceCapacities as $resourceKey => $capacity) {
            $definition = $definitionsByKey[$resourceKey] ?? null;
            if (! is_string($resourceKey) || ! is_int($capacity) || $capacity < 0
                || ! is_array($definition) || ($definition['category'] ?? null) === 'food'
                || ($definition['storable'] ?? null) !== true) {
                throw new DomainException('Published ruleset resource capacity settings are invalid.');
            }
        }
        ksort($resourceCapacities);

        if ($this->modifiers !== []) {
            throw new DomainException('Capacity modifier semantics are deferred until E-04 is decided.');
        }

        $secretaryBonus = $ruleset->settings['secretary']['capacity_bonus'] ?? null;
        if ($secretaryBonus !== null) {
            if (! is_array($secretaryBonus)
                || count($secretaryBonus) !== 5
                || ($secretaryBonus['level_source'] ?? null) !== 'sum_passive_skill_levels'
                || ($secretaryBonus['money_percent_per_level'] ?? null) !== 1
                || ($secretaryBonus['food_percent_per_level'] ?? null) !== 1
                || ($secretaryBonus['rounding'] ?? null) !== 'floor_after_multiplier'
                || ! array_key_exists('cap', $secretaryBonus)
                || $secretaryBonus['cap'] !== null) {
                throw new DomainException('Published Secretary capacity bonus settings are invalid.');
            }
            $skillDefinitions = $ruleset->settings['secretary']['skills'] ?? null;
            if (! is_array($skillDefinitions) || $skillDefinitions === []) {
                throw new DomainException('Published Secretary skill settings are invalid.');
            }
            $level = $this->secretaryLevel($nation, count($skillDefinitions));
            $baseMoney = intdiv($baseMoney * (100 + $level), 100);
            $baseFood = intdiv($baseFood * (100 + $level), 100);
        }

        return new NationCapacities($baseMoney, $baseFood, $resourceCapacities);
    }

    private function secretaryLevel(Nation $nation, int $expectedSkillCount): int
    {
        $row = DB::table('nation_memberships as membership')
            ->join('secretaries as secretary', 'secretary.user_id', '=', 'membership.user_id')
            ->join('secretary_skills as skill', 'skill.secretary_id', '=', 'secretary.id')
            ->where('membership.nation_id', $nation->id)
            ->where('membership.role', 'owner')
            ->selectRaw('count(skill.id) as skill_count, coalesce(sum(skill.level), 0) as level_total')
            ->first();
        $skillCount = (int) $row->skill_count;
        if ($skillCount === 0) {
            return 0;
        }
        if ($skillCount !== $expectedSkillCount) {
            throw new DomainException('Nation owner Secretary passive skills are incomplete.');
        }

        return (int) $row->level_total;
    }
}
