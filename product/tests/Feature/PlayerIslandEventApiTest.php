<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\OceanWorldGenerator;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlayerIslandEventApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_receives_only_allowlisted_projected_events_for_their_nation_and_world(): void
    {
        [$world, $owner, $nation] = $this->nation('投影国');
        [, , $rival] = $this->nation('競合国', $world);
        $world->update(['current_turn' => 2]);
        $ownCell = MapCell::query()->where('owner_nation_id', $nation->id)->firstOrFail();
        $rivalCell = MapCell::query()->where('owner_nation_id', $rival->id)->firstOrFail();

        $terrainId = $this->audit('terrain.changed', $ownCell, [
            'world_id' => $world->id,
            'target_turn' => 2,
            'nation_id' => $nation->id,
            'command_key' => 'land_level',
            'from_terrain_key' => 'wasteland',
            'to_terrain_key' => 'plain',
            'draw' => 987654,
            'operator_metadata' => 'never expose',
        ]);
        $this->audit('forest.grown', $ownCell, [
            'world_id' => $world->id, 'target_turn' => 2, 'nation_id' => $nation->id,
        ]);
        $this->audit('terrain.changed', $rivalCell, [
            'world_id' => $world->id, 'target_turn' => 2, 'nation_id' => $rival->id,
            'command_key' => 'land_level', 'from_terrain_key' => 'wasteland', 'to_terrain_key' => 'plain',
        ]);
        $turnId = $this->audit('turn.completed', $world, [
            'world_id' => $world->id,
            'target_turn' => 2,
            'random_seed' => str_repeat('a', 64),
            'exception_class' => 'Internal\\TurnFailure',
            'operations_only' => ['lock_ms' => 123],
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.anchor_turn', 2)
            ->assertJsonPath('data.turn_range.start', 1)
            ->assertJsonPath('data.turn_range.end', 2)
            ->assertJsonPath('data.groups.0.target_turn', 2)
            ->assertJsonPath('data.groups.0.events.0.id', $turnId)
            ->assertJsonPath('data.groups.0.events.0.type', 'turn.completed')
            ->assertJsonPath('data.groups.0.events.1.id', $terrainId)
            ->assertJsonPath('data.groups.0.events.1.coordinate.x', $ownCell->x)
            ->assertJsonPath('data.groups.0.events.1.coordinate.y', $ownCell->y)
            ->assertHeader('Vary', 'Cookie');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $body = $response->getContent();
        foreach (['random_seed', 'exception_class', 'operations_only', 'operator_metadata', '987654', 'forest.grown'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringNotContainsString('metadata', $body);
    }

    public function test_event_endpoint_requires_authentication_and_membership_of_the_requested_nation(): void
    {
        [$world, $owner, $nation] = $this->nation('認可国');
        [, $otherUser, $otherNation] = $this->nation('他国', $world);

        $this->getJson("/api/v1/nations/{$nation->id}/events")->assertUnauthorized();
        $this->actingAs($otherUser)
            ->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertForbidden()
            ->assertJsonPath('message', '自国の出来事だけを取得できます。');
        $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $this->actingAs($owner)->getJson("/api/v1/nations/{$otherNation->id}/events")->assertForbidden();
    }

    public function test_pagination_uses_fixed_24_turn_ranges_and_never_returns_events_outside_the_selected_range(): void
    {
        [$world, $owner, $nation] = $this->nation('ページ国');
        $world->update(['current_turn' => 50]);
        foreach ([51, 50, 27, 26, 3, 2] as $turn) {
            $this->audit('turn.completed', $world, [
                'world_id' => $world->id,
                'target_turn' => $turn,
            ]);
        }

        $first = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk()
            ->assertJsonPath('data.turns_per_page', 24)
            ->assertJsonPath('data.turn_range.start', 27)
            ->assertJsonPath('data.turn_range.end', 50)
            ->assertJsonPath('data.has_newer_page', false)
            ->assertJsonPath('data.has_older_page', true);
        $this->assertSame([50, 27], $this->turns($first->json('data.groups')));

        $world->update(['current_turn' => 51]);
        $this->audit('turn.completed', $world, ['world_id' => $world->id, 'target_turn' => 51]);
        $second = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events?page=2&anchor_turn=50")
            ->assertOk()
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.anchor_turn', 50)
            ->assertJsonPath('data.turn_range.start', 3)
            ->assertJsonPath('data.turn_range.end', 26)
            ->assertJsonPath('data.has_newer_page', true)
            ->assertJsonPath('data.has_older_page', true);
        $this->assertSame([26, 3], $this->turns($second->json('data.groups')));
        $this->assertNotContains(51, $this->turns($second->json('data.groups')));
        $this->assertNotContains(50, $this->turns($second->json('data.groups')));

        $third = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events?page=3&anchor_turn=50")
            ->assertOk()
            ->assertJsonPath('data.turn_range.start', 1)
            ->assertJsonPath('data.turn_range.end', 2)
            ->assertJsonPath('data.has_older_page', false);
        $this->assertSame([2], $this->turns($third->json('data.groups')));
    }

    public function test_projection_suppresses_duplicate_and_high_volume_events(): void
    {
        [$world, $owner, $nation] = $this->nation('重複抑制国');
        $world->update(['current_turn' => 2]);
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)->firstOrFail();
        $base = [
            'world_id' => $world->id,
            'target_turn' => 2,
            'nation_id' => $nation->id,
        ];
        $terrainId = $this->audit('terrain.changed', $cell, [
            ...$base,
            'command_key' => 'land_clear',
            'from_terrain_key' => 'wasteland',
            'to_terrain_key' => 'plain',
            'x' => $cell->x,
            'y' => $cell->y,
        ]);
        $this->audit('command.success', $cell, [
            ...$base,
            'command_key' => 'land_clear',
            'after' => ['x' => $cell->x, 'y' => $cell->y],
        ]);
        $famineId = $this->audit('famine.applied', $cell, [
            ...$base, 'actual_loss' => 300, 'after' => 700,
        ]);
        $this->audit('population.decreased', $cell, [
            ...$base, 'reason' => 'famine', 'actual_loss' => 300, 'after' => 700,
        ]);
        $this->audit('command.buried_treasure', $nation, [
            ...$base, 'found' => false, 'reward_money' => 0, 'applied_money' => 0, 'overflow_money' => 0,
        ]);
        $treasureId = $this->audit('command.buried_treasure', $nation, [
            ...$base, 'found' => true, 'reward_money' => 500, 'applied_money' => 500, 'overflow_money' => 0,
        ]);
        $this->audit('resource.food_produced', $nation, $base);
        $this->audit('resource.food_consumed', $nation, $base);
        $this->audit('forest.grown', $cell, $base);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk();
        $events = collect($response->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        );

        $this->assertSame([$treasureId, $famineId, $terrainId], $events->pluck('id')->all());
        $this->assertSame(
            ['command.buried_treasure', 'famine.applied', 'terrain.changed'],
            $events->pluck('type')->all(),
        );
    }

    /** @return array{World, User, Nation} */
    private function nation(string $name, ?World $world = null): array
    {
        $world ??= app(OceanWorldGenerator::class)->initialize();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name);

        return [$world, $user, $nation];
    }

    /** @param array<string, mixed> $metadata */
    private function audit(string $eventType, Model $subject, array $metadata): int
    {
        return (int) DB::table('audit_events')->insertGetId([
            'actor_user_id' => null,
            'event_type' => $eventType,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $groups @return list<int> */
    private function turns(array $groups): array
    {
        return array_map(static fn (array $group): int => (int) $group['target_turn'], $groups);
    }
}
