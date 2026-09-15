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
    /** @var array<string, list<string>> */
    private const SCOPE_SUITES = [
        'full' => ['Shared', 'Unit', 'Feature', 'Underground'],
        'surface' => ['Shared', 'Unit', 'Feature'],
        'underground' => ['Shared', 'Underground'],
    ];

    /** @var list<string> */
    private const FIXTURE_PROFILES = ['standard', 'reusable_surface', 'individual'];

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
    public function discover(string $scope = 'full'): array
    {
        $scope = self::normalizeScope($scope);
        $document = $this->loadConfiguration();
        $xpath = new DOMXPath($document);
        $files = [];
        $selectedSuites = array_fill_keys(self::SCOPE_SUITES[$scope], true);

        foreach ($xpath->query('/phpunit/testsuites/testsuite') ?: [] as $suiteNode) {
            if (! $suiteNode instanceof DOMElement) {
                continue;
            }

            $suiteName = $suiteNode->getAttribute('name');
            if (! in_array($suiteName, self::SCOPE_SUITES['full'], true)) {
                throw new RuntimeException("PHPUnit test suite [{$suiteName}] has no canonical scope.");
            }
            if (! isset($selectedSuites[$suiteName])) {
                continue;
            }

            foreach ($xpath->query('./directory', $suiteNode) ?: [] as $directoryNode) {
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
                        if (isset($files[$relativePath])) {
                            throw new RuntimeException("PHPUnit suites select test file [{$relativePath}] more than once.");
                        }
                        $files[$relativePath] = true;
                    }
                }
            }

            foreach ($xpath->query('./file', $suiteNode) ?: [] as $fileNode) {
                $file = $this->resolvePath(trim($fileNode->textContent), dirname($this->configurationPath));
                if (! is_file($file)) {
                    throw new RuntimeException("PHPUnit test file [{$file}] does not exist.");
                }

                $relativePath = $this->relativePath($file);
                if (isset($files[$relativePath])) {
                    throw new RuntimeException("PHPUnit suites select test file [{$relativePath}] more than once.");
                }
                $files[$relativePath] = true;
            }
        }

        $discovered = array_keys($files);
        sort($discovered, SORT_STRING);
        if ($discovered === []) {
            throw new RuntimeException("PHPUnit [{$scope}] test discovery returned no test files.");
        }

        return $discovered;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, float|int>  $weights
     * @return array<int, list<string>>
     */
    public function assign(array $files, int $shardTotal, array $weights = []): array
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

        $resolved = $this->resolveWeights($normalized, $weights);
        if ($resolved['strategy'] === 'lpt') {
            $loads = array_fill(0, $shardTotal, 0.0);
            usort($normalized, static function (string $left, string $right) use ($resolved): int {
                $weightComparison = $resolved['weights'][$right] <=> $resolved['weights'][$left];

                return $weightComparison !== 0 ? $weightComparison : strcmp($left, $right);
            });
            foreach ($normalized as $file) {
                $target = 0;
                for ($index = 1; $index < $shardTotal; $index++) {
                    if ($loads[$index] < $loads[$target]) {
                        $target = $index;
                    }
                }
                $shards[$target][] = $file;
                $loads[$target] += $resolved['weights'][$file];
            }

            return $shards;
        }

        foreach ($normalized as $offset => $file) {
            $shards[$offset % $shardTotal][] = $file;
        }

        return $shards;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, float|int>  $weights
     * @return array{strategy: 'deterministic_fallback'|'lpt', weights: array<string, float>, sources: array<string, string>}
     */
    public function resolveWeights(array $files, array $weights): array
    {
        $normalizedFiles = array_map(self::normalizePath(...), $files);
        sort($normalizedFiles, SORT_STRING);
        $provided = [];
        foreach ($weights as $file => $weight) {
            $normalized = self::normalizePath((string) $file);
            if (! in_array($normalized, $normalizedFiles, true)) {
                continue;
            }
            $numeric = (float) $weight;
            if (! is_finite($numeric) || $numeric <= 0) {
                throw new InvalidArgumentException("Test timing weight for [{$normalized}] must be positive and finite.");
            }
            $provided[$normalized] = $numeric;
        }
        if ($provided === []) {
            return [
                'strategy' => 'deterministic_fallback',
                'weights' => array_fill_keys($normalizedFiles, 1.0),
                'sources' => array_fill_keys($normalizedFiles, 'deterministic_fallback'),
            ];
        }

        $byProfile = array_fill_keys(self::FIXTURE_PROFILES, []);
        foreach ($provided as $file => $weight) {
            $byProfile[$this->fixtureProfile($file)][] = $weight;
        }
        $globalMedian = self::median(array_values($provided));
        $resolved = [];
        $sources = [];
        foreach ($normalizedFiles as $file) {
            if (isset($provided[$file])) {
                $resolved[$file] = $provided[$file];
                $sources[$file] = 'junit';

                continue;
            }
            $profileWeights = $byProfile[$this->fixtureProfile($file)];
            if ($profileWeights !== []) {
                $resolved[$file] = self::median($profileWeights);
                $sources[$file] = 'fixture_profile_median';
            } else {
                $resolved[$file] = $globalMedian;
                $sources[$file] = 'global_median';
            }
        }

        return ['strategy' => 'lpt', 'weights' => $resolved, 'sources' => $sources];
    }

    /**
     * @param  list<string>  $files
     * @param  list<string>  $junitPaths
     * @return array<string, float>
     */
    public function timingWeightsFromJunit(array $files, array $junitPaths): array
    {
        $allowed = array_fill_keys(array_map(self::normalizePath(...), $files), true);
        $weights = [];
        foreach ($junitPaths as $junitPath) {
            if (! is_file($junitPath) || is_link($junitPath)) {
                continue;
            }
            $document = new DOMDocument;
            $previous = libxml_use_internal_errors(true);
            $loaded = $document->load($junitPath, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            if (! $loaded) {
                continue;
            }
            $xpath = new DOMXPath($document);
            foreach ($xpath->query('//testcase[@file][@time]') ?: [] as $testcase) {
                if (! $testcase instanceof DOMElement) {
                    continue;
                }
                $file = $this->junitRelativePath($testcase->getAttribute('file'));
                $time = $testcase->getAttribute('time');
                if (! isset($allowed[$file]) || ! is_numeric($time) || (float) $time < 0) {
                    continue;
                }
                $weights[$file] = ($weights[$file] ?? 0.0) + (float) $time;
            }
        }
        foreach ($weights as $file => $weight) {
            $weights[$file] = max($weight, 0.000001);
        }
        ksort($weights, SORT_STRING);

        return $weights;
    }

    /**
     * @param  list<string>  $files
     * @return array{weights: array<string, float>, sources: list<string>}
     */
    public function historicalTiming(array $files, ?string $evidenceRoot = null): array
    {
        $root = $evidenceRoot === null
            ? $this->projectRoot.'/storage/framework/testing/test-evidence'
            : $this->resolvePath($evidenceRoot, $this->projectRoot);
        if (! is_dir($root) || is_link($root)) {
            return ['weights' => [], 'sources' => []];
        }
        $directories = array_values(array_filter(
            glob($root.'/phpunit-parallel-*', GLOB_ONLYDIR) ?: [],
            static fn (string $directory): bool => ! is_link($directory),
        ));
        usort($directories, static function (string $left, string $right): int {
            $modified = (filemtime($right) ?: 0) <=> (filemtime($left) ?: 0);

            return $modified !== 0 ? $modified : strcmp($right, $left);
        });

        $weights = [];
        $sources = [];
        $wanted = array_fill_keys(array_map(self::normalizePath(...), $files), true);
        foreach (array_slice($directories, 0, 64) as $directory) {
            $metadata = $directory.'/run.tsv';
            $contents = is_file($metadata) && ! is_link($metadata) ? file_get_contents($metadata) : false;
            if (! is_string($contents) || preg_match('/^run\t-\tpassed\t0\t/m', $contents) !== 1) {
                continue;
            }
            if (preg_match('/^selection_mode\t([^\t\r\n]+)$/m', $contents, $selection) === 1
                && ($selection[1] ?? null) !== 'scope') {
                continue;
            }
            $junitPaths = glob($directory.'/phpunit-[0-9][0-9].junit.xml') ?: [];
            sort($junitPaths, SORT_STRING);
            $candidate = $this->timingWeightsFromJunit(array_keys($wanted), $junitPaths);
            $added = false;
            foreach ($candidate as $file => $weight) {
                if (! isset($weights[$file])) {
                    $weights[$file] = $weight;
                    $added = true;
                }
            }
            if ($added) {
                $sources[] = basename($directory);
            }
            if (count($weights) === count($wanted)) {
                break;
            }
        }
        ksort($weights, SORT_STRING);

        return ['weights' => $weights, 'sources' => $sources];
    }

    /**
     * @return array<string, mixed>
     */
    public function createRunPlan(
        int $shardTotal,
        string $scope = 'full',
        ?string $evidenceRoot = null,
        array $metadata = [],
    ): array {
        $scope = self::normalizeScope($scope);
        $files = $this->discover($scope);
        $historical = $this->historicalTiming($files, $evidenceRoot);
        $resolved = $this->resolveWeights($files, $historical['weights']);
        $shards = $this->assign($files, $shardTotal, $historical['weights']);
        $report = $this->coverageReport($files, $shards);
        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Refusing to create an incomplete test shard plan.');
        }
        $predicted = [];
        foreach ($shards as $index => $shardFiles) {
            $predicted[$index] = array_sum(array_map(
                static fn (string $file): float => $resolved['weights'][$file],
                $shardFiles,
            ));
        }

        return [
            'schema' => 'hakoniwa.test-shard-plan.v1',
            'created_at' => gmdate('c'),
            'scope' => $scope,
            'shard_total' => $shardTotal,
            'strategy' => $resolved['strategy'],
            'source_tree_sha256' => $metadata['source_tree_sha256'] ?? 'unknown',
            'composer_lock_sha256' => $metadata['composer_lock_sha256'] ?? 'unknown',
            'discovered' => $files,
            'shards' => $shards,
            'weights' => $resolved['weights'],
            'weight_sources' => $resolved['sources'],
            'historical_sources' => $historical['sources'],
            'historical_weight_count' => count($historical['weights']),
            'predicted_seconds' => $predicted,
        ];
    }

    /** @param array<string, mixed> $plan */
    public function writeRunPlan(string $path, array $plan): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || is_link($directory) || file_exists($path) || is_link($path)) {
            throw new RuntimeException("Test shard plan path [{$path}] is unsafe or already exists.");
        }
        $encoded = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $encoded."\n", LOCK_EX) === false) {
            throw new RuntimeException("Unable to write test shard plan [{$path}].");
        }
    }

    /** @return array<string, mixed> */
    public function loadRunPlan(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new RuntimeException("Test shard plan [{$path}] does not exist or is unsafe.");
        }
        $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($decoded)
            || ($decoded['schema'] ?? null) !== 'hakoniwa.test-shard-plan.v1'
            || ! is_string($decoded['scope'] ?? null)
            || ! is_int($decoded['shard_total'] ?? null)
            || ! is_array($decoded['discovered'] ?? null)
            || ! is_array($decoded['shards'] ?? null)) {
            throw new RuntimeException('Test shard plan schema is invalid.');
        }
        $scope = self::normalizeScope($decoded['scope']);
        $discovered = array_map(self::normalizePath(...), $decoded['discovered']);
        $current = $this->discover($scope);
        if ($discovered !== $current || count($decoded['shards']) !== $decoded['shard_total']) {
            throw new RuntimeException('Test shard plan does not match current discovery.');
        }
        $shards = [];
        foreach ($decoded['shards'] as $index => $files) {
            if (! is_array($files)) {
                throw new RuntimeException('Test shard plan assignment is invalid.');
            }
            $shards[(int) $index] = array_map(self::normalizePath(...), $files);
        }
        $report = $this->coverageReport($current, $shards);
        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Test shard plan coverage is incomplete or overlapping.');
        }
        $decoded['discovered'] = $discovered;
        $decoded['shards'] = $shards;

        return $decoded;
    }

    /** @return array<int, list<string>> */
    public function plan(int $shardTotal, string $scope = 'full'): array
    {
        return $this->assign($this->discover($scope), $shardTotal);
    }

    /**
     * @param  list<string>  $files
     * @return array<string, list<string>>
     */
    public function groupByFixtureProfile(array $files): array
    {
        $groups = array_fill_keys(self::FIXTURE_PROFILES, []);
        foreach ($files as $file) {
            $normalized = self::normalizePath($file);
            $groups[$this->fixtureProfile($normalized)][] = $normalized;
        }
        foreach ($groups as &$group) {
            sort($group, SORT_STRING);
        }

        return $groups;
    }

    public function fixtureProfile(string $file): string
    {
        $path = $this->resolvePath($file, $this->projectRoot);
        if (! is_file($path)) {
            throw new RuntimeException("Test file [{$file}] does not exist.");
        }
        $relativePath = $this->relativePath($path);
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            throw new RuntimeException("Test file [{$relativePath}] is unreadable.");
        }

        $usedTraits = $this->usedTraits($contents);
        $reusable = in_array('UsesReusableSurfaceWorld', $usedTraits, true);
        $individual = array_intersect(
            ['UsesIndividualTestWorld', 'UsesForwardOnlyDatabaseMigrations'],
            $usedTraits,
        ) !== [];
        if ($reusable && $individual) {
            throw new RuntimeException("Test file [{$relativePath}] declares conflicting fixture profiles.");
        }
        if ($reusable) {
            return 'reusable_surface';
        }

        return $individual ? 'individual' : 'standard';
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
    public function verify(int $shardTotal, string $scope = 'full'): array
    {
        $discovered = $this->discover($scope);
        $report = $this->coverageReport($discovered, $this->assign($discovered, $shardTotal));

        if ($report['duplicate_count'] !== 0 || $report['missing_count'] !== 0 || $report['unexpected_count'] !== 0) {
            throw new RuntimeException('Test shard coverage is incomplete or overlapping.');
        }

        return $report;
    }

    public static function normalizeScope(string $scope): string
    {
        $normalized = strtolower(trim($scope));
        if ($normalized === 'all') {
            return 'full';
        }
        if (! isset(self::SCOPE_SUITES[$normalized])) {
            throw new InvalidArgumentException(
                "Test scope [{$scope}] is invalid; expected full, surface, or underground.",
            );
        }

        return $normalized;
    }

    public static function normalizeFixtureProfile(string $profile): string
    {
        $normalized = strtolower(trim($profile));
        if (! in_array($normalized, self::FIXTURE_PROFILES, true)) {
            throw new InvalidArgumentException(
                "Test fixture profile [{$profile}] is invalid; expected standard, reusable_surface, or individual.",
            );
        }

        return $normalized;
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

    private function junitRelativePath(string $path): string
    {
        $normalized = self::normalizePath($path);
        $marker = '/tests/';
        $position = strrpos('/'.$normalized, $marker);
        if ($position !== false) {
            return substr('/'.$normalized, $position + 1);
        }

        return $normalized;
    }

    /** @param list<float|int> $values */
    private static function median(array $values): float
    {
        if ($values === []) {
            throw new InvalidArgumentException('Cannot calculate a test timing median from an empty set.');
        }
        $values = array_map(static fn (float|int $value): float => (float) $value, $values);
        sort($values, SORT_NUMERIC);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @return list<string> */
    private function usedTraits(string $contents): array
    {
        $tokens = token_get_all($contents);
        $braceDepth = 0;
        $classDepth = null;
        $waitingForClassBrace = false;
        $traits = [];

        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_CLASS) {
                $waitingForClassBrace = true;

                continue;
            }
            if ($token === '{') {
                $braceDepth++;
                if ($waitingForClassBrace) {
                    $classDepth = $braceDepth;
                    $waitingForClassBrace = false;
                }

                continue;
            }
            if ($token === '}') {
                if ($classDepth === $braceDepth) {
                    $classDepth = null;
                }
                $braceDepth--;

                continue;
            }
            if (! is_array($token) || $token[0] !== T_USE || $classDepth !== $braceDepth) {
                continue;
            }

            $declaration = '';
            for ($index++; $index < $count; $index++) {
                $part = $tokens[$index];
                $text = is_array($part) ? $part[1] : $part;
                if ($text === ';' || $text === '{') {
                    break;
                }
                $declaration .= $text;
            }
            foreach (explode(',', $declaration) as $trait) {
                $trait = trim($trait);
                if ($trait !== '') {
                    $traits[] = basename(str_replace('\\', '/', $trait));
                }
            }
        }

        return array_values(array_unique($traits));
    }
}
