<?php

namespace App\Application\Underground;

use App\Domain\Underground\Combat\UndergroundRandom;
use App\Models\UndergroundBattle;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
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
    ): array {
        if (! $battle->exists
            || $battle->underground_profile_id !== $profile->id
            || $battle->activity_type !== UndergroundBattle::ACTIVITY_EXPLORATION
            || $battle->activity_key !== $huntingGroundKey
            || $battle->result !== UndergroundBattle::RESULT_VICTORY) {
            throw new RuntimeException('Underground equipment drop settlement requires a persisted exploration victory.');
        }

        $drop = $this->roll(
            $huntingGroundKey,
            $encounter,
            $battleSeed,
            implode(':', [
                $this->playerCatalog->explorationDropConfig()['identity'],
                $huntingGroundKey,
                $battle->request_id,
            ]),
        );
        if ($drop['status'] === 'none') {
            return $drop;
        }

        $payload = $drop['payload'] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException('Underground generated drop payload is missing.');
        }
        $used = UndergroundOwnedEquipment::query()
            ->where('underground_profile_id', $profile->id)
            ->count();
        if ($used >= $this->equipmentCatalog->vaultCapacity()) {
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
            'grant_key' => 'exploration-drop:'.$battle->request_id,
            'instance_kind' => 'generated',
            'instance_identity' => $payload['instance_identity'],
            'generator_identity' => $payload['generator_identity'],
            'generated_payload' => $payload,
            'source_battle_id' => $battle->id,
            'acquired_at' => $battle->finished_at ?? Carbon::now(),
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
        $drop = $this->playerCatalog->explorationDropConfig();
        $profileKey = $encounter['drop_profile'] ?? null;
        $itemLevelMin = $encounter['item_level_min'] ?? null;
        $itemLevelMax = $encounter['item_level_max'] ?? null;
        $profile = is_string($profileKey) ? ($drop['profiles'][$profileKey] ?? null) : null;
        if (! is_array($profile)
            || ! is_int($itemLevelMin) || ! is_int($itemLevelMax)
            || $itemLevelMin < 1 || $itemLevelMax < $itemLevelMin || $itemLevelMax > 60) {
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
            $huntingGroundKey,
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
