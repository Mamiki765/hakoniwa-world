<?php

namespace App\Application;

use App\Domain\Command\CommandParametersValidator;
use App\Domain\Command\HistoricalMonsterDispatchRequestInspector;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\World;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class RulesetV11MigrationService
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v10';

    public const TARGET_KEY = 'hakoniwa-2s-plus-v11';

    public const SOURCE_CHECKSUM = '6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1';

    public const TARGET_CHECKSUM = 'b39469ed710e0a80db966e630d7eb7dfaf64b2863ae56346679b829e175da8fe';

    public const FAILURE_AFTER_PROVENANCE = 'after_provenance';

    public const FAILURE_AFTER_QUEUE_REBIND = 'after_queue_rebind';

    public const FAILURE_AFTER_MONSTER_REBIND = 'after_monster_rebind';

    public const FAILURE_AFTER_KILL_STAT_REBIND = 'after_kill_stat_rebind';

    public const FAILURE_AFTER_WORLD_ACTIVATION = 'after_world_activation';

    private const WORLD_KEY = 'shared-world';

    private const QUEUE_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const MONSTER_INSTANCE_TRIGGER = 'monster_instance_world_ruleset_guard';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    /** @var list<string> */
    private const HISTORICAL_MONSTER_KEYS = [
        'mecha_inora',
        'inora',
        'sanjira',
        'red_inora',
        'dark_inora',
        'inora_ghost',
        'whale',
        'king_inora',
    ];

    /** @var list<string> */
    private const QUEUE_STATUSES = ['queued', 'completed', 'failed', 'cancelled'];

    /** @var list<string> */
    private const TERRAIN_KEYS = [
        'sea',
        'shallow',
        'wasteland',
        'scorched',
        'plain',
        'forest',
        'mountain',
    ];

    public function __construct(
        private RulesetPublisher $publisher,
        private SecretaryV1MigrationSafetyGuard $worldGuard,
        private CommandParametersValidator $parametersValidator,
        private HistoricalMonsterDispatchRequestInspector $historicalDispatchInspector,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $failureInjector  Test-only transaction failure seam.
     */
    public function migrate(?Closure $failureInjector = null): RulesetV11MigrationResult
    {
        return DB::transaction(function () use ($failureInjector): RulesetV11MigrationResult {
            $sourceSettings = config('hakoniwa.published_rulesets.'.self::SOURCE_KEY);
            $targetSettings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
            if (! is_array($sourceSettings) || ! is_array($targetSettings)) {
                throw new RuntimeException('The immutable v10 or v11 production ruleset snapshot is missing.');
            }
            if ($this->settingsChecksum($sourceSettings) !== self::SOURCE_CHECKSUM) {
                throw new RuntimeException('The authored v10 checksum differs from the immutable release baseline.');
            }
            if ($this->settingsChecksum($targetSettings) !== self::TARGET_CHECKSUM) {
                throw new RuntimeException('The authored v11 checksum differs from the immutable release contract.');
            }

            // This is the common release/turn advisory lock, World row lock, and unresolved
            // next non-dry TurnRun guard. It deliberately precedes every publication write.
            $guardedWorld = $this->worldGuard->lockAndAssertNoUnresolvedNextTurnRun('v11 migration');
            $world = $this->lockAndAssertWorldScope($guardedWorld);
            [$source, $existingTarget] = $this->lockRulesets($sourceSettings, $targetSettings);
            $this->lockConversionTables();
            $this->assertCatalogsAndSourceDefinitions(
                $sourceSettings,
                (int) $source->id,
                assertGlobalCatalogs: $world !== null,
            );

            $worldState = $world === null
                ? 'absent'
                : match ((int) $world->ruleset_version_id) {
                    (int) $source->id => 'source',
                    (int) ($existingTarget->id ?? -1) => 'target',
                    default => throw new RuntimeException(
                        'shared-world is attached to an unexpected ruleset; refusing an implicit v11 migration.',
                    ),
                };

            $queueItems = $world === null
                ? new EloquentCollection
                : $this->lockQueueItems((int) $world->id);
            $fingerprints = $queueItems->mapWithKeys(
                static fn (NationCommandQueueItem $item): array => [(int) $item->id => $item->request_fingerprint],
            )->all();
            $queuePlan = $this->preflightQueue(
                $queueItems,
                (int) $source->id,
                $existingTarget?->id === null ? null : (int) $existingTarget->id,
                $worldState,
            );
            $monsterPlan = $world === null
                ? ['alive' => 0]
                : $this->preflightMonsters((int) $world->id, (int) $source->id, $existingTarget?->id, $worldState);
            $statPlan = $world === null
                ? ['stats' => 0]
                : $this->preflightKillStats((int) $world->id, (int) $source->id, $existingTarget?->id, $worldState);

            // The authoritative publisher runs only after every source/World/live-data guard.
            $this->publisher->publish($sourceSettings);
            $target = $this->publisher->publish($targetSettings);
            $published = $existingTarget === null;
            $this->assertTargetDefinitionContracts((int) $source->id, (int) $target->id, $sourceSettings, $targetSettings);

            if ($worldState === 'target') {
                $this->assertPostconditions(
                    (int) $world->id,
                    (int) $source->id,
                    (int) $target->id,
                    allowV11TerminalHistory: true,
                );
                $this->assertFingerprintsUnchanged($fingerprints);

                return new RulesetV11MigrationResult(
                    rulesetVersionId: (int) $target->id,
                    checksum: $this->settingsChecksum($targetSettings),
                    published: $published,
                    alreadyCompleted: true,
                    requestProvenanceBackfilled: 0,
                    queuedCommandsRebound: 0,
                    aliveMonstersRebound: 0,
                    killStatsRebound: 0,
                    worldsActivated: 0,
                );
            }

            if ($worldState === 'absent') {
                return new RulesetV11MigrationResult(
                    rulesetVersionId: (int) $target->id,
                    checksum: $this->settingsChecksum($targetSettings),
                    published: $published,
                    alreadyCompleted: false,
                    requestProvenanceBackfilled: 0,
                    queuedCommandsRebound: 0,
                    aliveMonstersRebound: 0,
                    killStatsRebound: 0,
                    worldsActivated: 0,
                );
            }

            $backfillIds = array_values(array_unique([
                ...$queuePlan['queued_provenance_ids'],
                ...$queuePlan['terminal_provenance_ids'],
            ]));
            $provenanceBackfilled = $backfillIds === [] ? 0 : DB::table('nation_command_queue_items')
                ->whereIn('id', $backfillIds)
                ->whereNull('request_ruleset_version_id')
                ->update(['request_ruleset_version_id' => $source->id]);
            if ($provenanceBackfilled !== count($backfillIds)) {
                throw new RuntimeException('Request-ruleset provenance changed after preflight.');
            }
            $this->injectFailure($failureInjector, self::FAILURE_AFTER_PROVENANCE);

            $monsterTrigger = $monsterPlan['alive'] > 0
                ? $this->captureEnabledTrigger('monster_instances', self::MONSTER_INSTANCE_TRIGGER)
                : null;
            $statTrigger = $statPlan['stats'] > 0
                ? $this->captureEnabledTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER)
                : null;
            if ($monsterTrigger !== null) {
                DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_INSTANCE_TRIGGER);
            }
            if ($statTrigger !== null) {
                DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            }
            if ($queuePlan['queued'] > 0) {
                DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
            }

            $queuedRebound = DB::update(<<<'SQL'
UPDATE nation_command_queue_items item
   SET command_definition_id = target.id
  FROM nation_command_queues queue
  JOIN nations nation ON nation.id = queue.nation_id
  JOIN command_definitions source ON source.ruleset_version_id = ?
  JOIN command_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE item.nation_command_queue_id = queue.id
   AND nation.world_id = ?
   AND item.command_definition_id = source.id
   AND item.status = 'queued'
SQL, [$source->id, $target->id, $world->id]);
            if ($queuedRebound !== $queuePlan['queued']) {
                throw new RuntimeException("Expected to rebind {$queuePlan['queued']} queued commands, but rebound {$queuedRebound}.");
            }
            $this->injectFailure($failureInjector, self::FAILURE_AFTER_QUEUE_REBIND);

            $aliveMonstersRebound = DB::update(<<<'SQL'
UPDATE monster_instances instance
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE instance.world_id = ?
   AND instance.monster_definition_id = source.id
   AND instance.state = 'alive'
   AND source.ruleset_version_id = ?
SQL, [$target->id, $world->id, $source->id]);
            if ($aliveMonstersRebound !== $monsterPlan['alive']) {
                throw new RuntimeException("Expected to rebind {$monsterPlan['alive']} alive monsters, but rebound {$aliveMonstersRebound}.");
            }
            $this->injectFailure($failureInjector, self::FAILURE_AFTER_MONSTER_REBIND);

            $killStatsRebound = DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE stat.world_id = ?
   AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$target->id, $world->id, $source->id]);
            if ($killStatsRebound !== $statPlan['stats']) {
                throw new RuntimeException("Expected to rebind {$statPlan['stats']} monster kill stats, but rebound {$killStatsRebound}.");
            }
            $this->injectFailure($failureInjector, self::FAILURE_AFTER_KILL_STAT_REBIND);

            $worldsActivated = DB::table('worlds')->where('id', $world->id)
                ->where('ruleset_version_id', $source->id)
                ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]);
            if ($worldsActivated !== 1) {
                throw new RuntimeException('shared-world changed after v11 conversion preflight.');
            }
            $this->injectFailure($failureInjector, self::FAILURE_AFTER_WORLD_ACTIVATION);

            if ($monsterTrigger !== null) {
                DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_INSTANCE_TRIGGER);
                $this->assertTriggerRestored('monster_instances', self::MONSTER_INSTANCE_TRIGGER, $monsterTrigger);
            }
            if ($statTrigger !== null) {
                DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
                $this->assertTriggerRestored('nation_monster_kill_stats', self::KILL_STAT_TRIGGER, $statTrigger);
            }
            if ($queuePlan['queued'] > 0) {
                DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
            }

            $this->assertPostconditions(
                (int) $world->id,
                (int) $source->id,
                (int) $target->id,
                allowV11TerminalHistory: false,
            );
            $this->assertFingerprintsUnchanged($fingerprints);

            return new RulesetV11MigrationResult(
                rulesetVersionId: (int) $target->id,
                checksum: $this->settingsChecksum($targetSettings),
                published: $published,
                alreadyCompleted: false,
                requestProvenanceBackfilled: $provenanceBackfilled,
                queuedCommandsRebound: $queuedRebound,
                aliveMonstersRebound: $aliveMonstersRebound,
                killStatsRebound: $killStatsRebound,
                worldsActivated: $worldsActivated,
            );
        }, 3);
    }

    private function lockAndAssertWorldScope(?World $guardedWorld): ?World
    {
        $worlds = World::query()->orderBy('id')->lockForUpdate()
            ->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($worlds->count() > 1) {
            throw new RuntimeException('The v11 migration supports exactly the single shared-world product contract.');
        }
        if ($worlds->isEmpty()) {
            if ($guardedWorld !== null) {
                throw new RuntimeException('The guarded World disappeared during v11 migration preflight.');
            }

            return null;
        }
        $world = $worlds->first();
        if ($world->key !== self::WORLD_KEY
            || $guardedWorld === null || $guardedWorld->id !== $world->id) {
            throw new RuntimeException('The v11 migration encountered an unexpected World scope.');
        }

        return $world;
    }

    /**
     * @param  array<string, mixed>  $sourceSettings
     * @param  array<string, mixed>  $targetSettings
     * @return array{RulesetVersion, RulesetVersion|null}
     */
    private function lockRulesets(array $sourceSettings, array $targetSettings): array
    {
        $rows = RulesetVersion::query()->whereIn('key', [self::SOURCE_KEY, self::TARGET_KEY])
            ->orderBy('key')->lockForUpdate()->get()->keyBy('key');
        if (! $rows->has(self::SOURCE_KEY)) {
            throw new RuntimeException('The exact immutable published v10 source ruleset is missing or conflicting.');
        }
        $source = $rows->get(self::SOURCE_KEY);
        if ($source->version !== 10 || ! $source->is_active
            || $this->canonicalJson($source->settings) !== $this->canonicalJson($sourceSettings)) {
            throw new RuntimeException('The exact immutable published v10 source ruleset is missing or conflicting.');
        }
        $target = $rows->has(self::TARGET_KEY) ? $rows->get(self::TARGET_KEY) : null;
        if ($target !== null && ($target->version !== 11 || ! $target->is_active
            || $this->canonicalJson($target->settings) !== $this->canonicalJson($targetSettings))) {
            throw new RuntimeException('A conflicting immutable v11 publication already exists.');
        }

        return [$source, $target];
    }

    private function lockConversionTables(): void
    {
        foreach ([
            ['turn_runs', 'SHARE ROW EXCLUSIVE'],
            ['terrain_definitions', 'SHARE'],
            ['facility_definitions', 'SHARE'],
            ['resource_definitions', 'SHARE'],
            ['production_definitions', 'SHARE ROW EXCLUSIVE'],
            ['command_definitions', 'SHARE ROW EXCLUSIVE'],
            ['monster_definitions', 'SHARE ROW EXCLUSIVE'],
            ['nation_command_queues', 'SHARE ROW EXCLUSIVE'],
            ['nation_command_queue_items', 'SHARE ROW EXCLUSIVE'],
            ['monster_instances', 'SHARE ROW EXCLUSIVE'],
            ['monster_occupancies', 'SHARE ROW EXCLUSIVE'],
            ['nation_monster_kill_stats', 'SHARE ROW EXCLUSIVE'],
        ] as [$table, $mode]) {
            DB::statement("LOCK TABLE {$table} IN {$mode} MODE");
        }
    }

    /** @param array<string, mixed> $sourceSettings */
    private function assertCatalogsAndSourceDefinitions(
        array $sourceSettings,
        int $sourceRulesetId,
        bool $assertGlobalCatalogs,
    ): void {
        if ($assertGlobalCatalogs) {
            $this->assertExactKeys(
                'terrain_definitions',
                DB::table('terrain_definitions')->orderBy('key')->pluck('key')->all(),
                self::TERRAIN_KEYS,
            );
            $this->assertExactKeys(
                'facility_definitions',
                DB::table('facility_definitions')->orderBy('key')->pluck('key')->all(),
                $this->settingsMapKeys($sourceSettings, 'facility_definitions'),
            );
            $this->assertExactKeys(
                'resource_definitions',
                DB::table('resource_definitions')->orderBy('key')->pluck('key')->all(),
                $this->settingsDefinitionKeys($sourceSettings, 'resource_definitions'),
            );
        }
        foreach ([
            'production_definitions' => 'production_definitions',
            'command_definitions' => 'command_definitions',
            'monster_definitions' => 'monster_definitions',
        ] as $settingsKey => $table) {
            $this->assertExactKeys(
                "v10 {$table}",
                DB::table($table)->where('ruleset_version_id', $sourceRulesetId)->orderBy('key')->pluck('key')->all(),
                $this->settingsDefinitionKeys($sourceSettings, $settingsKey),
            );
        }
        $this->assertExactKeys(
            'v10 historical monster catalog',
            $this->settingsDefinitionKeys($sourceSettings, 'monster_definitions'),
            self::HISTORICAL_MONSTER_KEYS,
        );
    }

    /** @return EloquentCollection<int, NationCommandQueueItem> */
    private function lockQueueItems(int $worldId): EloquentCollection
    {
        return NationCommandQueueItem::query()
            ->whereHas('queue.nation', static fn (Builder $query): Builder => $query->where('world_id', $worldId))
            ->orderBy('id')
            ->lockForUpdate()
            ->with(['definition.rulesetVersion', 'requestRulesetVersion'])
            ->get();
    }

    /**
     * @param  EloquentCollection<int, NationCommandQueueItem>  $items
     * @return array{queued: int, queued_provenance_ids: list<int>, terminal_provenance_ids: list<int>}
     */
    private function preflightQueue(
        EloquentCollection $items,
        int $sourceRulesetId,
        ?int $targetRulesetId,
        string $worldState,
    ): array {
        $queued = 0;
        $queuedProvenanceIds = [];
        $terminalProvenanceIds = [];
        foreach ($items as $item) {
            if (! in_array($item->status, self::QUEUE_STATUSES, true)) {
                throw new RuntimeException("Queue item {$item->id} has unsupported status {$item->status}.");
            }
            $definition = $item->getRelation('definition');
            if (! $definition instanceof CommandDefinition) {
                throw new RuntimeException("Queue item {$item->id} has no command definition.");
            }
            $this->assertRequestScalarShape($item);

            if ($targetRulesetId !== null && ($definition->ruleset_version_id === $targetRulesetId
                || $item->request_ruleset_version_id === $targetRulesetId) && $worldState === 'source') {
                throw new RuntimeException("Queue item {$item->id} contains a partial v11 reference.");
            }

            if ($item->status === 'queued') {
                $queued++;
                $expectedRulesetId = $worldState === 'source' ? $sourceRulesetId : $targetRulesetId;
                if ($expectedRulesetId === null || $definition->ruleset_version_id !== $expectedRulesetId) {
                    throw new RuntimeException("Queued item {$item->id} has an invalid live command definition reference.");
                }
                if (! is_int($item->queue_position) || $item->queue_position < 1) {
                    throw new RuntimeException("Queued item {$item->id} has an invalid queue position.");
                }
                if ($worldState === 'source') {
                    if ($item->request_ruleset_version_id !== null
                        && $item->request_ruleset_version_id !== $sourceRulesetId) {
                        throw new RuntimeException("Queued item {$item->id} has contradictory request provenance.");
                    }
                    $this->assertStoredRequestMatchesDefinition($item, $definition);
                    if ($definition->key === 'monster_dispatch'
                        && ! $this->historicalDispatchInspector->inspect($item)->proven) {
                        throw new RuntimeException("Queued monster dispatch {$item->id} is not the exact historical v10 request shape.");
                    }
                    if ($item->request_ruleset_version_id === null) {
                        $queuedProvenanceIds[] = (int) $item->id;
                    }
                } elseif ($item->request_ruleset_version_id === null) {
                    throw new RuntimeException("Already-v11 queued item {$item->id} is missing immutable request provenance.");
                }

                continue;
            }

            if ($item->request_ruleset_version_id !== null
                && $item->request_ruleset_version_id !== $definition->ruleset_version_id) {
                throw new RuntimeException("Terminal queue item {$item->id} has contradictory request provenance.");
            }
            if ($item->request_fingerprint !== null) {
                if ($worldState === 'source' && $item->request_ruleset_version_id === null
                    && $definition->ruleset_version_id !== $sourceRulesetId) {
                    throw new RuntimeException("Terminal queue item {$item->id} cannot be safely attributed to v10.");
                }
                if ($definition->ruleset_version_id === $sourceRulesetId) {
                    $this->assertStoredRequestMatchesDefinition($item, $definition);
                    if ($definition->key === 'monster_dispatch'
                        && ! $this->historicalDispatchInspector->inspect($item)->proven) {
                        throw new RuntimeException("Terminal monster dispatch {$item->id} is not safely attributable to v10.");
                    }
                    if ($item->request_ruleset_version_id === null) {
                        $terminalProvenanceIds[] = (int) $item->id;
                    }
                }
            }
        }

        return [
            'queued' => $queued,
            'queued_provenance_ids' => $queuedProvenanceIds,
            'terminal_provenance_ids' => $terminalProvenanceIds,
        ];
    }

    private function assertRequestScalarShape(NationCommandQueueItem $item): void
    {
        if (! is_int($item->target_x) || ! is_int($item->target_y)
            || $item->quantity < 1 || $item->quantity > 99
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $item->request_key) !== 1
            || ($item->request_fingerprint !== null
                && preg_match('/^[0-9a-f]{64}$/', $item->request_fingerprint) !== 1)) {
            throw new RuntimeException("Queue item {$item->id} has malformed immutable request fields.");
        }
    }

    private function assertStoredRequestMatchesDefinition(
        NationCommandQueueItem $item,
        CommandDefinition $definition,
    ): void {
        $schemas = $definition->metadata['parameters'] ?? [];
        if (! is_array($schemas)) {
            throw new RuntimeException("Command definition {$definition->id} has malformed parameter metadata.");
        }
        try {
            $validated = $this->parametersValidator->validate($schemas, $item->parameters);
        } catch (Throwable $exception) {
            throw new RuntimeException("Queue item {$item->id} has malformed stored parameters.", 0, $exception);
        }
        if ($this->canonicalJson($validated) !== $this->canonicalJson($item->parameters)) {
            throw new RuntimeException("Queue item {$item->id} has a non-canonical stored parameter shape.");
        }
    }

    /** @return array{alive: int} */
    private function preflightMonsters(
        int $worldId,
        int $sourceRulesetId,
        ?int $targetRulesetId,
        string $worldState,
    ): array {
        $rows = DB::table('monster_instances as instance')
            ->join('monster_definitions as definition', 'definition.id', '=', 'instance.monster_definition_id')
            ->where('instance.world_id', $worldId)
            ->orderBy('instance.id')
            ->lockForUpdate()
            ->get([
                'instance.id', 'instance.state', 'instance.current_hp', 'instance.spawned_max_hp',
                'instance.version', 'definition.ruleset_version_id', 'definition.key',
                'definition.base_hp', 'definition.hp_variation',
            ]);
        $alive = 0;
        foreach ($rows as $row) {
            if (! in_array($row->state, ['alive', 'killed', 'removed'], true)) {
                throw new RuntimeException("Monster instance {$row->id} has unsupported state {$row->state}.");
            }
            if ($targetRulesetId !== null && (int) $row->ruleset_version_id === $targetRulesetId
                && $worldState === 'source') {
                throw new RuntimeException("Monster instance {$row->id} contains a partial v11 reference.");
            }
            if ($row->state !== 'alive') {
                continue;
            }
            $alive++;
            $expectedRulesetId = $worldState === 'source' ? $sourceRulesetId : $targetRulesetId;
            $allowedKeys = $worldState === 'source'
                ? self::HISTORICAL_MONSTER_KEYS
                : [...self::HISTORICAL_MONSTER_KEYS, 'aoi_inora', 'mecha_inora_zero'];
            if ($expectedRulesetId === null || (int) $row->ruleset_version_id !== $expectedRulesetId
                || ! in_array($row->key, $allowedKeys, true)
                || (int) $row->spawned_max_hp < (int) $row->base_hp
                || (int) $row->spawned_max_hp > (int) $row->base_hp + (int) $row->hp_variation
                || (int) $row->current_hp < 1
                || (int) $row->current_hp > (int) $row->spawned_max_hp) {
                throw new RuntimeException("Alive monster instance {$row->id} cannot be mapped exactly to v11.");
            }
        }
        $occupancyMismatch = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*) FROM monster_instances instance
      LEFT JOIN monster_occupancies occupancy ON occupancy.monster_instance_id = instance.id
     WHERE instance.world_id = ? AND instance.state = 'alive' AND occupancy.id IS NULL) AS missing,
    (SELECT count(*) FROM monster_occupancies occupancy
      JOIN monster_instances instance ON instance.id = occupancy.monster_instance_id
      JOIN map_cells cell ON cell.id = occupancy.map_cell_id
      JOIN map_spaces space ON space.id = cell.map_space_id
     WHERE instance.world_id = ? AND (instance.state <> 'alive' OR space.world_id <> instance.world_id)) AS invalid
SQL, [$worldId, $worldId]);
        if ((int) $occupancyMismatch->missing !== 0 || (int) $occupancyMismatch->invalid !== 0) {
            throw new RuntimeException('Monster occupancy integrity failed during v11 preflight.');
        }

        return ['alive' => $alive];
    }

    /** @return array{stats: int} */
    private function preflightKillStats(
        int $worldId,
        int $sourceRulesetId,
        ?int $targetRulesetId,
        string $worldState,
    ): array {
        $rows = DB::table('nation_monster_kill_stats as stat')
            ->join('nations as nation', 'nation.id', '=', 'stat.nation_id')
            ->join('monster_definitions as definition', 'definition.id', '=', 'stat.monster_definition_id')
            ->where('stat.world_id', $worldId)
            ->orderBy('stat.id')
            ->lockForUpdate()
            ->get([
                'stat.id', 'stat.world_id', 'nation.world_id as nation_world_id', 'stat.kill_count',
                'stat.first_killed_turn', 'stat.last_killed_turn', 'stat.version',
                'definition.ruleset_version_id', 'definition.key',
            ]);
        $expectedRulesetId = $worldState === 'source' ? $sourceRulesetId : $targetRulesetId;
        $allowedKeys = $worldState === 'source'
            ? self::HISTORICAL_MONSTER_KEYS
            : [...self::HISTORICAL_MONSTER_KEYS, 'aoi_inora', 'mecha_inora_zero'];
        foreach ($rows as $row) {
            if ($targetRulesetId !== null && (int) $row->ruleset_version_id === $targetRulesetId
                && $worldState === 'source') {
                throw new RuntimeException("Monster kill stat {$row->id} contains a partial v11 reference.");
            }
            if ($expectedRulesetId === null || (int) $row->ruleset_version_id !== $expectedRulesetId
                || (int) $row->nation_world_id !== $worldId
                || ! in_array($row->key, $allowedKeys, true)
                || (int) $row->kill_count < 1
                || (int) $row->first_killed_turn < 1
                || (int) $row->last_killed_turn < (int) $row->first_killed_turn
                || (int) $row->version < 1) {
                throw new RuntimeException("Monster kill stat {$row->id} cannot be mapped exactly to v11.");
            }
        }
        $duplicate = DB::selectOne(<<<'SQL'
SELECT source_stat.id AS source_id, target_stat.id AS target_id
  FROM nation_monster_kill_stats source_stat
  JOIN monster_definitions source_definition ON source_definition.id = source_stat.monster_definition_id
  JOIN monster_definitions target_definition
    ON target_definition.ruleset_version_id = ? AND target_definition.key = source_definition.key
  JOIN nation_monster_kill_stats target_stat
    ON target_stat.world_id = source_stat.world_id
   AND target_stat.nation_id = source_stat.nation_id
   AND target_stat.monster_definition_id = target_definition.id
   AND target_stat.id <> source_stat.id
 WHERE source_stat.world_id = ? AND source_definition.ruleset_version_id = ?
 LIMIT 1
SQL, [$targetRulesetId ?? -1, $worldId, $sourceRulesetId]);
        if ($duplicate !== null) {
            throw new RuntimeException(
                "Monster kill stat collision {$duplicate->source_id}->{$duplicate->target_id}; refusing to merge aggregates.",
            );
        }

        return ['stats' => $rows->count()];
    }

    /**
     * @param  array<string, mixed>  $sourceSettings
     * @param  array<string, mixed>  $targetSettings
     */
    private function assertTargetDefinitionContracts(
        int $sourceRulesetId,
        int $targetRulesetId,
        array $sourceSettings,
        array $targetSettings,
    ): void {
        $this->assertExactKeys(
            'v11 command definitions',
            DB::table('command_definitions')->where('ruleset_version_id', $targetRulesetId)->orderBy('key')->pluck('key')->all(),
            $this->settingsDefinitionKeys($targetSettings, 'command_definitions'),
        );
        $this->assertExactKeys(
            'v11 production definitions',
            DB::table('production_definitions')->where('ruleset_version_id', $targetRulesetId)->orderBy('key')->pluck('key')->all(),
            $this->settingsDefinitionKeys($targetSettings, 'production_definitions'),
        );
        $this->assertExactKeys(
            'v11 monster definitions',
            DB::table('monster_definitions')->where('ruleset_version_id', $targetRulesetId)->orderBy('key')->pluck('key')->all(),
            [...self::HISTORICAL_MONSTER_KEYS, 'aoi_inora', 'mecha_inora_zero'],
        );

        $sourceCommands = CommandDefinition::query()->where('ruleset_version_id', $sourceRulesetId)->get()->keyBy('key');
        $targetCommands = CommandDefinition::query()->where('ruleset_version_id', $targetRulesetId)->get()->keyBy('key');
        foreach ($sourceCommands as $key => $source) {
            $target = $targetCommands->get($key);
            if (! $target instanceof CommandDefinition || ! $target->enabled
                || $source->target_type !== $target->target_type
                || $source->execution_phase !== $target->execution_phase
                || $this->canonicalJson($source->metadata['parameters'] ?? [])
                    !== $this->canonicalJson($target->metadata['parameters'] ?? [])) {
                throw new RuntimeException("Command definition {$key} is not execution-compatible between v10 and v11.");
            }
        }

        $sourceMonsters = DB::table('monster_definitions')->where('ruleset_version_id', $sourceRulesetId)
            ->get(['key', 'base_hp', 'hp_variation'])->keyBy('key');
        $targetMonsters = DB::table('monster_definitions')->where('ruleset_version_id', $targetRulesetId)
            ->get(['key', 'base_hp', 'hp_variation'])->keyBy('key');
        foreach (self::HISTORICAL_MONSTER_KEYS as $key) {
            $source = $sourceMonsters->get($key);
            $target = $targetMonsters->get($key);
            if ($source === null || $target === null || $source->base_hp !== $target->base_hp
                || $source->hp_variation !== $target->hp_variation) {
                throw new RuntimeException("Monster definition {$key} has incompatible HP bounds between v10 and v11.");
            }
        }

        $this->assertExactKeys(
            'v10/v11 command stable keys',
            $this->settingsDefinitionKeys($sourceSettings, 'command_definitions'),
            $this->settingsDefinitionKeys($targetSettings, 'command_definitions'),
        );
        $this->assertExactKeys(
            'v10/v11 production stable keys',
            $this->settingsDefinitionKeys($sourceSettings, 'production_definitions'),
            $this->settingsDefinitionKeys($targetSettings, 'production_definitions'),
        );
    }

    /** @return array{definition: string, function: string} */
    private function captureEnabledTrigger(string $table, string $trigger): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT t.tgenabled,
       pg_get_triggerdef(t.oid, true) AS definition,
       pg_get_functiondef(t.tgfoid) AS function
  FROM pg_trigger t
 WHERE t.tgrelid = ?::regclass AND t.tgname = ? AND NOT t.tgisinternal
SQL, [$table, $trigger]);
        if ($row === null || $row->tgenabled !== 'O') {
            throw new RuntimeException("{$trigger} must exist exactly once and be enabled before migration.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    /** @param array{definition: string, function: string} $expected */
    private function assertTriggerRestored(string $table, string $trigger, array $expected): void
    {
        $actual = $this->captureEnabledTrigger($table, $trigger);
        if ($actual !== $expected) {
            throw new RuntimeException("{$trigger} definition changed during v11 migration.");
        }
    }

    private function assertPostconditions(
        int $worldId,
        int $sourceRulesetId,
        int $targetRulesetId,
        bool $allowV11TerminalHistory,
    ): void {
        $worldRuleset = (int) DB::table('worlds')->where('id', $worldId)->value('ruleset_version_id');
        if ($worldRuleset !== $targetRulesetId) {
            throw new RuntimeException('shared-world was not activated on exact v11.');
        }
        $counts = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*) FROM nation_command_queue_items item
      JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
      JOIN nations nation ON nation.id = queue.nation_id
      JOIN command_definitions definition ON definition.id = item.command_definition_id
     WHERE nation.world_id = ? AND item.status = 'queued'
       AND (definition.ruleset_version_id <> ? OR item.request_ruleset_version_id IS NULL)) AS queue_mismatches,
    (SELECT count(*) FROM monster_instances instance
      JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
     WHERE instance.world_id = ? AND instance.state = 'alive'
       AND definition.ruleset_version_id <> ?) AS instance_mismatches,
    (SELECT count(*) FROM nation_monster_kill_stats stat
      JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
     WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stat_mismatches,
    (SELECT count(*) FROM monster_instances instance
      JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
     WHERE instance.world_id = ? AND instance.state IN ('killed', 'removed')
       AND definition.ruleset_version_id = ?) AS rewritten_history
SQL, [
            $worldId, $targetRulesetId,
            $worldId, $targetRulesetId,
            $worldId, $targetRulesetId,
            $worldId, $targetRulesetId,
        ]);
        if ((int) $counts->queue_mismatches !== 0 || (int) $counts->instance_mismatches !== 0
            || (int) $counts->stat_mismatches !== 0
            || (! $allowV11TerminalHistory && (int) $counts->rewritten_history !== 0)) {
            throw new RuntimeException(
                "v11 postcondition mismatch (queue={$counts->queue_mismatches}, instances={$counts->instance_mismatches}, "
                ."stats={$counts->stat_mismatches}, history={$counts->rewritten_history}).",
            );
        }
        $this->captureEnabledTrigger('monster_instances', self::MONSTER_INSTANCE_TRIGGER);
        $this->captureEnabledTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
        $this->captureEnabledTrigger('nation_command_queue_items', self::QUEUE_CONSTRAINT);

        $partialSource = DB::table('nation_command_queue_items as item')
            ->join('nation_command_queues as queue', 'queue.id', '=', 'item.nation_command_queue_id')
            ->join('nations as nation', 'nation.id', '=', 'queue.nation_id')
            ->where('nation.world_id', $worldId)
            ->where('item.status', 'queued')
            ->whereNull('item.request_ruleset_version_id')
            ->count();
        if ($partialSource !== 0 || $sourceRulesetId === $targetRulesetId) {
            throw new RuntimeException('Request provenance or ruleset identity postcondition failed.');
        }
    }

    /** @param array<int, string|null> $expected */
    private function assertFingerprintsUnchanged(array $expected): void
    {
        if ($expected === []) {
            return;
        }
        $actual = DB::table('nation_command_queue_items')->whereIn('id', array_keys($expected))
            ->orderBy('id')->pluck('request_fingerprint', 'id')->all();
        if ($actual !== $expected) {
            throw new RuntimeException('Request fingerprint bytes changed during v11 migration.');
        }
    }

    /** @param (Closure(string): void)|null $failureInjector */
    private function injectFailure(?Closure $failureInjector, string $stage): void
    {
        $failureInjector?->__invoke($stage);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function settingsDefinitionKeys(array $settings, string $key): array
    {
        $definitions = $settings[$key] ?? null;
        if (! is_array($definitions)) {
            throw new RuntimeException("Ruleset section {$key} is missing.");
        }
        $keys = [];
        foreach ($definitions as $definition) {
            if (! is_array($definition) || ! is_string($definition['key'] ?? null)) {
                throw new RuntimeException("Ruleset section {$key} has malformed definitions.");
            }
            $keys[] = $definition['key'];
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function settingsMapKeys(array $settings, string $key): array
    {
        $definitions = $settings[$key] ?? null;
        if (! is_array($definitions) || array_is_list($definitions)) {
            throw new RuntimeException("Ruleset section {$key} is missing or is not a stable-key map.");
        }

        foreach ($definitions as $definitionKey => $definition) {
            if (! is_string($definitionKey) || $definitionKey === '' || ! is_array($definition)) {
                throw new RuntimeException("Ruleset section {$key} has malformed definitions.");
            }
        }

        return array_keys($definitions);
    }

    /**
     * @param  list<string>  $actual
     * @param  list<string>  $expected
     */
    private function assertExactKeys(string $label, array $actual, array $expected): void
    {
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || count($actual) !== count(array_unique($actual))) {
            throw new RuntimeException("{$label} has missing, unknown, or duplicate stable keys.");
        }
    }

    /** @param array<string, mixed> $settings */
    private function settingsChecksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
}
