<?php

namespace Tests\Underground\Feature;

use App\Models\UndergroundIntroRequest;
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
        UndergroundIntroRequest::query()->where('request_id', $admission['request_id'])->delete();
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
}
