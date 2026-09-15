<?php

namespace Tests\Support;

use App\Application\SecretaryService;
use App\Application\Underground\UndergroundIntroCatalog;
use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class UndergroundPlayerAccessTestCase extends TestCase
{
    protected function secretaryUser(string $name): array
    {
        $user = User::factory()->create();
        $secretary = app(SecretaryService::class)->ensureForUser($user);
        $secretary->update(['name' => $name, 'named_at' => Carbon::now()]);

        return [$user, $secretary];
    }

    protected function reachShopkeeperNaming(User $user): void
    {
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'initial_story_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/tutorial', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'escape_complete')->assertOk();
        $this->actingAs($user)->postJson('/api/v1/me/underground/entry', [
            'request_id' => (string) Str::uuid(),
        ])->assertOk();
        $this->advance($user, 'shopkeeper_encounter_complete')
            ->assertOk()->assertJsonPath('data.stage', 'shopkeeper_naming');
    }

    protected function advance(User $user, string $action): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/me/underground/story/advance', [
            'request_id' => (string) Str::uuid(),
            'action' => $action,
        ]);
    }

    protected function openEquipmentProfile(
        Secretary $secretary,
        int $shardBalance = 5_000,
        int $bankedShardBalance = 5_000,
    ): UndergroundProfile {
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
        $profile->update([
            'shard_balance' => $shardBalance,
            'banked_shard_balance' => $bankedShardBalance,
            'underground_contract_completed_at' => Carbon::now(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v2',
            'current_hp' => 492,
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $this->tutorialBattle($profile)->id,
        ]);

        return $profile->refresh();
    }

    protected function tutorialBattle(UndergroundProfile $profile): UndergroundBattle
    {
        return UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-underground-intro-alpha-v2',
            'activity_type' => UndergroundBattle::ACTIVITY_TUTORIAL,
            'activity_key' => 'first_descent_tutorial',
            'encounter_key' => 'tutorial_giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 5,
            'shard_delta' => 0,
            'combat_level_before' => 1,
            'combat_level_after' => 1,
            'combat_xp_before' => 0,
            'combat_xp_after' => 5,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => 1,
            'snapshot' => [],
            'started_at' => Carbon::now(),
            'finished_at' => Carbon::now(),
        ]);
    }

    /** @param array<string, mixed> $payload */
    protected function introFingerprint(string $operation, array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode([
            'story_identity' => app(UndergroundIntroCatalog::class)->identity(),
            'operation' => $operation,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    protected function trialBattleRow(
        UndergroundProfile $profile,
        string $trialKey,
        string $runKey,
        int $battleIndex,
        array $snapshot,
    ): array {
        $now = Carbon::now();

        return [
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('b', 64),
            'runtime_identity' => 'bounded-recollection-test',
            'activity_type' => UndergroundBattle::ACTIVITY_TRIAL,
            'activity_key' => $trialKey,
            'encounter_key' => "{$trialKey}_encounter",
            'trial_run_key' => $runKey,
            'trial_battle_index' => $battleIndex,
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 0,
            'shard_delta' => 0,
            'combat_level_before' => 1,
            'combat_level_after' => 1,
            'combat_xp_before' => 0,
            'combat_xp_after' => 0,
            'shard_balance_before' => 0,
            'shard_balance_after' => 0,
            'private_seed' => $battleIndex,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'started_at' => $now,
            'finished_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
