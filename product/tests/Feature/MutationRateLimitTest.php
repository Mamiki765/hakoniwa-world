<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class MutationRateLimitTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    #[DataProvider('mutations')]
    public function test_browsing_does_not_consume_mutation_budgets_and_each_budget_still_limits_requests(string $url): void
    {
        $this->freezeTime();
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $this->actingAs($user);
        $payload = [];
        if ($url === '/api/v1/me/monument-design') {
            $payload = ['name' => '閲覧後の記念碑'];
        } elseif ($url === '/api/v1/me/secretary/tickets/draw') {
            app(NationCreationService::class)->create($user, $world, '抽選確認島', '抽選確認島主');
            $secretary = $user->secretary()->sole();
            $ticket = $secretary->itemInstances()->create([
                'item_key' => SecretaryItemCatalog::WAKUWAKU_TICKET,
                'level' => 1,
                'equipped_slot' => null,
                'grant_key' => 'test:rate-limit-ticket',
                'obtained_at' => now(),
            ]);
            $payload = ['ticket_item_id' => $ticket->id, 'request_key' => (string) Str::uuid()];
        }

        // Use real HTTP middleware and its configured budget, without seeding limiter keys.
        $publicResponse = $this->getJson('/api/v1/application-version')->assertOk();
        $remainingReads = $publicResponse->headers->get('X-RateLimit-Remaining');
        $this->assertNotNull($remainingReads);
        for ($read = 0; $read < (int) $remainingReads; $read++) {
            $this->getJson('/api/v1/application-version')->assertOk();
        }

        $mutationResponse = $this->postJson($url, $payload)->assertOk();
        $remainingMutations = $mutationResponse->headers->get('X-RateLimit-Remaining');
        $this->assertNotNull($remainingMutations);
        for ($attempt = 0; $attempt < (int) $remainingMutations; $attempt++) {
            $this->postJson($url, $payload)->assertOk();
        }
        $this->postJson($url, $payload)->assertTooManyRequests();

        if ($url === '/api/v1/me/daily-login') {
            // Daily reward mutations retain one shared budget.
            $this->postJson('/api/v1/me/daily-quests/development-opened')->assertTooManyRequests();
        }
        $this->getJson('/api/v1/application-version')->assertTooManyRequests();
    }

    /** @return iterable<string, array{string}> */
    public static function mutations(): iterable
    {
        yield 'daily login' => ['/api/v1/me/daily-login'];
        yield 'development quest' => ['/api/v1/me/daily-quests/development-opened'];
        yield 'monument design' => ['/api/v1/me/monument-design'];
        yield 'ticket gacha' => ['/api/v1/me/secretary/tickets/draw'];
    }
}
