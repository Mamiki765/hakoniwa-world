<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\EquipmentCombatEffects;
use App\Models\UndergroundProfile;
use InvalidArgumentException;
use RuntimeException;

final class UndergroundEquipmentCatalog
{
    public const STARTER_KEY = 'starter_knife';

    /** @var list<string> */
    public const EQUIPPED_SLOTS = [
        'weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3', 'resonance',
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
        ...EquipmentCombatEffects::RESONANCE_MODIFIERS,
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

    public function generatorItemLevelMax(): int
    {
        $maximum = $this->data()['generator']['item_level_max'] ?? null;

        return is_int($maximum) && $maximum >= 60
            ? $maximum
            : throw new RuntimeException('Underground equipment generator item-level maximum is invalid.');
    }

    /** @return list<string> */
    public function resonanceVariantKeys(string $tier): array
    {
        return array_keys($this->data()['generator']['tiers'][$tier]['resonance_variants'] ?? []);
    }

    public function supportsGeneratorIdentity(string $identity): bool
    {
        return in_array($identity, [$this->generatorIdentity(), ...($this->data()['generator']['legacy_identities'] ?? [])], true);
    }

    public function vaultCapacity(string $inventory = 'equipment', bool $expanded = false): int
    {
        $capacity = $this->data()[$inventory === 'resonance' ? 'resonance_capacity' : 'vault_capacity'] ?? null;
        $extra = $this->data()[$inventory === 'resonance' ? 'resonance_expansion_capacity' : 'vault_expansion_capacity'] ?? null;

        return is_int($capacity) && $capacity > 0 && is_int($extra) && $extra > 0
            ? $capacity + ($expanded ? $extra : 0)
            : throw new RuntimeException('Underground vault capacity must be positive.');
    }

    public function vaultCapacityForProfile(UndergroundProfile $profile, string $inventory = 'equipment'): int
    {
        $purchasedAt = $inventory === 'resonance'
            ? $profile->resonance_expansion_purchased_at
            : $profile->vault_expansion_purchased_at;

        return $this->vaultCapacity($inventory, $purchasedAt !== null);
    }

    public function maximumBulkSellCount(): int
    {
        return $this->vaultCapacity('equipment', true) + $this->vaultCapacity('resonance', true);
    }

    public function pageSize(): int
    {
        $size = $this->data()['page_size'] ?? null;

        return is_int($size) && $size >= 1 && $size <= 100
            ? $size
            : throw new RuntimeException('Underground vault page size is invalid.');
    }

    /** @return list<array{key: string, label: string}> */
    public function weaponStyleOptions(): array
    {
        $styles = [];
        foreach ($this->definitions() as $definition) {
            if ($definition['category'] === 'weapon') {
                $styles[$definition['weapon_style']] = true;
            }
        }
        $labels = $this->data()['weapon_style_labels'] ?? null;
        if (! is_array($labels)) {
            throw new RuntimeException('Underground equipment weapon style labels are invalid.');
        }
        $styleKeys = array_keys($styles);
        $labelKeys = array_keys($labels);
        sort($styleKeys);
        sort($labelKeys);
        if ($labelKeys !== $styleKeys) {
            throw new RuntimeException('Underground equipment weapon style labels are invalid.');
        }
        foreach ($labels as $key => $label) {
            if (! is_string($key) || $key === '' || ! is_string($label) || $label === '') {
                throw new RuntimeException('Underground equipment weapon style label is invalid.');
            }
        }

        return array_map(
            static fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );
    }

    /** @return list<string> */
    public function weaponStyleKeys(): array
    {
        return array_column($this->weaponStyleOptions(), 'key');
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
            $this->assertDefinition(
                $definition,
                ($entry['instance_identity'] ?? null) !== null,
                enforceCurrentGeneratorQuality: false,
            );
            $expectedCategory = str_starts_with($slot, 'accessory_') ? 'accessory' : $slot;
            if ($definition['category'] !== $expectedCategory || ($definition['equippable'] ?? true) !== true) {
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
                'polish_level' => (int) ($definition['polish_level'] ?? 0),
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
            'unique_effect' => $weapon['unique_effect'],
            'items' => $items,
        ];
    }

    /** @param array<string, mixed> $definition */
    public function assertDefinition(
        array $definition,
        bool $generated,
        bool $enforceCurrentGeneratorQuality = true,
    ): void {
        $category = $definition['category'] ?? null;
        $style = $definition['weapon_style'] ?? null;
        $rarity = $definition['rarity'] ?? null;
        $statKeys = is_array($definition['stats'] ?? null)
            ? array_keys($definition['stats'])
            : [];
        sort($statKeys);
        $expectedStatKeys = AlphaV1CombatRules::STATS;
        sort($expectedStatKeys);
        $qualityMinimum = $this->data()['generator']['quality_min_bps'] ?? null;
        $qualityMaximum = $this->data()['generator']['quality_max_bps'] ?? null;
        if (! is_int($qualityMinimum) || ! is_int($qualityMaximum)
            || $qualityMinimum < 1 || $qualityMaximum < $qualityMinimum) {
            throw new RuntimeException('Underground equipment generator quality range is invalid.');
        }
        if (! is_string($definition['key'] ?? null) || $definition['key'] === ''
            || ! is_string($definition['name'] ?? null) || $definition['name'] === ''
            || ! in_array($category, ['weapon', 'armor', 'accessory', 'resonance'], true)
            || ($category === 'weapon' && ! in_array($style, ['dagger', 'rapier', 'longsword', 'crystal_staff'], true))
            || ($category !== 'weapon' && $style !== null)
            || ! is_int($definition['rank'] ?? null) || $definition['rank'] < 0
            || ! is_int($definition['item_level'] ?? null) || $definition['item_level'] < 1
            || (($generated || ($definition['equippable'] ?? true) !== false) && $definition['item_level'] > $this->generatorItemLevelMax())
            || (! in_array($rarity, ['common', 'uncommon', 'rare', 'epic'], true)
                && ! ($generated && $rarity === 'unique' && in_array($category, ['weapon', 'resonance'], true))
                && ! (! $generated && ($definition['equippable'] ?? true) === false && $rarity === 'unique'))
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
            || ! array_key_exists('unique_effect', $definition)) {
            throw new RuntimeException('Underground equipment definition is invalid.');
        }
        EquipmentCombatEffects::assertUnique($definition['unique_effect']);
        $effectType = $definition['unique_effect']['type'] ?? null;
        if (($effectType === 'shockwave' && ($category !== 'weapon' || $rarity !== 'unique'))
            || ($effectType === 'resonance' && $category !== 'resonance')
            || ($generated && $rarity === 'unique' && $effectType === null)) {
            throw new RuntimeException('Underground equipment intrinsic effect category is invalid.');
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
                || ($category !== 'resonance' && isset($affixKeys[$affix['key']]))
                || ! is_string($affix['label'] ?? null) || $affix['label'] === ''
                || ! in_array($affix['kind'] ?? null, ['stat', 'modifier', 'base'], true)
                || ! is_string($affix['target'] ?? null) || $affix['target'] === ''
                || ! is_int($affix['value'] ?? null) || $affix['value'] < 1
                || ! is_int($affix['quality_bps'] ?? null)
                || $affix['quality_bps'] < 1 || $affix['quality_bps'] > 10_000
                || ($generated && $enforceCurrentGeneratorQuality
                    && ($affix['quality_bps'] < $qualityMinimum || $affix['quality_bps'] > $qualityMaximum))) {
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
        if (! $generated && $definition['shop_sold'] === true && (($rarity !== 'common' && ($definition['equippable'] ?? true) !== false)
            || $definition['modifiers'] !== []
            || $definition['affixes'] !== [])) {
            throw new RuntimeException('Underground shop equipment must remain Novice without affixes.');
        }
        if ($generated && (! isset($definition['sell_price'])
            || ! is_int($definition['sell_price']) || $definition['sell_price'] < 1
            || ! is_string($definition['instance_identity'] ?? null)
            || strlen($definition['instance_identity']) !== 64
            || ! is_string($definition['generator_identity'] ?? null)
            || ! $this->supportsGeneratorIdentity($definition['generator_identity']))) {
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
