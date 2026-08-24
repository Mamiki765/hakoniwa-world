<?php

namespace App\Application;

use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\MonsterInstance;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final readonly class Ver250MonsterExperienceRulesetUpgrade
{
    public const SOURCE_KEY = 'hakoniwa-2s-plus-v14';

    public const SOURCE_VERSION = 14;

    public const SOURCE_CHECKSUM = Ver250SecretaryProfileRulesetUpgrade::TARGET_CHECKSUM;

    public const TARGET_KEY = 'hakoniwa-2s-plus-v15';

    public const TARGET_VERSION = 15;

    public const TARGET_CHECKSUM = 'd361856e81bb6fe8752a5f1c448d8cbbdb87b6471d5142b36a06b756923fda70';

    public const SOURCE_MIGRATION = '2026_08_24_000000_add_secretary_profiles_and_publish_v14';

    private const WORLD_KEY = 'shared-world';

    private const QUEUE_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const MONSTER_TRIGGER = 'monster_instance_world_ruleset_guard';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    private const BACKFILL_TABLE = 'ver250_old_bow_monster_experience_backfill';

    private const OLD_BOW_DAMAGE_TYPE = 'secretary_old_bow';

    private const DIGEST_PAGE_SIZE = 250;

    /** @var list<string> */
    private const INFRASTRUCTURE_TABLES = ['cache', 'cache_locks', 'migrations', 'sessions'];

    public function __construct(
        private RulesetPublisher $publisher,
        private CurrentCatalogInstaller $catalogs,
    ) {}

    public static function installSchema(): void
    {
        if (! Schema::hasColumn('secretaries', 'monster_experience')) {
            Schema::table('secretaries', static function (Blueprint $table): void {
                $table->bigInteger('monster_experience')->default(0);
            });
        }
        if (! DB::table('pg_constraint')
            ->where('conname', 'secretaries_monster_experience_non_negative')->exists()) {
            DB::statement(<<<'SQL'
ALTER TABLE secretaries
  ADD CONSTRAINT secretaries_monster_experience_non_negative
    CHECK (monster_experience >= 0)
SQL);
        }

        if (! Schema::hasColumn('monster_definitions', 'experience_per_damage')) {
            Schema::table('monster_definitions', static function (Blueprint $table): void {
                $table->smallInteger('experience_per_damage')->nullable();
            });
        }
        if (! DB::table('pg_constraint')
            ->where('conname', 'monster_definitions_experience_per_damage_non_negative')->exists()) {
            DB::statement(<<<'SQL'
ALTER TABLE monster_definitions
  ADD CONSTRAINT monster_definitions_experience_per_damage_non_negative
    CHECK (experience_per_damage IS NULL OR experience_per_damage >= 0)
SQL);
        }

        $skillConstraint = DB::selectOne(<<<'SQL'
SELECT pg_get_constraintdef(oid, true) AS definition
  FROM pg_constraint
 WHERE conname = 'secretary_skills_key_check'
   AND conrelid = 'secretary_skills'::regclass
SQL);
        if ($skillConstraint === null
            || ! str_contains((string) $skillConstraint->definition, SecretarySkillCatalog::FOREST_MANAGEMENT)) {
            if ($skillConstraint !== null) {
                DB::statement('ALTER TABLE secretary_skills DROP CONSTRAINT secretary_skills_key_check');
            }
            DB::statement(<<<'SQL'
ALTER TABLE secretary_skills
  ADD CONSTRAINT secretary_skills_key_check
    CHECK (skill_key IN (
      'agricultural_policy',
      'specialty_development',
      'gold_vein_survey',
      'forest_management',
      'final_defense_line'
    ))
SQL);
        }
    }

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v14.php');
        $targetSettings = config('hakoniwa.ruleset');
        if (! is_array($targetSettings)
            || ($sourceSettings['key'] ?? null) !== self::SOURCE_KEY
            || ($sourceSettings['version'] ?? null) !== self::SOURCE_VERSION
            || $this->checksum($sourceSettings) !== self::SOURCE_CHECKSUM
            || ($targetSettings['key'] ?? null) !== self::TARGET_KEY
            || ($targetSettings['version'] ?? null) !== self::TARGET_VERSION
            || $this->checksum($targetSettings) !== self::TARGET_CHECKSUM) {
            throw new RuntimeException(
                'The exact v14/v15 Ruleset authoring required by the monster experience upgrade is missing or changed.',
            );
        }

        return DB::transaction(function () use ($sourceSettings, $targetSettings): string {
            $this->lockBusinessTables();
            $worlds = World::query()->orderBy('id')->lockForUpdate()
                ->get(['id', 'key', 'current_turn', 'ruleset_version_id']);
            if ($worlds->isEmpty()) {
                $this->catalogs->assertInstalled($targetSettings);
                $this->publisher->publish($targetSettings);

                return 'fresh_install_current_v15';
            }
            if ($worlds->count() !== 1 || $worlds->first()->key !== self::WORLD_KEY) {
                throw new RuntimeException('Monster experience upgrade supports exactly one shared-world.');
            }

            $world = $worlds->first();
            $source = $this->publisher->assertPublished($sourceSettings);
            $existingTarget = RulesetVersion::query()->where('key', self::TARGET_KEY)->lockForUpdate()->first();
            if ($existingTarget instanceof RulesetVersion
                && (int) $world->ruleset_version_id === (int) $existingTarget->id) {
                $target = $this->publisher->assertPublished($targetSettings);
                $this->assertPostconditions((int) $world->id, (int) $source->id, (int) $target->id);

                return 'already_current_v15';
            }
            if (! DB::table('migrations')->where('migration', self::SOURCE_MIGRATION)->exists()
                || (int) $world->ruleset_version_id !== (int) $source->id) {
                throw new RuntimeException(
                    'Monster experience upgrade requires the exact supported ver 2.5.0-beta/v14 source.',
                );
            }
            $unresolved = TurnRun::query()->unresolvedProduction()->orderBy('id')->first(['id', 'status']);
            if ($unresolved instanceof TurnRun) {
                throw new RuntimeException(
                    "Monster experience upgrade blocked: unresolved non-dry TurnRun {$unresolved->id} has status {$unresolved->status}.",
                );
            }
            if (DB::table('secretaries')->where('monster_experience', '<>', 0)->exists()) {
                throw new RuntimeException(
                    'Monster experience upgrade requires every source-v14 Secretary to have zero monster experience.',
                );
            }
            $this->assertSourceSecretarySkillCatalog();

            $backfill = $this->prepareHistoricalBackfill((int) $world->id);
            $requestIdentity = $this->queryDigest(DB::table('nation_command_queue_items')->select([
                'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
            ]));
            $terminalHistory = $this->queryDigest(DB::table('nation_command_queue_items')
                ->where('status', '<>', 'queued'));
            $historicalMonsterState = $this->queryDigest(DB::table('monster_instances')
                ->where('state', '<>', 'alive'));
            $sourceMonsterDefinitions = $this->queryDigest(DB::table('monster_definitions')
                ->where('ruleset_version_id', $source->id));
            $secretaryState = $this->protectedSecretaryStateDigest();
            $historicalFacilityExperience = $this->queryDigest(DB::table('map_cells')->select([
                'id', 'facility_experience',
            ]));
            $auditHistory = $this->tableDigest('audit_events');

            $target = $this->publisher->publish($targetSettings);
            $this->catalogs->assertInstalled($targetSettings);
            $this->assertStableDefinitionKeys((int) $source->id, (int) $target->id);
            $forestManagementRowsAdded = $this->addForestManagementSkillRows();

            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' DEFERRED');
            $monsterTrigger = $this->captureTrigger('monster_instances', self::MONSTER_TRIGGER);
            $statTrigger = $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER);
            DB::statement('ALTER TABLE monster_instances DISABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);

            $this->rebindCurrentDefinitions((int) $world->id, (int) $source->id, (int) $target->id);
            if (DB::table('worlds')->where('id', $world->id)
                ->where('ruleset_version_id', $source->id)
                ->update(['ruleset_version_id' => $target->id, 'updated_at' => now()]) !== 1) {
                throw new RuntimeException('shared-world changed during the monster experience upgrade.');
            }

            DB::statement('ALTER TABLE monster_instances ENABLE TRIGGER '.self::MONSTER_TRIGGER);
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            DB::statement('SET CONSTRAINTS '.self::QUEUE_CONSTRAINT.' IMMEDIATE');
            if ($this->captureTrigger('monster_instances', self::MONSTER_TRIGGER) !== $monsterTrigger
                || $this->captureTrigger('nation_monster_kill_stats', self::KILL_STAT_TRIGGER) !== $statTrigger) {
                throw new RuntimeException('A gameplay integrity trigger changed during the monster experience upgrade.');
            }

            $appliedBackfill = $this->applyHistoricalBackfill();
            if ($appliedBackfill !== $backfill) {
                throw new RuntimeException('The prepared Old Bow backfill changed before it was applied.');
            }
            $this->assertPostconditions((int) $world->id, (int) $source->id, (int) $target->id);

            $changedProtectedData = array_keys(array_filter([
                'request_identity' => $requestIdentity !== $this->queryDigest(
                    DB::table('nation_command_queue_items')->select([
                        'id', 'request_key', 'request_ruleset_version_id', 'request_fingerprint', 'status',
                    ]),
                ),
                'terminal_command_history' => $terminalHistory !== $this->queryDigest(
                    DB::table('nation_command_queue_items')->where('status', '<>', 'queued'),
                ),
                'historical_monster_state' => $historicalMonsterState !== $this->queryDigest(
                    DB::table('monster_instances')->where('state', '<>', 'alive'),
                ),
                'source_monster_definitions' => $sourceMonsterDefinitions !== $this->queryDigest(
                    DB::table('monster_definitions')->where('ruleset_version_id', $source->id),
                ),
                'secretary_profile_and_equipment' => $secretaryState !== $this->protectedSecretaryStateDigest(),
                'historical_facility_experience' => $historicalFacilityExperience !== $this->queryDigest(
                    DB::table('map_cells')->select(['id', 'facility_experience']),
                ),
                'audit_history' => $auditHistory !== $this->tableDigest('audit_events'),
            ]));
            if ($changedProtectedData !== []) {
                throw new RuntimeException(
                    'Monster experience upgrade changed protected data: '.implode(', ', $changedProtectedData).'.',
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
                'event_type' => 'ruleset.v15_activated',
                'severity' => 'info',
                'subject_type' => $world->getMorphClass(),
                'subject_id' => $world->id,
                'metadata' => json_encode([
                    'source_key' => self::SOURCE_KEY,
                    'target_key' => self::TARGET_KEY,
                    'target_checksum' => self::TARGET_CHECKSUM,
                    'old_bow_kill_count' => $backfill['kill_count'],
                    'old_bow_secretary_count' => $backfill['secretary_count'],
                    'old_bow_monster_experience_total' => $backfill['experience_total'],
                    'request_identity_preserved' => true,
                    'terminal_command_history_preserved' => true,
                    'historical_monster_state_preserved' => true,
                    'source_monster_definitions_preserved' => true,
                    'historical_facility_experience_preserved' => true,
                    'forest_management_skill_rows_added' => $forestManagementRowsAdded,
                    'existing_secretary_skills_preserved' => true,
                    'historical_forest_management_backfill' => false,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 'production_v14_to_v15';
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

    /** @return array{kill_count: int, secretary_count: int, experience_total: int} */
    private function prepareHistoricalBackfill(int $worldId): array
    {
        DB::statement(<<<'SQL'
CREATE TEMPORARY TABLE ver250_old_bow_monster_experience_backfill (
    event_id bigint PRIMARY KEY,
    monster_instance_id bigint NOT NULL UNIQUE,
    secretary_id bigint NOT NULL,
    experience bigint NOT NULL CHECK (experience >= 0)
) ON COMMIT DROP
SQL);

        $duplicate = DB::table('audit_events')
            ->where('world_id', $worldId)
            ->where('event_type', 'monster.killed')
            ->whereRaw("metadata->>'damage_type' = ?", [self::OLD_BOW_DAMAGE_TYPE])
            ->whereNotNull('subject_id')
            ->groupBy('subject_id')
            ->havingRaw('count(*) <> 1')
            ->orderBy('subject_id')
            ->first(['subject_id']);
        if ($duplicate !== null) {
            throw new RuntimeException(
                "Old Bow history is ambiguous: monster instance {$duplicate->subject_id} has duplicate kill events.",
            );
        }

        $historicalOwners = DB::table('audit_events as created')
            ->where('created.event_type', 'nation.created')
            ->where('created.subject_type', Nation::class)
            ->groupBy('created.world_id', 'created.subject_id')
            ->selectRaw(
                'created.world_id, created.subject_id AS nation_id, count(*) AS creation_count, '
                .'min(created.actor_user_id) AS minimum_user_id, max(created.actor_user_id) AS maximum_user_id',
            );
        $currentOwners = DB::table('nation_memberships')
            ->where('role', 'owner')
            ->groupBy('world_id', 'nation_id')
            ->selectRaw(
                'world_id, nation_id, count(*) AS membership_count, '
                .'min(user_id) AS minimum_user_id, max(user_id) AS maximum_user_id',
            );

        $query = DB::table('audit_events as event')
            ->leftJoin('monster_instances as instance', 'instance.id', '=', 'event.subject_id')
            ->leftJoin('monster_definitions as definition', 'definition.id', '=', 'instance.monster_definition_id')
            ->leftJoin('nations as nation', 'nation.id', '=', 'event.nation_id')
            ->leftJoinSub($historicalOwners, 'historical_owner', static function ($join): void {
                $join->on('historical_owner.world_id', '=', 'event.world_id')
                    ->on('historical_owner.nation_id', '=', 'event.nation_id');
            })
            ->leftJoin('users as historical_user', 'historical_user.id', '=', 'historical_owner.minimum_user_id')
            ->leftJoin('secretaries as secretary', 'secretary.user_id', '=', 'historical_user.id')
            ->leftJoinSub($currentOwners, 'current_owner', static function ($join): void {
                $join->on('current_owner.world_id', '=', 'event.world_id')
                    ->on('current_owner.nation_id', '=', 'event.nation_id');
            })
            ->where('event.world_id', $worldId)
            ->where('event.event_type', 'monster.killed')
            ->whereRaw("event.metadata->>'damage_type' = ?", [self::OLD_BOW_DAMAGE_TYPE])
            ->select([
                'event.id as event_id',
                'event.subject_type as event_subject_type',
                'event.subject_id as event_subject_id',
                'event.world_id as event_world_id',
                'event.nation_id as event_nation_id',
                'instance.id as instance_id',
                'instance.world_id as instance_world_id',
                'instance.state as instance_state',
                'instance.removal_reason as instance_removal_reason',
                'definition.key as definition_key',
                'definition.missile_base_experience',
                'nation.world_id as nation_world_id',
                'historical_owner.creation_count',
                'historical_owner.minimum_user_id as historical_minimum_user_id',
                'historical_owner.maximum_user_id as historical_maximum_user_id',
                'secretary.id as secretary_id',
                'current_owner.membership_count',
                'current_owner.minimum_user_id as current_minimum_user_id',
                'current_owner.maximum_user_id as current_maximum_user_id',
            ])
            ->selectRaw("event.metadata->>'monster_instance_id' AS metadata_instance_id")
            ->selectRaw("event.metadata->>'monster_definition_key' AS metadata_definition_key")
            ->selectRaw("event.metadata->>'killer_nation_id' AS metadata_killer_nation_id");

        $buffer = [];
        foreach ($query->lazyById(self::DIGEST_PAGE_SIZE, 'event.id', 'event_id') as $row) {
            $eventId = $this->positiveInteger($row->event_id, 'Old Bow kill event id');
            $instanceId = $this->positiveInteger($row->instance_id, "Old Bow event {$eventId} monster instance");
            $nationId = $this->positiveInteger($row->event_nation_id, "Old Bow event {$eventId} killer nation");
            $ownerUserId = $this->positiveInteger(
                $row->historical_minimum_user_id,
                "Old Bow event {$eventId} historical owner",
            );
            $secretaryId = $this->positiveInteger($row->secretary_id, "Old Bow event {$eventId} Secretary");
            $experience = $this->nonNegativeInteger(
                $row->missile_base_experience,
                "Old Bow event {$eventId} historical experience",
            );
            $currentMemberships = $this->nonNegativeInteger(
                $row->membership_count ?? 0,
                "Old Bow event {$eventId} current membership count",
            );

            if ($row->event_subject_type !== MonsterInstance::class
                || $this->positiveInteger($row->event_subject_id, "Old Bow event {$eventId} subject") !== $instanceId
                || $this->positiveInteger($row->metadata_instance_id, "Old Bow event {$eventId} metadata instance") !== $instanceId
                || $this->positiveInteger($row->event_world_id, "Old Bow event {$eventId} world") !== $worldId
                || $this->positiveInteger($row->instance_world_id, "Old Bow event {$eventId} instance world") !== $worldId
                || $this->positiveInteger($row->nation_world_id, "Old Bow event {$eventId} nation world") !== $worldId
                || $row->instance_state !== 'killed'
                || $row->instance_removal_reason !== self::OLD_BOW_DAMAGE_TYPE
                || $row->metadata_definition_key !== $row->definition_key
                || $this->positiveInteger(
                    $row->metadata_killer_nation_id,
                    "Old Bow event {$eventId} metadata killer nation",
                ) !== $nationId
                || $this->nonNegativeInteger(
                    $row->creation_count,
                    "Old Bow event {$eventId} nation creation count",
                ) !== 1
                || $this->positiveInteger(
                    $row->historical_maximum_user_id,
                    "Old Bow event {$eventId} historical owner maximum",
                ) !== $ownerUserId
                || $currentMemberships > 1
                || ($currentMemberships === 1
                    && ($this->positiveInteger(
                        $row->current_minimum_user_id,
                        "Old Bow event {$eventId} current owner",
                    ) !== $ownerUserId
                        || $this->positiveInteger(
                            $row->current_maximum_user_id,
                            "Old Bow event {$eventId} current owner maximum",
                        ) !== $ownerUserId))) {
                throw new RuntimeException(
                    "Old Bow event {$eventId} cannot be attributed to exactly one historical Secretary.",
                );
            }

            $buffer[] = [
                'event_id' => $eventId,
                'monster_instance_id' => $instanceId,
                'secretary_id' => $secretaryId,
                'experience' => $experience,
            ];
            if (count($buffer) === self::DIGEST_PAGE_SIZE) {
                DB::table(self::BACKFILL_TABLE)->insert($buffer);
                $buffer = [];
            }
        }
        if ($buffer !== []) {
            DB::table(self::BACKFILL_TABLE)->insert($buffer);
        }

        $summary = DB::selectOne(<<<'SQL'
SELECT count(*) AS kill_count,
       count(DISTINCT secretary_id) AS secretary_count,
       coalesce(sum(experience), 0) AS experience_total
  FROM ver250_old_bow_monster_experience_backfill
SQL);

        return [
            'kill_count' => $this->nonNegativeInteger($summary->kill_count, 'Old Bow backfill kill count'),
            'secretary_count' => $this->nonNegativeInteger(
                $summary->secretary_count,
                'Old Bow backfill Secretary count',
            ),
            'experience_total' => $this->nonNegativeInteger(
                $summary->experience_total,
                'Old Bow backfill experience total',
            ),
        ];
    }

    /** @return array{kill_count: int, secretary_count: int, experience_total: int} */
    private function applyHistoricalBackfill(): array
    {
        $summary = DB::selectOne(<<<'SQL'
SELECT count(*) AS kill_count,
       count(DISTINCT item.secretary_id) AS secretary_count,
       coalesce(sum(item.experience), 0) AS experience_total,
       coalesce(max(totals.secretary_total), 0) AS largest_secretary_total
  FROM ver250_old_bow_monster_experience_backfill item
  LEFT JOIN (
      SELECT secretary_id, sum(experience) AS secretary_total
        FROM ver250_old_bow_monster_experience_backfill
       GROUP BY secretary_id
  ) totals ON totals.secretary_id = item.secretary_id
SQL);
        $largest = $this->nonNegativeInteger($summary->largest_secretary_total, 'largest Secretary backfill');
        if ($largest > PHP_INT_MAX) {
            throw new RuntimeException('Old Bow Secretary experience backfill exceeds the supported integer range.');
        }

        DB::update(<<<'SQL'
UPDATE secretaries secretary
   SET monster_experience = totals.experience
  FROM (
      SELECT secretary_id, sum(experience)::bigint AS experience
        FROM ver250_old_bow_monster_experience_backfill
       GROUP BY secretary_id
  ) totals
 WHERE secretary.id = totals.secretary_id
SQL);

        $mismatch = DB::selectOne(<<<'SQL'
SELECT count(*) AS count
  FROM (
      SELECT secretary_id, sum(experience)::bigint AS expected
        FROM ver250_old_bow_monster_experience_backfill
       GROUP BY secretary_id
  ) totals
  JOIN secretaries secretary ON secretary.id = totals.secretary_id
 WHERE secretary.monster_experience <> totals.expected
SQL);
        if ((int) $mismatch->count !== 0) {
            throw new RuntimeException('Old Bow Secretary experience backfill postcondition failed.');
        }

        return [
            'kill_count' => $this->nonNegativeInteger($summary->kill_count, 'Old Bow backfill kill count'),
            'secretary_count' => $this->nonNegativeInteger(
                $summary->secretary_count,
                'Old Bow backfill Secretary count',
            ),
            'experience_total' => $this->nonNegativeInteger(
                $summary->experience_total,
                'Old Bow backfill experience total',
            ),
        ];
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
                throw new RuntimeException("{$table} keys are not stable across exact v14 to v15.");
            }
        }
    }

    private function assertPostconditions(int $worldId, int $sourceId, int $targetId): void
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
    (SELECT count(*) FROM monster_definitions
     WHERE ruleset_version_id = ? AND experience_per_damage IS NOT NULL) AS source_experience,
    (SELECT count(*) FROM monster_definitions
     WHERE ruleset_version_id = ? AND experience_per_damage IS NULL) AS target_experience
SQL, [
            $worldId, $targetId,
            $worldId, $targetId,
            $worldId, $targetId,
            $sourceId,
            $targetId,
        ]);
        if ((int) DB::table('worlds')->where('id', $worldId)->value('ruleset_version_id') !== $targetId
            || (int) $mismatches->queued !== 0
            || (int) $mismatches->monsters !== 0
            || (int) $mismatches->stats !== 0
            || (int) $mismatches->source_experience !== 0
            || (int) $mismatches->target_experience !== 0) {
            throw new RuntimeException('Exact v15 activation postconditions failed.');
        }
        $this->assertTargetSecretarySkillCatalog();
    }

    private function assertSourceSecretarySkillCatalog(): void
    {
        $secretaryCount = (int) DB::table('secretaries')->count();
        $legacyRows = (int) DB::table('secretary_skills')
            ->whereIn('skill_key', SecretarySkillCatalog::V14_KEYS)
            ->count();
        $allRows = (int) DB::table('secretary_skills')->count();
        if ($legacyRows !== $secretaryCount * count(SecretarySkillCatalog::V14_KEYS)
            || $allRows !== $legacyRows) {
            throw new RuntimeException('Monster experience upgrade requires the exact source-v14 Secretary skill catalog.');
        }
    }

    private function addForestManagementSkillRows(): int
    {
        $now = now();
        $rows = [];
        $added = 0;
        foreach (DB::table('secretaries')->orderBy('id')->lazyById(self::DIGEST_PAGE_SIZE) as $secretary) {
            $rows[] = [
                'secretary_id' => $secretary->id,
                'skill_key' => SecretarySkillCatalog::FOREST_MANAGEMENT,
                'level' => 0,
                'experience' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if (count($rows) === self::DIGEST_PAGE_SIZE) {
                DB::table('secretary_skills')->insert($rows);
                $added += count($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('secretary_skills')->insert($rows);
            $added += count($rows);
        }

        return $added;
    }

    private function assertTargetSecretarySkillCatalog(): void
    {
        $secretaryCount = (int) DB::table('secretaries')->count();
        $targetRows = (int) DB::table('secretary_skills')
            ->whereIn('skill_key', SecretarySkillCatalog::KEYS)
            ->count();
        if ($targetRows !== $secretaryCount * count(SecretarySkillCatalog::KEYS)
            || (int) DB::table('secretary_skills')->count() !== $targetRows) {
            throw new RuntimeException('Exact v15 Secretary skill catalog postcondition failed.');
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
            throw new RuntimeException("{$trigger} must be enabled for the monster experience upgrade.");
        }

        return ['definition' => $row->definition, 'function' => $row->function];
    }

    private function protectedSecretaryStateDigest(): string
    {
        return $this->queryDigest(DB::table('secretaries')->select([
            'id', 'user_id', 'name', 'named_at', 'created_at', 'updated_at', 'equipment_version',
            'profile_biography', 'main_image_path', 'main_image_mime_type',
            'main_image_creation_method', 'main_image_credit', 'main_image_updated_at',
        ])).':'.$this->queryDigest(DB::table('secretary_skills')
            ->whereIn('skill_key', SecretarySkillCatalog::V14_KEYS))
            .':'.$this->tableDigest('secretary_item_instances').':'.$this->tableDigest('users');
    }

    private function tableDigest(string $table): string
    {
        return $this->queryDigest(DB::table($table));
    }

    private function queryDigest(Builder $query): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, '[');
        $first = true;
        foreach ($query->lazyById(self::DIGEST_PAGE_SIZE, 'id', 'id') as $row) {
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

    private function positiveInteger(mixed $value, string $label): int
    {
        $integer = $this->nonNegativeInteger($value, $label);
        if ($integer < 1) {
            throw new RuntimeException("{$label} must be a positive integer.");
        }

        return $integer;
    }

    private function nonNegativeInteger(mixed $value, string $label): int
    {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            if (strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                throw new RuntimeException("{$label} exceeds the supported integer range.");
            }
            $integer = (int) $value;
        } else {
            throw new RuntimeException("{$label} must be a non-negative integer.");
        }
        if ($integer < 0) {
            throw new RuntimeException("{$label} must be a non-negative integer.");
        }

        return $integer;
    }

    /** @param array<string, mixed> $settings */
    private function checksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
