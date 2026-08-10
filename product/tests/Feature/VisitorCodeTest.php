<?php

namespace Tests\Feature;

use App\Application\VisitorCodeAllocator;
use App\Application\VisitorCodeGenerator;
use App\Models\AuthIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisitorCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hmac_generator_is_stable_alphanumeric_and_domain_separated(): void
    {
        $generator = app(VisitorCodeGenerator::class);
        $discord = $generator->candidate('discord', '123456789012345678', 0);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{8}$/', $discord);
        $this->assertSame($discord, $generator->candidate('discord', '123456789012345678', 0));
        $this->assertNotSame($discord, $generator->candidate('google', '123456789012345678', 0));
        $this->assertNotSame($discord, $generator->candidate('discord', '123456789012345679', 0));
        $this->assertNotSame($discord, $generator->candidate('discord', '123456789012345678', 1));
        $this->assertNotSame(substr('123456789012345678', 0, 8), $discord);
    }

    public function test_discord_is_preferred_at_initial_allocation_and_google_is_fallback(): void
    {
        $allocator = app(VisitorCodeAllocator::class);
        $generator = app(VisitorCodeGenerator::class);
        $both = User::factory()->create();
        $this->identity($both, 'google', 'google-stable-id');
        $this->identity($both, 'discord', 'discord-stable-id');
        $googleOnly = User::factory()->create();
        $this->identity($googleOnly, 'google', 'google-only-id');

        $this->assertSame(
            $generator->candidate('discord', 'discord-stable-id', 0),
            $allocator->allocate($both),
        );
        $this->assertSame(
            $generator->candidate('google', 'google-only-id', 0),
            $allocator->allocate($googleOnly),
        );
        $this->assertNotSame($both->fresh()->visitor_code, $googleOnly->fresh()->visitor_code);
    }

    public function test_persisted_code_survives_discord_link_unlink_and_name_changes(): void
    {
        $user = User::factory()->create(['display_name' => 'first-name']);
        $google = $this->identity($user, 'google', 'google-persist-id');
        $allocator = app(VisitorCodeAllocator::class);
        $assigned = $allocator->allocate($user);

        $discord = $this->identity($user, 'discord', 'discord-later-id');
        $user->update(['display_name' => 'changed-name']);
        $google->update(['display_name' => 'changed-google-name']);
        $discord->delete();
        $google->delete();

        $this->assertSame($assigned, $allocator->allocate($user->fresh()));
        $this->assertSame($assigned, $user->fresh()->visitor_code);
    }

    public function test_collision_retries_with_counter_and_unique_constraint_is_authoritative(): void
    {
        $first = User::factory()->create(['visitor_code' => 'AAAAAAAA']);
        $second = User::factory()->create();
        $this->identity($second, 'google', 'collision-provider-id');
        $generator = new class extends VisitorCodeGenerator
        {
            public function candidate(string $provider, string $providerUserId, int $collisionCounter): string
            {
                return $collisionCounter === 0 ? 'AAAAAAAA' : 'BBBBBBBB';
            }
        };
        $allocator = new VisitorCodeAllocator($generator);

        $this->assertSame('BBBBBBBB', $allocator->allocate($second));
        $this->assertSame('AAAAAAAA', $first->fresh()->visitor_code);

        try {
            User::factory()->create(['visitor_code' => 'BBBBBBBB']);
            $this->fail('The visitor code unique constraint accepted a duplicate.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        }
    }

    public function test_same_user_allocation_is_idempotent(): void
    {
        $user = User::factory()->create();
        $this->identity($user, 'discord', 'stable-same-user');
        $allocator = app(VisitorCodeAllocator::class);

        $first = $allocator->allocate($user);
        $second = $allocator->allocate($user->fresh());

        $this->assertSame($first, $second);
        $this->assertSame(8, strlen($first));
    }

    private function identity(User $user, string $provider, string $providerUserId): AuthIdentity
    {
        return AuthIdentity::query()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'display_name' => 'private-provider-name',
        ]);
    }
}
