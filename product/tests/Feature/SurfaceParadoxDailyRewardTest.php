<?php

namespace Tests\Feature;

use App\Application\DailyQuestService;
use App\Application\ParadoxBalanceService;
use App\Models\User;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SurfaceParadoxDailyRewardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_me_exposes_surface_paradox_as_a_dedicated_untradeable_balance(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.paradox.name', '輝石')
            ->assertJsonPath('data.paradox.unit', 'Pd')
            ->assertJsonPath(
                'data.paradox.description',
                '触れると懐かしい気持ちになる不思議な宝石。それぞれのエルフごとに相性の良い輝石が存在する。貴重な施設やコマンドの使用に使用する',
            )
            ->assertJsonPath('data.paradox.balance', 0);

        $this->assertDatabaseMissing('resource_definitions', ['key' => 'paradox']);
        $this->assertDatabaseMissing('resource_definitions', ['name' => '輝石']);
    }

    public function test_daily_login_is_a_jst_idempotent_mutation_and_is_separate_from_lending_cap(): void
    {
        $user = User::factory()->create();
        Carbon::setTestNow('2026-09-09 00:05:00+09:00');

        $this->postJson('/api/v1/me/daily-login')->assertUnauthorized();
        $first = $this->actingAs($user)->postJson('/api/v1/me/daily-login')
            ->assertOk()
            ->assertJsonPath('data.awarded_now', true)
            ->assertJsonPath('data.canonical_day', '2026-09-09')
            ->assertJsonPath('data.paradox_awarded', 10)
            ->assertJsonPath('data.skip_tickets_awarded', 50)
            ->assertJsonPath('data.paradox.balance', 10)
            ->assertJsonPath('data.skip_ticket_balance', 50);
        $this->assertNotEmpty($first->getContent());

        $this->actingAs($user)->postJson('/api/v1/me/daily-login')
            ->assertOk()
            ->assertJsonPath('data.awarded_now', false)
            ->assertJsonPath('data.paradox_awarded', 0)
            ->assertJsonPath('data.skip_tickets_awarded', 0)
            ->assertJsonPath('data.paradox.balance', 10)
            ->assertJsonPath('data.skip_ticket_balance', 50);

        $this->assertDatabaseCount('user_daily_login_claims', 1);
        $this->assertDatabaseCount('user_paradox_ledger', 1);
        $this->assertDatabaseCount('user_skip_ticket_ledger', 1);
        $this->assertDatabaseCount('secretary_lending_daily_rewards', 0);
        $this->assertSame(50, UserSkipTicketBalance::query()->where('user_id', $user->id)->value('balance'));

        Carbon::setTestNow('2026-09-10 00:00:00+09:00');
        $this->actingAs($user)->postJson('/api/v1/me/daily-login')
            ->assertOk()
            ->assertJsonPath('data.awarded_now', true)
            ->assertJsonPath('data.canonical_day', '2026-09-10')
            ->assertJsonPath('data.paradox.balance', 20)
            ->assertJsonPath('data.skip_ticket_balance', 100);
        $this->assertDatabaseCount('user_daily_login_claims', 2);
    }

    public function test_all_three_confirmed_daily_quests_award_once(): void
    {
        $user = User::factory()->create();
        Carbon::setTestNow('2026-09-09 12:00:00+09:00');

        $this->actingAs($user)->postJson('/api/v1/me/daily-quests/development-opened')
            ->assertOk()
            ->assertJsonPath('data.key', DailyQuestService::DEVELOPMENT_OPENED)
            ->assertJsonPath('data.progress', 1)
            ->assertJsonPath('data.target', 1)
            ->assertJsonPath('data.completed_now', true)
            ->assertJsonPath('data.paradox_balance', 5);
        $this->actingAs($user)->postJson('/api/v1/me/daily-quests/development-opened')
            ->assertOk()
            ->assertJsonPath('data.completed_now', false)
            ->assertJsonPath('data.paradox_balance', 5);

        $quests = app(DailyQuestService::class);
        $four = $quests->recordUndergroundBattles($user->id, 4, 'test-battle-group-a');
        $this->assertSame(4, $four['progress']);
        $this->assertFalse($four['completed_now']);
        $duplicate = $quests->recordUndergroundBattles($user->id, 4, 'test-battle-group-a');
        $this->assertSame(4, $duplicate['progress']);
        $six = $quests->recordUndergroundBattles($user->id, 6, 'test-battle-group-b');
        $this->assertSame(10, $six['progress']);
        $this->assertTrue($six['completed_now']);
        $this->assertSame(10, $six['paradox_balance']);
        $afterCompletion = $quests->recordUndergroundBattles($user->id, 1, 'test-battle-group-c');
        $this->assertSame(10, $afterCompletion['progress']);
        $this->assertFalse($afterCompletion['completed_now']);
        $this->assertDatabaseCount('user_daily_quest_activities', 3);

        $command = $quests->recordCommandRegistered($user->id, 'item:123');
        $this->assertSame(DailyQuestService::COMMAND_REGISTERED, $command['key']);
        $this->assertSame('コマンドを登録する', $command['label']);
        $this->assertSame(1, $command['progress']);
        $this->assertTrue($command['completed_now']);
        $this->assertSame(15, $command['paradox_balance']);
        $commandDuplicate = $quests->recordCommandRegistered($user->id, 'item:123');
        $this->assertFalse($commandDuplicate['completed_now']);
        $this->assertSame(15, $commandDuplicate['paradox_balance']);

        Carbon::setTestNow('2026-09-10 00:00:00+09:00');
        $staleCommandRetry = $quests->currentStatus($user->id, DailyQuestService::COMMAND_REGISTERED);
        $staleBattleRetry = $quests->currentStatus($user->id, DailyQuestService::UNDERGROUND_BATTLES);
        $this->assertSame('2026-09-10', $staleCommandRetry['canonical_day']);
        $this->assertSame(0, $staleCommandRetry['progress']);
        $this->assertFalse($staleCommandRetry['completed_now']);
        $this->assertSame(0, $staleBattleRetry['progress']);
        $this->assertDatabaseMissing('user_daily_quest_progress', ['canonical_day' => '2026-09-10']);

        $this->assertDatabaseCount('user_daily_quest_progress', 3);
        $this->assertDatabaseCount('user_daily_quest_activities', 4);
        $this->assertSame(
            [DailyQuestService::COMMAND_REGISTERED, DailyQuestService::DEVELOPMENT_OPENED, DailyQuestService::UNDERGROUND_BATTLES],
            DB::table('user_daily_quest_progress')->orderBy('quest_key')->pluck('quest_key')->sort()->values()->all(),
        );
        $this->assertSame(15, app(ParadoxBalanceService::class)->balanceFor($user->id));
    }
}
