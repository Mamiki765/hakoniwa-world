<?php

namespace Tests\Feature;

use App\Application\CollectionBackfill;
use App\Application\CollectionRegistrar;
use App\Application\NationCreationService;
use App\Application\SecretaryCollectionService;
use App\Application\SecretaryItemGrantService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryCollectionTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_presents_pay_once_per_entry_and_milestone_while_undiscovered_entries_stay_hidden(): void
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);
        $keys = array_keys(app(SecretaryItemCatalog::class)->definitions());
        $grants = app(SecretaryItemGrantService::class);
        $milestone = SecretaryCollectionService::BONUS_INTERVAL;
        foreach (array_slice($keys, 0, $milestone - 2) as $index => $key) {
            $grants->grant($secretary, $key, 1, null, "collection-test:{$index}");
        }
        app(CollectionRegistrar::class)->registerBackfill($user->id, 'monster', 'inora', 3);

        $index = $this->actingAs($user)->getJson('/api/v1/me/secretary/collection')->assertOk()->json('data');
        $hidden = collect($index['items'])->firstWhere('registered', false);
        $this->assertArrayNotHasKey('key', $hidden);
        $this->assertArrayNotHasKey('name', $hidden);
        $this->assertSame($milestone - 1, $index['summary']['registered']);
        $this->assertSame(1, $index['summary']['next_bonus_remaining']);

        $first = $keys[0];
        $this->postJson('/api/v1/me/secretary/collection/claim', ['kind' => 'item', 'key' => $first])->assertOk();
        $this->postJson('/api/v1/me/secretary/collection/claim', ['kind' => 'item', 'key' => $first])->assertOk();
        $this->assertSame(SecretaryCollectionService::ENTRY_PARADOX, $this->balance($user));
        $this->postJson('/api/v1/me/secretary/collection/claim', ['kind' => 'item', 'key' => $keys[$milestone]])
            ->assertStatus(422)->assertJsonPath('code', 'collection_not_registered');
        $this->postJson('/api/v1/me/secretary/collection/claim', ['kind' => 'bonus', 'key' => (string) $milestone])
            ->assertStatus(422)->assertJsonPath('code', 'collection_bonus_unreached');

        $grants->grant($secretary, $keys[$milestone], 1, null, 'collection-test:last');
        $result = $this->postJson('/api/v1/me/secretary/collection/claim-all')->assertOk()->json('data');
        $this->postJson('/api/v1/me/secretary/collection/claim-all')->assertOk();

        $this->assertSame(0, $result['summary']['unclaimed']);
        $this->assertSame(
            $milestone * SecretaryCollectionService::ENTRY_PARADOX + SecretaryCollectionService::BONUS_PARADOX,
            $this->balance($user),
        );
        $this->assertSame($milestone + 1, DB::table('user_paradox_ledger')->where('source_kind', 'collection')->count());
    }

    public function test_backfill_registers_durable_and_retained_history_once(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '図鑑国', '図鑑国主');
        $secretary = Secretary::query()->firstOrCreate(['user_id' => $user->id]);
        $secretary->itemInstances()->create(['item_key' => SecretaryItemCatalog::RING, 'level' => 1, 'obtained_at' => now()]);
        DB::table('nation_monster_kill_stats')->insert([
            'world_id' => $world->id, 'nation_id' => $nation->id,
            'monster_definition_id' => DB::table('monster_definitions')->where('key', 'inora')->value('id'),
            'kill_count' => 1, 'first_killed_turn' => 7, 'last_killed_turn' => 7,
        ]);
        foreach ([[4, 1], [3, 0]] as [$turn, $damage]) {
            DB::table('audit_events')->insert([
                'event_type' => 'monster.damaged', 'turn' => $turn, 'world_id' => $world->id, 'occurred_at' => now(),
                'metadata' => json_encode(['monster_key' => 'sanjira', 'attacker_nation_id' => $nation->id, 'actual_damage' => $damage]),
            ]);
        }
        $former = User::factory()->create();
        $abandoned = app(NationCreationService::class)->create($former, $world, '旧図鑑国', '旧国主');
        DB::table('nation_monster_kill_stats')->insert([
            'world_id' => $world->id, 'nation_id' => $abandoned->id,
            'monster_definition_id' => DB::table('monster_definitions')->where('key', 'inora')->value('id'),
            'kill_count' => 1, 'first_killed_turn' => 2, 'last_killed_turn' => 2,
        ]);
        DB::table('nation_memberships')->where('nation_id', $abandoned->id)->delete();
        DB::table('nations')->where('id', $abandoned->id)->update(['state' => 'abandoned']);
        DB::table('user_collection_entries')->delete();
        $backfill = app(CollectionBackfill::class);

        $this->assertSame(['damage_events' => 1, 'kill_stats' => 2], array_intersect_key(
            $backfill->preview()['entries_by_source'], ['damage_events' => 0, 'kill_stats' => 0],
        ));
        $this->assertSame(0, DB::table('user_collection_entries')->count());
        // A live entry made after the release keeps its row but takes the older recorded turn.
        app(CollectionRegistrar::class)->registerMonsterForNation($nation->id, 'sanjira', 9);
        $applied = $backfill->apply();
        $this->assertSame($applied['candidates'] - 1, $applied['inserted']);
        $this->assertSame(0, $backfill->apply()['inserted']);
        $this->assertSame([['inora', 7, 'backfill'], ['sanjira', 4, 'live']], DB::table('user_collection_entries')
            ->where('user_id', $user->id)->where('kind', 'monster')->orderBy('entry_key')
            ->get()->map(fn (object $row): array => [$row->entry_key, (int) $row->first_turn, $row->source])->all());
        $this->assertDatabaseHas('user_collection_entries', ['user_id' => $former->id, 'kind' => 'monster', 'entry_key' => 'inora', 'first_turn' => 2]);
        $this->assertDatabaseHas('user_collection_entries', ['user_id' => $user->id, 'kind' => 'item', 'entry_key' => SecretaryItemCatalog::RING, 'claimed_at' => null]);
    }

    private function balance(User $user): int
    {
        return (int) DB::table('user_paradox_balances')->where('user_id', $user->id)->value('balance');
    }
}
