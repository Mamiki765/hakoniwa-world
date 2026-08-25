<?php

namespace App\Application;

use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver260OilResourceRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v15';

    public const SOURCE_VERSION = 15;

    public const SOURCE_CHECKSUM = Ver250MonsterExperienceRulesetUpgrade::TARGET_CHECKSUM;

    public const TARGET_KEY = 'hakoniwa-2s-plus-v16';

    public const TARGET_VERSION = 16;

    public const TARGET_CHECKSUM = '46720b62518c0b65f2be2698c4263c2a94e03caa723cc3c3b10aa932fcf39668';

    public const SOURCE_MIGRATION = '2026_08_24_010000_add_monster_experience_and_publish_v15';

    private const WORLD_KEY = 'shared-world';

    private const RESOURCE_KEY = 'oil';

    private const QUEUE_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const MONSTER_TRIGGER = 'monster_instance_world_ruleset_guard';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    private const DIGEST_PAGE_SIZE = 250;

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['cache', 'cache_locks', 'migrations', 'sessions'];

    public function __construct(
        private RulesetPublisher $publisher,
        private CurrentCatalogInstaller $catalogs,
    ) {}

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v15.php');
        $targetSettings = config('hakoniwa.ruleset');
        if (! is_array($targetSettings)
            || ($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException(
                'The exact v15/v16 Ruleset authoring required by the oil resource upgrade is missing or changed.',
            );
        }

        return DB::transaction(function () use ($sourceSettings, $targetSettings): string {
            $this->lockBusinessTables();
            $worlds = World::query()->orderBy('id')->lockForUpdate()
                ->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
            if ($worlds->isEmpty()) {
                $this->catalogs->install($targetSettings);
                $this->catalogs->assertInstalled($targetSettings);
                $this->publisher->publish($targetSettings);

                return 'fresh_install_current_v16';
            }
            if ($worlds->count() !== 1 || $worlds->first()->key !== self::WORLD_KEY) {
                throw new RuntimeException('Oil resource upgrade supports exactly one shared-world.');
            }

            $world = $worlds->first();
            $source = $this->publisher->assertPublished($sourceSettings);
            $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
            if ($existingTarget instanceof RulesetVersion
                && (int) $world->ruleset_version_id === (int) $existingTarget->id) {
                $target = $this->publisher->assertPublished($targetSettings);
                $this->catalogs->assertInstalled($targetSettings);
                $this->assertPostconditions((int) $world->id, (int) $target->id, false);

                return 'already_current_v16';
            }
            if (! DB::table('migrations')->where('migration', self::SOURCE_MIGRATION)->exists()
                || (int) $world->ruleset_version_id !== (int) $source->id) {
                throw new RuntimeException(
                    'Oil resource upgrade requires the exact supported ver 2.5.0/v15 source.',
                );
            }
            $unresolved = TurnRun::query()->unresolvedProduction()->orderBy('id')->first(['id', 'status']);
            if ($unresolved instanceof TurnRun) {
                throw new RuntimeException(
                    "Oil resource upgrade blocked: unresolved non-dry TurnRun {$unresolved->id} has status {$unresolved->status}.",
                );
            }
            $this->catalogs->assertInstalled($sourceSettings);

            $requestIdentity = $this->queryDigest(DB::table('nation_command_queue_items')->select([
                'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
            ]));
            $terminalHistory = $this->queryDigest(DB::table('nation_command_queue_items')
                ->where('status', '<>', 'queued'));
            $nationState = $this->tableDigest('nations');
            $mapState = $this->tableDigest('map_cells');
            $existingResources = $this->resourceStateDigest('nation_resources');
            $existingPolicies = $this->resourceStateDigest('nation_resource_sale_policies');
            $auditHistory = $this->tableDigest('audit_events');

            $this->catalogs->install($targetSettings);
            $this->catalogs->assertInstalled($targetSettings);
            $target = $this->publisher->publish($targetSettings);
            $this->assertStableDefinitionKeys((int) $source->id, (int) $target->id);
            $oil = ResourceDefinition::query()->where('key', self::RESOURCE_KEY)->sole();
            $nationCount = DB::table('nations')->count();
            if (DB::table('nation_resources')->where('resource_definition_id', $oil->id)->exists()
                || DB::table('nation_resource_sale_policies')->where('resource_definition_id', $oil->id)->exists()) {
                throw new RuntimeException('Oil resource state exists before the exact v15 to v16 backfill.');
            }

            $now = now();
            DB::insert(<<<'SQL'
INSERT INTO nation_resources
       (nation_id, resource_definition_id, amount, created_at, updated_at)
SELECT id, ?, 0, ?, ?
  FROM nations
 ORDER BY id
SQL, [$oil->id, $now, $now]);
            DB::insert(<<<'SQL'
INSERT INTO nation_resource_sale_policies
       (nation_id, resource_definition_id, policy, keep_amount, version, created_at, updated_at)
SELECT id, ?, ?, NULL, 1, ?, ?
  FROM nations
 ORDER BY id
SQL, [$oil->id, $targetSettings['default_sale_policy'], $now, $now]);

            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
            $monsterTrigger = $this->captureTrigger('monster_instances', self::MONSTER_TRIGGER);
            $statTrigger = $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
            DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);

            $this->rebindCurrentDefinitions((int) $world->id, (int) $source->id, (int) $target->id);
            if (DB::table('worlds')->where('id', $world->id)
                ->where('ruleset_version_id', $source->id)
                ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('shared-world changed during the oil resource upgrade.');
            }

            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
            if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
                || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
                throw new RuntimeException('A gameplay integrity trigger changed during the oil resource upgrade.');
            }

            $this->assertPostconditions((int) $world->id, (int) $target->id, true);
            $changedProtectedData = array_keys(array_filter([
                'request_identity' => $requestIdentity !== $this->queryDigest(
                    DB::table('nation_command_queue_items')->select([
                        'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
                    ]),
                ),
                'terminal_command_history' => $terminalHistory !== $this->queryDigest(
                    DB::table('nation_command_queue_items')->where('status', '<>', 'queued'),
                ),
                'nation_state' => $nationState !== $this->tableDigest('nations'),
                'map_state' => $mapState !== $this->tableDigest('map_cells'),
                'existing_resources' => $existingResources !== $this->resourceStateDigest('nation_resources'),
                'existing_sale_policies' => $existingPolicies
                    !== $this->resourceStateDigest('nation_resource_sale_policies'),
                'audit_history' => $auditHistory !== $this->tableDigest('audit_events'),
            ]));
            if ($changedProtectedData !== []) {
                throw new RuntimeException(
                    'Oil resource upgrade changed protected data: '.implode(', ', $changedProtectedData).'.',
                );
            }

            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'world_id' => $world->id,
                'turn' => $world->current_turn,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => 'admin',
                'event_type' => 'ruleset.v16_activated',
                'severity' => 'info',
                'subject_type' => $world->getMorphClass(),
                'subject_id' => $world->id,
                'metadata' => json_encode([
                    'source_key' => self::SOURCE_KEY,
                    'target_key' => self::TARGET_KEY,
                    'target_checksum' => self::TARGET_CHECKSUM,
                    'oil_resource_rows_added' => $nationCount,
                    'oil_sale_policy_rows_added' => $nationCount,
                    'initial_oil_units' => 0,
                    'existing_resource_balances_preserved' => true,
                    'existing_sale_policies_preserved' => true,
                    'request_identity_preserved' => true,
                    'terminal_command_history_preserved' => true,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 'production_v15_to_v16';
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

    private function rebindCurrentDefinitions(int $worldId, int $sourceId, int $targetId): void
    {
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
SQL, [$sourceId, $targetId, $worldId]);
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
SQL, [$targetId, $worldId, $sourceId]);
        DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE stat.world_id = ?
   AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$targetId, $worldId, $sourceId]);
    }

    private function assertStableDefinitionKeys(int $sourceId, int $targetId): void
    {
        foreach (['command_definitions', 'production_definitions', 'monster_definitions'] as $table) {
            $source = DB::table($table)->where('ruleset_version_id', $sourceId)->orderBy('key')->pluck('key')->all();
            $target = DB::table($table)->where('ruleset_version_id', $targetId)->orderBy('key')->pluck('key')->all();
            if ($source !== $target || $source === []) {
                throw new RuntimeException("{$table} keys are not stable across exact v15 to v16.");
            }
        }
    }

    private function assertPostconditions(int $worldId, int $targetId, bool $requireInitialState): void
    {
        $oil = ResourceDefinition::query()->where('key', self::RESOURCE_KEY)->sole();
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
     WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stats,
    (SELECT count(*) FROM nations nation
      LEFT JOIN nation_resources balance
        ON balance.nation_id = nation.id AND balance.resource_definition_id = ?
     WHERE balance.id IS NULL) AS missing_balances,
    (SELECT count(*) FROM nations nation
      LEFT JOIN nation_resource_sale_policies policy
        ON policy.nation_id = nation.id AND policy.resource_definition_id = ?
     WHERE policy.id IS NULL) AS missing_policies
SQL, [$worldId, $targetId, $worldId, $targetId, $worldId, $targetId, $oil->id, $oil->id]);
        $invalidInitialBalances = $requireInitialState
            ? DB::table('nation_resources')->where('resource_definition_id', $oil->id)->where('amount', '<>', 0)->count()
            : 0;
        $invalidInitialPolicies = $requireInitialState
            ? DB::table('nation_resource_sale_policies')->where('resource_definition_id', $oil->id)
                ->where(static function (Builder $query): void {
                    $query->where('policy', '<>', 'stockpile')
                        ->orWhereNotNull('keep_amount')
                        ->orWhere('version', '<>', 1);
                })->count()
            : 0;
        if ((int) DB::table('worlds')->where('id', $worldId)->value('ruleset_version_id') !== $targetId
            || (int) $mismatches->queued !== 0
            || (int) $mismatches->monsters !== 0
            || (int) $mismatches->stats !== 0
            || (int) $mismatches->missing_balances !== 0
            || (int) $mismatches->missing_policies !== 0
            || $invalidInitialBalances !== 0
            || $invalidInitialPolicies !== 0) {
            throw new RuntimeException('Exact v16 activation postconditions failed.');
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
            throw new RuntimeException("{$trigger} must be enabled for the oil resource upgrade.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    private function resourceStateDigest(string $table): string
    {
        return $this->queryDigest(DB::table("{$table} as state")
            ->join('resource_definitions as definition', 'definition.id', '=', 'state.resource_definition_id')
            ->where('definition.key', '<>', self::RESOURCE_KEY)
            ->select('state.*'), 'state.id');
    }

    private function tableDigest(string $table): string
    {
        return $this->queryDigest(DB::table($table));
    }

    private function queryDigest(Builder $query, string $idColumn = 'id'): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, '[');
        $first = true;
        foreach ($query->lazyById(self::DIGEST_PAGE_SIZE, $idColumn, 'id') as $row) {
            if (! $first) {
                hash_update($hash, ',');
            }
            hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
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
