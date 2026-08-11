<?php

namespace Tests\Feature;

use App\Application\AwardTurnFinalizer;
use App\Application\MonsterKillCycleService;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\TurnRunner;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\MonsterDefinition;
use App\Models\Nation;
use App\Models\NationAward;
use App\Models\NationMonsterCycleStat;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class AwardSystemTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_owner_approved_condition_series_grant_one_unowned_tier_per_series_and_turn(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, '条件賞国');

        for ($targetTurn = 2; $targetTurn <= 4; $targetTurn++) {
            $context = $this->context(
                $world,
                $targetTurn,
                [$nation],
                startPopulations: [$nation->id => 2_500_000],
                finalPopulations: [$nation->id => 2_000_000],
                refugees: [$nation->id => 80_000],
            );

            $metrics = app(AwardTurnFinalizer::class)->finalize($context);

            $this->assertSame(3, $metrics['condition_awards']);
            $retry = app(AwardTurnFinalizer::class)->finalize($context);
            $this->assertSame(0, $retry['condition_awards']);
            $this->assertSame(($targetTurn - 1) * 3, NationAward::query()->count());
        }

        $this->assertSame([
            'award.calamity', 'award.prosperity', 'award.peace',
            'award.calamity_great', 'award.prosperity_great', 'award.peace_great',
            'award.calamity_ultimate', 'award.prosperity_ultimate', 'award.peace_ultimate',
        ], NationAward::query()->orderBy('id')->pluck('award_key')->all());
        $this->assertSame([2, 2, 2, 3, 3, 3, 4, 4, 4],
            NationAward::query()->orderBy('id')->pluck('awarded_turn')->all());
        $this->assertSame(9, DB::table('audit_events')->where('event_type', 'award.granted')
            ->where('visibility', 'public')->count());
        $awardMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'award.granted')->orderBy('id')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($nation->id, $awardMetadata['nation_id']);
        $this->assertSame('条件賞国', $awardMetadata['nation_name']);
        $this->assertSame('award.calamity', $awardMetadata['award_key']);
        $this->assertSame('災難賞', $awardMetadata['award_name']);
        $awardMessages = collect(app(PlayerIslandEventService::class)->publicNationPage($nation, 1, 4)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertContains('条件賞国に災難賞が進呈されました。', $awardMessages->all());
        $this->assertContains('条件賞国に究極繁栄賞が進呈されました。', $awardMessages->all());

        $metrics = app(AwardTurnFinalizer::class)->finalize($this->context(
            $world,
            5,
            [$nation],
            startPopulations: [$nation->id => 2_500_000],
            finalPopulations: [$nation->id => 2_000_000],
            refugees: [$nation->id => 80_000],
        ));
        $this->assertSame(0, $metrics['condition_awards']);
        $this->assertSame(9, NationAward::query()->count());
    }

    public function test_condition_thresholds_use_final_population_net_loss_and_actual_received_refugees(): void
    {
        $world = $this->lightweightWorld();
        $series = [
            ['metric' => 'population', 'tiers' => [
                ['award.prosperity', 300_000],
                ['award.prosperity_great', 500_000],
                ['award.prosperity_ultimate', 1_000_000],
            ]],
            ['metric' => 'refugees', 'tiers' => [
                ['award.peace', 20_000],
                ['award.peace_great', 50_000],
                ['award.peace_ultimate', 80_000],
            ]],
            ['metric' => 'population_loss', 'tiers' => [
                ['award.calamity', 50_000],
                ['award.calamity_great', 100_000],
                ['award.calamity_ultimate', 200_000],
            ]],
        ];
        $targetTurn = 10;
        foreach ($series as $seriesIndex => $definition) {
            foreach ($definition['tiers'] as $tierIndex => [$awardKey, $threshold]) {
                $nation = Nation::query()->create([
                    'world_id' => $world->id,
                    'nation_number' => ($seriesIndex * 3) + $tierIndex + 1,
                    'registered_turn' => 1,
                    'name' => "閾値{$seriesIndex}-{$tierIndex}国",
                    'owner_name' => '閾値テスト所有者',
                    'profile_comment' => '',
                    'money' => 100,
                    'state' => 'active',
                    'idle_counter' => 100,
                ]);
                foreach (array_slice($definition['tiers'], 0, $tierIndex) as $ownedIndex => [$ownedKey]) {
                    NationAward::query()->create([
                        'world_id' => $world->id,
                        'nation_id' => $nation->id,
                        'award_key' => $ownedKey,
                        'awarded_turn' => $ownedIndex + 1,
                        'award_occurrence_key' => 'once',
                    ]);
                }
                $values = static function (int $value) use ($definition, $nation): array {
                    $start = 1_000;
                    $final = 1_000;
                    $refugees = 0;
                    if ($definition['metric'] === 'population') {
                        $start = $value;
                        $final = $value;
                    } elseif ($definition['metric'] === 'population_loss') {
                        $start += $value;
                    } else {
                        $refugees = $value;
                    }

                    return [
                        'start' => [$nation->id => $start],
                        'final' => [$nation->id => $final],
                        'refugees' => [$nation->id => $refugees],
                    ];
                };
                $below = $values($threshold - 1);
                $beforeCount = NationAward::query()->where('nation_id', $nation->id)->count();
                $metrics = app(AwardTurnFinalizer::class)->finalize($this->context(
                    $world,
                    $targetTurn++,
                    [$nation],
                    $below['start'],
                    $below['final'],
                    $below['refugees'],
                ));
                $this->assertSame(0, $metrics['condition_awards'], "{$awardKey} below threshold");
                $this->assertSame($beforeCount, NationAward::query()->where('nation_id', $nation->id)->count());

                $exact = $values($threshold);
                $exactContext = $this->context(
                    $world,
                    $targetTurn++,
                    [$nation],
                    $exact['start'],
                    $exact['final'],
                    $exact['refugees'],
                );
                $metrics = app(AwardTurnFinalizer::class)->finalize($exactContext);
                $this->assertSame(1, $metrics['condition_awards'], "{$awardKey} exact threshold");
                $this->assertSame($awardKey, NationAward::query()->where('nation_id', $nation->id)
                    ->latest('id')->value('award_key'));

                $retry = app(AwardTurnFinalizer::class)->finalize($exactContext);
                $this->assertSame(0, $retry['condition_awards'], "{$awardKey} retry");
                $this->assertSame($beforeCount + 1, NationAward::query()->where('nation_id', $nation->id)->count());
            }
        }
    }

    public function test_recurring_turn_and_monster_awards_handle_boundaries_ties_zero_and_retry(): void
    {
        $world = $this->lightweightWorld();
        $first = $this->createNation($world, '第一周期国');
        $second = $this->createNation($world, '第二周期国');

        $beforeBoundary = app(AwardTurnFinalizer::class)->finalize(
            $this->context($world, 99, [$first, $second]),
        );
        $this->assertSame(0, $beforeBoundary['turn_awards']);
        $this->assertSame(0, $beforeBoundary['monster_turn_awards']);

        foreach ([$first, $second] as $nation) {
            NationMonsterCycleStat::query()->create([
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                'cycle_start_turn' => 1,
                'cycle_end_turn' => 100,
                'kill_count' => 3,
                'version' => 1,
            ]);
        }
        $second->update(['state' => 'sunken_archived']);
        $turn100 = $this->context($world, 100, [$first, $second]);
        $metrics = app(AwardTurnFinalizer::class)->finalize($turn100);
        $this->assertSame(2, $metrics['turn_awards']);
        $this->assertSame(2, $metrics['monster_turn_awards']);
        $this->assertSame(2, $metrics['monster_cycle_rows_initialized']);
        $this->assertSame([0, 0], NationMonsterCycleStat::query()
            ->where('cycle_start_turn', 101)->orderBy('nation_id')->pluck('kill_count')->all());

        $retry = app(AwardTurnFinalizer::class)->finalize($turn100);
        $this->assertSame(0, $retry['turn_awards']);
        $this->assertSame(0, $retry['monster_turn_awards']);
        $this->assertSame(0, $retry['monster_cycle_rows_initialized']);
        $this->assertSame(4, DB::table('audit_events')->where('event_type', 'award.granted')
            ->where('turn', 100)->count());
        $this->assertEqualsCanonicalizing(
            ['award.turn', 'award.turn', 'award.monster_turn', 'award.monster_turn'],
            DB::table('audit_events')->where('event_type', 'award.granted')
                ->where('turn', 100)->get()->map(
                    fn (object $event): string => json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR)['award_key'],
                )->all(),
        );

        $turn101 = $this->context($world, 101, [$first, $second]);
        app(MonsterKillCycleService::class)->increment($turn101, $first);
        app(MonsterKillCycleService::class)->increment($turn101, $first);
        $turn200 = $this->context(
            $world,
            200,
            [$first, $second],
            finalPopulations: [$first->id => 2_000, $second->id => 1_000],
        );
        $metrics = app(AwardTurnFinalizer::class)->finalize($turn200);
        $this->assertSame(1, $metrics['turn_awards']);
        $this->assertSame(1, $metrics['monster_turn_awards']);
        $this->assertSame([100, 200], NationAward::query()
            ->where('nation_id', $first->id)->where('award_key', 'award.turn')
            ->orderBy('awarded_turn')->pluck('awarded_turn')->all());
        $this->assertSame([100, 200], NationAward::query()
            ->where('nation_id', $first->id)->where('award_key', 'award.monster_turn')
            ->orderBy('awarded_turn')->pluck('awarded_turn')->all());
        $this->assertSame([100], NationAward::query()
            ->where('nation_id', $second->id)->where('award_key', 'award.turn')
            ->pluck('awarded_turn')->all());

        $zeroNation = $this->createNation($world, '零周期国');
        $zero = app(AwardTurnFinalizer::class)->finalize(
            $this->context($world, 300, [$first, $second, $zeroNation]),
        );
        $this->assertSame(0, $zero['monster_turn_awards']);
    }

    public function test_awards_and_next_cycle_initialization_roll_back_with_the_turn_transaction(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, '原子性賞国');
        NationMonsterCycleStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'kill_count' => 1,
            'version' => 1,
        ]);

        try {
            DB::transaction(function () use ($world, $nation): never {
                app(AwardTurnFinalizer::class)->finalize($this->context($world, 100, [$nation]));
                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame(0, NationAward::query()->count());
        $this->assertFalse(NationMonsterCycleStat::query()->where('cycle_start_turn', 101)->exists());
        $this->assertSame(1, NationMonsterCycleStat::query()->where('cycle_start_turn', 1)->value('kill_count'));
    }

    public function test_award_occurrences_are_world_scoped_unique_and_immutable(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, '永続賞国');
        app(AwardTurnFinalizer::class)->finalize($this->context(
            $world,
            2,
            [$nation],
            finalPopulations: [$nation->id => 300_000],
        ));
        $award = NationAward::query()->sole();

        try {
            DB::transaction(static function () use ($award): void {
                $award->update(['awarded_turn' => 3]);
            });
            $this->fail('Award update unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Nation award occurrences are immutable', $exception->getMessage());
        }

        $otherWorld = World::query()->create([
            'key' => 'award-scope-world',
            'name' => '賞scope World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 1,
        ]);
        try {
            DB::transaction(static function () use ($otherWorld, $nation): void {
                NationAward::query()->create([
                    'world_id' => $otherWorld->id,
                    'nation_id' => $nation->id,
                    'award_key' => 'award.turn',
                    'awarded_turn' => 100,
                    'award_occurrence_key' => 'turn:100',
                ]);
            });
            $this->fail('Cross-World award unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('cannot cross World boundaries', $exception->getMessage());
        }

        $this->assertSame(2, $award->fresh()->awarded_turn);
        $this->assertSame(1, NationAward::query()->count());
    }

    public function test_monster_cycle_history_allows_only_exact_runtime_increments_and_freezes_completed_rows(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, '周期履歴国');
        NationMonsterCycleStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'kill_count' => 0,
            'version' => 1,
        ]);
        $cycle = NationMonsterCycleStat::query()->sole();

        $result = app(MonsterKillCycleService::class)->increment(
            $this->context($world, 2, [$nation]),
            $nation,
        );
        $this->assertSame(['previous' => 0, 'current' => 1], $result);
        $this->assertSame(2, $cycle->fresh()->version);

        try {
            DB::transaction(static function () use ($cycle): void {
                $cycle->update(['kill_count' => 3, 'version' => 3]);
            });
            $this->fail('Non-unit cycle update unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'must increment count and version by exactly one',
                $exception->getMessage(),
            );
        }

        $world->update(['current_turn' => 100]);
        try {
            DB::transaction(static function () use ($cycle): void {
                $cycle->update(['kill_count' => 2, 'version' => 3]);
            });
            $this->fail('Completed cycle update unexpectedly succeeded.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Completed monster cycle history is immutable', $exception->getMessage());
        }

        $this->assertSame(1, $cycle->fresh()->kill_count);
        $this->assertSame(2, $cycle->fresh()->version);
    }

    public function test_manual_cycle_seed_requires_exact_coordinates_and_fails_closed_on_duplicates(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, '手動seed国');
        $inora = MonsterDefinition::query()->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        DB::table('nation_monster_kill_stats')->insert([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $inora->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $targetTurn = (int) $world->current_turn + 1;
        $cycleStart = intdiv($targetTurn - 1, 100) * 100 + 1;
        $cycleEnd = $cycleStart + 99;
        DB::table('nation_monster_cycle_seed_requirements')->insert([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => $cycleStart,
            'cycle_end_turn' => $cycleEnd,
            'created_at' => now(),
        ]);
        $token = "SEED-{$world->key}-N{$nation->id}-{$cycleStart}-{$cycleEnd}-4";

        $missingConfirmation = Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $nation->id,
            '--kills' => '4',
        ]);
        $this->assertSame(1, $missingConfirmation);
        $this->assertStringContainsString($token, Artisan::output());
        $this->assertSame(0, NationMonsterCycleStat::query()->count());

        $success = Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $nation->id,
            '--kills' => '4',
            '--confirm' => $token,
        ]);
        $this->assertSame(0, $success);
        $this->assertDatabaseHas('nation_monster_cycle_stats', [
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => $cycleStart,
            'cycle_end_turn' => $cycleEnd,
            'kill_count' => 4,
        ]);
        $this->assertNotNull(NationMonsterCycleStat::query()->sole()->seeded_at);
        $this->assertNotNull(DB::table('nation_monster_cycle_seed_requirements')->value('completed_at'));
        $this->assertStringContainsString('remaining_required_nations=0', Artisan::output());

        $duplicate = Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $nation->id,
            '--kills' => '4',
            '--confirm' => $token,
        ]);
        $this->assertSame(1, $duplicate);
        $this->assertStringContainsString('seed requirement is already complete', Artisan::output());
        $this->assertSame(1, NationMonsterCycleStat::query()->count());
        $this->assertSame(1, DB::table('nation_monster_kill_stats')->count());
        $this->assertSame(1, (int) DB::table('nation_monster_kill_stats')->sum('kill_count'));
        $this->assertSame(0, NationAward::query()->count());

        $otherWorld = World::query()->create([
            'key' => 'seed-other-world',
            'name' => 'seed別World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => $world->current_turn,
        ]);
        $wrongWorldToken = "SEED-{$otherWorld->key}-N{$nation->id}-{$cycleStart}-{$cycleEnd}-0";
        $wrongWorld = Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $otherWorld->key,
            '--nation' => (string) $nation->id,
            '--kills' => '0',
            '--confirm' => $wrongWorldToken,
        ]);
        $this->assertSame(1, $wrongWorld);
        $this->assertStringContainsString('does not belong to World', Artisan::output());
        $this->assertSame(1, NationMonsterCycleStat::query()->count());

        $negative = Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $nation->id,
            '--kills' => '-1',
        ]);
        $this->assertSame(1, $negative);
    }

    public function test_legacy_seed_requirement_requires_seeded_stat_then_can_only_complete_once_and_cannot_be_deleted(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, 'seed要求監査国');
        $requirementId = DB::table('nation_monster_cycle_seed_requirements')->insertGetId([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'created_at' => now(),
        ]);

        try {
            DB::transaction(static function () use ($requirementId): void {
                DB::table('nation_monster_cycle_seed_requirements')
                    ->where('id', $requirementId)->update(['completed_at' => now()]);
            });
            $this->fail('Seed requirement unexpectedly completed without a seeded cycle stat.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('requires a corresponding seeded stat', $exception->getMessage());
        }
        $this->assertNull(DB::table('nation_monster_cycle_seed_requirements')
            ->where('id', $requirementId)->value('completed_at'));

        NationMonsterCycleStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'kill_count' => 0,
            'version' => 1,
            'seeded_at' => now(),
        ]);
        $this->assertSame(1, DB::table('nation_monster_cycle_seed_requirements')
            ->where('id', $requirementId)->update(['completed_at' => now()]));

        try {
            DB::transaction(static function () use ($requirementId): void {
                DB::table('nation_monster_cycle_seed_requirements')
                    ->where('id', $requirementId)->update(['completed_at' => now()->addSecond()]);
            });
            $this->fail('Completed seed requirement unexpectedly changed twice.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('may only be completed once', $exception->getMessage());
        }

        try {
            DB::transaction(static function () use ($requirementId): void {
                DB::table('nation_monster_cycle_seed_requirements')->where('id', $requirementId)->delete();
            });
            $this->fail('Seed requirement audit row was unexpectedly deleted.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('permanent while its World exists', $exception->getMessage());
        }

        $this->assertTrue(DB::table('nation_monster_cycle_seed_requirements')
            ->where('id', $requirementId)->exists());
    }

    public function test_completed_seed_requirement_without_seeded_stat_is_rejected_before_turn_or_ranking(): void
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, 'seed不整合監査国');
        $requirementId = DB::table('nation_monster_cycle_seed_requirements')->insertGetId([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'cycle_start_turn' => 1,
            'cycle_end_turn' => 100,
            'created_at' => now(),
        ]);

        DB::statement(
            'ALTER TABLE nation_monster_cycle_seed_requirements '
            .'DISABLE TRIGGER nation_monster_cycle_seed_requirement_update_guard',
        );
        try {
            DB::table('nation_monster_cycle_seed_requirements')
                ->where('id', $requirementId)->update(['completed_at' => now()]);
        } finally {
            DB::statement(
                'ALTER TABLE nation_monster_cycle_seed_requirements '
                .'ENABLE TRIGGER nation_monster_cycle_seed_requirement_update_guard',
            );
        }

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Turn unexpectedly started with a completed requirement but no seeded stat.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString("Nation IDs {$nation->id}", $exception->getMessage());
        }
        $this->assertSame(0, TurnRun::query()->where('world_id', $world->id)
            ->where('is_dry_run', false)->count());

        try {
            app(MonsterKillCycleService::class)->counts($world, 100, [$nation->id]);
            $this->fail('Ranking unexpectedly defaulted a completed requirement without a seeded stat to zero.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('incomplete legacy seed coverage', $exception->getMessage());
        }
    }

    public function test_incomplete_legacy_seed_coverage_blocks_turns_and_cycle_ranking_before_zero_default(): void
    {
        $world = $this->lightweightWorld();
        $first = $this->createNation($world, '移行seed第一国');
        $second = $this->createNation($world, '移行seed第二国');
        $interval = app(MonsterKillCycleService::class)->intervalForTurn($world->current_turn + 1);
        foreach ([$first, $second] as $nation) {
            DB::table('nation_monster_cycle_seed_requirements')->insert([
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                'cycle_start_turn' => $interval['start'],
                'cycle_end_turn' => $interval['end'],
                'created_at' => now(),
            ]);
        }

        $firstToken = "SEED-{$world->key}-N{$first->id}-{$interval['start']}-{$interval['end']}-0";
        $this->assertSame(0, Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $first->id,
            '--kills' => '0',
            '--confirm' => $firstToken,
        ]));
        $this->assertStringContainsString('remaining_required_nations=1', Artisan::output());

        try {
            app(TurnRunner::class)->run($world);
            $this->fail('Turn unexpectedly started with incomplete legacy seed coverage.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString("Nation IDs {$second->id}", $exception->getMessage());
        }
        $this->assertSame(0, TurnRun::query()->where('world_id', $world->id)
            ->where('is_dry_run', false)->count());

        try {
            app(MonsterKillCycleService::class)->counts($world, 100, [$first->id, $second->id]);
            $this->fail('Cycle ranking unexpectedly defaulted an incomplete legacy seed to zero.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('incomplete legacy seed coverage', $exception->getMessage());
        }

        $secondToken = "SEED-{$world->key}-N{$second->id}-{$interval['start']}-{$interval['end']}-0";
        $this->assertSame(0, Artisan::call('hakoniwa:awards:seed-monster-cycle', [
            '--world' => $world->key,
            '--nation' => (string) $second->id,
            '--kills' => '0',
            '--confirm' => $secondToken,
        ]));
        $this->assertStringContainsString('remaining_required_nations=0', Artisan::output());
        $this->assertSame(
            [$first->id => 0, $second->id => 0],
            app(MonsterKillCycleService::class)->counts($world, 100, [$first->id, $second->id]),
        );
    }

    /**
     * @param  list<Nation>  $nations
     * @param  array<int, int>  $startPopulations
     * @param  array<int, int>  $finalPopulations
     * @param  array<int, int>  $refugees
     */
    private function context(
        World $world,
        int $targetTurn,
        array $nations,
        array $startPopulations = [],
        array $finalPopulations = [],
        array $refugees = [],
    ): TurnContext {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $seed = hash('sha256', "award-system-{$world->id}-{$targetTurn}");
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds(array_map(static fn (Nation $nation): int => $nation->id, $nations));
        foreach ($nations as $nation) {
            $startPopulation = $startPopulations[$nation->id] ?? 1_000;
            $finalPopulation = $finalPopulations[$nation->id] ?? 1_000;
            $state->setNationStartSummary($nation->id, [
                'money' => 0, 'population' => $startPopulation, 'food' => 0,
            ]);
            $state->setNationAggregate($nation->id, [
                'population' => $finalPopulation,
                'farm_capacity' => 0,
                'factory_capacity' => 0,
                'mine_capacity' => 0,
                'owned_land_cells' => 1,
            ]);
            if (($refugees[$nation->id] ?? 0) > 0) {
                $state->addRefugeesReceived($nation->id, $refugees[$nation->id]);
            }
        }

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    private function createNation(World $world, string $name): Nation
    {
        return app(NationCreationService::class)->create(User::factory()->create(), $world, $name, $name.'主');
    }
}
