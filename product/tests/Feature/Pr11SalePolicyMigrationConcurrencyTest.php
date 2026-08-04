<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Application\SalePolicyService;
use App\Domain\Economy\SalePolicy;
use App\Models\Nation;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UsesForwardOnlyDatabaseMigrations;
use Tests\TestCase;

class Pr11SalePolicyMigrationConcurrencyTest extends TestCase
{
    use DatabaseMigrations, UsesForwardOnlyDatabaseMigrations {
        UsesForwardOnlyDatabaseMigrations::runDatabaseMigrations insteadof DatabaseMigrations;
        UsesForwardOnlyDatabaseMigrations::refreshTestDatabase insteadof DatabaseMigrations;
    }

    private const PROBE_CONNECTION = 'pgsql-pr11-sale-policy-migration-probe';

    private string $primaryConnection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->primaryConnection = DB::getDefaultConnection();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific sale-policy/ruleset serialization test.');
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

    public function test_wheat_policy_update_waits_for_migration_and_revalidates_against_pr11(): void
    {
        [$world, $user, $nation, $wheat, $policy] = $this->fixtureOnPr7();
        $this->useRulesetAsCurrent('roadmap-pr11-v1');
        $migration = require database_path('migrations/2026_07_30_000000_publish_roadmap_pr11_ruleset.php');
        $probe = DB::connection(self::PROBE_CONNECTION);

        $probe->beginTransaction();
        DB::setDefaultConnection(self::PROBE_CONNECTION);
        $migration->up();
        DB::setDefaultConnection($this->primaryConnection);
        DB::connection($this->primaryConnection)->statement("SET statement_timeout TO '5s'");

        try {
            app(SalePolicyService::class)->update(
                $user,
                $nation,
                $wheat,
                SalePolicy::SellAll->value,
                null,
                1,
            );
            $this->fail('The sale-policy update unexpectedly passed the migration World lock.');
        } catch (QueryException $exception) {
            $this->assertSame('55P03', $exception->errorInfo[0] ?? null);
        } finally {
            DB::connection($this->primaryConnection)->statement('SET statement_timeout TO DEFAULT');
        }

        $probe->commit();

        try {
            app(SalePolicyService::class)->update(
                $user,
                $nation,
                $wheat,
                SalePolicy::SellAll->value,
                null,
                1,
            );
            $this->fail('The sale-policy update accepted wheat sell_all after PR11 migration.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('sell_all', $exception->getMessage());
        }

        $this->assertSame(
            RulesetVersion::query()->where('key', 'roadmap-pr11-v1')->valueOrFail('id'),
            $world->fresh()->ruleset_version_id,
        );
        $this->assertSame(SalePolicy::Stockpile->value, $policy->fresh()->policy);
        $this->assertSame(1, $policy->fresh()->version);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'resource.sale_policy.updated')->count());
    }

    /** @return array{World, User, Nation, ResourceDefinition, NationResourceSalePolicy} */
    private function fixtureOnPr7(): array
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $world->update([
            'ruleset_version_id' => RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->valueOrFail('id'),
        ]);
        $this->useRulesetAsCurrent('roadmap-pr7-v1');
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world->fresh(), 'PR11 sale-policy concurrency', '試験島主');
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        $policy = NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $wheat->id)
            ->firstOrFail();

        return [$world->fresh(), $user, $nation, $wheat, $policy];
    }
}
