<?php

namespace Tests\Feature;

use App\Models\AuthIdentity;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MariaAchievementSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_receipt_retries_and_only_linked_pending_receipts_are_sent(): void
    {
        config(['services.maria_achievements.url' => 'https://maria.invalid/internal/hakoniwa/achievement',
            'services.maria_achievements.token' => 'test-only-secret']);
        $user = User::factory()->create();
        AuthIdentity::query()->create(['user_id' => $user->id, 'provider' => 'discord', 'provider_user_id' => '111111111111111111']);
        $receipt = UserAchievement::query()->create(['user_id' => $user->id, 'achievement_key' => 'island_secretary',
            'title_key' => 'island_secretary', 'acquired_at' => now()]);
        $unlinked = UserAchievement::query()->create(['user_id' => User::factory()->create()->id,
            'achievement_key' => 'island_secretary', 'title_key' => 'island_secretary', 'acquired_at' => now()]);
        Http::fakeSequence()->pushStatus(503)->push(['accepted' => true]);

        $this->artisan('hakoniwa:sync-maria-achievements')->assertFailed();
        $this->assertNull($receipt->fresh()->maria_sent_at);
        $this->artisan('hakoniwa:sync-maria-achievements')->assertSuccessful();
        $this->assertNotNull($receipt->fresh()->maria_sent_at);
        $this->assertNull($unlinked->fresh()->maria_sent_at);
        $this->artisan('hakoniwa:sync-maria-achievements')->assertSuccessful();
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer test-only-secret')
            && $request['discord_user_id'] === '111111111111111111'
            && $request['achievement_key'] === 'island_secretary');
    }
}
