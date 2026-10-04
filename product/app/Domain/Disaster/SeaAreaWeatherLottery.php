<?php

namespace App\Domain\Disaster;

use DomainException;

final class SeaAreaWeatherLottery
{
    /** @param array<string, mixed> $settings */
    public function select(array $settings, int $draw): string
    {
        $denominator = $settings['denominator'];
        $fixed = $settings['fixed_probabilities'];
        $weights = $settings['normal_weights'];
        $reserved = array_sum($fixed);
        $totalWeight = array_sum($weights);
        if ($draw < 0 || $draw >= $denominator || $reserved >= $denominator || $totalWeight <= 0) {
            throw new DomainException('Invalid sea-area weather lottery.');
        }
        // PostgreSQL jsonb does not preserve object key order; interval order is explicit.
        foreach (['typhoon', 'meteor_shower'] as $key) {
            $probability = $fixed[$key];
            if ($draw < $probability) {
                return $key;
            }
            $draw -= $probability;
        }
        $cumulative = 0;
        foreach (['sunny', 'cloudy', 'rain', 'snow', 'thunder'] as $key) {
            $weight = $weights[$key];
            $cumulative += $weight;
            if ($draw * $totalWeight < $cumulative * ($denominator - $reserved)) {
                return $key;
            }
        }

        throw new DomainException('Sea-area weather weights did not resolve a result.');
    }
}
