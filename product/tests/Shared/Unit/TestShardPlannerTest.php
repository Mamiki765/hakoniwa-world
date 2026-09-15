<?php

namespace Tests\Shared\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestShardPlanner;

final class TestShardPlannerTest extends TestCase
{
    private ?string $fixtureRoot = null;

    protected function tearDown(): void
    {
        if ($this->fixtureRoot !== null) {
            $this->removeDirectory($this->fixtureRoot);
        }

        parent::tearDown();
    }

    public function test_phpunit_suite_directories_are_the_authoritative_discovery_source(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Shared/CommonContractTest.php');
        $this->write($root.'/tests/Unit/ZedTest.php', "<?php\nclass ZedTest { use UsesReusableSurfaceWorld; }\n");
        $this->write($root.'/tests/Feature/ShipSystemTest.php');
        $this->write($root.'/tests/Feature/Helper.php');
        $this->write(
            $root.'/tests/Underground/CrystalPathTest.php',
            "<?php\nclass CrystalPathTest { use UsesForwardOnlyDatabaseMigrations; }\n",
        );

        $planner = new TestShardPlanner($root);

        $full = [
            'tests/Feature/ShipSystemTest.php',
            'tests/Shared/CommonContractTest.php',
            'tests/Underground/CrystalPathTest.php',
            'tests/Unit/ZedTest.php',
        ];
        $this->assertSame($full, $planner->discover());
        $this->assertSame($full, $planner->discover('all'));
        $this->assertSame([
            'tests/Feature/ShipSystemTest.php',
            'tests/Shared/CommonContractTest.php',
            'tests/Unit/ZedTest.php',
        ], $planner->discover('surface'));
        $this->assertSame([
            'tests/Shared/CommonContractTest.php',
            'tests/Underground/CrystalPathTest.php',
        ], $planner->discover('underground'));
        $this->assertSame([
            'standard' => [
                'tests/Feature/ShipSystemTest.php',
                'tests/Shared/CommonContractTest.php',
            ],
            'reusable_surface' => ['tests/Unit/ZedTest.php'],
            'individual' => ['tests/Underground/CrystalPathTest.php'],
        ], $planner->groupByFixtureProfile($full));
    }

    public function test_assignment_is_deterministic_normalized_and_complete(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());
        $files = [
            'tests\Unit\ZuluTest.php',
            './tests/Feature/AlphaTest.php',
            'tests/Feature/MiddleTest.php',
            'tests/Unit/OmegaTest.php',
        ];

        $first = $planner->assign($files, 3);
        $second = $planner->assign(array_reverse($files), 3);
        $report = $planner->coverageReport(array_map(
            TestShardPlanner::normalizePath(...),
            $files,
        ), $first);

        $this->assertSame($first, $second);
        $this->assertSame([
            0 => ['tests/Feature/AlphaTest.php', 'tests/Unit/ZuluTest.php'],
            1 => ['tests/Feature/MiddleTest.php'],
            2 => ['tests/Unit/OmegaTest.php'],
        ], $first);
        $this->assertSame(4, $report['discovered_count']);
        $this->assertSame(4, $report['union_count']);
        $this->assertSame(0, $report['duplicate_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame(0, $report['unexpected_count']);
    }

    public function test_more_shards_than_files_produce_valid_empty_shards(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());

        $shards = $planner->assign(['tests/Unit/A.php', 'tests/Unit/B.php'], 4);
        $report = $planner->coverageReport(['tests/Unit/A.php', 'tests/Unit/B.php'], $shards);

        $this->assertSame([
            0 => ['tests/Unit/A.php'],
            1 => ['tests/Unit/B.php'],
            2 => [],
            3 => [],
        ], $shards);
        $this->assertSame([1, 1, 0, 0], $report['shard_file_counts']);
        $this->assertSame(0, $report['duplicate_count']);
        $this->assertSame(0, $report['missing_count']);
    }

    public function test_coverage_report_exposes_duplicates_missing_and_unexpected_files(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());

        $report = $planner->coverageReport(
            ['tests/Unit/A.php', 'tests/Unit/B.php'],
            [
                ['tests/Unit/A.php', 'tests/Unit/A.php'],
                ['tests/Unit/Unexpected.php'],
            ],
        );

        $this->assertSame(1, $report['duplicate_count']);
        $this->assertSame(['tests/Unit/A.php'], $report['duplicates']);
        $this->assertSame(1, $report['missing_count']);
        $this->assertSame(['tests/Unit/B.php'], $report['missing']);
        $this->assertSame(1, $report['unexpected_count']);
        $this->assertSame(['tests/Unit/Unexpected.php'], $report['unexpected']);
    }

    public function test_duplicate_canonical_input_paths_are_rejected(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate canonical paths');

        $planner->assign(['tests\Unit\A.php', 'tests/Unit/A.php'], 2);
    }

    public function test_empty_phpunit_suite_is_rejected(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('discovery returned no test files');

        $planner->discover();
    }

    public function test_invalid_scope_is_rejected(): void
    {
        $planner = new TestShardPlanner($this->createFixtureProject());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected full, surface, or underground');

        $planner->discover('component-name');
    }

    public function test_repository_plan_covers_every_current_test_file_once(): void
    {
        $planner = new TestShardPlanner(dirname(__DIR__, 3));

        $full = $planner->discover('full');
        $surface = $planner->discover('surface');
        $underground = $planner->discover('underground');
        $scopeUnion = array_values(array_unique([...$surface, ...$underground]));
        sort($scopeUnion, SORT_STRING);

        $this->assertSame($full, $scopeUnion);
        $this->assertNotEmpty(array_intersect($surface, $underground));
        $this->assertSame([], array_filter(
            array_intersect($surface, $underground),
            static fn (string $file): bool => ! str_starts_with($file, 'tests/Shared/'),
        ));
        $profiles = $planner->groupByFixtureProfile($full);
        $profileUnion = array_merge(...array_values($profiles));
        sort($profileUnion, SORT_STRING);
        $this->assertSame($full, $profileUnion);
        $this->assertContains('tests/Feature/DomesticCommandExecutionTest.php', $profiles['reusable_surface']);
        $this->assertNotEmpty($profiles['individual']);
        foreach (['full', 'surface', 'underground'] as $scope) {
            $report = $planner->verify(4, $scope);
            $this->assertSame($report['discovered_count'], $report['union_count']);
            $this->assertSame(4, $report['shard_count']);
            $this->assertSame(0, $report['duplicate_count']);
            $this->assertSame(0, $report['missing_count']);
            $this->assertSame(0, $report['unexpected_count']);
        }
    }

    public function test_repository_composer_commands_define_non_overlapping_surface_underground_and_all_suites(): void
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 3).'/composer.json') ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($composer);
        $scripts = $composer['scripts'] ?? [];

        $this->assertSame(['@test:all'], $scripts['test']);
        foreach (['test:surface', 'test:underground', 'test:all', 'test:parallel'] as $script) {
            $this->assertSame('Composer\\Config::disableProcessTimeout', $scripts[$script][0]);
        }
        $this->assertSame('bash tests/scripts/run_parallel_tests.sh 1 surface', $scripts['test:surface'][1]);
        $this->assertSame('bash tests/scripts/run_parallel_tests.sh 1 underground', $scripts['test:underground'][1]);
        $this->assertSame('bash tests/scripts/run_parallel_tests.sh 1 full', $scripts['test:all'][1]);
        $this->assertSame('bash tests/scripts/run_parallel_tests.sh', $scripts['test:parallel'][1]);
    }

    private function createFixtureProject(): string
    {
        $root = sys_get_temp_dir().'/hakoniwa-shard-planner-'.bin2hex(random_bytes(8));
        mkdir($root.'/tests/Shared', 0777, true);
        mkdir($root.'/tests/Unit', 0777, true);
        mkdir($root.'/tests/Feature', 0777, true);
        mkdir($root.'/tests/Underground', 0777, true);
        file_put_contents($root.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
        <testsuite name="Shared">
            <directory>tests/Shared</directory>
        </testsuite>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory>tests/Feature</directory>
        </testsuite>
        <testsuite name="Underground">
            <directory>tests/Underground</directory>
        </testsuite>
    </testsuites>
</phpunit>
XML);
        $this->fixtureRoot = $root;

        return $root;
    }

    private function write(string $path, string $contents = "<?php\n"): void
    {
        file_put_contents($path, $contents);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
