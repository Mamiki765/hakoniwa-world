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

    private readonly string $projectRoot;

    private readonly string $configurationPath;

    private readonly string $workspaceDirectory;

    public function __construct(string $projectRoot, string $configurationPath = 'phpunit.xml')
    {
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException("Project root [{$projectRoot}] does not exist.");
        }

        $this->projectRoot = TestShardPlanner::normalizePath($resolvedRoot);
        $this->configurationPath = $this->projectRoot.'/'.TestShardPlanner::normalizePath($configurationPath);
        $this->workspaceDirectory = $this->projectRoot.'/storage/framework/testing';
    }

    public function prepare(int $shardTotal, ?string $requestedToken = null): string
    {
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
        $discovered = $planner->discover();
        $shards = $planner->assign($discovered, $shardTotal);
        $report = $planner->coverageReport($discovered, $shards);
        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Refusing to prepare databases for an incomplete shard plan.');
        }

        if ($requestedToken !== null && preg_match('/^[a-f0-9]{8}$/', $requestedToken) !== 1) {
            throw new InvalidArgumentException('Parallel test database token is invalid.');
        }

        $settings = $this->databaseSettings();
        $token = $requestedToken ?? bin2hex(random_bytes(4));
        $runDirectory = $this->workspaceDirectory.'/phpunit-parallel-'.$token;
        if (file_exists($runDirectory) || is_link($runDirectory) || ! mkdir($runDirectory, 0700, true)) {
            throw new RuntimeException("Unable to create parallel test workspace [{$runDirectory}].");
        }

        $pdo = null;
        $createdDatabases = [];
        $manifestShards = [];

        try {
            foreach ($shards as $index => $files) {
                if ($files === []) {
                    continue;
                }

                $database = self::databaseName($token, $index);
                $configuration = $runDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.xml';
                $log = $runDirectory.'/phpunit-'.sprintf('%02d', $index + 1).'.log';
                $this->writeTemporaryConfiguration($configuration, $database);
                $manifestShards[] = [
                    'index' => $index,
                    'database' => $database,
                    'configuration' => $configuration,
                    'log' => $log,
                ];
            }

            $manifest = $runDirectory.'/manifest.json';
            $payload = json_encode([
                'token' => $token,
                'directory' => $runDirectory,
                'shard_total' => $shardTotal,
                'discovered_count' => count($discovered),
                'shards' => $manifestShards,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifest, $payload."\n", LOCK_EX) === false) {
                throw new RuntimeException("Unable to write parallel test manifest [{$manifest}].");
            }

            $pdo = $this->connect($settings);
            foreach ($manifestShards as $shard) {
                $this->createDatabase($pdo, $shard['database']);
                $createdDatabases[] = $shard['database'];
            }

            return $manifest;
        } catch (Throwable $exception) {
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

    /** @return array{configuration: string, database: string, log: string}|null */
    public function shard(string $manifestPath, int $index): ?array
    {
        $manifest = $this->loadAndValidateManifest($manifestPath);
        foreach ($manifest['shards'] as $shard) {
            if ($shard['index'] === $index) {
                if (! is_file($shard['configuration'])
                    || is_link($shard['configuration'])
                    || is_link($shard['log'])
                    || (file_exists($shard['log']) && ! is_file($shard['log']))) {
                    throw new RuntimeException('Parallel test shard files failed their safety validation.');
                }

                return [
                    'configuration' => $shard['configuration'],
                    'database' => $shard['database'],
                    'log' => $shard['log'],
                ];
            }
        }

        return null;
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

    /**
     * @return array{
     *     token: string,
     *     directory: string,
     *     shard_total: int,
     *     discovered_count: int,
     *     shards: list<array{index: int, database: string, configuration: string, log: string}>
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
        $shardTotal = $decoded['shard_total'] ?? null;
        $discoveredCount = $decoded['discovered_count'] ?? null;
        $shards = $decoded['shards'] ?? null;
        $expectedDirectory = dirname($resolvedManifest);

        if (! is_string($token)
            || preg_match('/^[a-f0-9]{8}$/', $token) !== 1
            || $directory !== $expectedDirectory
            || basename($directory) !== 'phpunit-parallel-'.$token
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
                || isset($seenIndexes[$index])
                || isset($seenDatabases[$database])) {
                throw new RuntimeException('Parallel test manifest failed its database safety validation.');
            }

            $seenIndexes[$index] = true;
            $seenDatabases[$database] = true;
            $validatedShards[] = compact('index', 'database', 'configuration', 'log');
        }

        return [
            'token' => $token,
            'directory' => $directory,
            'shard_total' => $shardTotal,
            'discovered_count' => $discoveredCount,
            'shards' => $validatedShards,
        ];
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

        $host = (string) ($values['DB_HOST'] ?? '');
        $port = (string) ($values['DB_PORT'] ?? '');
        $username = (string) ($values['DB_USERNAME'] ?? '');
        $password = getenv('DB_PASSWORD');
        if ($host === '' || preg_match('/^[0-9]{1,5}$/', $port) !== 1 || $username === '') {
            throw new RuntimeException('Canonical phpunit.xml is missing safe PostgreSQL connection settings.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password === false ? '' : $password,
        ];
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
        if (! self::isSafeDatabaseName($database)) {
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
