<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\Ship;
use DomainException;

final class SurfaceShipCombatService
{
    public function __construct(
        private readonly SurfaceShipRemovalService $removal,
        private readonly BuriedTreasureService $treasures,
        private readonly NationRefugeeReceptionService $refugees,
        private readonly LaunchBaseExperienceService $baseExperience,
        private readonly SecretaryExperienceAwardService $secretaryExperience,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array{before_hp:int,after_hp:int,sunk:bool,experience:int,refugees:int,changed_cell_ids:list<int>} */
    public function damage(
        TurnContext $context,
        MapCell $cell,
        Ship $ship,
        int $damage,
        Nation $attacker,
        string $source,
        ?MapCell $firingBase = null,
    ): array {
        if ($damage < 1 || ! in_array($source, ['missile', 'warship'], true)
            || $ship->nation_id !== null || ! in_array($ship->ship_type_key, ['pirate', 'treasure'], true)) {
            throw new DomainException('NPC Ship combat received an invalid target or source.');
        }
        $beforeHp = (int) $ship->current_hp;
        $actualDamage = min($beforeHp, $damage);
        $experience = $ship->ship_type_key === 'treasure'
            ? (int) $context->ruleset->settings['ocean_loop']['warship_attack']['fixed_treasure_ship_experience']
            : intdiv(
                max(0, (int) $ship->population),
                (int) $context->ruleset->settings['military']['launch_base_experience']['settlement_hit']['population_divisor'],
            );
        if ($experience > 0) {
            if ($source === 'missile') {
                if ($firingBase instanceof MapCell) {
                    $experience = $this->baseExperience->credit($firingBase, $attacker, $experience, $context);
                } else {
                    $experience = 0;
                }
            } else {
                $this->secretaryExperience->awardSkill($context, (int) $attacker->id, SecretarySkillCatalog::NAVY, $experience);
            }
        }
        $sunk = $actualDamage >= $beforeHp;
        $received = 0;
        $changedCellIds = [];
        if (! $sunk) {
            $ship->current_hp = $beforeHp - $actualDamage;
            $ship->version++;
            $ship->save();
            $context->state->markMapChunkChanged((int) $cell->map_chunk_id);
        } else {
            $population = (int) ($ship->population ?? 0);
            $removed = $this->removal->sinkLockedAtCell($context, $cell, $ship, $source, [
                'attacker_nation_id' => (int) $attacker->id,
                'damage' => $actualDamage,
                'before_hp' => $beforeHp,
            ]);
            if (! $removed instanceof Ship) {
                throw new DomainException('NPC Ship disappeared during combat resolution.');
            }
            $this->treasures->create(
                $context,
                $cell,
                $ship->ship_type_key === 'pirate' ? 'pirate_sink' : 'treasure_ship_sink',
                $ship->ship_type_key === 'treasure',
            );
            if ($ship->ship_type_key === 'pirate' && $population > 0) {
                $percent = (int) $context->ruleset->settings['ocean_loop']['pirate_attack'][
                    $source === 'missile' ? 'missile_refugee_percent' : 'warship_refugee_percent'
                ];
                $generated = intdiv($population * $percent, 100);
                $reception = $this->refugees->receive(
                    $context,
                    $attacker,
                    $cell,
                    $generated,
                    'pirate_ship_sunk',
                    ['combat_source' => $source, 'pirate_population' => $population, 'percent' => $percent],
                );
                $received = $reception['received'];
                $changedCellIds = $reception['changed_cell_ids'];
            }
        }
        $this->events->record($context, 'ship.combat_hit', $ship, [
            'attacker_nation_id' => (int) $attacker->id,
            'ship_id' => (int) $ship->id,
            'ship_type_key' => $ship->ship_type_key,
            'combat_source' => $source,
            'x' => (int) $cell->x,
            'y' => (int) $cell->y,
            'before_hp' => $beforeHp,
            'after_hp' => $sunk ? 0 : (int) $ship->current_hp,
            'experience' => $experience,
            'refugees_received' => $received,
        ], 'public');

        return [
            'before_hp' => $beforeHp,
            'after_hp' => $sunk ? 0 : (int) $ship->current_hp,
            'sunk' => $sunk,
            'experience' => $experience,
            'refugees' => $received,
            'changed_cell_ids' => $changedCellIds,
        ];
    }
}
