<?php

namespace Tests\Feature;

use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class MessageBoardMigrationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_forward_migration_preserves_existing_users_nations_and_rulesets(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create(['display_name' => '移行前User']);
        $nation = Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => '移行前島',
            'owner_name' => '移行前島主',
            'profile_comment' => '保持対象',
            'money' => 4321,
            'state' => 'active',
            'idle_counter' => 7,
        ]);
        $rulesetSnapshot = $world->rulesetVersion()->firstOrFail()->getAttributes();
        $migration = require database_path('migrations/2026_08_11_000000_create_island_messages.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('island_messages'));
        $this->assertFalse(Schema::hasColumn('users', 'visitor_code'));
        $migration->up();

        $this->assertTrue(Schema::hasTable('island_messages'));
        $this->assertNull($user->fresh()->visitor_code);
        $this->assertNull($user->fresh()->message_board_last_posted_at);
        $this->assertSame('移行前User', $user->fresh()->display_name);
        $this->assertSame([
            'name' => '移行前島',
            'owner_name' => '移行前島主',
            'profile_comment' => '保持対象',
            'money' => 4321,
            'state' => 'active',
            'idle_counter' => 7,
        ], $nation->fresh()->only([
            'name', 'owner_name', 'profile_comment', 'money', 'state', 'idle_counter',
        ]));
        $this->assertSame($rulesetSnapshot, $world->rulesetVersion()->firstOrFail()->getAttributes());
    }
}
