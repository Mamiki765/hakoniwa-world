<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryItemCatalog
{
    public const OLD_BOW = 'old_bow';

    /**
     * @return array{
     *   key: string,
     *   category: string,
     *   category_label: string,
     *   max_level: int,
     *   name: string,
     *   flavor_text: string,
     *   unique_per_secretary: bool
     * }
     */
    public function definition(string $itemKey): array
    {
        if ($itemKey !== self::OLD_BOW) {
            throw new DomainException("Unknown Secretary item {$itemKey}.");
        }

        return [
            'key' => self::OLD_BOW,
            'category' => 'bow',
            'category_label' => '弓',
            'max_level' => 1,
            'name' => '古びた弓',
            'flavor_text' => '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
            'unique_per_secretary' => true,
        ];
    }

    public function maximumEquipped(string $category): int
    {
        return match ($category) {
            'bow' => 1,
            default => throw new DomainException("Unknown Secretary item category {$category}."),
        };
    }
}
