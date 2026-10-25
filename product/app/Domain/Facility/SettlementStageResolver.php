<?php

namespace App\Domain\Facility;

final class SettlementStageResolver
{
    /** @param array<array-key, array{minimum_population: int, maximum_population: int, facility_key: string}> $stages */
    public static function forPopulation(int $population, array $stages): string
    {
        foreach ($stages as $stage) {
            if ($population >= $stage['minimum_population'] && $population <= $stage['maximum_population']) {
                return $stage['facility_key'];
            }
        }

        return 'city';
    }
}
