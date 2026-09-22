<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryLendingService;
use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundReceiptPurgeService;
use App\Application\Underground\UndergroundReceiptRollupService;
use App\Application\Underground\UndergroundStarterEquipmentService;
use App\Models\GuideConversationTopic;
use App\Models\SecretaryImage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\UndergroundPlayerAccessTestCase;

final class UndergroundRequestAdmissionTest extends UndergroundPlayerAccessTestCase
{
    use RefreshDatabase;

    protected bool $withUndergroundAdmission = false;

    public function test_server_admission_preserves_retry_and_refuses_expired_deleted_or_legacy_requests(): void
    {
        [$user] = $this->secretaryUser('Admission secretary');
        $profile = $this->openEquipmentProfile($user->secretary, 5_000, 0);
        $this->actingAs($user);
        $path = '/api/v1/me/underground/bank/transfer';
        $this->postJson($path, ['request_id' => (string) Str::uuid(), 'action' => 'deposit', 'amount' => 1000])
            ->assertConflict()->assertJsonPath('code', 'underground_request_reload_required');
        $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path, 'request_id' => (string) Str::uuid()])
            ->assertUnprocessable();
        $admission = $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path])->assertOk()->json('data');
        $payload = ['request_id' => $admission['request_id'], 'action' => 'deposit', 'amount' => 1000];
        $headers = ['X-Underground-Receipt' => $admission['token']];
        $this->postJson($path, $payload, $headers)->assertOk();
        $this->postJson($path, $payload, $headers)->assertOk();
        $this->assertSame(4000, $profile->fresh()->shard_balance);
        $this->assertSame(1000, $profile->fresh()->banked_shard_balance);
        $this->postJson($path, [...$payload, 'amount' => 2000], $headers)->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->postJson('/api/v1/me/underground/inn/rest', ['request_id' => $admission['request_id']], $headers)
            ->assertConflict()->assertJsonPath('code', 'underground_request_reload_required');
        [$other] = $this->secretaryUser('Other owner');
        $this->openEquipmentProfile($other->secretary);
        $this->actingAs($other)->postJson($path, $payload, $headers)->assertConflict();
        $this->actingAs($user);
        $this->travel(24)->hours();
        $this->postJson($path, $payload, $headers)->assertOk(); // Stored result only.
        $this->travel(31)->days();
        app(UndergroundReceiptRollupService::class)->aggregate($profile->id, 'intro_request', now()->subDays(30), 500, true);
        $purged = app(UndergroundReceiptPurgeService::class)->purge($profile->id, 'intro_request', now()->subDays(30), 500, true);
        $this->assertSame(1, $purged['candidates']);
        $this->postJson($path, $payload, $headers)->assertConflict()->assertJsonPath('code', 'underground_request_expired');
        $this->assertSame(4000, $profile->fresh()->shard_balance);
        $this->assertSame(1000, $profile->fresh()->banked_shard_balance);
    }

    public function test_deadline_is_rechecked_after_the_profile_lock_before_assets_change(): void
    {
        [$user] = $this->secretaryUser('Waiting request');
        $profile = $this->openEquipmentProfile($user->secretary, 5_000, 0);
        $this->actingAs($user);
        $path = '/api/v1/me/underground/bank/transfer';
        $admission = $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path])->assertOk()->json('data');
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $advanced = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$advanced): void {
            if (! $advanced && str_contains($query->sql, '"underground_profiles"') && str_contains($query->sql, 'for update')) {
                $advanced = true;
                $this->travel(24)->hours();
            }
        });
        try {
            $this->postJson($path, ['request_id' => $admission['request_id'], 'action' => 'deposit', 'amount' => 1000],
                ['X-Underground-Receipt' => $admission['token']])
                ->assertConflict()->assertJsonPath('code', 'underground_request_expired');
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertTrue($advanced);
        $this->assertSame(5000, $profile->fresh()->shard_balance);
        $this->assertSame(0, $profile->fresh()->banked_shard_balance);
        $this->assertDatabaseMissing('underground_intro_requests', ['request_id' => $admission['request_id']]);
    }

    public function test_guide_reply_replays_its_saved_result_and_cannot_settle_again_after_purge(): void
    {
        [$user, $secretary] = $this->secretaryUser('Guide retry');
        $profile = $this->openEquipmentProfile($secretary);
        $topic = GuideConversationTopic::query()->create([
            'initial_line' => 'A topic', 'choice_1' => 'Reply', 'reply_1' => 'Original response',
            'unlock_key' => 'always', 'enabled' => true,
            'created_by_user_id' => $user->id, 'updated_by_user_id' => $user->id,
        ]);
        $this->actingAs($user);
        $path = '/api/v1/me/underground/guide-conversation/reply';
        $admission = $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path])->assertOk()->json('data');
        $payload = ['request_id' => $admission['request_id'], 'topic_id' => $topic->id, 'position' => 1];
        $headers = ['X-Underground-Receipt' => $admission['token']];
        $this->postJson($path, $payload, $headers)->assertOk()->assertJsonPath('data.reply_line', 'Original response');
        $topic->update(['reply_1' => 'Edited response', 'enabled' => false]);
        $this->travel(24)->hours();
        $this->postJson($path, $payload, $headers)->assertOk()->assertJsonPath('data.reply_line', 'Original response');
        $this->postJson($path, [...$payload, 'position' => 2], $headers)->assertConflict()->assertJsonPath('code', 'underground_request_conflict');
        $this->assertDatabaseHas('secretary_guide_conversation_totals', ['secretary_id' => $secretary->id, 'normal_replies' => 1]);
        $this->travel(31)->days();
        app(UndergroundReceiptRollupService::class)->aggregate($profile->id, 'intro_request', now()->subDays(30), 500, true);
        $purged = app(UndergroundReceiptPurgeService::class)->purge($profile->id, 'intro_request', now()->subDays(30), 500, true);
        $this->assertSame(1, $purged['candidates']);
        $this->postJson($path, $payload, $headers)->assertConflict()->assertJsonPath('code', 'underground_request_expired');
        $this->assertDatabaseHas('secretary_guide_conversation_totals', ['secretary_id' => $secretary->id, 'normal_replies' => 1]);
    }

    public function test_expiry_while_waiting_for_a_borrowed_profile_does_not_commit_an_image_lease(): void
    {
        [$leader, $leaderSecretary] = $this->secretaryUser('Waiting leader');
        $this->openEquipmentProfile($leaderSecretary);
        [$owner, $borrowedSecretary] = $this->secretaryUser('Borrowed secretary');
        $owner->forceFill(['visitor_code' => 'ADMLEND1'])->save();
        $borrowedProfile = $this->openEquipmentProfile($borrowedSecretary);
        app(UndergroundStarterEquipmentService::class)->reconcile($borrowedProfile);
        app(SecretaryLendingService::class)->update($owner, true);
        $imagePath = str_repeat('a', 64).'.png';
        SecretaryImage::query()->create([
            'secretary_id' => $borrowedSecretary->id, 'slot' => 'full_body', 'path' => $imagePath,
            'mime_type' => 'image/png', 'creation_method' => 'self_made',
        ]);
        app(UndergroundIntroService::class)->updateRentalParty($leader, (string) Str::uuid(), [$borrowedSecretary->id]);
        $this->actingAs($leader);
        $path = '/api/v1/me/underground/explore';
        $admission = $this->postJson('/api/v1/me/underground/requests', ['method' => 'POST', 'path' => $path])->assertOk()->json('data');
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $events = clone $original;
        $connection->setEventDispatcher($events);
        $advanced = false;
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$advanced): void {
            if (! $advanced && str_contains($query->sql, '"underground_profiles"')
                && str_contains($query->sql, '"secretary_id" in') && str_contains($query->sql, 'for update')) {
                $advanced = true;
                $this->travel(24)->hours();
            }
        });
        try {
            $this->postJson($path, ['request_id' => $admission['request_id'], 'borrowed_secretary_ids' => [$borrowedSecretary->id]],
                ['X-Underground-Receipt' => $admission['token']])
                ->assertConflict()->assertJsonPath('code', 'underground_request_expired');
        } finally {
            $connection->setEventDispatcher($original);
        }
        $this->assertTrue($advanced);
        $this->assertDatabaseMissing('underground_battle_image_references', ['path' => $imagePath]);
        $this->assertDatabaseMissing('underground_battles', ['request_id' => $admission['request_id']]);
    }
}
