<?php

namespace Tests\Feature;

use App\Application\AuthIdentityService;
use App\Application\ExternalIdentityData;
use App\Models\AuthIdentity;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AuthIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_creates_user_prevents_duplicates_and_supports_two_providers(): void
    {
        $service = app(AuthIdentityService::class);
        $first = $service->authenticate('discord', new ExternalIdentityData('discord-1', 'Alice'));
        $again = $service->authenticate('discord', new ExternalIdentityData('discord-1', 'Alice changed'));
        $linked = $service->authenticate('google', new ExternalIdentityData('google-1', 'Alice G'), $first);

        $this->assertSame($first->id, $again->id);
        $this->assertSame($first->id, $linked->id);
        $this->assertSame(1, User::query()->count());
        $this->assertSame(2, AuthIdentity::query()->count());
    }

    public function test_external_identity_cannot_be_linked_to_another_user(): void
    {
        $service = app(AuthIdentityService::class);
        $service->authenticate('discord', new ExternalIdentityData('shared-id', 'One'));

        $this->expectException(DomainException::class);
        $service->authenticate('discord', new ExternalIdentityData('shared-id', 'Two'), User::factory()->create());
    }

    public function test_oauth_redirect_uses_state_and_minimum_discord_scope(): void
    {
        config([
            'services.discord.client_id' => 'public-client-id',
            'services.discord.client_secret' => 'test-only-secret',
            'services.discord.redirect' => 'http://localhost/auth/discord/callback',
        ]);

        $response = $this->get('/auth/discord/redirect')->assertRedirect();
        $this->assertStringContainsString('scope=identify', $response->headers->get('Location'));
        $this->assertStringNotContainsString('email', $response->headers->get('Location'));
        $response->assertSessionHas('state');
    }

    public function test_mocked_oauth_callback_creates_internal_user_without_storing_token_or_email(): void
    {
        config([
            'services.discord.client_id' => 'public-client-id',
            'services.discord.client_secret' => 'test-only-secret',
        ]);
        $external = (new SocialiteUser)->map([
            'id' => 'discord-mock-1', 'name' => 'Mock Player', 'email' => 'must-not-persist@example.test',
        ])->setToken('must-not-persist-token');
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('setScopes')->once()->with(['identify'])->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($external);
        Socialite::shouldReceive('driver')->once()->with('discord')->andReturn($provider);

        $this->withSession(['oauth_intent' => 'login'])->get('/auth/discord/callback')->assertRedirect('/?oauth=success');

        $this->assertDatabaseHas('users', ['display_name' => 'Mock Player']);
        $this->assertDatabaseHas('auth_identities', ['provider' => 'discord', 'provider_user_id' => 'discord-mock-1']);
        $this->assertDatabaseMissing('auth_identities', ['display_name' => 'must-not-persist@example.test']);
        $this->assertStringNotContainsString('must-not-persist-token', json_encode(AuthIdentity::query()->firstOrFail()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_link_callback_does_not_fall_back_to_login_after_session_expiry(): void
    {
        config([
            'services.google.client_id' => 'public-client-id',
            'services.google.client_secret' => 'test-only-secret',
        ]);

        $this->withSession(['oauth_intent' => 'link'])
            ->get('/auth/google/callback')
            ->assertRedirect('/?oauth=link-session-expired');

        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, AuthIdentity::query()->count());
    }

    public function test_provider_failure_shows_temporary_outage_and_retry_guidance(): void
    {
        config([
            'services.discord.client_id' => 'public-client-id',
            'services.discord.client_secret' => 'test-only-secret',
        ]);
        $provider = Mockery::mock(AbstractProvider::class);
        $provider->shouldReceive('setScopes')->once()->with(['identify'])->andReturnSelf();
        $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('provider unavailable'));
        Socialite::shouldReceive('driver')->once()->with('discord')->andReturn($provider);

        $this->withSession(['oauth_intent' => 'login'])
            ->get('/auth/discord/callback')
            ->assertRedirect('/?oauth=failed')
            ->assertSessionHas('oauth_error');

        $this->get('/')
            ->assertOk()
            ->assertSee('一時的な障害')
            ->assertSee('再試行')
            ->assertSee('事前に別の認証サービスを連携済み');
    }
}
