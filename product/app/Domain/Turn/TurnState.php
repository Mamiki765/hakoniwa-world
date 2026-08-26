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
    private array $lifecycleNationIds = [];

    /**
     * @var array<int, array{state: 'active'|'dormant'|'recovery', reason: string|null, state_started_turn: int|null, resume_at_turn: int|null, capital_x: int, capital_y: int}>
     */
    private array $nationLifecycleSnapshots = [];

    /** @var list<int> */
    private array $developmentNationIds = [];

    /** @var list<int> */
    private array $surfaceCellIds = [];

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

    /** @var array<int, int> */
    private array $karmaStartSnapshots = [];

    /** @var array<int, int> */
    private array $karmaMinimumSnapshots = [];

    /** @var array<string, true> */
    private array $turnStartMonsterCoordinates = [];

    /** @var array<string, true> */
    private array $missileBoundaryMonsterCoordinates = [];

    /** @var array<string, int> */
    private array $recoveryTerritoryNationIds = [];

    /** @var array<int, true> */
    private array $recoveryExitedNationIds = [];

    /**
     * @var array<int, array{
     *     crime_points: int,
     *     hostile_impacts_received: int,
     *     foreign_monster_kill: bool,
     *     recovery_entry: bool,
     *     sanction_count: int,
     *     alliance_money: int
     * }>
     */
    private array $karmaLedgers = [];

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
     *     monster_experience: int,
     *     skills: array<string, array{level: int, experience: int}>
     * }>
     */
    private array $secretarySnapshots = [];

    /**
     * @var array<int, array{
     *     secretary_id: int,
     *     equipment_version: int,
     *     items: list<array{
     *         item_instance_id: int,
     *         item_key: string,
     *         category: string,
     *         level: int,
     *         equipped_slot: int,
     *         effects: list<array{
     *             type: string,
     *             timing: string,
     *             parameters: array<string, mixed>,
     *             target_map_space_keys: list<string>,
     *             random_stream_version: int|null
     *         }>
     *     }>
     * }>
     */
    private array $secretaryItemEffectSnapshots = [];

    /** @var array<int, true> */
    private array $secretaryRingFinanceNationIds = [];

    /** @var array{requested: int, applied: int, overflow: int} */
    private array $secretaryRingFinanceTotals = ['requested' => 0, 'applied' => 0, 'overflow' => 0];

    /** @var array<int, array<string, int>> */
    private array $pendingSecretaryExperience = [];

    /** @var array<int, int> */
    private array $pendingSecretaryMonsterExperience = [];

    /** @var array<int, int> */
    private array $finalDefenseInterceptionsUsed = [];

    private bool $secretaryExperienceFlushed = false;

    private bool $demographicExperienceAwarded = false;

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
    public function setLifecycleNationIds(array $nationIds): void
    {
        $this->lifecycleNationIds = $this->positiveIntegerList($nationIds, 'Lifecycle Nation order');
    }

    /** @return list<int> */
    public function lifecycleNationIds(): array
    {
        return $this->lifecycleNationIds;
    }

    /** @param array<string, mixed> $snapshot */
    public function setNationLifecycleSnapshot(int $nationId, array $snapshot): void
    {
        $nationId = $this->validatedNationId($nationId);
        $state = $snapshot['state'] ?? null;
        $reason = $snapshot['reason'] ?? null;
        $stateStartedTurn = $snapshot['state_started_turn'] ?? null;
        $resumeAtTurn = $snapshot['resume_at_turn'] ?? null;
        $capitalX = $snapshot['capital_x'] ?? null;
        $capitalY = $snapshot['capital_y'] ?? null;
        if (! in_array($state, ['active', 'dormant', 'recovery'], true)
            || (! is_string($reason) && $reason !== null)
            || (! is_int($stateStartedTurn) && $stateStartedTurn !== null)
            || (! is_int($resumeAtTurn) && $resumeAtTurn !== null)
            || ! is_int($capitalX) || ! is_int($capitalY)) {
            throw new InvalidArgumentException('Nation lifecycle snapshot is invalid.');
        }
        $this->nationLifecycleSnapshots[$nationId] = [
            'state' => $state,
            'reason' => $reason,
            'state_started_turn' => $stateStartedTurn,
            'resume_at_turn' => $resumeAtTurn,
            'capital_x' => $capitalX,
            'capital_y' => $capitalY,
        ];
    }

    /**
     * @return array<int, array{state: 'active'|'dormant'|'recovery', reason: string|null, state_started_turn: int|null, resume_at_turn: int|null, capital_x: int, capital_y: int}>
     */
    public function nationLifecycleSnapshots(): array
    {
        return $this->nationLifecycleSnapshots;
    }

    /** @return list<int> */
    public function dormantNationIds(): array
    {
        return array_map('intval', array_keys(array_filter(
            $this->nationLifecycleSnapshots,
            static fn (array $snapshot): bool => $snapshot['state'] === 'dormant',
        )));
    }

    /** @return list<int> */
    public function recoveryNationIds(): array
    {
        $nationIds = [];
        foreach ($this->nationLifecycleSnapshots as $nationId => $snapshot) {
            if ($snapshot['state'] === 'recovery' && ! isset($this->recoveryExitedNationIds[$nationId])) {
                $nationIds[] = (int) $nationId;
            }
        }

        return $nationIds;
    }

    /** @param array<string, int> $coordinates */
    public function setRecoveryTerritoryNationIds(array $coordinates): void
    {
        $validated = [];
        foreach ($coordinates as $coordinate => $nationId) {
            if (preg_match('/\A-?\d+:-?\d+\z/D', $coordinate) !== 1) {
                throw new InvalidArgumentException('Recovery territory coordinates must use canonical x:y keys.');
            }
            $validated[$coordinate] = $this->validatedNationId($nationId);
        }
        $this->recoveryTerritoryNationIds = $validated;
    }

    public function recoveryTerritoryNationId(int $x, int $y): ?int
    {
        return $this->recoveryTerritoryNationIds[$x.':'.$y] ?? null;
    }

    public function recordRecoveryTerritoryAcquired(int $nationId, int $x, int $y): void
    {
        $snapshot = $this->nationLifecycleSnapshots[$nationId] ?? null;
        if (($snapshot['state'] ?? null) !== 'recovery' || isset($this->recoveryExitedNationIds[$nationId])) {
            throw new InvalidArgumentException('Only a frozen recovery Nation may acquire protected territory.');
        }
        $key = $x.':'.$y;
        $existingNationId = $this->recoveryTerritoryNationIds[$key] ?? null;
        if ($existingNationId !== null && $existingNationId !== $nationId) {
            throw new InvalidArgumentException('Recovery territory cannot change between frozen recovery Nations.');
        }
        $this->recoveryTerritoryNationIds[$key] = $this->validatedNationId($nationId);
    }

    public function markRecoveryExited(int $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $this->recoveryExitedNationIds[$nationId] = true;
        foreach ($this->recoveryTerritoryNationIds as $coordinate => $territoryNationId) {
            if ($territoryNationId === $nationId) {
                unset($this->recoveryTerritoryNationIds[$coordinate]);
            }
        }
    }

    public function recoveryExitedThisTurn(int $nationId): bool
    {
        return isset($this->recoveryExitedNationIds[$this->validatedNationId($nationId)]);
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

    public function setKarmaStartSnapshot(mixed $nationId, mixed $karma): void
    {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($karma) || $karma < -30 || $karma > 100) {
            throw new InvalidArgumentException('KARMA snapshot must be an integer from -30 through 100.');
        }
        if (array_key_exists($nationId, $this->karmaStartSnapshots)) {
            throw new InvalidArgumentException("Nation {$nationId} already has a KARMA snapshot.");
        }
        $this->karmaStartSnapshots[$nationId] = $karma;
        $this->karmaLedgers[$nationId] = $this->emptyKarmaLedger();
    }

    public function karmaStartSnapshot(mixed $nationId): int
    {
        $nationId = $this->validatedNationId($nationId);
        if (! array_key_exists($nationId, $this->karmaStartSnapshots)) {
            throw new InvalidArgumentException("Nation {$nationId} has no KARMA snapshot.");
        }

        return $this->karmaStartSnapshots[$nationId];
    }

    /** @return array<int, int> */
    public function karmaStartSnapshots(): array
    {
        return $this->karmaStartSnapshots;
    }

    public function setKarmaMinimumSnapshot(mixed $nationId, mixed $minimum): void
    {
        $nationId = $this->validatedNationId($nationId);
        if (! isset($this->karmaStartSnapshots[$nationId])
            || ! is_int($minimum) || $minimum < -30 || $minimum > -10) {
            throw new InvalidArgumentException('KARMA minimum snapshot must be an integer from -30 through -10.');
        }
        if (array_key_exists($nationId, $this->karmaMinimumSnapshots)) {
            throw new InvalidArgumentException("Nation {$nationId} already has a KARMA minimum snapshot.");
        }
        $this->karmaMinimumSnapshots[$nationId] = $minimum;
    }

    public function karmaMinimumSnapshot(mixed $nationId): int
    {
        $nationId = $this->validatedNationId($nationId);
        if (! array_key_exists($nationId, $this->karmaMinimumSnapshots)) {
            throw new InvalidArgumentException("Nation {$nationId} has no KARMA minimum snapshot.");
        }

        return $this->karmaMinimumSnapshots[$nationId];
    }

    /** @param array<array-key, mixed> $coordinates */
    public function setMonsterCoordinateSnapshot(string $boundary, array $coordinates): void
    {
        if (! in_array($boundary, ['turn_start', 'missile_boundary'], true)) {
            throw new InvalidArgumentException('Monster snapshot boundary is invalid.');
        }
        $set = [];
        foreach ($coordinates as $coordinate) {
            if (! is_string($coordinate) || preg_match('/\A-?\d+:-?\d+\z/D', $coordinate) !== 1) {
                throw new InvalidArgumentException('Monster snapshot coordinates must use canonical x:y keys.');
            }
            $set[$coordinate] = true;
        }
        if ($boundary === 'turn_start') {
            $this->turnStartMonsterCoordinates = $set;
        } else {
            $this->missileBoundaryMonsterCoordinates = $set;
        }
    }

    /** @param array<array-key, mixed> $coordinates */
    public function monsterSnapshotIntersects(string $boundary, array $coordinates): bool
    {
        $snapshot = match ($boundary) {
            'turn_start' => $this->turnStartMonsterCoordinates,
            'missile_boundary' => $this->missileBoundaryMonsterCoordinates,
            default => throw new InvalidArgumentException('Monster snapshot boundary is invalid.'),
        };
        foreach ($coordinates as $coordinate) {
            if (is_string($coordinate) && isset($snapshot[$coordinate])) {
                return true;
            }
        }

        return false;
    }

    public function addKarmaCrime(mixed $nationId, mixed $points): void
    {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($points) || $points < 0) {
            throw new InvalidArgumentException('KARMA crime points must be a non-negative integer.');
        }
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['crime_points'] += $points;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    public function recordHostileImpactReceived(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['hostile_impacts_received']++;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    public function markForeignMonsterKill(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['foreign_monster_kill'] = true;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    public function markRecoveryEntry(mixed $nationId): void
    {
        $nationId = $this->validatedNationId($nationId);
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['recovery_entry'] = true;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    public function recordKarmaSanctions(mixed $nationId, mixed $shots): void
    {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($shots) || $shots < 0) {
            throw new InvalidArgumentException('KARMA sanction shots must be a non-negative integer.');
        }
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['sanction_count'] = $shots;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    public function addAllianceMoney(mixed $nationId, mixed $money): void
    {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($money) || $money < 0) {
            throw new InvalidArgumentException('Alliance money must be a non-negative integer.');
        }
        $ledger = $this->karmaLedgerForNation($nationId);
        $ledger['alliance_money'] += $money;
        $this->karmaLedgers[$nationId] = $ledger;
    }

    /**
     * @return array{
     *     crime_points: int,
     *     hostile_impacts_received: int,
     *     foreign_monster_kill: bool,
     *     recovery_entry: bool,
     *     sanction_count: int,
     *     alliance_money: int
     * }
     */
    public function karmaLedgerForNation(mixed $nationId): array
    {
        $nationId = $this->validatedNationId($nationId);
        if (! isset($this->karmaLedgers[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} has no KARMA ledger.");
        }

        return $this->karmaLedgers[$nationId];
    }

    /** @return array<int, array{crime_points: int, hostile_impacts_received: int, foreign_monster_kill: bool, recovery_entry: bool, sanction_count: int, alliance_money: int}> */
    public function karmaLedgers(): array
    {
        return $this->karmaLedgers;
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
        mixed $monsterExperience,
        array $skills,
    ): void {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($secretaryId) || $secretaryId < 1) {
            throw new InvalidArgumentException('Secretary snapshot ID must be a positive integer.');
        }
        if ($name !== null && (! is_string($name) || $name === '')) {
            throw new InvalidArgumentException('Secretary snapshot name must be null or a non-empty string.');
        }
        if (! is_int($monsterExperience) || $monsterExperience < 0) {
            throw new InvalidArgumentException('Secretary monster experience snapshot must be a non-negative integer.');
        }
        if (! in_array(array_keys($skills), [SecretarySkillCatalog::KEYS, SecretarySkillCatalog::V17_KEYS], true)) {
            throw new InvalidArgumentException('Secretary snapshot must contain the exact current skill catalog.');
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
            'monster_experience' => $monsterExperience,
            'skills' => $validatedSkills,
        ];
    }

    /**
     * @return array{
     *     secretary_id: int,
     *     name: string|null,
     *     monster_experience: int,
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

    /** @param array<array-key, mixed> $items */
    public function setSecretaryItemEffectSnapshot(
        mixed $nationId,
        mixed $secretaryId,
        mixed $equipmentVersion,
        array $items,
    ): void {
        $nationId = $this->validatedNationId($nationId);
        if (! is_int($secretaryId) || $secretaryId < 1
            || ! is_int($equipmentVersion) || $equipmentVersion < 1
            || ! array_is_list($items)) {
            throw new InvalidArgumentException('Secretary Item snapshot identity and Item list are invalid.');
        }
        if (isset($this->secretaryItemEffectSnapshots[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} already has a Secretary Item snapshot for this attempt.");
        }
        $validatedItems = [];
        $slots = [];
        foreach ($items as $item) {
            if (! is_array($item)
                || ! is_int($item['item_instance_id'] ?? null) || $item['item_instance_id'] < 1
                || ! is_string($item['item_key'] ?? null) || $item['item_key'] === ''
                || ! is_string($item['category'] ?? null) || $item['category'] === ''
                || ! is_int($item['level'] ?? null) || $item['level'] < 1
                || ! is_int($item['equipped_slot'] ?? null)
                || $item['equipped_slot'] < 1 || $item['equipped_slot'] > 5
                || ! is_array($item['effects'] ?? null) || ! array_is_list($item['effects'])) {
                throw new InvalidArgumentException('Secretary Item snapshot row is invalid.');
            }
            if (isset($slots[$item['equipped_slot']])) {
                throw new InvalidArgumentException('Secretary Item snapshot contains a duplicate equipped slot.');
            }
            $slots[$item['equipped_slot']] = true;
            $effects = [];
            foreach ($item['effects'] as $effect) {
                if (! is_array($effect)
                    || ! is_string($effect['type'] ?? null) || $effect['type'] === ''
                    || ! is_string($effect['timing'] ?? null) || $effect['timing'] === ''
                    || ! is_array($effect['parameters'] ?? null) || array_is_list($effect['parameters'])
                    || ! is_array($effect['target_map_space_keys'] ?? null)
                    || ! array_is_list($effect['target_map_space_keys'])
                    || (! is_int($effect['random_stream_version'] ?? null)
                        && ($effect['random_stream_version'] ?? null) !== null)) {
                    throw new InvalidArgumentException('Secretary Item snapshot effect is invalid.');
                }
                foreach ($effect['target_map_space_keys'] as $mapSpaceKey) {
                    if (! is_string($mapSpaceKey) || $mapSpaceKey === '') {
                        throw new InvalidArgumentException('Secretary Item target MapSpace keys must be non-empty strings.');
                    }
                }
                $effects[] = [
                    'type' => $effect['type'],
                    'timing' => $effect['timing'],
                    'parameters' => $effect['parameters'],
                    'target_map_space_keys' => $effect['target_map_space_keys'],
                    'random_stream_version' => $effect['random_stream_version'],
                ];
            }
            $validatedItems[] = [
                'item_instance_id' => $item['item_instance_id'],
                'item_key' => $item['item_key'],
                'category' => $item['category'],
                'level' => $item['level'],
                'equipped_slot' => $item['equipped_slot'],
                'effects' => $effects,
            ];
        }
        $this->secretaryItemEffectSnapshots[$nationId] = [
            'secretary_id' => $secretaryId,
            'equipment_version' => $equipmentVersion,
            'items' => $validatedItems,
        ];
    }

    /**
     * @return array{
     *     secretary_id: int,
     *     equipment_version: int,
     *     items: list<array<string, mixed>>
     * }
     */
    public function secretaryItemEffectSnapshot(mixed $nationId): array
    {
        $nationId = $this->validatedNationId($nationId);
        if (! isset($this->secretaryItemEffectSnapshots[$nationId])) {
            throw new InvalidArgumentException("Nation {$nationId} has no Secretary Item snapshot for this attempt.");
        }

        return $this->secretaryItemEffectSnapshots[$nationId];
    }

    public function hasSecretaryItemEffectSnapshot(mixed $nationId): bool
    {
        $nationId = $this->validatedNationId($nationId);

        return isset($this->secretaryItemEffectSnapshots[$nationId]);
    }

    public function secretaryItemEffectSnapshotCount(): int
    {
        return count($this->secretaryItemEffectSnapshots);
    }

    public function secretaryItemEffectItemCount(): int
    {
        return array_sum(array_map(
            static fn (array $snapshot): int => count($snapshot['items']),
            $this->secretaryItemEffectSnapshots,
        ));
    }

    public function recordSecretaryRingFinanceBonus(
        mixed $nationId,
        int $requested,
        int $applied,
        int $overflow,
    ): void {
        $nationId = $this->validatedNationId($nationId);
        if ($requested < 1 || $applied < 0 || $overflow < 0 || $applied + $overflow !== $requested) {
            throw new InvalidArgumentException('Secretary Ring finance metrics are invalid.');
        }
        $this->secretaryRingFinanceNationIds[$nationId] = true;
        $this->secretaryRingFinanceTotals['requested'] += $requested;
        $this->secretaryRingFinanceTotals['applied'] += $applied;
        $this->secretaryRingFinanceTotals['overflow'] += $overflow;
    }

    /** @return array{secretary_ring_nations: int, secretary_ring_bonus_requested: int, secretary_ring_bonus_applied: int, secretary_ring_bonus_overflow: int} */
    public function secretaryRingFinanceMetrics(): array
    {
        return [
            'secretary_ring_nations' => count($this->secretaryRingFinanceNationIds),
            'secretary_ring_bonus_requested' => $this->secretaryRingFinanceTotals['requested'],
            'secretary_ring_bonus_applied' => $this->secretaryRingFinanceTotals['applied'],
            'secretary_ring_bonus_overflow' => $this->secretaryRingFinanceTotals['overflow'],
        ];
    }

    public function secretarySkillLevel(mixed $nationId, string $skillKey): int
    {
        if (! in_array($skillKey, SecretarySkillCatalog::V17_KEYS, true)) {
            throw new InvalidArgumentException("Unknown Secretary skill {$skillKey}.");
        }

        return $this->secretarySnapshot($nationId)['skills'][$skillKey]['level'];
    }

    public function awardSecretaryExperience(mixed $nationId, string $skillKey, int $amount = 1): void
    {
        $nationId = $this->validatedNationId($nationId);
        $this->secretarySnapshot($nationId);
        if (! in_array($skillKey, SecretarySkillCatalog::V17_KEYS, true) || $amount < 1) {
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

    public function claimDemographicExperienceAward(): void
    {
        if ($this->demographicExperienceAwarded) {
            throw new InvalidArgumentException('Demographic Secretary experience was already awarded for this attempt.');
        }
        if ($this->secretaryExperienceFlushed) {
            throw new InvalidArgumentException('Demographic Secretary experience cannot be awarded after the attempt flush.');
        }
        $this->demographicExperienceAwarded = true;
    }

    /** @return array<int, array<string, int>> */
    public function pendingSecretaryExperience(): array
    {
        return $this->pendingSecretaryExperience;
    }

    public function awardSecretaryMonsterExperience(mixed $nationId, int $amount): void
    {
        $nationId = $this->validatedNationId($nationId);
        $this->secretarySnapshot($nationId);
        if ($amount < 1) {
            throw new InvalidArgumentException('Secretary monster experience award must be positive.');
        }
        if ($this->secretaryExperienceFlushed) {
            throw new InvalidArgumentException('Secretary monster experience cannot be awarded after the attempt flush.');
        }
        $current = $this->pendingSecretaryMonsterExperience[$nationId] ?? 0;
        if ($current > PHP_INT_MAX - $amount) {
            throw new InvalidArgumentException('Secretary monster experience award exceeds the supported integer range.');
        }
        $this->pendingSecretaryMonsterExperience[$nationId] = $current + $amount;
    }

    /** @return array<int, int> */
    public function pendingSecretaryMonsterExperience(): array
    {
        return $this->pendingSecretaryMonsterExperience;
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

    /** @return array{crime_points: int, hostile_impacts_received: int, foreign_monster_kill: bool, recovery_entry: bool, sanction_count: int, alliance_money: int} */
    private function emptyKarmaLedger(): array
    {
        return [
            'crime_points' => 0,
            'hostile_impacts_received' => 0,
            'foreign_monster_kill' => false,
            'recovery_entry' => false,
            'sanction_count' => 0,
            'alliance_money' => 0,
        ];
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
