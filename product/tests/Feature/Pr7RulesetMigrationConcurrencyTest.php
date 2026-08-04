<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class Pr7RulesetMigrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-pr7-migration-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific queue/ruleset serialization tests.');
        }

        config([
            'database.connections.'.self::PROBE_CONNECTION => config(
                'database.connections.'.$this->primaryConnection,
            ),
        ]);
        DB::connection($this->primaryConnection)->statement("SET lock_timeout TO '300ms'");
        DB::connection(self::PROBE_CONNECTION)->statement("SET lock_timeout TO '300ms'");
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->primaryConnection);

        foreach ([$this->primaryConnection, self::PROBE_CONNECTION] as $connectionName) {
            $connection = DB::connection($connectionName);
            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->statement('SET lock_timeout TO DEFAULT');
            $connection->statement('SET statement_timeout TO DEFAULT');
        }

        DB::purge(self::PROBE_CONNECTION);
        parent::tearDown();
    }

    public function test_queue_add_that_locks_world_first_is_remapped_by_the_waiting_migration(): void
    {
        [$world, $user, $nation, $mapSpace, $target] = $this->fixtureOnPr6();
        $migration = $this->migration();
        $primary = DB::connection($this->primaryConnection);

        $primary->beginTransaction();
        $item = $this->add($user, $nation, $mapSpace, $target);
        DB::connection(self::PROBE_CONNECTION)->statement("SET statement_timeout TO '5s'");

        try {
            $this->runMigration(self::PROBE_CONNECTION, $migration, 'up');
            $this->fail('The migration unexpectedly passed the queue add World lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->errorInfo[0] ?? null);
        } finally {
            DB::connection(self::PROBE_CONNECTION)->statement('SET statement_timeout TO DEFAULT');
            DB::setDefaultConnection($this->primaryConnection);
            $primary->commit();
        }

        $this->runMigration(self::PROBE_CONNECTION, $migration, 'up');

        $pr7Id = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->valueOrFail('id');
        $this->assertSame($pr7Id, $world->fresh()->ruleset_version_id);
        $this->assertSame(
            $pr7Id,
            NationCommandQueueItem::query()->findOrFail($item->id)->definition()->value('ruleset_version_id'),
        );
        $this->assertWorldQueueRulesetConsistency($world);
    }

    public function test_queue_add_waits_for_migration_then_reloads_the_pr7_definition(): void
    {
        [$world, $user, $nation, $mapSpace, $target] = $this->fixtureOnPr6();
        $this->useRulesetAsCurrent('roadmap-pr7-v1');
        $migration = $this->migration();
        $probe = DB::connection(self::PROBE_CONNECTION);

        $probe->beginTransaction();
        $probe->table('worlds')->where('id', $world->id)->lockForUpdate()->first();
        $probe->statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        $probe->statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::connection($this->primaryConnection)->statement("SET statement_timeout TO '5s'");

        try {
            $this->add($user, $nation, $mapSpace, $target);
            $this->fail('Queue add unexpectedly passed the migration World lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->errorInfo[0] ?? null);
        } finally {
            DB::connection($this->primaryConnection)->statement('SET statement_timeout TO DEFAULT');
        }

        DB::setDefaultConnection(self::PROBE_CONNECTION);
        $migration->up();
        $probe->commit();
        DB::setDefaultConnection($this->primaryConnection);

        $item = $this->add($user, $nation, $mapSpace, $target);
        $pr7Id = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->valueOrFail('id');

        $this->assertSame($pr7Id, $world->fresh()->ruleset_version_id);
        $this->assertSame($pr7Id, $item->definition()->value('ruleset_version_id'));
        $this->assertSame(
            0,
            NationCommandQueueItem::query()
                ->whereHas('definition', fn ($query) => $query->where('ruleset_version_id', '!=', $pr7Id))
                ->count(),
        );

        $pr6DefinitionId = DB::table('command_definitions')
            ->join('ruleset_versions', 'ruleset_versions.id', '=', 'command_definitions.ruleset_version_id')
            ->where('ruleset_versions.key', 'roadmap-pr6-v1')
            ->where('command_definitions.key', 'build_farm')
            ->value('command_definitions.id');
        try {
            DB::table('nation_command_queue_items')->where('id', $item->id)->update([
                'command_definition_id' => $pr6DefinitionId,
            ]);
            $this->fail('The database accepted an old-ruleset definition for a PR7 World.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0] ?? null);
        }

        $this->assertWorldQueueRulesetConsistency($world);
    }

    /**
     * @return array{World, User, Nation, MapSpace, MapCell}
     */
    private function fixtureOnPr6(): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update([
            'ruleset_version_id' => RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->valueOrFail('id'),
        ]);
        $migration = $this->migration();
        $this->runMigration($this->primaryConnection, $migration, 'down');
        $this->useRulesetAsCurrent('roadmap-pr6-v1');

        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world->fresh(), 'PR7 concurrency', '試験島主');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();

        return [$world->fresh(), $user, $nation, $mapSpace, $target];
    }

    private function add(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        MapCell $target,
    ): NationCommandQueueItem {
        return app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $mapSpace,
            commandKey: 'build_farm',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_07_29_000000_publish_roadmap_pr7_ruleset.php');
    }

    private function runMigration(string $connectionName, object $migration, string $method): void
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connectionName);

        try {
            DB::connection($connectionName)->transaction(function () use ($migration, $method): void {
                $migration->{$method}();
            });
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    private function assertWorldQueueRulesetConsistency(World $world): void
    {
        $mismatchCount = DB::table('nation_command_queue_items')
            ->join('nation_command_queues', 'nation_command_queues.id', '=', 'nation_command_queue_items.nation_command_queue_id')
            ->join('nations', 'nations.id', '=', 'nation_command_queues.nation_id')
            ->join('command_definitions', 'command_definitions.id', '=', 'nation_command_queue_items.command_definition_id')
            ->join('worlds', 'worlds.id', '=', 'nations.world_id')
            ->where('worlds.id', $world->id)
            ->whereColumn('command_definitions.ruleset_version_id', '!=', 'worlds.ruleset_version_id')
            ->count();

        $this->assertSame(0, $mismatchCount);
    }
}
