<?php

namespace Tests\Shared\Feature;

use App\Application\Underground\AtomicUndergroundExplorationCombat;
use App\Application\Underground\UndergroundIntroService;
use App\Application\Underground\UndergroundProfileService;
use App\Application\Underground\UndergroundRuntimeException;
use App\Application\Underground\UndergroundRuntimeService;
use App\Domain\Underground\Combat\AlphaV1BuildCatalog;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\BuildCombatResult;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class UndergroundSkillRebuildTest extends TestCase
{
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_pending_rebuild_preserves_progress_and_same_request_result_until_loadout_is_saved(): void
    {
        [$user, $secretary] = $this->secretaryUser();
        $profile = $this->unlockExploration($secretary);
        $this->app->instance(AtomicUndergroundExplorationCombat::class, new SkillRebuildCombat);
        $runtime = app(UndergroundRuntimeService::class);
        $run = $runtime->startTrial($user, 'trial_01');
        $requestId = (string) Str::uuid();
        $battle = $runtime->fightTrial($user, $run->run_key, $requestId)['battle'];
        // This state remains reachable from a baseline database whose owner has
        // not yet saved a loadout after a prior refund. Do not replay retired DDL.
        $profile->refresh()->update([
            'skill_tree_identity' => config('underground-alpha-v1.skill_tree_identity'),
            'skill_points_total' => 60,
            'skill_points_unspent' => 60,
            'skill_rebuild_required' => true,
            'custom_ai_rules' => null,
            'rental_party' => [],
        ]);
        $progressBefore = $profile->only([
            'combat_xp', 'shard_balance', 'banked_shard_balance',
            'unlocked_area_layers', 'growth_path_selected_at',
        ]);
        $this->assertEquals($battle->snapshot, $runtime->fightTrial($user, $run->run_key, $requestId)['battle']->snapshot);
        $this->assertRuntimeError('underground_skill_rebuild_required', fn () => $runtime->fightTrial($user, $run->run_key, (string) Str::uuid()));
        $intro = app(UndergroundIntroService::class);
        $intro->acquireSkillNode($user, (string) Str::uuid(), 'miracle_holy_bolt');
        $intro->acquireSkillNode($user, (string) Str::uuid(), 'miracle_mending_prayer');
        $this->assertTrue($profile->refresh()->skill_rebuild_required);
        $intro->updateActiveLoadout($user, (string) Str::uuid(), ['holy_bolt', 'mending_prayer', null, null, null]);
        $this->assertFalse($profile->refresh()->skill_rebuild_required);
        $this->assertSame(48, $profile->skill_points_unspent);
        $this->assertSame(2, $run->refresh()->next_battle_index);
        $this->assertEquals($progressBefore, $profile->refresh()->only(array_keys($progressBefore)));
        $this->assertSame(60, $profile->skill_points_total);
    }

    /** @return array{User, Secretary} */
    private function secretaryUser(): array
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create([
            'user_id' => $user->id,
            'name' => 'Runtime secretary',
            'named_at' => Carbon::now(),
        ]);

        return [$user, $secretary];
    }

    private function unlockExploration(Secretary $secretary): UndergroundProfile
    {
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary)->refresh();
        $profile->update([
            'underground_contract_completed_at' => Carbon::now()->subMinute(),
            'growth_path_key' => 'martial_red',
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
            'growth_path_selected_at' => Carbon::now(),
            'skill_points_total' => 20,
            'skill_points_unspent' => 20,
            'skill_tree_identity' => config('underground-alpha-v1.skill_tree_identity'),
            'unspent_stp' => ($profile->combat_level - 1) * 5,
        ]);
        $tutorial = UndergroundBattle::query()->create([
            'underground_profile_id' => $profile->id,
            'request_id' => (string) Str::uuid(),
            'request_fingerprint' => str_repeat('a', 64),
            'runtime_identity' => 'secretary-underground-intro-alpha-v2',
            'activity_type' => UndergroundBattle::ACTIVITY_TUTORIAL,
            'activity_key' => 'tutorial',
            'encounter_key' => 'giant_rat',
            'result' => UndergroundBattle::RESULT_VICTORY,
            'rounds' => 1,
            'damage_dealt' => 1,
            'damage_received' => 0,
            'healing_done' => 0,
            'xp_awarded' => 0,
            'shard_delta' => 0,
            'combat_level_before' => $profile->combat_level,
            'combat_level_after' => $profile->combat_level,
            'combat_xp_before' => $profile->combat_xp,
            'combat_xp_after' => $profile->combat_xp,
            'shard_balance_before' => $profile->shard_balance,
            'shard_balance_after' => $profile->shard_balance,
            'private_seed' => 1,
            'snapshot' => [],
            'started_at' => Carbon::now()->subHour(),
            'finished_at' => Carbon::now()->subHour(),
        ]);
        UndergroundIntroProgress::query()->create([
            'underground_profile_id' => $profile->id,
            'stage' => 'underground_open',
            'shopkeeper_name' => '案内係',
            'special_loss_required' => false,
            'branch_identity' => 'normal',
            'tutorial_battle_id' => $tutorial->id,
        ]);

        return $profile->refresh();
    }

    /** @param callable(): mixed $operation */
    private function assertRuntimeError(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected Underground runtime error [{$code}].");
        } catch (UndergroundRuntimeException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }
}

final class SkillRebuildCombat implements AtomicUndergroundExplorationCombat
{
    public function fight(
        AlphaV1BuildCatalog $catalog,
        array $playerSnapshot,
        string $enemyKey,
        int $seed,
        int $maxRounds,
        int $naturalRecovery,
    ): BuildCombatResult {
        $normalMaxHp = max(100, (int) $playerSnapshot['current_hp']);

        return new BuildCombatResult(
            rulesIdentity: AlphaV1CombatRules::IDENTITY,
            generatorIdentity: AlphaV1CombatRules::GENERATOR_IDENTITY,
            seed: $seed,
            buildKey: 'secretary_runtime',
            enemyKey: $enemyKey,
            tierKey: 'runtime',
            winner: 'player',
            rounds: 3,
            playerRemainingHp: 100,
            enemyRemainingHp: 0,
            damageDealt: 7,
            damageReceived: 3,
            effectiveHealing: 0,
            damagePrevented: 0,
            mpSpent: 0,
            mpNaturalRecovery: $naturalRecovery,
            mpSkillRecovery: 0,
            mpOverflow: 0,
            mpExhaustionRound: null,
            skillUnavailableDueToMp: 0,
            emergencyHealOpportunities: 0,
            emergencyHealAvailable: 0,
            emergencyHealBlockedByMp: 0,
            crystalCycleRecovery: 0,
            finalMp: AlphaV1CombatRules::MAX_MP,
            actionUsage: ['normal_attack' => 1],
            statusUptime: [],
            finalRoleStacks: ['fighting_spirit' => 0, 'grace' => 0],
            mpHistory: [],
            abnormalState: [],
            actionLog: [[
                'round' => 3,
                'kind' => 'effect',
                'side' => 'player',
                'target_side' => 'enemy',
                'action' => 'normal_attack',
                'amount' => 7,
                'effect_type' => 'damage',
            ]],
            generatedEquipment: [$playerSnapshot['equipment']],
            awakening: [
                'identity' => UndergroundAwakening::IDENTITY,
                'unlocked' => false,
                'gauge_before' => 0,
                'gauge_after' => 0,
                'gauge_gained' => 0,
                'triggered' => false,
                'normal_max_hp' => $normalMaxHp,
                'final_max_hp' => $normalMaxHp,
                'normal_stats' => [],
                'final_stats' => [],
                'technique' => null,
            ],
        );
    }
}
