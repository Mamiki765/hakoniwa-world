<?php

namespace App\Application;

use App\Domain\Award\NationAwardCatalog;
use App\Models\MonsterDefinition;
use App\Models\Nation;
use App\Models\NationAward;
use App\Models\NationMonsterKillStat;
use App\Models\World;
use App\Services\AssetManifestResolver;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class PublicRankingAchievementProjection
{
    public function __construct(private readonly AssetManifestResolver $assets) {}

    /**
     * @param  Collection<int, Nation>  $nations
     * @return array<int, array{awards: list<array<string, mixed>>, monster_kills: array<string, mixed>|null}>
     */
    public function forWorld(World $world, Collection $nations): array
    {
        $nationIds = $nations->modelKeys();
        $projection = [];
        foreach ($nationIds as $nationId) {
            $projection[$nationId] = ['awards' => [], 'monster_kills' => null];
        }
        if ($nationIds === []) {
            return $projection;
        }

        $projection = $this->projectAwards($world, $nationIds, $projection);
        $projection = $this->projectMonsterKills($world, $nationIds, $projection);

        return $projection;
    }

    /**
     * @param  list<int>  $nationIds
     * @param  array<int, array{awards: list<array<string, mixed>>, monster_kills: array<string, mixed>|null}>  $projection
     * @return array<int, array{awards: list<array<string, mixed>>, monster_kills: array<string, mixed>|null}>
     */
    private function projectAwards(World $world, array $nationIds, array $projection): array
    {
        $rows = NationAward::query()
            ->where('world_id', $world->id)
            ->whereIn('nation_id', $nationIds)
            ->orderBy('awarded_turn')
            ->orderBy('id')
            ->get()
            ->groupBy('nation_id');
        /** @var array<string, array<string, mixed>> $assetCache */
        $assetCache = [];
        foreach ($nationIds as $nationId) {
            $grouped = $rows->get($nationId, collect())->groupBy('award_key');
            $awards = [];
            foreach ($grouped as $awardKey => $occurrences) {
                $definition = NationAwardCatalog::definition((string) $awardKey);
                if ($definition === null) {
                    continue;
                }
                $assetCache[$definition['asset_key']] ??= $this->assets->resolve(
                    $definition['asset_key'],
                    $definition['name'],
                );
                $award = [
                    'key' => (string) $awardKey,
                    'name' => $definition['name'],
                    'recurring' => $definition['recurring'],
                    'count' => $occurrences->count(),
                    'asset' => $assetCache[$definition['asset_key']],
                    'sort_order' => $definition['sort_order'],
                ];
                if ($definition['recurring']) {
                    $award['awarded_turns'] = $occurrences->pluck('awarded_turn')
                        ->map(static fn (mixed $turn): int => (int) $turn)
                        ->sort()
                        ->values()
                        ->all();
                }
                $awards[] = $award;
            }
            usort($awards, static fn (array $left, array $right): int => $left['sort_order'] <=> $right['sort_order']);
            $projection[$nationId]['awards'] = array_map(static function (array $award): array {
                unset($award['sort_order']);

                return $award;
            }, $awards);
        }

        return $projection;
    }

    /**
     * @param  list<int>  $nationIds
     * @param  array<int, array{awards: list<array<string, mixed>>, monster_kills: array<string, mixed>|null}>  $projection
     * @return array<int, array{awards: list<array<string, mixed>>, monster_kills: array<string, mixed>|null}>
     */
    private function projectMonsterKills(World $world, array $nationIds, array $projection): array
    {
        $rows = NationMonsterKillStat::query()
            ->where('world_id', $world->id)
            ->whereIn('nation_id', $nationIds)
            ->where('kill_count', '>', 0)
            ->with('definition')
            ->get()
            ->groupBy('nation_id');
        foreach ($nationIds as $nationId) {
            $species = [];
            foreach ($rows->get($nationId, collect()) as $stat) {
                $definition = $stat->definition;
                if (! $definition instanceof MonsterDefinition) {
                    throw new DomainException('Public monster achievement references a missing definition.');
                }
                $kind = $definition->source_metadata['kind'] ?? null;
                if (! is_int($kind) || $kind < 0 || $kind > 7) {
                    throw new DomainException('Public monster achievement requires stable source kind 0..7.');
                }
                $species[] = [
                    'key' => $definition->key,
                    'name' => $definition->name,
                    'kill_count' => $stat->kill_count,
                    'kind' => $kind,
                    'asset_key' => $definition->asset_key,
                ];
            }
            usort($species, static fn (array $left, array $right): int => $left['kind'] <=> $right['kind']);
            if ($species === []) {
                continue;
            }
            $display = $species[array_key_last($species)];
            $projection[$nationId]['monster_kills'] = [
                'total_count' => array_sum(array_column($species, 'kill_count')),
                'asset' => $this->assets->resolve($display['asset_key'], $display['name']),
                'species' => array_map(static fn (array $row): array => [
                    'key' => $row['key'],
                    'name' => $row['name'],
                    'kill_count' => $row['kill_count'],
                ], $species),
            ];
        }

        return $projection;
    }
}
