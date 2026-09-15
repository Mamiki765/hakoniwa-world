<?php

namespace Tests\Support;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class ReusableSurfaceTemplateFingerprint
{
    /** @var list<string> */
    private const DEFAULT_INPUTS = [
        'bootstrap/app.php',
        'bootstrap/providers.php',
        'app/Providers/AppServiceProvider.php',
        'composer.lock',
        'phpunit.xml',
        'database/migrations',
        'config/app.php',
        'config/database.php',
        'config/hakoniwa.php',
        'config/hakoniwa/rulesets',
        'app/Application/CurrentCatalogInstaller.php',
        'app/Application/MapSpaceCoveragePreflight.php',
        'app/Application/OceanWorldGenerator.php',
        'app/Application/RulesetPublisher.php',
        'app/Domain/Map/ChunkCoordinateService.php',
        'app/Domain/Map/GridCoordinate.php',
        'app/Domain/Command/CommandQueueLimit.php',
        'app/Domain/Command/DevelopmentPlanQuantity.php',
        'app/Domain/Command/MissileTargetPolicy.php',
        'app/Domain/Economy/SalePolicy.php',
        'app/Domain/Facility/FacilityVisibilityPolicy.php',
        'app/Domain/Monster/MonsterBehaviorResolver.php',
        'app/Domain/Monster/MonsterDispatchOptionResolver.php',
        'app/Domain/Monster/MonsterDisplayOrderResolver.php',
        'app/Domain/Monster/MonsterHardening.php',
        'app/Domain/Monster/MonsterNaturalSpawnPolicy.php',
        'app/Domain/Monster/MonsterRewardPolicyResolver.php',
        'app/Domain/Ruleset',
        'app/Domain/Secretary/SecretaryItemCatalog.php',
        'app/Domain/Secretary/SecretaryItemGameplayContract.php',
        'app/Domain/Secretary/SecretaryItemTargetSafetyPolicy.php',
        'app/Domain/Secretary/SecretaryMonsterDropContract.php',
        'app/Domain/Secretary/SecretarySkillCatalog.php',
        'app/Domain/Secretary/SecretarySkillProgression.php',
        'app/Domain/TradingPost/TradingPostRules.php',
        'app/Domain/Turn/DeterministicRandomStream.php',
        'app/Domain/Turn/TurnAlreadyRunningException.php',
        'app/Domain/World/MapBounds.php',
        'app/Domain/World/MapSpaceCoverageValidator.php',
        'app/Domain/World/WorldGenerationProfile.php',
        'app/Domain/World/WorldMutationLock.php',
        'app/Models/CommandDefinition.php',
        'app/Models/FacilityDefinition.php',
        'app/Models/MapCell.php',
        'app/Models/MapChunk.php',
        'app/Models/MapSpace.php',
        'app/Models/MonsterDefinition.php',
        'app/Models/MonsterInstance.php',
        'app/Models/MonumentDefinition.php',
        'app/Models/ProductionDefinition.php',
        'app/Models/ResourceDefinition.php',
        'app/Models/RulesetVersion.php',
        'app/Models/TerrainDefinition.php',
        'app/Models/TurnRun.php',
        'app/Models/World.php',
        'tests/TestCase.php',
        'tests/Concerns/CreatesTestWorlds.php',
        'tests/Concerns/UsesReusableSurfaceWorld.php',
        'tests/Support/ParallelTestDatabaseManager.php',
        'tests/Support/ReusableSurfaceTemplateBuilderTest.php',
        'tests/Support/ReusableSurfaceTemplateFingerprint.php',
        'tests/Support/ReusableSurfaceWorldState.php',
    ];

    private readonly string $projectRoot;

    /** @var list<string> */
    private readonly array $inputs;

    /** @param list<string>|null $inputs */
    public function __construct(string $projectRoot, ?array $inputs = null)
    {
        $resolved = realpath($projectRoot);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new InvalidArgumentException("Project root [{$projectRoot}] does not exist.");
        }
        $this->projectRoot = TestShardPlanner::normalizePath($resolved);
        $this->inputs = $inputs ?? self::DEFAULT_INPUTS;
    }

    /**
     * @param  array<string, string>  $runtime
     * @return array{fingerprint: string, files: list<string>}
     */
    public function calculate(array $runtime): array
    {
        $files = [];
        foreach ($this->inputs as $input) {
            $normalized = TestShardPlanner::normalizePath($input);
            $path = $this->projectRoot.'/'.$normalized;
            if (is_link($path) || (! is_file($path) && ! is_dir($path))) {
                throw new RuntimeException("Reusable surface template input [{$normalized}] is missing or unsafe.");
            }
            if (is_file($path)) {
                $files[$normalized] = $path;

                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->isLink()) {
                    continue;
                }
                $absolute = TestShardPlanner::normalizePath($file->getPathname());
                $relative = substr($absolute, strlen($this->projectRoot) + 1);
                $files[$relative] = $absolute;
            }
        }
        foreach (glob($this->projectRoot.'/app/Application/Ver*RulesetUpgrade.php') ?: [] as $path) {
            if (! is_file($path) || is_link($path)) {
                throw new RuntimeException('Reusable surface template Ruleset upgrade input is unsafe.');
            }
            $normalized = TestShardPlanner::normalizePath($path);
            $files[substr($normalized, strlen($this->projectRoot) + 1)] = $normalized;
        }
        ksort($files, SORT_STRING);
        ksort($runtime, SORT_STRING);

        $hash = hash_init('sha256');
        hash_update($hash, "hakoniwa.reusable-surface-template.v1\n");
        foreach ($runtime as $key => $value) {
            hash_update($hash, "runtime\0{$key}\0{$value}\n");
        }
        foreach ($files as $relative => $absolute) {
            $fileHash = hash_file('sha256', $absolute);
            if (! is_string($fileHash)) {
                throw new RuntimeException("Unable to hash reusable surface template input [{$relative}].");
            }
            hash_update($hash, "file\0{$relative}\0{$fileHash}\n");
        }

        return ['fingerprint' => hash_final($hash), 'files' => array_keys($files)];
    }
}
