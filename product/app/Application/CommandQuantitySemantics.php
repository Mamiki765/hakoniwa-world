<?php

namespace App\Application;

use App\Models\CommandDefinition;
use App\Models\MonumentDefinition;
use DomainException;

final class CommandQuantitySemantics
{
    /** @var list<string> */
    private const QUANTITY_COMMAND_KEYS = [
        'build_farm', 'build_factory', 'build_mine',
        'missile', 'pp_missile', 'land_destruction_missile', 'spp_missile',
    ];

    public const ORDINARY = 'ordinary';

    public const SELECTOR = 'selector';

    public const UNUSED = 'unused';

    public function for(CommandDefinition $definition): string
    {
        if (is_string($definition->metadata['quantity_selects_catalog'] ?? null)) {
            return self::SELECTOR;
        }
        if (in_array($definition->key, self::QUANTITY_COMMAND_KEYS, true)
            || is_string($definition->metadata['oil_search_effect_key'] ?? null)
            || is_int($definition->metadata['transfer_money_per_quantity'] ?? null)
            || is_int($definition->metadata['transfer_food_tons_per_quantity'] ?? null)) {
            return self::ORDINARY;
        }

        return self::UNUSED;
    }

    public function presentationDefault(CommandDefinition $definition): ?int
    {
        if ($this->for($definition) === self::SELECTOR) {
            return null;
        }

        return in_array($definition->key, ['missile', 'pp_missile', 'land_destruction_missile'], true)
            ? 99
            : 1;
    }

    /** @return list<array{value: int, key: string, label: string}> */
    public function options(CommandDefinition $definition): array
    {
        if ($this->for($definition) !== self::SELECTOR) {
            return [];
        }
        if (($definition->metadata['quantity_selects_catalog'] ?? null) !== 'monument_definitions') {
            throw new DomainException('未対応のquantity selector catalogです。');
        }

        // PR22 catalog ids 1..3 are the original persisted selector values. Unlike display order,
        // the primary key remains stable when an option is disabled or reordered.
        return MonumentDefinition::query()
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (MonumentDefinition $option): array => [
                'value' => (int) $option->id,
                'key' => $option->key,
                'label' => $option->name,
            ])->all();
    }

    public function validateForRegistration(CommandDefinition $definition, int $quantity, bool $provided): void
    {
        $semantics = $this->for($definition);
        if ($semantics === self::SELECTOR) {
            if (! $provided) {
                throw new DomainException('選択肢を明示してからcommandを登録してください。');
            }
            if (! collect($this->options($definition))->contains(
                static fn (array $option): bool => $option['value'] === $quantity,
            )) {
                throw new DomainException('選択されたcatalog itemは利用できません。');
            }
        }
        if ($semantics === self::UNUSED && $quantity !== 1) {
            throw new DomainException('このcommandではquantityを指定できません。');
        }
    }

    public function assertEditable(CommandDefinition $definition): void
    {
        if ($this->for($definition) !== self::ORDINARY) {
            throw new DomainException('このcommandのquantityは汎用数量エディタでは変更できません。');
        }
    }

    public function label(CommandDefinition $definition, int $quantity): ?string
    {
        if ($this->for($definition) !== self::SELECTOR) {
            return null;
        }

        $option = MonumentDefinition::query()->find($quantity);
        if ($option !== null) {
            return $option->name;
        }

        return '存在しない選択肢';
    }
}
