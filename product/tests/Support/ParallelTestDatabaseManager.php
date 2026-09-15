<?php

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class ParallelTestDatabaseManager
{
    private const DATABASE_PATTERN = '/^hakoniwa_parallel_([a-f0-9]{8})_([0-9]{2})_test$/';

    private const TEMPLATE_DATABASE_PATTERN = '/^hakoniwa_surface_fixture_([a-f0-9]{16})_template$/';

    private const TEMPLATE_BUILD_DATABASE_PATTERN = '/^hakoniwa_surface_fixture_build_([a-f0-9]{8})_test$/';

    private const TEMPLATE_LOCK_KEY = 420_320_042;

    private readonly string $projectRoot;

    private readonly string $configurationPath;

    private readonly string $workspaceDirectory;

    private readonly string $evidenceRootDirectory;

    public function __construct(string $projectRoot, string $configurationPath = 'phpunit.xml')
    {
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException("Project root [{$projectRoot}] does not exist.");
        }

        $this->projectRoot = TestShardPlanner::normalizePath($resolvedRoot);
        $this->configurationPath = $this->projectRoot.'/'.TestShardPlanner::normalizePath($configurationPath);
        $this->workspaceDirectory = $this->projectRoot.'/storage/framework/testing';
        $this->evidenceRootDirectory = $this->workspaceDirectory.'/test-evidence';
    }

    /**
     * @param  array<int, list<string>>|null  $plannedShards
     * @param  list<string>|null  $plannedDiscovered
     */
    public function prepare(
        int $shardTotal,
        string $scope = 'full',
        ?string $requestedToken = null,
        ?array $plannedShards = null,
        ?array $plannedDiscovered = null,
        bool $useReusableSurfaceTemplate = false,
    ): string {
        $scope = TestShardPlanner::normalizeScope($scope);
        if ($shardTotal < 1 || $shardTotal > 64) {
            throw new InvalidArgumentException('Local shard total must be in the range 1..64.');
        }

        $cachedConfiguration = $this->projectRoot.'/bootstrap/cache/config.php';
        if (is_file($cachedConfiguration) || is_link($cachedConfiguration)) {
            throw new RuntimeException(
                'Refusing to prepare parallel test databases while Laravel configuration is cached.',
            );
        }

        $planner = new TestShardPlanner($this->projectRoot, $this->configurationPath);
        $discovered = $planner->discover($scope);
        $selected = $plannedDiscovered === null
            ? $discovered
            : array_values(array_map(TestShardPlanner::normalizePath(...), $plannedDiscovered));
        if ($selected === []
            || count($selected) !== count(array_unique($selected))
            || array_diff($selected, $discovered) !== []) {
            throw new RuntimeException('Refusing to prepare databases for an invalid focused test selection.');
        }
        $shards = $plannedShards ?? $planner->assign($selected, $shardTotal);
        if (count($shards) !== $shardTotal) {
            throw new RuntimeException('Refusing to prepare databases for a shard plan with the wrong worker count.');
        }
        $shards = array_values(array_map(
            static fn (array $files): array => array_map(TestShardPlanner::normalizePath(...), $files),
            $shards,
        ));
        $report = $planner->coverageReport($selected, $shards);
        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Refusing to prepare databases for an incomplete shard plan.');
        }
        $profiles = $planner->groupByFixtureProfile($selected);
        if ($useReusableSurfaceTemplate
            && ($profiles['reusable_surface'] === []
                || $profiles['standard'] !== []
                || $profiles['individual'] !== [])) {
            throw new RuntimeException(
                'Reusable surface template cloning is restricted to a focused reusable_surface-only selection.',
            );
        }

        if ($requestedToken !== null && preg_match('/^[a-f0-9]{8}$/', $requestedToken) !== 1) {
            throw new InvalidArgumentException('Parallel test database token is invalid.');
        }

        $settings = $this->databaseSettings();
        $this->ensureEvidenceRootDirectory();
        $token = $requestedToken ?? bin2hex(random_bytes(4));
        $runDirectory = $this->workspaceDirectory.'/phpunit-parallel-'.$token;
        $evidenceDirectory = $this->evidenceRootDirectory.'/phpunit-parallel-'.$token;
        if (file_exists($runDirectory) || is_link($runDirectory) || ! mkdir($runDirectory, 0700, true)) {
            throw new RuntimeException("Unable to create parallel test workspace [{$runDirectory}].");
        }

        $pdo = null;
        $createdDatabases = [];
        $manifestShards = [];
        $template = null;
        $templateLockHeld = false;

        try {
            foreach ($shards as $index => $files) {
                if ($files === []) {
                    continue;
                }

                $database = self::databaseName($token, $index);
                $configuration = $runDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.xml';
                $log = $runDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.log';
                $evidenceLog = $evidenceDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.log';
                $junit = $evidenceDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.junit.xml';
                $this->writeTemporaryConfiguration($configuration, $database);
                $manifestShards[] = [
                    'index' => $index,
                    'database' => $database,
                    'configuration' => $configuration,
                    'log' => $log,
                    'evidence_log' => $evidenceLog,
                    'junit' => $junit,
                    'test_file_count' => count($files),
                ];
            }

            $pdo = $this->connect($settings);
            if ($useReusableSurfaceTemplate) {
                $this->acquireTemplateLock($pdo);
                $templateLockHeld = true;
                $template = $this->ensureReusableSurfaceTemplate($pdo, $settings, $token, $runDirectory);
            }
            try {
                foreach ($manifestShards as $shard) {
                    if ($template === null) {
                        $this->createDatabase($pdo, $shard['database']);
                    } else {
                        $this->createDatabaseFromTemplate($pdo, $shard['database'], $template['database']);
                    }
                    $createdDatabases[] = $shard['database'];
                }
                if ($template !== null) {
                    $this->pruneReusableSurfaceTemplates($pdo, $template['database']);
                }
            } finally {
                if ($templateLockHeld) {
                    $this->releaseTemplateLock($pdo);
                    $templateLockHeld = false;
                }
            }

            $manifest = $runDirectory.'/manifest.json';
            $manifestPayload = [
                'token' => $token,
                'directory' => $runDirectory,
                'evidence_directory' => $evidenceDirectory,
                'scope' => $scope,
                'shard_total' => $shardTotal,
                'discovered_count' => count($selected),
                'shards' => $manifestShards,
            ];
            if ($template !== null) {
                $manifestPayload['reusable_surface_template'] = $template;
            }
            $payload = json_encode(
                $manifestPayload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
            if (file_put_contents($manifest, $payload."\n", LOCK_EX) === false) {
                throw new RuntimeException("Unable to write parallel test manifest [{$manifest}].");
            }

            return $manifest;
        } catch (Throwable $exception) {
            if ($templateLockHeld && $pdo instanceof PDO) {
                try {
                    $this->releaseTemplateLock($pdo);
                } catch (Throwable) {
                    // The original preparation failure remains authoritative.
                }
            }
            $cleanupFailures = [];
            foreach ($pdo instanceof PDO ? array_reverse($createdDatabases) : [] as $database) {
                try {
                    $this->dropDatabase($pdo, $database);
                } catch (Throwable $cleanupException) {
                    $cleanupFailures[] = $cleanupException->getMessage();
                }
            }

            if ($cleanupFailures === []) {
                $this->removeDirectory($runDirectory);
            }

            $suffix = $cleanupFailures === [] ? '' : ' Cleanup also failed: '.implode(' | ', $cleanupFailures);
            throw new RuntimeException($exception->getMessage().$suffix, 0, $exception);
        }
    }

    /** @return array{configuration: string, database: string, log: string, evidence_log?: string, junit?: string}|null */
    public function shard(string $manifestPath, int $index): ?array
    {
        $manifest = $this->loadAndValidateManifest($manifestPath);
        foreach ($manifest['shards'] as $shard) {
            if ($shard['index'] === $index) {
                if (! is_file($shard['configuration'])
                    || is_link($shard['configuration'])
                    || is_link($shard['log'])
                    || (file_exists($shard['log']) && ! is_file($shard['log']))
                    || (isset($shard['evidence_log'])
                        && (is_link($shard['evidence_log'])
                            || (file_exists($shard['evidence_log']) && ! is_file($shard['evidence_log']))))
                    || (isset($shard['junit'])
                        && (is_link($shard['junit'])
                            || (file_exists($shard['junit']) && ! is_file($shard['junit']))))) {
                    throw new RuntimeException('Parallel test shard files failed their safety validation.');
                }

                $result = [
                    'configuration' => $shard['configuration'],
                    'database' => $shard['database'],
                    'log' => $shard['log'],
                ];
                if (isset($shard['evidence_log'])) {
                    $result['evidence_log'] = $shard['evidence_log'];
                }
                if (isset($shard['junit'])) {
                    $result['junit'] = $shard['junit'];
                }

                return $result;
            }
        }

        return null;
    }

    public function fixtureArtifact(
        string $manifestPath,
        int $index,
        string $profile,
        string $field,
    ): ?string {
        $profile = TestShardPlanner::normalizeFixtureProfile($profile);
        $shard = $this->shard($manifestPath, $index);
        if ($shard === null) {
            return null;
        }

        $stem = 'phpunit-'.sprintf('%02d', $index + 1).'-'.$profile;

        return match ($field) {
            'log' => dirname($shard['log']).'/'.$stem.'.log',
            'completion' => dirname($shard['log']).'/'.$stem.'.completed',
            'evidence_log' => isset($shard['evidence_log'])
                ? dirname($shard['evidence_log']).'/'.$stem.'.log'
                : null,
            'junit' => isset($shard['junit'])
                ? dirname($shard['junit']).'/'.$stem.'.junit.xml'
                : null,
            'fixture_metrics' => isset($shard['junit'])
                ? dirname($shard['junit']).'/'.$stem.'.fixture.tsv'
                : null,
            default => throw new InvalidArgumentException("Parallel test fixture artifact field [{$field}] is invalid."),
        };
    }

    public function cleanup(string $manifestPath): void
    {
        $manifest = $this->loadAndValidateManifest($manifestPath);
        $settings = $this->databaseSettings();
        $pdo = $this->connect($settings);
        $failures = [];

        foreach (array_reverse($manifest['shards']) as $shard) {
            try {
                $this->dropDatabase($pdo, $shard['database']);
            } catch (Throwable $exception) {
                $failures[] = $exception->getMessage();
            }
        }

        if ($failures !== []) {
            throw new RuntimeException(
                'Parallel test database cleanup failed; the manifest was preserved for a safe retry: '.implode(' | ', $failures),
            );
        }

        $this->removeDirectory($manifest['directory']);
    }

    public function evidenceDirectory(string $manifestPath): ?string
    {
        $manifest = $this->loadAndValidateManifest($manifestPath);

        return $manifest['evidence_directory'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function reusableSurfaceTemplate(string $manifestPath): ?array
    {
        $manifest = $this->loadAndValidateManifest($manifestPath);

        return $manifest['reusable_surface_template'] ?? null;
    }

    public function finalizeEvidence(
        string $token,
        int $testExitCode,
        int $cleanupExitCode,
        int $discoveredTestFiles,
    ): int {
        if (preg_match('/^[a-f0-9]{8}$/', $token) !== 1
            || $testExitCode < 0 || $testExitCode > 255
            || $cleanupExitCode < 0 || $cleanupExitCode > 255
            || $discoveredTestFiles < 0) {
            throw new InvalidArgumentException('Parallel test evidence finalization input is invalid.');
        }

        $this->ensureEvidenceRootDirectory();
        $evidenceDirectory = $this->evidenceRootDirectory.'/phpunit-parallel-'.$token;
        $resolvedDirectory = realpath($evidenceDirectory);
        if ($resolvedDirectory === false
            || is_link($evidenceDirectory)
            || TestShardPlanner::normalizePath($resolvedDirectory) !== $evidenceDirectory
            || TestShardPlanner::normalizePath(dirname($resolvedDirectory)) !== $this->evidenceRootDirectory) {
            throw new RuntimeException('Parallel test evidence directory failed its safety validation.');
        }
        $metadata = $evidenceDirectory.'/run.tsv';
        if (! is_file($metadata) || is_link($metadata)) {
            throw new RuntimeException('Parallel test evidence metadata failed its safety validation.');
        }

        $finalExitCode = $testExitCode === 0 && $cleanupExitCode === 0 ? 0 : 1;
        $status = $finalExitCode === 0 ? 'passed' : 'failed';
        $handle = fopen($metadata, 'r+b');
        if ($handle === false) {
            throw new RuntimeException('Unable to open parallel test evidence metadata.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock parallel test evidence metadata.');
            }
            $contents = stream_get_contents($handle);
            if (! is_string($contents) || preg_match('/^run\t/m', $contents) === 1) {
                throw new RuntimeException('Parallel test final evidence was already recorded or is unreadable.');
            }
            if (fseek($handle, 0, SEEK_END) !== 0
                || fwrite($handle, "run\t-\t{$status}\t{$finalExitCode}\t-\t{$discoveredTestFiles}\t-\t-\t-\n") === false
                || ! fflush($handle)) {
                throw new RuntimeException('Unable to finalize parallel test evidence metadata.');
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $finalExitCode;
    }

    public static function databaseName(string $token, int $zeroBasedIndex): string
    {
        if (preg_match('/^[a-f0-9]{8}$/', $token) !== 1 || $zeroBasedIndex < 0 || $zeroBasedIndex > 63) {
            throw new InvalidArgumentException('Parallel test database token or shard index is invalid.');
        }

        return 'hakoniwa_parallel_'.$token.'_'.sprintf('%02d', $zeroBasedIndex + 1).'_test';
    }

    public static function isSafeDatabaseName(string $database): bool
    {
        return preg_match(self::DATABASE_PATTERN, $database) === 1;
    }

    public static function isSafeTemplateDatabaseName(string $database): bool
    {
        return preg_match(self::TEMPLATE_DATABASE_PATTERN, $database) === 1;
    }

    public static function isSafeTemplateBuildDatabaseName(string $database): bool
    {
        return preg_match(self::TEMPLATE_BUILD_DATABASE_PATTERN, $database) === 1;
    }

    /**
     * @return array{
     *     token: string,
     *     directory: string,
     *     evidence_directory?: string,
     *     scope?: string,
     *     shard_total: int,
     *     discovered_count: int,
     *     shards: list<array{index: int, database: string, configuration: string, log: string, evidence_log?: string, junit?: string, test_file_count?: int}>,
     *     reusable_surface_template?: array<string, mixed>
     * }
     */
    private function loadAndValidateManifest(string $manifestPath): array
    {
        $resolvedManifest = realpath($manifestPath);
        if ($resolvedManifest === false || ! is_file($resolvedManifest)) {
            throw new RuntimeException("Parallel test manifest [{$manifestPath}] does not exist.");
        }

        $resolvedManifest = TestShardPlanner::normalizePath($resolvedManifest);
        $workspacePrefix = TestShardPlanner::normalizePath(realpath($this->workspaceDirectory) ?: $this->workspaceDirectory).'/';
        if (! str_starts_with($resolvedManifest, $workspacePrefix) || basename($resolvedManifest) !== 'manifest.json') {
            throw new RuntimeException('Refusing to use a parallel test manifest outside the test workspace.');
        }

        $decoded = json_decode((string) file_get_contents($resolvedManifest), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Parallel test manifest must contain a JSON object.');
        }

        $token = $decoded['token'] ?? null;
        $directory = isset($decoded['directory']) ? TestShardPlanner::normalizePath((string) $decoded['directory']) : null;
        $evidenceDirectory = isset($decoded['evidence_directory'])
            ? TestShardPlanner::normalizePath((string) $decoded['evidence_directory'])
            : null;
        $scope = $decoded['scope'] ?? 'full';
        $shardTotal = $decoded['shard_total'] ?? null;
        $discoveredCount = $decoded['discovered_count'] ?? null;
        $shards = $decoded['shards'] ?? null;
        $expectedDirectory = dirname($resolvedManifest);
        $expectedEvidenceDirectory = is_string($token)
            ? $this->evidenceRootDirectory.'/phpunit-parallel-'.$token
            : null;

        if (! is_string($token)
            || preg_match('/^[a-f0-9]{8}$/', $token) !== 1
            || $directory !== $expectedDirectory
            || basename($directory) !== 'phpunit-parallel-'.$token
            || ($evidenceDirectory !== null
                && $evidenceDirectory !== $expectedEvidenceDirectory)
            || ! is_string($scope)
            || TestShardPlanner::normalizeScope($scope) !== $scope
            || ! is_int($shardTotal)
            || $shardTotal < 1
            || $shardTotal > 64
            || ! is_int($discoveredCount)
            || $discoveredCount < 0
            || ! is_array($shards)) {
            throw new RuntimeException('Parallel test manifest metadata is invalid.');
        }

        $validatedShards = [];
        $seenIndexes = [];
        $seenDatabases = [];
        foreach ($shards as $shard) {
            if (! is_array($shard)) {
                throw new RuntimeException('Parallel test manifest contains an invalid shard record.');
            }

            $index = $shard['index'] ?? null;
            $database = $shard['database'] ?? null;
            $configuration = isset($shard['configuration'])
                ? TestShardPlanner::normalizePath((string) $shard['configuration'])
                : null;
            $log = isset($shard['log']) ? TestShardPlanner::normalizePath((string) $shard['log']) : null;
            $evidenceLog = isset($shard['evidence_log'])
                ? TestShardPlanner::normalizePath((string) $shard['evidence_log'])
                : null;
            $junit = isset($shard['junit'])
                ? TestShardPlanner::normalizePath((string) $shard['junit'])
                : null;
            $testFileCount = $shard['test_file_count'] ?? null;

            if (! is_int($index)
                || $index < 0
                || $index >= $shardTotal
                || ! is_string($database)
                || $database !== self::databaseName($token, $index)
                || ! is_string($configuration)
                || dirname($configuration) !== $directory
                || basename($configuration) !== 'phpunit-'.sprintf('%02d', $index + 1).'.xml'
                || ! is_string($log)
                || dirname($log) !== $directory
                || basename($log) !== 'phpunit-'.sprintf('%02d', $index + 1).'.log'
                || ($evidenceDirectory === null && ($evidenceLog !== null || $junit !== null))
                || ($evidenceDirectory !== null
                    && ($evidenceLog === null
                        || dirname($evidenceLog) !== $evidenceDirectory
                        || basename($evidenceLog) !== 'phpunit-'.sprintf('%02d', $index + 1).'.log'
                        || $junit === null
                        || dirname($junit) !== $evidenceDirectory
                        || basename($junit) !== 'phpunit-'.sprintf('%02d', $index + 1).'.junit.xml'))
                || ($testFileCount !== null && (! is_int($testFileCount) || $testFileCount < 0))
                || isset($seenIndexes[$index])
                || isset($seenDatabases[$database])) {
                throw new RuntimeException('Parallel test manifest failed its database safety validation.');
            }

            $seenIndexes[$index] = true;
            $seenDatabases[$database] = true;
            $validatedShard = compact('index', 'database', 'configuration', 'log');
            if ($evidenceLog !== null) {
                $validatedShard['evidence_log'] = $evidenceLog;
            }
            if ($junit !== null) {
                $validatedShard['junit'] = $junit;
            }
            if ($testFileCount !== null) {
                $validatedShard['test_file_count'] = $testFileCount;
            }
            $validatedShards[] = $validatedShard;
        }

        $result = [
            'token' => $token,
            'directory' => $directory,
            'scope' => $scope,
            'shard_total' => $shardTotal,
            'discovered_count' => $discoveredCount,
            'shards' => $validatedShards,
        ];
        if ($evidenceDirectory !== null) {
            $result['evidence_directory'] = $evidenceDirectory;
        }
        $template = $this->validateTemplateManifestMetadata(
            $decoded['reusable_surface_template'] ?? null,
            $directory,
        );
        if ($template !== null) {
            $result['reusable_surface_template'] = $template;
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function validateTemplateManifestMetadata(mixed $raw, string $runDirectory): ?array
    {
        if ($raw === null) {
            return null;
        }
        if (! is_array($raw)) {
            throw new RuntimeException('Parallel test manifest has invalid reusable surface template metadata.');
        }

        $fingerprint = $raw['fingerprint'] ?? null;
        $database = $raw['database'] ?? null;
        $cacheHit = $raw['cache_hit'] ?? null;
        $inputCount = $raw['input_count'] ?? null;
        $inputsSha256 = $raw['inputs_sha256'] ?? null;
        $buildDatabase = $raw['build_database'] ?? null;
        $buildSeconds = $raw['build_seconds'] ?? null;
        $buildMigrationSeconds = $raw['build_migration_seconds'] ?? null;
        $buildMapGenerationCount = $raw['build_map_generation_count'] ?? null;
        $buildMapGenerationSeconds = $raw['build_map_generation_seconds'] ?? null;
        $buildLog = isset($raw['build_log']) ? TestShardPlanner::normalizePath((string) $raw['build_log']) : null;
        $buildMetrics = isset($raw['build_metrics'])
            ? TestShardPlanner::normalizePath((string) $raw['build_metrics'])
            : null;
        $expectedKeys = [
            'fingerprint', 'database', 'cache_hit', 'input_count', 'inputs_sha256',
            'build_database', 'build_seconds', 'build_migration_seconds',
            'build_map_generation_count', 'build_map_generation_seconds', 'build_log', 'build_metrics',
        ];
        $validBuildMetrics = is_int($buildMapGenerationCount)
            && $buildMapGenerationCount >= 0
            && (is_int($buildSeconds) || is_float($buildSeconds))
            && $buildSeconds >= 0
            && (is_int($buildMigrationSeconds) || is_float($buildMigrationSeconds))
            && $buildMigrationSeconds >= 0
            && (is_int($buildMapGenerationSeconds) || is_float($buildMapGenerationSeconds))
            && $buildMapGenerationSeconds >= 0;

        if (count($raw) !== count($expectedKeys)
            || array_diff(array_keys($raw), $expectedKeys) !== []
            || ! is_string($fingerprint)
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1
            || ! is_string($database)
            || ! self::isSafeTemplateDatabaseName($database)
            || $database !== self::templateDatabaseName($fingerprint)
            || ! is_bool($cacheHit)
            || ! is_int($inputCount)
            || $inputCount < 1
            || ! is_string($inputsSha256)
            || preg_match('/^[a-f0-9]{64}$/', $inputsSha256) !== 1
            || ! $validBuildMetrics) {
            throw new RuntimeException('Parallel test manifest has invalid reusable surface template metadata.');
        }

        if ($cacheHit) {
            if ($buildDatabase !== null || $buildLog !== null || $buildMetrics !== null
                || $buildSeconds != 0 || $buildMigrationSeconds != 0
                || $buildMapGenerationCount !== 0 || $buildMapGenerationSeconds != 0) {
                throw new RuntimeException('Reusable surface template cache-hit metadata is invalid.');
            }
        } elseif (! is_string($buildDatabase)
            || ! self::isSafeTemplateBuildDatabaseName($buildDatabase)
            || $buildLog !== $runDirectory.'/reusable-surface-template.log'
            || $buildMetrics !== $runDirectory.'/reusable-surface-template.fixture.tsv'
            || $buildMapGenerationCount !== 1) {
            throw new RuntimeException('Reusable surface template build metadata is invalid.');
        }

        $raw['build_log'] = $buildLog;
        $raw['build_metrics'] = $buildMetrics;

        return $raw;
    }

    /**
     * @return array{host: string, port: string, username: string, password: string}
     */
    private function databaseSettings(): array
    {
        $document = $this->loadConfiguration();
        $xpath = new DOMXPath($document);
        $values = [];
        $forced = [];

        foreach ($xpath->query('/phpunit/php/env') ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $name = $node->getAttribute('name');
            $xmlValue = $node->getAttribute('value');
            $isForced = strtolower($node->getAttribute('force')) === 'true';
            $environmentValue = getenv($name);
            $values[$name] = ! $isForced && $environmentValue !== false ? $environmentValue : $xmlValue;
            $forced[$name] = $isForced;
        }

        if (($values['APP_ENV'] ?? null) !== 'testing'
            || ! ($forced['APP_ENV'] ?? false)
            || ($values['DB_CONNECTION'] ?? null) !== 'pgsql'
            || ! ($forced['DB_CONNECTION'] ?? false)
            || ($values['DB_HOST'] ?? null) !== 'hakoniwa-postgres'
            || ! ($forced['DB_HOST'] ?? false)
            || ($values['DB_PORT'] ?? null) !== '5432'
            || ! ($forced['DB_PORT'] ?? false)
            || ($values['DB_DATABASE'] ?? null) !== 'hakoniwa_test'
            || ! ($forced['DB_DATABASE'] ?? false)) {
            throw new RuntimeException('Canonical phpunit.xml no longer enforces the isolated PostgreSQL test contract.');
        }

        $memoryLimit = $xpath->query('/phpunit/php/ini[@name="memory_limit"]')->item(0);
        if (! $memoryLimit instanceof DOMElement || $memoryLimit->getAttribute('value') !== '512M') {
            throw new RuntimeException('Canonical phpunit.xml no longer enforces memory_limit=512M.');
        }

        $host = (string) $values['DB_HOST'];
        $port = (string) $values['DB_PORT'];
        $username = (string) ($values['DB_USERNAME'] ?? '');
        $password = getenv('DB_PASSWORD');
        if (preg_match('/^[0-9]{1,5}$/', $port) !== 1 || $username === '') {
            throw new RuntimeException('Canonical phpunit.xml is missing safe PostgreSQL connection settings.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password === false ? '' : $password,
        ];
    }

    private function ensureEvidenceRootDirectory(): void
    {
        if (is_link($this->workspaceDirectory)
            || (file_exists($this->workspaceDirectory) && ! is_dir($this->workspaceDirectory))
            || (! is_dir($this->workspaceDirectory) && ! mkdir($this->workspaceDirectory, 0700, true))) {
            throw new RuntimeException('Unable to create the parallel test workspace root safely.');
        }
        $resolvedWorkspace = realpath($this->workspaceDirectory);
        if ($resolvedWorkspace === false
            || TestShardPlanner::normalizePath($resolvedWorkspace) !== $this->workspaceDirectory) {
            throw new RuntimeException('Parallel test workspace root failed its safety validation.');
        }

        if (is_link($this->evidenceRootDirectory)
            || (file_exists($this->evidenceRootDirectory) && ! is_dir($this->evidenceRootDirectory))
            || (! is_dir($this->evidenceRootDirectory) && ! mkdir($this->evidenceRootDirectory, 0700))) {
            throw new RuntimeException('Unable to create the parallel test evidence root safely.');
        }
        $resolvedEvidenceRoot = realpath($this->evidenceRootDirectory);
        if ($resolvedEvidenceRoot === false
            || TestShardPlanner::normalizePath($resolvedEvidenceRoot) !== $this->evidenceRootDirectory
            || TestShardPlanner::normalizePath(dirname($resolvedEvidenceRoot)) !== $this->workspaceDirectory) {
            throw new RuntimeException('Parallel test evidence root failed its safety validation.');
        }
    }

    /** @param array{host: string, port: string, username: string, password: string} $settings */
    private function connect(array $settings): PDO
    {
        try {
            return new PDO(
                "pgsql:host={$settings['host']};port={$settings['port']};dbname=postgres",
                $settings['username'],
                $settings['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to connect to the PostgreSQL administrative database for isolated local tests.',
                0,
                $exception,
            );
        }
    }

    /**
     * @param  array{host: string, port: string, username: string, password: string}  $settings
     * @return array<string, mixed>
     */
    private function ensureReusableSurfaceTemplate(
        PDO $pdo,
        array $settings,
        string $token,
        string $runDirectory,
    ): array {
        $fingerprintResult = (new ReusableSurfaceTemplateFingerprint($this->projectRoot))->calculate([
            'php_version' => PHP_VERSION,
            'postgres_server_version' => (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'fixture_profile' => 'debug-32x32',
        ]);
        $fingerprint = $fingerprintResult['fingerprint'];
        $templateDatabase = self::templateDatabaseName($fingerprint);
        $this->dropStaleTemplateBuildDatabases($pdo);

        if ($this->databaseExists($pdo, $templateDatabase)) {
            try {
                $this->validateReusableSurfaceTemplate($settings, $templateDatabase, $fingerprint);

                return [
                    'fingerprint' => $fingerprint,
                    'database' => $templateDatabase,
                    'cache_hit' => true,
                    'input_count' => count($fingerprintResult['files']),
                    'inputs_sha256' => hash('sha256', implode("\n", $fingerprintResult['files'])),
                    'build_database' => null,
                    'build_seconds' => 0.0,
                    'build_migration_seconds' => 0.0,
                    'build_map_generation_count' => 0,
                    'build_map_generation_seconds' => 0.0,
                    'build_log' => null,
                    'build_metrics' => null,
                ];
            } catch (Throwable) {
                $this->dropTemplateDatabase($pdo, $templateDatabase);
            }
        }

        $buildDatabase = 'hakoniwa_surface_fixture_build_'.$token.'_test';
        if (! self::isSafeTemplateBuildDatabaseName($buildDatabase)) {
            throw new RuntimeException('Reusable surface template build database identity is invalid.');
        }
        $buildConfiguration = $runDirectory.'/reusable-surface-template.xml';
        $buildLog = $runDirectory.'/reusable-surface-template.log';
        $buildMetrics = $runDirectory.'/reusable-surface-template.fixture.tsv';
        $buildStderr = $runDirectory.'/reusable-surface-template.stderr.log';
        $buildStartedAt = hrtime(true);
        $buildCreated = false;
        $templateCreated = false;

        try {
            $this->createTemplateBuildDatabase($pdo, $buildDatabase);
            $buildCreated = true;
            $this->writeTemporaryConfiguration($buildConfiguration, $buildDatabase);
            $environment = getenv();
            if (! is_array($environment)) {
                $environment = [];
            }
            $environment['APP_ENV'] = 'testing';
            $environment['DB_CONNECTION'] = 'pgsql';
            $environment['DB_DATABASE'] = $buildDatabase;
            $environment['HAKONIWA_TEST_FIXTURE_PROFILE'] = 'reusable_surface';
            $environment['HAKONIWA_TEST_FIXTURE_METRICS'] = $buildMetrics;
            $environment['HAKONIWA_REUSABLE_SURFACE_TEMPLATE_MODE'] = 'build';
            $environment['HAKONIWA_REUSABLE_SURFACE_TEMPLATE_FINGERPRINT'] = $fingerprint;
            $process = proc_open([
                PHP_BINARY,
                '-d',
                'memory_limit=512M',
                $this->projectRoot.'/vendor/bin/phpunit',
                '--configuration',
                $buildConfiguration,
                '--colors=never',
                $this->projectRoot.'/tests/Support/ReusableSurfaceTemplateBuilderTest.php',
            ], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $buildLog, 'wb'],
                2 => ['file', $buildStderr, 'wb'],
            ], $pipes, $this->projectRoot, $environment);
            if (! is_resource($process)) {
                throw new RuntimeException('Unable to start the reusable surface template builder.');
            }
            $buildExitCode = proc_close($process);
            if (is_file($buildStderr)) {
                $stderr = file_get_contents($buildStderr);
                if (is_string($stderr) && $stderr !== '') {
                    file_put_contents($buildLog, "\n".$stderr, FILE_APPEND | LOCK_EX);
                }
                unlink($buildStderr);
            }
            if ($buildExitCode !== 0) {
                throw new RuntimeException(
                    "Reusable surface template builder failed with exit code {$buildExitCode}; see {$buildLog}.",
                );
            }
            $metrics = $this->readFixtureMetrics($buildMetrics);
            if (($metrics['fixture_available'] ?? null) !== '1'
                || ($metrics['map_generation_count'] ?? null) !== '1') {
                throw new RuntimeException('Reusable surface template builder did not produce one verified map baseline.');
            }
            $this->validateReusableSurfaceTemplate($settings, $buildDatabase, $fingerprint);
            $pdo->exec(
                'ALTER DATABASE '.$this->quoteIdentifier($buildDatabase)
                .' RENAME TO '.$this->quoteIdentifier($templateDatabase),
            );
            $buildCreated = false;
            $templateCreated = true;
            $this->validateReusableSurfaceTemplate($settings, $templateDatabase, $fingerprint);
        } catch (Throwable $exception) {
            $failedDatabase = $templateCreated
                ? $templateDatabase
                : ($buildCreated ? $buildDatabase : null);
            if ($failedDatabase !== null) {
                try {
                    $this->dropTemplateDatabase($pdo, $failedDatabase);
                } catch (Throwable $cleanupException) {
                    throw new RuntimeException(
                        $exception->getMessage().' Template build cleanup also failed: '.$cleanupException->getMessage(),
                        0,
                        $exception,
                    );
                }
            }

            throw $exception;
        } finally {
            if (is_file($buildStderr)) {
                unlink($buildStderr);
            }
        }

        $metrics = $this->readFixtureMetrics($buildMetrics);

        return [
            'fingerprint' => $fingerprint,
            'database' => $templateDatabase,
            'cache_hit' => false,
            'input_count' => count($fingerprintResult['files']),
            'inputs_sha256' => hash('sha256', implode("\n", $fingerprintResult['files'])),
            'build_database' => $buildDatabase,
            'build_seconds' => (hrtime(true) - $buildStartedAt) / 1_000_000_000,
            'build_migration_seconds' => (float) ($metrics['migration_seconds'] ?? 0.0),
            'build_map_generation_count' => (int) ($metrics['map_generation_count'] ?? 0),
            'build_map_generation_seconds' => (float) ($metrics['map_generation_seconds'] ?? 0.0),
            'build_log' => $buildLog,
            'build_metrics' => $buildMetrics,
        ];
    }

    private static function templateDatabaseName(string $fingerprint): string
    {
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new InvalidArgumentException('Reusable surface template fingerprint is invalid.');
        }

        return 'hakoniwa_surface_fixture_'.substr($fingerprint, 0, 16).'_template';
    }

    private function acquireTemplateLock(PDO $pdo): void
    {
        $pdo->query('SELECT pg_advisory_lock('.self::TEMPLATE_LOCK_KEY.')')->fetchColumn();
    }

    private function releaseTemplateLock(PDO $pdo): void
    {
        $released = $pdo->query('SELECT pg_advisory_unlock('.self::TEMPLATE_LOCK_KEY.')')->fetchColumn();
        if ($released !== true) {
            throw new RuntimeException('Reusable surface template advisory lock was not held.');
        }
    }

    private function databaseExists(PDO $pdo, string $database): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :database');
        $statement->execute(['database' => $database]);

        return $statement->fetchColumn() !== false;
    }

    private function createTemplateBuildDatabase(PDO $pdo, string $database): void
    {
        if (! self::isSafeTemplateBuildDatabaseName($database)) {
            throw new RuntimeException("Refusing to create unsafe template build database [{$database}].");
        }
        $pdo->exec('CREATE DATABASE '.$this->quoteIdentifier($database));
    }

    private function createDatabaseFromTemplate(PDO $pdo, string $database, string $template): void
    {
        if (! self::isSafeDatabaseName($database) || ! self::isSafeTemplateDatabaseName($template)) {
            throw new RuntimeException('Refusing to clone an unsafe reusable surface template database.');
        }
        $pdo->exec(
            'CREATE DATABASE '.$this->quoteIdentifier($database).' TEMPLATE '.$this->quoteIdentifier($template),
        );
    }

    /** @param array{host: string, port: string, username: string, password: string} $settings */
    private function validateReusableSurfaceTemplate(array $settings, string $database, string $fingerprint): void
    {
        if ((! self::isSafeTemplateDatabaseName($database)
                && ! self::isSafeTemplateBuildDatabaseName($database))
            || preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new RuntimeException('Refusing to validate an unsafe reusable surface template database.');
        }
        $connection = new PDO(
            "pgsql:host={$settings['host']};port={$settings['port']};dbname={$database}",
            $settings['username'],
            $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $marker = $connection->query(
            'SELECT fingerprint, profile, world_key, cell_count '
            .'FROM hakoniwa_test_fixture_metadata WHERE singleton = 1',
        )->fetch(PDO::FETCH_ASSOC);
        if (! is_array($marker)
            || ! hash_equals($fingerprint, (string) ($marker['fingerprint'] ?? ''))
            || ($marker['profile'] ?? null) !== 'debug-32x32'
            || (int) ($marker['cell_count'] ?? 0) !== 1024
            || ($marker['world_key'] ?? null) !== 'shared-world') {
            throw new RuntimeException('Reusable surface template marker validation failed.');
        }
        $cellCount = (int) $connection->query(
            'SELECT count(*) FROM map_cells c JOIN map_spaces s ON s.id = c.map_space_id '
            ."JOIN worlds w ON w.id = s.world_id WHERE w.key = 'shared-world'",
        )->fetchColumn();
        $completed = (int) $connection->query(
            'SELECT count(*) FROM world_generation_runs r JOIN map_spaces s ON s.id = r.map_space_id '
            ."JOIN worlds w ON w.id = s.world_id WHERE w.key = 'shared-world' AND r.status = 'completed'",
        )->fetchColumn();
        if ($cellCount !== 1024 || $completed !== 1) {
            throw new RuntimeException('Reusable surface template World baseline validation failed.');
        }
        $connection = null;
    }

    /** @return array<string, string> */
    private function readFixtureMetrics(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException('Reusable surface template fixture metrics are missing or unsafe.');
        }
        $metrics = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            [$key, $value] = array_pad(explode("\t", $line, 2), 2, null);
            if (is_string($key) && $key !== '' && is_string($value)) {
                $metrics[$key] = $value;
            }
        }

        return $metrics;
    }

    private function dropStaleTemplateBuildDatabases(PDO $pdo): void
    {
        foreach ($pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'hakoniwa_surface_fixture_build_%'") as $row) {
            $database = (string) $row['datname'];
            if (self::isSafeTemplateBuildDatabaseName($database)) {
                $this->dropTemplateDatabase($pdo, $database);
            }
        }
    }

    private function pruneReusableSurfaceTemplates(PDO $pdo, string $current): void
    {
        if (! self::isSafeTemplateDatabaseName($current)) {
            throw new RuntimeException('Refusing to prune templates without a safe current identity.');
        }
        foreach ($pdo->query("SELECT datname FROM pg_database WHERE datname LIKE 'hakoniwa_surface_fixture_%_template'") as $row) {
            $database = (string) $row['datname'];
            if ($database !== $current && self::isSafeTemplateDatabaseName($database)) {
                $this->dropTemplateDatabase($pdo, $database);
            }
        }
    }

    private function dropTemplateDatabase(PDO $pdo, string $database): void
    {
        if (! self::isSafeTemplateDatabaseName($database)
            && ! self::isSafeTemplateBuildDatabaseName($database)) {
            throw new RuntimeException("Refusing to drop unsafe template database [{$database}].");
        }
        $statement = $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()',
        );
        $statement->execute(['database' => $database]);
        $pdo->exec('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($database));
    }

    private function createDatabase(PDO $pdo, string $database): void
    {
        if (! self::isSafeDatabaseName($database)) {
            throw new RuntimeException("Refusing to create unsafe database name [{$database}].");
        }

        $pdo->exec('CREATE DATABASE '.$this->quoteIdentifier($database));
    }

    private function dropDatabase(PDO $pdo, string $database): void
    {
        if (! self::isSafeDatabaseName($database)) {
            throw new RuntimeException("Refusing to drop unsafe database name [{$database}].");
        }

        $statement = $pdo->prepare(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = :database AND pid <> pg_backend_pid()',
        );
        $statement->execute(['database' => $database]);
        $pdo->exec('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($database));
    }

    private function writeTemporaryConfiguration(string $destination, string $database): void
    {
        if (! self::isSafeDatabaseName($database)
            && ! self::isSafeTemplateBuildDatabaseName($database)) {
            throw new RuntimeException("Refusing to configure unsafe database name [{$database}].");
        }

        $document = $this->loadConfiguration();
        $xpath = new DOMXPath($document);
        $root = $document->documentElement;
        if (! $root instanceof DOMElement) {
            throw new RuntimeException('Canonical phpunit.xml has no root element.');
        }

        $bootstrap = $root->getAttribute('bootstrap');
        if ($bootstrap !== '') {
            $root->setAttribute('bootstrap', $this->absoluteProjectPath($bootstrap));
        }

        foreach ($xpath->query('/phpunit/testsuites/testsuite/directory | /phpunit/testsuites/testsuite/file | /phpunit/source/include/directory | /phpunit/source/include/file | /phpunit/source/exclude/directory | /phpunit/source/exclude/file') ?: [] as $pathNode) {
            $pathNode->textContent = $this->absoluteProjectPath(trim($pathNode->textContent));
        }

        $databaseNode = $xpath->query('/phpunit/php/env[@name="DB_DATABASE"]')->item(0);
        if (! $databaseNode instanceof DOMElement) {
            throw new RuntimeException('Canonical phpunit.xml is missing DB_DATABASE.');
        }
        $databaseNode->setAttribute('value', $database);
        $databaseNode->setAttribute('force', 'true');
        $document->formatOutput = true;

        if ($document->save($destination) === false) {
            throw new RuntimeException("Unable to write temporary PHPUnit configuration [{$destination}].");
        }
    }

    private function absoluteProjectPath(string $path): string
    {
        $normalized = TestShardPlanner::normalizePath($path);
        if (preg_match('#^(?:[A-Za-z]:/|/)#', $normalized) === 1) {
            return $normalized;
        }

        return $this->projectRoot.'/'.$normalized;
    }

    private function loadConfiguration(): DOMDocument
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->load($this->configurationPath, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new RuntimeException("PHPUnit configuration [{$this->configurationPath}] is not valid XML.");
        }

        return $document;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function removeDirectory(string $directory): void
    {
        $normalized = TestShardPlanner::normalizePath($directory);
        $workspacePrefix = TestShardPlanner::normalizePath(realpath($this->workspaceDirectory) ?: $this->workspaceDirectory).'/';
        if (! str_starts_with($normalized, $workspacePrefix)
            || preg_match('/^phpunit-parallel-[a-f0-9]{8}$/', basename($normalized)) !== 1) {
            throw new RuntimeException("Refusing to remove unsafe test workspace [{$directory}].");
        }

        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            throw new RuntimeException("Unable to inspect test workspace [{$directory}].");
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        $manifestContents = is_file($manifestPath) && ! is_link($manifestPath)
            ? file_get_contents($manifestPath)
            : false;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            if ($item === 'manifest.json' && $manifestContents !== false) {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectoryContents($path);
                if (! rmdir($path)) {
                    throw new RuntimeException("Unable to remove test workspace directory [{$path}].");
                }
            } elseif (! unlink($path)) {
                throw new RuntimeException("Unable to remove test workspace file [{$path}].");
            }
        }

        if ($manifestContents !== false && ! unlink($manifestPath)) {
            throw new RuntimeException("Unable to remove test workspace manifest [{$manifestPath}].");
        }

        if (! rmdir($directory)) {
            $restored = $manifestContents !== false
                && file_put_contents($manifestPath, $manifestContents, LOCK_EX) !== false;
            $suffix = $restored ? ' The cleanup manifest was restored.' : '';
            throw new RuntimeException("Unable to remove test workspace [{$directory}].{$suffix}");
        }
    }

    private function removeDirectoryContents(string $directory): void
    {
        $items = scandir($directory);
        if ($items === false) {
            throw new RuntimeException("Unable to inspect test workspace directory [{$directory}].");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectoryContents($path);
                if (! rmdir($path)) {
                    throw new RuntimeException("Unable to remove test workspace directory [{$path}].");
                }
            } elseif (! unlink($path)) {
                throw new RuntimeException("Unable to remove test workspace file [{$path}].");
            }
        }
    }
}
