<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Monster\MonsterDamageResult;
use App\Domain\Monster\MonsterHardening;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\MonsterInstance;
use App\Models\Nation;
use App\Models\NationMonsterKillStat;
use App\Models\ResourceDefinition;
use DomainException;
use Illuminate\Support\Facades\DB;

final class MonsterDamageService
{
    public function __construct(
        private readonly MonsterHardening $hardening,
        private readonly CapacityBoundedAssetService $boundedAssets,
        private readonly FoodOverflowResolver $foodOverflow,
        private readonly MonsterRemovalService $removal,
        private readonly TurnEventRecorder $events,
        private readonly MonsterKillCycleService $monsterCycles,
    ) {}

    public function applyDamage(
        MonsterInstance $monster,
        int $amount,
        string $damageType,
        ?Nation $killerNation,
        ?MapCell $firingBase,
        MapCell $hostCell,
        TurnContext $context,
    ): MonsterDamageResult {
        if ($amount < 1 || $damageType === '') {
            throw new DomainException('Monster damage requires a positive amount and explicit damage type.');
        }

        return DB::transaction(function () use (
            $monster,
            $amount,
            $damageType,
            $killerNation,
            $firingBase,
            $hostCell,
            $context,
        ): MonsterDamageResult {
            $hostCell = MapCell::query()->whereKey($hostCell->id)->lockForUpdate()->firstOrFail();
            $locked = MonsterInstance::query()
                ->whereKey($monster->id)
                ->with(['definition', 'occupancy'])
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->world_id !== $context->world->id) {
                throw new DomainException('Monster damage cannot cross World boundaries.');
            }
            if ($locked->state !== 'alive') {
                return new MonsterDamageResult(
                    status: 'already_resolved',
                    beforeHp: $locked->current_hp,
                    afterHp: $locked->current_hp,
                    blocked: false,
                    killed: $locked->state === 'killed',
                );
            }
            $occupancy = $locked->occupancy;
            if ($occupancy === null || $occupancy->map_cell_id !== $hostCell->id) {
                throw new DomainException('Monster damage host cell does not match current occupancy.');
            }
            if ($killerNation !== null && $killerNation->world_id !== $context->world->id) {
                throw new DomainException('Monster killer Nation cannot cross World boundaries.');
            }
            $hostNationSnapshot = $hostCell->owner_nation_id === null
                ? null
                : Nation::query()->whereKey($hostCell->owner_nation_id)->first(['id', 'name']);
            if ($this->hardening->isHardened($locked->definition, $context->targetTurn)) {
                $this->events->record($context, 'monster.damage_blocked', $locked, [
                    'monster_key' => $locked->definition->key,
                    'nation_id' => $killerNation?->id,
                    'attacker_nation_id' => $killerNation?->id,
                    'attacker_nation_name' => $killerNation?->name,
                    'host_nation_id' => $hostNationSnapshot?->id,
                    'host_nation_name' => $hostNationSnapshot?->name,
                    'x' => $hostCell->x,
                    'y' => $hostCell->y,
                    'damage_type' => $damageType,
                    'requested_damage' => $amount,
                    'hardening' => true,
                ]);

                return new MonsterDamageResult(
                    status: 'blocked_hardened',
                    beforeHp: $locked->current_hp,
                    afterHp: $locked->current_hp,
                    blocked: true,
                    killed: false,
                );
            }

            $beforeHp = $locked->current_hp;
            $afterHp = max(0, $beforeHp - $amount);
            if ($afterHp > 0) {
                $locked->current_hp = $afterHp;
                $locked->version++;
                $locked->save();
                $context->state->markMapChunkChanged($hostCell->map_chunk_id);
                $this->events->record($context, 'monster.damaged', $locked, [
                    'monster_key' => $locked->definition->key,
                    'nation_id' => $killerNation?->id,
                    'attacker_nation_id' => $killerNation?->id,
                    'attacker_nation_name' => $killerNation?->name,
                    'host_nation_id' => $hostNationSnapshot?->id,
                    'host_nation_name' => $hostNationSnapshot?->name,
                    'x' => $hostCell->x,
                    'y' => $hostCell->y,
                    'damage_type' => $damageType,
                    'requested_damage' => $amount,
                    'before_hp' => $beforeHp,
                    'after_hp' => $afterHp,
                ]);

                return new MonsterDamageResult(
                    status: 'damaged',
                    beforeHp: $beforeHp,
                    afterHp: $afterHp,
                    blocked: false,
                    killed: false,
                );
            }

            $hostNation = $hostCell->owner_nation_id === null
                ? null
                : Nation::query()->whereKey($hostCell->owner_nation_id)->lockForUpdate()->first();
            $value = $locked->definition->wreckage_value_money;
            $killerShare = intdiv($value, 2);
            $hostShare = $value - $killerShare;
            $killerMoney = null;
            $hostMeat = null;
            if ($killerNation !== null) {
                $killerMoney = $this->boundedAssets
                    ->creditMoney($killerNation, $killerShare, $context->ruleset)
                    ->toArray();
                if ($hostNation !== null) {
                    $meat = ResourceDefinition::query()->where('key', 'monster_meat')->firstOrFail();
                    $hostMeatCredit = $this->boundedAssets
                        ->creditFood($hostNation, $meat, $hostShare * 500, $context->ruleset);
                    $hostMeat = $hostMeatCredit->toArray();
                    if ($hostMeatCredit->overflow > 0) {
                        $this->foodOverflow->resolve($context, $hostNation, $meat, $hostMeatCredit);
                    }
                }
            }

            $baseExperienceApplied = $killerNation === null || $firingBase === null
                ? 0
                : $this->creditFiringBaseExperience($firingBase, $killerNation, $locked, $context);
            $this->removal->detachForKill($context, $occupancy, $hostCell);
            $locked->current_hp = 0;
            $locked->state = 'killed';
            $locked->removal_reason = $damageType;
            $locked->removed_at = now();
            $locked->version++;
            $locked->save();

            $killStat = null;
            $previousKillCount = null;
            $newKillCount = null;
            $monsterCycle = null;
            if ($killerNation !== null) {
                [$killStat, $previousKillCount, $newKillCount] = $this->incrementKillStat(
                    $context,
                    $killerNation,
                    $locked,
                );
                $monsterCycle = $this->monsterCycles->increment($context, $killerNation);
            }

            $eventMetadata = [
                'monster_instance_id' => $locked->id,
                'monster_definition_key' => $locked->definition->key,
                'monster_key' => $locked->definition->key,
                'nation_id' => $killerNation->id ?? $hostNation?->id,
                'attacker_nation_id' => $killerNation?->id,
                'attacker_nation_name' => $killerNation?->name,
                'killer_nation_id' => $killerNation?->id,
                'killer_nation_name' => $killerNation?->name,
                'host_nation_id' => $hostNation?->id,
                'host_nation_name' => $hostNation?->name,
                'x' => $hostCell->x,
                'y' => $hostCell->y,
                'damage_type' => $damageType,
                'before_hp' => $beforeHp,
                'after_hp' => 0,
                'wreckage_value_money' => $value,
                'killer_money' => $killerMoney,
                'host_meat_food' => $hostMeat,
                'unclaimed_host_value_money' => $hostNation === null && $killerNation !== null ? $hostShare : 0,
                'firing_base_experience_applied' => $baseExperienceApplied,
                'firing_base_id' => $firingBase?->id,
                'kill_stat_id' => $killStat?->id,
                'previous_kill_count' => $previousKillCount,
                'new_kill_count' => $newKillCount,
                'previous_monster_cycle_kill_count' => $monsterCycle['previous'] ?? null,
                'new_monster_cycle_kill_count' => $monsterCycle['current'] ?? null,
            ];
            $this->events->record($context, 'monster.killed', $locked, $eventMetadata);
            if ($killerNation !== null) {
                $this->events->record($context, 'monster.reward_distributed', $locked, $eventMetadata);
                $this->events->record($context, 'monster.kill_stat_incremented', $killStat, $eventMetadata);
            }

            return new MonsterDamageResult(
                status: $killerNation === null ? 'killed_unattributed' : 'killed',
                beforeHp: $beforeHp,
                afterHp: 0,
                blocked: false,
                killed: true,
                killerMoney: $killerMoney,
                hostMeat: $hostMeat,
                firingBaseExperienceApplied: $baseExperienceApplied,
                killStatId: $killStat?->id,
                previousKillCount: $previousKillCount,
                newKillCount: $newKillCount,
            );
        }, 3);
    }

    /** @return array{NationMonsterKillStat, int, int} */
    private function incrementKillStat(
        TurnContext $context,
        Nation $killerNation,
        MonsterInstance $monster,
    ): array {
        $row = DB::selectOne(<<<'SQL'
INSERT INTO nation_monster_kill_stats (
    world_id, nation_id, monster_definition_id, kill_count,
    first_killed_turn, last_killed_turn, version, created_at, updated_at
) VALUES (?, ?, ?, 1, ?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (world_id, nation_id, monster_definition_id) DO UPDATE SET
    kill_count = nation_monster_kill_stats.kill_count + 1,
    last_killed_turn = EXCLUDED.last_killed_turn,
    version = nation_monster_kill_stats.version + 1,
    updated_at = CURRENT_TIMESTAMP
RETURNING id, kill_count
SQL, [
            $context->world->id,
            $killerNation->id,
            $monster->monster_definition_id,
            $context->targetTurn,
            $context->targetTurn,
        ]);
        if ($row === null) {
            throw new DomainException('Monster kill stat increment did not return its authoritative row.');
        }
        $newCount = (int) $row->kill_count;
        $stat = NationMonsterKillStat::query()->findOrFail((int) $row->id);

        return [$stat, $newCount - 1, $newCount];
    }

    private function creditFiringBaseExperience(
        MapCell $firingBase,
        Nation $killerNation,
        MonsterInstance $monster,
        TurnContext $context,
    ): int {
        $base = MapCell::query()
            ->whereKey($firingBase->id)
            ->with('facility')
            ->lockForUpdate()
            ->firstOrFail();
        if ($base->owner_nation_id !== $killerNation->id || $base->facility?->key !== 'missile_base') {
            throw new DomainException('Firing base must be a missile base owned by the killer Nation.');
        }
        $maximum = $context->ruleset->settings['monster_system']['reward']['missile_base_experience_maximum'] ?? null;
        if (! is_int($maximum) || $maximum !== 200) {
            throw new DomainException('The active ruleset has an invalid missile-base experience cap.');
        }
        $before = (int) ($base->facility_experience ?? 0);
        $after = min($maximum, $before + $monster->definition->missile_base_experience);
        $applied = $after - $before;
        if ($applied > 0) {
            $base->facility_experience = $after;
            $base->version++;
            $base->save();
            $context->state->markMapChunkChanged($base->map_chunk_id);
        }

        return $applied;
    }
}
