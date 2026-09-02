<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatRules;
use InvalidArgumentException;
use RuntimeException;

final class UndergroundEquipmentCatalog
{
    public const STARTER_KEY = 'starter_knife';

    /** @var list<string> */
    public const EQUIPPED_SLOTS = [
        'weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3',
    ];

    /** @var list<string> */
    public const ACCESSORY_SLOTS = [
        'accessory_1', 'accessory_2', 'accessory_3',
    ];

    /** @var list<string> */
    public const MODIFIER_KEYS = [
        'physical_damage_bps',
        'miracle_damage_bps',
        'healing_bps',
        'barrier_bps',
        'critical_chance_bps',
        'critical_damage_bps',
        'mp_cost_reduction_bps',
    ];

    public function identity(): string
    {
        $identity = $this->data()['catalog_identity'] ?? null;

        return is_string($identity) && $identity !== ''
            ? $identity
            : throw new RuntimeException('Underground equipment catalog identity is invalid.');
    }

    /** @return list<string> */
    public function supportedIdentities(): array
    {
        $legacy = array_keys($this->data()['legacy_catalogs'] ?? []);
        foreach ($legacy as $identity) {
            if (! is_string($identity) || $identity === '') {
                throw new RuntimeException('Underground legacy equipment catalog identity is invalid.');
            }
        }

        return [$this->identity(), ...$legacy];
    }

    public function supportsIdentity(string $identity): bool
    {
        return in_array($identity, $this->supportedIdentities(), true);
    }

    public function generatorIdentity(): string
    {
        $identity = $this->data()['generator']['identity'] ?? null;

        return is_string($identity) && $identity !== ''
            ? $identity
            : throw new RuntimeException('Underground equipment generator identity is invalid.');
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
    public function definitions(?string $catalogIdentity = null): array
    {
        $identity = $catalogIdentity ?? $this->identity();
        $data = $this->data();
        $definitions = $identity === $this->identity()
            ? ($data['definitions'] ?? null)
            : ($data['legacy_catalogs'][$identity] ?? null);
        if (! is_array($definitions)) {
            throw new InvalidArgumentException("Unknown Underground equipment catalog [{$identity}].");
        }
        foreach ($definitions as $key => $definition) {
            if (! is_string($key) || ! is_array($definition) || ($definition['key'] ?? null) !== $key) {
                throw new RuntimeException('Underground equipment definition identity is invalid.');
            }
            $this->assertDefinition($definition, false);
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    public function definition(string $key, ?string $catalogIdentity = null): array
    {
        $definition = $this->definitions($catalogIdentity)[$key] ?? null;

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
        if (isset($definition['sell_price'])) {
            return is_int($definition['sell_price']) && $definition['sell_price'] >= 1
                ? $definition['sell_price']
                : throw new RuntimeException('Underground generated equipment sell price is invalid.');
        }
        $price = $definition['buy_price'] ?? null;

        return is_int($price) && $price >= 0
            ? intdiv($price, 2)
            : throw new RuntimeException('Underground equipment buy price is invalid.');
    }

    /**
     * @param  list<array<string, mixed>>  $equipped
     * @return array<string, mixed>
     */
    public function combatLoadout(array $equipped): array
    {
        $bySlot = [];
        $stats = array_fill_keys(AlphaV1CombatRules::STATS, 0);
        $physicalDefense = 0;
        $magicalDefense = 0;
        $maxHp = 0;
        $modifiers = [];
        $affixes = [];
        foreach ($equipped as $entry) {
            $slot = $entry['slot'] ?? null;
            $definition = $entry['definition'] ?? null;
            if (! is_string($slot) || ! in_array($slot, self::EQUIPPED_SLOTS, true)
                || ! is_array($definition) || isset($bySlot[$slot])) {
                throw new RuntimeException('Underground equipment slot contains invalid or multiple items.');
            }
            $this->assertDefinition($definition, ($entry['instance_identity'] ?? null) !== null);
            $expectedCategory = str_starts_with($slot, 'accessory_') ? 'accessory' : $slot;
            if ($definition['category'] !== $expectedCategory) {
                throw new RuntimeException('Underground equipped slot is incompatible.');
            }
            $bySlot[$slot] = $entry;
            $physicalDefense += $definition['physical_defense'];
            $magicalDefense += $definition['magical_defense'];
            $maxHp += $definition['max_hp'];
            foreach (AlphaV1CombatRules::STATS as $stat) {
                $stats[$stat] += $definition['stats'][$stat];
            }
            foreach ($definition['modifiers'] as $key => $value) {
                $modifiers[$key] = ($modifiers[$key] ?? 0) + $value;
            }
            foreach ($definition['affixes'] as $affix) {
                $affixes[] = [
                    'item_key' => $definition['key'],
                    ...$affix,
                ];
            }
        }
        $weaponEntry = $bySlot['weapon'] ?? null;
        $weapon = is_array($weaponEntry) ? ($weaponEntry['definition'] ?? null) : null;
        if (! is_array($weapon)) {
            throw new RuntimeException('Underground weapon slot cannot be empty.');
        }

        $items = [];
        foreach (self::EQUIPPED_SLOTS as $slot) {
            $entry = $bySlot[$slot] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            $definition = $entry['definition'];
            $items[] = [
                'key' => $definition['key'],
                'name' => $definition['name'],
                'category' => $definition['category'],
                'equipped_slot' => $slot,
                'rank' => $definition['rank'],
                'item_level' => $definition['item_level'],
                'rarity' => $definition['rarity'],
                'catalog_identity' => $entry['catalog_identity'],
                'instance_identity' => $entry['instance_identity'],
            ];
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
            'modifiers' => $modifiers,
            'affixes' => $affixes,
            'unique_effect' => null,
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $definition */
    public function assertDefinition(array $definition, bool $generated): void
    {
        $category = $definition['category'] ?? null;
        $style = $definition['weapon_style'] ?? null;
        $rarity = $definition['rarity'] ?? null;
        $statKeys = is_array($definition['stats'] ?? null)
            ? array_keys($definition['stats'])
            : [];
        sort($statKeys);
        $expectedStatKeys = AlphaV1CombatRules::STATS;
        sort($expectedStatKeys);
        if (! is_string($definition['key'] ?? null) || $definition['key'] === ''
            || ! is_string($definition['name'] ?? null) || $definition['name'] === ''
            || ! in_array($category, ['weapon', 'armor', 'accessory'], true)
            || ($category === 'weapon' && ! in_array($style, ['dagger', 'rapier', 'longsword', 'crystal_staff'], true))
            || ($category !== 'weapon' && $style !== null)
            || ! is_int($definition['rank'] ?? null) || $definition['rank'] < 0 || $definition['rank'] > 4
            || ! is_int($definition['item_level'] ?? null) || $definition['item_level'] < 1 || $definition['item_level'] > 60
            || ! in_array($rarity, ['common', 'uncommon', 'rare', 'epic'], true)
            || ! is_string($definition['rarity_label'] ?? null) || $definition['rarity_label'] === ''
            || (! is_null($definition['buy_price'] ?? null) && (! is_int($definition['buy_price']) || $definition['buy_price'] < 1))
            || ! is_bool($definition['shop_sold'] ?? null)
            || ! is_bool($definition['sellable'] ?? null)
            || (! is_null($definition['required_trial_key'] ?? null) && (! is_string($definition['required_trial_key']) || $definition['required_trial_key'] === ''))
            || ! is_int($definition['weapon_power'] ?? null) || $definition['weapon_power'] < 0
            || ! is_int($definition['physical_defense'] ?? null) || $definition['physical_defense'] < 0
            || ! is_int($definition['magical_defense'] ?? null) || $definition['magical_defense'] < 0
            || ! is_int($definition['max_hp'] ?? null) || $definition['max_hp'] < 0
            || ! is_array($definition['stats'] ?? null)
            || $statKeys !== $expectedStatKeys
            || ! is_array($definition['modifiers'] ?? null)
            || ! is_array($definition['affixes'] ?? null) || ! array_is_list($definition['affixes'])
            || ! array_key_exists('unique_effect', $definition)
            || $definition['unique_effect'] !== null) {
            throw new RuntimeException('Underground equipment definition is invalid.');
        }
        foreach ($definition['stats'] as $value) {
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Underground equipment stat is invalid.');
            }
        }
        foreach ($definition['modifiers'] as $key => $value) {
            if (! in_array($key, self::MODIFIER_KEYS, true) || ! is_int($value) || $value < 0) {
                throw new RuntimeException('Underground equipment modifier is invalid.');
            }
        }
        $affixKeys = [];
        foreach ($definition['affixes'] as $affix) {
            if (! is_array($affix)
                || ! is_string($affix['key'] ?? null) || $affix['key'] === ''
                || isset($affixKeys[$affix['key']])
                || ! is_string($affix['label'] ?? null) || $affix['label'] === ''
                || ! in_array($affix['kind'] ?? null, ['stat', 'modifier', 'base'], true)
                || ! is_string($affix['target'] ?? null) || $affix['target'] === ''
                || ! is_int($affix['value'] ?? null) || $affix['value'] < 1
                || ! is_int($affix['quality_bps'] ?? null)
                || $affix['quality_bps'] < 8_000 || $affix['quality_bps'] > 10_000) {
                throw new RuntimeException('Underground equipment affix is invalid.');
            }
            $allowedTargets = match ($affix['kind']) {
                'stat' => AlphaV1CombatRules::STATS,
                'modifier' => self::MODIFIER_KEYS,
                'base' => ['max_hp', 'physical_defense', 'magical_defense'],
            };
            if (! in_array($affix['target'], $allowedTargets, true)) {
                throw new RuntimeException('Underground equipment affix target is invalid.');
            }
            $affixKeys[$affix['key']] = true;
        }
        if ($definition['shop_sold'] === true && $definition['buy_price'] === null) {
            throw new RuntimeException('Underground shop equipment price is missing.');
        }
        if (! $generated && ($rarity !== 'common'
            || $definition['modifiers'] !== []
            || $definition['affixes'] !== [])) {
            throw new RuntimeException('Fixed Underground equipment must remain Novice without affixes.');
        }
        if ($generated && (! isset($definition['sell_price'])
            || ! is_int($definition['sell_price']) || $definition['sell_price'] < 1
            || ! is_string($definition['instance_identity'] ?? null)
            || strlen($definition['instance_identity']) !== 64
            || ($definition['generator_identity'] ?? null) !== $this->generatorIdentity())) {
            throw new RuntimeException('Generated Underground equipment identity is invalid.');
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-equipment');
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 2) {
            throw new RuntimeException('Underground equipment configuration is invalid.');
        }

        return $data;
    }
}
