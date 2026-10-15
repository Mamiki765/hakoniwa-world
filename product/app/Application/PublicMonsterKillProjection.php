<?php

namespace App\Application;

use App\Domain\Monster\MonsterDisplayOrderResolver;
use App\Models\MonsterDefinition;
use App\Models\NationMonsterKillStat;
use App\Models\World;
use DomainException;

final class PublicMonsterKillProjection
{
    public function __construct(
        private readonly MonsterDisplayOrderResolver $monsterDisplayOrders,
    ) {}

    /**
     * @param  list<int>  $nationIds
     * @return array<int, list<array{key: string, name: string, asset_key: string, display_order: int, kill_count: int, first_killed_turn: int, last_killed_turn: int}>>
     */
    public function forWorld(World $world, array $nationIds): array
    {
        $projection = array_fill_keys($nationIds, []);
        if ($nationIds === []) {
            return $projection;
        }
        $rows = NationMonsterKillStat::query()
            ->where('world_id', $world->id)
            ->whereIn('nation_id', $nationIds)
            ->where('kill_count', '>', 0)
            ->get();
        if ($rows->isEmpty()) {
            return $projection;
        }
        // Resolve historical references and the World's display catalog in one batch.
        $definitions = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->orWhereIn('id', $rows->pluck('monster_definition_id'))
            ->get();
        $byId = $definitions->keyBy('id');
        $currentByKey = $definitions->where('ruleset_version_id', $world->ruleset_version_id)->keyBy('key');

        foreach ($rows->groupBy('nation_id') as $nationId => $stats) {
            $species = [];
            $displayDefinitions = [];
            foreach ($stats as $stat) {
                $source = $byId->get($stat->monster_definition_id);
                if (! $source instanceof MonsterDefinition) {
                    throw new DomainException('Public monster statistic references a missing definition.');
                }
                $definition = $currentByKey->get($source->key);
                if (! $definition instanceof MonsterDefinition) {
                    throw new DomainException('Public monster statistic has no definition in the World Ruleset.');
                }
                $displayDefinitions[$definition->id] = $definition;
                $species[$definition->key] ??= [
                    'key' => $definition->key,
                    'name' => $definition->name,
                    'asset_key' => $definition->asset_key,
                    'display_order' => $this->monsterDisplayOrders->forDefinition($definition),
                    'kill_count' => 0,
                    'first_killed_turn' => $stat->first_killed_turn,
                    'last_killed_turn' => $stat->last_killed_turn,
                ];
                $row = &$species[$definition->key];
                $row['kill_count'] += $stat->kill_count;
                $row['first_killed_turn'] = min($row['first_killed_turn'], $stat->first_killed_turn);
                $row['last_killed_turn'] = max($row['last_killed_turn'], $stat->last_killed_turn);
                unset($row);
            }
            // Check distinct species after combining definitions of the same stable key.
            $this->monsterDisplayOrders->uniqueOrders($displayDefinitions);
            usort($species, static function (array $left, array $right): int {
                $byOrder = $left['display_order'] <=> $right['display_order'];

                return $byOrder !== 0 ? $byOrder : $left['key'] <=> $right['key'];
            });
            $projection[$nationId] = $species;
        }

        return $projection;
    }
}
