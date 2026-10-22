<?php

namespace Tests\Support;

use App\Application\AdditionalTurnPlan;
use App\Domain\World\WorldMutationLock;
use App\Models\World;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use RuntimeException;

/** Read-only SQL fixtures. No PDO, database server, migrations or persisted data. */
final class NoOpTurnDatabase extends Connection
{
    /** @var list<string> */
    public array $selects = [];

    public function __construct(private bool $withBoost)
    {
        parent::__construct(static fn () => throw new RuntimeException('No-op probe must never open a database connection.'), 'no_database', '', ['driver' => 'pgsql']);
    }

    public static function install(Application $app, bool $withBoost): self
    {
        $fixture = new self($withBoost);
        $app['config']->set('database.default', 'noop_fixture');
        $app['config']->set('database.connections.noop_fixture', ['driver' => 'noop_fixture']);
        $app['db']->extend('noop_fixture', static fn () => $fixture);
        $app->instance(WorldMutationLock::class, new class extends WorldMutationLock
        {
            public function acquire(World $world): void {}

            public function release(World $world): void {}
        });

        return $fixture;
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
    {
        $this->selects[] = $query;
        if (str_contains($query, '"worlds"')) {
            return [(object) ['id' => 1, 'key' => 'shared-world', 'ruleset_version_id' => 41, 'current_turn' => 1,
                'next_regular_turn_at' => '2026-11-01T09:00:00+00:00', 'turn_schedule_origin_at' => '2026-11-01T07:00:00+00:00']];
        }
        if (str_contains($query, '"ruleset_versions"')) {
            return [(object) ['id' => 41, 'key' => config('hakoniwa.ruleset.key'), 'version' => config('hakoniwa.ruleset.version'), 'settings' => '{}']];
        }
        if (str_contains($query, '"turn_runs"')) {
            return [];
        }
        if (str_contains($query, '"boost_occurrences"')) {
            if (! $this->withBoost) {
                return [];
            }
            $start = CarbonImmutable::parse('2026-11-01T19:00:00+09:00');
            $end = $start->addHour();
            $plan = AdditionalTurnPlan::create($start, $end, 2, 30);

            return [(object) ['id' => 1, 'world_id' => 1, 'boost_series_id' => 1, 'kind' => 'turn_acceleration', 'occurrence_number' => 1,
                'starts_at' => $start->toIso8601String(), 'ends_at' => $end->toIso8601String(),
                'series_schedule' => json_encode(['interval_minutes' => 30, 'windows' => [['additional_scheduled_ats' => $plan->timestamps()]]], JSON_THROW_ON_ERROR)]];
        }
        throw new RuntimeException('Unexpected no-op probe query: '.$query);
    }
}
