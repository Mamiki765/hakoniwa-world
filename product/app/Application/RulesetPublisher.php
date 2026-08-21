<?php

namespace App\Application;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MonsterDefinition;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RulesetPublisher
{
    public function __construct(private readonly RulesetAuthoringValidator $validator) {}

    /** @param array<string, mixed> $settings */
    public function publish(array $settings): RulesetVersion
    {
        $this->validator->validate($settings);
        $supportsMonsterDisplayOrder = array_key_exists('monster_definitions', $settings)
            && Schema::hasColumn('monster_definitions', 'display_order');
        if (! $supportsMonsterDisplayOrder) {
            foreach ($settings['monster_definitions'] ?? [] as $definition) {
                if (is_array($definition) && array_key_exists('display_order', $definition)) {
                    throw new DomainException('Explicit monster display order requires the C3 schema migration.');
                }
            }
        }

        $key = $settings['key'] ?? null;
        $version = $settings['version'] ?? null;
        if (! is_string($key) || $key === '' || ! is_int($version) || $version < 1) {
            throw new DomainException('ruleset snapshotには一意なkeyと正の整数versionが必要です。');
        }

        return DB::transaction(function () use ($settings, $key, $version, $supportsMonsterDisplayOrder): RulesetVersion {
            $ruleset = RulesetVersion::query()->where('key', $key)->lockForUpdate()->first();
            if ($ruleset !== null) {
                $this->assertSameSnapshot($ruleset, $settings, $version);
                $this->assertDefinitions($ruleset, $settings, $supportsMonsterDisplayOrder);

                return $ruleset;
            }

            $ruleset = RulesetVersion::query()->create([
                'key' => $key,
                'version' => $version,
                'settings' => $settings,
                'is_active' => true,
            ]);
            $this->createDefinitions($ruleset, $settings, $supportsMonsterDisplayOrder);

            return $ruleset;
        }, 3);
    }

    /** @param array<string, mixed> $settings */
    private function assertSameSnapshot(RulesetVersion $ruleset, array $settings, int $version): void
    {
        if ($ruleset->version !== $version
            || ! $ruleset->is_active
            || $this->canonicalJson($ruleset->settings) !== $this->canonicalJson($settings)) {
            throw new DomainException(
                "Published ruleset {$ruleset->key} already exists with a different immutable payload. "
                .'Publish the changed settings under a new key/version.',
            );
        }
    }

    /** @param array<string, mixed> $settings */
    private function createDefinitions(
        RulesetVersion $ruleset,
        array $settings,
        bool $supportsMonsterDisplayOrder,
    ): void {
        foreach ($this->commandPayloads($ruleset, $settings) as $payload) {
            CommandDefinition::query()->create($payload);
        }
        foreach ($this->productionPayloads($ruleset, $settings) as $payload) {
            $payload['production_per_scale'] = $this->databaseDecimal($payload['production_per_scale']);
            /** @var array<model-property<ProductionDefinition>, mixed> $payload */
            ProductionDefinition::query()->create($payload);
        }
        foreach ($this->monsterPayloads($ruleset, $settings, $supportsMonsterDisplayOrder) as $payload) {
            MonsterDefinition::query()->create($payload);
        }
    }

    /** @param array<string, mixed> $settings */
    private function assertDefinitions(
        RulesetVersion $ruleset,
        array $settings,
        bool $supportsMonsterDisplayOrder,
    ): void {
        $expectedCommands = collect($this->commandPayloads($ruleset, $settings))->keyBy('key');
        $commands = CommandDefinition::query()->where('ruleset_version_id', $ruleset->id)->get();
        if ($commands->count() !== $expectedCommands->count()) {
            throw new DomainException("Published ruleset {$ruleset->key} has different command definitions.");
        }
        foreach ($commands as $command) {
            $expected = $expectedCommands->get($command->key);
            if (! is_array($expected) || $this->canonicalJson($this->commandState($command)) !== $this->canonicalJson($expected)) {
                throw new DomainException("Published ruleset {$ruleset->key} command {$command->key} differs from its snapshot.");
            }
        }

        $expectedProduction = collect($this->productionPayloads($ruleset, $settings))->keyBy('key');
        $production = ProductionDefinition::query()->where('ruleset_version_id', $ruleset->id)->get();
        if ($production->count() !== $expectedProduction->count()) {
            throw new DomainException("Published ruleset {$ruleset->key} has different production definitions.");
        }
        foreach ($production as $definition) {
            $expected = $expectedProduction->get($definition->key);
            if (! is_array($expected)
                || $this->canonicalJson($this->productionState($definition)) !== $this->canonicalJson($expected)) {
                throw new DomainException(
                    "Published ruleset {$ruleset->key} production {$definition->key} differs from its snapshot.",
                );
            }
        }

        if (! array_key_exists('monster_definitions', $settings)) {
            return;
        }
        $expectedMonsters = collect($this->monsterPayloads(
            $ruleset,
            $settings,
            $supportsMonsterDisplayOrder,
        ))->keyBy('key');
        $monsters = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)->get();
        if ($monsters->count() !== $expectedMonsters->count()) {
            throw new DomainException("Published ruleset {$ruleset->key} has different monster definitions.");
        }
        foreach ($monsters as $definition) {
            $expected = $expectedMonsters->get($definition->key);
            if (! is_array($expected)
                || $this->canonicalJson($this->monsterState(
                    $definition,
                    $supportsMonsterDisplayOrder,
                )) !== $this->canonicalJson($expected)) {
                throw new DomainException(
                    "Published ruleset {$ruleset->key} monster {$definition->key} differs from its snapshot.",
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function monsterPayloads(
        RulesetVersion $ruleset,
        array $settings,
        bool $supportsMonsterDisplayOrder,
    ): array {
        $payloads = [];
        foreach ($settings['monster_definitions'] ?? [] as $definition) {
            if (! is_array($definition)) {
                throw new DomainException("Ruleset {$ruleset->key} has an invalid monster definition.");
            }
            $payload = [
                'ruleset_version_id' => $ruleset->id,
                'key' => $definition['key'],
                'name' => $definition['name'],
                'asset_key' => $definition['asset_key'],
                'hardened_asset_key' => $definition['hardened_asset_key'],
                'base_hp' => $definition['base_hp'],
                'hp_variation' => $definition['hp_variation'],
                'skill_key' => $definition['skill_key'],
                'movement_limit' => $definition['movement_limit'],
                'natural_spawn_tier' => $definition['natural_spawn_tier'],
                'wreckage_value_money' => $definition['wreckage_value_money'],
                'missile_base_experience' => $definition['missile_base_experience'],
                'skill_description' => $definition['skill_description'],
                'visibility' => $definition['visibility'],
                'movement_terrain_contract' => $definition['movement_terrain_contract'],
                'trample_contract' => $definition['trample_contract'],
                'hardening_contract' => $definition['hardening_contract'],
                'source_metadata' => $definition['source_metadata'],
            ];
            if ($supportsMonsterDisplayOrder) {
                $payload['display_order'] = $definition['display_order'] ?? null;
            }
            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function commandPayloads(RulesetVersion $ruleset, array $settings): array
    {
        $payloads = [];
        foreach ($settings['command_definitions'] ?? [] as $definition) {
            if (! is_array($definition)) {
                throw new DomainException("Ruleset {$ruleset->key} has an invalid command definition.");
            }
            $payloads[] = [
                'ruleset_version_id' => $ruleset->id,
                'key' => $definition['key'],
                'name' => $definition['name'],
                'description' => $definition['description'],
                'target_type' => $definition['target_type'],
                'target_terrain_keys' => $definition['target_terrain_keys'],
                'target_facility_keys' => $definition['target_facility_keys'],
                'requires_empty_facility' => $definition['requires_empty_facility'],
                'cost_money' => $definition['cost_money'],
                'required_resources' => $definition['required_resources'],
                'execution_phase' => $definition['execution_phase'],
                'result_terrain_key' => $definition['result_terrain_key'],
                'result_facility_key' => $definition['result_facility_key'],
                'enabled' => true,
                'sort_order' => $definition['sort_order'],
                'metadata' => $definition['metadata'],
            ];
        }

        return $payloads;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<array<string, mixed>>
     */
    private function productionPayloads(RulesetVersion $ruleset, array $settings): array
    {
        $payloads = [];
        foreach ($settings['production_definitions'] ?? [] as $definition) {
            if (! is_array($definition)) {
                throw new DomainException("Ruleset {$ruleset->key} has an invalid production definition.");
            }
            $facilityId = FacilityDefinition::query()->where('key', $definition['facility_key'])->value('id');
            $resourceId = ResourceDefinition::query()->where('key', $definition['output_resource_key'])->value('id');
            if ($facilityId === null || $resourceId === null) {
                throw new DomainException(
                    "Ruleset {$ruleset->key} production {$definition['key']} references a missing catalog row.",
                );
            }
            $payloads[] = [
                'ruleset_version_id' => $ruleset->id,
                'key' => $definition['key'],
                'facility_definition_id' => (int) $facilityId,
                'output_resource_definition_id' => (int) $resourceId,
                'production_per_scale' => (float) $definition['production_per_scale'],
                'required_workforce_per_scale' => $definition['required_workforce_per_scale'],
                'operating_condition' => $definition['operating_condition'],
                'price_reference' => $definition['price_reference'],
                'enabled' => true,
                'metadata' => $definition['metadata'],
            ];
        }

        return $payloads;
    }

    /** @return array<string, mixed> */
    private function commandState(CommandDefinition $definition): array
    {
        return [
            'ruleset_version_id' => $definition->ruleset_version_id,
            'key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'target_type' => $definition->target_type,
            'target_terrain_keys' => $definition->target_terrain_keys,
            'target_facility_keys' => $definition->target_facility_keys,
            'requires_empty_facility' => $definition->requires_empty_facility,
            'cost_money' => $definition->cost_money,
            'required_resources' => $definition->required_resources,
            'execution_phase' => $definition->execution_phase,
            'result_terrain_key' => $definition->result_terrain_key,
            'result_facility_key' => $definition->result_facility_key,
            'enabled' => $definition->enabled,
            'sort_order' => $definition->sort_order,
            'metadata' => $definition->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function productionState(ProductionDefinition $definition): array
    {
        return [
            'ruleset_version_id' => $definition->ruleset_version_id,
            'key' => $definition->key,
            'facility_definition_id' => $definition->facility_definition_id,
            'output_resource_definition_id' => $definition->output_resource_definition_id,
            'production_per_scale' => $definition->production_per_scale,
            'required_workforce_per_scale' => $definition->required_workforce_per_scale,
            'operating_condition' => $definition->operating_condition,
            'price_reference' => $definition->price_reference,
            'enabled' => $definition->enabled,
            'metadata' => $definition->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function monsterState(MonsterDefinition $definition, bool $supportsMonsterDisplayOrder): array
    {
        $state = [
            'ruleset_version_id' => $definition->ruleset_version_id,
            'key' => $definition->key,
            'name' => $definition->name,
            'asset_key' => $definition->asset_key,
            'hardened_asset_key' => $definition->hardened_asset_key,
            'base_hp' => $definition->base_hp,
            'hp_variation' => $definition->hp_variation,
            'skill_key' => $definition->skill_key,
            'movement_limit' => $definition->movement_limit,
            'natural_spawn_tier' => $definition->natural_spawn_tier,
            'wreckage_value_money' => $definition->wreckage_value_money,
            'missile_base_experience' => $definition->missile_base_experience,
            'skill_description' => $definition->skill_description,
            'visibility' => $definition->visibility,
            'movement_terrain_contract' => $definition->movement_terrain_contract,
            'trample_contract' => $definition->trample_contract,
            'hardening_contract' => $definition->hardening_contract,
            'source_metadata' => $definition->source_metadata,
        ];
        if ($supportsMonsterDisplayOrder) {
            $state['display_order'] = $definition->display_order;
        }

        return $state;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function databaseDecimal(mixed $value): string
    {
        if (! is_int($value) && ! is_float($value)) {
            throw new DomainException('Validated production_per_scale must be numeric.');
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
}
