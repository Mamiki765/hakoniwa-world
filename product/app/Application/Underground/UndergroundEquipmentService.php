<?php

namespace App\Application\Underground;

use App\Domain\Underground\Intro\UndergroundIntroStage;
use App\Models\Secretary;
use App\Models\UndergroundBattle;
use App\Models\UndergroundIntroProgress;
use App\Models\UndergroundIntroRequest;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final readonly class UndergroundEquipmentService
{
    /** @var list<string> */
    public const BULK_SELL_RARITY_KEYS = [
        'novice', 'regular', 'high_quality', 'artifact', 'relic', 'unique',
    ];

    /** @var list<string> */
    public const BULK_SELL_CATEGORY_KEYS = ['weapon', 'armor', 'accessory'];

    public function __construct(
        private UndergroundEquipmentCatalog $catalog,
        private UndergroundEquipmentLoadoutResolver $loadout,
        private UndergroundStarterEquipmentService $starter,
        private UndergroundAlphaV1PlayerCatalog $playerCatalog,
    ) {}

    /** @return array<string, mixed> */
    public function summary(User $user): array
    {
        return $this->withLockedOpenProfile(
            $user,
            fn (UndergroundProfile $profile): array => $this->loadout->summary($profile),
        );
    }

    /** @return array<string, mixed> */
    public function shop(User $user): array
    {
        return $this->withLockedOpenProfile($user, function (UndergroundProfile $profile): array {
            $owned = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->orderBy('id')
                ->get();
            $ownedKeys = $owned->pluck('definition_key')->all();
            $clearedTrials = UndergroundTrialProgress::query()
                ->where('underground_profile_id', $profile->id)
                ->whereNotNull('first_cleared_at')
                ->pluck('trial_key')
                ->all();
            $items = array_map(function (array $definition) use ($ownedKeys, $clearedTrials): array {
                $requiredTrial = $definition['required_trial_key'];

                return [
                    ...$definition,
                    'sell_price' => $this->catalog->sellPrice($definition),
                    'owned' => in_array($definition['key'], $ownedKeys, true),
                    'locked' => is_string($requiredTrial)
                        && ! in_array($requiredTrial, $clearedTrials, true),
                    'unlock_requirement' => $requiredTrial === 'trial_01'
                        ? '試練1を初回clear'
                        : null,
                ];
            }, $this->catalog->shopDefinitions());

            return [
                'catalog_identity' => $this->catalog->identity(),
                'currency_label' => '輝石の欠片 G',
                'shard_balance' => $profile->shard_balance,
                'banked_shard_balance' => $profile->banked_shard_balance,
                'bank_auto_withdraw' => false,
                'items' => $items,
                'owned_items' => $owned
                    ->map(fn (UndergroundOwnedEquipment $row): array => $this->loadout->projectOwned($row))
                    ->values()
                    ->all(),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function vault(User $user, int $page): array
    {
        if ($page < 1) {
            throw new UndergroundRuntimeException('underground_vault_page_invalid', '宝物庫のpageを確認してください。');
        }

        return $this->withLockedOpenProfile($user, function (UndergroundProfile $profile) use ($page): array {
            $perPage = $this->catalog->pageSize();
            $total = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->count();
            $lastPage = max(1, (int) ceil($total / $perPage));
            if ($page > $lastPage) {
                throw new UndergroundRuntimeException('underground_vault_page_invalid', '宝物庫のpageを確認してください。');
            }
            $items = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->orderByRaw('equipped_slot IS NULL')
                ->orderBy('equipped_slot')
                ->orderByDesc('acquired_at')
                ->orderByDesc('id')
                ->forPage($page, $perPage)
                ->get()
                ->map(fn (UndergroundOwnedEquipment $row): array => $this->loadout->projectOwned($row))
                ->values()
                ->all();

            return [
                ...$this->loadout->summary($profile),
                'catalog_identity' => $this->catalog->identity(),
                'bulk_sell_options' => $this->bulkSellOptions(),
                'items' => $items,
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'total' => $total,
            ];
        });
    }

    /**
     * @param  array<mixed>  $rarities
     * @param  array<mixed>  $categories
     * @param  array<mixed>  $weaponStyles
     * @return array<string, mixed>
     */
    public function bulkSellPreview(
        User $user,
        ?int $itemLevelMax,
        array $rarities,
        array $categories,
        array $weaponStyles,
    ): array {
        [$rarities, $categories, $weaponStyles] = $this->normalizeBulkSellFilters(
            $rarities,
            $categories,
            $weaponStyles,
        );

        return $this->withLockedOpenProfile($user, function (UndergroundProfile $profile) use (
            $itemLevelMax,
            $rarities,
            $categories,
            $weaponStyles,
        ): array {
            $items = UndergroundOwnedEquipment::query()
                ->where('underground_profile_id', $profile->id)
                ->orderByDesc('acquired_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (UndergroundOwnedEquipment $row): array => $this->loadout->projectOwned($row))
                ->filter(fn (array $item): bool => $this->matchesBulkSellFilters(
                    $item,
                    $itemLevelMax,
                    $rarities,
                    $categories,
                    $weaponStyles,
                ))
                ->values()
                ->all();
            $totalSellPrice = $this->sumSellPrices($items);

            return [
                'catalog_identity' => $this->catalog->identity(),
                'items' => $items,
                'count' => count($items),
                'total_sell_price' => $totalSellPrice,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function purchase(User $user, string $requestId, string $definitionKey): array
    {
        try {
            $definition = $this->catalog->definition($definitionKey);
        } catch (InvalidArgumentException) {
            throw new UndergroundRuntimeException('underground_equipment_not_sold', '装備ショップの商品を確認してください。');
        }
        if ($definition['shop_sold'] !== true || ! is_int($definition['buy_price'])) {
            throw new UndergroundRuntimeException('underground_equipment_not_sold', '装備ショップの商品を確認してください。');
        }

        return $this->mutate(
            $user,
            $requestId,
            'equipment_purchase',
            ['definition_key' => $definitionKey],
            function (UndergroundProfile $profile) use ($definition): void {
                $this->assertDefinitionUnlocked($profile, $definition);
                $alreadyOwned = UndergroundOwnedEquipment::query()
                    ->where('underground_profile_id', $profile->id)
                    ->where('definition_key', $definition['key'])
                    ->exists();
                if ($alreadyOwned) {
                    throw new UndergroundRuntimeException(
                        'underground_equipment_already_owned',
                        '現在所有している固定装備は重複購入できません。',
                    );
                }
                $used = UndergroundOwnedEquipment::query()
                    ->where('underground_profile_id', $profile->id)
                    ->count();
                if ($used >= $this->catalog->vaultCapacity()) {
                    throw new UndergroundRuntimeException('underground_vault_full', '宝物庫に空きがありません。');
                }
                if ($profile->shard_balance < $definition['buy_price']) {
                    throw new UndergroundRuntimeException(
                        'underground_equipment_insufficient_carried_shards',
                        '手持ちの輝石の欠片が不足しています。銀行からの自動引き出しは行いません。',
                    );
                }

                $profile->shard_balance -= $definition['buy_price'];
                $profile->save();
                UndergroundOwnedEquipment::query()->create([
                    'underground_profile_id' => $profile->id,
                    'definition_key' => $definition['key'],
                    'catalog_identity' => $this->catalog->identity(),
                    'equipped_slot' => null,
                    'grant_key' => null,
                    'instance_kind' => 'fixed',
                    'acquired_at' => Carbon::now(),
                ]);
            },
        );
    }

    /** @return array<string, mixed> */
    public function sell(User $user, string $requestId, int $itemId): array
    {
        if ($itemId < 1) {
            throw new UndergroundRuntimeException('underground_equipment_not_owned', '売却する装備を確認してください。');
        }

        return $this->mutate(
            $user,
            $requestId,
            'equipment_sell',
            ['item_id' => $itemId],
            function (UndergroundProfile $profile) use ($itemId): void {
                $item = UndergroundOwnedEquipment::query()
                    ->whereKey($itemId)
                    ->where('underground_profile_id', $profile->id)
                    ->lockForUpdate()
                    ->first();
                if (! $item instanceof UndergroundOwnedEquipment) {
                    throw new UndergroundRuntimeException('underground_equipment_not_owned', '売却する装備を確認してください。');
                }
                try {
                    $definition = $this->loadout->definitionForRow($item);
                } catch (RuntimeException) {
                    throw new UndergroundRuntimeException('underground_equipment_identity_invalid', '装備のidentityを確認できません。');
                }
                if ($definition['sellable'] !== true) {
                    throw new UndergroundRuntimeException('underground_equipment_not_sellable', 'この装備は通常売却できません。');
                }
                if ($item->equipped_slot !== null) {
                    throw new UndergroundRuntimeException('underground_equipment_equipped', '装備中のitemは外してから売却してください。');
                }
                $sellPrice = $this->catalog->sellPrice($definition);
                if ($profile->shard_balance > PHP_INT_MAX - $sellPrice) {
                    throw new UndergroundRuntimeException('underground_equipment_balance_overflow', '手持ち残高の上限を超えます。');
                }

                $item->delete();
                $profile->shard_balance += $sellPrice;
                $profile->save();
            },
        );
    }

    /**
     * @param  array<mixed>  $quotedItems
     * @return array<string, mixed>
     */
    public function bulkSell(
        User $user,
        string $requestId,
        string $catalogIdentity,
        array $quotedItems,
    ): array {
        $quotedItems = $this->normalizeBulkSellQuotes($quotedItems);

        return $this->mutate(
            $user,
            $requestId,
            'equipment_bulk_sell',
            ['catalog_identity' => $catalogIdentity, 'items' => $quotedItems],
            function (UndergroundProfile $profile) use ($catalogIdentity, $quotedItems): void {
                if ($catalogIdentity !== $this->catalog->identity()) {
                    throw new UndergroundRuntimeException(
                        'underground_bulk_sell_preview_changed',
                        '装備catalogが更新されています。売却候補をpreviewし直してください。',
                    );
                }
                $quotedById = [];
                foreach ($quotedItems as $quote) {
                    $quotedById[$quote['id']] = $quote['sell_price'];
                }
                $itemIds = array_keys($quotedById);
                $items = UndergroundOwnedEquipment::query()
                    ->where('underground_profile_id', $profile->id)
                    ->whereIn('id', $itemIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                if ($items->count() !== count($itemIds)) {
                    throw new UndergroundRuntimeException(
                        'underground_bulk_sell_preview_changed',
                        '売却候補の所有状態が変わりました。previewし直してください。',
                    );
                }

                $sellPrice = 0;
                foreach ($items as $item) {
                    if ($item->equipped_slot !== null) {
                        throw new UndergroundRuntimeException(
                            'underground_bulk_sell_preview_changed',
                            '売却候補に装備中のitemがあります。previewし直してください。',
                        );
                    }
                    try {
                        $definition = $this->loadout->definitionForRow($item);
                    } catch (RuntimeException) {
                        throw new UndergroundRuntimeException(
                            'underground_equipment_identity_invalid',
                            '装備のidentityを確認できません。',
                        );
                    }
                    if ($definition['sellable'] !== true) {
                        throw new UndergroundRuntimeException(
                            'underground_bulk_sell_preview_changed',
                            '通常売却できない装備が含まれています。previewし直してください。',
                        );
                    }
                    $canonicalPrice = $this->catalog->sellPrice($definition);
                    if ($canonicalPrice !== $quotedById[$item->id]) {
                        throw new UndergroundRuntimeException(
                            'underground_bulk_sell_preview_changed',
                            '売却価格が変わりました。previewし直してください。',
                        );
                    }
                    if ($sellPrice > PHP_INT_MAX - $canonicalPrice) {
                        throw new UndergroundRuntimeException(
                            'underground_equipment_balance_overflow',
                            '売却額の上限を超えます。',
                        );
                    }
                    $sellPrice += $canonicalPrice;
                }
                if ($profile->shard_balance > PHP_INT_MAX - $sellPrice) {
                    throw new UndergroundRuntimeException(
                        'underground_equipment_balance_overflow',
                        '手持ち残高の上限を超えます。',
                    );
                }

                $deleted = UndergroundOwnedEquipment::query()
                    ->where('underground_profile_id', $profile->id)
                    ->whereIn('id', $itemIds)
                    ->delete();
                if ($deleted !== count($itemIds)) {
                    throw new RuntimeException('Underground bulk equipment sale lost a locked item.');
                }
                $profile->shard_balance += $sellPrice;
                $profile->save();
            },
        );
    }

    /** @return array<string, mixed> */
    public function equip(User $user, string $requestId, int $itemId, ?string $targetSlot = null): array
    {
        if ($itemId < 1) {
            throw new UndergroundRuntimeException('underground_equipment_not_owned', '装備するitemを確認してください。');
        }

        $payload = ['item_id' => $itemId];
        if ($targetSlot !== null) {
            $payload['target_slot'] = $targetSlot;
        }

        return $this->mutate(
            $user,
            $requestId,
            'equipment_equip',
            $payload,
            function (UndergroundProfile $profile) use ($itemId, $targetSlot): void {
                $item = UndergroundOwnedEquipment::query()
                    ->whereKey($itemId)
                    ->where('underground_profile_id', $profile->id)
                    ->lockForUpdate()
                    ->first();
                if (! $item instanceof UndergroundOwnedEquipment) {
                    throw new UndergroundRuntimeException('underground_equipment_not_owned', '装備するitemを確認してください。');
                }
                try {
                    $definition = $this->loadout->definitionForRow($item);
                } catch (RuntimeException) {
                    throw new UndergroundRuntimeException('underground_equipment_identity_invalid', '装備のidentityを確認できません。');
                }
                $slot = $definition['category'] === 'accessory'
                    ? ($targetSlot ?? 'accessory_1')
                    : $definition['category'];
                if (! in_array($slot, UndergroundEquipmentCatalog::EQUIPPED_SLOTS, true)
                    || ($definition['category'] === 'accessory' && ! in_array($slot, UndergroundEquipmentCatalog::ACCESSORY_SLOTS, true))
                    || ($definition['category'] !== 'accessory' && $targetSlot !== null && $targetSlot !== $slot)) {
                    throw new UndergroundRuntimeException('underground_equipment_slot_invalid', '装備先slotを確認してください。');
                }
                $this->swapSlot($profile, $item, $slot);
            },
        );
    }

    /** @return array<string, mixed> */
    public function unequip(User $user, string $requestId, string $slot): array
    {
        $requestedSlot = $slot;
        if ($slot === 'accessory') {
            $slot = 'accessory_1';
        }
        if (! in_array($slot, ['armor', ...UndergroundEquipmentCatalog::ACCESSORY_SLOTS], true)) {
            throw new UndergroundRuntimeException('underground_equipment_slot_invalid', '武器は外せません。防具またはアクセサリーを指定してください。');
        }

        return $this->mutate(
            $user,
            $requestId,
            'equipment_unequip',
            ['slot' => $requestedSlot],
            function (UndergroundProfile $profile) use ($slot): void {
                $oldMaxHp = $this->currentMaxHp($profile);
                $currentHp = min($profile->current_hp ?? $oldMaxHp, $oldMaxHp);
                $equipped = UndergroundOwnedEquipment::query()
                    ->where('underground_profile_id', $profile->id)
                    ->where('equipped_slot', $slot)
                    ->lockForUpdate()
                    ->first();
                if ($equipped instanceof UndergroundOwnedEquipment) {
                    $equipped->equipped_slot = null;
                    $equipped->save();
                }
                $profile->current_hp = min($currentHp, $this->currentMaxHp($profile));
                $profile->save();
            },
        );
    }

    private function swapSlot(
        UndergroundProfile $profile,
        UndergroundOwnedEquipment $item,
        string $slot,
    ): void {
        $oldMaxHp = $this->currentMaxHp($profile);
        $currentHp = min($profile->current_hp ?? $oldMaxHp, $oldMaxHp);
        $current = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->where('equipped_slot', $slot)
            ->lockForUpdate()
            ->first();
        if ($current instanceof UndergroundOwnedEquipment && $current->id !== $item->id) {
            $current->equipped_slot = null;
            $current->save();
        }
        if ($item->equipped_slot !== $slot) {
            if ($item->equipped_slot !== null) {
                throw new UndergroundRuntimeException('underground_equipment_slot_invalid', '装備slotが一致しません。');
            }
            $item->equipped_slot = $slot;
            $item->save();
        }
        $profile->current_hp = min($currentHp, $this->currentMaxHp($profile));
        $profile->save();
    }

    private function currentMaxHp(UndergroundProfile $profile): int
    {
        return $this->playerCatalog->currentMaxHp(
            (string) $profile->growth_path_key,
            $profile->combat_level,
            $profile->allocatedStp(),
            $this->loadout->combatLoadout($profile),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(UndergroundProfile):void  $operation
     * @return array<string, mixed>
     */
    private function mutate(
        User $user,
        string $requestId,
        string $operationName,
        array $payload,
        callable $operation,
    ): array {
        if (! Str::isUuid($requestId)) {
            throw new UndergroundRuntimeException('underground_request_id_invalid', 'request IDを確認してください。');
        }
        $fingerprint = $this->fingerprint($operationName, $payload, $this->catalog->identity());

        return DB::transaction(function () use (
            $user,
            $requestId,
            $operationName,
            $payload,
            $fingerprint,
            $operation,
        ): array {
            [$profile, $intro] = $this->lockedOpenState($user);
            $previous = UndergroundIntroRequest::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($previous instanceof UndergroundIntroRequest) {
                $acceptedFingerprints = array_map(
                    fn (string $identity): string => $this->fingerprint($operationName, $payload, $identity),
                    $this->catalog->supportedIdentities(),
                );
                if (! in_array($previous->request_fingerprint, $acceptedFingerprints, true)) {
                    throw new UndergroundRuntimeException(
                        'underground_request_conflict',
                        '同じrequest IDが別の操作またはitemに使用されています。',
                    );
                }

                return $this->mutationState($profile->refresh(), $operationName);
            }
            $battle = UndergroundBattle::query()
                ->where('underground_profile_id', $profile->id)
                ->where('request_id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($battle instanceof UndergroundBattle) {
                throw new UndergroundRuntimeException('underground_request_conflict', '同じrequest IDが別の戦闘に使用されています。');
            }

            $operation($profile);
            UndergroundIntroRequest::query()->create([
                'underground_profile_id' => $profile->id,
                'request_id' => $requestId,
                'request_fingerprint' => $fingerprint,
                'operation' => $operationName,
                'resulting_stage' => $intro->stage,
                'underground_battle_id' => null,
            ]);

            return $this->mutationState($profile->refresh(), $operationName);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function mutationState(UndergroundProfile $profile, string $operation): array
    {
        return [
            'operation' => $operation,
            'shard_balance' => $profile->shard_balance,
            'banked_shard_balance' => $profile->banked_shard_balance,
            'vault' => $this->loadout->summary($profile),
        ];
    }

    /** @return array{UndergroundProfile, UndergroundIntroProgress} */
    private function lockedOpenState(User $user): array
    {
        $secretary = Secretary::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        if (! $secretary instanceof Secretary || $secretary->name === null) {
            throw new UndergroundRuntimeException('underground_secretary_missing', '名前のある秘書が必要です。');
        }
        $profile = UndergroundProfile::query()
            ->where('secretary_id', $secretary->id)
            ->lockForUpdate()
            ->first();
        if (! $profile instanceof UndergroundProfile) {
            throw new UndergroundRuntimeException('underground_equipment_locked', '装備ショップはまだ解禁されていません。');
        }
        $intro = UndergroundIntroProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->lockForUpdate()
            ->first();
        if (! $intro instanceof UndergroundIntroProgress
            || $intro->stage !== UndergroundIntroStage::UNDERGROUND_OPEN
            || $profile->growth_path_key === null) {
            throw new UndergroundRuntimeException('underground_equipment_locked', '装備ショップはまだ解禁されていません。');
        }
        $this->starter->reconcile($profile);

        return [$profile, $intro];
    }

    /**
     * @template T
     *
     * @param  callable(UndergroundProfile): T  $operation
     * @return T
     */
    private function withLockedOpenProfile(User $user, callable $operation): mixed
    {
        return DB::transaction(function () use ($user, $operation): mixed {
            [$profile] = $this->lockedOpenState($user);

            return $operation($profile);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(string $operation, array $payload, string $catalogIdentity): string
    {
        ksort($payload);
        try {
            $encoded = json_encode([
                'catalog_identity' => $catalogIdentity,
                'operation' => $operation,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Underground equipment request fingerprint failed.', previous: $exception);
        }

        return hash('sha256', $encoded);
    }

    /**
     * @return array{
     *   rarities: list<array{key: string, label: string}>,
     *   categories: list<array{key: string, label: string}>,
     *   weapon_styles: list<array{key: string, label: string}>
     * }
     */
    private function bulkSellOptions(): array
    {
        return [
            'rarities' => [
                ['key' => 'novice', 'label' => 'ノービス'],
                ['key' => 'regular', 'label' => 'レギュラー'],
                ['key' => 'high_quality', 'label' => 'ハイクオリティ'],
                ['key' => 'artifact', 'label' => 'アーティファクト'],
                ['key' => 'relic', 'label' => 'レリック'],
                ['key' => 'unique', 'label' => 'ユニーク'],
            ],
            'categories' => [
                ['key' => 'weapon', 'label' => '武器'],
                ['key' => 'armor', 'label' => '防具'],
                ['key' => 'accessory', 'label' => 'アクセサリー'],
            ],
            'weapon_styles' => $this->catalog->weaponStyleOptions(),
        ];
    }

    /**
     * @param  array<mixed>  $rarities
     * @param  array<mixed>  $categories
     * @param  array<mixed>  $weaponStyles
     * @return array{list<string>, list<string>, list<string>}
     */
    private function normalizeBulkSellFilters(
        array $rarities,
        array $categories,
        array $weaponStyles,
    ): array {
        return [
            $this->normalizeBulkSellFilterGroup($rarities, self::BULK_SELL_RARITY_KEYS, 'rarity'),
            $this->normalizeBulkSellFilterGroup($categories, self::BULK_SELL_CATEGORY_KEYS, 'category'),
            $this->normalizeBulkSellFilterGroup($weaponStyles, $this->catalog->weaponStyleKeys(), 'weapon style'),
        ];
    }

    /**
     * @param  array<mixed>  $selected
     * @param  list<string>  $allowed
     * @return list<string>
     */
    private function normalizeBulkSellFilterGroup(array $selected, array $allowed, string $label): array
    {
        if (! array_is_list($selected)) {
            throw new UndergroundRuntimeException(
                'underground_bulk_sell_filter_invalid',
                "まとめ売りの{$label}条件を確認してください。",
            );
        }
        $normalized = [];
        $seen = [];
        foreach ($selected as $value) {
            if (! is_string($value) || ! in_array($value, $allowed, true) || isset($seen[$value])) {
                throw new UndergroundRuntimeException(
                    'underground_bulk_sell_filter_invalid',
                    "まとめ売りの{$label}条件を確認してください。",
                );
            }
            $seen[$value] = true;
            $normalized[] = $value;
        }
        sort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  list<string>  $rarities
     * @param  list<string>  $categories
     * @param  list<string>  $weaponStyles
     */
    private function matchesBulkSellFilters(
        array $item,
        ?int $itemLevelMax,
        array $rarities,
        array $categories,
        array $weaponStyles,
    ): bool {
        if (($item['sellable'] ?? null) !== true || ($item['equipped_slot'] ?? null) !== null) {
            return false;
        }
        if ($itemLevelMax !== null && $item['item_level'] > $itemLevelMax) {
            return false;
        }
        if (! in_array($this->bulkSellRarityKey($item), $rarities, true)
            || ! in_array($item['category'], $categories, true)) {
            return false;
        }

        return $item['category'] !== 'weapon'
            || in_array($item['weapon_style'], $weaponStyles, true);
    }

    /** @param array<string, mixed> $item */
    private function bulkSellRarityKey(array $item): string
    {
        if (($item['instance_kind'] ?? null) === 'fixed') {
            return 'novice';
        }

        return match ($item['rarity'] ?? null) {
            'common' => 'regular',
            'uncommon' => 'high_quality',
            'rare' => 'artifact',
            'epic' => 'relic',
            default => throw new RuntimeException('Underground equipment rarity cannot be filtered.'),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function sumSellPrices(array $items): int
    {
        $total = 0;
        foreach ($items as $item) {
            $price = $item['sell_price'] ?? null;
            if (! is_int($price) || $price < 1 || $total > PHP_INT_MAX - $price) {
                throw new UndergroundRuntimeException(
                    'underground_equipment_balance_overflow',
                    '売却額の上限を超えます。',
                );
            }
            $total += $price;
        }

        return $total;
    }

    /**
     * @param  array<mixed>  $quotedItems
     * @return list<array{id: int, sell_price: int}>
     */
    private function normalizeBulkSellQuotes(array $quotedItems): array
    {
        if ($quotedItems === [] || ! array_is_list($quotedItems)
            || count($quotedItems) > $this->catalog->vaultCapacity()) {
            throw new UndergroundRuntimeException(
                'underground_bulk_sell_quote_invalid',
                'まとめ売りする装備を確認してください。',
            );
        }
        $normalized = [];
        $seen = [];
        foreach ($quotedItems as $quote) {
            if (! is_array($quote)) {
                throw new UndergroundRuntimeException(
                    'underground_bulk_sell_quote_invalid',
                    'まとめ売りする装備を確認してください。',
                );
            }
            $keys = array_keys($quote);
            sort($keys);
            $itemId = $quote['id'] ?? null;
            $sellPrice = $quote['sell_price'] ?? null;
            if ($keys !== ['id', 'sell_price']
                || ! is_int($itemId) || $itemId < 1
                || ! is_int($sellPrice) || $sellPrice < 1
                || isset($seen[$itemId])) {
                throw new UndergroundRuntimeException(
                    'underground_bulk_sell_quote_invalid',
                    'まとめ売りする装備を確認してください。',
                );
            }
            $seen[$itemId] = true;
            $normalized[] = ['id' => $itemId, 'sell_price' => $sellPrice];
        }
        usort($normalized, static fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $normalized;
    }

    /** @param array<string, mixed> $definition */
    private function assertDefinitionUnlocked(UndergroundProfile $profile, array $definition): void
    {
        $requiredTrial = $definition['required_trial_key'] ?? null;
        if ($requiredTrial === null) {
            return;
        }
        $cleared = UndergroundTrialProgress::query()
            ->where('underground_profile_id', $profile->id)
            ->where('trial_key', $requiredTrial)
            ->whereNotNull('first_cleared_at')
            ->exists();
        if (! $cleared) {
            throw new UndergroundRuntimeException(
                'underground_equipment_locked',
                'この装備は試練1の初回clear後に購入できます。',
            );
        }
    }
}
