<?php

namespace App\Domain\Monster;

use App\Models\MonsterDefinition;
use DomainException;

final class MonsterDisplayOrderResolver
{
    private const POSTGRESQL_INTEGER_MAX = 2_147_483_647;

    public function forDefinition(MonsterDefinition $definition): int
    {
        return $this->resolve($definition->display_order, $definition->source_metadata);
    }

    /** @param array<string, mixed> $sourceMetadata */
    public function resolve(mixed $displayOrder, array $sourceMetadata): int
    {
        if ($displayOrder !== null) {
            if (! is_int($displayOrder)
                || $displayOrder < 0
                || $displayOrder > self::POSTGRESQL_INTEGER_MAX) {
                throw new DomainException(
                    'Monster display order must fit the PostgreSQL integer range 0..'
                    .self::POSTGRESQL_INTEGER_MAX.'.',
                );
            }

            return $displayOrder;
        }

        $kind = $sourceMetadata['kind'] ?? null;
        if (! is_int($kind) || $kind < 0 || $kind > 7) {
            throw new DomainException('Historical monster display order requires source kind 0..7.');
        }

        return $kind * 100;
    }

    /**
     * @param  iterable<array-key, MonsterDefinition>  $definitions
     * @return array<int, int> definition ID => effective display order
     */
    public function uniqueOrders(iterable $definitions): array
    {
        $orders = [];
        $seen = [];
        foreach ($definitions as $definition) {
            $order = $this->forDefinition($definition);
            if (isset($seen[$order])) {
                throw new DomainException('Monster definitions contain duplicate effective display order.');
            }
            $seen[$order] = true;
            $orders[$definition->id] = $order;
        }

        return $orders;
    }
}
