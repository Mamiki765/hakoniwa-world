<?php

namespace App\Domain\Secretary;

use DomainException;

class SecretaryItemCatalog
{
    public const OLD_BOW = 'old_bow';

    public const RING = 'ring';

    public const RARITY_NOVICE = 'novice';

    /**
     * @return array{
     *   key: string,
     *   category: string,
     *   category_label: string,
     *   category_max_equipped: int,
     *   rarity: string,
     *   rarity_label: string,
     *   tradable: bool,
     *   npc_tradable: bool,
     *   max_level: int,
     *   name: string,
     *   flavor_text: string,
     *   unique_per_secretary: bool,
     *   same_item_max_equipped: int
     * }
     */
    public function definition(string $itemKey): array
    {
        $definition = $this->definitions()[$itemKey] ?? null;
        if (! is_array($definition)) {
            throw new DomainException("Unknown Secretary item {$itemKey}.");
        }

        return $definition;
    }

    /**
     * @return array<string, array{
     *   key: string,
     *   category: string,
     *   category_label: string,
     *   category_max_equipped: int,
     *   rarity: string,
     *   rarity_label: string,
     *   tradable: bool,
     *   npc_tradable: bool,
     *   max_level: int,
     *   name: string,
     *   flavor_text: string,
     *   unique_per_secretary: bool,
     *   same_item_max_equipped: int
     * }>
     */
    public function definitions(): array
    {
        return [
            self::OLD_BOW => [
                'key' => self::OLD_BOW,
                'category' => 'bow',
                'category_label' => '弓',
                'category_max_equipped' => 1,
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => false,
                'max_level' => 1,
                'name' => '古びた弓',
                'flavor_text' => '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
                'unique_per_secretary' => true,
                'same_item_max_equipped' => 1,
            ],
            self::RING => [
                'key' => self::RING,
                'category' => 'ring',
                'category_label' => '指輪',
                'category_max_equipped' => 5,
                'rarity' => self::RARITY_NOVICE,
                'rarity_label' => 'ノービス',
                'tradable' => true,
                'npc_tradable' => true,
                'max_level' => 10,
                'name' => '指輪',
                'flavor_text' => '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
                'unique_per_secretary' => false,
                'same_item_max_equipped' => 5,
            ],
        ];
    }

    public function maximumEquipped(string $category): int
    {
        $maximums = [];
        foreach ($this->definitions() as $definition) {
            if ($definition['category'] === $category) {
                $maximums[$definition['category_max_equipped']] = true;
            }
        }
        if (count($maximums) !== 1) {
            throw new DomainException("Unknown Secretary item category {$category}.");
        }

        return (int) array_key_first($maximums);
    }

    public function sameItemMaximum(string $itemKey): int
    {
        return $this->definition($itemKey)['same_item_max_equipped'];
    }

    /** @return list<array{category: string, label: string, maximum_equipped: int}> */
    public function categoryLimits(): array
    {
        $limits = [];
        foreach ($this->definitions() as $definition) {
            $category = $definition['category'];
            $limits[$category] = [
                'category' => $category,
                'label' => $definition['category_label'],
                'maximum_equipped' => $this->maximumEquipped($category),
            ];
        }

        ksort($limits);

        return array_values($limits);
    }
}
