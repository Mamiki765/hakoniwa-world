<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class AnnouncementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_latest_returns_five_and_index_uses_stable_ten_item_pages(): void
    {
        $time = Carbon::parse('2026-08-09 12:00:00+09:00');
        foreach (range(1, 12) as $number) {
            $this->announcement("Article {$number}", "Body {$number}", $time);
        }

        $latest = $this->getJson('/api/v1/public/announcements/latest')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.title', 'Article 12')
            ->assertJsonPath('data.4.title', 'Article 8');
        $this->assertStringNotContainsString('provider_user_id', $latest->getContent());

        $this->getJson('/api/v1/public/announcements')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.title', 'Article 12')
            ->assertJsonPath('data.9.title', 'Article 3')
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 12);
        $this->getJson('/api/v1/public/announcements?page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Article 2')
            ->assertJsonPath('data.1.title', 'Article 1');
    }

    public function test_public_article_preserves_plain_text_line_breaks_without_admin_data(): void
    {
        $announcement = Announcement::query()->create([
            'title' => 'Maintenance',
            'body' => "First line\nSecond line",
        ]);

        $response = $this->getJson("/api/v1/public/announcements/{$announcement->id}")
            ->assertOk()
            ->assertJsonPath('data.title', 'Maintenance')
            ->assertJsonPath('data.body', "First line\nSecond line")
            ->assertJsonStructure(['data' => ['id', 'title', 'body', 'created_at', 'updated_at']]);

        $this->assertStringNotContainsString('discord', $response->getContent());
        $this->assertStringNotContainsString('can_manage', $response->getContent());
    }

    public function test_exact_twenty_articles_have_a_full_second_and_final_page(): void
    {
        $time = Carbon::parse('2026-08-09 12:00:00+09:00');
        foreach (range(1, 20) as $number) {
            $this->announcement("Article {$number}", "Body {$number}", $time);
        }

        $this->getJson('/api/v1/public/announcements?page=2')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 20);
    }

    public function test_configured_discord_identity_can_create_update_and_soft_delete(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'stable-admin-id']);
        $admin = $this->identityUser('discord', 'stable-admin-id', 'Operator');

        $created = $this->actingAs($admin)->postJson('/api/v1/admin/announcements', [
            'title' => 'Release',
            'body' => "Version 1.1.0\nReleased.",
        ])->assertCreated()->assertJsonPath('data.title', 'Release')->json('data');

        $this->actingAs($admin)->patchJson("/api/v1/admin/announcements/{$created['id']}", [
            'title' => 'Release updated',
            'body' => "Version 1.1.0\nUpdated.",
        ])->assertOk()->assertJsonPath('data.title', 'Release updated');

        $this->actingAs($admin)->deleteJson("/api/v1/admin/announcements/{$created['id']}")
            ->assertNoContent();
        $this->getJson("/api/v1/public/announcements/{$created['id']}")->assertNotFound();
        $this->assertSoftDeleted('announcements', ['id' => $created['id']]);
    }

    public function test_admin_routes_fail_closed_for_guest_non_admin_and_unset_config(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'stable-admin-id']);
        $payload = ['title' => 'No', 'body' => 'Not authorized'];
        $this->postJson('/api/v1/admin/announcements', $payload)->assertForbidden();

        $sameName = $this->identityUser('discord', 'different-id', 'Operator');
        $this->actingAs($sameName)->postJson('/api/v1/admin/announcements', $payload)->assertForbidden();

        $sameIdDifferentProvider = $this->identityUser('google', 'stable-admin-id', 'Operator');
        $this->actingAs($sameIdDifferentProvider)->postJson('/api/v1/admin/announcements', $payload)
            ->assertForbidden();

        config(['hakoniwa.admin.discord_user_id' => null]);
        $configuredUser = $this->identityUser('discord', 'stable-admin-id', 'Operator');
        $this->actingAs($configuredUser)->postJson('/api/v1/admin/announcements', $payload)->assertForbidden();
    }

    public function test_admin_api_rejects_html_and_me_exposes_only_capability(): void
    {
        config(['hakoniwa.admin.discord_user_id' => 'stable-admin-id']);
        $admin = $this->identityUser('discord', 'stable-admin-id', 'Operator');

        $this->actingAs($admin)->postJson('/api/v1/admin/announcements', [
            'title' => '<b>Unsafe</b>',
            'body' => '<script>alert(1)</script>',
        ])->assertUnprocessable()->assertJsonValidationErrors(['title', 'body']);

        $response = $this->actingAs($admin)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.can_manage_announcements', true);
        $this->assertStringNotContainsString('stable-admin-id', $response->getContent());
        $this->assertStringNotContainsString('provider_user_id', $response->getContent());

        $nonAdmin = User::factory()->create();
        $this->actingAs($nonAdmin)->getJson('/api/v1/me')
            ->assertOk()->assertJsonPath('data.can_manage_announcements', false);
    }

    private function identityUser(string $provider, string $providerUserId, string $displayName): User
    {
        $user = User::factory()->create(['display_name' => $displayName]);
        AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'display_name' => $displayName,
        ]);

        return $user;
    }

    private function announcement(string $title, string $body, Carbon $createdAt): Announcement
    {
        $announcement = Announcement::query()->create(compact('title', 'body'));
        $announcement->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $announcement;
    }
}
