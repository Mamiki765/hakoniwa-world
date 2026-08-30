<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatRules;
use InvalidArgumentException;
use RuntimeException;

final class UndergroundEquipmentCatalog
{
    public const STARTER_KEY = 'starter_knife';

    public function identity(): string
    {
        $identity = $this->data()['catalog_identity'] ?? null;

        return is_string($identity) && $identity !== ''
            ? $identity
            : throw new RuntimeException('Underground equipment catalog identity is invalid.');
    }

    public function vaultCapacity(): int
    {
        $capacity = $this->data()['vault_capacity'] ?? null;

        return $capacity === 500
            ? $capacity
            : throw new RuntimeException('Underground vault capacity must be exactly 500.');
    }

    public function pageSize(): int
    {
        $size = $this->data()['page_size'] ?? null;

        return is_int($size) && $size >= 50 && $size <= 100
            ? $size
            : throw new RuntimeException('Underground vault page size is invalid.');
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        $definitions = $this->data()['definitions'] ?? null;
        if (! is_array($definitions)) {
            throw new RuntimeException('Underground equipment definitions are invalid.');
        }
        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || ! is_array($definition) || ($definition['key'] ?? null) !== $key) {
                throw new RuntimeException('Underground equipment definition identity is invalid.');
            }
            $this->assertDefinition($definition);
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    public function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;

        return is_array($definition)
            ? $definition
            : throw new InvalidArgumentException("Unknown Underground equipment [{$key}].");
    }

    /** @return list<array<string, mixed>> */
    public function shopDefinitions(): array
    {
        return array_values(array_filter(
            $this->definitions(),
            static fn (array $definition): bool => $definition['shop_sold'] === true,
        ));
    }

    /** @param array<string, mixed> $definition */
    public function sellPrice(array $definition): int
    {
        if ($definition['sellable'] !== true) {
            return 0;
        }
        $price = $definition['buy_price'] ?? null;

        return is_int($price) && $price >= 0
            ? intdiv($price, 2)
            : throw new RuntimeException('Underground equipment buy price is invalid.');
    }

    /**
     * @param  list<array<string, mixed>>  $equippedDefinitions
     * @return array<string, mixed>
     */
    public function combatLoadout(array $equippedDefinitions): array
    {
        $byCategory = [];
        $stats = array_fill_keys(AlphaV1CombatRules::STATS, 0);
        $physicalDefense = 0;
        $magicalDefense = 0;
        $maxHp = 0;
        foreach ($equippedDefinitions as $definition) {
            $this->assertDefinition($definition);
            $category = $definition['category'];
            if (isset($byCategory[$category])) {
                throw new RuntimeException('Underground equipment slot contains multiple items.');
            }
            $byCategory[$category] = $definition;
            $physicalDefense += $definition['physical_defense'];
            $magicalDefense += $definition['magical_defense'];
            $maxHp += $definition['max_hp'];
            foreach (AlphaV1CombatRules::STATS as $stat) {
                $stats[$stat] += $definition['stats'][$stat];
            }
        }
        $weapon = $byCategory['weapon'] ?? null;
        if (! is_array($weapon)) {
            throw new RuntimeException('Underground weapon slot cannot be empty.');
        }

        return [
            'key' => $weapon['key'],
            'label' => $weapon['name'],
            'catalog_identity' => $this->identity(),
            'item_level' => $weapon['item_level'],
            'rarity' => $weapon['rarity'],
            'weapon_style' => $weapon['weapon_style'],
            'weapon_power' => $weapon['weapon_power'],
            'physical_defense' => $physicalDefense,
            'magical_defense' => $magicalDefense,
            'max_hp' => $maxHp,
            'stats' => $stats,
            'modifiers' => [],
            'affixes' => [],
            'unique_effect' => null,
            'items' => array_values(array_map(
                static fn (array $definition): array => [
                    'key' => $definition['key'],
                    'name' => $definition['name'],
                    'category' => $definition['category'],
                    'rank' => $definition['rank'],
                    'item_level' => $definition['item_level'],
                ],
                $byCategory,
            )),
        ];
    }

    /** @param array<string, mixed> $definition */
    private function assertDefinition(array $definition): void
    {
        $category = $definition['category'] ?? null;
        $style = $definition['weapon_style'] ?? null;
        if (! is_string($definition['key'] ?? null) || $definition['key'] === ''
            || ! is_string($definition['name'] ?? null) || $definition['name'] === ''
            || ! in_array($category, ['weapon', 'armor', 'accessory'], true)
            || ($category === 'weapon' && ! in_array($style, ['dagger', 'rapier', 'longsword', 'crystal_staff'], true))
            || ($category !== 'weapon' && $style !== null)
            || ! is_int($definition['rank'] ?? null) || $definition['rank'] < 0 || $definition['rank'] > 3
            || ! is_int($definition['item_level'] ?? null) || $definition['item_level'] < 1
            || ($definition['rarity'] ?? null) !== 'common'
            || (! is_null($definition['buy_price'] ?? null) && (! is_int($definition['buy_price']) || $definition['buy_price'] < 1))
            || ! is_bool($definition['shop_sold'] ?? null)
            || ! is_bool($definition['sellable'] ?? null)
            || ! is_int($definition['weapon_power'] ?? null) || $definition['weapon_power'] < 0
            || ! is_int($definition['physical_defense'] ?? null) || $definition['physical_defense'] < 0
            || ! is_int($definition['magical_defense'] ?? null) || $definition['magical_defense'] < 0
            || ! is_int($definition['max_hp'] ?? null) || $definition['max_hp'] < 0
            || ! is_array($definition['stats'] ?? null)
            || array_keys($definition['stats']) !== AlphaV1CombatRules::STATS
            || ($definition['modifiers'] ?? null) !== []
            || ($definition['affixes'] ?? null) !== []
            || ! array_key_exists('unique_effect', $definition)
            || $definition['unique_effect'] !== null) {
            throw new RuntimeException('Underground equipment definition is invalid.');
        }
        foreach ($definition['stats'] as $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Underground equipment stat is invalid.');
            }
        }
        if ($definition['shop_sold'] === true && $definition['buy_price'] === null) {
            throw new RuntimeException('Underground shop equipment price is missing.');
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-equipment');
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Underground equipment configuration is invalid.');
        }

        return $data;
    }
}
