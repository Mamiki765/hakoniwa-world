<?php

namespace App\Application;

use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryEquipmentValidationException;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SecretaryEquipmentService
{
    public function __construct(
        private readonly UserMembershipMutationLock $membershipMutationLock,
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly SecretaryItemCatalog $catalog,
        private readonly SecretaryItemEffectContextResolver $effectContexts,
        private readonly SecretaryItemGameplayContract $itemGameplay,
    ) {}

    /**
     * @return array{
     *   slot: int,
     *   equipment_version: int,
     *   current_item: array<string, mixed>|null,
     *   items: list<array<string, mixed>>,
     *   category_limits: list<array{category: string, label: string, maximum_equipped: int}>,
     *   effect_context: array{source: string, world_id: int, ruleset_version_id: int, ruleset_key: string, ruleset_version: int}|null
     * }
     */
    public function options(User $user, int $slot, ?int $worldId = null): array
    {
        $this->assertSlot($slot);
        $effectProjection = $this->effectContexts->resolve($user, $worldId);
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->with('itemInstances')
            ->first();
        if (! $secretary instanceof Secretary) {
            throw new SecretaryNotFoundException('秘書がまだ作成されていません。');
        }

        /** @var Collection<int, SecretaryItemInstance> $items */
        $items = $secretary->itemInstances->sortBy([
            ['obtained_at', 'asc'],
            ['id', 'asc'],
        ])->values();
        $current = $items->first(
            static fn (SecretaryItemInstance $item): bool => $item->equipped_slot === $slot,
        );
        $candidateItems = [];
        if ($current instanceof SecretaryItemInstance) {
            $candidateItems[] = $this->optionItem($current, $effectProjection);
        }
        foreach ($items as $item) {
            if ($current instanceof SecretaryItemInstance && $item->id === $current->id) {
                continue;
            }
            if ($item->equipped_slot !== null) {
                continue;
            }
            if ($item->is_escrowed) {
                continue;
            }

            $proposed = $this->proposedState($items, $slot, $item);
            try {
                $this->assertValidState($proposed);
            } catch (SecretaryEquipmentValidationException) {
                continue;
            }
            $candidateItems[] = $this->optionItem($item, $effectProjection);
        }

        return [
            'slot' => $slot,
            'equipment_version' => $secretary->equipment_version,
            'current_item' => $current instanceof SecretaryItemInstance
                ? $this->optionItem($current, $effectProjection)
                : null,
            'items' => $candidateItems,
            'category_limits' => $this->catalog->categoryLimits(),
            'effect_context' => $effectProjection?->context,
        ];
    }

    public function mutate(User $user, int $slot, ?int $itemId, int $expectedVersion): Secretary
    {
        $this->assertSlot($slot);
        if ($expectedVersion < 1) {
            throw new SecretaryEquipmentValidationException('装備versionを確認してください。');
        }

        $this->membershipMutationLock->acquire($user);
        /** @var list<World> $acquiredWorlds */
        $acquiredWorlds = [];

        try {
            $frozenMemberships = $this->currentOwnerMembershipSnapshot($user->id);
            $worldIds = array_values(array_unique(array_column($frozenMemberships, 'world_id')));
            sort($worldIds, SORT_NUMERIC);
            $worlds = $worldIds === []
                ? collect()
                : World::query()->whereIn('id', $worldIds)->orderBy('id')->get();
            if ($worlds->count() !== count($worldIds)) {
                throw new SecretaryEquipmentConflictException(
                    'secretary_equipment_membership_changed',
                    '所属Worldの状態が変わりました。装備画面を再読込してください。',
                );
            }

            try {
                foreach ($worlds as $world) {
                    $this->worldMutationLock->acquire($world);
                    $acquiredWorlds[] = $world;
                }
            } catch (TurnAlreadyRunningException $exception) {
                throw new SecretaryEquipmentConflictException(
                    'secretary_equipment_world_updating',
                    '所属Worldが現在更新中です。後でもう一度お試しください。',
                    $exception,
                );
            }

            return DB::transaction(function () use (
                $user,
                $slot,
                $itemId,
                $expectedVersion,
                $worldIds,
                $frozenMemberships,
            ): Secretary {
                /** @var Collection<int, World> $lockedWorlds */
                $lockedWorlds = $worldIds === []
                    ? new Collection
                    : World::query()->whereIn('id', $worldIds)->orderBy('id')->lockForUpdate()->get();
                if ($lockedWorlds->count() !== count($worldIds)) {
                    throw new SecretaryEquipmentConflictException(
                        'secretary_equipment_membership_changed',
                        '所属Worldの状態が変わりました。装備画面を再読込してください。',
                    );
                }

                $currentMemberships = $this->lockedCurrentOwnerMembershipSnapshot($user->id);
                if ($currentMemberships !== $frozenMemberships) {
                    throw new SecretaryEquipmentConflictException(
                        'secretary_equipment_membership_changed',
                        '所属Worldの状態が変わりました。装備画面を再読込してください。',
                    );
                }

                foreach ($lockedWorlds as $world) {
                    try {
                        $this->turnRunGuard->assertClear($world);
                    } catch (UnresolvedNextTurnRunException $exception) {
                        throw new SecretaryEquipmentConflictException(
                            'secretary_equipment_turn_unresolved',
                            '次のターン処理が未解決のため装備を変更できません。',
                            $exception,
                        );
                    }
                }

                $secretary = Secretary::query()
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if (! $secretary instanceof Secretary) {
                    throw new SecretaryNotFoundException('秘書がまだ作成されていません。');
                }

                /** @var Collection<int, SecretaryItemInstance> $items */
                $items = SecretaryItemInstance::query()
                    ->where('secretary_id', $secretary->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($secretary->equipment_version !== $expectedVersion) {
                    throw new SecretaryEquipmentConflictException(
                        'secretary_equipment_version_conflict',
                        '装備状態が更新されています。最新の状態から選び直してください。',
                    );
                }

                $selected = null;
                if ($itemId !== null) {
                    $selected = $items->firstWhere('id', $itemId);
                    if (! $selected instanceof SecretaryItemInstance) {
                        throw new SecretaryEquipmentValidationException('選択したアイテムは装備できません。');
                    }
                    if ($selected->equipped_slot !== null && $selected->equipped_slot !== $slot) {
                        throw new SecretaryEquipmentValidationException('別のslotに装備中のアイテムは選択できません。');
                    }
                    if ($selected->is_escrowed) {
                        throw new SecretaryEquipmentValidationException('交易場へ出品中のアイテムは装備できません。');
                    }
                }

                $current = $items->first(
                    static fn (SecretaryItemInstance $item): bool => $item->equipped_slot === $slot,
                );
                $proposed = $this->proposedState($items, $slot, $selected);
                $this->assertValidState($proposed);

                $currentId = $current instanceof SecretaryItemInstance ? $current->id : null;
                if ($currentId === $itemId) {
                    return $secretary->load(['skills', 'itemInstances']);
                }

                if ($current instanceof SecretaryItemInstance) {
                    $current->equipped_slot = null;
                    $current->save();
                }
                if ($selected instanceof SecretaryItemInstance) {
                    $selected->equipped_slot = $slot;
                    $selected->save();
                }

                $previousVersion = $secretary->equipment_version;
                $secretary->equipment_version = $previousVersion + 1;
                $secretary->save();
                $this->recordMutation($user, $secretary, $slot, $current, $selected, $previousVersion);

                return $secretary->fresh(['skills', 'itemInstances']);
            }, 3);
        } finally {
            foreach (array_reverse($acquiredWorlds) as $world) {
                $this->worldMutationLock->release($world);
            }
            $this->membershipMutationLock->release($user);
        }
    }

    /** @return list<array{membership_id: int, world_id: int, nation_id: int}> */
    private function currentOwnerMembershipSnapshot(int $userId): array
    {
        return NationMembership::query()
            ->join('nations', 'nations.id', '=', 'nation_memberships.nation_id')
            ->where('nation_memberships.user_id', $userId)
            ->where('nation_memberships.role', 'owner')
            ->whereIn('nations.state', ['active', 'dormant', 'recovery'])
            ->whereColumn('nations.world_id', 'nation_memberships.world_id')
            ->orderBy('nation_memberships.world_id')
            ->orderBy('nation_memberships.nation_id')
            ->orderBy('nation_memberships.id')
            ->get([
                'nation_memberships.id as membership_id',
                'nation_memberships.world_id',
                'nation_memberships.nation_id',
            ])
            ->map(static fn (NationMembership $membership): array => [
                'membership_id' => (int) $membership->getAttribute('membership_id'),
                'world_id' => (int) $membership->world_id,
                'nation_id' => (int) $membership->nation_id,
            ])->all();
    }

    /** @return list<array{membership_id: int, world_id: int, nation_id: int}> */
    private function lockedCurrentOwnerMembershipSnapshot(int $userId): array
    {
        /** @var Collection<int, NationMembership> $memberships */
        $memberships = NationMembership::query()
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->orderBy('world_id')
            ->orderBy('nation_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $nationIds = $memberships->pluck('nation_id')->map(static fn (mixed $id): int => (int) $id)->all();
        /** @var Collection<int, Nation> $nations */
        $nations = $nationIds === []
            ? new Collection
            : Nation::query()->whereIn('id', $nationIds)->orderBy('world_id')->orderBy('id')->lockForUpdate()->get();
        $nationsById = $nations->keyBy('id');
        $snapshot = [];
        foreach ($memberships as $membership) {
            $nation = $nationsById->get($membership->nation_id);
            if (! $nation instanceof Nation || ! in_array($nation->state, ['active', 'dormant', 'recovery'], true)) {
                continue;
            }
            if ($nation->world_id !== $membership->world_id) {
                throw new SecretaryEquipmentConflictException(
                    'secretary_equipment_membership_changed',
                    '所属Worldの状態が変わりました。装備画面を再読込してください。',
                );
            }
            $snapshot[] = [
                'membership_id' => $membership->id,
                'world_id' => $membership->world_id,
                'nation_id' => $membership->nation_id,
            ];
        }

        return $snapshot;
    }

    /**
     * @param  Collection<int, SecretaryItemInstance>  $items
     * @return array<int, SecretaryItemInstance>
     */
    private function proposedState(Collection $items, int $targetSlot, ?SecretaryItemInstance $selected): array
    {
        $proposed = [];
        foreach ($items as $item) {
            if ($item->equipped_slot === null || $item->equipped_slot === $targetSlot) {
                continue;
            }
            if (isset($proposed[$item->equipped_slot])) {
                throw new SecretaryEquipmentValidationException('現在の装備状態を確認できません。');
            }
            $proposed[$item->equipped_slot] = $item;
        }
        if ($selected instanceof SecretaryItemInstance) {
            $proposed[$targetSlot] = $selected;
        }
        ksort($proposed, SORT_NUMERIC);

        return $proposed;
    }

    /** @param array<int, SecretaryItemInstance> $state */
    private function assertValidState(array $state): void
    {
        if (count($state) > SecretaryItemGrantService::EQUIPMENT_SLOT_COUNT) {
            throw new SecretaryEquipmentValidationException('装備slot数の上限を超えています。');
        }
        $instanceIds = [];
        $categoryCounts = [];
        $itemCounts = [];
        foreach ($state as $slot => $item) {
            $this->assertSlot($slot);
            if (isset($instanceIds[$item->id])) {
                throw new SecretaryEquipmentValidationException('同じアイテムを複数のslotへ装備できません。');
            }
            if ($item->is_escrowed) {
                throw new SecretaryEquipmentValidationException('交易場へ出品中のアイテムは装備できません。');
            }
            $instanceIds[$item->id] = true;
            try {
                $definition = $this->catalog->definition($item->item_key);
            } catch (DomainException $exception) {
                throw new SecretaryEquipmentValidationException('選択したアイテムは装備できません。', previous: $exception);
            }
            if ($item->level < 1 || $item->level > $definition['max_level']) {
                throw new SecretaryEquipmentValidationException('選択したアイテムは装備できません。');
            }
            $category = $definition['category'];
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            $itemCounts[$item->item_key] = ($itemCounts[$item->item_key] ?? 0) + 1;
            if ($categoryCounts[$category] > $this->catalog->maximumEquipped($category)) {
                throw new SecretaryEquipmentValidationException('このcategoryの装備上限を超えています。');
            }
            if ($itemCounts[$item->item_key] > $this->catalog->sameItemMaximum($item->item_key)) {
                throw new SecretaryEquipmentValidationException('同じ種類のアイテムの装備上限を超えています。');
            }
        }
    }

    private function assertSlot(int $slot): void
    {
        if ($slot < 1 || $slot > SecretaryItemGrantService::EQUIPMENT_SLOT_COUNT) {
            throw new SecretaryEquipmentValidationException('装備slotは1から5で指定してください。');
        }
    }

    /** @return array<string, mixed> */
    private function optionItem(
        SecretaryItemInstance $item,
        ?SecretaryItemEffectProjection $projection,
    ): array {
        $definition = $this->catalog->definition($item->item_key);

        return [
            'id' => $item->id,
            'key' => $item->item_key,
            'name' => $definition['name'],
            'level' => $item->level,
            'category' => $definition['category'],
            'category_label' => $definition['category_label'],
            'rarity' => $definition['rarity'],
            'rarity_label' => $definition['rarity_label'],
            'equipped_slot' => $item->equipped_slot,
            'effect_text' => $projection === null
                ? null
                : $this->itemGameplay->effectText(
                    $projection->rulesetSettings,
                    $item->item_key,
                    $item->level,
                ),
        ];
    }

    private function recordMutation(
        User $user,
        Secretary $secretary,
        int $slot,
        ?SecretaryItemInstance $previous,
        ?SecretaryItemInstance $next,
        int $previousVersion,
    ): void {
        $now = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'world_id' => null,
            'turn' => null,
            'nation_id' => null,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'secretary.equipment_changed',
            'severity' => 'info',
            'subject_type' => Secretary::class,
            'subject_id' => $secretary->id,
            'metadata' => json_encode([
                'secretary_id' => $secretary->id,
                'user_id' => $user->id,
                'target_slot' => $slot,
                'previous_item_id' => $previous?->id,
                'previous_item_key' => $previous?->item_key,
                'new_item_id' => $next?->id,
                'new_item_key' => $next?->item_key,
                'previous_equipment_version' => $previousVersion,
                'new_equipment_version' => $secretary->equipment_version,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
