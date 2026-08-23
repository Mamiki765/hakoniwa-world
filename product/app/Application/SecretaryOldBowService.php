<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemProbability;
use App\Domain\Secretary\SecretaryItemTargetSafetyPolicy;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MapSpace;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use DomainException;

final class SecretaryOldBowService
{
    public function __construct(
        private readonly SecretaryItemGameplayContract $contract,
        private readonly SecretaryItemProbability $probability,
        private readonly SecretaryItemTargetSafetyPolicy $safety,
        private readonly MonsterDamageService $damage,
    ) {}

    /** @return array<string, int> */
    public function execute(TurnContext $context, MapSpace $surface, bool $normalMonsterPassSeparated): array
    {
        if (! $this->contract->exists($context->ruleset->settings)) {
            return [];
        }
        if (! $normalMonsterPassSeparated) {
            throw new DomainException('Secretary Old Bow timing requires the separated normal-monster pass.');
        }
        if ($surface->world_id !== $context->world->id || $surface->key !== 'surface') {
            throw new DomainException('Secretary Old Bow C2 execution requires the active World surface MapSpace.');
        }

        $nationEffects = [];
        foreach ($context->state->stableNationIds() as $nationId) {
            if (! $context->state->hasSecretaryItemEffectSnapshot($nationId)) {
                throw new DomainException("Nation {$nationId} is missing its Secretary Item effect snapshot.");
            }
            $resolved = $this->oldBowEffect($context, $nationId);
            if ($resolved !== null) {
                $nationEffects[$nationId] = $resolved;
            }
        }
        $metrics = [
            'secretary_old_bow_eligible_nations' => count($nationEffects),
            'secretary_old_bow_attempts' => 0,
            'secretary_old_bow_triggers' => 0,
            'secretary_old_bow_misses' => 0,
            'secretary_old_bow_hits' => 0,
            'secretary_old_bow_kills' => 0,
            'secretary_old_bow_no_safe_target' => 0,
        ];
        if ($nationEffects === []) {
            return $metrics;
        }

        $nationIds = array_keys($nationEffects);
        $nations = Nation::query()
            ->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'recovery'])
            ->whereIn('id', $nationIds)
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        if ($nations->count() !== count($nationIds)) {
            throw new DomainException('Secretary Old Bow snapshot references a missing current Nation.');
        }
        $occupancies = MonsterOccupancy::query()
            ->whereHas('monster', fn ($query) => $query
                ->where('world_id', $context->world->id)
                ->where('state', 'alive')
                ->whereHas('definition', fn ($definition) => $definition
                    ->where('ruleset_version_id', $context->ruleset->id)))
            ->whereHas('cell', fn ($cell) => $cell
                ->where('map_space_id', $surface->id)
                ->whereIn('owner_nation_id', $nationIds))
            ->with(['monster.definition', 'cell'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $candidatesByNation = [];
        foreach ($occupancies as $occupancy) {
            $nationId = (int) $occupancy->cell->owner_nation_id;
            $effect = $nationEffects[$nationId]['effect'];
            if ($this->safety->allows(
                $occupancy->monster,
                $effect['parameters']['damage'],
                $context->targetTurn,
            )) {
                $candidatesByNation[$nationId][] = $occupancy;
            }
        }

        foreach ($context->state->stableNationIds() as $nationId) {
            $resolved = $nationEffects[$nationId] ?? null;
            if ($resolved === null) {
                continue;
            }
            $candidates = $candidatesByNation[$nationId] ?? [];
            if ($candidates === []) {
                $metrics['secretary_old_bow_no_safe_target']++;

                continue;
            }
            $effect = $resolved['effect'];
            $version = $effect['random_stream_version'];
            $metrics['secretary_old_bow_attempts']++;
            $trigger = $context->random->stream(
                TurnRandomStreamFactory::secretaryOldBow($nationId, 'trigger', $version),
            )->integer(0, 9_999);
            if (! $this->probability->passesBasisPointDraw(
                $trigger,
                $effect['parameters']['chance_basis_points'],
            )) {
                $metrics['secretary_old_bow_misses']++;

                continue;
            }
            $metrics['secretary_old_bow_triggers']++;
            $targetIndex = $context->random->stream(
                TurnRandomStreamFactory::secretaryOldBow($nationId, 'target', $version),
            )->integer(0, count($candidates) - 1);
            $target = $candidates[$targetIndex];
            $result = $this->damage->applyDamage(
                $target->monster,
                $effect['parameters']['damage'],
                $effect['parameters']['damage_type'],
                $nations->get($nationId),
                null,
                $target->cell,
                $context,
            );
            if ($result->blocked || ! in_array($result->status, ['damaged', 'killed'], true)) {
                throw new DomainException('Secretary Old Bow authoritative damage did not match its safe current target.');
            }
            $metrics['secretary_old_bow_hits']++;
            $metrics['secretary_old_bow_kills'] += $result->killed ? 1 : 0;
        }

        return $metrics;
    }

    /** @return array{item: array<string, mixed>, effect: array<string, mixed>}|null */
    private function oldBowEffect(TurnContext $context, int $nationId): ?array
    {
        $found = null;
        foreach ($context->state->secretaryItemEffectSnapshot($nationId)['items'] as $item) {
            if ($item['item_key'] !== SecretaryItemCatalog::OLD_BOW) {
                continue;
            }
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] !== SecretaryItemGameplayContract::PRE_NORMAL_MONSTER_ATTACK) {
                    continue;
                }
                if ($found !== null
                    || $effect['timing'] !== SecretaryItemGameplayContract::OLD_BOW_TIMING
                    || $effect['target_map_space_keys'] !== ['surface']
                    || ($effect['parameters']['damage_type'] ?? null) !== SecretaryItemGameplayContract::OLD_BOW_DAMAGE_TYPE
                    || ($effect['parameters']['target_scope'] ?? null) !== SecretaryItemGameplayContract::OLD_BOW_TARGET_SCOPE
                    || ($effect['parameters']['target_safety_policy'] ?? null) !== SecretaryItemGameplayContract::OLD_BOW_TARGET_SAFETY
                    || ! is_int($effect['parameters']['chance_basis_points'] ?? null)
                    || ! is_int($effect['parameters']['damage'] ?? null)
                    || ! is_int($effect['random_stream_version'] ?? null)) {
                    throw new DomainException('Secretary Old Bow snapshot contains an invalid C2 effect.');
                }
                $found = ['item' => $item, 'effect' => $effect];
            }
        }

        return $found;
    }
}
