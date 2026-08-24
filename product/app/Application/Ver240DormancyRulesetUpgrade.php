<?php

namespace App\Application;

use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver240DormancyRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v11';

    public const SOURCE_VERSION = 11;

    public const SOURCE_CHECKSUM = Ver240InstallUpgradeRebaseline::CURRENT_CHECKSUM;

    public const TARGET_KEY = 'hakoniwa-2s-plus-v12';

    public const TARGET_VERSION = 12;

    public const TARGET_CHECKSUM = 'cf55370616b56822fe6807f29cdaec6cb0fd3d9bcc12849d3e61df015bdf656e';

    public const SOURCE_MIGRATION = '2026_08_22_000000_rebaseline_ver_2_4_install_and_upgrade';

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
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v11.php');
        $targetSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v12.php');
        if (! is_array($targetSettings)
            || ($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException('The exact v11/v12 Ruleset authoring required by the dormancy upgrade is missing or changed.');
        }

        return DB::transaction(function () use ($sourceSettings, $targetSettings): string {
            $this->lockBusinessTables();
            $worlds = World::query()->orderBy('id')->lockForUpdate()->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
            if ($worlds->isEmpty()) {
                if (RulesetVersion::query()->where('version', '>', self::TARGET_VERSION)->exists()) {
                    return 'fresh_install_future_current';
                }
                $this->catalogs->assertInstalled($targetSettings);
                $this->publisher->assertPublished($targetSettings);

                return 'fresh_install_current_v12';
            }
            if ($worlds->count() !== 1 || $worlds->first()->key !== self::WORLD_KEY) {
                throw new RuntimeException('Dormancy upgrade supports exactly one shared-world.');
            }

            $world = $worlds->first();
            $source = $this->publisher->assertPublished($sourceSettings);
            $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
            $existingTargetId = $existingTarget === null ? -1 : (int) $existingTarget->id;
            if ((int) $world->ruleset_version_id === $existingTargetId) {
                $target = $this->publisher->assertPublished($targetSettings);
                $this->assertPostconditions((int) $world->id, (int) $target->id);

                return 'already_current_v12';
            }
            if (! DB::table('migrations')->where('migration', self::SOURCE_MIGRATION)->exists()
                || (int) $world->ruleset_version_id !== (int) $source->id) {
                throw new RuntimeException('Dormancy upgrade requires the exact supported ver 2.4.0/v11 source.');
            }
            $unresolved = TurnRun::query()->unresolvedProduction()->orderBy('id')->first(['id', 'status']);
            if ($unresolved instanceof TurnRun) {
                throw new RuntimeException(
                    "Dormancy upgrade blocked: unresolved non-dry TurnRun {$unresolved->id} has status {$unresolved->status}.",
                );
            }
            $invalidNation = DB::table('nations')
                ->whereNotIn('state', ['active', 'abandoned'])
                ->orderBy('id')->first(['id', 'state']);
            if ($invalidNation !== null) {
                throw new RuntimeException(
                    "Dormancy upgrade cannot reinterpret Nation {$invalidNation->id} state {$invalidNation->state}.",
                );
            }

            $fingerprints = DB::table('nation_command_queue_items')->orderBy('id')
                ->pluck('request_fingerprint', 'id')->all();
            $idleCounters = DB::table('nations')->orderBy('id')->pluck('idle_counter', 'id')->all();
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
                throw new RuntimeException('shared-world changed during the dormancy upgrade.');
            }

            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
            if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
                || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
                throw new RuntimeException('A gameplay integrity trigger changed during the dormancy upgrade.');
            }

            $this->assertPostconditions((int) $world->id, (int) $target->id);
            if ($fingerprints !== DB::table('nation_command_queue_items')->orderBy('id')
                ->pluck('request_fingerprint', 'id')->all()
                || $idleCounters !== DB::table('nations')->orderBy('id')->pluck('idle_counter', 'id')->all()
                || $secretaryState !== $this->tableDigest('secretaries').':'.$this->tableDigest('secretary_skills')
                    .':'.$this->tableDigest('secretary_item_instances')) {
                throw new RuntimeException('Dormancy upgrade changed protected request, Nation counter, or Secretary data.');
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
                'event_type' => 'ruleset.v12_activated',
                'severity' => 'info',
                'subject_type' => $world->getMorphClass(),
                'subject_id' => $world->id,
                'metadata' => json_encode([
                    'source_key' => self::SOURCE_KEY,
                    'target_key' => self::TARGET_KEY,
                    'target_checksum' => self::TARGET_CHECKSUM,
                    'existing_idle_counters_preserved' => true,
                    'request_fingerprints_preserved' => true,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 'production_v11_to_v12';
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
                throw new RuntimeException("{$table} keys are not stable across exact v11 to v12.");
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
            throw new RuntimeException('Exact v12 activation postconditions failed.');
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
            throw new RuntimeException("{$trigger} must be enabled for the dormancy upgrade.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    private function tableDigest(string $table): string
    {
        return hash('sha256', json_encode(
            DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all(),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    /** @param array<string, mixed> $settings */
    private function checksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
