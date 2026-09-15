<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\BuriedTreasure;
use App\Models\BuriedTreasureReveal;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use DomainException;

final class BuriedTreasureService
{
    public function __construct(
        private readonly SecretaryItemGrantService $items,
        private readonly TurnEventRecorder $events,
        private readonly WorldDisasterOpportunityService $opportunities,
    ) {}

    /** @return array<string, int> */
    public function spawnNatural(TurnContext $context, MapSpace $space): array
    {
        $settings = $context->ruleset->settings['ocean_loop']['buried_treasure'] ?? null;
        if (! is_array($settings)) {
            return [];
        }
        $spawn = $settings['natural_spawn'] ?? null;
        if (! is_array($spawn)) {
            throw new DomainException('The active Ruleset has no natural Buried Treasure contract.');
        }
        $metrics = [
            'natural_treasure_spawn_draws' => 0,
            'natural_treasures_spawned' => 0,
            'natural_treasure_spawn_blocked' => 0,
        ];
        $opportunities = $this->opportunities->resolve(
            $context,
            $space,
            'buried_treasure',
            $spawn['world_area_scale'] ?? null,
        );
        $probability = $spawn['probability'] ?? null;
        $version = (int) $settings['stream_version'];
        if (! is_array($probability)
            || ! is_int($probability['numerator'] ?? null)
            || ! is_int($probability['denominator'] ?? null)
            || $probability['denominator'] < 1
            || $probability['numerator'] < 0
            || $probability['numerator'] > $probability['denominator']) {
            throw new DomainException('The active Ruleset has an invalid natural Buried Treasure probability.');
        }
        for ($opportunity = 1; $opportunity <= $opportunities['count']; $opportunity++) {
            $draw = $context->random->stream(TurnRandomStreamFactory::naturalTreasure(
                $opportunity,
                'trigger',
                $version,
            ))->integer(0, $probability['denominator'] - 1);
            $metrics['natural_treasure_spawn_draws']++;
            if ($draw >= $probability['numerator']) {
                continue;
            }
            $candidates = MapCell::query()->where('map_space_id', $space->id)
                ->whereNull('owner_nation_id')->whereNull('facility_definition_id')->where('population', 0)
                ->whereHas('terrain', fn ($query) => $query->where('key', $spawn['terrain_key']))
                ->whereDoesntHave('ship')->orderBy('id')->lockForUpdate()->get();
            $occupiedByMonster = MonsterOccupancy::query()->whereIn('map_cell_id', $candidates->modelKeys())
                ->pluck('map_cell_id')->map(static fn ($id): int => (int) $id)->flip();
            $candidates = $candidates->reject(
                static fn (MapCell $cell): bool => $occupiedByMonster->has((int) $cell->id),
            )->values();
            if ($candidates->isEmpty()) {
                $metrics['natural_treasure_spawn_blocked']++;

                continue;
            }
            /** @var MapCell $cell */
            $cell = $candidates->get($context->random->stream(TurnRandomStreamFactory::naturalTreasure(
                $opportunity,
                'candidate',
                $version,
            ))->integer(0, $candidates->count() - 1));
            $this->create($context, $cell, 'natural', false);
            $metrics['natural_treasures_spawned']++;
        }

        return $metrics;
    }

    public function create(TurnContext $context, MapCell $cell, string $source, bool $premium): BuriedTreasure
    {
        $settings = $this->settings($context);
        $active = BuriedTreasure::query()->where('world_id', $context->world->id)
            ->where('map_cell_id', $cell->id)->where('state', BuriedTreasure::STATE_ACTIVE)
            ->orderBy('id')->lockForUpdate()->get();
        $maximum = (int) $settings['maximum_stack_per_cell'];
        while ($active->count() >= $maximum) {
            /** @var BuriedTreasure $oldest */
            $oldest = $active->shift();
            $this->removeLocked($oldest, $context->targetTurn, 'stack_overflow');
        }
        $snapshot = $settings[$premium ? 'premium_reward' : 'standard_reward'];
        $this->validateRewardSnapshot($snapshot, $premium);
        $treasure = BuriedTreasure::query()->create([
            'world_id' => $context->world->id,
            'map_cell_id' => $cell->id,
            'source' => $source,
            'reward_snapshot' => $snapshot,
            'created_turn' => $context->targetTurn,
            'state' => BuriedTreasure::STATE_ACTIVE,
        ]);
        $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
        $this->events->record($context, 'buried_treasure.created', $treasure, [
            'treasure_id' => (int) $treasure->id,
            'source' => $source,
            'premium' => $premium,
            'item_key' => (string) $snapshot['item_key'],
            'x' => (int) $cell->x,
            'y' => (int) $cell->y,
        ], $source === 'natural' ? 'admin' : 'public');

        return $treasure;
    }

    public function collectAtCell(TurnContext $context, MapCell $cell, Nation $nation, string $reason): int
    {
        $treasures = BuriedTreasure::query()->where('world_id', $context->world->id)
            ->where('map_cell_id', $cell->id)->where('state', BuriedTreasure::STATE_ACTIVE)
            ->orderBy('id')->lockForUpdate()->get();
        if ($treasures->isEmpty()) {
            return 0;
        }
        $membership = NationMembership::query()->where('world_id', $context->world->id)
            ->where('nation_id', $nation->id)->where('role', 'owner')->lockForUpdate()->sole();
        $secretary = Secretary::query()->where('user_id', $membership->user_id)->lockForUpdate()->sole();
        $required = 0;
        foreach ($treasures as $treasure) {
            $snapshot = $treasure->reward_snapshot;
            $this->validateRewardSnapshot($snapshot, ($snapshot['item_key'] ?? null) === SecretaryItemCatalog::DOKIDOKI_TICKET);
            $required += (int) $snapshot['quantity'];
        }
        if ($secretary->itemInstances()->count() + $required > SecretaryItemGrantService::INVENTORY_CAPACITY) {
            $this->events->record($context, 'buried_treasure.collection_failed', $cell, [
                'nation_id' => (int) $nation->id, 'x' => (int) $cell->x, 'y' => (int) $cell->y,
                'reason' => 'secretary_inventory_full', 'treasure_count' => $treasures->count(),
            ], 'nation', 'warning');

            return 0;
        }
        foreach ($treasures as $treasure) {
            $snapshot = $treasure->reward_snapshot;
            for ($quantity = 1; $quantity <= (int) $snapshot['quantity']; $quantity++) {
                $granted = $this->items->grant(
                    $secretary,
                    (string) $snapshot['item_key'],
                    1,
                    null,
                    'buried_treasure:'.$treasure->id.':'.$quantity,
                );
                if ($granted === null) {
                    throw new DomainException('Buried Treasure reward preflight diverged from the locked inventory.');
                }
            }
            $treasure->state = BuriedTreasure::STATE_COLLECTED;
            $treasure->resolved_by_nation_id = $nation->id;
            $treasure->resolved_turn = $context->targetTurn;
            $treasure->resolution_reason = 'collected';
            $treasure->resolved_at = now();
            $treasure->save();
        }
        $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
        $this->events->record($context, 'buried_treasure.collected', $cell, [
            'nation_id' => (int) $nation->id, 'x' => (int) $cell->x, 'y' => (int) $cell->y,
            'collection_reason' => $reason, 'treasure_count' => $treasures->count(),
            'item_count' => $required,
        ], 'nation');

        return $treasures->count();
    }

    /** @param list<int> $cellIds */
    public function removeForInitialIsland(array $cellIds, int $turn): int
    {
        if ($cellIds === []) {
            return 0;
        }
        $treasures = BuriedTreasure::query()->whereIn('map_cell_id', $cellIds)
            ->where('state', BuriedTreasure::STATE_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        foreach ($treasures as $treasure) {
            $this->removeLocked($treasure, $turn, 'initial_island_overwrite');
        }

        return $treasures->count();
    }

    public function snapshotRemoteReveals(TurnContext $context): int
    {
        $settings = $context->ruleset->settings['ocean_loop']['buried_treasure'] ?? null;
        if (! is_array($settings)) {
            return 0;
        }
        $treasures = BuriedTreasure::query()->where('world_id', $context->world->id)
            ->where('state', BuriedTreasure::STATE_ACTIVE)->orderBy('id')->lockForUpdate()->get();
        $nationIds = Nation::query()->where('world_id', $context->world->id)->where('state', 'active')
            ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id);
        $created = 0;
        foreach ($treasures as $treasure) {
            foreach ($nationIds as $nationId) {
                $probability = $settings['remote_reveal_probability'];
                $draw = $context->random->stream(TurnRandomStreamFactory::treasureReveal(
                    (int) $treasure->id,
                    $nationId,
                    (int) $settings['stream_version'],
                ))->integer(0, (int) $probability['denominator'] - 1);
                if ($draw >= (int) $probability['numerator']) {
                    continue;
                }
                BuriedTreasureReveal::query()->firstOrCreate([
                    'buried_treasure_id' => $treasure->id,
                    'nation_id' => $nationId,
                    'turn' => $context->targetTurn,
                ]);
                $created++;
            }
        }

        return $created;
    }

    private function removeLocked(BuriedTreasure $treasure, int $turn, string $reason): void
    {
        $treasure->state = BuriedTreasure::STATE_REMOVED;
        $treasure->resolved_turn = $turn;
        $treasure->resolution_reason = $reason;
        $treasure->resolved_at = now();
        $treasure->save();
    }

    /** @return array<string, mixed> */
    private function settings(TurnContext $context): array
    {
        $settings = $context->ruleset->settings['ocean_loop']['buried_treasure'] ?? null;
        if (! is_array($settings)) {
            throw new DomainException('The active Ruleset has no Buried Treasure contract.');
        }

        return $settings;
    }

    /** @param array<string, mixed> $snapshot */
    private function validateRewardSnapshot(array $snapshot, bool $premium): void
    {
        $expected = $premium
            ? [SecretaryItemCatalog::DOKIDOKI_TICKET, SecretaryItemCatalog::RARITY_HIGH_QUALITY, 1500]
            : [SecretaryItemCatalog::WAKUWAKU_TICKET, SecretaryItemCatalog::RARITY_REGULAR, 500];
        if (($snapshot['item_key'] ?? null) !== $expected[0]
            || ($snapshot['quantity'] ?? null) !== 1
            || ($snapshot['rarity'] ?? null) !== $expected[1]
            || ($snapshot['fixed_sale_price_money'] ?? null) !== $expected[2]) {
            throw new DomainException('Buried Treasure reward snapshot is invalid.');
        }
    }
}
