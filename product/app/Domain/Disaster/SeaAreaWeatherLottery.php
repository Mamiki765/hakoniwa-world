<?php

namespace App\Domain\Disaster;

use App\Domain\Turn\DeterministicRandomStream;
use DomainException;

final class SeaAreaWeatherLottery
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array{numerator: int, denominator: int}  $terminalProbability
     */
    public function draw(array $settings, array $terminalProbability, DeterministicRandomStream $stream): string
    {
        $intervals = $this->disasterIntervals($settings, $terminalProbability);
        $disaster = $this->selectDisaster($intervals, HugeMeteorWeatherDistribution::uniformIndex($intervals['denominator'], $stream));
        if ($disaster !== null) {
            return $disaster;
        }
        $weights = $settings['normal_weights'];

        return $this->selectNormal($weights, HugeMeteorWeatherDistribution::uniformIndex(array_sum($weights), $stream));
    }

    /**
     * @param  array<string, mixed>  $settings
     * @param  array{numerator: int, denominator: int}  $terminalProbability
     * @return array{denominator: int, typhoon: int, meteor_shower: int, huge_meteor: int}
     */
    public function disasterIntervals(array $settings, array $terminalProbability): array
    {
        $left = $settings['denominator'];
        $right = $terminalProbability['denominator'];
        while ($right !== 0) {
            [$left, $right] = [$right, $left % $right];
        }
        $denominator = intdiv($settings['denominator'], $left) * $terminalProbability['denominator'];
        $fixedScale = intdiv($denominator, $settings['denominator']);

        // One exclusive draw keeps disaster marginal rates independent of normal weights/seasons.
        return [
            'denominator' => $denominator,
            'typhoon' => $settings['fixed_probabilities']['typhoon'] * $fixedScale,
            'meteor_shower' => $settings['fixed_probabilities']['meteor_shower'] * $fixedScale,
            'huge_meteor' => $terminalProbability['numerator'] * intdiv($denominator, $terminalProbability['denominator']),
        ];
    }

    /** @param array{denominator: int, typhoon: int, meteor_shower: int, huge_meteor: int} $intervals */
    public function selectDisaster(array $intervals, int $draw): ?string
    {
        if ($draw < 0 || $draw >= $intervals['denominator']
            || $intervals['denominator'] <= $intervals['typhoon'] + $intervals['meteor_shower'] + $intervals['huge_meteor']) {
            throw new DomainException('Invalid sea-area disaster lottery.');
        }
        // PostgreSQL jsonb does not preserve object order; interval order is explicit.
        foreach (['typhoon', 'meteor_shower', 'huge_meteor'] as $key) {
            if ($draw < $intervals[$key]) {
                return $key;
            }
            $draw -= $intervals[$key];
        }

        return null;
    }

    /** @param array<string, int> $weights */
    public function selectNormal(array $weights, int $draw): string
    {
        if ($draw < 0 || $draw >= array_sum($weights)) {
            throw new DomainException('Invalid normal-weather weight draw.');
        }
        foreach (['sunny', 'cloudy', 'rain', 'snow', 'thunder'] as $key) {
            if ($draw < $weights[$key]) {
                return $key;
            }
            $draw -= $weights[$key];
        }

        throw new DomainException('Sea-area weather weights did not resolve a result.');
    }
}
