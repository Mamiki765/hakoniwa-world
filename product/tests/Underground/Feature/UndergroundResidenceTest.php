<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\UndergroundEquipmentDropService;
use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundTrialProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundResidenceTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    private ?string $guideAssetDirectory = null;

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
        $profile->update(['next_battle_at' => now()->addSeconds(10), 'next_otherworld_battle_at' => now()->addSeconds(600)]);
        $purchases = [];
        foreach ([10_000, 50_000, 100_000] as $price) {
            $request = ['request_id' => (string) Str::uuid(), 'price' => $price];
            $this->actingAs($user)->postJson('/api/v1/me/underground/shop/distorted-stone', $request)->assertOk();
            $purchases[] = $request;
        }
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', [
            'request_id' => (string) Str::uuid(), 'price' => 100_000,
        ])->assertConflict()->assertJsonPath('code', 'underground_distorted_stone_sold_out');
        $this->assertSame(3, $profile->fresh()->distorted_stone_balance);
        $this->assertSame(40_000, $profile->fresh()->shard_balance);
        Carbon::setTestNow('2026-09-23 00:00:00+09:00');
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', $purchases[2])->assertOk()
            ->assertJsonPath('data.distorted_stone_shop.purchased_today', 0)
            ->assertJsonPath('data.distorted_stone_shop.balance', 3);
        $this->postJson('/api/v1/me/underground/shop/distorted-stone', [
            'request_id' => (string) Str::uuid(), 'price' => 10_000,
        ])->assertOk()->assertJsonPath('data.distorted_stone_shop.balance', 4);
        $this->assertSame(30_000, $profile->fresh()->shard_balance);
        $this->assertEquals(Carbon::parse('2026-09-22 23:59:10+09:00'), $profile->fresh()->next_battle_at);
        $this->assertEquals(Carbon::parse('2026-09-23 00:09:00+09:00'), $profile->fresh()->next_otherworld_battle_at);
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
        $this->actingAs($user)->getJson('/api/v1/me/underground/journal')->assertOk()
            ->assertJsonPath('data.trophies.0.key', 'trial_01')
            ->assertJsonPath('data.trophies.0.achieved_at', $firstClear->copy()->utc()->toIso8601String())
            ->assertJsonMissing(['name' => 'デュラハンの兜'])
            ->assertJsonMissing(['name' => '魔剣のレプリカ']);
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
        $profile = $this->openEquipmentProfile($secretary);
        $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
            'show_ai_generated_images' => true, 'own_secretary_fallback' => 'silhouette',
        ])->assertOk();
        $directory = storage_path('framework/testing/scene-'.Str::uuid());
        File::ensureDirectoryExists($directory.'/background');
        copy(resource_path('images/underground-placeholder.jpg'), $directory.'/background/area.jpg');
        file_put_contents($directory.'/scene-assets.json', json_encode([
            'assets' => ['cave' => ['file' => 'background/area.jpg', 'creation_method' => 'ai_generated']],
            'scenes' => ['hunting_ground.black_crystal_cave' => ['background' => 'cave']],
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
            $this->actingAs($user)->patchJson('/api/v1/me/secretary/image-preferences', [
                'show_ai_generated_images' => false, 'own_secretary_fallback' => 'silhouette',
            ])->assertOk();
            $this->actingAs($user->fresh())->getJson('/api/v1/me/underground')->assertOk()
                ->assertJsonPath('data.visuals.scenes.home.background', null)
                ->assertJsonPath('data.visuals.home_background_key', 'hunting_ground.black_crystal_cave')
                ->assertJsonMissing(['id' => 'cave']);
            $this->assertSame('hunting_ground.black_crystal_cave', $profile->fresh()->home_background_key);
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
