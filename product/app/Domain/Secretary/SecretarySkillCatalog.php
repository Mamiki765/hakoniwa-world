<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretarySkillCatalog
{
    public const AGRICULTURAL_POLICY = 'agricultural_policy';

    public const SPECIALTY_DEVELOPMENT = 'specialty_development';

    public const GOLD_VEIN_SURVEY = 'gold_vein_survey';

    public const FOREST_MANAGEMENT = 'forest_management';

    public const FINAL_DEFENSE_LINE = 'final_defense_line';

    public const DECLINING_BIRTHRATE_POLICY = 'declining_birthrate_policy';

    public const INDOMITABLE = 'indomitable';

    public const SHIP_OPERATIONS = 'ship_operations';

    public const NAVY = 'navy';

    public const ENERGY_SAVING = 'energy_saving';

    /** @var list<string> */
    public const KEYS = [
        self::AGRICULTURAL_POLICY,
        self::SPECIALTY_DEVELOPMENT,
        self::GOLD_VEIN_SURVEY,
        self::FOREST_MANAGEMENT,
        self::FINAL_DEFENSE_LINE,
        self::DECLINING_BIRTHRATE_POLICY,
        self::INDOMITABLE,
        self::SHIP_OPERATIONS,
        self::NAVY,
        self::ENERGY_SAVING,
    ];

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array<string, array<string, mixed>>
     */
    public function definitions(array $ruleset): array
    {
        $definitions = $ruleset['secretary']['skills'] ?? null;
        if (! is_array($definitions)) {
            throw new DomainException('The active ruleset must define its exact Secretary skill catalog.');
        }
        $actualKeys = array_keys($definitions);
        $expectedKeys = $this->keysForRuleset($ruleset);
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new DomainException('The active ruleset must define its exact Secretary skill catalog.');
        }
        $ordered = [];
        foreach ($this->keysForRuleset($ruleset) as $key) {
            $definition = $definitions[$key] ?? null;
            if (! is_array($definition) || ($definition['key'] ?? null) !== $key) {
                throw new DomainException("Secretary skill {$key} has an invalid definition.");
            }
            $ordered[$key] = $definition;
        }

        return $ordered;
    }

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array<string, mixed>
     */
    public function definition(array $ruleset, string $skillKey): array
    {
        if (! in_array($skillKey, $this->keysForRuleset($ruleset), true)) {
            throw new DomainException("Unknown Secretary skill {$skillKey}.");
        }

        return $this->definitions($ruleset)[$skillKey];
    }

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array<string, array{level: int, experience: int}>
     */
    public function initialStates(array $ruleset): array
    {
        $states = [];
        foreach ($this->definitions($ruleset) as $key => $definition) {
            $level = $definition['initial_level'] ?? null;
            if (! is_int($level) || $level < 0) {
                throw new DomainException("Secretary skill {$key} has an invalid initial level.");
            }
            $states[$key] = ['level' => $level, 'experience' => 0];
        }

        return $states;
    }

    /** @param array<string, mixed> $ruleset
     * @return list<string>
     */
    private function keysForRuleset(array $ruleset): array
    {
        $version = $ruleset['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw new DomainException('The active ruleset has an invalid Secretary catalog version.');
        }

        if (($ruleset['key'] ?? null) === config('hakoniwa.ruleset.key')
            && $version === config('hakoniwa.ruleset.version')) {
            return self::KEYS;
        }
        // Historical World projections read the saved catalog. They do not
        // author or execute a retired Ruleset, nor invent missing modern skills.
        $definitions = $ruleset['secretary']['skills'] ?? null;
        if (! is_array($definitions)) {
            throw new DomainException('The historical snapshot has no Secretary skill catalog.');
        }

        return array_values(array_filter(self::KEYS, static fn (string $key): bool => array_key_exists($key, $definitions)));
    }
}
