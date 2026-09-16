<?php

namespace Tests\Underground\Feature;

use App\Models\SecretaryGuideConversationTotal;
use App\Models\UndergroundBattle;
use App\Models\UndergroundTrialProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundResidenceTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    private ?string $guideAssetDirectory = null;

    protected function tearDown(): void
    {
        if ($this->guideAssetDirectory !== null) {
            File::deleteDirectory($this->guideAssetDirectory);
        }
        parent::tearDown();
    }

    public function test_residence_purchase_and_event_retries_preserve_assets_and_unlock_only_the_owner_journal(): void
    {
        [$user, $secretary] = $this->secretaryUser('別荘の主');
        $profile = $this->openEquipmentProfile($secretary, 1_100_000);
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
        $post('events/advance', ['event' => 'exchange', 'page' => 2])->assertConflict();
        $post('events/advance', ['event' => 'exchange', 'page' => 1])->assertOk();
        $post('events/advance', ['event' => 'exchange', 'page' => 2])->assertOk();
        $requestId = (string) Str::uuid();
        $post('residence/purchase', ['item' => 'villa', 'request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_000_000);
        $post('residence/purchase', ['item' => 'villa', 'request_id' => $requestId])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_000_000);
        $post('residence/purchase', ['item' => 'villa'])
            ->assertOk()->assertJsonPath('data.shard_balance', 1_000_000);
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
