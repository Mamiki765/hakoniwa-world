<?php

namespace App\Domain\Monster;

use DomainException;

final class MonsterNaturalSpawnPolicy
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  list<string>  $definitionKeys
     */
    public function validatePoolReferences(array $settings, array $definitionKeys): void
    {
        $tiers = $settings['population_tiers'] ?? null;
        if (! is_array($tiers) || ! array_is_list($tiers)) {
            throw new DomainException('The active ruleset has invalid monster population tiers.');
        }
        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! array_is_list($tier['monster_keys'] ?? null)) {
                throw new DomainException('The active ruleset has an invalid monster population tier.');
            }
            $seen = [];
            foreach ($tier['monster_keys'] as $monsterKey) {
                if (! is_string($monsterKey) || $monsterKey === '') {
                    throw new DomainException('A monster population tier contains an invalid monster key.');
                }
                if (isset($seen[$monsterKey])) {
                    throw new DomainException('A monster population tier contains a duplicate monster key.');
                }
                if (! in_array($monsterKey, $definitionKeys, true)) {
                    throw new DomainException("A monster population tier references missing definition {$monsterKey}.");
                }
                $seen[$monsterKey] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{numerator: int, denominator: int}
     */
    public function probabilityForLand(array $settings, int $ownedLandCells): array
    {
        if ($ownedLandCells < 0) {
            throw new DomainException('Monster spawn land area cannot be negative.');
        }
        $probability = $settings['probability_per_land_cell'] ?? null;
        $maximum = $settings['maximum_probability_numerator'] ?? null;
        if (! is_array($probability)
            || ! is_int($probability['numerator'] ?? null)
            || $probability['numerator'] < 0
            || ! is_int($probability['denominator'] ?? null)
            || $probability['denominator'] < 1
            || ! is_int($maximum)
            || $maximum < 0
            || $maximum > $probability['denominator']) {
            throw new DomainException('The active ruleset has invalid monster spawn probability arithmetic.');
        }
        $perCell = $probability['numerator'];
        if ($perCell > 0 && $ownedLandCells > intdiv(PHP_INT_MAX, $perCell)) {
            throw new DomainException('Monster spawn probability arithmetic overflowed.');
        }

        return [
            'numerator' => min($maximum, $ownedLandCells * $perCell),
            'denominator' => $probability['denominator'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function poolForPopulation(array $settings, int $population): array
    {
        if ($population < 0) {
            throw new DomainException('Monster spawn population cannot be negative.');
        }
        $tiers = $settings['population_tiers'] ?? null;
        if (! is_array($tiers)) {
            throw new DomainException('The active ruleset has invalid monster population tiers.');
        }

        $pool = [];
        foreach ($tiers as $tier) {
            if (! is_array($tier) || ! is_int($tier['minimum_population'] ?? null)
                || ! is_array($tier['monster_keys'] ?? null)) {
                throw new DomainException('The active ruleset has an invalid monster population tier.');
            }
            foreach ($tier['monster_keys'] as $monsterKey) {
                if (! is_string($monsterKey) || $monsterKey === '') {
                    throw new DomainException('A monster population tier contains an invalid monster key.');
                }
            }
            if ($population >= $tier['minimum_population']) {
                $pool = array_values($tier['monster_keys']);
            }
        }

        return $pool;
    }
}
