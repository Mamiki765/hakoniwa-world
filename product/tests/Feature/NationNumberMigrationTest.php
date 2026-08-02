<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NationNumberMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_partitions_by_world_and_orders_each_partition_by_internal_id(): void
    {
        $migration = require database_path('migrations/2026_08_02_020000_add_per_world_nation_numbers.php');
        $migration->down();

        $rulesetId = RulesetVersion::query()->where('key', 'roadmap-pr15-v1')->valueOrFail('id');
        $firstWorld = World::query()->create([
            'key' => 'backfill-first', 'name' => 'Backfill first', 'ruleset_version_id' => $rulesetId,
        ]);
        $secondWorld = World::query()->create([
            'key' => 'backfill-second', 'name' => 'Backfill second', 'ruleset_version_id' => $rulesetId,
        ]);
        $now = now();
        $secondFirstId = DB::table('nations')->insertGetId([
            'world_id' => $secondWorld->id, 'name' => 'Second N1', 'money' => 100, 'state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstFirstId = DB::table('nations')->insertGetId([
            'world_id' => $firstWorld->id, 'name' => 'First N1', 'money' => 100, 'state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $firstSecondId = DB::table('nations')->insertGetId([
            'world_id' => $firstWorld->id, 'name' => 'First N2', 'money' => 100, 'state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $secondSecondId = DB::table('nations')->insertGetId([
            'world_id' => $secondWorld->id, 'name' => 'Second N2', 'money' => 100, 'state' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $user = User::factory()->create();
        $membershipId = DB::table('nation_memberships')->insertGetId([
            'user_id' => $user->id,
            'world_id' => $firstWorld->id,
            'nation_id' => $firstSecondId,
            'role' => 'owner',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $eventId = DB::table('audit_events')->insertGetId([
            'actor_user_id' => $user->id,
            'event_type' => 'nation.created',
            'subject_type' => Nation::class,
            'subject_id' => $firstSecondId,
            'metadata' => json_encode(['world_id' => $firstWorld->id], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $migration->up();

        $this->assertSame([
            $secondFirstId => 1,
            $firstFirstId => 1,
            $firstSecondId => 2,
            $secondSecondId => 2,
        ], DB::table('nations')->orderBy('id')->pluck('nation_number', 'id')->all());
        $this->assertSame($firstSecondId, DB::table('nation_memberships')->where('id', $membershipId)->value('nation_id'));
        $this->assertSame($firstSecondId, DB::table('audit_events')->where('id', $eventId)->value('subject_id'));
    }

    public function test_database_rejects_duplicate_numbers_within_one_world(): void
    {
        $world = World::query()->create([
            'key' => 'constraint-world',
            'name' => 'Constraint World',
            'ruleset_version_id' => RulesetVersion::query()->where('key', 'roadmap-pr15-v1')->valueOrFail('id'),
        ]);
        Nation::query()->create([
            'world_id' => $world->id, 'nation_number' => 1, 'name' => 'N1', 'money' => 100, 'state' => 'active',
        ]);

        $this->expectException(QueryException::class);
        Nation::query()->create([
            'world_id' => $world->id, 'nation_number' => 1, 'name' => 'Duplicate',
            'money' => 100, 'state' => 'active',
        ]);
    }

    public function test_database_rejects_non_positive_nation_numbers(): void
    {
        $world = World::query()->create([
            'key' => 'positive-world',
            'name' => 'Positive World',
            'ruleset_version_id' => RulesetVersion::query()->where('key', 'roadmap-pr15-v1')->valueOrFail('id'),
        ]);

        $this->expectException(QueryException::class);
        Nation::query()->create([
            'world_id' => $world->id, 'nation_number' => 0, 'name' => 'Zero',
            'money' => 100, 'state' => 'active',
        ]);
    }
}
