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
        if (! is_int($value) || $value < 1 || $value > 100) {
            throw new RuntimeException('Underground runtime max rounds must be between 1 and 100.');
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
            : throw new RuntimeException('Underground battle log retention must be a positive number of hours.');
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

    /**
     * Resolve content-authored party boss scaling without embedding a formula in the engine.
     *
     * @param  array<string, mixed>  $scaling
     * @return array{mode: 'none'|'table', hp_bps: int, attack_bps: int}
     */
    public function resolvePartyBossScaling(array $scaling, int $partySize): array
    {
        if ($partySize < 1 || $partySize > 4 || ! isset($scaling['mode'])) {
            throw new InvalidArgumentException('Underground party boss scaling is invalid.');
        }
        if ($scaling['mode'] === 'none') {
            return ['mode' => 'none', 'hp_bps' => 10_000, 'attack_bps' => 10_000];
        }
        if ($scaling['mode'] !== 'table'
            || ! is_array($scaling['hp_bps'] ?? null)
            || ! is_array($scaling['attack_bps'] ?? null)
            || array_keys($scaling['hp_bps']) !== [1, 2, 3, 4]
            || array_keys($scaling['attack_bps']) !== [1, 2, 3, 4]) {
            throw new InvalidArgumentException('Underground party boss scaling table is invalid.');
        }
        foreach ([$scaling['hp_bps'], $scaling['attack_bps']] as $table) {
            foreach ($table as $multiplier) {
                if (! is_int($multiplier) || $multiplier < 1) {
                    throw new InvalidArgumentException('Underground party boss scaling table is invalid.');
                }
            }
        }

        return [
            'mode' => 'table',
            'hp_bps' => $scaling['hp_bps'][$partySize],
            'attack_bps' => $scaling['attack_bps'][$partySize],
        ];
    }

    /** @return array{identity: string, content_type: 'hunting_ground'|'trial', actual_clears_required: int, ticket_cost: int} */
    public function skipPolicy(string $contentType): array
    {
        if (! in_array($contentType, ['hunting_ground', 'trial'], true)) {
            throw new InvalidArgumentException('Unknown Underground skip content type.');
        }
        $skip = $this->data()['skip'] ?? null;
        $identity = is_array($skip) ? ($skip['identity'] ?? null) : null;
        $policy = is_array($skip) ? ($skip[$contentType] ?? null) : null;
        $expectedRequired = $contentType === 'hunting_ground' ? 50 : 5;
        $expectedCost = $contentType === 'hunting_ground' ? 1 : 10;
        if (! is_string($identity) || $identity === '' || mb_strlen($identity) > 100
            || ! is_array($policy)
            || ($policy['actual_clears_required'] ?? null) !== $expectedRequired
            || ($policy['ticket_cost'] ?? null) !== $expectedCost) {
            throw new RuntimeException('Underground skip policy is invalid.');
        }

        return [
            'identity' => $identity,
            'content_type' => $contentType,
            'actual_clears_required' => $expectedRequired,
            'ticket_cost' => $expectedCost,
        ];
    }

    /**
     * @return array{
     *   label: string,
     *   content_identity: string,
     *   balance_manifest: string,
     *   required_trial_key: string|null,
     *   drop_tier_key: string|null,
     *   interbattle_heal_bps: int,
     *   first_clear_skill_points: int,
     *   unlocked_area_layers: int,
     *   encounters: list<string>,
     *   rewards: list<array{xp: int, shards: int}>
     * }
     */
    public function trial(string $key): array
    {
        $trials = $this->data()['trials'] ?? null;
        $trial = is_array($trials) ? ($trials[$key] ?? null) : null;
        if (! is_array($trial)
            || ! is_string($trial['label'] ?? null)
            || $trial['label'] === ''
            || ! is_string($trial['content_identity'] ?? null)
            || $trial['content_identity'] === ''
            || mb_strlen($trial['content_identity']) > 128
            || ! is_string($trial['balance_manifest'] ?? null)
            || preg_match('/\Aunderground\/balance\/[a-z0-9][a-z0-9._-]*\.json\z/', $trial['balance_manifest']) !== 1
            || (! is_null($trial['required_trial_key'] ?? null)
                && ! is_string($trial['required_trial_key']))
            || (! is_null($trial['drop_tier_key'] ?? null)
                && ! is_string($trial['drop_tier_key']))
            || ($trial['required_trial_key'] ?? null) === $key
            || ! is_int($trial['interbattle_heal_bps'] ?? null)
            || $trial['interbattle_heal_bps'] < 0
            || $trial['interbattle_heal_bps'] > 10_000
            || ! is_int($trial['first_clear_skill_points'] ?? null)
            || $trial['first_clear_skill_points'] < 0
            || ! is_int($trial['unlocked_area_layers'] ?? null)
            || $trial['unlocked_area_layers'] < 0) {
            throw new InvalidArgumentException("Unknown Underground trial [{$key}].");
        }
        if (is_string($trial['required_trial_key'])
            && ! array_key_exists($trial['required_trial_key'], $trials)) {
            throw new RuntimeException("Underground Trial [{$key}] references an unknown prerequisite.");
        }
        $encounters = $this->stringList($trial['encounters'] ?? null, 'trial encounters');
        $configuredRewards = $trial['rewards'] ?? null;
        if ($encounters === []
            || count($encounters) > 100
            || ! is_array($configuredRewards)
            || ! array_is_list($configuredRewards)
            || count($configuredRewards) !== count($encounters)) {
            throw new RuntimeException('Underground Trial encounters and rewards are invalid.');
        }
        $rewards = [];
        $dropTierKey = $trial['drop_tier_key'] ?? null;
        $dropProfiles = config('underground-alpha-v1.exploration.drop.profiles');
        $generatorMaximum = config('underground-equipment.generator.item_level_max');
        foreach ($configuredRewards as $reward) {
            if (! is_array($reward)
                || ! is_int($reward['xp'] ?? null)
                || ! is_int($reward['shards'] ?? null)
                || $reward['xp'] < 0
                || $reward['shards'] < 0) {
                throw new RuntimeException('Underground Trial rewards are invalid.');
            }
            $dropProfile = $reward['drop_profile'] ?? null;
            $itemLevelMin = $reward['item_level_min'] ?? null;
            $itemLevelMax = $reward['item_level_max'] ?? null;
            if ($dropTierKey === null) {
                if ($dropProfile !== null || $itemLevelMin !== null || $itemLevelMax !== null) {
                    throw new RuntimeException('Underground Trial reward drop metadata is invalid.');
                }
            } elseif (! is_string($dropProfile)
                || ! is_array($dropProfiles) || ! is_array($dropProfiles[$dropProfile] ?? null)
                || ! is_int($itemLevelMin) || ! is_int($itemLevelMax)
                || $itemLevelMin < 1 || $itemLevelMax < $itemLevelMin
                || ! is_int($generatorMaximum) || $itemLevelMax > $generatorMaximum) {
                throw new RuntimeException('Underground Trial reward drop metadata is invalid.');
            }
            $rewards[] = [
                'xp' => $reward['xp'],
                'shards' => $reward['shards'],
                'drop_profile' => $dropProfile,
                'item_level_min' => $itemLevelMin,
                'item_level_max' => $itemLevelMax,
            ];
        }
        return [
            'label' => $trial['label'],
            'content_identity' => $trial['content_identity'],
            'balance_manifest' => $trial['balance_manifest'],
            'required_trial_key' => $trial['required_trial_key'],
            'drop_tier_key' => $dropTierKey,
            'interbattle_heal_bps' => $trial['interbattle_heal_bps'],
            'first_clear_skill_points' => $trial['first_clear_skill_points'],
            'unlocked_area_layers' => $trial['unlocked_area_layers'],
            'encounters' => $encounters,
            'rewards' => $rewards,
        ];
    }

    public function firstTrialKey(): string
    {
        $keys = $this->trialKeys();

        return $keys[0] ?? throw new RuntimeException('Underground runtime has no authored trial.');
    }

    /** @return list<string> */
    public function trialKeys(): array
    {
        return $this->configuredTrialKeys();
    }

    /** @return list<string> */
    private function configuredTrialKeys(): array
    {
        $trials = $this->data()['trials'] ?? null;
        if (! is_array($trials) || $trials === [] || array_is_list($trials)) {
            throw new RuntimeException('Underground trial catalog is invalid.');
        }
        foreach (array_keys($trials) as $key) {
            if (! is_string($key) || $key === '' || strlen($key) > 64) {
                throw new RuntimeException('Underground trial catalog is invalid.');
            }
        }

        return array_keys($trials);
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
