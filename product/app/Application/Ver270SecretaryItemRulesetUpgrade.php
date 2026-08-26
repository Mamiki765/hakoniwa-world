<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Secretary\SecretarySkillProgression;
use App\Domain\World\WorldMutationLock;
use App\Models\RulesetVersion;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver270SecretaryItemRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v16';

    public const SOURCE_VERSION = 16;

    public const SOURCE_CHECKSUM = '331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d';

    public const TARGET_KEY = 'hakoniwa-2s-plus-v17';

    public const TARGET_VERSION = 17;

    public const TARGET_CHECKSUM = '10ef012e8c267aae6d1f6c4e5b888674e11f9c94058bdfdc118a6a7730dfc780';

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
        private SecretarySkillProgression $skillProgression,
    ) {}

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php');
        $targetSettings = config('hakoniwa.ruleset');
        if (! is_array($targetSettings)
            || ($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException('The exact immutable v16 and authored v17 Rulesets required by the ver 2.7.0 upgrade are missing or changed.');
        }

        $world = World::query()->orderBy('id')->first();
        if (! $world instanceof World) {
            return DB::transaction(function () use ($targetSettings): string {
                $this->lockBusinessTables();
                if (World::query()->exists()) {
                    throw new RuntimeException('A World appeared while publishing fresh-install v17.');
                }
                $this->catalogInstaller->install($targetSettings);
                $this->publisher->publish($targetSettings);

                return 'fresh_install_current_v17';
            }, 1);
        }

        $this->worldMutationLock->acquire($world);
        try {
            return DB::transaction(fn (): string => $this->upgradeLockedWorld(
                $world,
                $sourceSettings,
                $targetSettings,
            ), 1);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    /** @param array<string, mixed> $sourceSettings
     * @param  array<string, mixed>  $targetSettings
     */
    private function upgradeLockedWorld(World $advisoryWorld, array $sourceSettings, array $targetSettings): string
    {
        $this->lockBusinessTables();
        $worlds = World::query()->orderBy('id')->lockForUpdate()
            ->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($worlds->count() !== 1 || (int) $worlds->first()->id !== (int) $advisoryWorld->id
            || $worlds->first()->key !== self::WORLD_KEY) {
            throw new RuntimeException('The v17 upgrade supports exactly one locked shared-world.');
        }
        /** @var World $world */
        $world = $worlds->first();
        $this->turnRunGuard->assertClear($world);
        $source = $this->publisher->assertPublished($sourceSettings);
        $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
        if ($existingTarget instanceof RulesetVersion
            && (int) $world->ruleset_version_id === (int) $existingTarget->id) {
            $target = $this->publisher->assertPublished($targetSettings);
            $this->assertPostconditions((int) $world->id, (int) $target->id);

            return 'already_current_v17';
        }
        if ((int) $world->ruleset_version_id !== (int) $source->id) {
            throw new RuntimeException('The v17 upgrade requires the mutable shared-world to be exact v16.');
        }
        if (DB::table('auction_listings')
            ->where('world_id', $world->id)
            ->where('status', 'active')
            ->where('product_type', 'item')
            ->where('item_key', 'old_bow')
            ->exists()) {
            throw new RuntimeException('Active Old Bow auctions must be cancelled under v16 before the v17 upgrade.');
        }

        $protected = $this->protectedDigests();
        $target = $this->publisher->publish($targetSettings);
        $this->assertStableDefinitionKeys((int) $source->id, (int) $target->id);
        $demographicBackfill = $this->backfillDemographicSkills((int) $world->id, $targetSettings);

        DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
        $monsterTrigger = $this->captureTrigger('monster_instances', self::MONSTER_TRIGGER);
        $statTrigger = $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
        DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_TRIGGER);
        DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);

        $this->rebindCurrentDefinitions((int) $world->id, (int) $source->id, (int) $target->id);
        if (DB::table('worlds')->where('id', $world->id)
            ->where('ruleset_version_id', $source->id)
            ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]) !== 1) {
            throw new RuntimeException('shared-world changed during the exact v16 to v17 upgrade.');
        }

        DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
        DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
        DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
        if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
            || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
            throw new RuntimeException('A gameplay integrity trigger changed during the v17 upgrade.');
        }

        $this->assertPostconditions((int) $world->id, (int) $target->id);
        $changed = [];
        foreach ($protected as $name => $digest) {
            if ($digest !== $this->protectedDigests()[$name]) {
                $changed[] = $name;
            }
        }
        if ($changed !== []) {
            throw new RuntimeException('The v17 upgrade changed protected data: '.implode(', ', $changed).'.');
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
            'event_type' => 'ruleset.v17_activated',
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
                'secretary_item_equipment_auction_data_preserved' => true,
                'item_backfill_performed' => false,
                'demographic_skill_rows_added' => $demographicBackfill['skill_rows'],
                'population_high_water_rows_seeded' => $demographicBackfill['nation_rows'],
                'historical_population_source' => 'authoritative_turn_summary_and_current_population_only',
                'speculative_population_history_reconstruction' => false,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return 'production_v16_to_v17';
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

    private function assertStableDefinitionKeys(int $sourceId, int $targetId): void
    {
        foreach (['command_definitions', 'production_definitions', 'monster_definitions'] as $table) {
            $source = DB::table($table)->where('ruleset_version_id', $sourceId)->orderBy('key')->pluck('key')->all();
            $target = DB::table($table)->where('ruleset_version_id', $targetId)->orderBy('key')->pluck('key')->all();
            if ($source === [] || $source !== $target) {
                throw new RuntimeException("{$table} stable keys differ across exact v16 to v17.");
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
            throw new RuntimeException('Exact v17 activation postconditions failed.');
        }
        foreach ([SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY, SecretarySkillCatalog::INDOMITABLE] as $skillKey) {
            if (DB::table('secretaries as secretary')
                ->leftJoin('secretary_skills as skill', static function ($join) use ($skillKey): void {
                    $join->on('skill.secretary_id', '=', 'secretary.id')->where('skill.skill_key', '=', $skillKey);
                })
                ->whereNull('skill.id')->exists()) {
                throw new RuntimeException("Exact v17 activation left Secretary skill {$skillKey} missing.");
            }
        }
    }

    /** @param array<string, mixed> $targetSettings
     * @return array{skill_rows: int, nation_rows: int}
     */
    private function backfillDemographicSkills(int $worldId, array $targetSettings): array
    {
        $skillKeys = [SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY, SecretarySkillCatalog::INDOMITABLE];
        if (DB::table('secretary_skills')->whereIn('skill_key', $skillKeys)->exists()) {
            throw new RuntimeException('Exact v16 source already contains v17 demographic Secretary skill rows.');
        }
        $currentPopulation = DB::table('map_cells')->whereIn(
            'owner_nation_id',
            DB::table('nations')->where('world_id', $worldId)->select('id'),
        )->selectRaw('owner_nation_id, SUM(population) AS aggregate')
            ->groupBy('owner_nation_id')->pluck('aggregate', 'owner_nation_id');
        $history = [];
        foreach (DB::table('audit_events')->where('world_id', $worldId)
            ->where('event_type', 'turn.summary')->whereNotNull('nation_id')
            ->orderBy('id')->get(['nation_id', 'metadata']) as $event) {
            $metadata = is_array($event->metadata)
                ? $event->metadata
                : json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
            $population = $metadata['summary']['population'] ?? null;
            $start = is_array($population) && is_numeric($population['start'] ?? null)
                ? (int) $population['start'] : null;
            $end = is_array($population) && is_numeric($population['end'] ?? null)
                ? (int) $population['end'] : null;
            if ($start === null || $start < 0 || $end === null || $end < 0) {
                continue;
            }
            $nationId = (int) $event->nation_id;
            $history[$nationId] ??= ['peak' => 0, 'loss' => 0];
            $history[$nationId]['peak'] = max($history[$nationId]['peak'], $start, $end);
            $loss = max(0, $start - $end);
            if ($history[$nationId]['loss'] > PHP_INT_MAX - $loss) {
                throw new RuntimeException('Historical authoritative population loss exceeds the supported integer range.');
            }
            $history[$nationId]['loss'] += $loss;
        }

        $nationStates = [];
        $nations = DB::table('nations')->where('world_id', $worldId)->orderBy('id')->lockForUpdate()->get(['id']);
        foreach ($nations as $nation) {
            $nationId = (int) $nation->id;
            $current = (int) ($currentPopulation[$nationId] ?? 0);
            $peak = max($current, $history[$nationId]['peak'] ?? 0);
            $loss = $history[$nationId]['loss'] ?? 0;
            if (DB::table('nations')->where('id', $nationId)->update([
                'population_high_water' => $peak,
                'updated_at' => now(),
            ]) !== 1) {
                throw new RuntimeException("Population high-water seed failed for Nation {$nationId}.");
            }
            $nationStates[$nationId] = ['peak' => $peak, 'loss' => $loss];
        }
        $nationByUser = DB::table('nation_memberships')->where('world_id', $worldId)->where('role', 'owner')
            ->orderBy('id')->pluck('nation_id', 'user_id');
        $birthrateDefinition = $targetSettings['secretary']['skills'][SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY];
        $indomitableDefinition = $targetSettings['secretary']['skills'][SecretarySkillCatalog::INDOMITABLE];
        $now = now();
        $rows = [];
        foreach (DB::table('secretaries')->orderBy('id')->lockForUpdate()->get(['id', 'user_id']) as $secretary) {
            $nationId = isset($nationByUser[$secretary->user_id]) ? (int) $nationByUser[$secretary->user_id] : null;
            $state = $nationId === null ? ['peak' => 0, 'loss' => 0] : ($nationStates[$nationId] ?? ['peak' => 0, 'loss' => 0]);
            $birthrate = $this->skillProgression->advance($birthrateDefinition, 0, 0, $state['peak']);
            $indomitable = $this->skillProgression->advance($indomitableDefinition, 0, 0, $state['loss']);
            foreach ([
                SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY => $birthrate,
                SecretarySkillCatalog::INDOMITABLE => $indomitable,
            ] as $skillKey => $progress) {
                $rows[] = [
                    'secretary_id' => (int) $secretary->id,
                    'skill_key' => $skillKey,
                    'level' => $progress['level'],
                    'experience' => $progress['experience'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        if ($rows !== []) {
            DB::table('secretary_skills')->insert($rows);
        }

        return ['skill_rows' => count($rows), 'nation_rows' => count($nationStates)];
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
            'existing_secretary_skills' => $this->queryDigest(DB::table('secretary_skills')
                ->whereNotIn('skill_key', [
                    SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
                    SecretarySkillCatalog::INDOMITABLE,
                ])),
            'secretary_items' => $this->queryDigest(DB::table('secretary_item_instances')),
            'auction_listings' => $this->queryDigest(DB::table('auction_listings')),
            'auction_bids' => $this->queryDigest(DB::table('auction_bids')),
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
            throw new RuntimeException("{$trigger} must be enabled for the v17 upgrade.");
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
