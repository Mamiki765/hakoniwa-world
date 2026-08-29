<?php

namespace App\Domain\Underground\Combat;

use InvalidArgumentException;

final class DeterministicEquipmentGenerator
{
    public function __construct(private readonly AlphaV1CombatRules $rules) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    public function generate(AlphaV1BuildCatalog $catalog, int $itemLevel, array $request): array
    {
        $equipment = $catalog->equipment();
        $slot = $request['slot'] ?? null;
        $weaponStyle = $request['weapon_style'] ?? null;
        $rarityKey = $request['rarity'] ?? null;
        $seed = $request['seed'] ?? null;
        if (! is_string($slot) || ! in_array($slot, AlphaV1CombatRules::EQUIPMENT_SLOTS, true)
            || ! is_string($weaponStyle) || ! in_array($weaponStyle, AlphaV1CombatRules::WEAPON_STYLES, true)
            || ! is_string($rarityKey) || ! is_int($seed)) {
            throw new InvalidArgumentException('Underground alpha-v1 equipment request is invalid.');
        }
        $this->rules->progressionScaleBps($itemLevel, $itemLevel);
        $rarity = $equipment['rarities'][$rarityKey] ?? null;
        $slotDefinition = $equipment['slots'][$slot] ?? null;
        $style = $equipment['weapon_style_bases'][$weaponStyle] ?? null;
        if (! is_array($rarity) || ! is_array($slotDefinition) || ! is_array($style)) {
            throw new InvalidArgumentException('Underground alpha-v1 equipment catalog entry is invalid.');
        }
        $affixCount = $rarity['affix_count'] ?? null;
        $qualityMin = $rarity['quality_min_bps'] ?? null;
        $qualityMax = $rarity['quality_max_bps'] ?? null;
        if (! is_int($affixCount) || $affixCount < 0 || ! is_int($qualityMin) || ! is_int($qualityMax)
            || $qualityMin < 1 || $qualityMax < $qualityMin || $qualityMax > 15_000) {
            throw new InvalidArgumentException('Underground alpha-v1 rarity definition is invalid.');
        }

        $random = new UndergroundRandom($seed);
        $scaleBps = $this->rules->progressionScaleBps($itemLevel, $itemLevel);
        $baseStats = $this->scaledMap($slotDefinition['base_stats'] ?? [], $scaleBps);
        if ($slot === 'weapon') {
            foreach ($this->scaledMap($style['base_stats'] ?? [], $scaleBps) as $key => $value) {
                $baseStats[$key] = ($baseStats[$key] ?? 0) + $value;
            }
        }
        $base = [
            'weapon_power' => $slot === 'weapon'
                ? max(1, intdiv((int) ($style['weapon_power'] ?? 0) * $scaleBps, 10_000))
                : 0,
            'physical_defense' => max(0, intdiv((int) ($slotDefinition['physical_defense'] ?? 0) * $scaleBps, 10_000)),
            'magical_defense' => max(0, intdiv((int) ($slotDefinition['magical_defense'] ?? 0) * $scaleBps, 10_000)),
            'max_hp' => max(0, intdiv((int) ($slotDefinition['max_hp'] ?? 0) * $scaleBps, 10_000)),
            'stats' => $baseStats,
        ];

        $eligible = [];
        foreach (($equipment['affixes'] ?? []) as $key => $definition) {
            if (! is_string($key) || ! is_array($definition) || $key === 'max_mp') {
                continue;
            }
            $slots = $definition['slots'] ?? null;
            if (is_array($slots) && in_array($slot, $slots, true)) {
                $eligible[$key] = $definition;
            }
        }
        if ($affixCount > count($eligible)) {
            throw new InvalidArgumentException('Underground alpha-v1 rarity requests too many eligible affixes.');
        }

        $affixes = [];
        for ($index = 0; $index < $affixCount; $index++) {
            $keys = array_keys($eligible);
            $selectedIndex = $random->integer("equipment:affix:{$index}", 0, count($keys) - 1);
            $key = $keys[$selectedIndex];
            $definition = $eligible[$key];
            unset($eligible[$key]);
            $quality = $random->integer("equipment:quality:{$index}", $qualityMin, $qualityMax);
            $minimum = $definition['minimum'] ?? null;
            $maximum = $definition['maximum'] ?? null;
            $cap = $definition['cap'] ?? null;
            $scales = $definition['scales_with_item_level'] ?? null;
            if (! is_int($minimum) || ! is_int($maximum) || ! is_int($cap)
                || ! is_bool($scales) || $minimum < 0 || $maximum < $minimum || $cap < $maximum) {
                throw new InvalidArgumentException("Underground alpha-v1 affix [{$key}] is invalid.");
            }
            $rolled = $random->integer("equipment:roll:{$index}", $minimum, $maximum);
            if ($scales) {
                $rolled = intdiv($rolled * $scaleBps, 10_000);
            } else {
                $itemTierBps = min(20_000, 10_000 + (($itemLevel - 1) * 100));
                $rolled = intdiv($rolled * $itemTierBps, 10_000);
            }
            $value = min($cap, max(0, intdiv($rolled * $quality, 10_000)));
            $affixes[] = [
                'key' => $key,
                'label' => (string) ($definition['label'] ?? $key),
                'kind' => (string) ($definition['kind'] ?? ''),
                'target' => (string) ($definition['target'] ?? ''),
                'value' => $value,
                'cap' => $cap,
            ];
        }

        $uniqueEffect = null;
        if (($rarity['unique_eligible'] ?? false) === true) {
            $eligibleUnique = [];
            foreach (($equipment['unique_effects'] ?? []) as $key => $definition) {
                if (! is_string($key) || ! is_array($definition)) {
                    continue;
                }
                $styles = $definition['weapon_styles'] ?? [];
                $slots = $definition['slots'] ?? [];
                if (is_array($styles) && is_array($slots)
                    && ($styles === [] || in_array($weaponStyle, $styles, true))
                    && in_array($slot, $slots, true)) {
                    $eligibleUnique[$key] = $definition;
                }
            }
            if ($eligibleUnique !== []) {
                $keys = array_keys($eligibleUnique);
                $key = $keys[$random->integer('equipment:unique', 0, count($keys) - 1)];
                $uniqueEffect = ['key' => $key, ...$eligibleUnique[$key]];
            }
        }

        $identityPayload = [
            'generator_identity' => AlphaV1CombatRules::GENERATOR_IDENTITY,
            'item_level' => $itemLevel,
            'slot' => $slot,
            'weapon_style' => $weaponStyle,
            'rarity' => $rarityKey,
            'seed' => $seed,
        ];
        $stableIdentity = hash('sha256', json_encode($identityPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $rarityLabel = (string) ($rarity['label'] ?? $rarityKey);
        $slotLabel = (string) ($slotDefinition['label'] ?? $slot);
        $styleLabel = (string) ($style['label'] ?? $weaponStyle);

        return [
            'identity' => $stableIdentity,
            'generator_identity' => AlphaV1CombatRules::GENERATOR_IDENTITY,
            'item_level' => $itemLevel,
            'rarity' => $rarityKey,
            'slot' => $slot,
            'weapon_style' => $weaponStyle,
            'base' => $base,
            'affixes' => $affixes,
            'unique_effect' => $uniqueEffect,
            'display' => [
                'name' => trim($rarityLabel.' '.$styleLabel.$slotLabel),
                'item_level' => 'アイテムLv '.$itemLevel,
                'rarity' => $rarityLabel,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{stats: array<string, int>, weapon_power: int, physical_defense: int, magical_defense: int, max_hp: int, modifiers: array<string, int|bool|string>, unique_effects: list<string>}
     */
    public function aggregate(array $items): array
    {
        $stats = array_fill_keys(AlphaV1CombatRules::STATS, 0);
        $result = [
            'stats' => $stats,
            'weapon_power' => 0,
            'physical_defense' => 0,
            'magical_defense' => 0,
            'max_hp' => 0,
            'modifiers' => [],
            'unique_effects' => [],
        ];
        foreach ($items as $item) {
            $base = $item['base'] ?? [];
            foreach (AlphaV1CombatRules::STATS as $key) {
                $result['stats'][$key] += (int) ($base['stats'][$key] ?? 0);
            }
            foreach (['weapon_power', 'physical_defense', 'magical_defense', 'max_hp'] as $key) {
                $result[$key] += (int) ($base[$key] ?? 0);
            }
            foreach (($item['affixes'] ?? []) as $affix) {
                if (! is_array($affix)) {
                    continue;
                }
                $kind = $affix['kind'] ?? null;
                $target = $affix['target'] ?? null;
                $value = $affix['value'] ?? null;
                if (! is_string($target) || ! is_int($value)) {
                    continue;
                }
                if ($kind === 'stat' && in_array($target, AlphaV1CombatRules::STATS, true)) {
                    $result['stats'][$target] += $value;
                } elseif ($kind === 'modifier') {
                    $result['modifiers'][$target] = (int) ($result['modifiers'][$target] ?? 0) + $value;
                } elseif ($kind === 'base' && array_key_exists($target, $result)) {
                    $result[$target] += $value;
                }
            }
            $unique = $item['unique_effect']['key'] ?? null;
            if (is_string($unique)) {
                $result['unique_effects'][] = $unique;
            }
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    private function scaledMap(mixed $values, int $scaleBps): array
    {
        if (! is_array($values)) {
            throw new InvalidArgumentException('Underground alpha-v1 equipment base stats are invalid.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! in_array($key, AlphaV1CombatRules::STATS, true) || ! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Underground alpha-v1 equipment base stats are invalid.');
            }
            $result[$key] = intdiv($value * $scaleBps, 10_000);
        }

        return $result;
    }
}
