<?php

namespace App\Http\Resources;

use App\Application\NationBasicStatusProjection;
use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\NationCapacities;
use App\Domain\Economy\NationCapacityResolver;
use App\Models\Nation;
use App\Models\NationResource as NationResourceBalance;
use App\Support\MoneyFormatter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nation */
class NationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $balances = $this->relationLoaded('resourceBalances')
            ? $this->resourceBalances->sortBy(fn (NationResourceBalance $balance): int => $balance->definition->sort_order)
            : null;
        $isOwner = $balances !== null;
        $basicStatus = app(NationBasicStatusProjection::class)->forNation($this->resource);
        $foodTotal = $basicStatus['food_total_tons'];
        $capacities = $isOwner
            ? app(NationCapacityResolver::class)->resolve($this->resource)
            : null;
        $moneyInUse = $isOwner
            ? app(CapacityBoundedAssetService::class)->moneyInUse($this->resource)
            : null;
        $currentTurn = (int) $this->world()->value('current_turn');
        $lifecycle = config('hakoniwa.ruleset.nation_lifecycle', []);
        $turnsPerDay = is_int($lifecycle['turns_per_day'] ?? null) ? $lifecycle['turns_per_day'] : 12;
        $abandonmentThreshold = is_int($lifecycle['abandonment_idle_threshold'] ?? null)
            ? $lifecycle['abandonment_idle_threshold'] : 2160;
        $dormancyRemainingTurns = $this->state === 'dormant' && $this->resume_at_turn !== null
            ? max(0, (int) $this->resume_at_turn - $currentTurn - 1)
            : null;
        $recoveryRemainingTurns = $this->state === 'recovery' && $this->resume_at_turn !== null
            ? max(0, (int) $this->resume_at_turn - $currentTurn - 1)
            : null;
        $manualDays = $this->state_reason === 'manual'
            && $this->state_started_turn !== null && $this->resume_at_turn !== null
                ? intdiv((int) $this->resume_at_turn - (int) $this->state_started_turn - 1, $turnsPerDay)
                : null;
        $money = app(MoneyFormatter::class);
        $publicMoney = $money->publicEstimate((int) $this->money);

        return [
            'id' => $this->id, 'world_id' => $this->world_id,
            'nation_number' => $this->nation_number, 'name' => $this->name,
            'owner_name' => $this->owner_name,
            'comment' => $this->profile_comment,
            'money' => $this->when($isOwner, (int) $this->money),
            'money_display' => $isOwner ? $money->exact((int) $this->money) : $publicMoney['display'],
            'money_bucket' => $this->when(! $isOwner, $publicMoney['bucket']),
            'money_capacity' => $this->when($isOwner, $capacities?->money),
            'money_remaining_capacity' => $this->when(
                $isOwner,
                max(0, ($capacities->money ?? 0) - ($moneyInUse ?? 0)),
            ),
            'money_is_at_capacity' => $this->when(
                $isOwner,
                ($moneyInUse ?? 0) >= ($capacities->money ?? PHP_INT_MAX),
            ),
            'state' => $this->state,
            'state_label' => match ($this->state) {
                'active' => '', 'dormant' => '休眠',
                'recovery' => '休戦中：残り'.$recoveryRemainingTurns.'ターン',
                'abandoned' => '破棄', default => $this->state,
            },
            'karma' => (int) $this->karma,
            'karma_positive' => $this->karma > 0,
            'recovery_remaining_turns' => $recoveryRemainingTurns,
            'state_reason' => $this->state_reason,
            'state_started_turn' => $this->state_started_turn,
            'resume_at_turn' => $this->resume_at_turn,
            'manual_dormancy_days' => $manualDays,
            'dormancy_remaining_turns' => $dormancyRemainingTurns,
            'dormancy_remaining_days' => $dormancyRemainingTurns === null ? null : (int) ceil($dormancyRemainingTurns / $turnsPerDay),
            'abandonment_remaining_turns' => max(0, $abandonmentThreshold - (int) $this->idle_counter),
            'can_request_dormancy' => $isOwner && $this->state === 'active',
            'winter_theme_active' => $this->state === 'dormant',
            'current_turn' => $currentTurn,
            'registered_turn' => (int) $this->registered_turn,
            'survival_turns' => max(0, $currentTurn - (int) $this->registered_turn),
            'finance_only_turns' => (int) $this->idle_counter,
            'activity_status' => match ($this->state) {
                'dormant' => 'dormant',
                'recovery' => 'recovery',
                default => (int) $this->idle_counter > 0 ? 'finance_only' : 'active',
            },
            'total_population' => $basicStatus['total_population'],
            'territory_cell_count' => $basicStatus['territory_cell_count'],
            'owned_land_cells' => $basicStatus['owned_land_cells'],
            'total_food_tons' => $this->when($isOwner, $foodTotal),
            'food_total_tons' => $foodTotal,
            'food_capacity_tons' => $this->when($isOwner, $capacities?->foodTons),
            'food_remaining_capacity_tons' => $this->when(
                $isOwner,
                max(0, ($capacities->foodTons ?? 0) - $foodTotal),
            ),
            'food_is_at_capacity' => $this->when(
                $isOwner,
                $foodTotal >= ($capacities->foodTons ?? PHP_INT_MAX),
            ),
            'farm_capacity_people' => $basicStatus['farm_capacity_people'],
            'factory_capacity_people' => $basicStatus['factory_capacity_people'],
            'mine_capacity_people' => $basicStatus['mine_capacity_people'],
            'food_resources' => $this->when($isOwner, fn (): array => $balances
                ?->filter(fn (NationResourceBalance $balance): bool => $balance->definition->category === 'food')
                ->map(fn (NationResourceBalance $balance): array => [
                    'key' => $balance->definition->key,
                    'name' => $balance->definition->name,
                    'balance' => $balance->amount,
                    'unit' => $balance->definition->unit,
                    'unit_label' => $balance->definition->unit_label,
                ])->values()->all() ?? []),
            'resources' => $this->when($isOwner, fn (): array => $balances
                ?->map(fn (NationResourceBalance $balance): array => [
                    'key' => $balance->definition->key,
                    'name' => $balance->definition->name,
                    'category' => $balance->definition->category,
                    'unit' => $balance->definition->unit,
                    'unit_label' => $balance->definition->unit_label,
                    'nutrition_per_unit' => $balance->definition->nutrition_per_unit,
                    'storable' => $balance->definition->storable,
                    'tradable' => $balance->definition->tradable,
                    'amount' => $balance->amount,
                    ...$this->capacityFields($balance, $capacities, $foodTotal),
                ])->values()->all() ?? []),
            'capital' => $this->whenLoaded('capital', fn (): ?array => $this->capital === null ? null : [
                'x' => $this->capital->x, 'y' => $this->capital->y,
            ]),
        ];
    }

    /** @return array{capacity: int|null, remaining_capacity: int|null, is_at_capacity: bool} */
    private function capacityFields(
        NationResourceBalance $balance,
        ?NationCapacities $capacities,
        ?int $foodTotal,
    ): array {
        if ($capacities === null) {
            return ['capacity' => null, 'remaining_capacity' => null, 'is_at_capacity' => false];
        }

        $isFood = $balance->definition->category === 'food';
        $capacity = $isFood
            ? $capacities->foodTons
            : $capacities->resource($balance->definition->key);
        $amountForCapacity = $isFood ? ($foodTotal ?? 0) : (int) $balance->amount;

        return [
            'capacity' => $capacity,
            'remaining_capacity' => $capacity === null ? null : max(0, $capacity - $amountForCapacity),
            'is_at_capacity' => $capacity !== null && $amountForCapacity >= $capacity,
        ];
    }
}
