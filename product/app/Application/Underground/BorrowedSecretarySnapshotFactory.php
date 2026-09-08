<?php

namespace App\Application\Underground;

use App\Application\SecretaryProfilePresenter;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\SecretaryLendingBuildSnapshot;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

/** Builds an immutable borrowed-member snapshot; it does not mutate either owner. */
final readonly class BorrowedSecretarySnapshotFactory
{
    private const BUILD_CACHE_IDENTITY = 'secretary-lending-build-cache-v1';

    public function __construct(
        private UndergroundAlphaV1PlayerCatalog $players,
        private UndergroundEquipmentCatalog $equipment,
        private UndergroundEquipmentLoadoutResolver $loadout,
        private UndergroundRuntimeEquipmentGenerator $generated,
        private SecretaryProfilePresenter $presenter,
        private UndergroundAwakening $awakening,
    ) {}

    /**
     * @param  array<array-key, mixed>  $leaderEquipmentItemLevels
     * @return array<string, mixed>
     */
    public function create(
        Secretary $secretary,
        UndergroundProfile $profile,
        int $leaderCombatLevel,
        array $leaderEquipmentItemLevels,
        User $viewer,
        bool $awakeningUnlocked,
    ): array {
        if ($leaderCombatLevel < 1 || $profile->secretary_id !== $secretary->id
            || ! is_string($profile->growth_path_key) || ! is_string($secretary->name) || $secretary->name === '') {
            throw new InvalidArgumentException('Borrowed Secretary snapshot input is invalid.');
        }
        $leaderEquipmentItemLevels = $this->validatedLeaderEquipmentItemLevels($leaderEquipmentItemLevels);
        $sourceSnapshot = $this->sourceSnapshot($secretary, $profile, $awakeningUnlocked);
        $originalLevel = $sourceSnapshot['original_combat_level'];
        if ($originalLevel < 1) {
            throw new InvalidArgumentException('Borrowed Secretary combat level is invalid.');
        }
        $effectiveLevel = min($originalLevel, $leaderCombatLevel);
        $growthPathKey = $sourceSnapshot['growth_path_key'];
        $originalAllocation = $this->normalizedAllocation($sourceSnapshot['allocated_stp']);
        $effectiveAllocation = $this->scaleAllocation($originalAllocation, $effectiveLevel, $growthPathKey);

        $originalItems = $sourceSnapshot['equipment'];
        $effectiveItems = [];
        foreach ($originalItems as $item) {
            $slot = $item['slot'];
            $definition = $item['payload'];
            $slotCap = $leaderEquipmentItemLevels[$slot] ?? null;
            if ($slotCap === null) {
                continue;
            }
            $effectiveItems[] = [
                'slot' => $slot,
                'instance_identity' => $item['instance_identity'],
                'catalog_identity' => $item['catalog_identity'],
                'definition' => $this->effectiveDefinition($definition, $slotCap),
            ];
        }
        if ($effectiveItems === []) {
            throw new RuntimeException('Borrowed Secretary requires an equipped weapon.');
        }
        $effectiveEquipment = $this->equipment->combatLoadout($effectiveItems);
        $definition = $this->players->explorationCombatDefinition(
            $growthPathKey,
            $effectiveLevel,
            $effectiveAllocation,
            $effectiveEquipment,
            $this->presenter->battleDisplayName($secretary),
            null,
            $sourceSnapshot['skill_allocations'],
            $sourceSnapshot['custom_ai_rules'],
        );
        $originalCurrentHp = $sourceSnapshot['current_hp'] ?? $definition['max_hp'];
        $effectiveCurrentHp = min(max(1, (int) $originalCurrentHp), (int) $definition['max_hp']);
        $definition['current_hp'] = $effectiveCurrentHp;
        $definition['player_snapshot']['current_hp'] = $effectiveCurrentHp;
        $awakening = $sourceSnapshot['awakening'];
        $growthPath = $this->players->growthPath($growthPathKey);
        $definition['player_snapshot']['awakening'] = $awakening;
        $definition['player_snapshot']['natural_recovery'] = $growthPath['natural_recovery'];

        return [
            'source' => [
                'secretary_id' => $secretary->id,
                'owner_game_id' => $sourceSnapshot['owner_game_id'],
            ],
            'display_name' => $this->presenter->battleDisplayName($secretary),
            'formal_name' => $secretary->name,
            'original_combat_level' => $originalLevel,
            'effective_combat_level' => $effectiveLevel,
            'equipment_sync' => [
                'authority' => 'leader_equipped_slot_item_level',
                'leader_item_levels' => $leaderEquipmentItemLevels,
                'missing_leader_slot' => 'unequipped_for_battle',
            ],
            'growth_path_key' => $growthPathKey,
            'growth_path_identity' => $sourceSnapshot['growth_path_identity'],
            'original_allocated_stp' => $originalAllocation,
            'effective_allocated_stp' => $effectiveAllocation,
            'resources' => [
                'original_current_hp' => (int) $originalCurrentHp,
                'effective_current_hp' => $effectiveCurrentHp,
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
            ],
            'original_equipment' => $originalItems,
            'effective_equipment' => $effectiveEquipment,
            'player_snapshot' => $definition['player_snapshot'],
            'active_skills' => $definition['active_skills'],
            'ai' => $definition['ai'],
            'image_references' => [
                'compact' => $this->presenter->resolveCompactImage($secretary, $viewer),
                'awakening_compact' => $this->presenter->resolveCompactImage($secretary, $viewer, true),
                'normal' => $this->presenter->resolveLargeImage($secretary, $viewer),
                'awakening' => $this->presenter->resolveLargeImage($secretary, $viewer, true),
            ],
            'awakening' => $awakening,
            'source_build_cache' => [
                'identity' => self::BUILD_CACHE_IDENTITY,
                'fingerprint' => $sourceSnapshot['fingerprint'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function sourceSnapshot(
        Secretary $secretary,
        UndergroundProfile $profile,
        bool $awakeningUnlocked,
    ): array {
        $ownedEquipment = $profile->relationLoaded('ownedEquipment')
            ? $profile->getRelation('ownedEquipment')
            : $profile->ownedEquipment()->orderBy('id')->get();
        if (! $ownedEquipment instanceof Collection) {
            throw new RuntimeException('Borrowed Secretary equipment relation is invalid.');
        }
        $skillAllocations = $profile->skillAllocationMap();
        ksort($skillAllocations);
        $ownerGameId = $secretary->user->visitor_code;
        if (! is_string($ownerGameId) || $ownerGameId === '') {
            throw new RuntimeException('Borrowed Secretary owner game identity is unavailable.');
        }
        $equippedSource = [];
        foreach ($ownedEquipment->whereNotNull('equipped_slot')->sortBy('id') as $row) {
            if (! $row instanceof UndergroundOwnedEquipment) {
                throw new RuntimeException('Borrowed Secretary equipment relation is invalid.');
            }
            $equippedSource[] = [
                'id' => $row->id,
                'slot' => $row->equipped_slot,
                'definition_key' => $row->definition_key,
                'catalog_identity' => $row->catalog_identity,
                'instance_kind' => $row->instance_kind,
                'instance_identity' => $row->instance_identity,
                'generator_identity' => $row->generator_identity,
                'generated_payload' => $row->generated_payload,
            ];
        }
        $fingerprintPayload = [
            'identity' => self::BUILD_CACHE_IDENTITY,
            'secretary' => [
                'id' => $secretary->id,
                'name' => $secretary->name,
                'nickname' => $secretary->nickname,
                'owner_game_id' => $ownerGameId,
            ],
            'profile' => [
                'combat_level' => $profile->combat_level,
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'allocated_stp' => $profile->allocatedStp(),
                'current_hp' => $profile->current_hp,
                'custom_ai_rules' => $profile->custom_ai_rules,
                'awakening_unlocked' => $awakeningUnlocked,
                'awakening_gauge' => $profile->awakening_gauge,
                'awakening_message' => $profile->awakening_message,
                'awakening_technique_key' => $profile->awakening_technique_key,
            ],
            'skill_allocations' => $skillAllocations,
            'equipped' => $equippedSource,
        ];
        $fingerprint = hash('sha256', json_encode(
            $fingerprintPayload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
        $cache = SecretaryLendingBuildSnapshot::query()
            ->where('secretary_id', $secretary->id)
            ->first();
        if ($cache !== null
            && $cache->build_identity === self::BUILD_CACHE_IDENTITY
            && hash_equals($cache->source_fingerprint, $fingerprint)) {
            return [...$cache->source_snapshot, 'fingerprint' => $fingerprint];
        }

        $equipment = [];
        foreach ($ownedEquipment->whereNotNull('equipped_slot')->sortBy('id') as $row) {
            if (! $row instanceof UndergroundOwnedEquipment) {
                throw new RuntimeException('Borrowed Secretary equipment relation is invalid.');
            }
            $equipment[] = [
                'slot' => $row->equipped_slot,
                'instance_identity' => $row->instance_identity,
                'catalog_identity' => $row->catalog_identity,
                'payload' => $this->loadout->definitionForRow($row),
            ];
        }
        $technique = $this->awakening->technique(
            $profile->growth_path_key,
            $profile->awakening_technique_key,
        );
        $snapshot = [
            'owner_game_id' => $ownerGameId,
            'original_combat_level' => $profile->combat_level,
            'growth_path_key' => $profile->growth_path_key,
            'growth_path_identity' => $profile->growth_path_identity,
            'allocated_stp' => $profile->allocatedStp(),
            'current_hp' => $profile->current_hp,
            'skill_allocations' => $skillAllocations,
            'custom_ai_rules' => $profile->custom_ai_rules,
            'equipment' => $equipment,
            'awakening' => [
                'unlocked' => $awakeningUnlocked,
                'gauge' => $awakeningUnlocked ? $profile->awakening_gauge : 0,
                'message' => $this->awakening->renderMessage($profile->awakening_message, $secretary->name),
                'technique_key' => $technique['key'],
                'growth_path' => $profile->growth_path_key,
            ],
        ];
        SecretaryLendingBuildSnapshot::query()->updateOrCreate(
            ['secretary_id' => $secretary->id],
            [
                'build_identity' => self::BUILD_CACHE_IDENTITY,
                'source_fingerprint' => $fingerprint,
                'source_snapshot' => $snapshot,
            ],
        );

        return [...$snapshot, 'fingerprint' => $fingerprint];
    }

    /**
     * @param  array<array-key, mixed>  $itemLevels
     * @return array<string, int>
     */
    private function validatedLeaderEquipmentItemLevels(array $itemLevels): array
    {
        $validated = [];
        foreach ($itemLevels as $slot => $itemLevel) {
            if (! is_string($slot)
                || ! in_array($slot, UndergroundEquipmentCatalog::EQUIPPED_SLOTS, true)
                || ! is_int($itemLevel)
                || $itemLevel < 1) {
                throw new InvalidArgumentException('Leader equipment sync input is invalid.');
            }
            $validated[$slot] = $itemLevel;
        }
        if (! isset($validated['weapon'])) {
            throw new InvalidArgumentException('Leader equipment sync requires an equipped weapon.');
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @return array<string, int>
     */
    private function normalizedAllocation(array $allocation): array
    {
        $normalized = [];
        foreach (AlphaV1CombatRules::STATS as $stat) {
            $value = $allocation[$stat] ?? null;
            if (! is_int($value) || $value < 0) {
                throw new RuntimeException('Borrowed Secretary STP snapshot is invalid.');
            }
            $normalized[$stat] = $value;
        }
        if (count($allocation) !== count($normalized)) {
            throw new RuntimeException('Borrowed Secretary STP snapshot is invalid.');
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $allocation
     * @return array<string, int>
     */
    private function scaleAllocation(array $allocation, int $effectiveLevel, string $growthPath): array
    {
        $effectiveEntitlement = $this->players->stpEntitlement($growthPath, $effectiveLevel);
        $total = array_sum($allocation);
        if ($total === 0 || $effectiveEntitlement >= $total) {
            return $allocation;
        }
        $target = min($total, $effectiveEntitlement);
        $result = [];
        $fractions = [];
        foreach (AlphaV1CombatRules::STATS as $stat) {
            $numerator = $allocation[$stat] * $target;
            $result[$stat] = intdiv($numerator, $total);
            $fractions[$stat] = $numerator % $total;
        }
        $remaining = $target - array_sum($result);
        foreach (array_keys($fractions) as $stat) {
            if ($remaining < 1) {
                break;
            }
            $best = $stat;
            foreach ($fractions as $candidate => $fraction) {
                if ($fraction > $fractions[$best]) {
                    $best = $candidate;
                }
            }
            $result[$best]++;
            $fractions[$best] = -1;
            $remaining--;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function effectiveDefinition(array $definition, int $itemLevelCap): array
    {
        $originalLevel = $definition['item_level'] ?? null;
        if (! is_int($originalLevel) || $originalLevel < 1) {
            throw new RuntimeException('Borrowed equipment item level is invalid.');
        }
        $effectiveLevel = min($originalLevel, $itemLevelCap);
        if (isset($definition['source']) && is_array($definition['source'])) {
            $source = $definition['source'];
            $tier = $source['tier_key'] ?? null;
            $seed = $source['seed'] ?? null;
            $sourceIdentity = $source['identity'] ?? null;
            $category = $definition['category'] ?? null;
            $rarity = $definition['rarity'] ?? null;
            $style = $definition['weapon_style'] ?? null;
            $mainStat = $category === 'accessory' ? ($this->mainStat($definition)) : null;
            if (! is_string($tier) || ! is_int($seed) || ! is_string($sourceIdentity)
                || ! is_string($category) || ! is_string($rarity)) {
                throw new RuntimeException('Borrowed generated equipment provenance is invalid.');
            }
            $effective = $this->generated->generate($effectiveLevel, $tier, $rarity, $category, $style, $mainStat, $seed, $sourceIdentity);

            return $effective;
        }
        $originalMainStat = ($definition['category'] ?? null) === 'accessory'
            ? $this->mainStat($definition)
            : null;
        $candidates = array_filter(
            $this->equipment->definitions(),
            fn (array $candidate): bool => $candidate['category'] === ($definition['category'] ?? null)
                && $candidate['weapon_style'] === ($definition['weapon_style'] ?? null)
                && (($definition['category'] ?? null) !== 'accessory'
                    || $this->mainStat($candidate) === $originalMainStat)
                && $candidate['item_level'] <= $effectiveLevel,
        );
        usort($candidates, static fn (array $a, array $b): int => $b['item_level'] <=> $a['item_level']);

        return $candidates[0] ?? throw new RuntimeException('No compatible fixed equipment exists at synced level.');
    }

    /** @param array<string, mixed> $definition */
    private function mainStat(array $definition): ?string
    {
        foreach (AlphaV1CombatRules::STATS as $stat) {
            if (($definition['base']['stats'][$stat] ?? 0) > 0) {
                return $stat;
            }
        }

        return null;
    }
}
