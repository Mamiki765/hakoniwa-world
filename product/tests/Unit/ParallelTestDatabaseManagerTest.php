<?php

namespace Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\ParallelTestDatabaseManager;

final class ParallelTestDatabaseManagerTest extends TestCase
{
    /** @var list<string> */
    private array $fixtureRoots = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->fixtureRoots) as $root) {
            $this->removeFixtureDirectory($root);
        }

        parent::tearDown();
    }

    public function test_database_names_use_the_fixed_test_only_contract(): void
    {
        $database = ParallelTestDatabaseManager::databaseName('0123abcd', 3);

        $this->assertSame('hakoniwa_parallel_0123abcd_04_test', $database);
        $this->assertTrue(ParallelTestDatabaseManager::isSafeDatabaseName($database));
        $this->assertStringEndsWith('_test', $database);
    }

    #[DataProvider('unsafeDatabaseNameProvider')]
    public function test_production_and_arbitrary_database_names_are_never_safe(string $database): void
    {
        $this->assertFalse(ParallelTestDatabaseManager::isSafeDatabaseName($database));
    }

    /** @return array<string, array{string}> */
    public static function unsafeDatabaseNameProvider(): array
    {
        return [
            'production default' => ['hakoniwa'],
            'canonical serial test database' => ['hakoniwa_test'],
            'missing test suffix' => ['hakoniwa_parallel_0123abcd_01'],
            'sql punctuation' => ['hakoniwa_parallel_0123abcd_01_test;DROP DATABASE hakoniwa'],
            'path-like name' => ['../hakoniwa_parallel_0123abcd_01_test'],
            'wrong prefix' => ['other_parallel_0123abcd_01_test'],
        ];
    }

    public function test_invalid_tokens_and_out_of_range_indexes_are_rejected(): void
    {
        foreach ([['not-hex!', 0], ['0123abcd', -1], ['0123abcd', 64]] as [$token, $index]) {
            try {
                ParallelTestDatabaseManager::databaseName($token, $index);
                $this->fail('Invalid parallel database input must be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cached_application_configuration_is_rejected_before_database_operations(): void
    {
        $root = sys_get_temp_dir().'/hakoniwa-parallel-cache-'.bin2hex(random_bytes(8));
        mkdir($root.'/bootstrap/cache', 0777, true);
        file_put_contents($root.'/bootstrap/cache/config.php', '<?php return [];');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('while Laravel configuration is cached');

            (new ParallelTestDatabaseManager($root))->prepare(1);
        } finally {
            unlink($root.'/bootstrap/cache/config.php');
            rmdir($root.'/bootstrap/cache');
            rmdir($root.'/bootstrap');
            rmdir($root);
        }
    }

    public function test_manifest_outside_the_test_workspace_is_rejected(): void
    {
        [$manager, $manifest, $payload, $root] = $this->createManifestFixture();
        $outsideManifest = $root.'/outside-manifest.json';
        file_put_contents($outsideManifest, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside the test workspace');

        $manager->shard($outsideManifest, 0);
    }

    public function test_manifest_cannot_name_a_production_database(): void
    {
        [$manager, $manifest, $payload] = $this->createManifestFixture();
        $payload['shards'][0]['database'] = 'hakoniwa';
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database safety validation');

        $manager->shard($manifest, 0);
    }

    public function test_manifest_cannot_repeat_a_shard_or_database(): void
    {
        [$manager, $manifest, $payload] = $this->createManifestFixture();
        $payload['shards'][] = $payload['shards'][0];
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('database safety validation');

        $manager->shard($manifest, 0);
    }

    public function test_manifest_token_and_directory_must_match_its_workspace(): void
    {
        [$manager, $manifest, $payload] = $this->createManifestFixture();
        $payload['token'] = 'deadbeef';
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('metadata is invalid');

        $manager->shard($manifest, 0);
    }

    public function test_manifest_rejects_a_symlinked_phpunit_configuration(): void
    {
        [$manager, $manifest, $payload, $root] = $this->createManifestFixture();
        $configuration = $payload['shards'][0]['configuration'];
        $target = $root.'/outside-configuration.xml';
        file_put_contents($target, '<?xml version="1.0"?><phpunit/>');
        unlink($configuration);
        if (! @symlink($target, $configuration)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shard files failed their safety validation');

        $manager->shard($manifest, 0);
    }

    public function test_manifest_rejects_a_symlinked_log(): void
    {
        [$manager, $manifest, $payload, $root] = $this->createManifestFixture();
        $log = $payload['shards'][0]['log'];
        $target = $root.'/outside.log';
        file_put_contents($target, 'do not truncate');
        if (! @symlink($target, $log)) {
            $this->markTestSkipped('Symbolic links are unavailable on this platform.');
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('shard files failed their safety validation');

        $manager->shard($manifest, 0);
    }

    public function test_administrative_host_and_port_must_keep_the_forced_local_contract(): void
    {
        $root = $this->createFixtureRoot();
        mkdir($root.'/tests/Unit', 0777, true);
        file_put_contents($root.'/tests/Unit/DummyTest.php', "<?php\n");
        file_put_contents($root.'/phpunit.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
    <php>
        <ini name="memory_limit" value="512M"/>
        <env name="APP_ENV" value="testing" force="true"/>
        <env name="DB_CONNECTION" value="pgsql" force="true"/>
        <env name="DB_HOST" value="external-postgres"/>
        <env name="DB_PORT" value="5432" force="true"/>
        <env name="DB_DATABASE" value="hakoniwa_test" force="true"/>
        <env name="DB_USERNAME" value="hakoniwa"/>
    </php>
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
    </testsuites>
</phpunit>
XML);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('isolated PostgreSQL test contract');

        (new ParallelTestDatabaseManager($root))->prepare(1);
    }

    public function test_cleanup_drops_only_the_generated_database_when_its_configuration_is_missing(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $manager = new ParallelTestDatabaseManager($projectRoot);
        $manifest = $manager->prepare(1);

        try {
            $shard = $manager->shard($manifest, 0);
            $pdo = $this->administrativeConnection();
            $protectedBefore = $this->protectedDatabaseStates($pdo);

            $this->assertNotNull($shard);
            $this->assertTrue(ParallelTestDatabaseManager::isSafeDatabaseName($shard['database']));
            $this->assertTrue($this->databaseExists($pdo, $shard['database']));
            unlink($shard['configuration']);
        } finally {
            if (is_file($manifest)) {
                $manager->cleanup($manifest);
            }
        }

        $this->assertFalse($this->databaseExists($pdo, $shard['database']));
        $this->assertSame($protectedBefore, $this->protectedDatabaseStates($pdo));
    }

    /**
     * @return array{ParallelTestDatabaseManager, string, array<string, mixed>, string}
     */
    private function createManifestFixture(): array
    {
        $root = $this->createFixtureRoot();
        $token = bin2hex(random_bytes(4));
        $directory = str_replace('\\', '/', $root.'/storage/framework/testing/phpunit-parallel-'.$token);
        mkdir($directory, 0777, true);
        $configuration = $directory.'/phpunit-01.xml';
        file_put_contents($configuration, '<?xml version="1.0"?><phpunit/>');
        $payload = [
            'token' => $token,
            'directory' => $directory,
            'shard_total' => 1,
            'discovered_count' => 1,
            'shards' => [[
                'index' => 0,
                'database' => ParallelTestDatabaseManager::databaseName($token, 0),
                'configuration' => $configuration,
                'log' => $directory.'/phpunit-01.log',
            ]],
        ];
        $manifest = $directory.'/manifest.json';
        file_put_contents($manifest, json_encode($payload, JSON_THROW_ON_ERROR));

        return [new ParallelTestDatabaseManager($root), $manifest, $payload, $root];
    }

    private function createFixtureRoot(): string
    {
        $root = sys_get_temp_dir().'/hakoniwa-parallel-manager-'.bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $this->fixtureRoots[] = $root;

        return str_replace('\\', '/', $root);
    }

    private function administrativeConnection(): PDO
    {
        $username = getenv('DB_USERNAME');
        $password = getenv('DB_PASSWORD');

        return new PDO(
            'pgsql:host=hakoniwa-postgres;port=5432;dbname=postgres',
            $username === false ? 'hakoniwa' : $username,
            $password === false ? '' : $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    /** @return array<string, bool> */
    private function protectedDatabaseStates(PDO $pdo): array
    {
        return [
            'hakoniwa' => $this->databaseExists($pdo, 'hakoniwa'),
            'hakoniwa_test' => $this->databaseExists($pdo, 'hakoniwa_test'),
        ];
    }

    private function databaseExists(PDO $pdo, string $database): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM pg_database WHERE datname = :database');
        $statement->execute(['database' => $database]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function removeFixtureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeFixtureDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
