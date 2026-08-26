<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryMonsterDropContract
{
    public const RULESET_KEYS = ['hakoniwa-2s-plus-v17', 'hakoniwa-2s-plus-v18'];

    /** @var list<string> */
    public const ELIGIBLE_MONSTERS = [
        'inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost', 'aoi_inora', 'whale', 'king_inora',
    ];

    /** @var list<string> */
    public const EXCLUDED_MONSTERS = ['mecha_inora', 'mecha_inora_zero'];

    public function __construct(private readonly SecretaryItemCatalog $items) {}

    /** @param array<string, mixed> $settings */
    public function exists(array $settings): bool
    {
        return isset($settings['monster_system']['item_drop']);
    }

    /** @param array<string, mixed> $settings */
    public function validate(array $settings): void
    {
        $drop = $settings['monster_system']['item_drop'] ?? null;
        if (! in_array($settings['key'] ?? null, self::RULESET_KEYS, true)) {
            if ($drop !== null) {
                throw new DomainException('Secretary monster drops may only be authored by the v17+ contract.');
            }

            return;
        }
        if (! is_array($drop) || array_is_list($drop)) {
            throw new DomainException('ruleset.monster_system.item_drop must be an object map.');
        }
        $recipient = is_array($drop['recipient'] ?? null) ? $drop['recipient'] : [];
        if (($drop['random_stream_version'] ?? null) !== 1
            || ($drop['excluded_monster_keys'] ?? null) !== self::EXCLUDED_MONSTERS
            || ! $this->hasExactKeys($recipient, [
                'killer_percent_when_foreign_host', 'host_percent_when_foreign_host',
                'same_or_no_host', 'inventory_full_reroute',
            ])
            || ($recipient['killer_percent_when_foreign_host'] ?? null) !== 75
            || ($recipient['host_percent_when_foreign_host'] ?? null) !== 25
            || ($recipient['same_or_no_host'] ?? null) !== 'killer'
            || ($recipient['inventory_full_reroute'] ?? null) !== false) {
            throw new DomainException('ruleset.monster_system.item_drop differs from the recipient/RNG contract.');
        }
        $definitionKeys = [];
        foreach ($settings['monster_definitions'] ?? [] as $definition) {
            if (is_array($definition) && is_string($definition['key'] ?? null)) {
                $definitionKeys[] = $definition['key'];
            }
        }
        foreach ([...self::ELIGIBLE_MONSTERS, ...self::EXCLUDED_MONSTERS] as $monsterKey) {
            if (! in_array($monsterKey, $definitionKeys, true)) {
                throw new DomainException("Monster drop references unknown monster {$monsterKey}.");
            }
        }
        $expectedPools = [
            SecretaryItemCatalog::RARITY_NOVICE => [
                SecretaryItemCatalog::RING,
                SecretaryItemCatalog::SECRETARY_SUIT,
                SecretaryItemCatalog::INORA_BRACELET,
                SecretaryItemCatalog::HOARDER_TALISMAN,
                SecretaryItemCatalog::GOOD_PERSON_TREASURE,
                SecretaryItemCatalog::VAULT_KEY,
                SecretaryItemCatalog::MONSTER_REPELLENT_INCENSE,
                SecretaryItemCatalog::FULLNESS_HERB,
            ],
            SecretaryItemCatalog::RARITY_REGULAR => [
                SecretaryItemCatalog::ELF_BOW,
                SecretaryItemCatalog::LONGSHOT_BOW,
                SecretaryItemCatalog::MECHANICAL_BOW,
            ],
            SecretaryItemCatalog::RARITY_CURSED => [SecretaryItemCatalog::COLLAR],
        ];
        $rarityPools = $drop['rarity_pools'] ?? null;
        if (! is_array($rarityPools)
            || ! $this->hasExactKeys($rarityPools, array_keys($expectedPools))) {
            throw new DomainException('ruleset.monster_system.item_drop.rarity_pools differs from the closed pools.');
        }
        foreach ($expectedPools as $rarity => $pool) {
            if (($rarityPools[$rarity] ?? null) !== $pool) {
                throw new DomainException('ruleset.monster_system.item_drop.rarity_pools differs from the closed pools.');
            }
        }
        $authoredPools = $this->authoredPools($drop);
        if (! is_array($authoredPools)) {
            throw new DomainException('ruleset.monster_system.item_drop.rarity_pools must be an object map.');
        }
        foreach ($authoredPools as $rarity => $pool) {
            if (! is_string($rarity) || ! is_array($pool) || $pool === []) {
                throw new DomainException("Monster drop rarity pool {$rarity} must not be empty.");
            }
            foreach ($pool as $itemKey) {
                if (! is_string($itemKey)
                    || $itemKey === SecretaryItemCatalog::OLD_BOW
                    || $this->items->definition($itemKey)['rarity'] !== $rarity) {
                    throw new DomainException("Monster drop item {$itemKey} does not match rarity {$rarity}.");
                }
            }
        }
        $tables = $drop['monster_tables'] ?? null;
        if (! is_array($tables) || ! $this->hasExactKeys($tables, self::ELIGIBLE_MONSTERS)) {
            throw new DomainException('ruleset.monster_system.item_drop.monster_tables has invalid monster keys/order.');
        }
        foreach ($tables as $monsterKey => $table) {
            $weights = $table['rarity_weights'] ?? null;
            $cap = $table['level_cap_percent'] ?? null;
            if (! is_array($weights)
                || ! $this->hasExactKeys($weights, array_keys($expectedPools))
                || array_sum($weights) !== 100
                || array_filter($weights, static fn (mixed $weight): bool => ! is_int($weight) || $weight < 0) !== []
                || ! is_int($cap) || $cap < 1 || $cap > 100) {
                throw new DomainException("Monster drop table {$monsterKey} has invalid weights or level cap.");
            }
        }
    }

    /** @param array<string, mixed> $settings
     * @return array<string, mixed>|null
     */
    public function table(array $settings, string $monsterKey): ?array
    {
        $this->validate($settings);

        return $settings['monster_system']['item_drop']['monster_tables'][$monsterKey] ?? null;
    }

    /** @param array<string, mixed> $settings
     * @return list<string>
     */
    public function pool(array $settings, string $rarity): array
    {
        $this->validate($settings);

        return $settings['monster_system']['item_drop']['rarity_pools'][$rarity]
            ?? throw new DomainException("Unknown monster drop rarity {$rarity}.");
    }

    /** @param array<string, mixed> $drop */
    private function authoredPools(array $drop): mixed
    {
        return $drop['rarity_pools'] ?? null;
    }

    /** @param array<array-key, mixed> $value
     * @param  list<string>  $expected
     */
    private function hasExactKeys(array $value, array $expected): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);

        return $actual === $expected;
    }
}
