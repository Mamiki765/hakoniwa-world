<?php

namespace App\Console\Commands;

use App\Application\MonsterKillCycleService;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\WorldTurnLock;
use App\Models\Nation;
use App\Models\NationMonsterCycleStat;
use App\Models\TurnRun;
use App\Models\World;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SeedMonsterAwardCycle extends Command
{
    protected $signature = 'hakoniwa:awards:seed-monster-cycle
        {--world= : Exact World key}
        {--nation= : Exact Nation database ID}
        {--kills= : Verified current-cycle attributed final-blow total}
        {--confirm= : Must equal the command-reported SEED token}';

    protected $description = 'Seed one pre-1.3.0 Nation monster-award cycle total without inferring history.';

    public function handle(WorldTurnLock $turnLock, MonsterKillCycleService $cycles): int
    {
        $worldKey = (string) $this->option('world');
        $nationOption = (string) $this->option('nation');
        $killsOption = (string) $this->option('kills');
        if ($worldKey === '' || ! ctype_digit($nationOption) || ! ctype_digit($killsOption)) {
            $this->error('World, positive Nation ID, and non-negative kills must be specified explicitly.');

            return self::FAILURE;
        }
        $nationId = (int) $nationOption;
        $kills = (int) $killsOption;
        if ($nationId < 1) {
            $this->error('Nation ID must be positive.');

            return self::FAILURE;
        }
        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World '{$worldKey}' does not exist.");

            return self::FAILURE;
        }

        try {
            $turnLock->acquire($world);
        } catch (TurnAlreadyRunningException) {
            $this->error("World '{$worldKey}' is currently processing a turn. Seed was not started.");

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($world, $worldKey, $nationId, $kills, $cycles): array {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $targetTurn = (int) $lockedWorld->current_turn + 1;
                $interval = $cycles->intervalForTurn($targetTurn);
                $expected = $this->confirmationToken(
                    $worldKey,
                    $nationId,
                    $interval['start'],
                    $interval['end'],
                    $kills,
                );
                if ((string) $this->option('confirm') !== $expected) {
                    throw new DomainException("Confirmation must exactly equal {$expected}.");
                }
                $unsafe = TurnRun::query()
                    ->where('world_id', $lockedWorld->id)
                    ->where('target_turn', $targetTurn)
                    ->where('is_dry_run', false)
                    ->whereIn('status', [
                        TurnRun::STATUS_PENDING,
                        TurnRun::STATUS_RUNNING,
                        TurnRun::STATUS_FAILED,
                        TurnRun::STATUS_BLOCKED,
                    ])
                    ->exists();
                if ($unsafe) {
                    throw new DomainException("Target turn {$targetTurn} has an unresolved production TurnRun.");
                }
                $nation = Nation::query()->whereKey($nationId)->lockForUpdate()->first();
                if ($nation === null || $nation->world_id !== $lockedWorld->id) {
                    throw new DomainException("Nation {$nationId} does not belong to World '{$worldKey}'.");
                }
                $requirement = DB::table('nation_monster_cycle_seed_requirements')
                    ->where('world_id', $lockedWorld->id)
                    ->where('nation_id', $nation->id)
                    ->where('cycle_start_turn', $interval['start'])
                    ->where('cycle_end_turn', $interval['end'])
                    ->lockForUpdate()
                    ->first();
                if ($requirement === null) {
                    throw new DomainException('This Nation and cycle has no legacy seed requirement.');
                }
                if ($requirement->completed_at !== null) {
                    throw new DomainException('This Nation and cycle seed requirement is already complete.');
                }
                $existing = NationMonsterCycleStat::query()
                    ->where('world_id', $lockedWorld->id)
                    ->where('nation_id', $nation->id)
                    ->where('cycle_start_turn', $interval['start'])
                    ->lockForUpdate()
                    ->exists();
                if ($existing) {
                    throw new DomainException('This Nation and cycle already has seed or runtime state.');
                }
                NationMonsterCycleStat::query()->create([
                    'world_id' => $lockedWorld->id,
                    'nation_id' => $nation->id,
                    'cycle_start_turn' => $interval['start'],
                    'cycle_end_turn' => $interval['end'],
                    'kill_count' => $kills,
                    'version' => 1,
                    'seeded_at' => now(),
                ]);
                $completed = DB::table('nation_monster_cycle_seed_requirements')
                    ->where('id', $requirement->id)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => now()]);
                if ($completed !== 1) {
                    throw new DomainException('Legacy seed requirement completion was not recorded exactly once.');
                }
                $remaining = DB::table('nation_monster_cycle_seed_requirements')
                    ->where('world_id', $lockedWorld->id)
                    ->where('cycle_start_turn', $interval['start'])
                    ->where('cycle_end_turn', $interval['end'])
                    ->whereNull('completed_at')
                    ->count();

                return [
                    'nation_name' => $nation->name,
                    'target_turn' => $targetTurn,
                    'remaining' => $remaining,
                    ...$interval,
                ];
            }, 3);
        } catch (Throwable $exception) {
            $this->error('Monster award cycle seed failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $turnLock->release($world);
        }

        $this->info(
            "monster_cycle_seeded world={$worldKey} nation_id={$nationId} "
            ."nation={$result['nation_name']} cycle={$result['start']}-{$result['end']} "
            ."next_target_turn={$result['target_turn']} kills={$kills} "
            ."remaining_required_nations={$result['remaining']}",
        );

        return self::SUCCESS;
    }

    private function confirmationToken(
        string $worldKey,
        int $nationId,
        int $cycleStart,
        int $cycleEnd,
        int $kills,
    ): string {
        return "SEED-{$worldKey}-N{$nationId}-{$cycleStart}-{$cycleEnd}-{$kills}";
    }
}
