<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use JsonException;
use RuntimeException;

final readonly class UndergroundAlphaV1PlayerCatalog
{
    public function __construct(private AlphaV1CombatRules $rules) {}

    public function growthIdentity(): string
    {
        return $this->string($this->data(), 'growth_identity');
    }

    /** @return list<array<string, mixed>> */
    public function growthPaths(): array
    {
        $paths = $this->data()['growth_paths'];

        return array_map(fn (string $key): array => $this->growthPath($key), array_keys($paths));
    }

    /** @return array<string, mixed> */
    public function growthPath(string $key): array
    {
        $configured = $this->data()['growth_paths'][$key] ?? null;
        if (! is_array($configured)) {
            throw new UndergroundRuntimeException('underground_growth_path_invalid', '輝石の選択を確認してください。');
        }
        $buildKey = $this->string($configured, 'build_key');
        $build = $this->laboratoryCatalog()->build($buildKey);
        $stats = $build['base_stats'] ?? null;
        $growth = $configured['natural_growth'] ?? null;
        $stp = $configured['unspent_stp_per_level'] ?? null;
        if (! is_array($stats) || ! is_array($growth) || ! is_int($stp)) {
            throw new RuntimeException("Underground growth path [{$key}] is invalid.");
        }
        $this->rules->assertFiveStats($stats);
        if (array_keys($growth) !== AlphaV1CombatRules::STATS
            || array_filter($growth, 'is_int') !== $growth
            || array_sum($growth) + $stp !== 10
            || ($growth['agility'] ?? null) !== 0) {
            throw new RuntimeException("Underground growth path [{$key}] growth contract is invalid.");
        }
        $description = $configured['description'] ?? null;
        if (! is_array($description) || ! array_is_list($description)
            || array_filter($description, 'is_string') !== $description) {
            throw new RuntimeException("Underground growth path [{$key}] description is invalid.");
        }

        return [
            'key' => $key,
            'identity' => $this->growthIdentity(),
            'label' => $this->string($configured, 'label'),
            'color' => $this->string($configured, 'color'),
            'description' => $description,
            'default_build_key' => $buildKey,
            'stats' => $stats,
            'max_hp' => $this->rules->maxHp($stats, 10_000),
            'max_mp' => AlphaV1CombatRules::MAX_MP,
            'natural_recovery' => $this->laboratoryCatalog()->balanceInt('mp_natural_recovery'),
            'natural_growth' => $growth,
            'unspent_stp_per_level' => $stp,
            'points_per_level' => array_sum($growth) + $stp,
        ];
    }

    /** @return array<string, mixed> */
    public function playtestOptions(string $growthPathKey): array
    {
        $playtest = $this->playtestConfig();
        $catalog = $this->laboratoryCatalog();
        $builds = [];
        foreach ($playtest['builds'] as $key => $configured) {
            if (! is_string($key) || ! is_array($configured)) {
                throw new RuntimeException('Underground playtest build configuration is invalid.');
            }
            $builds[] = [
                'key' => $key,
                'label' => $this->string($catalog->build($key), 'label'),
                'description' => $this->string($configured, 'description'),
            ];
        }
        $enemies = [];
        foreach ($playtest['enemies'] as $key => $configured) {
            if (! is_string($key) || ! is_array($configured)) {
                throw new RuntimeException('Underground playtest enemy configuration is invalid.');
            }
            $catalog->enemy($key);
            $enemies[] = [
                'key' => $key,
                'label' => $this->string($configured, 'label'),
                'description' => $this->string($configured, 'description'),
            ];
        }

        return [
            'notice' => $this->string($playtest, 'notice'),
            'default_build_key' => $this->growthPath($growthPathKey)['default_build_key'],
            'builds' => $builds,
            'enemies' => $enemies,
        ];
    }

    /** @return array{catalog: AlphaV1BuildCatalog, identity: string, tier_key: string, max_rounds: int, build_label: string, enemy_label: string} */
    public function playtestDefinition(string $buildKey, string $enemyKey): array
    {
        $playtest = $this->playtestConfig();
        $build = $playtest['builds'][$buildKey] ?? null;
        $enemy = $playtest['enemies'][$enemyKey] ?? null;
        if (! is_array($build) || ! is_array($enemy)) {
            throw new UndergroundRuntimeException(
                'underground_playtest_selection_invalid',
                '力試しのbuildまたは対戦相手を確認してください。',
            );
        }
        $maxRounds = $enemy['max_rounds'] ?? null;
        if ($maxRounds !== 100) {
            throw new RuntimeException('Underground playtest round contract is invalid.');
        }
        $catalog = $this->laboratoryCatalog();
        $buildDefinition = $catalog->build($buildKey);
        $catalog->enemy($enemyKey);

        return [
            'catalog' => $catalog,
            'identity' => $this->string($playtest, 'identity'),
            'tier_key' => $this->string($playtest, 'tier_key'),
            'max_rounds' => $maxRounds,
            'build_label' => $this->string($buildDefinition, 'label'),
            'enemy_label' => $this->string($enemy, 'label'),
        ];
    }

    /** @return array{catalog: AlphaV1BuildCatalog, build_key: string, enemy_key: string, tier_key: string, combat_level_equivalent: int, enemy_scale_bps: int, seed: int, max_rounds: int, expected_winner: string} */
    public function trueNameStoryBattle(): array
    {
        $definition = $this->data()['true_name_story_battle'];
        $manifest = $this->laboratoryCatalog()->manifest();
        $buildKey = $this->string($definition, 'build_key');
        $enemyKey = $this->string($definition, 'enemy_key');
        $manifest['builds'][$buildKey] = $definition['build'];
        $manifest['enemies'][$enemyKey] = $definition['enemy'];
        $catalog = new AlphaV1BuildCatalog($manifest);
        $seed = $definition['seed'] ?? null;
        $maxRounds = $definition['max_rounds'] ?? null;
        $equivalentCombatLevel = $definition['combat_level_equivalent'] ?? null;
        if (! is_int($seed) || ! is_int($maxRounds) || ! is_int($equivalentCombatLevel)) {
            throw new RuntimeException('Underground true-name story battle contract is invalid.');
        }

        return [
            'catalog' => $catalog,
            'build_key' => $buildKey,
            'enemy_key' => $enemyKey,
            'tier_key' => $this->string($definition, 'tier_key'),
            'combat_level_equivalent' => $equivalentCombatLevel,
            'enemy_scale_bps' => $this->rules->storyBenchmarkScaleBps($equivalentCombatLevel),
            'seed' => $seed,
            'max_rounds' => $maxRounds,
            'expected_winner' => $this->string($definition, 'expected_winner'),
        ];
    }

    public function laboratoryCatalog(): AlphaV1BuildCatalog
    {
        try {
            $manifest = json_decode(
                file_get_contents(config_path('underground/balance/foundation-v1.json')) ?: '',
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw new RuntimeException('Underground alpha-v1 manifest is invalid.', previous: $exception);
        }
        if (! is_array($manifest)) {
            throw new RuntimeException('Underground alpha-v1 manifest is invalid.');
        }

        return new AlphaV1BuildCatalog($manifest);
    }

    /** @return array<string, mixed> */
    private function playtestConfig(): array
    {
        $playtest = $this->data()['playtest'];
        if (! is_array($playtest['builds'] ?? null) || ! is_array($playtest['enemies'] ?? null)) {
            throw new RuntimeException('Underground playtest configuration is invalid.');
        }

        return $playtest;
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        $data = config('underground-alpha-v1');
        if (! is_array($data) || ($data['schema_version'] ?? null) !== 1
            || ! is_array($data['growth_paths'] ?? null)
            || ! is_array($data['playtest'] ?? null)
            || ! is_array($data['true_name_story_battle'] ?? null)) {
            throw new RuntimeException('Underground alpha-v1 player configuration is invalid.');
        }

        return $data;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : throw new RuntimeException("Underground alpha-v1 [{$key}] is invalid.");
    }
}
