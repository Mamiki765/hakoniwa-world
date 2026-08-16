<?php

namespace App\Domain\Turn;

use App\Domain\Monster\MonsterSpawnSource;
use App\Domain\Secretary\SecretarySkillCatalog;
use InvalidArgumentException;

final class TurnState
{
    /** @var list<int> */
    private array $stableNationIds = [];

    /** @var list<int> */
    private array $developmentNationIds = [];

    /** @var list<int> */
    private array $surfaceCellIds = [];

    /** @var array<int, int> */
    private array $legacySeaEdgeByCellId = [];

    /** @var array<int, true> */
    private array $famineNationIds = [];

    /** @var array<int, true> */
    private array $attractionNationIds = [];

    /** @var array<int, true> */
    private array $changedMapChunkIds = [];

    /** @var array<int, array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}> */
    private array $nationAggregates = [];

    /** @var array<int, array{money: int, population: int, food: int}> */
    private array $nationStartSummaries = [];

    /** @var array<int, int> */
    private array $refugeesReceivedByNation = [];

    /** @var list<LaunchIntent> */
    private array $launchIntents = [];

    /** @var array<int, MonsterSpawnSource> */
    private array $spawnedMonsterSources = [];

    /**
     * @var array<int, array{
     *     finance_succeeded: bool,
     *     immediate_normal_command_succeeded: bool,
     *     missile_intent_pending: bool,
     *     missile_shots_fired: int,
     *     idle_counter_finalized: bool
     * }>
     */
    private array $nationActivity = [];

    /**
     * @var array<int, array{
     *     secretary_id: int,
     *     name: string|null,
     *     skills: array<string, array{level: int, experience: int}>
     * }>
     */
    private array $secretarySnapshots = [];

    /** @var array<int, array<string, int>> */
    private array $pendingSecretaryExperience = [];

    /** @var array<int, int> */
    private array $finalDefenseInterceptionsUsed = [];

    private bool $secretaryExperienceFlushed = false;

    /** @param array<array-key, mixed> $nationIds */
    public function setStableNationIds(array $nationIds): void
    {
        $this->stableNationIds = $this->positiveIntegerList($nationIds, 'Stable Nation order');
    }

    /** @return list<int> */
    public function stableNationIds(): array
    {
        return $this->stableNationIds;
    }

    /** @param array<array-key, mixed> $nationIds */
    public function setDevelopmentNationIds(array $nationIds): void
    {
        $this->developmentNationIds = $this->positiveIntegerList($nationIds, 'Development Nation order');
    }

    /** @return list<int> */
    public function developmentNationIds(): array
    {
        return $this->developmentNationIds;
    }

    /** @param array<array-key, mixed> $cellIds */
    public function setSurfaceCellIds(array $cellIds): void
    {
        $this->surfaceCellIds = $this->positiveIntegerList($cellIds, 'Surface cell order');
    }

    /** @return list<int> */
    public function surfaceCellIds(): array
    {
        return $this->surfaceCellIds;
    }

    /** @param array<array-key, mixed> $seaEdgeByCellId */
    public function setLegacySeaEdgeByCellId(array $seaEdgeByCellId): void
    {
        $validated = [];
        foreach ($seaEdgeByCellId as $cellId => $seaEdge) {
            if (! is_int($cellId) || $cellId < 1 || ! is_int($seaEdge) || $seaEdge < 0) {
                throw new InvalidArgumentException('Legacy sea-edge context must map positive cell IDs to non-negative integers.');
            }
            $validated[$cellId] = $seaEdge;
        }
        $this->legacySeaEdgeByCellId = $validated;
    }

    public function legacySeaEdgeForCell(int $cellId): int
    {
        if (! array_key_exists($cellId, $this->legacySeaEdgeByCellId)) {
            throw new InvalidArgumentException("Legacy sea-edge context is missing cell {$cellId}.");
        }

        return $this->legacySeaEdgeByCellId[$cellId];
    }

    public function markFamine(int $nationId): void
    {
        if ($nationId < 1) {
            throw new InvalidArgumentException('Famine Nation ID must be positive.');
        }
        $this->famineNationIds[$nationId] = true;
    }

    public function isFamine(int $nationId): bool
    {
        return isset($this->famineNationIds[$nationId]);
    }

    /** @return list<int> */
    public function famineNationIds(): array
    {
        return array_map('intval', array_keys($this->famineNationIds));
    }

    public function markAttraction(int $nationId): void
    {
        if ($nationId < 1) {
            throw new InvalidArgumentException('Attraction Nation ID must be positive.');
        }
        $this->attractionNationIds[$nationId] = true;
    }

    public function hasAttraction(int $nationId): bool
    {
        return isset($this->attractionNationIds[$nationId]);
    }

    public function markMapChunkChanged(int $mapChunkId): void
    {
        if ($mapChunkId < 1) {
            throw new InvalidArgumentException('Changed MapChunk ID must be positive.');
        }
        $this->changedMapChunkIds[$mapChunkId] = true;
    }

    /** @return list<int> */
    public function changedMapChunkIds(): array
    {
        $ids = array_map('intval', array_keys($this->changedMapChunkIds));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * @param  array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}  $aggregate
     */
    public function setNationAggregate(int $nationId, array $aggregate): void
    {
        if ($nationId < 1 || min($aggregate) < 0) {
            throw new InvalidArgumentException('Nation aggregate values must be non-negative integers.');
        }
        $this->nationAggregates[$nationId] = $aggregate;
    }

    /** @return array<int, array{population: int, farm_capacity: int, factory_capacity: int, mine_capacity: int, owned_land_cells: int}> */
    public function nationAggregates(): array
    {
        return $this->nationAggregates;
    }

    /** @param array{money: int, population: int, food: int} $summary */
    public function setNationStartSummary(int $nationId, array $summary): void
    {
        if ($nationId < 1 || min($summary) < 0) {
            throw new InvalidArgumentException('Nation start summary values must be non-negative integers.');
        }
        $this->nationStartSummaries[$nationId] = $summary;
    }

    /** @return array{money: int, population: int, food: int} */
    public function nationStartSummary(int $nationId): array
    {
        if (! isset($this->nationStartSummaries[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} has no start-of-turn summary.");
        }

        return $this->nationStartSummaries[$nationId];
    }

    public function addRefugeesReceived(int $nationId, int $population): void
    {
        if ($nationId < 1 || $population < 0) {
            throw new InvalidArgumentException('Received refugees require a positive Nation ID and non-negative population.');
        }
        $this->refugeesReceivedByNation[$nationId] = ($this->refugeesReceivedByNation[$nationId] ?? 0) + $population;
    }

    public function refugeesReceivedForNation(int $nationId): int
    {
        if ($nationId < 1) {
            throw new InvalidArgumentException('Received-refugee Nation ID must be positive.');
        }

        return $this->refugeesReceivedByNation[$nationId] ?? 0;
    }

    public function registerLaunchIntent(
        mixed $nationId,
        mixed $definitionKey,
        mixed $targetX,
        mixed $targetY,
        mixed $requestedShots,
        mixed $queueItemId = null,
    ): LaunchIntent {
        $intent = new LaunchIntent($nationId, $definitionKey, $targetX, $targetY, $requestedShots, $queueItemId);
        $this->launchIntents[] = $intent;
        $this->markMissileIntentPending($intent->nationId);

        return $intent;
    }

    /** @return list<LaunchIntent> */
    public function launchIntents(): array
    {
        return $this->launchIntents;
    }

    /** @return list<LaunchIntent> */
    public function launchIntentsForNation(mixed $nationId): array
    {
        if (! is_int($nationId) || $nationId < 1) {
            throw new InvalidArgumentException('Launch intent Nation ID must be a positive integer.');
        }

        return array_values(array_filter(
            $this->launchIntents,
            static fn (LaunchIntent $intent): bool => $intent->nationId === $nationId,
        ));
    }

    public function consumeLaunchIntentShots(LaunchIntent $intent, mixed $shots): void
    {
        if (! in_array($intent, $this->launchIntents, true)) {
            throw new InvalidArgumentException('Launch intent does not belong to this turn state.');
        }

        $intent->consumeShots($shots);
    }

    public function recordMonsterSpawned(mixed $monsterId, MonsterSpawnSource $source): void
    {
        if (! is_int($monsterId) || $monsterId < 1) {
            throw new InvalidArgumentException('Spawned monster ID must be a positive integer.');
        }
        if (isset($this->spawnedMonsterSources[$monsterId])) {
            throw new InvalidArgumentException('A monster spawn source cannot change within a target turn.');
        }

        $this->spawnedMonsterSources[$monsterId] = $source;
    }

    /** @return list<int> */
    public function monsterIdsDeferredFromSpawnTurnMovement(): array
    {
        $ids = [];
        foreach ($this->spawnedMonsterSources as $monsterId => $source) {
            if (! $source->canActOnSpawnTurn()) {
                $ids[] = $monsterId;
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function recordFinanceSucceeded(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $activity = $this->nationActivity($nationId);
        if ($activity['idle_counter_finalized']) {
            return;
        }
        $activity['finance_succeeded'] = true;
        $this->nationActivity[$nationId] = $activity;
    }

    public function recordImmediateNormalCommandSucceeded(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $activity = $this->nationActivity($nationId);
        if ($activity['idle_counter_finalized']) {
            return;
        }
        $activity['immediate_normal_command_succeeded'] = true;
        $this->nationActivity[$nationId] = $activity;
    }

    public function markMissileIntentPending(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $activity = $this->nationActivity($nationId);
        if ($activity['idle_counter_finalized']) {
            return;
        }
        $activity['missile_intent_pending'] = true;
        $this->nationActivity[$nationId] = $activity;
    }

    public function recordMissileShotsFired(mixed $nationId, mixed $shots): void
    {
        $nationId = $this->validatedNationId($nationId);
        $activity = $this->nationActivity($nationId);
        if (! is_int($shots) || $shots < 0) {
            throw new InvalidArgumentException('Missile shots fired must be a non-negative integer.');
        }
        if ($activity['idle_counter_finalized']) {
            return;
        }
        if (! $activity['missile_intent_pending']) {
            throw new InvalidArgumentException('Missile shots cannot be recorded without a pending launch intent.');
        }
        $activity['missile_shots_fired'] += $shots;
        $this->nationActivity[$nationId] = $activity;
    }

    /**
     * @return array{
     *     finance_succeeded: bool,
     *     immediate_normal_command_succeeded: bool,
     *     missile_intent_pending: bool,
     *     missile_shots_fired: int,
     *     idle_counter_finalized: bool
     * }
     */
    public function nationActivity(mixed $nationId): array
    {
        $nationId = $this->validatedNationId($nationId);

        return $this->nationActivity[$nationId] ?? [
            'finance_succeeded' => false,
            'immediate_normal_command_succeeded' => false,
            'missile_intent_pending' => false,
            'missile_shots_fired' => 0,
            'idle_counter_finalized' => false,
        ];
    }

    public function markIdleCounterFinalized(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $activity = $this->nationActivity($nationId);
        if ($activity['idle_counter_finalized']) {
            return;
        }
        $activity['idle_counter_finalized'] = true;
        $this->nationActivity[$nationId] = $activity;
    }

    /** @param array<string, mixed> $skills */
    public function setSecretarySnapshot(
        mixed $nationId,
        mixed $secretaryId,
        mixed $name,
        array $skills,
    ): void {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($secretaryId) || $secretaryId < 1) {
            throw new InvalidArgumentException('Secretary snapshot ID must be a positive integer.');
        }
        if ($name !== null && (! is_string($name) || $name === '')) {
            throw new InvalidArgumentException('Secretary snapshot name must be null or a non-empty string.');
        }
        if (array_keys($skills) !== SecretarySkillCatalog::KEYS) {
            throw new InvalidArgumentException('Secretary snapshot must contain the exact Secretary v1 skill catalog.');
        }
        $validatedSkills = [];
        foreach ($skills as $skillKey => $skill) {
            if (! is_array($skill)
                || ! is_int($skill['level'] ?? null) || $skill['level'] < 0
                || ! is_int($skill['experience'] ?? null) || $skill['experience'] < 0) {
                throw new InvalidArgumentException('Secretary snapshot skill values must be non-negative integers.');
            }
            $validatedSkills[$skillKey] = [
                'level' => $skill['level'],
                'experience' => $skill['experience'],
            ];
        }
        if (isset($this->secretarySnapshots[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} already has a Secretary snapshot for this attempt.");
        }
        $this->secretarySnapshots[$nationId] = [
            'secretary_id' => $secretaryId,
            'name' => $name,
            'skills' => $validatedSkills,
        ];
    }

    /**
     * @return array{
     *     secretary_id: int,
     *     name: string|null,
     *     skills: array<string, array{level: int, experience: int}>
     * }
     */
    public function secretarySnapshot(mixed $nationId): array
    {
        $nationId = $this->validatedNationId($nationId);
        if (! isset($this->secretarySnapshots[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} has no Secretary snapshot for this attempt.");
        }

        return $this->secretarySnapshots[$nationId];
    }

    public function hasSecretarySnapshot(mixed $nationId): bool
    {
        $nationId = $this->validatedNationId($nationId);

        return isset($this->secretarySnapshots[$nationId]);
    }

    public function secretarySkillLevel(mixed $nationId, string $skillKey): int
    {
        if (! in_array($skillKey, SecretarySkillCatalog::KEYS, true)) {
            throw new InvalidArgumentException("Unknown Secretary skill {$skillKey}.");
        }

        return $this->secretarySnapshot($nationId)['skills'][$skillKey]['level'];
    }

    public function awardSecretaryExperience(mixed $nationId, string $skillKey, int $amount = 1): void
    {
        $nationId = $this->validatedNationId($nationId);
        $this->secretarySnapshot($nationId);
        if (! in_array($skillKey, SecretarySkillCatalog::KEYS, true) || $amount < 1) {
            throw new InvalidArgumentException('Secretary experience award must use a known skill and positive amount.');
        }
        if ($this->secretaryExperienceFlushed) {
            throw new InvalidArgumentException('Secretary experience cannot be awarded after the attempt flush.');
        }
        $current = $this->pendingSecretaryExperience[$nationId][$skillKey] ?? 0;
        if ($current > PHP_INT_MAX - $amount) {
            throw new InvalidArgumentException('Secretary experience award exceeds the supported integer range.');
        }
        $this->pendingSecretaryExperience[$nationId][$skillKey] = $current + $amount;
    }

    /** @return array<int, array<string, int>> */
    public function pendingSecretaryExperience(): array
    {
        return $this->pendingSecretaryExperience;
    }

    public function consumeFinalDefenseInterception(mixed $nationId): bool
    {
        $nationId = $this->validatedNationId($nationId);
        $level = $this->secretarySkillLevel($nationId, SecretarySkillCatalog::FINAL_DEFENSE_LINE);
        $used = $this->finalDefenseInterceptionsUsed[$nationId] ?? 0;
        if ($used >= $level) {
            return false;
        }
        $this->finalDefenseInterceptionsUsed[$nationId] = $used + 1;

        return true;
    }

    public function finalDefenseInterceptionsUsed(mixed $nationId): int
    {
        $nationId = $this->validatedNationId($nationId);
        $this->secretarySnapshot($nationId);

        return $this->finalDefenseInterceptionsUsed[$nationId] ?? 0;
    }

    public function markSecretaryExperienceFlushed(): void
    {
        if ($this->secretaryExperienceFlushed) {
            throw new InvalidArgumentException('Secretary experience was already flushed for this attempt.');
        }
        $this->secretaryExperienceFlushed = true;
    }

    /** @param array<array-key, mixed> $values
     * @return list<int>
     */
    private function positiveIntegerList(array $values, string $label): array
    {
        if (! array_is_list($values)) {
            throw new InvalidArgumentException("{$label} must be a list.");
        }
        foreach ($values as $value) {
            if (! is_int($value) || $value < 1) {
                throw new InvalidArgumentException("{$label} must contain positive integers.");
            }
        }

        return $values;
    }

    private function validatedNationId(mixed $nationId): int
    {
        if (! is_int($nationId) || $nationId < 1) {
            throw new InvalidArgumentException('Turn activity Nation ID must be a positive integer.');
        }

        return $nationId;
    }
}
