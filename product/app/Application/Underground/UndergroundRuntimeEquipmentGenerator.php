<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\UndergroundRandom;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class UndergroundRuntimeEquipmentGenerator
{
    /**
     * @return array<string, mixed>
     */
    public function generate(
        int $itemLevel,
        string $tierKey,
        string $rarityKey,
        string $category,
        ?string $weaponStyle,
        ?string $mainStat,
        int $seed,
        string $sourceIdentity,
    ): array {
        $generator = $this->config();
        if ($itemLevel < $generator['item_level_min'] || $itemLevel > $generator['item_level_max']
            || ! in_array($category, ['weapon', 'armor', 'accessory'], true)
            || $seed < 0 || $seed > 2_147_483_647
            || $sourceIdentity === '' || strlen($sourceIdentity) > 200
            || preg_match('//u', $sourceIdentity) !== 1) {
            throw new InvalidArgumentException('Underground generated equipment input is invalid.');
        }
        $tier = $generator['tiers'][$tierKey] ?? null;
        $rarity = $generator['rarities'][$rarityKey] ?? null;
        if (! is_array($tier) || ! is_array($rarity) || $rarityKey === 'unique') {
            throw new InvalidArgumentException('Underground generated equipment tier or rarity is invalid.');
        }

        if ($category === 'weapon') {
            if (! is_string($weaponStyle)
                || ! in_array($weaponStyle, ['dagger', 'rapier', 'longsword', 'crystal_staff'], true)) {
                throw new InvalidArgumentException('Underground generated weapon style is invalid.');
            }
            $bodyKey = $weaponStyle;
            $name = $tier['weapon_names'][$weaponStyle] ?? null;
        } elseif ($category === 'armor') {
            if ($weaponStyle !== null || $mainStat !== null) {
                throw new InvalidArgumentException('Underground generated armor input is invalid.');
            }
            $bodyKey = 'armor';
            $name = $tier['armor_name'] ?? null;
        } else {
            if ($weaponStyle !== null || ! is_string($mainStat)
                || ! in_array($mainStat, AlphaV1CombatRules::STATS, true)) {
                throw new InvalidArgumentException('Underground generated accessory main stat is invalid.');
            }
            $bodyKey = 'accessory';
            $name = $tier['accessory_name'] ?? null;
        }
        if (! is_string($name) || $name === '') {
            throw new RuntimeException('Underground generated equipment name is invalid.');
        }

        $bodyDefinition = $generator['body_anchors'][$bodyKey] ?? null;
        if (! is_array($bodyDefinition)) {
            throw new RuntimeException('Underground generated equipment body is invalid.');
        }
        $base = $this->body($bodyDefinition, $itemLevel, $mainStat);
        $random = new UndergroundRandom($seed);
        $affixCount = $category === 'accessory'
            ? $this->accessoryAffixCount($random, $rarity)
            : (int) ($rarity['weapon_armor_slots'] ?? -1);
        $accessoryValueBps = $category === 'accessory'
            ? (int) ($rarity['accessory_value_bps'] ?? -1)
            : 10_000;
        if ($affixCount < 0 || $affixCount > 4
            || $accessoryValueBps < 1 || $accessoryValueBps > 10_000) {
            throw new RuntimeException('Underground generated equipment rarity contract is invalid.');
        }

        $eligible = $generator['affixes'] ?? null;
        if (! is_array($eligible) || count($eligible) < $affixCount) {
            throw new RuntimeException('Underground generated equipment affix pool is invalid.');
        }
        $qualityMin = $generator['quality_min_bps'] ?? null;
        $qualityMax = $generator['quality_max_bps'] ?? null;
        if (! is_int($qualityMin) || ! is_int($qualityMax)
            || $qualityMin < 1 || $qualityMax < $qualityMin || $qualityMax > 10_000) {
            throw new RuntimeException('Underground generated equipment quality range is invalid.');
        }

        $affixes = [];
        for ($index = 0; $index < $affixCount; $index++) {
            $keys = array_keys($eligible);
            $selected = $random->integer("affix:key:{$index}", 0, count($keys) - 1);
            $key = $keys[$selected];
            $definition = $eligible[$key];
            unset($eligible[$key]);
            if (! is_array($definition)) {
                throw new RuntimeException('Underground generated equipment affix definition is invalid.');
            }
            $quality = $random->integer("affix:quality:{$index}", $qualityMin, $qualityMax);
            $affixes[] = $this->rollAffix(
                $random,
                $index,
                $key,
                $definition,
                $itemLevel,
                $quality,
                $accessoryValueBps,
            );
        }

        $stats = $base['stats'];
        $modifiers = [];
        $weaponPower = $base['weapon_power'];
        $physicalDefense = $base['physical_defense'];
        $magicalDefense = $base['magical_defense'];
        $maxHp = $base['max_hp'];
        foreach ($affixes as $affix) {
            $target = $affix['target'];
            $value = $affix['value'];
            if ($affix['kind'] === 'stat') {
                $stats[$target] += $value;
            } elseif ($affix['kind'] === 'modifier') {
                $modifiers[$target] = ($modifiers[$target] ?? 0) + $value;
            } elseif ($target === 'max_hp') {
                $maxHp += $value;
            } elseif ($target === 'physical_defense') {
                $physicalDefense += $value;
            } elseif ($target === 'magical_defense') {
                $magicalDefense += $value;
            }
        }

        $identityPayload = [
            'generator_identity' => $generator['identity'],
            'item_level' => $itemLevel,
            'tier' => $tierKey,
            'rarity' => $rarityKey,
            'category' => $category,
            'weapon_style' => $weaponStyle,
            'main_stat' => $mainStat,
            'seed' => $seed,
            'source_identity' => $sourceIdentity,
        ];
        try {
            $encoded = json_encode($identityPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Underground generated equipment identity failed.', previous: $exception);
        }
        $identity = hash('sha256', $encoded);
        $sellPriceBps = $generator['sell_price_bps'] ?? null;
        if (! is_int($sellPriceBps) || $sellPriceBps < 1 || $sellPriceBps > 10_000) {
            throw new RuntimeException('Underground generated equipment sell rate is invalid.');
        }
        $sellPrice = $this->sellPrice($category, $itemLevel, $sellPriceBps);
        $rarityLabel = $rarity['label'] ?? null;
        if (! is_string($rarityLabel) || $rarityLabel === '') {
            throw new RuntimeException('Underground generated equipment rarity label is invalid.');
        }

        return [
            'key' => "generated:{$tierKey}:{$bodyKey}",
            'name' => $name,
            'category' => $category,
            'weapon_style' => $weaponStyle,
            'rank' => 0,
            'item_level' => $itemLevel,
            'rarity' => $rarityKey,
            'rarity_label' => $rarityLabel,
            'buy_price' => null,
            'shop_sold' => false,
            'sellable' => true,
            'required_trial_key' => null,
            'sell_price' => $sellPrice,
            'weapon_power' => $weaponPower,
            'physical_defense' => $physicalDefense,
            'magical_defense' => $magicalDefense,
            'max_hp' => $maxHp,
            'stats' => $stats,
            'modifiers' => $modifiers,
            'affixes' => $affixes,
            'unique_effect' => null,
            'base' => $base,
            'instance_identity' => $identity,
            'generator_identity' => $generator['identity'],
            'source' => [
                'tier_key' => $tierKey,
                'seed' => $seed,
                'identity' => $sourceIdentity,
            ],
        ];
    }

    /** @param array<string, mixed> $rarity */
    private function accessoryAffixCount(UndergroundRandom $random, array $rarity): int
    {
        $slots = $rarity['accessory_slots'] ?? null;
        $presence = $rarity['accessory_presence_bps'] ?? null;
        if (! is_int($slots) || ! is_int($presence) || $slots < 0 || $slots > 2
            || $presence < 0 || $presence > 10_000) {
            throw new RuntimeException('Underground generated accessory rarity contract is invalid.');
        }
        $count = 0;
        for ($index = 0; $index < $slots; $index++) {
            if ($random->integer("affix:presence:{$index}", 1, 10_000) <= $presence) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, int|string>
     */
    private function rollAffix(
        UndergroundRandom $random,
        int $index,
        string $key,
        array $definition,
        int $itemLevel,
        int $qualityBps,
        int $accessoryValueBps,
    ): array {
        $label = $definition['label'] ?? null;
        $kind = $definition['kind'] ?? null;
        $target = $definition['target'] ?? null;
        if (! is_string($label) || $label === '' || ! is_string($target) || $target === ''
            || ! in_array($kind, ['stat', 'modifier', 'base'], true)) {
            throw new RuntimeException("Underground generated equipment affix [{$key}] is invalid.");
        }

        $rollAudit = [];
        if ($kind === 'stat') {
            $standard = 1 + intdiv($itemLevel, 10);
            $value = $this->roundHalfUp($standard * $qualityBps * $accessoryValueBps, 100_000_000);
            $rollAudit['standard_value'] = $standard;
        } elseif ($kind === 'modifier') {
            $minimum = $definition['minimum'] ?? null;
            $maximum = $definition['maximum'] ?? null;
            if (! is_int($minimum) || ! is_int($maximum) || $minimum < 1 || $maximum < $minimum) {
                throw new RuntimeException("Underground generated equipment modifier affix [{$key}] is invalid.");
            }
            $raw = $random->integer("affix:value:{$index}", $minimum, $maximum);
            $itemLevelBps = min(20_000, 10_000 + (($itemLevel - 1) * 100));
            $numerator = $raw * $itemLevelBps * $qualityBps * $accessoryValueBps;
            $denominator = 1_000_000_000_000;
            $scaledCapNumerator = $maximum * $itemLevelBps * $accessoryValueBps;
            $scaledCapDenominator = 100_000_000;
            $value = min(
                $this->roundHalfUp($numerator, $denominator),
                $this->roundHalfUp($scaledCapNumerator, $scaledCapDenominator),
            );
            $rollAudit['raw_value'] = $raw;
            $rollAudit['item_level_bps'] = $itemLevelBps;
        } else {
            $standard = $target === 'max_hp'
                ? 10 + (2 * $itemLevel)
                : 2 + intdiv($itemLevel, 5);
            $value = $this->roundHalfUp($standard * $qualityBps * $accessoryValueBps, 100_000_000);
            $rollAudit['standard_value'] = $standard;
        }

        return [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'target' => $target,
            'value' => max(1, $value),
            'quality_bps' => $qualityBps,
            ...$rollAudit,
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{weapon_power: int, physical_defense: int, magical_defense: int, max_hp: int, stats: array<string, int>}
     */
    private function body(array $definition, int $itemLevel, ?string $mainStat): array
    {
        $stats = array_fill_keys(AlphaV1CombatRules::STATS, 0);
        foreach (($definition['stats'] ?? []) as $stat => $anchors) {
            if (! in_array($stat, AlphaV1CombatRules::STATS, true) || ! is_array($anchors)) {
                throw new RuntimeException('Underground generated equipment stat anchors are invalid.');
            }
            $stats[$stat] = $this->interpolate($anchors, $itemLevel);
        }
        if ($definition['category'] === 'accessory') {
            if (! is_string($mainStat)) {
                throw new RuntimeException('Underground generated accessory main stat is missing.');
            }
            $stats[$mainStat] = $this->interpolate($definition['main_stat'], $itemLevel);
        }

        return [
            'weapon_power' => $this->interpolate($definition['weapon_power'] ?? [1 => 0, 60 => 0], $itemLevel),
            'physical_defense' => $this->interpolate($definition['physical_defense'] ?? [1 => 0, 60 => 0], $itemLevel),
            'magical_defense' => $this->interpolate($definition['magical_defense'] ?? [1 => 0, 60 => 0], $itemLevel),
            'max_hp' => $this->interpolate($definition['max_hp'] ?? [1 => 0, 60 => 0], $itemLevel),
            'stats' => $stats,
        ];
    }

    /** @param array<mixed, mixed> $anchors */
    private function interpolate(array $anchors, int $itemLevel): int
    {
        $validated = [];
        foreach ($anchors as $level => $value) {
            if (! is_int($level) || ! is_int($value)) {
                throw new RuntimeException('Underground generated equipment anchor is invalid.');
            }
            $validated[$level] = $value;
        }
        ksort($validated, SORT_NUMERIC);
        if (array_key_exists($itemLevel, $validated)) {
            return $validated[$itemLevel];
        }
        $lowerLevel = null;
        $upperLevel = null;
        foreach (array_keys($validated) as $level) {
            if ($level < $itemLevel) {
                $lowerLevel = $level;
            } elseif ($level > $itemLevel) {
                $upperLevel = $level;
                break;
            }
        }
        if (! is_int($lowerLevel) || ! is_int($upperLevel)) {
            throw new RuntimeException('Underground generated equipment item level cannot be extrapolated.');
        }
        $range = $upperLevel - $lowerLevel;
        $numerator = ($validated[$lowerLevel] * $range)
            + (($validated[$upperLevel] - $validated[$lowerLevel]) * ($itemLevel - $lowerLevel));

        return $this->roundHalfUp($numerator, $range);
    }

    private function sellPrice(string $category, int $itemLevel, int $sellPriceBps): int
    {
        $anchors = match ($category) {
            'weapon' => [1 => 120, 10 => 360, 20 => 1_000, 40 => 3_000, 60 => 6_000],
            'armor' => [1 => 100, 10 => 300, 20 => 900, 40 => 2_700, 60 => 5_400],
            'accessory' => [1 => 60, 10 => 180, 20 => 600, 40 => 1_800, 60 => 3_600],
            default => throw new RuntimeException('Underground generated equipment category is invalid.'),
        };
        $buyEquivalent = $this->interpolate($anchors, $itemLevel);

        return max(1, $this->roundHalfUp($buyEquivalent * $sellPriceBps, 10_000));
    }

    private function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator < 0 || $denominator < 1) {
            throw new RuntimeException('Underground generated equipment rounding input is invalid.');
        }

        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        $config = config('underground-equipment.generator');
        if (! is_array($config)
            || ($config['identity'] ?? null) !== 'secretary-underground-drop-equipment-alpha-v1') {
            throw new RuntimeException('Underground generated equipment configuration is invalid.');
        }

        return $config;
    }
}
