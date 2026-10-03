<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretaryItemSynthesisContract
{
    /** @param array<string, mixed> $settings */
    public function validate(array $settings): void
    {
        $this->recipe($settings);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{key: string, ingredient_keys: list<string>, ingredient_level: int, result_key: string, result_level: int, cost_money: int, disclosure: string}|null
     */
    public function recipe(array $settings): ?array
    {
        $recipes = $settings['secretary']['item_synthesis'] ?? null;
        if ($recipes === null) {
            return null;
        }
        if (($settings['key'] ?? null) !== 'hakoniwa-2s-plus-v28'
            || ! is_array($recipes) || array_keys($recipes) !== [SecretaryItemCatalog::SUCCUBUS_EMBLEM]) {
            throw new DomainException('Unsupported Secretary Item synthesis contract.');
        }
        $recipe = $recipes[SecretaryItemCatalog::SUCCUBUS_EMBLEM];
        $expected = [
            'key' => SecretaryItemCatalog::SUCCUBUS_EMBLEM,
            'ingredient_keys' => [SecretaryItemCatalog::LOVE_EMBLEM, SecretaryItemCatalog::TWIN_STAR_EMBLEM, SecretaryItemCatalog::CRESCENT_EMBLEM],
            'ingredient_level' => 1,
            'result_key' => SecretaryItemCatalog::SUCCUBUS_EMBLEM,
            'result_level' => 1,
            'cost_money' => 0,
            'disclosure' => 'owns_all_ingredients',
        ];
        if (! is_array($recipe) || count($recipe) !== count($expected)) {
            throw new DomainException('Unsupported Secretary Item synthesis recipe.');
        }
        // PostgreSQL jsonb reorders object keys; list order and scalar types
        // still carry meaning and must match the supported execution contract.
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $recipe) || $recipe[$key] !== $value) {
                throw new DomainException('Unsupported Secretary Item synthesis recipe.');
            }
        }

        return $recipe;
    }
}
