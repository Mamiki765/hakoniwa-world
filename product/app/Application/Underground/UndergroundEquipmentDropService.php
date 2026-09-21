<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\UndergroundRandom;
use App\Models\UndergroundBattle;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\UndergroundSkipBatch;
use App\Models\UndergroundSkipSettlement;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

final readonly class UndergroundEquipmentDropService
{
    public function __construct(
        private UndergroundAlphaV1PlayerCatalog $playerCatalog,
        private UndergroundRuntimeEquipmentGenerator $generator,
        private UndergroundEquipmentCatalog $equipmentCatalog,
    ) {}

    /**
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    public function settleVictory(
        UndergroundProfile $profile,
        UndergroundBattle $battle,
        string $huntingGroundKey,
        array $encounter,
        int $battleSeed,
        ?string $dropTierKey = null,
    ): array {
        if (! $battle->exists
            || $battle->underground_profile_id !== $profile->id
            || $battle->activity_type !== UndergroundBattle::ACTIVITY_EXPLORATION
            || $battle->activity_key !== $huntingGroundKey
            || $battle->result !== UndergroundBattle::RESULT_VICTORY) {
            throw new RuntimeException('Underground equipment drop settlement requires a persisted exploration victory.');
        }

        $this->playerCatalog->explorationHuntingGround($huntingGroundKey);
        $drop = $this->rollForTier(
            $dropTierKey ?? $huntingGroundKey,
            $encounter,
            $battleSeed,
            implode(':', [
                $this->playerCatalog->explorationDropConfig()['identity'],
                $huntingGroundKey,
                $battle->request_id,
            ]),
        );

        return $this->settleGeneratedDrop($profile, $battle, $drop, 'exploration-drop:'.$battle->request_id);
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return array<string, mixed>
     */
    public function settleTrialVictory(
        UndergroundProfile $profile,
        UndergroundBattle $battle,
        string $trialKey,
        string $tierKey,
        array $reward,
        int $battleSeed,
    ): array {
        if (! $battle->exists
            || $battle->underground_profile_id !== $profile->id
            || $battle->activity_type !== UndergroundBattle::ACTIVITY_TRIAL
            || $battle->activity_key !== $trialKey
            || $battle->result !== UndergroundBattle::RESULT_VICTORY) {
            throw new RuntimeException('Underground equipment drop settlement requires a persisted Trial victory.');
        }
        $drop = $this->rollForTier(
            $tierKey,
            $reward,
            $battleSeed,
            implode(':', [
                $this->playerCatalog->explorationDropConfig()['identity'],
                $trialKey,
                $battle->request_id,
            ]),
        );

        return $this->settleGeneratedDrop($profile, $battle, $drop, 'trial-drop:'.$battle->request_id);
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return array<string, mixed>
     */
    public function settleSkippedVictory(
        UndergroundProfile $profile,
        UndergroundSkipSettlement $settlement,
        string $tierKey,
        array $reward,
        int $rewardSeed,
        int $rewardIndex,
    ): array {
        if (! $settlement->exists
            || $settlement->underground_profile_id !== $profile->id
            || $rewardIndex < 1) {
            throw new RuntimeException('Underground equipment drop settlement requires a persisted skip settlement.');
        }
        $drop = $this->rollForTier(
            $tierKey,
            $reward,
            $rewardSeed,
            implode(':', [
                $this->playerCatalog->explorationDropConfig()['identity'],
                'skip',
                $settlement->content_type,
                $settlement->content_key,
                $settlement->request_id,
                $rewardIndex,
            ]),
        );

        return $this->settleGeneratedSkipDrop(
            $profile,
            $settlement,
            $rewardIndex,
            $drop,
            'skip-drop:'.$settlement->id.':'.$rewardIndex,
        );
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return array<string, mixed>
     */
    public function settleBulkSkippedVictory(
        UndergroundProfile $profile,
        UndergroundSkipBatch $batch,
        string $tierKey,
        array $reward,
        int $rewardSeed,
        int $rewardIndex,
        bool $vaultCapacityAvailable,
    ): array {
        if (! $batch->exists
            || $batch->underground_profile_id !== $profile->id
            || $rewardIndex < 1) {
            throw new RuntimeException('Underground equipment drop settlement requires a persisted bulk skip.');
        }
        $drop = $this->rollForTier(
            $tierKey,
            $reward,
            $rewardSeed,
            implode(':', [
                $this->playerCatalog->explorationDropConfig()['identity'],
                'bulk-skip',
                $batch->content_type,
                $batch->content_key,
                $batch->request_id,
                $rewardIndex,
            ]),
        );

        if ($drop['status'] === 'none') {
            return $drop;
        }
        if (! $vaultCapacityAvailable) {
            $payload = $drop['payload'] ?? null;
            if (! is_array($payload)) {
                throw new RuntimeException('Underground generated drop payload is missing.');
            }

            return [
                'identity' => $drop['identity'],
                'status' => 'vault_full',
                'item' => $this->itemSummary($payload),
            ];
        }

        return $this->persistGeneratedDrop(
            $profile,
            $drop,
            'bulk-skip-drop:'.$batch->id.':'.$rewardIndex,
            null,
            null,
            $batch->id,
            $rewardIndex,
            $batch->settled_at ?? Carbon::now(),
            false,
        );
    }

    public function remainingVaultCapacity(UndergroundProfile $profile): int
    {
        $used = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->count();

        return max(0, $this->equipmentCatalog->vaultCapacity() - $used);
    }

    /**
     * @param  array<string, mixed>  $drop
     * @return array<string, mixed>
     */
    private function settleGeneratedDrop(
        UndergroundProfile $profile,
        UndergroundBattle $battle,
        array $drop,
        string $grantKey,
    ): array {
        if ($drop['status'] === 'none') {
            return $drop;
        }

        return $this->persistGeneratedDrop(
            $profile,
            $drop,
            $grantKey,
            $battle->id,
            null,
            null,
            null,
            $battle->finished_at ?? Carbon::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $drop
     * @return array<string, mixed>
     */
    private function settleGeneratedSkipDrop(
        UndergroundProfile $profile,
        UndergroundSkipSettlement $settlement,
        int $rewardIndex,
        array $drop,
        string $grantKey,
    ): array {
        if ($drop['status'] === 'none') {
            return $drop;
        }

        return $this->persistGeneratedDrop(
            $profile,
            $drop,
            $grantKey,
            null,
            $settlement->id,
            null,
            $rewardIndex,
            $settlement->settled_at ?? Carbon::now(),
        );
    }

    /**
     * @param  array<string, mixed>  $drop
     * @return array<string, mixed>
     */
    private function persistGeneratedDrop(
        UndergroundProfile $profile,
        array $drop,
        string $grantKey,
        ?int $sourceBattleId,
        ?int $sourceSkipSettlementId,
        ?int $sourceSkipBatchId,
        ?int $sourceRewardIndex,
        CarbonInterface $acquiredAt,
        bool $checkVaultCapacity = true,
    ): array {
        $payload = $drop['payload'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Underground generated drop payload is missing.');
        }
        if ($checkVaultCapacity && $this->remainingVaultCapacity($profile) < 1) {
            return [
                'identity' => $drop['identity'],
                'status' => 'vault_full',
                'item' => $this->itemSummary($payload),
            ];
        }

        UndergroundOwnedEquipment::query()->create([
            'underground_profile_id' => $profile->id,
            'definition_key' => $payload['key'],
            'catalog_identity' => $this->equipmentCatalog->identity(),
            'equipped_slot' => null,
            'grant_key' => $grantKey,
            'instance_kind' => 'generated',
            'instance_identity' => $payload['instance_identity'],
            'generator_identity' => $payload['generator_identity'],
            'generated_payload' => $payload,
            'source_battle_id' => $sourceBattleId,
            'source_skip_settlement_id' => $sourceSkipSettlementId,
            'source_skip_batch_id' => $sourceSkipBatchId,
            'source_reward_index' => $sourceRewardIndex,
            'acquired_at' => $acquiredAt,
        ]);

        return [
            'identity' => $drop['identity'],
            'status' => 'granted',
            'item' => $this->itemSummary($payload),
        ];
    }

    /**
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    public function roll(
        string $huntingGroundKey,
        array $encounter,
        int $battleSeed,
        string $sourceIdentity,
    ): array {
        $this->playerCatalog->explorationHuntingGround($huntingGroundKey);

        return $this->rollForTier($huntingGroundKey, $encounter, $battleSeed, $sourceIdentity);
    }

    /**
     * @param  array<string, mixed>  $encounter
     * @return array<string, mixed>
     */
    private function rollForTier(
        string $tierKey,
        array $encounter,
        int $battleSeed,
        string $sourceIdentity,
    ): array {
        $drop = $this->playerCatalog->explorationDropConfig();
        $profileKey = $encounter['drop_profile'] ?? null;
        $itemLevelMin = $encounter['item_level_min'] ?? null;
        $itemLevelMax = $encounter['item_level_max'] ?? null;
        $profile = is_string($profileKey) ? ($drop['profiles'][$profileKey] ?? null) : null;
        if (! is_array($profile)
            || ! is_int($itemLevelMin) || ! is_int($itemLevelMax)
            || $itemLevelMin < 1 || $itemLevelMax < $itemLevelMin
            || $itemLevelMax > $this->equipmentCatalog->generatorItemLevelMax()) {
            throw new RuntimeException('Underground encounter drop metadata is invalid.');
        }

        $random = new UndergroundRandom($battleSeed);
        if ($random->integer('drop:presence', 1, 10_000) > $profile['presence_bps']) {
            return ['identity' => $drop['identity'], 'status' => 'none'];
        }
        $rarity = $this->weightedKey(
            $profile['rarity_weights'],
            $random->integer('drop:rarity', 1, 10_000),
            'rarity',
        );
        $itemLevel = $random->integer('drop:item_level', $itemLevelMin, $itemLevelMax);
        $category = $this->weightedKey(
            $drop['category_weights'],
            $random->integer('drop:category', 1, 10_000),
            'category',
        );
        $weaponStyle = $category === 'weapon'
            ? $drop['weapon_styles'][$random->integer(
                'drop:category:weapon_style',
                0,
                count($drop['weapon_styles']) - 1,
            )]
            : null;
        $mainStat = $category === 'accessory'
            ? $drop['accessory_main_stats'][$random->integer(
                'drop:category:accessory_main_stat',
                0,
                count($drop['accessory_main_stats']) - 1,
            )]
            : null;
        $affixSeed = $random->integer('drop:affix', 0, 2_147_483_647);
        $payload = $this->generator->generate(
            $itemLevel,
            $tierKey,
            $rarity,
            $category,
            is_string($weaponStyle) ? $weaponStyle : null,
            is_string($mainStat) ? $mainStat : null,
            $affixSeed,
            $sourceIdentity,
        );
        $this->equipmentCatalog->assertDefinition($payload, true);

        return [
            'identity' => $drop['identity'],
            'status' => 'generated',
            'payload' => $payload,
        ];
    }

    /** @param array<mixed, mixed> $weights */
    private function weightedKey(array $weights, int $roll, string $domain): string
    {
        if ($roll < 1 || $roll > 10_000 || array_sum($weights) !== 10_000) {
            throw new RuntimeException("Underground equipment drop {$domain} weights are invalid.");
        }
        $upper = 0;
        foreach ($weights as $key => $weight) {
            if (! is_string($key) || ! is_int($weight) || $weight < 0) {
                throw new RuntimeException("Underground equipment drop {$domain} weights are invalid.");
            }
            $upper += $weight;
            if ($roll <= $upper) {
                return $key;
            }
        }

        throw new RuntimeException("Underground equipment drop {$domain} roll is invalid.");
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function itemSummary(array $payload): array
    {
        return [
            'instance_identity' => $payload['instance_identity'],
            'name' => $payload['name'],
            'category' => $payload['category'],
            'item_level' => $payload['item_level'],
            'rarity' => $payload['rarity'],
            'rarity_label' => $payload['rarity_label'],
            'affixes' => array_map(
                static fn (array $affix): array => [
                    'key' => $affix['key'],
                    'label' => $affix['label'],
                    'target' => $affix['target'],
                    'value' => $affix['value'],
                ],
                $payload['affixes'],
            ),
        ];
    }
}
