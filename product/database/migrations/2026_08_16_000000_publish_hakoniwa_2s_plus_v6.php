<?php

use App\Application\RulesetPublisher;
use App\Models\TurnRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const KILL_STAT_GUARD = 'nation_monster_kill_stat_guard';

    private const SOURCE_KEY = 'hakoniwa-2s-plus-v5';

    private const TARGET_KEY = 'hakoniwa-2s-plus-v6';

    private const WORLD_KEY = 'shared-world';

    private const REVIEWED_QUEUE_REBIND_OVERRIDE_ENV = 'HAKONIWA_V6_REBIND_REVIEWED_QUEUE_ITEMS';

    private const REVIEWED_QUEUE_REBIND_OVERRIDE_VALUE = 'CONFIRM_REVIEWED_V5_QUEUE_ITEMS_TO_V6';

    /** @var list<string> */
    private const BEHAVIOR_CHANGING_COMMAND_KEYS = [
        'logging',
        'build_defense_facility',
        'build_monument',
    ];

    public function up(): void
    {
        $sourceSettings = config('hakoniwa.published_rulesets.'.self::SOURCE_KEY);
        $targetSettings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($sourceSettings) || ! is_array($targetSettings)) {
            throw new RuntimeException('The immutable v5 or v6 production ruleset snapshot is missing.');
        }
        $this->assertApprovedTargetDiff($sourceSettings, $targetSettings);

        $publisher = app(RulesetPublisher::class);
        $source = $publisher->publish($sourceSettings);
        $target = $publisher->publish($targetSettings);
        DB::transaction(fn () => $this->moveLiveReferences($source->id, $target->id));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v6 production migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }

    /** @param array<string, mixed> $source
     * @param  array<string, mixed>  $target
     */
    private function assertApprovedTargetDiff(array $source, array $target): void
    {
        $expected = $source;
        $expected['key'] = self::TARGET_KEY;
        $expected['version'] = 6;
        foreach ($expected['command_definitions'] as &$command) {
            if ($command['key'] === 'logging') {
                $command['result_terrain_key'] = 'plain';
            }
            if ($command['key'] === 'build_defense_facility') {
                $command['metadata']['owner_overbuild_effect'] = 'defense_self_destruct';
            }
            if ($command['key'] === 'build_monument') {
                $command['metadata']['owner_overbuild_effect'] = 'monument_flight';
                $command['metadata']['parameters']['target_nation_id'] = [
                    'label' => '対象Nation ID',
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 2_147_483_647,
                    'required' => false,
                    'nullable' => true,
                ];
            }
        }
        unset($command);
        $expected['military']['defense_spp_resistance'] = [
            'facility_key' => 'defense',
            'ineffective_missile_keys' => ['spp_missile'],
        ];
        if ($target !== $expected) {
            throw new RuntimeException('hakoniwa-2s-plus-v6 contains changes outside the approved v6 contract.');
        }
    }

    private function moveLiveReferences(int $fromRulesetId, int $toRulesetId): void
    {
        $identity = DB::table('worlds')->where('key', self::WORLD_KEY)->first(['id', 'key']);
        if ($identity === null) {
            return;
        }

        $this->acquireWorldTurnMigrationLock($identity);
        $world = DB::table('worlds')->where('id', $identity->id)->lockForUpdate()
            ->first(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($world === null) {
            throw new RuntimeException('shared-world disappeared while acquiring the migration lock.');
        }
        if (! in_array((int) $world->ruleset_version_id, [$fromRulesetId, $toRulesetId], true)) {
            throw new RuntimeException('shared-world is attached to an unexpected ruleset; refusing an implicit production migration.');
        }

        DB::statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE monster_definitions IN SHARE MODE');
        DB::statement('LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE');
        $this->installLiveQueueRulesetConstraint();
        $this->assertDefinitionSetsMatch('command_definitions', $fromRulesetId, $toRulesetId);
        $this->assertDefinitionSetsMatch('monster_definitions', $fromRulesetId, $toRulesetId);
        $this->assertKillStatGuardEnabled();

        if ((int) $world->ruleset_version_id === $toRulesetId) {
            $this->assertLiveReferencesUseRuleset((int) $world->id, $toRulesetId, 'already migrated');

            return;
        }

        $this->assertNoUnresolvedNextTurnRun($world);
        $this->assertLiveReferencesUseRuleset((int) $world->id, $fromRulesetId, 'before migration');
        $this->assertNoBehaviorChangingQueuedItems((int) $world->id, $fromRulesetId);
        $this->assertNoKillStatCollisions((int) $world->id, $fromRulesetId, $toRulesetId);
        DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' DEFERRED');
        DB::table('worlds')->where('id', $world->id)->update([
            'ruleset_version_id' => $toRulesetId,
            'updated_at' => now(),
        ]);

        DB::update(<<<'SQL'
UPDATE nation_command_queue_items item
   SET command_definition_id = target.id
  FROM nation_command_queues queue
  JOIN nations nation ON nation.id = queue.nation_id
  JOIN command_definitions source ON source.ruleset_version_id = ?
  JOIN command_definitions target
    ON target.ruleset_version_id = ?
   AND target.key = source.key
 WHERE item.nation_command_queue_id = queue.id
   AND nation.world_id = ?
   AND item.command_definition_id = source.id
   AND item.status = 'queued'
SQL, [$fromRulesetId, $toRulesetId, $world->id]);

        DB::update(<<<'SQL'
UPDATE monster_instances instance
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ?
   AND target.key = source.key
 WHERE instance.world_id = ?
   AND instance.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$toRulesetId, $world->id, $fromRulesetId]);

        $statsToMove = DB::table('nation_monster_kill_stats as stat')
            ->join('monster_definitions as definition', 'definition.id', '=', 'stat.monster_definition_id')
            ->where('stat.world_id', $world->id)
            ->where('definition.ruleset_version_id', $fromRulesetId)
            ->count();
        if ($statsToMove > 0) {
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_GUARD);
            $moved = DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ?
   AND target.key = source.key
 WHERE stat.world_id = ?
   AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$toRulesetId, $world->id, $fromRulesetId]);
            if ($moved !== $statsToMove) {
                throw new RuntimeException("Expected to migrate {$statsToMove} monster kill stats, but migrated {$moved}.");
            }
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_GUARD);
        }

        $this->assertKillStatGuardEnabled();
        $this->assertLiveReferencesUseRuleset((int) $world->id, $toRulesetId, 'after migration');
        DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' IMMEDIATE');
    }

    private function assertNoBehaviorChangingQueuedItems(int $worldId, int $fromRulesetId): void
    {
        $items = DB::table('nation_command_queue_items as item')
            ->join('nation_command_queues as queue', 'queue.id', '=', 'item.nation_command_queue_id')
            ->join('nations as nation', 'nation.id', '=', 'queue.nation_id')
            ->join('command_definitions as definition', 'definition.id', '=', 'item.command_definition_id')
            ->where('nation.world_id', $worldId)
            ->where('definition.ruleset_version_id', $fromRulesetId)
            ->where('item.status', 'queued')
            ->whereIn('definition.key', self::BEHAVIOR_CHANGING_COMMAND_KEYS)
            ->orderBy('nation.nation_number')
            ->orderBy('queue.id')
            ->orderBy('item.queue_position')
            ->orderBy('item.id')
            ->get([
                'item.id as item_id',
                'nation.id as nation_id',
                'nation.nation_number',
                'nation.name as nation_name',
                'queue.id as queue_id',
                'item.queue_position',
                'definition.key as command_key',
                'item.target_x',
                'item.target_y',
            ]);
        if ($items->isEmpty() || $this->reviewedQueueRebindOverrideEnabled()) {
            return;
        }

        $details = $items->map(static fn (object $item): string => json_encode([
            'item_id' => (int) $item->item_id,
            'nation_id' => (int) $item->nation_id,
            'nation_number' => (int) $item->nation_number,
            'nation_name' => (string) $item->nation_name,
            'queue_id' => (int) $item->queue_id,
            'queue_position' => (int) $item->queue_position,
            'command_key' => (string) $item->command_key,
            'target_x' => $item->target_x === null ? null : (int) $item->target_x,
            'target_y' => $item->target_y === null ? null : (int) $item->target_y,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode(PHP_EOL);

        throw new RuntimeException(
            "Refusing v6 migration with queued v5 commands whose behavior changes in v6.\n"
            ."Affected queued items:\n{$details}\n"
            .'After manually reviewing the queue and target map cells, follow '
            .'product/docs/operations/ver-1.7.0-v6-ruleset-migration.md and use the dedicated v6 one-shot '
            .self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV.' override only for that migration invocation.',
        );
    }

    private function reviewedQueueRebindOverrideEnabled(): bool
    {
        return getenv(self::REVIEWED_QUEUE_REBIND_OVERRIDE_ENV) === self::REVIEWED_QUEUE_REBIND_OVERRIDE_VALUE;
    }

    private function installLiveQueueRulesetConstraint(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION enforce_queue_item_world_ruleset_match()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    world_ruleset_id bigint;
    definition_ruleset_id bigint;
BEGIN
    IF NEW.status <> 'queued' THEN
        RETURN NEW;
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM nation_command_queue_items
         WHERE id = NEW.id
    ) THEN
        RETURN NEW;
    END IF;

    SELECT worlds.ruleset_version_id, command_definitions.ruleset_version_id
      INTO world_ruleset_id, definition_ruleset_id
      FROM nation_command_queues
      INNER JOIN nations ON nations.id = nation_command_queues.nation_id
      INNER JOIN worlds ON worlds.id = nations.world_id
      INNER JOIN command_definitions ON command_definitions.id = NEW.command_definition_id
     WHERE nation_command_queues.id = NEW.nation_command_queue_id;

    IF NOT FOUND OR world_ruleset_id IS DISTINCT FROM definition_ruleset_id THEN
        RAISE EXCEPTION
            'queued item % command definition ruleset % does not match World ruleset %',
            NEW.id,
            definition_ruleset_id,
            world_ruleset_id
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS nation_command_queue_items_world_ruleset_match
    ON nation_command_queue_items;

CREATE CONSTRAINT TRIGGER nation_command_queue_items_world_ruleset_match
AFTER INSERT OR UPDATE OF nation_command_queue_id, command_definition_id, status
ON nation_command_queue_items
DEFERRABLE INITIALLY IMMEDIATE
FOR EACH ROW
EXECUTE FUNCTION enforce_queue_item_world_ruleset_match();
SQL);
    }

    private function acquireWorldTurnMigrationLock(object $world): void
    {
        $lock = DB::selectOne(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
            ["hakoniwa.turn.world.{$world->id}"],
        );
        if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
            throw new RuntimeException(
                "Refusing to migrate shared-world {$world->id} ({$world->key}) while a turn operation holds its advisory lock.",
            );
        }
    }

    private function assertNoUnresolvedNextTurnRun(object $world): void
    {
        DB::statement('LOCK TABLE turn_runs IN SHARE ROW EXCLUSIVE MODE');
        $run = DB::table('turn_runs')->where('world_id', $world->id)
            ->where('target_turn', (int) $world->current_turn + 1)
            ->where('is_dry_run', false)
            ->whereIn('status', TurnRun::UNRESOLVED_PRODUCTION_STATUSES)
            ->orderBy('id')->first(['id', 'target_turn', 'status']);
        if ($run !== null) {
            throw new RuntimeException(
                "Refusing v6 migration with unresolved non-dry TurnRun {$run->id}, "
                ."target_turn={$run->target_turn}, status={$run->status}.",
            );
        }
    }

    private function assertDefinitionSetsMatch(string $table, int $fromRulesetId, int $toRulesetId): void
    {
        $source = DB::table($table)->where('ruleset_version_id', $fromRulesetId)->orderBy('key')->pluck('key')->all();
        $target = DB::table($table)->where('ruleset_version_id', $toRulesetId)->orderBy('key')->pluck('key')->all();
        if ($source !== $target || count($source) !== count(array_unique($source))
            || count($target) !== count(array_unique($target))) {
            throw new RuntimeException("v5 and v6 have different or ambiguous {$table} stable-key sets.");
        }
    }

    private function assertNoKillStatCollisions(int $worldId, int $fromRulesetId, int $toRulesetId): void
    {
        $collision = DB::selectOne(<<<'SQL'
SELECT source_stat.id AS source_id, target_stat.id AS target_id
  FROM nation_monster_kill_stats source_stat
  JOIN monster_definitions source_definition
    ON source_definition.id = source_stat.monster_definition_id
   AND source_definition.ruleset_version_id = ?
  JOIN monster_definitions target_definition
    ON target_definition.ruleset_version_id = ?
   AND target_definition.key = source_definition.key
  JOIN nation_monster_kill_stats target_stat
    ON target_stat.world_id = source_stat.world_id
   AND target_stat.nation_id = source_stat.nation_id
   AND target_stat.monster_definition_id = target_definition.id
   AND target_stat.id <> source_stat.id
 WHERE source_stat.world_id = ?
 LIMIT 1
SQL, [$fromRulesetId, $toRulesetId, $worldId]);
        if ($collision !== null) {
            throw new RuntimeException(
                "Monster kill stat collision {$collision->source_id}->{$collision->target_id}; refusing to merge aggregates.",
            );
        }
    }

    private function assertKillStatGuardEnabled(): void
    {
        $rows = DB::table('pg_trigger')
            ->where('tgrelid', DB::raw("'nation_monster_kill_stats'::regclass"))
            ->where('tgname', self::KILL_STAT_GUARD)->where('tgisinternal', false)->get(['tgenabled']);
        if ($rows->count() !== 1 || $rows->first()->tgenabled !== 'O') {
            throw new RuntimeException('nation_monster_kill_stat_guard must exist exactly once and be enabled.');
        }
    }

    private function assertLiveReferencesUseRuleset(int $worldId, int $rulesetId, string $stage): void
    {
        $counts = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*)
       FROM nation_command_queue_items item
       JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
      JOIN nations nation ON nation.id = queue.nation_id
      JOIN command_definitions definition ON definition.id = item.command_definition_id
      WHERE nation.world_id = ?
        AND item.status = 'queued'
        AND definition.ruleset_version_id <> ?) AS queue_mismatches,
    (SELECT count(*)
       FROM monster_instances instance
       JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
      WHERE instance.world_id = ? AND definition.ruleset_version_id <> ?) AS instance_mismatches,
    (SELECT count(*)
       FROM nation_monster_kill_stats stat
       JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
      WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS stat_mismatches
SQL, [$worldId, $rulesetId, $worldId, $rulesetId, $worldId, $rulesetId]);
        if ((int) $counts->queue_mismatches !== 0
            || (int) $counts->instance_mismatches !== 0
            || (int) $counts->stat_mismatches !== 0) {
            throw new RuntimeException(
                "shared-world live ruleset reference mismatch {$stage} "
                ."(queue={$counts->queue_mismatches}, instances={$counts->instance_mismatches}, "
                ."kill_stats={$counts->stat_mismatches}).",
            );
        }
    }
};
