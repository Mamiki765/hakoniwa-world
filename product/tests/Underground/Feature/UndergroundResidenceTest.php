<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundEquipmentDropService;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundBattle;
use App\Models\UndergroundContentClearProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundTrialProgress;
use App\Models\UserSkipTicketBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundResidenceTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    private ?string $guideAssetDirectory = null;

    public function test_journal_records_keep_missing_self_facts_separate_from_party_and_other_owners(): void
    {
        [$user, $secretary] = $this->secretaryUser('日誌の主');
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['villa_purchased_at' => now()]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.maximum_hit.value', null)
            ->assertJsonPath('data.favorite_skills.entries', [])
            ->assertJsonPath('data.combat_support.self.effective_healing.value', 0)
            ->assertJsonPath('data.content_clears', [])
            ->assertJsonPath('data.lending_participation_count', 0);

        // Supported compacted older party receipt: only PT support totals survive.
        $legacy = UndergroundBattle::query()->where('underground_profile_id', $profile->id)->firstOrFail();
        $legacy->update(['statistics_version' => 1, 'statistics' => [
            'self' => [], 'party' => ['effective_healing' => 30, 'damage_prevented' => 14, 'revivals' => 2],
        ]]);
        $this->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.combat_support.self.effective_healing.value', null)
            ->assertJsonPath('data.combat_support.party.effective_healing.value', 30)
            ->assertJsonPath('data.maximum_hit.unknown_battles', 1);
        $known = $this->tutorialBattle($profile);
        $known->update(['statistics_version' => 1, 'statistics' => [
            'self' => ['maximum_hit' => 90, 'maximum_hit_action_key' => 'decisive_heavenrend',
                'maximum_hit_damage_source' => 'direct', 'effective_healing' => 7, 'damage_prevented' => 4,
                'revivals_performed' => 1, 'action_usage' => ['normal_attack' => 999,
                    'mending_prayer' => 8, 'renewing_guard' => 4, 'decisive_heavenrend' => 4, 'harmony_heal' => 1]],
            'party' => ['effective_healing' => 20, 'damage_prevented' => 10, 'revivals' => 1],
        ]]);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'shallow_caves', 'actual_clear_count' => 2, 'total_clear_count' => 5,
        ]);
        // Guide-duel progress is reachable but lies outside adventure journal clears.
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'guide_duel',
            'content_key' => $catalog->guideDuel()['key'],
            'actual_clear_count' => 1, 'total_clear_count' => 1,
        ]);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_01',
            'unlocked_at' => now(), 'first_cleared_at' => now(),
        ]);
        UserSkipTicketBalance::query()->create(['user_id' => $user->id, 'balance' => 0, 'lifetime_participation_count' => 42]);
        [$other, $otherSecretary] = $this->secretaryUser('非公開の他人');
        $otherProfile = $this->openEquipmentProfile($otherSecretary);
        $otherProfile->update(['villa_purchased_at' => now()]);
        UserSkipTicketBalance::query()->create(['user_id' => $other->id, 'balance' => 0, 'lifetime_participation_count' => 99]);
        $this->tutorialBattle($otherProfile)->update(['statistics_version' => 1,
            'statistics' => ['self' => ['maximum_hit' => 99_999, 'action_usage' => ['private_other_action' => 1000]]]]);

        $data = $this->getJson('/api/v1/me/underground/journal?user_id='.$other->id)->assertOk()
            ->assertJsonPath('data.maximum_hit.value', 90)
            ->assertJsonPath('data.maximum_hit.action_name', app(UndergroundAwakening::class)->technique('martial_red', 'decisive_heavenrend')['name'])
            ->assertJsonPath('data.maximum_hit.known_battles', 1)
            ->assertJsonPath('data.maximum_hit.unknown_battles', 1)
            ->assertJsonPath('data.favorite_skills.entries.0.key', 'mending_prayer')
            ->assertJsonPath('data.favorite_skills.entries.1.key', 'decisive_heavenrend')
            ->assertJsonPath('data.favorite_skills.entries.2.key', 'renewing_guard')
            ->assertJsonPath('data.favorite_skills.unknown_battles', 1)
            ->assertJsonPath('data.combat_support.self.effective_healing.value', 7)
            ->assertJsonPath('data.combat_support.self.effective_healing.unknown_battles', 1)
            ->assertJsonPath('data.combat_support.party.effective_healing.value', 50)
            ->assertJsonPath('data.combat_support.self.damage_prevented.value', 4)
            ->assertJsonPath('data.combat_support.party.damage_prevented.value', 24)
            ->assertJsonPath('data.combat_support.self.revivals.value', 1)
            ->assertJsonPath('data.combat_support.party.revivals.value', 3)
            ->assertJsonPath('data.content_clears.0.name', $catalog->explorationHuntingGround('shallow_caves')['name'])
            ->assertJsonPath('data.content_clears.0.actual_clear_count', 2)
            ->assertJsonPath('data.content_clears.0.skip_clear_count', 3)
            ->assertJsonPath('data.content_clears.1.actual_clear_count', null)
            ->assertJsonPath('data.content_clears.1.skip_clear_count', null)
            ->assertJsonMissing(['type' => 'guide_duel'])
            ->assertJsonPath('data.lending_participation_count', 42)
            ->assertJsonMissing(['key' => 'private_other_action'])->json('data');
        $this->assertArrayNotHasKey('occurred_at', $data['maximum_hit']);
        $this->assertArrayNotHasKey('opponent', $data['maximum_hit']);
        $this->actingAs($other)->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.maximum_hit.value', 99_999)
            ->assertJsonPath('data.lending_participation_count', 99);
    }

    public function test_yunagi_harbor_intro_requires_trial_three_clear_and_remains_read_after_receipt_removal(): void
    {
        [$user, $secretary] = $this->secretaryUser('帰港地に進む秘書');
        $profile = $this->openEquipmentProfile($secretary);
        $progress = UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_03', 'unlocked_at' => now(),
        ]);
        $request = ['request_id' => (string) Str::uuid(), 'event' => 'yunagi_harbor', 'page' => 1];
        $this->actingAs($user)->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.yunagi_harbor_intro_available', false);
        $this->postJson('/api/v1/me/underground/events/advance', $request)->assertConflict();
        $progress->update(['first_cleared_at' => now()]);
        $this->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.yunagi_harbor_intro_available', true)
            ->assertJsonPath('data.residence.mirror_owned', false);
        $before = $profile->fresh()->only(['combat_xp', 'shard_balance', 'unlocked_area_layers']);
        $this->postJson('/api/v1/me/underground/events/advance', $request)->assertOk()
            ->assertJsonPath('data.yunagi_harbor_intro_available', false);
        $completedAt = $profile->fresh()->yunagi_harbor_intro_completed_at;
        $this->assertNotNull($completedAt);
        $this->postJson('/api/v1/me/underground/events/advance', $request)->assertOk();
        UndergroundIntroRequest::query()->where('underground_profile_id', $profile->id)->delete();
        $this->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.yunagi_harbor_intro_available', false);
        $this->postJson('/api/v1/me/underground/events/advance', [
            ...$request, 'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->assertEquals($completedAt, $profile->fresh()->yunagi_harbor_intro_completed_at);
        $this->assertSame($before, $profile->fresh()->only(array_keys($before)));
    }

    public function test_otherworld_discovery_requires_trial_two_and_level_one_hundred_and_survives_request_removal(): void
    {
        [$user, $secretary] = $this->secretaryUser('異世界に向かう秘書');
        $profile = $this->openEquipmentProfile($secretary);
        $profile->update(['combat_level' => 99]);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_02',
            'unlocked_at' => now(), 'first_cleared_at' => now(),
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/main')->assertOk()
            ->assertJsonPath('data.otherworld_intro_available', false);
        $profile->update(['combat_level' => 100]);
        $this->getJson('/api/v1/me/underground/main')->assertOk()
            ->assertJsonPath('data.otherworld_intro_available', true)->assertJsonPath('data.otherworld_unlocked', false);
        $request = ['request_id' => (string) Str::uuid(), 'event' => 'otherworld', 'page' => 1];
        $this->postJson('/api/v1/me/underground/events/advance', $request)->assertOk()
            ->assertJsonPath('data.otherworld_unlocked', true)->assertJsonPath('data.otherworld_intro_available', false);
        $discoveredAt = $profile->fresh()->otherworld_discovered_at;
        $this->postJson('/api/v1/me/underground/events/advance', $request)->assertOk();
        UndergroundIntroRequest::query()->where('underground_profile_id', $profile->id)->delete();
        $this->getJson('/api/v1/me/underground/main')->assertOk()->assertJsonPath('data.otherworld_unlocked', true);
        $this->assertEquals($discoveredAt, $profile->fresh()->otherworld_discovered_at);
    }

    public function test_purchased_vault_expansions_allow_new_items_without_charging_retries(): void
    {
        // Reach a full vault through ordinary purchases without manufacturing hundreds of items.
        config(['underground-equipment.vault_capacity' => 2]);
        [$user, $secretary] = $this->secretaryUser('増築する秘書');
        $profile = $this->openEquipmentProfile($secretary, 2_500_000);
        $this->actingAs($user)->getJson('/api/v1/me/underground')->assertOk();
        $purchase = fn (string $key) => $this->postJson('/api/v1/me/underground/equipment/shop/purchase', [
            'request_id' => (string) Str::uuid(), 'definition_key' => $key,
        ]);
        $purchase('iron_dagger')->assertOk();
        $purchase('leather_armor')->assertConflict()->assertJsonPath('code', 'underground_vault_full');
        $this->postJson('/api/v1/me/underground/residence/purchase', [
            'request_id' => (string) Str::uuid(), 'item' => 'villa',
        ])->assertOk();
        $balanceBefore = $profile->fresh()->shard_balance;
        $request = ['request_id' => (string) Str::uuid(), 'item' => 'vault_expansion'];
        $this->postJson('/api/v1/me/underground/residence/purchase', $request)->assertOk();
        $this->postJson('/api/v1/me/underground/residence/purchase', $request)->assertOk();
        $this->postJson('/api/v1/me/underground/residence/purchase', [
            ...$request, 'request_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('data.shard_balance', $balanceBefore - 1_000_000);
        $purchase('leather_armor')->assertOk();
        $this->getJson('/api/v1/me/underground/equipment/vault')->assertOk()
            ->assertJsonPath('data.total', 3)->assertJsonPath('data.capacity', 102);
        $remaining = app(UndergroundEquipmentDropService::class)->remainingVaultCapacity($profile->fresh());
        $this->assertSame(99, $remaining);

        $this->postJson('/api/v1/me/underground/residence/purchase', [
            'request_id' => (string) Str::uuid(), 'item' => 'resonance_expansion',
        ])->assertOk()->assertJsonPath('data.residence.resonance_expansion_owned', true);
        $this->getJson('/api/v1/me/underground/equipment/vault?inventory=resonance')->assertOk()
            ->assertJsonPath('data.capacity', 100);
        $this->assertSame(100, app(UndergroundEquipmentDropService::class)->remainingVaultCapacity($profile->fresh(), 'resonance'));
    }

    public function test_distorted_stone_purchases_keep_the_daily_price_and_replay_across_midnight(): void
    {
        Carbon::setTestNow('2026-09-22 23:59:00+09:00');
        [$user, $secretary] = $this->secretaryUser('輝石を買う秘書');
        $profile = $this->openEquipmentProfile($secretary, 200_000);
        $profile->update(['next_battle_at' => now()->addSeconds(10)]);
        $this->actingAs($user)->postJson('/api/v1/me/underground/shop/distorted-stone', [
            'request_id' => (string) Str::uuid(), 'price' => 0,
        ])->assertConflict()->assertJsonPath('code', 'underground_distorted_stone_locked');
        $this->getJson('/api/v1/me/underground/distorted-stone-reminder')
            ->assertOk()->assertJsonPath('data.unclaimed', false);
        $this->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.distorted_stone_reminder', false);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_02',
            'unlocked_at' => now(), 'first_cleared_at' => now(),
        ]);
        $profile->update(['otherworld_discovered_at' => now()]);
        $this->getJson('/api/v1/me/underground/distorted-stone-reminder')
            ->assertOk()->assertJsonPath('data.unclaimed', true)
            ->assertJsonPath('data.day', '2026-09-22')->assertJsonPath('data.reset_after_ms', 60_000);
        $this->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.distorted_stone_reminder', true);
        $purchases = [['request_id' => (string) Str::uuid(), 'price' => 0]];
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', $purchases[0])->assertOk()
            ->assertJsonPath('data.distorted_stone_reminder', false);
        $this->getJson('/api/v1/me/underground/distorted-stone-reminder')
            ->assertOk()->assertJsonPath('data.unclaimed', false);
        foreach ([10_000, 50_000, 100_000] as $price) {
            $request = ['request_id' => (string) Str::uuid(), 'price' => $price];
            $this->actingAs($user)->postJson('/api/v1/me/underground/shop/distorted-stone', $request)->assertOk();
            $purchases[] = $request;
        }
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', [
            'request_id' => (string) Str::uuid(), 'price' => 100_000,
        ])->assertConflict()->assertJsonPath('code', 'underground_distorted_stone_sold_out');
        $this->assertSame(4, $profile->fresh()->distorted_stone_balance);
        $this->assertSame(40_000, $profile->fresh()->shard_balance);
        Carbon::setTestNow('2026-09-23 00:00:00+09:00');
        $this->getJson('/api/v1/me/underground/distorted-stone-reminder')
            ->assertOk()->assertJsonPath('data.unclaimed', true)
            ->assertJsonPath('data.day', '2026-09-23')->assertJsonPath('data.reset_after_ms', 86_400_000);
        $this->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.distorted_stone_shop.purchased_today', 0)
            ->assertJsonPath('data.distorted_stone_shop.next_price', 0)
            ->assertJsonPath('data.distorted_stone_shop.day', '2026-09-23')
            ->assertJsonPath('data.distorted_stone_shop.reset_after_ms', 86_400_000)
            ->assertJsonPath('data.distorted_stone_reminder', true);
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', $purchases[3])->assertOk()
            ->assertJsonPath('data.distorted_stone_shop.purchased_today', 0)
            ->assertJsonPath('data.distorted_stone_shop.balance', 4);
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', [
            'request_id' => (string) Str::uuid(), 'price' => 0,
        ])->assertOk()->assertJsonPath('data.distorted_stone_shop.balance', 5)
            ->assertJsonPath('data.distorted_stone_reminder', false);
        $this->assertSame(40_000, $profile->fresh()->shard_balance);
        $this->assertEquals(Carbon::parse('2026-09-22 23:59:10+09:00'), $profile->fresh()->next_battle_at);
        $profile->update(['distorted_stone_balance' => 0]);
        $this->getJson('/api/v1/me/underground/distorted-stone-reminder')
            ->assertOk()->assertJsonPath('data.unclaimed', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        if ($this->guideAssetDirectory !== null) {
            File::deleteDirectory($this->guideAssetDirectory);
        }
        parent::tearDown();
    }

    public function test_residence_purchase_and_event_retries_preserve_assets_and_unlock_only_the_owner_journal(): void
    {
        [$user, $secretary] = $this->secretaryUser('別荘の主');
        $profile = $this->openEquipmentProfile($secretary, 1_600_000);
        // Supported upgrade: trial clears can predate the furniture feature.
        $firstClear = Carbon::parse('2026-09-10T12:34:56+09:00')->utc();
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_01',
            'unlocked_at' => $firstClear->copy()->subDay(), 'first_cleared_at' => $firstClear,
        ]);
        UndergroundTrialProgress::query()->create([
            'underground_profile_id' => $profile->id, 'trial_key' => 'trial_02', 'unlocked_at' => $firstClear,
        ]);
        [$other, $otherSecretary] = $this->secretaryUser('別の秘書');
        $otherProfile = $this->openEquipmentProfile($otherSecretary);
        $this->guideAssetDirectory = storage_path('framework/testing/guide-'.Str::uuid());
        File::ensureDirectoryExists($this->guideAssetDirectory.'/npc');
        copy(resource_path('images/underground-placeholder.jpg'), $this->guideAssetDirectory.'/npc/guide.jpg');
        file_put_contents($this->guideAssetDirectory.'/scene-assets.json', json_encode([
            'assets' => ['guide' => ['file' => 'npc/guide.jpg', 'creation_method' => 'commissioned_or_permitted',
                'credit' => '© 配置用素材', 'show_credit' => true]],
            'scenes' => ['shop' => ['actors' => [['asset' => 'guide', 'name' => '案内人']]]],
        ], JSON_THROW_ON_ERROR));
        config(['hakoniwa.assets.path' => $this->guideAssetDirectory]);
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => false, 'own_secretary_fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.visuals.scenes.shop.actors', []);
        $post = fn (string $path, array $body) => $this->actingAs($user)->postJson('/api/v1/me/underground/'.$path, [
            'request_id' => (string) Str::uuid(), ...$body,
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertConflict();
        $post('residence/purchase', ['item' => 'mirror'])->assertConflict();
        $post('residence/purchase', ['item' => 'trophy_shelf'])->assertConflict();
        $post('events/advance', ['event' => 'exchange', 'page' => 2])->assertConflict();
        $post('events/advance', ['event' => 'exchange', 'page' => 1])->assertOk();
        $post('events/advance', ['event' => 'exchange', 'page' => 2])->assertOk();
        $requestId = (string) Str::uuid();
        $post('residence/purchase', ['item' => 'villa', 'request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_500_000);
        $post('residence/purchase', ['item' => 'villa', 'request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_500_000);
        $post('residence/purchase', ['item' => 'villa'])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_500_000);
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()->assertJsonPath('data.trophies', []);
        $shelfRequest = (string) Str::uuid();
        $post('residence/purchase', ['item' => 'trophy_shelf', 'request_id' => $shelfRequest])
            ->assertOk()->assertJsonPath('data.residence.trophy_shelf_owned', true)->assertJsonPath('data.shard_balance', 1_000_000);
        $purchasedAt = $profile->fresh()->trophy_shelf_purchased_at;
        $post('residence/purchase', ['item' => 'trophy_shelf', 'request_id' => $shelfRequest])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_000_000);
        $post('residence/purchase', ['item' => 'trophy_shelf'])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_000_000);
        $this->assertEquals($purchasedAt, $profile->fresh()->trophy_shelf_purchased_at);
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'bahamul_beginner_1', 'actual_clear_count' => 1, 'total_clear_count' => 1,
        ])->forceFill(['first_cleared_at' => $firstClear])->save();
        UndergroundContentClearProgress::query()->create([
            'underground_profile_id' => $profile->id, 'content_type' => 'hunting_ground',
            'content_key' => 'bahamul_intermediate_1', 'actual_clear_count' => 1, 'total_clear_count' => 1,
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.trophies.0.key', 'trial_01')
            ->assertJsonPath('data.trophies.0.achieved_at', $firstClear->copy()->utc()->toIso8601String())
            ->assertJsonMissing(['name' => 'デュラハンの兜'])
            ->assertJsonMissing(['name' => '魔剣のレプリカ']);
        $trophies = array_column($this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()->json('data.trophies'), null, 'key');
        $this->assertSame(['name' => '黒竜の爪(初級1)', 'achievement' => '黒竜バハムル撃破(初級1)',
            'achieved_at' => $firstClear->toIso8601String()], array_diff_key($trophies['bahamul_beginner_1'], ['key' => true]));
        $this->assertSame('黒竜の爪(中級1)', $trophies['bahamul_intermediate_1']['name']);
        $this->assertSame('黒竜バハムル撃破(中級1)', $trophies['bahamul_intermediate_1']['achievement']);
        $this->assertNull($trophies['bahamul_intermediate_1']['achieved_at']);
        $post('residence/purchase', ['item' => 'mirror'])
            ->assertOk()->assertJsonPath('data.residence.mirror_owned', true)->assertJsonPath('data.shard_balance', 0);
        $post('events/advance', ['event' => 'mirror', 'page' => 1])
            ->assertOk()->assertJsonPath('data.residence.mirror_event_completed', true)
            ->assertJsonPath('data.visuals.show_ai', false)
            ->assertJsonPath('data.visuals.scenes.shop.actors.0.asset.id', 'guide')
            ->assertJsonPath('data.visuals.scenes.shop.actors.0.asset.credit', '© 配置用素材');
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true, 'own_secretary_fallback' => 'silhouette',
        ])->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/underground')->assertOk()
            ->assertJsonPath('data.visuals.show_ai', true)
            ->assertJsonPath('data.visuals.scenes.shop.actors.0.asset.id', 'guide');

        // These are permanent battle rows supported after log compaction; totals must not read the deleted log.
        $battle = $this->tutorialBattle($profile);
        $battle->update(['activity_type' => UndergroundBattle::ACTIVITY_EXPLORATION, 'activity_key' => 'shallow_caves',
            'damage_dealt' => 900, 'damage_received' => 400,
            'statistics_version' => 1, 'statistics' => ['self' => ['damage_dealt' => 20, 'damage_received' => 10]]]);
        $story = $this->tutorialBattle($profile);
        $story->update(['activity_type' => UndergroundBattle::ACTIVITY_STORY, 'activity_key' => 'shopkeeper_special_loss']);
        SecretaryGuideConversationTotal::query()->create(['secretary_id' => $secretary->id, 'punch_count' => 3]);
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.battle_count', 2)->assertJsonPath('data.victory_count', 2)
            ->assertJsonPath('data.damage_dealt', 21)->assertJsonPath('data.damage_received', 10)
            ->assertJsonPath('data.guide_punch_count', 3);
        $this->actingAs($other)->getJson('/api/v1/me/underground/journal')->assertConflict();
        $this->assertNull($otherProfile->fresh()->villa_purchased_at);
        $this->assertNull($otherProfile->fresh()->trophy_shelf_purchased_at);
        $this->assertSame(5_000, $profile->fresh()->banked_shard_balance);
    }

    public function test_home_background_requires_a_clear_and_ai_off_keeps_the_selection_without_exposing_the_ai_url(): void
    {
        [$user, $secretary] = $this->secretaryUser('背景を選ぶ秘書');
        $profile = $this->openEquipmentProfile($secretary, 1_100_000);
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true, 'own_secretary_fallback' => 'silhouette',
        ])->assertOk();
        $directory = storage_path('framework/testing/scene-'.Str::uuid());
        File::ensureDirectoryExists($directory.'/background');
        File::ensureDirectoryExists($directory.'/npc');
        copy(resource_path('images/underground-placeholder.jpg'), $directory.'/background/area.jpg');
        copy(resource_path('images/underground-placeholder.jpg'), $directory.'/npc/guide.jpg');
        file_put_contents($directory.'/scene-assets.json', json_encode([
            'assets' => [
                'cave' => ['file' => 'background/area.jpg', 'creation_method' => 'ai_generated'],
                'castle' => ['file' => 'background/area.jpg', 'creation_method' => 'ai_generated'],
                'harbor' => ['file' => 'background/area.jpg', 'creation_method' => 'ai_generated'],
                'guide' => ['file' => 'npc/guide.jpg', 'creation_method' => 'commissioned_or_permitted'],
            ],
            'scenes' => [
                'hunting_ground.black_crystal_cave' => ['background' => 'cave'],
                'trial_03.twilight_castle' => ['background' => 'castle'],
                'hunting_ground.yunagi_harbor' => ['background' => 'harbor'],
                'shop' => ['actors' => [['asset' => 'guide', 'name' => '案内人']]],
            ],
        ], JSON_THROW_ON_ERROR));
        config(['hakoniwa.assets.path' => $directory]);
        try {
            $payload = ['key' => 'hunting_ground.black_crystal_cave', 'request_id' => (string) Str::uuid()];
            $this->actingAs($user)->postJson('/api/v1/me/underground/home-background', $payload)->assertConflict();
            UndergroundTrialProgress::query()->create([
                'underground_profile_id' => $profile->id, 'trial_key' => 'trial_01',
                'unlocked_at' => now(), 'first_cleared_at' => now(),
            ]);
            $this->actingAs($user)->postJson('/api/v1/me/underground/home-background', $payload)->assertOk()
                ->assertJsonPath('data.visuals.scenes.home.background.id', 'cave');
            $castle = ['key' => 'trial_03.twilight_castle', 'request_id' => (string) Str::uuid()];
            $harbor = ['key' => 'hunting_ground.yunagi_harbor', 'request_id' => (string) Str::uuid()];
            $this->actingAs($user)->postJson('/api/v1/me/underground/home-background', $castle)->assertConflict();
            $this->postJson('/api/v1/me/underground/home-background', $harbor)->assertConflict();
            foreach (['trial_02', 'trial_03'] as $trialKey) {
                UndergroundTrialProgress::query()->create([
                    'underground_profile_id' => $profile->id, 'trial_key' => $trialKey,
                    'unlocked_at' => now(), 'first_cleared_at' => now(),
                ]);
            }
            $this->actingAs($user)->postJson('/api/v1/me/underground/home-background', $castle)->assertOk()
                ->assertJsonPath('data.visuals.scenes.home.background.id', 'castle')
                ->assertJsonPath('data.visuals.scenes.home.background.creation_method', 'ai_generated');
            $selected = $this->postJson('/api/v1/me/underground/home-background', $harbor)->assertOk()
                ->assertJsonPath('data.visuals.scenes.home.background.id', 'harbor')
                ->assertJsonPath('data.visuals.scenes.yunagi-harbor-intro.background.id', 'harbor')
                ->assertJsonPath('data.visuals.scenes.yunagi-harbor-intro.actors', []);
            $harborOption = collect($selected->json('data.visuals.home_backgrounds'))->firstWhere('key', $harbor['key']);
            $this->assertSame(app(UndergroundAlphaV1PlayerCatalog::class)->explorationHuntingGround('yunagi_harbor')['name'], $harborOption['name']);
            $this->postJson('/api/v1/me/underground/residence/purchase', [
                'request_id' => (string) Str::uuid(), 'item' => 'villa',
            ])->assertOk();
            $this->postJson('/api/v1/me/underground/residence/purchase', [
                'request_id' => (string) Str::uuid(), 'item' => 'mirror',
            ])->assertOk()->assertJsonPath('data.visuals.scenes.yunagi-harbor-intro.actors.0.asset.id', 'guide');
            $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
                'show_ai_generated_images' => false, 'own_secretary_fallback' => 'silhouette',
            ])->assertOk();
            $this->actingAs($user->fresh())->getJson('/api/v1/me/underground')->assertOk()
                ->assertJsonPath('data.visuals.scenes.home.background', null)
                ->assertJsonPath('data.visuals.home_background_key', $harbor['key'])
                ->assertJsonPath('data.visuals.scenes.yunagi-harbor-intro.background', null)
                ->assertJsonPath('data.visuals.scenes.yunagi-harbor-intro.actors.0.asset.id', 'guide')
                ->assertJsonMissing(['id' => 'harbor']);
            $this->assertSame($harbor['key'], $profile->fresh()->home_background_key);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
