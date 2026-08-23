<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\TurnRunner;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\NationMembership;
use App\Models\NationResource;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class NationDormancyTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_manual_dormancy_is_owner_only_validated_and_uses_exact_one_and_seven_day_boundaries(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '手動休止島', '休止島主');
        $endpoint = "/api/v1/nations/{$nation->id}/dormancy";

        $this->postJson($endpoint, ['days' => 1])->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($endpoint, ['days' => 1])->assertForbidden();
        foreach ([0, 8, '1.5'] as $days) {
            $this->actingAs($owner)->postJson($endpoint, ['days' => $days])
                ->assertUnprocessable()->assertJsonValidationErrors('days');
        }

        NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))
            ->update(['amount' => 0]);
        $farmId = FacilityDefinition::query()->where('key', 'farm')->valueOrFail('id');
        MapCell::query()->where('owner_nation_id', $nation->id)
            ->where('facility_definition_id', $farmId)
            ->update(['facility_definition_id' => null, 'facility_scale' => null]);

        $response = $this->actingAs($owner)->postJson($endpoint, ['days' => 1])
            ->assertOk()
            ->assertJsonPath('data.state', 'dormant')
            ->assertJsonPath('data.state_label', '休眠')
            ->assertJsonPath('data.state_reason', 'manual')
            ->assertJsonPath('data.state_started_turn', 1)
            ->assertJsonPath('data.resume_at_turn', 14)
            ->assertJsonPath('data.manual_dormancy_days', 1)
            ->assertJsonPath('data.dormancy_remaining_turns', 12)
            ->assertJsonPath('data.dormancy_remaining_days', 1)
            ->assertJsonPath('data.can_request_dormancy', false)
            ->assertJsonPath('data.winter_theme_active', true);
        $this->assertSame(10_000, (int) $response->json('data.food_total_tons'));
        $this->assertSame(1, MapCell::query()->where('owner_nation_id', $nation->id)
            ->where('facility_definition_id', $farmId)->count());
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'nation.dormant',
            'actor_user_id' => $owner->id,
            'nation_id' => $nation->id,
            'visibility' => 'public',
        ]);
        $dormancyMessage = '主が帰ってくるまでの間、秘書が禁呪を解き放ちました。手動休止島に冬が訪れています……';
        $publicLog = $this->getJson("/api/v1/public/nations/{$nation->id}/events")->assertOk();
        $this->assertContains($dormancyMessage, $this->messages($publicLog->json('data.groups')));
        $this->assertStringNotContainsString('secretary_id', (string) $publicLog->getContent());
        $ownerLog = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $this->assertContains($dormancyMessage, $this->messages($ownerLog->json('data.groups')));
        $this->actingAs($owner)->postJson($endpoint, ['days' => 1])
            ->assertConflict()->assertJsonPath('code', 'nation_not_active');

        $sevenDayOwner = User::factory()->create();
        $sevenDayNation = app(NationCreationService::class)->create(
            $sevenDayOwner,
            $world,
            '七日休止島',
            '七日島主',
        );
        $this->actingAs($sevenDayOwner)
            ->postJson("/api/v1/nations/{$sevenDayNation->id}/dormancy", ['days' => 7])
            ->assertOk()
            ->assertJsonPath('data.resume_at_turn', 86)
            ->assertJsonPath('data.dormancy_remaining_turns', 84)
            ->assertJsonPath('data.dormancy_remaining_days', 7);
    }

    public function test_manual_dormancy_retains_queue_and_resumes_only_on_the_exact_due_turn(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '期限休止島', '期限島主');
        $this->actingAs($owner)->postJson("/api/v1/nations/{$nation->id}/dormancy", ['days' => 1])
            ->assertOk()->assertJsonPath('data.resume_at_turn', 14);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $owner,
            nation: $nation->fresh(),
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $moneyBefore = (int) $nation->fresh()->money;
        $idleBefore = (int) $nation->fresh()->idle_counter;
        $world->update(['current_turn' => 12]);

        $skipped = app(TurnRunner::class)->run($world->fresh());

        $this->assertSame(TurnRun::STATUS_COMPLETED, $skipped->status);
        $this->assertSame(13, $world->fresh()->current_turn);
        $this->assertSame('dormant', $nation->fresh()->state);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame('forest', $target->fresh()->terrain()->value('key'));
        $this->assertSame($moneyBefore + 10, (int) $nation->fresh()->money);
        $this->assertSame($idleBefore + 1, (int) $nation->fresh()->idle_counter);

        $resumed = app(TurnRunner::class)->run($world->fresh());

        $this->assertSame(TurnRun::STATUS_COMPLETED, $resumed->status);
        $this->assertSame(14, $world->fresh()->current_turn);
        $this->assertSame('active', $nation->fresh()->state);
        $this->assertNull($nation->fresh()->state_reason);
        $this->assertNull($nation->fresh()->resume_at_turn);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'nation.dormancy_resumed',
            'nation_id' => $nation->id,
            'message' => '期限休止島に春が訪れ、活動を再開しました。',
        ]);
        $resumeMessage = '期限休止島に春が訪れ、活動を再開しました。';
        $publicLog = $this->getJson("/api/v1/public/nations/{$nation->id}/events")->assertOk();
        $this->assertContains($resumeMessage, $this->messages($publicLog->json('data.groups')));
        $ownerLog = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $this->assertContains($resumeMessage, $this->messages($ownerLog->json('data.groups')));
    }

    public function test_recovery_exposes_exact_remaining_turns_and_exits_only_on_t_plus_85(): void
    {
        $world = $this->lightweightWorld();
        $activeOwner = User::factory()->create();
        $activeNation = app(NationCreationService::class)->create(
            $activeOwner,
            $world,
            '復帰活動島',
            '復帰活動島主',
        );
        $idleOwner = User::factory()->create();
        $idleNation = app(NationCreationService::class)->create(
            $idleOwner,
            $world,
            '復帰休眠島',
            '復帰休眠島主',
        );
        foreach ([$activeNation, $idleNation] as $nation) {
            $nation->update([
                'state' => 'recovery',
                'state_reason' => null,
                'state_started_turn' => 10,
                'resume_at_turn' => 95,
            ]);
        }
        $activeNation->update(['karma' => 4, 'idle_counter' => 0]);
        $idleNation->update(['idle_counter' => 360]);
        $world->update(['current_turn' => 10]);

        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonFragment([
                'id' => $activeNation->id,
                'state' => 'recovery',
                'state_label' => '休戦中：残り84ターン',
                'recovery_remaining_turns' => 84,
                'karma' => 4,
                'karma_badge' => 'KARMA:4',
            ]);
        $this->actingAs($activeOwner)->getJson("/api/v1/nations/{$activeNation->id}")
            ->assertOk()
            ->assertJsonPath('data.state_label', '休戦中：残り84ターン')
            ->assertJsonPath('data.recovery_remaining_turns', 84)
            ->assertJsonPath('data.karma', 4)
            ->assertJsonPath('data.karma_positive', true);

        $world->update(['current_turn' => 93]);
        $lastProtectedTurn = app(TurnRunner::class)->run($world->fresh());

        $this->assertSame(TurnRun::STATUS_COMPLETED, $lastProtectedTurn->status);
        $this->assertSame(94, (int) $world->fresh()->current_turn);
        $this->assertSame('recovery', $activeNation->fresh()->state);
        $this->assertSame('recovery', $idleNation->fresh()->state);
        $this->assertGreaterThanOrEqual(360, (int) $idleNation->fresh()->idle_counter);
        $this->assertDatabaseMissing('audit_events', [
            'event_type' => 'nation.recovery_ended',
            'turn' => 94,
        ]);

        $target = MapCell::query()->where('owner_nation_id', $activeNation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $activeOwner,
            nation: $activeNation->fresh(),
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];

        $firstUnprotectedTurn = app(TurnRunner::class)->run($world->fresh());

        $this->assertSame(TurnRun::STATUS_COMPLETED, $firstUnprotectedTurn->status);
        $this->assertSame(95, (int) $world->fresh()->current_turn);
        $this->assertSame('active', $activeNation->fresh()->state);
        $this->assertNull($activeNation->fresh()->state_reason);
        $this->assertNull($activeNation->fresh()->resume_at_turn);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('dormant', $idleNation->fresh()->state);
        $this->assertSame('idle', $idleNation->fresh()->state_reason);
        $this->assertNull($idleNation->fresh()->resume_at_turn);
        $this->assertSame(2, DB::table('audit_events')
            ->where('event_type', 'nation.recovery_ended')
            ->where('turn', 95)
            ->count());
    }

    public function test_turn_end_enters_dormancy_and_2160_heartbeat_reuses_canonical_abandonment(): void
    {
        $world = $this->lightweightWorld();
        $idleOwner = User::factory()->create();
        $idleNation = app(NationCreationService::class)->create($idleOwner, $world, '無活動島', '無活動島主');
        $idleNation->update(['idle_counter' => 359]);
        $abandonedOwner = User::factory()->create();
        $abandonedNation = app(NationCreationService::class)->create(
            $abandonedOwner,
            $world,
            '忘却島',
            '忘却島主',
        );
        $abandonedNation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'idle_counter' => 2159,
        ]);
        $secretaryId = Secretary::query()->where('user_id', $abandonedOwner->id)->valueOrFail('id');

        $run = app(TurnRunner::class)->run($world);

        $this->assertSame(TurnRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('dormant', $idleNation->fresh()->state);
        $this->assertSame('idle', $idleNation->fresh()->state_reason);
        $this->assertSame(360, (int) $idleNation->fresh()->idle_counter);
        $this->assertSame('abandoned', $abandonedNation->fresh()->state);
        $this->assertSame(0, (int) $abandonedNation->fresh()->idle_counter);
        $this->assertDatabaseMissing('nation_capitals', ['nation_id' => $abandonedNation->id]);
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $abandonedNation->id]);
        $this->assertDatabaseHas('secretaries', ['id' => $secretaryId, 'user_id' => $abandonedOwner->id]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'nation.abandoned',
            'nation_id' => $abandonedNation->id,
            'message' => '忘却島は放置され、忘れ去られる。',
            'visibility' => 'public',
            'turn' => 2,
        ]);
        $abandonmentEvent = DB::table('audit_events')
            ->where('event_type', 'nation.abandoned')
            ->where('nation_id', $abandonedNation->id)
            ->sole();
        $abandonmentMetadata = json_decode((string) $abandonmentEvent->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(2, $abandonmentMetadata['target_turn']);
        $this->assertSame(2, $abandonmentMetadata['current_turn']);
        $finalize = collect($run->phase_results)->firstWhere('phase', 'finalize_turn')['metrics'];
        $this->assertSame(1, $finalize['entered_dormant']);
        $this->assertSame(1, $finalize['abandoned']);
        $this->assertSame(0, NationMembership::query()->where('nation_id', $abandonedNation->id)->count());
    }

    /** @param list<array<string, mixed>> $groups @return list<string> */
    private function messages(array $groups): array
    {
        return collect($groups)->flatMap(
            static fn (array $group): array => $group['events'],
        )->pluck('message')->all();
    }
}
