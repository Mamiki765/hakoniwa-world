<?php

namespace App\Domain\Monster;

use App\Models\MonsterDefinition;
use DomainException;

final class MonsterBehaviorResolver
{
    public const METADATA_KEY = 'behavior';

    public const LEGACY_LAND = 'legacy_land';

    public const WATER_NEUTRALIZING = 'water_neutralizing';

    public const NUCLEAR_AT_HP_ONE = 'nuclear_self_destruct_at_hp_one';

    public const NORMAL_MOVEMENT_STAGE = 'normal';

    public const BEFORE_SURFACE_CELL_PROCESSING_MOVEMENT_STAGE = 'before_surface_cell_processing';

    public function forDefinition(MonsterDefinition $definition): MonsterBehavior
    {
        return $this->resolve($definition->source_metadata, $definition->key);
    }

    /** @param array<string, mixed> $sourceMetadata */
    public function resolve(array $sourceMetadata, string $monsterKey): MonsterBehavior
    {
        if (! array_key_exists(self::METADATA_KEY, $sourceMetadata)) {
            throw new DomainException("Monster {$monsterKey} behavior must be explicitly authored.");
        }

        $behavior = $this->validate($sourceMetadata[self::METADATA_KEY], $monsterKey);

        return new MonsterBehavior(
            movement: $behavior['movement'],
            dispatchable: $behavior['dispatchable'],
            canActOnSpawnTurn: $behavior['can_act_on_spawn_turn'],
            specialAction: $behavior['special_action'],
            islandCreationDisplaceable: $behavior['island_creation_displaceable'],
            worldSpawn: $behavior['world_spawn'] ?? null,
            movementStage: $behavior['movement_stage'] ?? self::NORMAL_MOVEMENT_STAGE,
            defeatTerrainKey: $behavior['defeat_terrain_key'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function validate(mixed $authored, string $monsterKey): array
    {
        if (! is_array($authored) || array_is_list($authored)) {
            throw new DomainException("Monster {$monsterKey} behavior must be an authored map.");
        }
        $required = ['movement', 'dispatchable', 'can_act_on_spawn_turn', 'special_action', 'island_creation_displaceable'];
        if (array_values(array_intersect($required, array_keys($authored))) !== $required) {
            throw new DomainException("Monster {$monsterKey} behavior is missing required fields.");
        }
        $unknown = array_values(array_diff(array_keys($authored), [
            ...$required,
            'world_spawn',
            'movement_stage',
            'defeat_terrain_key',
        ]));
        if ($unknown !== []) {
            throw new DomainException("Monster {$monsterKey} behavior contains unknown fields.");
        }
        if (! in_array($authored['movement'], [self::LEGACY_LAND, self::WATER_NEUTRALIZING], true)
            || ! is_bool($authored['dispatchable'])
            || $authored['can_act_on_spawn_turn'] !== false
            || ! in_array($authored['special_action'], ['none', self::NUCLEAR_AT_HP_ONE], true)
            || ! is_bool($authored['island_creation_displaceable'])
            || ! in_array($authored['movement_stage'] ?? self::NORMAL_MOVEMENT_STAGE, [
                self::NORMAL_MOVEMENT_STAGE,
                self::BEFORE_SURFACE_CELL_PROCESSING_MOVEMENT_STAGE,
            ], true)
            || ($authored['defeat_terrain_key'] ?? null) !== null
                && (! is_string($authored['defeat_terrain_key']) || $authored['defeat_terrain_key'] === '')) {
            throw new DomainException("Monster {$monsterKey} behavior has an unsupported value.");
        }

        $expected = match ($monsterKey) {
            'mecha_inora' => [self::LEGACY_LAND, true, 'none', false],
            'mecha_inora_zero' => [self::LEGACY_LAND, true, self::NUCLEAR_AT_HP_ONE, false],
            'aoi_inora' => [self::WATER_NEUTRALIZING, false, 'none', true],
            default => [self::LEGACY_LAND, false, 'none', false],
        };
        if ([$authored['movement'], $authored['dispatchable'], $authored['special_action'], $authored['island_creation_displaceable']] !== $expected) {
            throw new DomainException("Monster {$monsterKey} behavior differs from the approved v11 contract.");
        }
        if ($monsterKey !== 'aoi_inora' && array_key_exists('world_spawn', $authored)) {
            throw new DomainException("Monster {$monsterKey} must not author a World spawn contract.");
        }
        if ($monsterKey === 'aoi_inora') {
            $expectedSpawn = [
                'type' => 'world_aoi_disaster',
                'probability_per_active_owned_land_cell' => ['numerator' => 1, 'denominator' => 10_000],
                'maximum_probability_numerator' => 10_000,
                'terrain_keys' => ['sea', 'shallow'],
                'minimum_land_distance' => 4,
                'stream_version' => 1,
            ];
            if ($this->canonicalize($authored['world_spawn'] ?? null) !== $this->canonicalize($expectedSpawn)) {
                throw new DomainException('Aoi Inora World spawn behavior differs from the approved v11 contract.');
            }
        }
        if ($monsterKey === 'nyowamiya') {
            if (($authored['movement_stage'] ?? null) !== self::BEFORE_SURFACE_CELL_PROCESSING_MOVEMENT_STAGE
                || ($authored['defeat_terrain_key'] ?? null) !== 'plain') {
                throw new DomainException('Nyowamiya behavior must author pre-surface movement and plain defeat terrain.');
            }
        } elseif (array_key_exists('movement_stage', $authored)
            || array_key_exists('defeat_terrain_key', $authored)) {
            throw new DomainException("Monster {$monsterKey} must not author Nyowamiya-only behavior fields.");
        }

        return $authored;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
