<?php

namespace App\Application;

use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\RulesetVersion;
use DomainException;

/** Resolves gameplay definitions while leaving spawn provenance untouched. */
final class CurrentMonsterDefinitionResolver
{
    /** @var array<int, array<string, MonsterDefinition>> */
    private array $definitions = [];

    /** @var array<string, RulesetVersion> */
    private array $rulesets = [];

    public function resolve(MonsterInstance $monster, RulesetVersion $ruleset): MonsterDefinition
    {
        $this->applyAll([$monster], $ruleset);

        return $monster->definition;
    }

    /** @param iterable<MonsterInstance> $monsters */
    public function applyAll(iterable $monsters, RulesetVersion $ruleset): void
    {
        $instances = [];
        $keys = [];
        $rulesetId = (int) $ruleset->id;
        $this->rulesets[$ruleset->key.':'.$ruleset->version] = $ruleset;
        foreach ($monsters as $monster) {
            $source = $monster->definition;
            if ((int) $source->ruleset_version_id === $rulesetId) {
                continue;
            }
            $instances[] = [$monster, $source->key];
            if (! isset($this->definitions[$rulesetId][$source->key])) {
                $keys[$source->key] = true;
            }
        }
        if ($keys !== []) {
            foreach (MonsterDefinition::query()->where('ruleset_version_id', $rulesetId)
                ->whereIn('key', array_keys($keys))->get() as $definition) {
                $this->definitions[$rulesetId][$definition->key] = $definition;
            }
        }
        foreach ($instances as [$monster, $key]) {
            $definition = $this->definitions[$rulesetId][$key] ?? null;
            if (! $definition instanceof MonsterDefinition) {
                throw new DomainException("Monster {$key} has no definition in the World Ruleset.");
            }
            $monster->setRelation('definition', $definition);
        }
    }

    /** @param array<string, mixed> $settings */
    public function forSettings(MonsterInstance $monster, array $settings): MonsterDefinition
    {
        $key = $settings['key'] ?? null;
        $version = $settings['version'] ?? null;
        if (! is_string($key) || ! is_int($version)) {
            throw new DomainException('Monster presentation requires a published Ruleset identity.');
        }
        $identity = $key.':'.$version;
        $ruleset = $this->rulesets[$identity] ??= RulesetVersion::query()
            ->where('key', $key)->where('version', $version)->first()
            ?? throw new DomainException('Monster presentation Ruleset is not published.');

        return $this->resolve($monster, $ruleset);
    }
}
