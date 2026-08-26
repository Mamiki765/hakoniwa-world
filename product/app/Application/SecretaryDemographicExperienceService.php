<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryDemographicPolicy;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Models\Nation;
use DomainException;

final class SecretaryDemographicExperienceService
{
    public function __construct(
        private readonly SecretaryDemographicPolicy $policy,
        private readonly SecretaryExperienceAwardService $experience,
    ) {}

    /** @param array<int, int> $finalPopulationByNation
     * @return array{population_high_water_increase: int, net_population_loss: int, nations_awarded: int}
     */
    public function award(TurnContext $context, array $finalPopulationByNation): array
    {
        $metrics = [
            'population_high_water_increase' => 0,
            'net_population_loss' => 0,
            'nations_awarded' => 0,
        ];
        if (! $this->policy->enabled($context->ruleset->settings)) {
            return $metrics;
        }
        $context->state->claimDemographicExperienceAward();
        $nationIds = array_keys($finalPopulationByNation);
        sort($nationIds, SORT_NUMERIC);
        $nations = Nation::query()->whereIn('id', $nationIds)->orderBy('id')
            ->lockForUpdate()->get()->keyBy('id');
        if ($nations->count() !== count($nationIds)) {
            throw new DomainException('Demographic final population references a missing Nation.');
        }
        foreach ($nationIds as $nationId) {
            $nation = $nations->get($nationId);
            $finalPopulation = $finalPopulationByNation[$nationId];
            if (! $nation instanceof Nation || $finalPopulation < 0 || $nation->population_high_water < 0) {
                throw new DomainException('Demographic final population or high-water checkpoint is invalid.');
            }
            $awarded = false;
            $highWaterIncrease = max(0, $finalPopulation - $nation->population_high_water);
            if ($highWaterIncrease > 0) {
                $nation->population_high_water = $finalPopulation;
                $nation->save();
                $this->experience->awardSkill(
                    $context,
                    $nationId,
                    SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
                    $highWaterIncrease,
                );
                $metrics['population_high_water_increase'] += $highWaterIncrease;
                $awarded = true;
            }
            $startPopulation = $context->state->nationStartSummary($nationId)['population'];
            $loss = max(0, $startPopulation - $finalPopulation);
            if ($loss > 0) {
                $this->experience->awardSkill(
                    $context,
                    $nationId,
                    SecretarySkillCatalog::INDOMITABLE,
                    $loss,
                );
                $metrics['net_population_loss'] += $loss;
                $awarded = true;
            }
            $metrics['nations_awarded'] += $awarded ? 1 : 0;
        }

        return $metrics;
    }
}
