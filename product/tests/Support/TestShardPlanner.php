<?php

namespace Tests\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class TestShardPlanner
{
    private readonly string $projectRoot;

    private readonly string $configurationPath;

    public function __construct(string $projectRoot, string $configurationPath = 'phpunit.xml')
    {
        $resolvedRoot = realpath($projectRoot);
        if ($resolvedRoot === false || ! is_dir($resolvedRoot)) {
            throw new InvalidArgumentException("Project root [{$projectRoot}] does not exist.");
        }

        $this->projectRoot = self::normalizePath($resolvedRoot);
        $this->configurationPath = $this->resolvePath($configurationPath, $this->projectRoot);
    }

    /** @return list<string> */
    public function discover(): array
    {
        $document = $this->loadConfiguration();
        $xpath = new DOMXPath($document);
        $files = [];

        foreach ($xpath->query('/phpunit/testsuites/testsuite/directory') ?: [] as $directoryNode) {
            if (! $directoryNode instanceof DOMElement) {
                continue;
            }

            $directory = $this->resolvePath(trim($directoryNode->textContent), dirname($this->configurationPath));
            if (! is_dir($directory)) {
                throw new RuntimeException("PHPUnit test directory [{$directory}] does not exist.");
            }

            $suffix = $directoryNode->getAttribute('suffix') ?: 'Test.php';
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), $suffix)) {
                    $relativePath = $this->relativePath($file->getPathname());
                    $files[$relativePath] = true;
                }
            }
        }

        foreach ($xpath->query('/phpunit/testsuites/testsuite/file') ?: [] as $fileNode) {
            $file = $this->resolvePath(trim($fileNode->textContent), dirname($this->configurationPath));
            if (! is_file($file)) {
                throw new RuntimeException("PHPUnit test file [{$file}] does not exist.");
            }

            $relativePath = $this->relativePath($file);
            $files[$relativePath] = true;
        }

        $discovered = array_keys($files);
        sort($discovered, SORT_STRING);
        if ($discovered === []) {
            throw new RuntimeException('PHPUnit test discovery returned no test files.');
        }

        return $discovered;
    }

    /**
     * @param  list<string>  $files
     * @return array<int, list<string>>
     */
    public function assign(array $files, int $shardTotal): array
    {
        if ($shardTotal < 1) {
            throw new InvalidArgumentException('Shard total must be at least 1.');
        }

        $normalized = array_map(self::normalizePath(...), $files);
        if (count($normalized) !== count(array_unique($normalized))) {
            throw new InvalidArgumentException('Discovered test files contain duplicate canonical paths.');
        }

        sort($normalized, SORT_STRING);
        $shards = array_fill(0, $shardTotal, []);

        foreach ($normalized as $offset => $file) {
            $shards[$offset % $shardTotal][] = $file;
        }

        return $shards;
    }

    /** @return array<int, list<string>> */
    public function plan(int $shardTotal): array
    {
        return $this->assign($this->discover(), $shardTotal);
    }

    /**
     * @param  list<string>  $discovered
     * @param  array<int, list<string>>  $shards
     * @return array{
     *     discovered_count: int,
     *     shard_count: int,
     *     shard_file_counts: array<int, int>,
     *     union_count: int,
     *     duplicate_count: int,
     *     missing_count: int,
     *     unexpected_count: int,
     *     duplicates: list<string>,
     *     missing: list<string>,
     *     unexpected: list<string>
     * }
     */
    public function coverageReport(array $discovered, array $shards): array
    {
        $discovered = array_map(self::normalizePath(...), $discovered);
        sort($discovered, SORT_STRING);
        $assigned = [];
        $shardFileCounts = [];

        foreach ($shards as $index => $files) {
            $normalizedFiles = array_map(self::normalizePath(...), $files);
            $shardFileCounts[$index] = count($normalizedFiles);
            array_push($assigned, ...$normalizedFiles);
        }

        $assignedCounts = array_count_values($assigned);
        $duplicates = array_keys(array_filter($assignedCounts, static fn (int $count): bool => $count > 1));
        $union = array_keys($assignedCounts);
        $missing = array_values(array_diff($discovered, $union));
        $unexpected = array_values(array_diff($union, $discovered));
        sort($duplicates, SORT_STRING);
        sort($missing, SORT_STRING);
        sort($unexpected, SORT_STRING);

        return [
            'discovered_count' => count($discovered),
            'shard_count' => count($shards),
            'shard_file_counts' => $shardFileCounts,
            'union_count' => count($union),
            'duplicate_count' => count($assigned) - count($union),
            'missing_count' => count($missing),
            'unexpected_count' => count($unexpected),
            'duplicates' => $duplicates,
            'missing' => $missing,
            'unexpected' => $unexpected,
        ];
    }

    /**
     * @return array{
     *     discovered_count: int,
     *     shard_count: int,
     *     shard_file_counts: array<int, int>,
     *     union_count: int,
     *     duplicate_count: int,
     *     missing_count: int,
     *     unexpected_count: int,
     *     duplicates: list<string>,
     *     missing: list<string>,
     *     unexpected: list<string>
     * }
     */
    public function verify(int $shardTotal): array
    {
        $discovered = $this->discover();
        $report = $this->coverageReport($discovered, $this->assign($discovered, $shardTotal));

        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Test shard coverage is incomplete or overlapping.');
        }

        return $report;
    }

    public static function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        while (str_starts_with($normalized, './')) {
            $normalized = substr($normalized, 2);
        }

        return rtrim($normalized, '/');
    }

    private function loadConfiguration(): DOMDocument
    {
        if (! is_file($this->configurationPath)) {
            throw new RuntimeException("PHPUnit configuration [{$this->configurationPath}] does not exist.");
        }

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

    private function relativePath(string $path): string
    {
        $normalized = self::normalizePath(realpath($path) ?: $path);
        $prefix = $this->projectRoot.'/';

        if (strncasecmp($normalized, $prefix, strlen($prefix)) !== 0) {
            throw new RuntimeException("Test file [{$normalized}] is outside project root [{$this->projectRoot}].");
        }

        return substr($normalized, strlen($prefix));
    }

    private function resolvePath(string $path, string $baseDirectory): string
    {
        $normalized = self::normalizePath($path);
        if (preg_match('#^(?:[A-Za-z]:/|/)#', $normalized) === 1) {
            return $normalized;
        }

        return self::normalizePath($baseDirectory.'/'.$normalized);
    }
}
