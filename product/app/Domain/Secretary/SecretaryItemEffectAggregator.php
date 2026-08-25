<?php

namespace App\Domain\Secretary;

use App\Domain\Turn\TurnState;
use App\Models\Nation;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates only Secretary Item effects. Item percentages add here; callers
 * multiply the resulting item genre with independent source genres.
 */
final class SecretaryItemEffectAggregator
{
    public function __construct(
        private readonly SecretaryItemCatalog $catalog,
        private readonly SecretaryItemGameplayContract $gameplay,
    ) {}

    /**
     * @return array{all_nation_resources: int, money: int, food_aggregate: int}
     */
    public function currentCapacityPercentages(Nation $nation, RulesetVersion $ruleset): array
    {
        $totals = $this->emptyCapacityPercentages();
        foreach ($this->currentEffects($nation, $ruleset) as $resolved) {
            $effect = $resolved['effect'];
            if ($effect['type'] !== SecretaryItemGameplayContract::CAPACITY_PERCENT) {
                continue;
            }
            $parameters = $effect['parameters'];
            $target = $parameters['target'] ?? null;
            $percentPerLevel = $parameters['percent_per_level'] ?? null;
            if (($parameters['source_genre'] ?? null) !== SecretaryItemGameplayContract::SOURCE_GENRE_ITEM
                || ! is_string($target) || ! array_key_exists($target, $totals)
                || ! is_int($percentPerLevel)) {
                throw new DomainException('Secretary Item capacity snapshot is invalid.');
            }
            $totals[$target] += $resolved['level'] * $percentPerLevel;
        }

        return $totals;
    }

    public function snapshotPercentage(
        TurnState $state,
        int $nationId,
        string $effectType,
        string $target,
    ): int {
        $total = 0;
        foreach ($this->snapshotEffects($state, $nationId, $effectType) as $resolved) {
            $parameters = $resolved['effect']['parameters'];
            if (($parameters['source_genre'] ?? null) !== SecretaryItemGameplayContract::SOURCE_GENRE_ITEM
                || ($parameters['target'] ?? null) !== $target
                || ! is_int($parameters['percent_per_level'] ?? null)) {
                throw new DomainException('Secretary Item percentage snapshot is invalid.');
            }
            $total += $resolved['level'] * $parameters['percent_per_level'];
        }

        return $total;
    }

    public function snapshotKarmaMinimumDelta(TurnState $state, int $nationId): int
    {
        $total = 0;
        foreach ($this->snapshotEffects(
            $state,
            $nationId,
            SecretaryItemGameplayContract::KARMA_MINIMUM_DELTA,
        ) as $resolved) {
            $perLevel = $resolved['effect']['parameters']['lower_minimum_per_level'] ?? null;
            if (! is_int($perLevel) || $perLevel < 1) {
                throw new DomainException('Secretary Item Karma minimum snapshot is invalid.');
            }
            $total += $resolved['level'] * $perLevel;
        }

        return $total;
    }

    /** @return array{chance_percent: int, multiplier: int, random_stream_version: int}|null */
    public function snapshotExperienceDouble(TurnState $state, int $nationId, string $source): ?array
    {
        $matches = $this->snapshotEffects(
            $state,
            $nationId,
            SecretaryItemGameplayContract::EXPERIENCE_DOUBLE_CHANCE,
        );
        if ($matches === []) {
            return null;
        }
        if (count($matches) !== 1) {
            throw new DomainException('Secretary experience equipment must resolve to one clothing effect.');
        }
        $resolved = $matches[0];
        $parameters = $resolved['effect']['parameters'];
        $sources = $parameters['sources'] ?? null;
        $chancePerLevel = $parameters['chance_percent_per_level'] ?? null;
        $multiplier = $parameters['multiplier'] ?? null;
        $version = $resolved['effect']['random_stream_version'] ?? null;
        if (! is_array($sources) || ! in_array($source, $sources, true)
            || ! is_int($chancePerLevel) || $chancePerLevel < 1
            || ! is_int($multiplier) || $multiplier < 2
            || ! is_int($version) || $version < 1) {
            throw new DomainException('Secretary experience equipment snapshot is invalid.');
        }

        return [
            'chance_percent' => $resolved['level'] * $chancePerLevel,
            'multiplier' => $multiplier,
            'random_stream_version' => $version,
        ];
    }

    /**
     * @return list<array{item_key: string, level: int, effect: array<string, mixed>}>
     */
    private function currentEffects(Nation $nation, RulesetVersion $ruleset): array
    {
        $rows = DB::table('nation_memberships as membership')
            ->join('secretaries as secretary', 'secretary.user_id', '=', 'membership.user_id')
            ->join('secretary_item_instances as item', 'item.secretary_id', '=', 'secretary.id')
            ->where('membership.nation_id', $nation->id)
            ->where('membership.world_id', $nation->world_id)
            ->where('membership.role', 'owner')
            ->whereNotNull('item.equipped_slot')
            ->where('item.is_escrowed', false)
            ->orderBy('item.equipped_slot')
            ->orderBy('item.id')
            ->get(['item.item_key', 'item.level']);
        $resolved = [];
        foreach ($rows as $row) {
            $itemKey = (string) $row->item_key;
            $level = (int) $row->level;
            $definition = $this->catalog->definition($itemKey);
            if ($level < 1 || $level > $definition['max_level']) {
                throw new DomainException("Secretary Item {$itemKey} has an invalid equipped level.");
            }
            foreach ($this->gameplay->resolvedEffects($ruleset->settings, $itemKey, $level) as $effect) {
                $resolved[] = ['item_key' => $itemKey, 'level' => $level, 'effect' => $effect];
            }
        }

        return $resolved;
    }

    /**
     * @return list<array{item_key: string, level: int, effect: array<string, mixed>}>
     */
    private function snapshotEffects(TurnState $state, int $nationId, string $effectType): array
    {
        if (! $state->hasSecretaryItemEffectSnapshot($nationId)) {
            return [];
        }
        $resolved = [];
        foreach ($state->secretaryItemEffectSnapshot($nationId)['items'] as $item) {
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] === $effectType) {
                    $resolved[] = [
                        'item_key' => $item['item_key'],
                        'level' => $item['level'],
                        'effect' => $effect,
                    ];
                }
            }
        }

        return $resolved;
    }

    /** @return array{all_nation_resources: int, money: int, food_aggregate: int} */
    private function emptyCapacityPercentages(): array
    {
        return [
            SecretaryItemGameplayContract::CAPACITY_ALL_RESOURCES => 0,
            SecretaryItemGameplayContract::CAPACITY_MONEY => 0,
            SecretaryItemGameplayContract::CAPACITY_FOOD => 0,
        ];
    }
}
