<?php

namespace App\Domain\Economy;

use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class NationCapacityResolver
{
    /** @var list<CapacityModifier> */
    private array $modifiers;

    /** @param iterable<CapacityModifier> $modifiers */
    public function __construct(
        private readonly SecretaryItemEffectAggregator $itemEffects,
        iterable $modifiers = [],
    ) {
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

        [$centralMoney, $centralFood] = $this->centralCapacityBonuses($nation, $ruleset);
        $baseMoney = $this->checkedAdd($baseMoney, $centralMoney, 'money');
        $baseFood = $this->checkedAdd($baseFood, $centralFood, 'food');

        $expectedSkillCount = null;
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
            $expectedSkillCount = count($skillDefinitions);
        }

        [$secretaryPercent, $equippedItems] = $this->capacitySources($nation, $expectedSkillCount);
        $itemPercentages = $this->itemEffects->capacityPercentages($ruleset, $equippedItems);
        $baseMoney = $this->applyPercentageGenres(
            $baseMoney,
            $itemPercentages[SecretaryItemGameplayContract::CAPACITY_MONEY],
            $secretaryPercent,
        );
        $baseFood = $this->applyPercentageGenres(
            $baseFood,
            $itemPercentages[SecretaryItemGameplayContract::CAPACITY_ALL_RESOURCES]
                + $itemPercentages[SecretaryItemGameplayContract::CAPACITY_FOOD],
            $secretaryPercent,
        );
        $resourceItemPercent = $itemPercentages[SecretaryItemGameplayContract::CAPACITY_ALL_RESOURCES];
        if ($resourceItemPercent !== 0) {
            foreach ($resourceCapacities as $resourceKey => $capacity) {
                $resourceCapacities[$resourceKey] = $this->applyPercentageGenres($capacity, $resourceItemPercent);
            }
        }

        return new NationCapacities($baseMoney, $baseFood, $resourceCapacities);
    }

    /**
     * @return array{int, list<array{item_key: string, level: int}>}
     */
    private function capacitySources(Nation $nation, ?int $expectedSkillCount): array
    {
        $skillTotals = DB::table('nation_memberships as skill_membership')
            ->join('secretaries as skill_secretary', 'skill_secretary.user_id', '=', 'skill_membership.user_id')
            ->join('secretary_skills as skill', 'skill.secretary_id', '=', 'skill_secretary.id')
            ->where('skill_membership.nation_id', $nation->id)
            ->where('skill_membership.world_id', $nation->world_id)
            ->where('skill_membership.role', 'owner')
            ->groupBy('skill_membership.nation_id')
            ->selectRaw('skill_membership.nation_id, count(skill.id) as skill_count, coalesce(sum(skill.level), 0) as level_total');
        $rows = DB::table('nations as capacity_nation')
            ->leftJoinSub($skillTotals, 'skill_totals', 'skill_totals.nation_id', '=', 'capacity_nation.id')
            ->leftJoin('nation_memberships as membership', function (JoinClause $join): void {
                $join->on('membership.nation_id', '=', 'capacity_nation.id')
                    ->on('membership.world_id', '=', 'capacity_nation.world_id')
                    ->where('membership.role', '=', 'owner');
            })
            ->leftJoin('secretaries as secretary', 'secretary.user_id', '=', 'membership.user_id')
            ->leftJoin('secretary_item_instances as item', function (JoinClause $join): void {
                $join->on('item.secretary_id', '=', 'secretary.id')
                    ->whereNotNull('item.equipped_slot')
                    ->where('item.is_escrowed', '=', false);
            })
            ->where('capacity_nation.id', $nation->id)
            ->where('capacity_nation.world_id', $nation->world_id)
            ->orderBy('item.equipped_slot')
            ->orderBy('item.id')
            ->get([
                'skill_totals.skill_count', 'skill_totals.level_total',
                'item.item_key', 'item.level',
            ]);
        $first = $rows->first();
        if ($first === null) {
            throw new DomainException('Capacity Nation no longer exists in its World.');
        }
        $skillCount = (int) ($first->skill_count ?? 0);
        if ($expectedSkillCount !== null && $skillCount !== 0 && $skillCount !== $expectedSkillCount) {
            throw new DomainException('Nation owner Secretary passive skills are incomplete.');
        }
        $items = [];
        foreach ($rows as $row) {
            if ($row->item_key === null) {
                continue;
            }
            $items[] = ['item_key' => (string) $row->item_key, 'level' => (int) $row->level];
        }

        return [
            $expectedSkillCount === null ? 0 : (int) ($first->level_total ?? 0),
            $items,
        ];
    }

    private function applyPercentageGenres(int $base, int ...$percentages): int
    {
        $numerator = $base;
        $denominator = 1;
        foreach ($percentages as $percentage) {
            $factor = 100 + $percentage;
            if ($factor < 0) {
                $factor = 0;
            }
            $numerator *= $factor;
            $denominator *= 100;
        }

        return intdiv($numerator, $denominator);
    }

    /** @return array{int, int} */
    private function centralCapacityBonuses(Nation $nation, RulesetVersion $ruleset): array
    {
        $definitions = $ruleset->settings['central_facilities']['definitions'] ?? null;
        if ($definitions === null) {
            return [0, 0];
        }
        if (! is_array($definitions) || array_is_list($definitions)) {
            throw new DomainException('Published central facility capacity settings are invalid.');
        }

        $rows = DB::table('map_cells as cell')
            ->join('facility_definitions as facility', 'facility.id', '=', 'cell.facility_definition_id')
            ->where('cell.owner_nation_id', $nation->id)
            ->whereIn('facility.key', array_keys($definitions))
            ->orderBy('facility.key')
            ->get(['facility.key', 'cell.facility_scale']);
        $counts = [];
        $money = 0;
        $food = 0;
        foreach ($rows as $row) {
            $facilityKey = (string) $row->key;
            $contract = $definitions[$facilityKey] ?? null;
            $authoredFacility = $ruleset->settings['facility_definitions'][$facilityKey] ?? null;
            if (! is_array($contract) || ! is_array($authoredFacility)
                || ($contract['facility_key'] ?? null) !== $facilityKey
                || ($contract['maximum_per_nation'] ?? null) !== 1
                || ! is_int($contract['capacity_per_level'] ?? null)
                || $contract['capacity_per_level'] < 1
                || ! is_int($authoredFacility['maximum_scale'] ?? null)) {
                throw new DomainException("Published central facility {$facilityKey} settings are invalid.");
            }
            $counts[$facilityKey] = ($counts[$facilityKey] ?? 0) + 1;
            if ($counts[$facilityKey] > 1) {
                throw new DomainException("Nation has more than one {$facilityKey} facility.");
            }
            $level = $row->facility_scale;
            if (! is_int($level) || $level < 1 || $level > $authoredFacility['maximum_scale']) {
                throw new DomainException("Central facility {$facilityKey} has an invalid level.");
            }
            $bonus = $level * $contract['capacity_per_level'];
            match ($contract['capacity_kind'] ?? null) {
                'money' => $money = $this->checkedAdd($money, $bonus, 'central money bonus'),
                'food_tons' => $food = $this->checkedAdd($food, $bonus, 'central food bonus'),
                default => throw new DomainException("Central facility {$facilityKey} has an invalid capacity kind."),
            };
        }

        return [$money, $food];
    }

    private function checkedAdd(int $left, int $right, string $label): int
    {
        if ($right > PHP_INT_MAX - $left) {
            throw new DomainException("Resolved {$label} capacity would overflow.");
        }

        return $left + $right;
    }
}
