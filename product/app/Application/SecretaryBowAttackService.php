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

final class SecretaryBowAttackService
{
    /** @var list<string> */
    private const BOW_KEYS = [
        SecretaryItemCatalog::OLD_BOW,
        SecretaryItemCatalog::ELF_BOW,
        SecretaryItemCatalog::LONGSHOT_BOW,
        SecretaryItemCatalog::MECHANICAL_BOW,
    ];

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
            throw new DomainException('Secretary Bow timing requires the separated normal-monster pass.');
        }
        if ($surface->world_id !== $context->world->id || $surface->key !== 'surface') {
            throw new DomainException('Secretary Bow execution requires the active World surface MapSpace.');
        }

        $nationEffects = [];
        foreach ($context->state->stableNationIds() as $nationId) {
            if (! $context->state->hasSecretaryItemEffectSnapshot($nationId)) {
                throw new DomainException("Nation {$nationId} is missing its Secretary Item effect snapshot.");
            }
            $resolved = $this->bowEffect($context, $nationId);
            if ($resolved !== null) {
                $nationEffects[$nationId] = $resolved;
            }
        }
        $metrics = [
            'secretary_old_bow_eligible_nations' => count(array_filter(
                $nationEffects,
                static fn (array $resolved): bool => $resolved['item']['item_key'] === SecretaryItemCatalog::OLD_BOW,
            )),
            'secretary_old_bow_attempts' => 0,
            'secretary_old_bow_triggers' => 0,
            'secretary_old_bow_misses' => 0,
            'secretary_old_bow_hits' => 0,
            'secretary_old_bow_kills' => 0,
            'secretary_old_bow_no_safe_target' => 0,
            'secretary_bow_eligible_nations' => count($nationEffects),
            'secretary_bow_attempts' => 0,
            'secretary_bow_hits' => 0,
            'secretary_bow_kills' => 0,
            'secretary_mechanical_bow_finishers' => 0,
        ];
        if ($nationEffects === []) {
            return $metrics;
        }

        $nationIds = array_keys($nationEffects);
        $nations = Nation::query()->where('world_id', $context->world->id)
            ->whereIn('state', ['active', 'recovery'])->whereIn('id', $nationIds)
            ->orderBy('id')->get()->keyBy('id');
        if ($nations->count() !== count($nationIds)) {
            throw new DomainException('Secretary Bow snapshot references a missing current Nation.');
        }
        $occupancies = MonsterOccupancy::query()
            ->whereHas('monster', fn ($query) => $query
                ->where('world_id', $context->world->id)->where('state', 'alive')
                ->whereHas('definition', fn ($definition) => $definition
                    ->where('ruleset_version_id', $context->ruleset->id)))
            ->whereHas('cell', fn ($cell) => $cell->where('map_space_id', $surface->id))
            ->with(['monster.definition', 'cell'])->orderBy('id')->lockForUpdate()->get();

        foreach ($context->state->stableNationIds() as $nationId) {
            $resolved = $nationEffects[$nationId] ?? null;
            if ($resolved === null) {
                continue;
            }
            $itemKey = $resolved['item']['item_key'];
            $effect = $resolved['effect'];
            $candidates = [];
            foreach ($occupancies as $occupancy) {
                $owned = (int) $occupancy->cell->owner_nation_id === $nationId;
                $longshotAoi = $itemKey === SecretaryItemCatalog::LONGSHOT_BOW
                    && $occupancy->monster->definition->key === 'aoi_inora';
                if (! $owned && ! $longshotAoi) {
                    continue;
                }
                $damage = (int) $effect['parameters']['damage'];
                $finisher = false;
                if ($this->safety->allows($occupancy->monster, $damage, $context->targetTurn)) {
                    $candidates[] = ['occupancy' => $occupancy, 'damage' => $damage, 'finisher' => false];

                    continue;
                }
                if ($itemKey === SecretaryItemCatalog::MECHANICAL_BOW
                    && $occupancy->monster->current_hp === 2
                    && $this->safety->allows($occupancy->monster, 2, $context->targetTurn)) {
                    $finisher = true;
                }
                if ($finisher) {
                    $candidates[] = ['occupancy' => $occupancy, 'damage' => 2, 'finisher' => true];
                }
            }
            $isOld = $itemKey === SecretaryItemCatalog::OLD_BOW;
            if ($candidates === []) {
                if ($isOld) {
                    $metrics['secretary_old_bow_no_safe_target']++;
                }

                continue;
            }

            $metrics['secretary_bow_attempts']++;
            if ($isOld) {
                $metrics['secretary_old_bow_attempts']++;
                $triggerDraw = $context->random->stream(TurnRandomStreamFactory::secretaryOldBow(
                    $nationId, 'trigger', $effect['random_stream_version'],
                ))->integer(0, 9_999);
                $candidate = null;
            } else {
                $candidate = $candidates[$context->random->stream(TurnRandomStreamFactory::secretaryBow(
                    $nationId, $itemKey, 'target', $effect['random_stream_version'],
                ))->integer(0, count($candidates) - 1)];
                $triggerDraw = $context->random->stream(TurnRandomStreamFactory::secretaryBow(
                    $nationId, $itemKey, 'trigger', $effect['random_stream_version'],
                ))->integer(0, 9_999);
            }
            $chance = isset($effect['parameters']['chance_basis_points'])
                ? (int) $effect['parameters']['chance_basis_points']
                : (int) $effect['parameters']['chance_base_basis_points']
                    + ((int) $resolved['item']['level'] * (int) $effect['parameters']['chance_basis_points_per_level']);
            if ($candidate !== null && $candidate['finisher']) {
                $chance = intdiv($chance * 2, 5);
            }
            if (! $this->probability->passesBasisPointDraw($triggerDraw, $chance)) {
                if ($isOld) {
                    $metrics['secretary_old_bow_misses']++;
                }

                continue;
            }
            if ($isOld) {
                $metrics['secretary_old_bow_triggers']++;
                $candidate = $candidates[$context->random->stream(TurnRandomStreamFactory::secretaryOldBow(
                    $nationId, 'target', $effect['random_stream_version'],
                ))->integer(0, count($candidates) - 1)];
            }
            $target = $candidate['occupancy'];
            $result = $this->damage->applyDamage(
                $target->monster,
                $candidate['damage'],
                $effect['parameters']['damage_type'],
                $nations->get($nationId),
                null,
                $target->cell,
                $context,
            );
            if ($result->blocked || ! in_array($result->status, ['damaged', 'killed'], true)) {
                throw new DomainException('Secretary Bow authoritative damage did not match its safe current target.');
            }
            if ($result->killed) {
                $occupancies = $occupancies->reject(
                    static fn (MonsterOccupancy $occupancy): bool => $occupancy->monster_instance_id === $target->monster_instance_id,
                )->values();
            } else {
                $target->monster->current_hp = $result->afterHp;
            }
            $metrics['secretary_bow_hits']++;
            $metrics['secretary_bow_kills'] += $result->killed ? 1 : 0;
            $metrics['secretary_mechanical_bow_finishers'] += $candidate['finisher'] ? 1 : 0;
            if ($isOld) {
                $metrics['secretary_old_bow_hits']++;
                $metrics['secretary_old_bow_kills'] += $result->killed ? 1 : 0;
            }
        }

        return $metrics;
    }

    /** @return array{item: array<string, mixed>, effect: array<string, mixed>}|null */
    private function bowEffect(TurnContext $context, int $nationId): ?array
    {
        $found = null;
        foreach ($context->state->secretaryItemEffectSnapshot($nationId)['items'] as $item) {
            if (! in_array($item['item_key'], self::BOW_KEYS, true)) {
                continue;
            }
            foreach ($item['effects'] as $effect) {
                if ($effect['type'] !== SecretaryItemGameplayContract::PRE_NORMAL_MONSTER_ATTACK) {
                    continue;
                }
                if ($found !== null || $effect['timing'] !== SecretaryItemGameplayContract::OLD_BOW_TIMING
                    || $effect['target_map_space_keys'] !== ['surface']
                    || ($effect['parameters']['target_safety_policy'] ?? null) !== SecretaryItemGameplayContract::OLD_BOW_TARGET_SAFETY
                    || ! is_int($effect['parameters']['damage'] ?? null)
                    || ! is_int($effect['random_stream_version'] ?? null)) {
                    throw new DomainException('Secretary Bow snapshot contains an invalid effect.');
                }
                $found = ['item' => $item, 'effect' => $effect];
            }
        }

        return $found;
    }
}
