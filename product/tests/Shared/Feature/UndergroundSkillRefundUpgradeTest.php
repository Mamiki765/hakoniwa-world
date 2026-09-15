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
use App\Models\UndergroundSkillAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\UsesIndividualTestWorld;
use Tests\TestCase;

final class UndergroundSkillRefundUpgradeTest extends TestCase
{
    use RefreshDatabase;
    use UsesIndividualTestWorld;

    public function test_skill_refund_preserves_earned_progress_and_historical_retry_until_loadout_is_saved(): void
    {
        [$user, $secretary] = $this->secretaryUser();
        $profile = $this->unlockExploration($secretary);
        $this->app->instance(AtomicUndergroundExplorationCombat::class, new RefundMigrationCombat);
        $runtime = app(UndergroundRuntimeService::class);
        $run = $runtime->startTrial($user, 'trial_01');
        $requestId = (string) Str::uuid();
        $battle = $runtime->fightTrial($user, $run->run_key, $requestId)['battle'];
        $profile->refresh()->update([
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            'skill_points_total' => 60, 'skill_points_unspent' => 48,
            'custom_ai_rules' => [['action' => 'skill:radiant_judgment', 'conditions' => []]],
        ]);
        UndergroundSkillAllocation::query()->create([
            'underground_profile_id' => $profile->id, 'tree_key' => 'martial',
            'node_key' => 'martial_precision_cut', 'rank' => 1, 'active_slot' => 1,
        ]);
        $unchanged = $profile->getAttributes();
        foreach (['skill_tree_identity', 'skill_points_unspent', 'custom_ai_rules', 'skill_rebuild_required', 'rental_party'] as $key) {
            unset($unchanged[$key]);
        }
        DB::statement('ALTER TABLE underground_profiles DROP COLUMN skill_rebuild_required, DROP COLUMN rental_party');
        DB::statement('ALTER TABLE announcements DROP COLUMN body_format');
        $announcementId = DB::table('announcements')->insertGetId([
            'title' => '旧記事', 'body' => "**装飾ではない文章**\n- 以前の告知",
            'created_at' => '2026-09-01 12:34:56', 'updated_at' => '2026-09-01 12:34:56',
        ]);
        $announcementBefore = (array) DB::table('announcements')->where('id', $announcementId)->sole();
        $migration = require database_path('migrations/2026_09_13_000000_rebuild_underground_skills_and_store_rental_party.php');
        $migration->up();
        $announcementAfter = (array) DB::table('announcements')->where('id', $announcementId)->sole();
        $this->assertSame('plain_text', $announcementAfter['body_format']);
        $this->assertSame($announcementBefore, array_intersect_key($announcementAfter, $announcementBefore));
        $profile->refresh();
        $this->assertSame($unchanged, array_intersect_key($profile->getAttributes(), $unchanged));
        $this->assertSame([60, 60, true], [$profile->skill_points_total, $profile->skill_points_unspent, $profile->skill_rebuild_required]);
        $this->assertNull($profile->custom_ai_rules);
        $this->assertSame(0, $profile->skillAllocations()->count());
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

final class RefundMigrationCombat implements AtomicUndergroundExplorationCombat
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
