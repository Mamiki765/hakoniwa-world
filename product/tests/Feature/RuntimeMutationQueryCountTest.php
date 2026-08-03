<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\SalePolicyService;
use App\Application\TurnRunner;
use App\Domain\Economy\SalePolicy;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnSeedGenerator;
use App\Models\MapCell;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RuntimeMutationQueryCountTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_current_ruleset_guard_adds_no_queries_and_current_mutations_complete(): void
    {
        $this->app->bind(TurnSeedGenerator::class, fn () => new Pr17QueryTurnSeedGenerator);
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $loadedWorld = $world->fresh()->load('rulesetVersion');
        $queries = [];
        app(CurrentRulesetGuard::class)->assertMutable($loadedWorld, $loadedWorld->rulesetVersion);
        $this->assertSame([], $queries, 'The guard must compare already-loaded IDs without SQL.');

        $queries = [];
        $nation = app(NationCreationService::class)->create($user, $world, '計測国');
        $counts = ['nation_create' => count($queries)];

        $mapSpace = $this->surfaceMapSpace($world);
        $target = MapCell::query()
            ->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->firstOrFail();

        $queries = [];
        app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $mapSpace,
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        );
        $counts['queue_add'] = count($queries);

        $resource = ResourceDefinition::query()->where('key', 'industrial_goods')->firstOrFail();
        $queries = [];
        app(SalePolicyService::class)->update(
            user: $user,
            nation: $nation,
            resource: $resource,
            policy: SalePolicy::KeepAmount->value,
            keepAmount: 25,
            expectedVersion: 1,
        );
        $counts['sale_policy_update'] = count($queries);

        $queries = [];
        app(TurnRunner::class)->run($world);
        $counts['turn'] = count($queries);

        if (getenv('REPORT_QUERY_COUNTS') === '1') {
            fwrite(STDERR, json_encode($counts, JSON_THROW_ON_ERROR).PHP_EOL);
        }

        $this->assertGreaterThan(0, $counts['nation_create']);
        $this->assertGreaterThan(0, $counts['queue_add']);
        $this->assertGreaterThan(0, $counts['sale_policy_update']);
        $this->assertGreaterThan(0, $counts['turn']);
        $this->assertSame(2, $world->fresh()->current_turn);
    }
}

final readonly class Pr17QueryTurnSeedGenerator implements TurnSeedGenerator
{
    public function generate(World $world, int $targetTurn, RulesetVersion $ruleset): string
    {
        return str_repeat('5', 64);
    }
}
