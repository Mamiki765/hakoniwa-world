<?php

namespace Tests\Shared\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PhpunitSelection;
use Tests\Support\ReusableSurfaceTemplateFingerprint;
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

    public function test_selected_test_file_hash_tracks_normalized_paths_and_file_contents(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Feature/AlphaTest.php', "<?php\nreturn 'alpha';\n");
        $this->write($root.'/tests/Unit/BetaTest.php', "<?php\nreturn 'beta';\n");
        $planner = new TestShardPlanner($root);

        $first = $planner->selectedTestFilesSha256([
            './tests/Unit/BetaTest.php',
            'tests\\Feature\\AlphaTest.php',
        ]);
        $this->assertSame($first, $planner->selectedTestFilesSha256([
            'tests/Feature/AlphaTest.php',
            'tests/Unit/BetaTest.php',
        ]));

        $this->write($root.'/tests/Feature/AlphaTest.php', "<?php\nreturn 'changed alpha';\n");
        $this->assertNotSame($first, $planner->selectedTestFilesSha256([
            'tests/Feature/AlphaTest.php',
            'tests/Unit/BetaTest.php',
        ]));
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

    public function test_lpt_assigns_heavy_files_first_to_the_lightest_worker_deterministically(): void
    {
        $root = $this->createFixtureProject();
        foreach (['A', 'B', 'C', 'D'] as $name) {
            $this->write($root."/tests/Unit/{$name}Test.php");
        }
        $planner = new TestShardPlanner($root);
        $files = [
            'tests/Unit/DTest.php',
            'tests/Unit/BTest.php',
            'tests/Unit/ATest.php',
            'tests/Unit/CTest.php',
        ];
        $weights = [
            'tests/Unit/ATest.php' => 10,
            'tests/Unit/BTest.php' => 9,
            'tests/Unit/CTest.php' => 2,
            'tests/Unit/DTest.php' => 1,
        ];

        $this->assertSame([
            0 => ['tests/Unit/ATest.php', 'tests/Unit/DTest.php'],
            1 => ['tests/Unit/BTest.php', 'tests/Unit/CTest.php'],
        ], $planner->assign($files, 2, $weights));
        $this->assertSame(
            $planner->assign($files, 2, $weights),
            $planner->assign(array_reverse($files), 2, $weights),
        );
    }

    public function test_missing_timing_uses_the_fixture_profile_median_then_the_global_median(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Unit/ReusableKnownTest.php', "<?php\nclass ReusableKnownTest { use UsesReusableSurfaceWorld; }\n");
        $this->write($root.'/tests/Unit/ReusableNewTest.php', "<?php\nclass ReusableNewTest { use UsesReusableSurfaceWorld; }\n");
        $this->write($root.'/tests/Feature/StandardKnownTest.php');
        $this->write($root.'/tests/Feature/StandardNewTest.php');
        $this->write($root.'/tests/Underground/IndividualNewTest.php', "<?php\nclass IndividualNewTest { use UsesForwardOnlyDatabaseMigrations; }\n");
        $planner = new TestShardPlanner($root);
        $resolved = $planner->resolveWeights([
            'tests/Unit/ReusableKnownTest.php',
            'tests/Unit/ReusableNewTest.php',
            'tests/Feature/StandardKnownTest.php',
            'tests/Feature/StandardNewTest.php',
            'tests/Underground/IndividualNewTest.php',
        ], [
            'tests/Unit/ReusableKnownTest.php' => 8,
            'tests/Feature/StandardKnownTest.php' => 2,
        ]);

        $this->assertSame(8.0, $resolved['weights']['tests/Unit/ReusableNewTest.php']);
        $this->assertSame('fixture_profile_median', $resolved['sources']['tests/Unit/ReusableNewTest.php']);
        $this->assertSame(2.0, $resolved['weights']['tests/Feature/StandardNewTest.php']);
        $this->assertSame('fixture_profile_median', $resolved['sources']['tests/Feature/StandardNewTest.php']);
        $this->assertSame(5.0, $resolved['weights']['tests/Underground/IndividualNewTest.php']);
        $this->assertSame('global_median', $resolved['sources']['tests/Underground/IndividualNewTest.php']);
    }

    public function test_run_plan_reads_only_passed_junit_and_rejects_discovery_changes_after_snapshot(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Unit/ATest.php');
        $this->write($root.'/tests/Unit/BTest.php');
        $evidence = $root.'/storage/framework/testing/test-evidence/phpunit-parallel-0123abcd';
        mkdir($evidence, 0777, true);
        file_put_contents($evidence.'/run.tsv', "schema\ttest\nselection_mode\tscope\nrun\t-\tpassed\t0\t-\t2\t-\t-\t-\n");
        file_put_contents($evidence.'/phpunit-01.junit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite>
    <testcase name="one" file="/var/www/html/tests/Unit/ATest.php" time="1.5"/>
    <testcase name="two" file="/var/www/html/tests/Unit/ATest.php" time="2.5"/>
  </testsuite>
</testsuites>
XML);
        file_put_contents($evidence.'/phpunit-01-standard.junit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite>
    <testcase name="duplicate-profile-output" file="/var/www/html/tests/Unit/ATest.php" time="999"/>
  </testsuite>
</testsuites>
XML);
        $focusedEvidence = $root.'/storage/framework/testing/test-evidence/phpunit-parallel-fedcba98';
        mkdir($focusedEvidence, 0777, true);
        file_put_contents($focusedEvidence.'/run.tsv', "schema\ttest\nselection_mode\tfocused\nrun\t-\tpassed\t0\t-\t2\t-\t-\t-\n");
        file_put_contents($focusedEvidence.'/phpunit-01.junit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite>
    <testcase name="partial" file="/var/www/html/tests/Unit/BTest.php" time="999"/>
  </testsuite>
</testsuites>
XML);
        $planner = new TestShardPlanner($root);
        $plan = $planner->createRunPlan(2, metadata: [
            'source_tree_sha256' => str_repeat('a', 64),
            'composer_lock_sha256' => str_repeat('b', 64),
        ]);

        $this->assertSame('lpt', $plan['strategy']);
        $this->assertSame(4.0, $plan['weights']['tests/Unit/ATest.php']);
        $this->assertSame(4.0, $plan['weights']['tests/Unit/BTest.php']);
        $this->assertSame('fixture_profile_median', $plan['weight_sources']['tests/Unit/BTest.php']);
        $this->assertSame(['phpunit-parallel-0123abcd'], $plan['historical_sources']);
        $this->assertSame(str_repeat('a', 64), $plan['source_tree_sha256']);
        $planPath = $root.'/storage/framework/testing/fixed-plan.json';
        $planner->writeRunPlan($planPath, $plan);
        $this->assertSame($plan['shards'], $planner->loadRunPlan($planPath)['shards']);

        $this->write($root.'/tests/Feature/AddedAfterPlanTest.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not match current discovery');
        $planner->loadRunPlan($planPath);
    }

    public function test_phpunit_arguments_distinguish_real_selection_from_display_options_and_remove_test_inputs(): void
    {
        $this->assertSame([
            'selection_mode' => 'scope',
            'has_test_inputs' => false,
            'passthrough' => ['--colors=always', '--debug', '--columns', 'max'],
        ], PhpunitSelection::classify(['--colors=always', '--debug', '--columns', 'max']));
        $this->assertSame([
            'selection_mode' => 'focused',
            'has_test_inputs' => false,
            'passthrough' => ['--filter', 'selected method'],
        ], PhpunitSelection::classify(['--filter', 'selected method']));
        $this->assertSame([
            'selection_mode' => 'focused',
            'has_test_inputs' => true,
            'passthrough' => ['--filter=selected method', '--colors=never'],
        ], PhpunitSelection::classify([
            '--filter=selected method',
            'tests/Feature/SelectedTest.php',
            '--colors=never',
        ]));
    }

    public function test_focused_plan_keeps_only_listed_files_across_fixture_profiles_and_preserves_shards(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Feature/StandardSelectedTest.php');
        $this->write($root.'/tests/Feature/StandardSkippedTest.php');
        $this->write(
            $root.'/tests/Unit/ReusableSelectedTest.php',
            "<?php\nclass ReusableSelectedTest { use UsesReusableSurfaceWorld; }\n",
        );
        $this->write(
            $root.'/tests/Underground/IndividualSelectedTest.php',
            "<?php\nclass IndividualSelectedTest { use UsesForwardOnlyDatabaseMigrations; }\n",
        );
        $planner = new TestShardPlanner($root);
        $scopePlan = $planner->createRunPlan(2);
        $list = $root.'/list-tests.xml';
        file_put_contents($list, sprintf(<<<'XML'
<?xml version="1.0"?>
<testSuite>
 <tests>
  <testClass name="StandardSelectedTest" file="%s"><testMethod id="StandardSelectedTest::test_one"/></testClass>
  <testClass name="ReusableSelectedTest" file="%s"><testMethod id="ReusableSelectedTest::test_one"/></testClass>
  <testClass name="IndividualSelectedTest" file="%s"><testMethod id="IndividualSelectedTest::test_one"/></testClass>
 </tests>
</testSuite>
XML,
            $root.'/tests/Feature/StandardSelectedTest.php',
            $root.'/tests/Unit/ReusableSelectedTest.php',
            $root.'/tests/Underground/IndividualSelectedTest.php',
        ));

        $focused = $planner->focusRunPlan($scopePlan, $list);
        $focusedPath = $root.'/focused-plan.json';
        $planner->writeRunPlan($focusedPath, $focused);
        $loaded = $planner->loadRunPlan($focusedPath);

        $this->assertSame('focused', $loaded['selection_mode']);
        $this->assertSame(3, $loaded['selected_test_identifier_count']);
        $this->assertSame([
            'tests/Feature/StandardSelectedTest.php',
            'tests/Underground/IndividualSelectedTest.php',
            'tests/Unit/ReusableSelectedTest.php',
        ], $loaded['discovered']);
        $this->assertSame($scopePlan['shard_total'], count($loaded['shards']));
        $this->assertSame([
            'standard' => ['tests/Feature/StandardSelectedTest.php'],
            'reusable_surface' => ['tests/Unit/ReusableSelectedTest.php'],
            'individual' => ['tests/Underground/IndividualSelectedTest.php'],
        ], $planner->groupByFixtureProfile($loaded['discovered']));
    }

    public function test_focused_plan_rejects_zero_identifiers_before_database_preparation(): void
    {
        $root = $this->createFixtureProject();
        $this->write($root.'/tests/Feature/SelectedTest.php');
        $list = $root.'/empty-list-tests.xml';
        file_put_contents($list, '<?xml version="1.0"?><testSuite><tests/></testSuite>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('matched no test identifiers');

        (new TestShardPlanner($root))->focusRunPlan(
            (new TestShardPlanner($root))->createRunPlan(1),
            $list,
        );
    }

    public function test_reusable_surface_fingerprint_tracks_fixture_inputs_but_not_unrelated_test_bodies(): void
    {
        $root = $this->createFixtureProject();
        mkdir($root.'/database/migrations', 0777, true);
        mkdir($root.'/tests/Concerns', 0777, true);
        $this->write($root.'/database/migrations/fixture.php', "<?php\nreturn 'first';\n");
        $this->write($root.'/tests/Concerns/ReusableFixture.php', "<?php\nreturn 'fixture';\n");
        $fingerprint = new ReusableSurfaceTemplateFingerprint($root, [
            'database/migrations',
            'tests/Concerns/ReusableFixture.php',
        ]);
        $runtime = ['php_version' => '8.5.8', 'postgres_server_version' => '18.4'];
        $first = $fingerprint->calculate($runtime);

        $this->write($root.'/tests/Feature/UnrelatedTest.php', "<?php\nreturn 'changed test body';\n");
        $this->assertSame($first, $fingerprint->calculate($runtime));

        $this->write($root.'/database/migrations/fixture.php', "<?php\nreturn 'second';\n");
        $this->assertNotSame($first['fingerprint'], $fingerprint->calculate($runtime)['fingerprint']);
        $this->assertNotSame(
            $first['fingerprint'],
            $fingerprint->calculate([...$runtime, 'postgres_server_version' => '18.5'])['fingerprint'],
        );
        $this->assertContains(
            'app/Providers/AppServiceProvider.php',
            (new ReusableSurfaceTemplateFingerprint(dirname(__DIR__, 3)))->calculate($runtime)['files'],
        );
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
