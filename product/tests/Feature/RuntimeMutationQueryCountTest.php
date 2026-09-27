<?php

namespace Tests\Feature;

use App\Domain\Ruleset\CurrentRulesetGuard;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class RuntimeMutationQueryCountTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_current_ruleset_guard_adds_no_queries_for_loaded_relations(): void
    {
        $world = $this->lightweightWorld();
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $loadedWorld = $world->fresh()->load('rulesetVersion');
        $queries = [];
        app(CurrentRulesetGuard::class)->assertMutable($loadedWorld, $loadedWorld->rulesetVersion);
        $this->assertSame([], $queries, 'The guard must compare already-loaded IDs without SQL.');
    }
}
