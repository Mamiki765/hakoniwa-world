<?php

namespace App\Application;

use App\Domain\World\WorldMutationLock;
use App\Models\RulesetVersion;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver310TerritoryAbandonmentRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v18';

    public const SOURCE_VERSION = 18;

    public const SOURCE_CHECKSUM = '40bb900705776bf82e69e11b4f6f9aeed433988599aa0690cfd6088964e16f8b';

    public const TARGET_KEY = 'hakoniwa-2s-plus-v19';

    public const TARGET_VERSION = 19;

    public const TARGET_CHECKSUM = 'b65752b88e9daf3c9b64e6d28b72847315d521dfe65b704f4cd8fd622e1368c9';

    private const WORLD_KEY = 'shared-world';

    private const QUEUE_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const MONSTER_TRIGGER = 'monster_instance_world_ruleset_guard';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['cache', 'cache_locks', 'migrations', 'sessions'];

    public function __construct(
        private CurrentCatalogInstaller $catalogInstaller,
        private RulesetPublisher $publisher,
        private WorldMutationLock $worldMutationLock,
        private NextProductionTurnRunGuard $turnRunGuard,
    ) {}

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v18.php');
        $targetSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v19.php');
        if (($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException('The exact immutable v18 and authored v19 Rulesets required by the ver 3.1.0 upgrade are missing or changed.');
        }

        $world = World::query()->orderBy('id')->first();
        if (! $world instanceof World) {
            return DB::transaction(function () use ($targetSettings): string {
                $this->lockBusinessTables();
                if (World::query()->exists()) {
                    throw new RuntimeException('A World appeared while publishing fresh-install v19.');
                }
                $this->catalogInstaller->install($targetSettings);
                $this->publisher->publish($targetSettings);

                return 'fresh_install_current_v19';
            }, 1);
        }

        $this->worldMutationLock->acquire($world);
        try {
            return DB::transaction(
                fn (): string => $this->upgradeLockedWorld($world, $sourceSettings, $targetSettings),
                1,
            );
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    /**
     * @param  array<string, mixed>  $sourceSettings
     * @param  array<string, mixed>  $targetSettings
     */
    private function upgradeLockedWorld(World $advisoryWorld, array $sourceSettings, array $targetSettings): string
    {
        $this->lockBusinessTables();
        $worlds = World::query()->orderBy('id')->lockForUpdate()
            ->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($worlds->count() !== 1 || (int) $worlds->first()->id !== (int) $advisoryWorld->id
            || $worlds->first()->key !== self::WORLD_KEY) {
            throw new RuntimeException('The v19 upgrade supports exactly one locked shared-world.');
        }
        /** @var World $world */
        $world = $worlds->first();
        $this->turnRunGuard->assertClear($world);
        $source = $this->publisher->assertPublished($sourceSettings);
        $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
        if ($existingTarget instanceof RulesetVersion
            && (int) $world->ruleset_version_id === (int) $existingTarget->id) {
            $this->catalogInstaller->assertInstalled($targetSettings);
            $target = $this->publisher->assertPublished($targetSettings);
            $reconciled = $this->reconcileTrialOneAreaEntitlement();
            $this->assertPostconditions((int) $world->id, (int) $target->id);

            return $reconciled > 0 ? 'already_current_v19_reconciled' : 'already_current_v19';
        }
        if ((int) $world->ruleset_version_id !== (int) $source->id) {
            throw new RuntimeException('The v19 upgrade requires the mutable shared-world to be exact v18.');
        }

        $protected = $this->protectedDigests();
        $this->catalogInstaller->install($targetSettings);
        $target = $this->publisher->publish($targetSettings);
        $this->assertDefinitionKeys((int) $source->id, (int) $target->id);

        DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
        $monsterTrigger = $this->captureTrigger('monster_instances', self::MONSTER_TRIGGER);
        $statTrigger = $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
        DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_TRIGGER);
        DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);

        $this->rebindCurrentDefinitions((int) $world->id, (int) $source->id, (int) $target->id);
        if (DB::table('worlds')->where('id', $world->id)
            ->where('ruleset_version_id', $source->id)
            ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('shared-world changed during the exact v18 to v19 upgrade.');
        }
        $reconciled = $this->reconcileTrialOneAreaEntitlement();

        DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
        DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
        DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
        if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
            || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
            throw new RuntimeException('A gameplay integrity trigger changed during the v19 upgrade.');
        }

        $this->assertPostconditions((int) $world->id, (int) $target->id);
        $afterProtected = $this->protectedDigests();
        foreach ($protected as $name => $digest) {
            if ($digest !== $afterProtected[$name]) {
                throw new RuntimeException("The v19 upgrade changed protected data: {$name}.");
            }
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
            'event_type' => 'ruleset.v19_activated',
            'severity' => 'info',
            'subject_type' => $world->getMorphClass(),
            'subject_id' => $world->id,
            'metadata' => json_encode([
                'source_key' => self::SOURCE_KEY,
                'source_checksum' => self::SOURCE_CHECKSUM,
                'target_key' => self::TARGET_KEY,
                'target_checksum' => self::TARGET_CHECKSUM,
                'request_identity_preserved' => true,
                'historical_records_preserved' => true,
                'queued_definitions_rebound_by_stable_key' => true,
                'new_command_key' => 'territory_abandon',
                'trial_one_area_entitlements_reconciled' => $reconciled,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 'production_v18_to_v19';
    }

    private function reconcileTrialOneAreaEntitlement(): int
    {
        return DB::update(<<<'SQL'
UPDATE underground_profiles profile
   SET unlocked_area_layers = 1
  FROM underground_trial_progress progress
 WHERE progress.underground_profile_id = profile.id
   AND progress.trial_key = 'trial_01'
   AND progress.first_cleared_at IS NOT NULL
   AND profile.unlocked_area_layers < 1
SQL);
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
  JOIN command_definitions target ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE item.nation_command_queue_id = queue.id
   AND nation.world_id = ? AND item.command_definition_id = source.id AND item.status = 'queued'
SQL, [$sourceId, $targetId, $worldId]);
        DB::update(<<<'SQL'
UPDATE monster_instances instance
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE instance.world_id = ? AND instance.monster_definition_id = source.id
   AND instance.state = 'alive' AND source.ruleset_version_id = ?
SQL, [$targetId, $worldId, $sourceId]);
        DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE stat.world_id = ? AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$targetId, $worldId, $sourceId]);
    }

    private function assertDefinitionKeys(int $sourceId, int $targetId): void
    {
        foreach (['production_definitions', 'monster_definitions'] as $table) {
            $source = DB::table($table)->where('ruleset_version_id', $sourceId)->orderBy('key')->pluck('key')->all();
            $target = DB::table($table)->where('ruleset_version_id', $targetId)->orderBy('key')->pluck('key')->all();
            if ($source === [] || $source !== $target) {
                throw new RuntimeException("{$table} stable keys differ across exact v18 to v19.");
            }
        }

        $sourceCommands = DB::table('command_definitions')->where('ruleset_version_id', $sourceId)
            ->orderBy('key')->pluck('key')->all();
        $targetCommands = DB::table('command_definitions')->where('ruleset_version_id', $targetId)
            ->orderBy('key')->pluck('key')->all();
        $expectedCommands = [...$sourceCommands, 'territory_abandon'];
        sort($expectedCommands, SORT_STRING);
        if ($sourceCommands === [] || $targetCommands !== $expectedCommands) {
            throw new RuntimeException('command_definitions differ beyond the one authored v19 territory-abandonment command.');
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
  WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stats,
 (SELECT count(*) FROM underground_profiles profile
   JOIN underground_trial_progress progress ON progress.underground_profile_id = profile.id
  WHERE progress.trial_key = 'trial_01' AND progress.first_cleared_at IS NOT NULL
    AND profile.unlocked_area_layers < 1) AS trial_one_entitlements
SQL, [$worldId, $targetId, $worldId, $targetId, $worldId, $targetId]);
        if ((int) DB::table('worlds')->where('id', $worldId)->value('ruleset_version_id') !== $targetId
            || (int) $mismatches->queued !== 0
            || (int) $mismatches->monsters !== 0
            || (int) $mismatches->stats !== 0
            || (int) $mismatches->trial_one_entitlements !== 0) {
            throw new RuntimeException('Exact v19 activation postconditions failed.');
        }
    }

    /** @return array<string, string> */
    private function protectedDigests(): array
    {
        return [
            'request_provenance' => $this->queryDigest(DB::table('nation_command_queue_items')->select([
                'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
            ])),
            'terminal_commands' => $this->queryDigest(DB::table('nation_command_queue_items')->where('status', '<>', 'queued')),
            'turn_runs' => $this->queryDigest(DB::table('turn_runs')),
            'historical_monsters' => $this->queryDigest(DB::table('monster_instances')->where('state', '<>', 'alive')),
            'historical_events' => $this->queryDigest(DB::table('audit_events')),
            'secretaries' => $this->queryDigest(DB::table('secretaries')),
            'secretary_skills' => $this->queryDigest(DB::table('secretary_skills')),
            'secretary_items' => $this->queryDigest(DB::table('secretary_item_instances')),
            'underground_profiles_except_layer' => $this->queryDigest(DB::table('underground_profiles')->select([
                'id', 'secretary_id', 'combat_level', 'combat_xp', 'shard_balance', 'banked_shard_balance',
                'current_hp', 'next_battle_at', 'underground_contract_completed_at', 'growth_path_key',
                'growth_path_identity', 'growth_path_selected_at', 'unspent_stp', 'allocated_vitality_stp',
                'allocated_might_stp', 'allocated_finesse_stp', 'allocated_spirit_stp', 'allocated_agility_stp',
                'skill_points_total', 'skill_points_unspent', 'skill_tree_identity', 'awakening_gauge',
                'awakening_message', 'created_at', 'updated_at',
            ])),
            'underground_trial_progress' => $this->queryDigest(DB::table('underground_trial_progress')),
            'underground_trial_runs' => $this->queryDigest(DB::table('underground_trial_runs')),
            'underground_battles' => $this->queryDigest(DB::table('underground_battles')),
            'underground_battle_logs' => $this->queryDigest(DB::table('underground_battle_logs')),
            'underground_intro_progress' => $this->queryDigest(DB::table('underground_intro_progress')),
            'underground_intro_requests' => $this->queryDigest(DB::table('underground_intro_requests')),
            'underground_skill_allocations' => $this->queryDigest(DB::table('underground_skill_allocations')),
            'underground_owned_equipment' => $this->queryDigest(DB::table('underground_owned_equipment')),
        ];
    }

    /** @return array{definition: string, function: string} */
    private function captureTrigger(string $table, string $trigger): array
    {
        $row = DB::selectOne(<<<'SQL'
SELECT t.tgenabled, pg_get_triggerdef(t.oid, true) AS definition, pg_get_functiondef(t.tgfoid) AS function
  FROM pg_trigger t
 WHERE t.tgrelid = ?::regclass AND t.tgname = ? AND NOT t.tgisinternal
SQL, [$table, $trigger]);
        if ($row === null || $row->tgenabled !== 'O') {
            throw new RuntimeException("{$trigger} must be enabled for the v19 upgrade.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    private function queryDigest(Builder $query): string
    {
        $hash = hash_init('sha256');
        foreach ($query->orderBy('id')->lazyById(250) as $row) {
            hash_update($hash, json_encode((array) $row, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        return hash_final($hash);
    }

    /** @param array<string, mixed> $settings */
    private function checksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
