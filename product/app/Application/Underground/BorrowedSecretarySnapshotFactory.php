<?php

namespace App\Application\Underground;

use App\Application\SecretaryProfilePresenter;
use App\Domain\Underground\Combat\AlphaV1CombatRules;
use App\Domain\Underground\Combat\PriorityCombatAiConfiguration;
use App\Domain\Underground\Combat\UndergroundAwakening;
use App\Models\Secretary;
use App\Models\SecretaryLendingBuildSnapshot;
use App\Models\UndergroundOwnedEquipment;
use App\Models\UndergroundProfile;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use RuntimeException;

/** Builds an immutable borrowed-member snapshot; it does not mutate either owner. */
final readonly class BorrowedSecretarySnapshotFactory
{
    private const BUILD_CACHE_IDENTITY = 'secretary-lending-build-cache-v1';

    private const PROJECTION_CACHE_IDENTITY = 'secretary-lending-build-projection-v1';

    private const PROJECTION_CACHE_SCHEMA_VERSION = 2;

    public function __construct(
        private UndergroundAlphaV1PlayerCatalog $players,
        private UndergroundEquipmentCatalog $equipment,
        private UndergroundEquipmentLoadoutResolver $loadout,
        private UndergroundRuntimeEquipmentGenerator $generated,
        private SecretaryProfilePresenter $presenter,
        private UndergroundAwakening $awakening,
        private PriorityCombatAiConfiguration $aiConfiguration = new PriorityCombatAiConfiguration,
        /** @var (Closure(string): void)|null $projectionCallObserver */
        private ?Closure $projectionCallObserver = null,
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
        $sourceFingerprint = $sourceSnapshot['numeric_fingerprint'];
        $behaviorFingerprint = $sourceSnapshot['behavior_fingerprint'];
        if (! is_string($sourceFingerprint) || ! is_string($behaviorFingerprint)) {
            throw new RuntimeException('Borrowed Secretary source fingerprint is invalid.');
        }

        $cache = SecretaryLendingBuildSnapshot::query()
            ->where('secretary_id', $secretary->id)
            ->first();
        $originalEquipment = $this->sourceEquipment($sourceSnapshot, $cache, $sourceFingerprint);
        $sourceSnapshot['equipment'] = $originalEquipment;
        $sourceCacheMatches = $cache !== null
            && $cache->build_identity === self::BUILD_CACHE_IDENTITY
            && hash_equals($cache->source_fingerprint, $sourceFingerprint);
        $sourceSnapshotNeedsWrite = $this->cachedSourceEquipment($cache, $sourceFingerprint) === null;

        $originalLevel = $sourceSnapshot['original_combat_level'];
        if (! is_int($originalLevel) || $originalLevel < 1) {
            throw new InvalidArgumentException('Borrowed Secretary combat level is invalid.');
        }
        $effectiveLevel = min($originalLevel, $leaderCombatLevel);
        $growthPathKey = $sourceSnapshot['growth_path_key'];
        if (! is_string($growthPathKey) || $growthPathKey === '') {
            throw new RuntimeException('Borrowed Secretary growth path is invalid.');
        }
        $originalAllocation = $this->normalizedAllocation($sourceSnapshot['allocated_stp']);
        $rulesIdentity = $this->rulesIdentity($profile);
        $dependencies = [
            'source_fingerprint' => $sourceFingerprint,
            'leader_combat_level' => $leaderCombatLevel,
            'effective_combat_level' => $effectiveLevel,
            'leader_item_levels' => $leaderEquipmentItemLevels,
            'rules' => $rulesIdentity,
        ];
        $projectionKey = $this->fingerprint($dependencies);
        $projectionCache = $this->normalizedProjectionCache($cache?->projection_cache);
        if (! $sourceCacheMatches) {
            // Source changes invalidate aggregate numeric projections. Slot entries remain reusable.
            $projectionCache['projections'] = [];
        }
        $projection = $this->cachedProjection($projectionCache, $projectionKey);
        $cacheNeedsWrite = $sourceSnapshotNeedsWrite || ! $sourceCacheMatches;

        if ($projection === null) {
            $effectiveAllocation = $this->scaleAllocation(
                $originalAllocation,
                $effectiveLevel,
                $growthPathKey,
            );
            $effectiveItems = $this->effectiveItems(
                $originalEquipment,
                $leaderEquipmentItemLevels,
                $rulesIdentity,
                $projectionCache,
            );
            if ($effectiveItems === []) {
                throw new RuntimeException('Borrowed Secretary requires an equipped weapon.');
            }
            $this->observeProjectionCall('combat_loadout');
            $effectiveEquipment = $this->equipment->combatLoadout($effectiveItems);
            $this->observeProjectionCall('combat_definition');
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
            $projection = [
                'source_fingerprint' => $sourceFingerprint,
                'dependencies' => $dependencies,
                'effective_allocated_stp' => $effectiveAllocation,
                'effective_equipment' => $effectiveEquipment,
                'definition' => $this->serializableDefinition($definition),
                'behavior_fingerprint' => $behaviorFingerprint,
            ];
            $projectionCache['projections'][$projectionKey] = $projection;
            $projectionCache['projections'] = $this->boundedMap($projectionCache['projections'], 32);
            $cacheNeedsWrite = true;
        } else {
            $cachedAllocation = $projection['effective_allocated_stp'] ?? null;
            $cachedEquipment = $projection['effective_equipment'] ?? null;
            $cachedDefinition = $projection['definition'] ?? null;
            if (! is_array($cachedAllocation)
                || ! is_array($cachedEquipment)
                || ! is_array($cachedDefinition)) {
                throw new RuntimeException('Borrowed Secretary numeric projection is invalid.');
            }
            $effectiveAllocation = $this->normalizedAllocation($cachedAllocation);
            $effectiveEquipment = $this->validatedEffectiveEquipment($cachedEquipment);
            if (($projection['behavior_fingerprint'] ?? null) !== $behaviorFingerprint) {
                // AI changes affect the next battle's behavior. Reuse numeric/equipment values and
                // use the canonical skill/AI projection without resolving stats again.
                $definition = $this->behaviorDefinition(
                    $cachedDefinition,
                    $effectiveEquipment,
                    $sourceSnapshot['skill_allocations'],
                    $sourceSnapshot['custom_ai_rules'],
                );
                $projection['definition'] = $this->serializableDefinition($definition);
                $projection['behavior_fingerprint'] = $behaviorFingerprint;
                $projectionCache['projections'][$projectionKey] = $projection;
                $cacheNeedsWrite = true;
            } else {
                $definition = $cachedDefinition;
            }
        }

        if (! is_int($definition['max_hp'] ?? null)
            || ! is_array($definition['player_snapshot'] ?? null)
            || ! is_array($definition['active_skills'] ?? null)
            || ! is_array($definition['ai'] ?? null)) {
            throw new RuntimeException('Borrowed Secretary numeric projection is invalid.');
        }
        $originalCurrentHp = $sourceSnapshot['current_hp'] ?? $definition['max_hp'];
        if (! is_int($originalCurrentHp)) {
            $originalCurrentHp = (int) $originalCurrentHp;
        }
        $effectiveCurrentHp = min(max(1, $originalCurrentHp), $definition['max_hp']);
        $definition['current_hp'] = $effectiveCurrentHp;
        $definition['player_snapshot']['current_hp'] = $effectiveCurrentHp;
        $definition['player_snapshot']['label'] = $this->presenter->battleDisplayName($secretary);
        $awakening = $sourceSnapshot['awakening'];
        $growthPath = $this->players->growthPath($growthPathKey);
        $definition['player_snapshot']['awakening'] = $awakening;
        $definition['player_snapshot']['natural_recovery'] = $growthPath['natural_recovery'];

        if ($cacheNeedsWrite) {
            $this->persistCache($secretary, $sourceSnapshot, $originalEquipment, $projectionCache);
        }

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
                'original_current_hp' => $originalCurrentHp,
                'effective_current_hp' => $effectiveCurrentHp,
                'effective_max_hp' => $definition['max_hp'],
                'battle_start_mp' => AlphaV1CombatRules::MAX_MP,
            ],
            'original_equipment' => $originalEquipment,
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
                'fingerprint' => $sourceFingerprint,
                'projection_key' => $projectionKey,
            ],
        ];
    }

    /**
     * Collects only the current source inputs. The full equipment definitions are deferred until
     * a numeric projection misses, so display-only changes do not rebuild the combat build.
     *
     * @return array<string, mixed>
     */
    private function sourceSnapshot(
        Secretary $secretary,
        UndergroundProfile $profile,
        bool $awakeningUnlocked,
    ): array {
        $ownedEquipment = $profile->relationLoaded('ownedEquipment')
            ? $profile->getRelation('ownedEquipment')
            : $profile->ownedEquipment()->whereNotNull('equipped_slot')->orderBy('id')->get();
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
                'polish_level' => $row->polish_level,
            ];
        }
        $skillTreeIdentity = $profile->skill_tree_identity;
        if (! is_string($skillTreeIdentity) || $skillTreeIdentity === '') {
            // Older supported profiles can predate the explicit identity column. Keep the
            // canonical catalog identity in the cache key rather than rejecting an otherwise
            // valid lending profile.
            $skillTreeIdentity = $this->players->skillTreeIdentity();
        }
        $rulesIdentity = $this->rulesIdentity($profile);
        $fingerprintPayload = [
            'identity' => self::BUILD_CACHE_IDENTITY,
            'rules' => $rulesIdentity,
            'profile' => [
                'combat_level' => $profile->combat_level,
                'growth_path_key' => $profile->growth_path_key,
                'growth_path_identity' => $profile->growth_path_identity,
                'skill_tree_identity' => $skillTreeIdentity,
                'allocated_stp' => $profile->allocatedStp(),
            ],
            'skill_allocations' => $skillAllocations,
            'equipped' => $equippedSource,
        ];
        $behaviorFingerprint = $this->fingerprint([
            'identity' => self::BUILD_CACHE_IDENTITY,
            'skill_allocations' => $skillAllocations,
            'custom_ai_rules' => $profile->custom_ai_rules,
        ]);
        $technique = $this->awakening->technique(
            (string) $profile->growth_path_key,
            $profile->awakening_technique_key,
        );

        return [
            'owner_game_id' => $ownerGameId,
            'original_combat_level' => $profile->combat_level,
            'growth_path_key' => $profile->growth_path_key,
            'growth_path_identity' => $profile->growth_path_identity,
            'skill_tree_identity' => $skillTreeIdentity,
            'allocated_stp' => $profile->allocatedStp(),
            'current_hp' => $profile->current_hp,
            'skill_allocations' => $skillAllocations,
            'custom_ai_rules' => $profile->custom_ai_rules,
            'equipment_source' => $equippedSource,
            // Internal-only relation; it is removed before persisting the JSON snapshot.
            'equipment_rows' => $ownedEquipment,
            'awakening' => [
                'unlocked' => $awakeningUnlocked,
                'gauge' => $awakeningUnlocked ? $profile->awakening_gauge : 0,
                'message' => $this->awakening->renderMessage($profile->awakening_message, $secretary->name),
                'technique_key' => $technique['key'],
                'growth_path' => $profile->growth_path_key,
            ],
            'numeric_fingerprint' => $this->fingerprint($fingerprintPayload),
            'behavior_fingerprint' => $behaviorFingerprint,
        ];
    }

    /**
     * @param  array<string, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function sourceEquipment(
        array $source,
        ?SecretaryLendingBuildSnapshot $cache,
        string $sourceFingerprint,
    ): array {
        $cached = $this->cachedSourceEquipment($cache, $sourceFingerprint);
        if ($cached !== null) {
            return $cached;
        }
        $rows = $source['equipment_rows'] ?? null;
        if (! $rows instanceof Collection) {
            throw new RuntimeException('Borrowed Secretary equipment relation is invalid.');
        }
        $equipment = [];
        foreach ($rows->whereNotNull('equipped_slot')->sortBy('id') as $row) {
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

        return $equipment;
    }

    /** @return list<array<string, mixed>>|null */
    private function cachedSourceEquipment(
        ?SecretaryLendingBuildSnapshot $cache,
        string $sourceFingerprint,
    ): ?array {
        if ($cache === null
            || $cache->build_identity !== self::BUILD_CACHE_IDENTITY
            || ! hash_equals($cache->source_fingerprint, $sourceFingerprint)) {
            return null;
        }
        $equipment = $cache->source_snapshot['equipment'] ?? null;
        if (! is_array($equipment) || ! array_is_list($equipment)) {
            return null;
        }
        foreach ($equipment as $index => $item) {
            if (! is_array($item)
                || ! is_string($item['slot'] ?? null)
                || ! is_array($item['payload'] ?? null)) {
                return null;
            }
            try {
                $equipment[$index] = $this->orderedKeys($item, [
                    'slot', 'instance_identity', 'catalog_identity', 'payload',
                ]);
                $equipment[$index]['payload'] = $this->normalizedItemDefinition($item['payload']);
            } catch (RuntimeException) {
                return null;
            }
        }

        return $equipment;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<array<string, mixed>>  $equipment
     * @param  array<string, mixed>  $projectionCache
     */
    private function persistCache(
        Secretary $secretary,
        array $source,
        array $equipment,
        array $projectionCache,
    ): void {
        $snapshot = $source;
        unset($snapshot['equipment_rows'], $snapshot['equipment_source']);
        $snapshot['equipment'] = $equipment;
        $sourceFingerprint = $source['numeric_fingerprint'] ?? null;
        if (! is_string($sourceFingerprint)) {
            throw new RuntimeException('Borrowed Secretary source fingerprint is invalid.');
        }
        // A lender can be used by more than one leader at the same time. Use a single
        // PostgreSQL upsert for the unique secretary key so a cold cache does not turn the
        // second concurrent battle into a unique-constraint failure.
        SecretaryLendingBuildSnapshot::query()->upsert(
            [[
                'secretary_id' => $secretary->id,
                'build_identity' => self::BUILD_CACHE_IDENTITY,
                'source_fingerprint' => $sourceFingerprint,
                'source_snapshot' => json_encode(
                    $snapshot,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
                'projection_cache' => json_encode(
                    $projectionCache,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ),
            ]],
            ['secretary_id'],
            ['build_identity', 'source_fingerprint', 'source_snapshot', 'projection_cache'],
        );
    }

    /**
     * @param  array<string, mixed>|null  $cache
     * @return array<string, mixed>
     */
    private function normalizedProjectionCache(?array $cache): array
    {
        if (! is_array($cache)
            || ($cache['identity'] ?? null) !== self::PROJECTION_CACHE_IDENTITY
            || ($cache['schema_version'] ?? null) !== self::PROJECTION_CACHE_SCHEMA_VERSION
            || ! is_array($cache['slots'] ?? null)
            || ! is_array($cache['projections'] ?? null)) {
            return [
                'identity' => self::PROJECTION_CACHE_IDENTITY,
                'schema_version' => self::PROJECTION_CACHE_SCHEMA_VERSION,
                'slots' => [],
                'projections' => [],
            ];
        }

        return [
            'identity' => self::PROJECTION_CACHE_IDENTITY,
            'schema_version' => self::PROJECTION_CACHE_SCHEMA_VERSION,
            'slots' => $cache['slots'],
            'projections' => $cache['projections'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cache
     * @return array<string, mixed>|null
     */
    private function cachedProjection(array $cache, string $key): ?array
    {
        $projection = $cache['projections'][$key] ?? null;
        if (! is_array($projection)
            || ! is_array($projection['effective_allocated_stp'] ?? null)
            || ! is_array($projection['effective_equipment'] ?? null)
            || ! is_array($projection['definition'] ?? null)
            || ! is_int($projection['effective_equipment']['max_hp'] ?? null)
            || ! is_int($projection['effective_equipment']['item_level'] ?? null)
            || ! is_array($projection['effective_equipment']['items'] ?? null)
            || ! is_int($projection['definition']['max_hp'] ?? null)
            || ! is_array($projection['definition']['player_snapshot'] ?? null)
            || ! is_array($projection['definition']['active_skills'] ?? null)
            || ! is_array($projection['definition']['ai'] ?? null)) {
            return null;
        }

        try {
            $projection['effective_equipment'] = $this->validatedEffectiveEquipment(
                $projection['effective_equipment'],
            );
            $projection['definition'] = $this->normalizedCombatDefinition($projection['definition']);
        } catch (RuntimeException) {
            return null;
        }

        return $projection;
    }

    /**
     * @param  list<array<string, mixed>>  $originalEquipment
     * @param  array<string, int>  $leaderEquipmentItemLevels
     * @param  array<string, string>  $rulesIdentity
     * @param  array<string, mixed>  $projectionCache
     * @return list<array<string, mixed>>
     */
    private function effectiveItems(
        array $originalEquipment,
        array $leaderEquipmentItemLevels,
        array $rulesIdentity,
        array &$projectionCache,
    ): array {
        $effectiveItems = [];
        foreach ($originalEquipment as $item) {
            $slot = $item['slot'] ?? null;
            $definition = $item['payload'] ?? null;
            if (! is_string($slot) || ! is_array($definition)) {
                throw new RuntimeException('Borrowed Secretary equipment snapshot is invalid.');
            }
            $slotCap = $leaderEquipmentItemLevels[$slot] ?? null;
            if ($slotCap === null) {
                continue;
            }
            $itemFingerprint = $this->fingerprint([
                'slot' => $slot,
                'instance_identity' => $item['instance_identity'] ?? null,
                'catalog_identity' => $item['catalog_identity'] ?? null,
                'payload' => $definition,
            ]);
            $slotKey = $this->fingerprint([
                'identity' => self::PROJECTION_CACHE_IDENTITY,
                'slot' => $slot,
                'item_fingerprint' => $itemFingerprint,
                'item_level_cap' => $slotCap,
                'rules' => [
                    'combat' => $rulesIdentity['combat'],
                    'equipment_catalog' => $rulesIdentity['equipment_catalog'],
                    'equipment_generator' => $rulesIdentity['equipment_generator'],
                ],
            ]);
            $slotProjection = $projectionCache['slots'][$slotKey] ?? null;
            $cachedDefinition = is_array($slotProjection)
                && ($slotProjection['item_fingerprint'] ?? null) === $itemFingerprint
                && ($slotProjection['item_level_cap'] ?? null) === $slotCap
                && is_array($slotProjection['definition'] ?? null)
                ? $slotProjection['definition']
                : null;
            $effectiveDefinition = null;
            if (is_array($cachedDefinition)) {
                try {
                    $effectiveDefinition = $this->normalizedItemDefinition($cachedDefinition);
                } catch (RuntimeException) {
                    $effectiveDefinition = null;
                }
            }
            if ($effectiveDefinition === null) {
                $this->observeProjectionCall(
                    is_array($definition['source'] ?? null)
                        ? 'equipment_generator'
                        : 'equipment_catalog_projection',
                );
                $effectiveDefinition = $this->effectiveDefinition($definition, $slotCap);
            }
            $projectionCache['slots'][$slotKey] = [
                'item_fingerprint' => $itemFingerprint,
                'item_level_cap' => $slotCap,
                'definition' => $effectiveDefinition,
            ];
            $effectiveItems[] = [
                'slot' => $slot,
                'instance_identity' => $item['instance_identity'] ?? null,
                'catalog_identity' => $item['catalog_identity'] ?? null,
                'definition' => $effectiveDefinition,
            ];
        }
        $projectionCache['slots'] = $this->boundedMap($projectionCache['slots'], 256);

        return $effectiveItems;
    }

    /**
     * Rebuilds only the skill and AI portion of a cached combat definition. Numeric values remain
     * owned by the projection created through UndergroundAlphaV1PlayerCatalog.
     *
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $equipment
     * @param  array<string, mixed>  $skillAllocations
     * @param  array<mixed, mixed>|null  $customAiRules
     * @return array<string, mixed>
     */
    private function behaviorDefinition(
        array $definition,
        array $equipment,
        array $skillAllocations,
        ?array $customAiRules,
    ): array {
        $weaponStyle = $equipment['weapon_style'] ?? null;
        if (! is_string($weaponStyle) || $weaponStyle === '') {
            throw new RuntimeException('Borrowed Secretary effective weapon style is invalid.');
        }
        $catalog = $this->players->explorationCatalog();
        $skillBuild = $this->players->playerSkillBuild($skillAllocations, $weaponStyle);
        $aiRules = $customAiRules === null
            ? $this->aiConfiguration->defaultRules($skillBuild['ai_rules'], $catalog)
            : $this->aiConfiguration->normalizeRules($customAiRules, $catalog);
        $ai = $this->aiConfiguration->snapshot($aiRules, $catalog);
        $activeSkills = $skillBuild['active_skills'];
        $definition['active_skills'] = $activeSkills;
        $definition['ai'] = $ai;
        if (is_array($definition['player_snapshot'] ?? null)) {
            $definition['player_snapshot']['active_skills'] = $activeSkills;
            $definition['player_snapshot']['ai_rules'] = $ai['rules'];
            $definition['player_snapshot']['ai_mode'] = $customAiRules === null ? 'default' : 'custom';
            $definition['player_snapshot']['modifiers'] = $skillBuild['passive_modifiers'];
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function validatedEffectiveEquipment(array $definition): array
    {
        if (! is_int($definition['max_hp'] ?? null)
            || ! is_int($definition['item_level'] ?? null)
            || ! is_array($definition['items'] ?? null)) {
            throw new RuntimeException('Borrowed Secretary effective equipment projection is invalid.');
        }

        $definition['stats'] = $this->orderedStats($definition['stats'] ?? null);
        if (! array_is_list($definition['items'])) {
            throw new RuntimeException('Borrowed Secretary effective equipment items are invalid.');
        }
        foreach ($definition['items'] as $index => $item) {
            if (! is_array($item)) {
                throw new RuntimeException('Borrowed Secretary effective equipment item is invalid.');
            }
            $definition['items'][$index] = $this->orderedKeys($item, [
                'key', 'name', 'category', 'equipped_slot', 'rank', 'item_level', 'rarity',
                'catalog_identity', 'instance_identity',
            ]);
        }

        return $this->orderedKeys($definition, [
            'key', 'label', 'catalog_identity', 'item_level', 'rarity', 'weapon_style',
            'weapon_power', 'physical_defense', 'magical_defense', 'max_hp', 'stats',
            'modifiers', 'affixes', 'unique_effect', 'items',
        ]);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizedCombatDefinition(array $definition): array
    {
        $definition['progression_stats'] = $this->orderedStats($definition['progression_stats'] ?? null);
        $definition['combat_stats'] = $this->orderedStats($definition['combat_stats'] ?? null);
        $equipment = $definition['equipment'] ?? null;
        if (! is_array($equipment)) {
            throw new RuntimeException('Borrowed Secretary combat definition equipment is invalid.');
        }
        $definition['equipment'] = $this->validatedEffectiveEquipment($equipment);
        $playerSnapshot = $definition['player_snapshot'] ?? null;
        if (! is_array($playerSnapshot)) {
            throw new RuntimeException('Borrowed Secretary combat definition player snapshot is invalid.');
        }
        unset($playerSnapshot['party_healing_target_scope']);
        $playerSnapshot['stats'] = $this->orderedStats($playerSnapshot['stats'] ?? null);
        $playerSnapshot['ai_rules'] = $this->normalizedAiRules($playerSnapshot['ai_rules'] ?? null);
        $playerEquipment = $playerSnapshot['equipment'] ?? null;
        if (! is_array($playerEquipment)) {
            throw new RuntimeException('Borrowed Secretary player snapshot equipment is invalid.');
        }
        $playerSnapshot['equipment'] = $this->validatedEffectiveEquipment($playerEquipment);
        $definition['player_snapshot'] = $this->orderedKeys($playerSnapshot, [
            'key', 'label', 'stats', 'active_skills', 'ai_rules', 'ai_mode', 'modifiers',
            'equipment', 'current_hp',
        ]);
        if (is_array($definition['ai'] ?? null)) {
            $definition['ai']['rules'] = $this->normalizedAiRules($definition['ai']['rules'] ?? null);
            $definition['ai'] = $this->orderedKeys($definition['ai'], ['schema_version', 'rules', 'hash']);
        }

        return $this->orderedKeys($definition, [
            'player_snapshot', 'progression_stats', 'combat_stats', 'equipment', 'current_hp',
            'max_hp', 'acquired_nodes', 'active_skills', 'passive_modifiers', 'ai',
        ]);
    }

    /** @return array<string, int> */
    private function orderedStats(mixed $stats): array
    {
        if (! is_array($stats)) {
            throw new RuntimeException('Borrowed Secretary combat stats projection is invalid.');
        }
        $ordered = [];
        foreach (AlphaV1CombatRules::STATS as $stat) {
            if (! array_key_exists($stat, $stats) || ! is_int($stats[$stat])) {
                throw new RuntimeException('Borrowed Secretary combat stats projection is invalid.');
            }
            $ordered[$stat] = $stats[$stat];
        }
        if (count($stats) !== count($ordered)) {
            throw new RuntimeException('Borrowed Secretary combat stats projection is invalid.');
        }

        return $ordered;
    }

    /** @return list<array<string, mixed>> */
    private function normalizedAiRules(mixed $rules): array
    {
        if (! is_array($rules) || ! array_is_list($rules)) {
            throw new RuntimeException('Borrowed Secretary AI rules projection is invalid.');
        }
        $normalized = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)
                || ! is_array($rule['conditions'] ?? null)
                || ! array_is_list($rule['conditions'])
                || ! is_string($rule['action'] ?? null)) {
                throw new RuntimeException('Borrowed Secretary AI rule projection is invalid.');
            }
            $conditions = [];
            foreach ($rule['conditions'] as $condition) {
                if (! is_array($condition) || ! is_string($condition['type'] ?? null)) {
                    throw new RuntimeException('Borrowed Secretary AI condition projection is invalid.');
                }
                $conditionKeys = match ($condition['type']) {
                    'own_hp_lte', 'own_hp_gte', 'own_mp_lte', 'own_mp_gte',
                    'enemy_hp_lte', 'ally_hp_lte' => ['type', 'percent'],
                    'self_has_status', 'self_lacks_status', 'enemy_has_status',
                    'enemy_lacks_status' => ['type', 'status'],
                    'status_stacks_gte', 'role_stacks_gte' => ['type', 'status', 'stacks'],
                    'skill_ready' => ['type', 'skill'],
                    'round_gte' => ['type', 'round'],
                    'round_modulo' => ['type', 'modulo', 'equals'],
                    'always', 'enemy_telegraph' => ['type'],
                    default => throw new RuntimeException('Borrowed Secretary AI condition projection is invalid.'),
                };
                if (array_diff(array_keys($condition), $conditionKeys) !== []) {
                    throw new RuntimeException('Borrowed Secretary AI condition projection is invalid.');
                }
                $conditions[] = $this->orderedKeys($condition, $conditionKeys);
            }
            $ruleKeys = ['conditions', 'action'];
            if (array_key_exists('target', $rule)) {
                if (! in_array($rule['target'], ['lowest_hp_ally', 'untaunted_enemy'], true)) {
                    throw new RuntimeException('Borrowed Secretary AI rule target projection is invalid.');
                }
                $ruleKeys[] = 'target';
            }
            if ($rule['action'] === 'jump') {
                $ruleKeys[] = 'jump_to';
            }
            if (array_diff(array_keys($rule), $ruleKeys) !== []) {
                throw new RuntimeException('Borrowed Secretary AI rule projection is invalid.');
            }
            $rule['conditions'] = $conditions;
            $normalized[] = $this->orderedKeys($rule, $ruleKeys);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalizedItemDefinition(array $definition): array
    {
        $definition['stats'] = $this->orderedStats($definition['stats'] ?? null);
        if (is_array($definition['base'] ?? null)) {
            $base = $definition['base'];
            $base['stats'] = $this->orderedStats($base['stats'] ?? null);
            $definition['base'] = $this->orderedKeys($base, [
                'weapon_power', 'physical_defense', 'magical_defense', 'max_hp', 'stats',
            ]);
        }
        if (is_array($definition['source'] ?? null)) {
            $definition['source'] = $this->orderedKeys($definition['source'], [
                'tier_key', 'seed', 'identity',
            ]);
        }
        if (is_array($definition['affixes'] ?? null) && array_is_list($definition['affixes'])) {
            foreach ($definition['affixes'] as $index => $affix) {
                if (! is_array($affix)) {
                    continue;
                }
                $definition['affixes'][$index] = $this->orderedKeys($affix, [
                    'key', 'label', 'kind', 'target', 'value', 'quality_bps',
                    'standard_value', 'raw_value', 'item_level_bps',
                ]);
            }
        }

        return $this->orderedKeys($definition, [
            'key', 'name', 'category', 'weapon_style', 'rank', 'item_level', 'rarity',
            'rarity_label', 'buy_price', 'shop_sold', 'sellable', 'required_trial_key',
            'sell_price', 'weapon_power', 'physical_defense', 'magical_defense', 'max_hp',
            'stats', 'modifiers', 'affixes', 'unique_effect', 'base', 'instance_identity',
            'generator_identity', 'source',
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  list<string>  $preferred
     * @return array<string, mixed>
     */
    private function orderedKeys(array $source, array $preferred): array
    {
        $ordered = [];
        foreach ($preferred as $key) {
            if (array_key_exists($key, $source)) {
                $ordered[$key] = $source[$key];
            }
        }
        foreach ($source as $key => $value) {
            if (! array_key_exists($key, $ordered)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    private function observeProjectionCall(string $operation): void
    {
        if ($this->projectionCallObserver !== null) {
            ($this->projectionCallObserver)($operation);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function serializableDefinition(array $definition): array
    {
        $keys = [
            'player_snapshot',
            'progression_stats',
            'combat_stats',
            'equipment',
            'current_hp',
            'max_hp',
            'acquired_nodes',
            'active_skills',
            'passive_modifiers',
            'ai',
        ];
        $projection = [];
        foreach ($keys as $key) {
            if (! array_key_exists($key, $definition)) {
                throw new RuntimeException('Borrowed Secretary combat definition is incomplete.');
            }
            $projection[$key] = $definition[$key];
        }

        return $projection;
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

        $ordered = [];
        foreach (UndergroundEquipmentCatalog::EQUIPPED_SLOTS as $slot) {
            if (isset($validated[$slot])) {
                $ordered[$slot] = $validated[$slot];
            }
        }

        return $ordered;
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
            $effective = $this->generated->generate(
                $effectiveLevel, $tier, $rarity, $category, $style, $mainStat, $seed, $sourceIdentity,
                $source['resonance_variant'] ?? null,
            );

            return (new UndergroundEquipmentPolishing)->apply($effective, (int) ($definition['polish_level'] ?? 0));
        }
        if ($originalLevel <= $itemLevelCap) {
            return $definition;
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
        $stats = is_array($definition['source'] ?? null)
            ? ($definition['base']['stats'] ?? [])
            : ($definition['stats'] ?? []);
        foreach (AlphaV1CombatRules::STATS as $stat) {
            if (($stats[$stat] ?? 0) > 0) {
                return $stat;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function rulesIdentity(UndergroundProfile $profile): array
    {
        return [
            'combat' => AlphaV1CombatRules::IDENTITY,
            'equipment_catalog' => $this->equipment->identity(),
            'equipment_generator' => $this->equipment->generatorIdentity(),
            'growth' => is_string($profile->growth_path_identity) && $profile->growth_path_identity !== ''
                ? $profile->growth_path_identity
                : $this->players->growthIdentity(),
            'skill_tree' => is_string($profile->skill_tree_identity) && $profile->skill_tree_identity !== ''
                ? $profile->skill_tree_identity
                : $this->players->skillTreeIdentity(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        $canonical = [];
        $keys = array_keys($value);
        usort($keys, static fn (int|string $left, int|string $right): int => strcmp((string) $left, (string) $right));
        foreach ($keys as $key) {
            $canonical[$key] = $this->canonicalize($value[$key]);
        }

        return $canonical;
    }

    /**
     * @param  array<mixed, mixed>  $map
     * @return array<mixed, mixed>
     */
    private function boundedMap(array $map, int $limit): array
    {
        if (count($map) <= $limit) {
            return $map;
        }

        return array_slice($map, -$limit, null, true);
    }
}
