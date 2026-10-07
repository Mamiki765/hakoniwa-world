<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\FoodConsumptionPlanner;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Economy\PowerEconomyCalculator;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class PowerEconomyTurnService
{
    public function __construct(
        private readonly PowerEconomyCalculator $power,
        private readonly FoodConsumptionPlanner $food,
        private readonly NationCapacityResolver $capacities,
        private readonly CapacityBoundedAssetService $assets,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @param iterable<MapCell> $facilities
     * @param  Collection<int, ResourceDefinition>  $catalog
     */
    public function execute(TurnContext $context, Nation $nation, iterable $facilities, Collection $catalog): void
    {
        if (! isset($context->ruleset->settings['power_economy']) || ! in_array($nation->state, ['active', 'recovery'], true)) {
            return;
        }
        $windIds = [];
        $scales = [];
        $thermalScales = [];
        foreach ($facilities as $cell) {
            if ($cell->facility_operational_state === 'damaged') {
                continue;
            }
            if ($cell->facility?->key === 'wind_power') {
                $windIds[] = (int) $cell->id;
            } elseif ($cell->facility?->key === 'thermal_power') {
                if ($cell->facility_scale === null) {
                    throw new DomainException('Thermal scale is missing.');
                }
                $thermalScales[] = (int) $cell->facility_scale;
            } elseif ($cell->facility?->key === 'pizzeria') {
                if ($cell->facility_scale === null) {
                    throw new DomainException('Pizzeria scale is missing.');
                }
                $scales[] = (int) $cell->facility_scale;
            }
        }
        if ($windIds === [] && $scales === [] && $thermalScales === []) {
            return;
        }
        $balances = NationResource::query()->where('nation_id', $nation->id)->lockForUpdate()->get()->keyBy('resource_definition_id');
        $foods = [];
        foreach ($context->ruleset->settings['turn_processing']['food']['consumption_priority'] as $key) {
            $definition = $catalog->firstWhere('key', $key);
            if (! $definition instanceof ResourceDefinition || $definition->category !== 'food') {
                throw new DomainException('Power economy food definition is missing.');
            }
            $foods[] = [
                'resource_key' => $key,
                'amount' => (int) ($balances->get($definition->id)->amount ?? 0),
                'nutrition' => (int) $definition->nutrition_per_unit,
            ];
        }
        $foodAvailable = array_sum(array_map(static fn (array $row): int => $row['amount'] * $row['nutrition'], $foods));
        $definition = $catalog->firstWhere('key', 'power');
        if (! $definition instanceof ResourceDefinition) {
            throw new DomainException('Power resource definition is missing.');
        }
        $balance = $balances->get($definition->id);
        if (! $balance instanceof NationResource) {
            throw new DomainException('Power balance is missing; apply the forward migration.');
        }
        $generation = $this->power->windGeneration($context->ruleset->settings, $windIds, $context->random);
        $fuelBalances = [];
        foreach (['oil', 'minerals'] as $key) {
            $fuelDefinition = $catalog->firstWhere('key', $key);
            $fuelBalance = $fuelDefinition instanceof ResourceDefinition ? $balances->get($fuelDefinition->id) : null;
            if (! $fuelBalance instanceof NationResource) {
                throw new DomainException('Thermal fuel balance is missing.');
            }
            $fuelBalances[$key] = $fuelBalance;
        }
        $thermal = $this->power->thermalGenerationForTurn($context->ruleset->settings, $thermalScales,
            (int) $fuelBalances['oil']->amount, (int) $fuelBalances['minerals']->amount, (int) $nation->id, $context->random);
        $generation += $thermal['generated_mw'];
        $level = $context->state->hasSecretarySnapshot($nation->id)
            ? $context->state->secretarySkillLevel($nation->id, SecretarySkillCatalog::ENERGY_SAVING) : 0;
        $plan = $this->power->settleTurn(
            $context->ruleset->settings, (int) $nation->id, (int) $balance->amount,
            $generation, $this->capacities->resolve($nation, $context->ruleset)->resources['power'],
            $foodAvailable, $scales, $context->random, $level,
        );
        foreach (['oil', 'minerals'] as $key) {
            if ($thermal[$key.'_consumed'] > 0) {
                $fuelBalances[$key]->decrement('amount', $thermal[$key.'_consumed']);
            }
        }
        $consumption = $this->food->plan($foods, $plan['food_consumed_tons']);
        foreach ($consumption['resources'] as $row) {
            $resource = $catalog->firstWhere('key', $row['resource_key']);
            $foodBalance = $resource instanceof ResourceDefinition ? $balances->get($resource->id) : null;
            if ($row['consumed_units'] > 0 && $foodBalance instanceof NationResource) {
                $foodBalance->decrement('amount', $row['consumed_units']);
            }
        }
        $balance->update(['amount' => $plan['stored_after_mw']]);
        $credit = $this->assets->creditMoney($nation, $plan['pizzeria_revenue'], $context->ruleset);
        if ($scales !== [] && $plan['food_consumed_tons'] > 0) {
            $context->state->addEconomicContribution($nation->id, 'pizzeria', 'money', $credit->applied, null);
        }
        $nation->refresh();
        if ($plan['consumed_mw'] > 0 && $context->state->hasSecretarySnapshot($nation->id)) {
            $context->state->awardSecretaryExperience($nation->id, SecretarySkillCatalog::ENERGY_SAVING, $plan['consumed_mw']);
        }
        $this->events->record($context, 'resource.power_settled', $nation, [
            ...$plan, 'resource_key' => 'power', 'wind_count' => count($windIds),
            'credited_revenue' => $credit->applied,
            'food_consumption' => $consumption,
            'thermal' => $thermal, 'energy_saving_level' => $level,
        ]);
    }
}
