<?php

namespace App\Application;

use App\Models\FacilityDefinition;
use App\Models\MonumentDefinition;
use App\Models\ResourceDefinition;
use App\Models\TerrainDefinition;
use DomainException;
use Illuminate\Database\Eloquent\Model;

final class CurrentCatalogInstaller
{
    /** @param array<string, mixed> $rules */
    public function install(array $rules): void
    {
        foreach ($this->catalogs($rules) as $modelClass => $definitions) {
            foreach ($definitions as $definition) {
                $this->createOrAssert($modelClass, $definition);
            }
        }
    }

    /** @param array<string, mixed> $rules */
    public function assertInstalled(array $rules): void
    {
        foreach ($this->catalogs($rules) as $modelClass => $definitions) {
            $expectedKeys = array_column($definitions, 'key');
            $actualKeys = $modelClass::query()->orderBy('key')->pluck('key')->all();
            sort($expectedKeys, SORT_STRING);

            if ($actualKeys !== $expectedKeys) {
                throw new DomainException("{$modelClass} has missing, unknown, or duplicate current catalog keys.");
            }
            foreach ($definitions as $definition) {
                $this->assertModel($modelClass::query()->where('key', $definition['key'])->sole(), $definition);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<class-string<Model>, list<array<string, mixed>>>
     */
    private function catalogs(array $rules): array
    {
        $forest = $rules['terrain_quantities']['forest'];
        $terrains = [
            ['key' => 'sea', 'name' => '海', 'asset_key' => 'tile.sea', 'is_water' => true, 'is_buildable' => false, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => []],
            ['key' => 'shallow', 'name' => '浅瀬', 'asset_key' => 'tile.shallow', 'is_water' => true, 'is_buildable' => false, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => []],
            ['key' => 'wasteland', 'name' => '荒地', 'asset_key' => 'tile.wasteland', 'is_water' => false, 'is_buildable' => true, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => []],
            ['key' => 'scorched', 'name' => '焦土', 'asset_key' => 'tile.scorched', 'is_water' => false, 'is_buildable' => true, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => ['created_by' => 'missile_impact']],
            ['key' => 'plain', 'name' => '平地', 'asset_key' => 'tile.plain', 'is_water' => false, 'is_buildable' => true, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => []],
            [
                'key' => 'forest', 'name' => '森', 'asset_key' => 'tile.forest', 'is_water' => false, 'is_buildable' => false,
                'quantity_key' => $forest['key'], 'quantity_label' => $forest['label'], 'quantity_unit' => $forest['unit'],
                'initial_quantity' => $forest['initial_quantity'], 'minimum_quantity' => $forest['minimum_quantity'],
                'maximum_quantity' => $forest['maximum_quantity'], 'growth_rule_key' => $forest['growth_rule_key'],
                'metadata' => ['legacy_quantity_unit' => 100, 'growth_increment' => $forest['growth_increment']],
            ],
            ['key' => 'mountain', 'name' => '山', 'asset_key' => 'tile.mountain', 'is_water' => false, 'is_buildable' => false, 'quantity_key' => null, 'quantity_label' => null, 'quantity_unit' => null, 'initial_quantity' => null, 'minimum_quantity' => null, 'maximum_quantity' => null, 'growth_rule_key' => null, 'metadata' => []],
        ];
        $facilities = [];
        foreach ($rules['facility_definitions'] as $key => $definition) {
            $facilities[] = [
                'key' => $key,
                'name' => $definition['name'],
                'asset_key' => $definition['asset_key'],
                'enabled' => true,
                'build_command_key' => $definition['build_command_key'],
                'visibility_policy' => $definition['visibility_policy'],
                'disguise_terrain_key' => $definition['disguise_terrain_key'] ?? null,
                'disguise_asset_key' => $definition['disguise_asset_key'] ?? null,
                'disguise_ownership_policy' => $definition['disguise_ownership_policy'] ?? null,
                'scale_unit_people' => $definition['scale_unit_people'],
                'initial_scale' => $definition['initial_scale'],
                'scale_increment' => $definition['scale_increment'],
                'maximum_scale' => $definition['maximum_scale'],
                'workforce_per_scale_people' => $definition['workforce_per_scale_people'],
                'production_definition_key' => $definition['production_definition_key'],
                'buildable_terrain_keys' => $definition['buildable_terrain_keys'],
                'metadata' => array_filter([
                    'initial_experience' => $definition['initial_experience'] ?? null,
                    'maximum_experience' => $definition['maximum_experience'] ?? null,
                    'level_thresholds' => $definition['level_thresholds'] ?? null,
                    'launch_capacity_by_level' => $definition['launch_capacity_by_level'] ?? null,
                    'display_as_facility_key' => $definition['display_as_facility_key'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        return [
            TerrainDefinition::class => $terrains,
            FacilityDefinition::class => $facilities,
            ResourceDefinition::class => $rules['resource_definitions'],
            MonumentDefinition::class => [
                [
                    'key' => 'peace', 'name' => '平和記念碑', 'asset_key' => 'tile.monument.peace',
                    'description' => '平和を願う記念碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 10, 'metadata' => [],
                ],
                [
                    'key' => 'prosperity', 'name' => '繁栄記念碑', 'asset_key' => 'tile.monument.prosperity',
                    'description' => 'Nationの繁栄を記念する碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 20, 'metadata' => [],
                ],
                [
                    'key' => 'victory', 'name' => '戦勝記念碑', 'asset_key' => 'tile.monument.victory',
                    'description' => '歴史的な勝利を記録する碑です。', 'effect_key' => null,
                    'enabled' => true, 'sort_order' => 30, 'metadata' => [],
                ],
            ],
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $expected
     */
    private function createOrAssert(string $modelClass, array $expected): void
    {
        $model = $modelClass::query()->where('key', $expected['key'])->first();
        if ($model === null) {
            $modelClass::query()->create($expected);

            return;
        }

        $this->assertModel($model, $expected);
    }

    /** @param array<string, mixed> $expected */
    private function assertModel(Model $model, array $expected): void
    {
        foreach ($expected as $field => $value) {
            if (! $this->sameCatalogValue($model->getAttribute($field), $value)) {
                $modelClass = $model::class;
                throw new DomainException(
                    "{$modelClass} {$expected['key']} differs from the configured catalog. "
                    .'Apply an explicit data migration instead of overwriting a published catalog row.',
                );
            }
        }
    }

    private function sameCatalogValue(mixed $stored, mixed $expected): bool
    {
        if (is_array($stored) && is_array($expected)) {
            return $this->canonicalize($stored) === $this->canonicalize($expected);
        }
        if ((is_int($stored) || is_float($stored)) && (is_int($expected) || is_float($expected))) {
            return (float) $stored === (float) $expected;
        }

        return $stored === $expected;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $nested) {
            if (is_array($nested)) {
                $value[$key] = $this->canonicalize($nested);
            }
        }

        return $value;
    }
}
