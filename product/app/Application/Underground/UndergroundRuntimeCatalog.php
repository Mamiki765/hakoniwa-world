<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\UndergroundCombatRules;
use InvalidArgumentException;
use RuntimeException;

final class UndergroundRuntimeCatalog
{
    public function runtimeIdentity(): string
    {
        $identity = $this->data()['runtime_identity'] ?? null;

        return is_string($identity) && $identity !== ''
            ? $identity
            : throw new RuntimeException('Underground runtime identity is missing.');
    }

    public function maxRounds(): int
    {
        $value = $this->combatValue('max_rounds');
        if (! is_int($value) || $value !== 100) {
            throw new RuntimeException('Underground runtime max rounds must be exactly 100.');
        }

        return $value;
    }

    public function cooldownSeconds(): int
    {
        $value = $this->combatValue('cooldown_seconds');

        return is_int($value) && $value > 0
            ? $value
            : throw new RuntimeException('Underground battle cooldown is invalid.');
    }

    public function battleLogRetentionHours(): int
    {
        $value = $this->combatValue('battle_log_retention_hours');

        return is_int($value) && $value > 0
            ? $value
            : throw new RuntimeException('Underground battle log retention is invalid.');
    }

    public function actorKey(): string
    {
        $value = $this->combatValue('actor_key');

        return is_string($value) && $value !== ''
            ? $value
            : throw new RuntimeException('Underground runtime actor is invalid.');
    }

    /** @return list<string> */
    public function loadout(): array
    {
        $value = $this->combatValue('loadout');
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException('Underground runtime loadout is invalid.');
        }
        $loadout = [];
        foreach ($value as $skillKey) {
            if (! is_string($skillKey)) {
                throw new RuntimeException('Underground runtime loadout is invalid.');
            }
            $loadout[] = $skillKey;
        }

        return $loadout;
    }

    public function aiPreset(): string
    {
        $value = $this->combatValue('ai_preset');

        return is_string($value) && $value === UndergroundCombatRules::AI_PRESET
            ? $value
            : throw new RuntimeException('Underground runtime AI preset is invalid.');
    }

    /** @return array{first_level_cost: int, cost_increment_per_level: int} */
    public function xpCurve(): array
    {
        $curve = $this->data()['xp_curve'] ?? null;
        if (! is_array($curve)
            || ! is_int($curve['first_level_cost'] ?? null)
            || ! is_int($curve['cost_increment_per_level'] ?? null)
            || $curve['first_level_cost'] < 1
            || $curve['cost_increment_per_level'] < 0) {
            throw new RuntimeException('Underground XP curve is invalid.');
        }

        return [
            'first_level_cost' => $curve['first_level_cost'],
            'cost_increment_per_level' => $curve['cost_increment_per_level'],
        ];
    }

    /** @return array{type: string, enemy_key: string, xp: int, shards: int} */
    public function encounter(string $key): array
    {
        $encounters = $this->data()['encounters'] ?? null;
        $encounter = is_array($encounters) ? ($encounters[$key] ?? null) : null;
        if (! is_array($encounter)
            || ! is_string($encounter['type'] ?? null)
            || ! is_string($encounter['enemy_key'] ?? null)
            || ! is_int($encounter['xp'] ?? null)
            || ! is_int($encounter['shards'] ?? null)
            || $encounter['xp'] < 0
            || $encounter['shards'] < 0) {
            throw new InvalidArgumentException("Unknown Underground encounter [{$key}].");
        }

        return [
            'type' => $encounter['type'],
            'enemy_key' => $encounter['enemy_key'],
            'xp' => $encounter['xp'],
            'shards' => $encounter['shards'],
        ];
    }

    /** @return array{minimum_combat_level: int, encounters: list<string>} */
    public function huntingGround(string $key): array
    {
        $grounds = $this->data()['hunting_grounds'] ?? null;
        $ground = is_array($grounds) ? ($grounds[$key] ?? null) : null;
        if (! is_array($ground)
            || ! is_int($ground['minimum_combat_level'] ?? null)
            || $ground['minimum_combat_level'] < 1) {
            throw new InvalidArgumentException("Unknown Underground hunting ground [{$key}].");
        }
        $encounters = $this->stringList($ground['encounters'] ?? null, 'hunting ground encounters');
        if ($encounters === []) {
            throw new RuntimeException('Underground hunting ground must contain an encounter.');
        }

        return ['minimum_combat_level' => $ground['minimum_combat_level'], 'encounters' => $encounters];
    }

    /** @return array{content_identity: string, encounters: list<string>} */
    public function trial(string $key): array
    {
        $trials = $this->data()['trials'] ?? null;
        $trial = is_array($trials) ? ($trials[$key] ?? null) : null;
        if (! is_array($trial)
            || ! is_string($trial['content_identity'] ?? null)
            || $trial['content_identity'] === ''
            || mb_strlen($trial['content_identity']) > 128) {
            throw new InvalidArgumentException("Unknown Underground trial [{$key}].");
        }
        $encounters = $this->stringList($trial['encounters'] ?? null, 'trial encounters');
        if (count($encounters) < 1) {
            throw new RuntimeException('Underground trial must contain an encounter.');
        }

        return [
            'content_identity' => $trial['content_identity'],
            'encounters' => $encounters,
        ];
    }

    public function firstTrialKey(): string
    {
        $keys = $this->trialKeys();

        return $keys[0] ?? throw new RuntimeException('Underground runtime has no authored trial.');
    }

    public function nextTrialKey(string $key): ?string
    {
        $keys = $this->trialKeys();
        $position = array_search($key, $keys, true);
        if ($position === false) {
            throw new InvalidArgumentException("Unknown Underground trial [{$key}].");
        }

        return $keys[$position + 1] ?? null;
    }

    /** @return list<string> */
    private function trialKeys(): array
    {
        $trials = $this->data()['trials'] ?? null;
        if (! is_array($trials)) {
            throw new RuntimeException('Underground trial catalog is invalid.');
        }

        return array_values(array_filter(array_keys($trials), 'is_string'));
    }

    private function combatValue(string $key): mixed
    {
        $combat = $this->data()['combat'] ?? null;

        return is_array($combat) ? ($combat[$key] ?? null) : null;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-runtime');
        if (! is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['combat_rules_identity'] ?? null) !== UndergroundCombatRules::IDENTITY) {
            throw new RuntimeException('Underground runtime configuration is invalid.');
        }

        return $data;
    }

    /** @return list<string> */
    private function stringList(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException("Underground {$label} are invalid.");
        }
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new RuntimeException("Underground {$label} are invalid.");
            }
            $strings[] = $item;
        }

        return $strings;
    }
}
