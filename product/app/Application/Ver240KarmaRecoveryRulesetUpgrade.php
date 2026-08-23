<?php

namespace App\Application;

use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver240KarmaRecoveryRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v12';

    public const SOURCE_VERSION = 12;

    public const SOURCE_CHECKSUM = Ver240DormancyRulesetUpgrade::TARGET_CHECKSUM;

    public const TARGET_KEY = 'hakoniwa-2s-plus-v13';

    public const TARGET_VERSION = 13;

    public const TARGET_CHECKSUM = '27c5d58d80e55bf2807cecd147b99b80e57ea0e1afd836eea150982445723b1f';

    public const SOURCE_MIGRATION = '2026_08_23_000000_add_nation_dormancy_and_publish_v12';

    private const WORLD_KEY = 'shared-world';

    private const QUEUE_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const MONSTER_TRIGGER = 'monster_instance_world_ruleset_guard';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['cache', 'cache_locks', 'migrations', 'sessions'];

    public function __construct(
        private RulesetPublisher $publisher,
        private CurrentCatalogInstaller $catalogs,
    ) {}

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v12.php');
        $targetSettings = config('hakoniwa.ruleset');
        if (! is_array($targetSettings)
            || ($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException('The exact v12/v13 Ruleset authoring required by the KARMA/recovery upgrade is missing or changed.');
        }

        return DB::transaction(function () use ($sourceSettings, $targetSettings): string {
            $this->lockBusinessTables();
            $worlds = World::query()->orderBy('id')->lockForUpdate()->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
            if ($worlds->isEmpty()) {
                $this->catalogs->assertInstalled($targetSettings);
                $this->publisher->assertPublished($targetSettings);

                return 'fresh_install_current_v13';
            }
            if ($worlds->count() !== 1 || $worlds->first()->key !== self::WORLD_KEY) {
                throw new RuntimeException('KARMA/recovery upgrade supports exactly one shared-world.');
            }

            $world = $worlds->first();
            $source = $this->publisher->assertPublished($sourceSettings);
            $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
            $existingTargetId = $existingTarget === null ? -1 : (int) $existingTarget->id;
            if ((int) $world->ruleset_version_id === $existingTargetId) {
                $target = $this->publisher->assertPublished($targetSettings);
                $this->assertPostconditions((int) $world->id, (int) $target->id);

                return 'already_current_v13';
            }
            if (! DB::table('migrations')->where('migration', self::SOURCE_MIGRATION)->exists()
                || (int) $world->ruleset_version_id !== (int) $source->id) {
                throw new RuntimeException('KARMA/recovery upgrade requires the exact supported ver 2.4.0/v12 source.');
            }
            $unresolved = TurnRun::query()->unresolvedProduction()->orderBy('id')->first(['id', 'status']);
            if ($unresolved instanceof TurnRun) {
                throw new RuntimeException(
                    "KARMA/recovery upgrade blocked: unresolved non-dry TurnRun {$unresolved->id} has status {$unresolved->status}.",
                );
            }
            $invalidNation = DB::table('nations')
                ->whereNotIn('state', ['active', 'dormant', 'abandoned'])
                ->orderBy('id')->first(['id', 'state']);
            if ($invalidNation !== null) {
                throw new RuntimeException(
                    "KARMA/recovery upgrade cannot reinterpret Nation {$invalidNation->id} state {$invalidNation->state}.",
                );
            }
            if (DB::table('nations')->where('karma', '<>', 0)->exists()) {
                throw new RuntimeException('Existing Nations must enter the v13 upgrade with initial KARMA 0.');
            }

            $fingerprints = $this->rowsDigest(DB::table('nation_command_queue_items')
                ->select(['id', 'request_fingerprint'])->orderBy('id')->cursor());
            $idleCounters = $this->rowsDigest(DB::table('nations')
                ->select(['id', 'idle_counter'])->orderBy('id')->cursor());
            $lifecycleState = $this->rowsDigest(DB::table('nations')->select([
                'id', 'state', 'state_reason', 'state_started_turn', 'resume_at_turn', 'idle_counter', 'karma',
            ])->orderBy('id')->cursor());
            $requestIdentity = $this->rowsDigest(DB::table('nation_command_queue_items')->select([
                'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
            ])->orderBy('id')->cursor());
            $terminalHistory = $this->rowsDigest(DB::table('nation_command_queue_items')
                ->where('status', '<>', 'queued')->orderBy('id')->cursor());
            $monsterState = $this->rowsDigest(DB::table('monster_instances')->select([
                'id', 'current_hp', 'spawned_max_hp', 'state', 'spawned_target_turn',
                'removal_reason', 'removed_at', 'version',
            ])->orderBy('id')->cursor());
            $occupancyState = $this->tableDigest('monster_occupancies');
            $auditHistory = $this->tableDigest('audit_events');
            $secretaryState = $this->tableDigest('secretaries').':'.$this->tableDigest('secretary_skills')
                .':'.$this->tableDigest('secretary_item_instances');

            $target = $this->publisher->publish($targetSettings);
            $this->assertStableDefinitionKeys((int) $source->id, (int) $target->id);

            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
            $monsterTrigger = $this->captureTrigger('monster_instances', self::MONSTER_TRIGGER);
            $statTrigger = $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
            DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);

            DB::update(<<<'SQL'
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
            DB::update(<<<'SQL'
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
            DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE stat.world_id = ?
   AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$target->id, $world->id, $source->id]);

            $updated = DB::table('worlds')->where('id', $world->id)
                ->where('ruleset_version_id', $source->id)
                ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]);
            if ($updated !== 1) {
                throw new RuntimeException('shared-world changed during the KARMA/recovery upgrade.');
            }

            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
            if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
                || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
                throw new RuntimeException('A gameplay integrity trigger changed during the KARMA/recovery upgrade.');
            }

            $this->assertPostconditions((int) $world->id, (int) $target->id);
            $changedProtectedData = array_keys(array_filter([
                'request_fingerprints' => $fingerprints !== $this->rowsDigest(
                    DB::table('nation_command_queue_items')->select(['id', 'request_fingerprint'])
                        ->orderBy('id')->cursor(),
                ),
                'nation_idle_counters' => $idleCounters !== $this->rowsDigest(
                    DB::table('nations')->select(['id', 'idle_counter'])->orderBy('id')->cursor(),
                ),
                'nation_lifecycle' => $lifecycleState !== $this->rowsDigest(DB::table('nations')->select([
                    'id', 'state', 'state_reason', 'state_started_turn', 'resume_at_turn', 'idle_counter', 'karma',
                ])->orderBy('id')->cursor()),
                'request_identity' => $requestIdentity !== $this->rowsDigest(
                    DB::table('nation_command_queue_items')->select([
                        'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
                    ])->orderBy('id')->cursor(),
                ),
                'terminal_command_history' => $terminalHistory !== $this->rowsDigest(
                    DB::table('nation_command_queue_items')->where('status', '<>', 'queued')->orderBy('id')->cursor(),
                ),
                'live_monster_state' => $monsterState !== $this->rowsDigest(DB::table('monster_instances')->select([
                    'id', 'current_hp', 'spawned_max_hp', 'state', 'spawned_target_turn',
                    'removal_reason', 'removed_at', 'version',
                ])->orderBy('id')->cursor()),
                'monster_occupancies' => $occupancyState !== $this->tableDigest('monster_occupancies'),
                'audit_events' => $auditHistory !== $this->tableDigest('audit_events'),
                'secretary_state' => $secretaryState !== $this->tableDigest('secretaries')
                    .':'.$this->tableDigest('secretary_skills').':'.$this->tableDigest('secretary_item_instances'),
            ]));
            if ($changedProtectedData !== []) {
                throw new RuntimeException(
                    'KARMA/recovery upgrade changed protected data: '.implode(', ', $changedProtectedData).'.',
                );
            }

            $now = now();
            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'world_id' => $world->id,
                'turn' => $world->current_turn,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'admin',
                'event_type' => 'ruleset.v13_activated',
                'severity' => 'info',
                'subject_type' => $world->getMorphClass(),
                'subject_id' => $world->id,
                'metadata' => json_encode([
                    'source_key' => self::SOURCE_KEY,
                    'target_key' => self::TARGET_KEY,
                    'target_checksum' => self::TARGET_CHECKSUM,
                    'existing_idle_counters_preserved' => true,
                    'existing_karma_initialized_to_zero' => true,
                    'request_fingerprints_preserved' => true,
                    'historical_request_provenance_preserved' => true,
                    'terminal_command_history_preserved' => true,
                    'live_monster_state_preserved' => true,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 'production_v12_to_v13';
        }, 1);
    }

    private function lockBusinessTables(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn (string $table): bool => ! in_array($table, self::INFRASTRUCTURE_TABLES, true),
        ));
        sort($tables, SORT_STRING);
        foreach ($tables as $table) {
            DB::statement('LOCK TABLE '.$grammar->wrapTable($table).' IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function assertStableDefinitionKeys(int $sourceId, int $targetId): void
    {
        foreach (['command_definitions', 'production_definitions', 'monster_definitions'] as $table) {
            $source = DB::table($table)->where('ruleset_version_id', $sourceId)->orderBy('key')->pluck('key')->all();
            $target = DB::table($table)->where('ruleset_version_id', $targetId)->orderBy('key')->pluck('key')->all();
            if ($source !== $target || $source === []) {
                throw new RuntimeException("{$table} keys are not stable across exact v12 to v13.");
            }
        }
    }

    private function assertPostconditions(int $worldId, int $targetId): void
    {
        $mismatches = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*) FROM nation_command_queue_items item
      JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
      JOIN nations nation ON nation.id = queue.nation_id
      JOIN command_definitions definition ON definition.id = item.command_definition_id
     WHERE nation.world_id = ? AND item.status = 'queued' AND definition.ruleset_version_id <> ?) AS queued,
    (SELECT count(*) FROM monster_instances instance
      JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
     WHERE instance.world_id = ? AND instance.state = 'alive' AND definition.ruleset_version_id <> ?) AS monsters,
    (SELECT count(*) FROM nation_monster_kill_stats stat
      JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
     WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stats
SQL, [$worldId, $targetId, $worldId, $targetId, $worldId, $targetId]);
        if ((int) DB::table('worlds')->where('id', $worldId)->value('ruleset_version_id') !== $targetId
            || (int) $mismatches->queued !== 0
            || (int) $mismatches->monsters !== 0
            || (int) $mismatches->stats !== 0) {
            throw new RuntimeException('Exact v13 activation postconditions failed.');
        }
    }

    /** @return array{definition: string, function: string} */
    private function captureTrigger(string $table, string $trigger): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT t.tgenabled, pg_get_triggerdef(t.oid, true) AS definition,
       pg_get_functiondef(t.tgfoid) AS function
  FROM pg_trigger t
 WHERE t.tgrelid = ?::regclass AND t.tgname = ? AND NOT t.tgisinternal
SQL, [$table, $trigger]);
        if ($row === null || $row->tgenabled !== 'O') {
            throw new RuntimeException("{$trigger} must be enabled for the KARMA/recovery upgrade.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    private function tableDigest(string $table): string
    {
        return $this->rowsDigest(DB::table($table)->orderBy('id')->cursor());
    }

    /** @param iterable<int, object> $rows */
    private function rowsDigest(iterable $rows): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, '[');
        $first = true;
        foreach ($rows as $row) {
            if (! $first) {
                hash_update($hash, ',');
            }
            hash_update($hash, json_encode(
                (array) $row,
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            ));
            $first = false;
        }
        hash_update($hash, ']');

        return hash_final($hash);
    }

    /** @param array<string, mixed> $settings */
    private function checksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
