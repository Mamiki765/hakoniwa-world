<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundIntroCatalog;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\UndergroundBattle;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundTrialProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundMadMoonTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    public function test_inherited_actual_wins_unlock_only_after_a_normal_harbor_battle(): void
    {
        [$user, $secretary] = $this->secretaryUser('継続の秘書');
        $profile = $this->openEquipmentProfile($secretary);
        foreach (['trial_01', 'trial_02', 'trial_03'] as $trial) {
            UndergroundTrialProgress::query()->create(['underground_profile_id' => $profile->id,
                'trial_key' => $trial, 'unlocked_at' => now(), 'first_cleared_at' => now()]);
        }
        // One additional skip after the existing 50 actual wins must not satisfy 51.
        config(['underground-intro.mad_moon.required_actual_clears' => 51]);
        $clears = UndergroundContentClearProgress::query()->create(['underground_profile_id' => $profile->id,
            'content_type' => 'hunting_ground', 'content_key' => 'yunagi_harbor', 'actual_clear_count' => 50, 'total_clear_count' => 51]);
        $this->actingAs($user)->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.intro_pending', false);
        $this->postJson('/api/v1/me/underground/explore', ['request_id' => (string) Str::uuid(), 'hunting_ground_key' => 'yunagi_harbor'])->assertOk();
        $this->assertNull($profile->fresh()->mad_moon_unlocked_at);
        $clears->update(['actual_clear_count' => 51]);
        $profile->refresh()->update(['next_battle_at' => null]);
        $this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.intro_pending', false);
        $trigger = ['request_id' => (string) Str::uuid(), 'hunting_ground_key' => 'yunagi_harbor'];
        $this->postJson('/api/v1/me/underground/explore', $trigger)->assertOk();
        $this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.intro_pending', true);
        $this->assertNotNull($profile->fresh()->mad_moon_unlocked_at);
        $this->postJson('/api/v1/me/underground/explore', $trigger)->assertOk()->assertJsonPath('data.id', $trigger['request_id']);
        $this->postJson('/api/v1/me/underground/explore', ['request_id' => (string) Str::uuid(), 'hunting_ground_key' => 'yunagi_harbor'])
            ->assertConflict()->assertJsonPath('code', 'underground_mad_moon_intro_pending');
    }

    public function test_chain_resources_once_rewards_and_victory_scene_survive_receipt_removal(): void
    {
        [$user, $secretary] = $this->secretaryUser('名前$&<img>');
        $user->forceFill(['visitor_code' => '41400001'])->save();
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['mad_moon_unlocked_at' => now(), 'current_hp' => 123, 'awakening_gauge' => 67,
            'unspent_stp' => 7, 'next_battle_at' => now()->addHour(), 'villa_purchased_at' => now()]);
        UndergroundTrialProgress::query()->create(['underground_profile_id' => $profile->id,
            'trial_key' => 'trial_01', 'unlocked_at' => now(), 'first_cleared_at' => now()]);
        config(['underground-alpha-v1.mad_moon.enemy.max_hp' => 1,
            'underground-alpha-v1.mad_moon.enemy.physical_defense' => 0,
            'underground-alpha-v1.mad_moon.enemy.magical_defense' => 0]);
        app(UndergroundStarterEquipmentService::class)->reconcile($profile);
        $equipmentBefore = $profile->ownedEquipment()->count();
        $rentalBefore = $profile->rental_party;
        $beforeXp = $profile->combat_xp;
        $beforeGold = $profile->shard_balance;
        $beforeStp = $profile->unspent_stp;
        $beforeBanked = $profile->banked_shard_balance;
        $this->actingAs($user);
        $this->postJson('/api/v1/me/underground/inn/rest', ['request_id' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'underground_mad_moon_intro_pending');
        $this->postJson('/api/v1/me/underground/bank/transfer', ['request_id' => (string) Str::uuid(), 'action' => 'deposit_all'])
            ->assertConflict()->assertJsonPath('code', 'underground_mad_moon_intro_pending');
        $this->assertSame($beforeBanked, $profile->fresh()->banked_shard_balance);
        $unread = collect($this->getJson('/api/v1/me/underground')->assertOk()->json('data.recollections.entries'))
            ->firstWhere('key', 'mad_moon_intro');
        $this->assertFalse($unread['experienced']);
        $this->assertArrayNotHasKey('body', $unread);
        $request = ['request_id' => (string) Str::uuid()];
        $battle = $this->actingAs($user)->postJson('/api/v1/me/underground/mad-moon', $request)->assertOk()
            ->assertJsonPath('data.result', 'victory')->assertJsonPath('data.current_hp_before', 123)
            ->assertJsonPath('data.awakening.gauge_before', 67)
            ->assertJsonPath('data.xp_awarded', 200000)->assertJsonPath('data.shard_delta', 120000)->json('data');
        $this->assertSame($beforeXp + 200000, $profile->fresh()->combat_xp);
        $this->assertSame($beforeGold + 120000, $profile->fresh()->shard_balance);
        $this->assertGreaterThan($battle['combat_level_before'], $battle['combat_level_after']);
        $this->assertGreaterThan(0, $battle['stp_awarded']);
        $this->assertSame($beforeStp + $battle['stp_awarded'], $battle['unspent_stp_after']);
        $this->assertSame($profile->fresh()->unspent_stp, $battle['unspent_stp_after']);
        $this->assertSame($equipmentBefore, $profile->ownedEquipment()->count());
        $this->assertSame($rentalBefore, $profile->fresh()->rental_party);
        $this->assertSame($battle['current_hp_after'], $profile->fresh()->current_hp);
        $this->assertSame($battle['awakening']['gauge_after'], $profile->fresh()->awakening_gauge);
        $this->assertCount(2, $battle['party']['members']);
        $this->assertFalse($battle['initial_state']['npc:guide']['awakening_unlocked']);
        $this->postJson('/api/v1/me/underground/mad-moon', $request)->assertOk()->assertJsonPath('data.id', $request['request_id'])
            ->assertJsonPath('data.unspent_stp_after', $battle['unspent_stp_after']);
        $this->assertSame($beforeGold + 120000, $profile->fresh()->shard_balance);
        $this->assertSame(0, Artisan::call('underground:statistics', ['--activity' => [UndergroundBattle::ACTIVITY_EVENT]]));
        $eventReport = json_decode(explode("\n", trim(Artisan::output()))[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $eventReport['battle_count']);
        $this->assertSame(0, Artisan::call('underground:statistics'));
        $defaultReport = json_decode(explode("\n", trim(Artisan::output()))[0], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $defaultReport['battle_count']);
        UndergroundBattle::query()->where('underground_profile_id', $profile->id)->delete();
        $this->postJson('/api/v1/me/underground/mad-moon', ['request_id' => (string) Str::uuid()])->assertConflict();
        $entries = collect($this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.victory_pending', true)
            ->assertJsonPath('data.mad_moon.retry_available', false)->json('data.recollections.entries'));
        $this->assertTrue($entries->firstWhere('key', 'mad_moon_intro')['experienced']);
        $victory = $entries->firstWhere('key', 'mad_moon_victory');
        $this->assertFalse($victory['experienced']);
        $this->assertTrue($victory['locked']);
        $this->assertArrayNotHasKey('body', $victory);
        $this->postJson('/api/v1/me/underground/events/advance', ['request_id' => (string) Str::uuid(), 'event' => 'mad_moon_victory', 'page' => 1])->assertOk();
        $replayBefore = $profile->fresh()->getRawOriginal();
        $requestCount = $profile->introRequests()->count();
        $entries = collect($this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.victory_pending', false)
            ->json('data.recollections.entries'));
        $stories = app(UndergroundIntroCatalog::class)->madMoon();
        foreach (['mad_moon_intro' => 'introduction', 'mad_moon_victory' => 'victory'] as $key => $storyKey) {
            $entry = $entries->firstWhere('key', $key);
            $story = $stories[$storyKey];
            $this->assertTrue($entry['experienced']);
            $this->assertFalse($entry['locked']);
            $this->assertSame($story['title'], $entry['title']);
            $this->assertSame(array_map(fn (string $line): string => str_replace('(秘書名)', $secretary->name, $line), $story['body']), $entry['body']);
            $this->assertSame($key === 'mad_moon_intro' ? 'yunagi-harbor-mad-moon' : 'yunagi-harbor-mad-moon-victory', $entry['scene']);
        }
        $this->assertSame($replayBefore, $profile->fresh()->getRawOriginal());
        $this->assertSame($requestCount, $profile->introRequests()->count());
        $this->assertSame(0, $profile->battles()->count());
    }

    public function test_withdrawal_and_defeat_allow_retry_after_the_normal_wait(): void
    {
        [$user, $secretary] = $this->secretaryUser('再挑戦の秘書');
        $user->forceFill(['visitor_code' => '41400002'])->save();
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['mad_moon_unlocked_at' => now()]);
        $this->actingAs($user);
        $this->postJson('/api/v1/me/underground/events/advance', ['request_id' => (string) Str::uuid(), 'event' => 'mad_moon', 'page' => 1])->assertOk();
        $profile->update(['current_hp' => 492, 'next_battle_at' => null]);
        config(['underground-alpha-v1.mad_moon.max_rounds' => 1]);
        $this->postJson('/api/v1/me/underground/mad-moon', ['request_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('data.result', 'withdrawal')->assertJsonPath('data.xp_awarded', 0)->assertJsonPath('data.shard_delta', 0);
        $this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.retry_available', true);
        $this->postJson('/api/v1/me/underground/mad-moon', ['request_id' => (string) Str::uuid()])->assertConflict();
        $profile->refresh()->update(['next_battle_at' => null]);
        config(['underground-alpha-v1.mad_moon.max_rounds' => 80,
            'underground-alpha-v1.mad_moon.enemy.base_stats.might' => 1000000,
            'underground-alpha-v1.mad_moon.enemy.normal_attack.dodgeable' => false,
            'underground-alpha-v1.mad_moon.enemy.ai_rules' => [['conditions' => [['type' => 'always']], 'action' => 'normal_attack']]]);
        $this->postJson('/api/v1/me/underground/mad-moon', ['request_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('data.result', 'defeat');
        $this->getJson('/api/v1/me/underground')->assertOk()->assertJsonPath('data.mad_moon.retry_available', true);
        $this->assertNull($profile->fresh()->mad_moon_cleared_at);
    }
}
