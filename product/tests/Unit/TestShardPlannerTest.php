<?php

namespace Tests\Unit;

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
        $this->write($root.'/tests/Unit/ZedTest.php');
        $this->write($root.'/tests/Feature/ShipSystemTest.php');
        $this->write($root.'/tests/Feature/Helper.php');
        $this->write($root.'/tests/Underground/CrystalPathTest.php');

        $planner = new TestShardPlanner($root);

        $this->assertSame([
            'tests/Feature/ShipSystemTest.php',
            'tests/Underground/CrystalPathTest.php',
            'tests/Unit/ZedTest.php',
        ], $planner->discover());
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

    public function test_repository_plan_covers_every_current_test_file_once(): void
    {
        $planner = new TestShardPlanner(dirname(__DIR__, 2));

        $report = $planner->verify(16);

        $this->assertGreaterThanOrEqual(70, $report['discovered_count']);
        $this->assertSame($report['discovered_count'], $report['union_count']);
        $this->assertSame(16, $report['shard_count']);
        $this->assertSame(0, $report['duplicate_count']);
        $this->assertSame(0, $report['missing_count']);
        $this->assertSame(0, $report['unexpected_count']);
    }

    public function test_repository_composer_commands_define_non_overlapping_surface_underground_and_all_suites(): void
    {
        $composer = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/composer.json') ?: '',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($composer);
        $scripts = $composer['scripts'] ?? [];

        $this->assertSame(['@test:all'], $scripts['test']);
        $this->assertSame(
            '@php -d memory_limit=512M vendor/bin/phpunit tests/Unit tests/Feature',
            $scripts['test:surface'][1],
        );
        $this->assertSame(
            '@php -d memory_limit=512M vendor/bin/phpunit tests/Underground',
            $scripts['test:underground'][1],
        );
        $this->assertSame(
            '@php -d memory_limit=512M vendor/bin/phpunit',
            $scripts['test:all'][1],
        );
        $this->assertStringNotContainsString('Underground', $scripts['test:surface'][1]);
        $this->assertStringNotContainsString('tests/Unit', $scripts['test:underground'][1]);
        $this->assertStringNotContainsString('tests/Feature', $scripts['test:underground'][1]);
    }

    private function createFixtureProject(): string
    {
        $root = sys_get_temp_dir().'/hakoniwa-shard-planner-'.bin2hex(random_bytes(8));
        mkdir($root.'/tests/Unit', 0777, true);
        mkdir($root.'/tests/Feature', 0777, true);
        mkdir($root.'/tests/Underground', 0777, true);
        file_put_contents($root.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <testsuites>
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

    private function write(string $path): void
    {
        file_put_contents($path, "<?php\n");
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
