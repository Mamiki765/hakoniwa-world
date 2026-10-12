<?php

namespace App\Application;

use App\Domain\Monster\MonsterBehaviorResolver;
use App\Models\MapSpace;
use App\Models\MonsterInstance;
use App\Models\Nation;
use App\Models\World;
use App\Services\MapCellPresenter;

final class WorldMonsterCountSnapshot
{
    public function __construct(private readonly MonsterBehaviorResolver $behaviors) {}

    /**
     * Called after lifecycle removals, inside the official Turn transaction.
     * Sea monsters are a subset of the World total and never inferred island residents.
     *
     * @return array<string, int>
     */
    public function record(World $world, int $targetTurn): array
    {
        $nationCounts = array_fill_keys(Nation::query()->where('world_id', $world->id)
            ->whereIn('state', ['active', 'dormant', 'recovery'])->orderBy('id')->pluck('id')->all(), 0);
        $monsters = MonsterInstance::query()->where('world_id', $world->id)->where('state', 'alive')
            ->whereHas('occupancy.cell', fn ($query) => $query->whereIn('map_space_id', MapSpace::query()
                ->select('id')->where('world_id', $world->id)->where('key', config('hakoniwa.world.map_space_key'))))
            ->with(['definition', 'occupancy.cell.terrain', 'occupancy.cell.facility'])->orderBy('id')->get();
        $seaCount = 0;
        $resolved = [];
        foreach ($monsters as $monster) {
            $definition = $monster->definition;
            $behavior = $resolved[$definition->id] ??= $this->behaviors->forDefinition($definition);
            if ($behavior->movement === MonsterBehaviorResolver::WATER_NEUTRALIZING) {
                $seaCount++;

                continue;
            }
            $cell = $monster->occupancy?->cell;
            if ($cell !== null) {
                $ownerId = MapCellPresenter::visibleState($cell, null)['owner_nation_id'];
                if ($ownerId !== null && array_key_exists($ownerId, $nationCounts)) {
                    $nationCounts[$ownerId]++;
                }
            }
        }
        $world->update(['monster_count_snapshot' => [
            'counted_turn' => $targetTurn,
            'total_alive_count' => $monsters->count(),
            'land_alive_count' => $monsters->count() - $seaCount,
            'sea_alive_count' => $seaCount,
            'nation_counts' => $nationCounts,
        ]]);

        return ['monster_counted_turn' => $targetTurn, 'monsters_alive' => $monsters->count(), 'sea_monsters_alive' => $seaCount];
    }

    /** @return array{counted_turn: int, total_alive_count: int, land_alive_count: int, sea_alive_count: int}|null */
    public function forWorld(World $world): ?array
    {
        $snapshot = $world->monster_count_snapshot;

        return $snapshot === null ? null : [
            'counted_turn' => $snapshot['counted_turn'],
            'total_alive_count' => $snapshot['total_alive_count'],
            'land_alive_count' => $snapshot['land_alive_count'],
            'sea_alive_count' => $snapshot['sea_alive_count'],
        ];
    }

    /** @return array{alive_monster_count: int|null, monster_counted_turn: int|null} */
    public function forNation(World $world, int $nationId): array
    {
        $snapshot = $world->monster_count_snapshot;

        return [
            'alive_monster_count' => $snapshot['nation_counts'][$nationId] ?? null,
            'monster_counted_turn' => $snapshot['counted_turn'] ?? null,
        ];
    }
}
