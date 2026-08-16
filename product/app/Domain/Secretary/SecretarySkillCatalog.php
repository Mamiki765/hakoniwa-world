<?php

namespace App\Domain\Secretary;

use DomainException;

final class SecretarySkillCatalog
{
    public const AGRICULTURAL_POLICY = 'agricultural_policy';

    public const SPECIALTY_DEVELOPMENT = 'specialty_development';

    public const GOLD_VEIN_SURVEY = 'gold_vein_survey';

    public const FINAL_DEFENSE_LINE = 'final_defense_line';

    /** @var list<string> */
    public const KEYS = [
        self::AGRICULTURAL_POLICY,
        self::SPECIALTY_DEVELOPMENT,
        self::GOLD_VEIN_SURVEY,
        self::FINAL_DEFENSE_LINE,
    ];

    /**
     * @param  array<string, mixed>  $ruleset
     * @return array<string, array<string, mixed>>
     */
    public function definitions(array $ruleset): array
    {
        $definitions = $ruleset['secretary']['skills'] ?? null;
        if (! is_array($definitions)) {
            throw new DomainException('The active ruleset must define the exact Secretary v1 skill catalog.');
        }
        $actualKeys = array_keys($definitions);
        $expectedKeys = self::KEYS;
        sort($actualKeys);
        sort($expectedKeys);
        if ($actualKeys !== $expectedKeys) {
            throw new DomainException('The active ruleset must define the exact Secretary v1 skill catalog.');
        }
        $ordered = [];
        foreach (self::KEYS as $key) {
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
        if (! in_array($skillKey, self::KEYS, true)) {
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
}
