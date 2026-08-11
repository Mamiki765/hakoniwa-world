<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const KILL_STAT_GUARD = 'nation_monster_kill_stat_guard';

    private const SOURCE_KEY = 'hakoniwa-2s-plus-v2';

    private const TARGET_KEY = 'hakoniwa-2s-plus-v3';

    private const WORLD_KEY = 'shared-world';

    public function up(): void
    {
        $sourceSettings = config('hakoniwa.published_rulesets.'.self::SOURCE_KEY);
        $targetSettings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($sourceSettings) || ! is_array($targetSettings)) {
            throw new RuntimeException('The immutable v2 or v3 production ruleset snapshot is missing.');
        }
        $this->assertMinimalTargetDiff($sourceSettings, $targetSettings);

        $publisher = app(RulesetPublisher::class);
        $source = $publisher->publish($sourceSettings);
        $target = $publisher->publish($targetSettings);
        DB::transaction(fn () => $this->moveLiveReferences($source->id, $target->id));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v3 production migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    private function assertMinimalTargetDiff(array $source, array $target): void
    {
        $normalized = $target;
        $normalized['key'] = self::SOURCE_KEY;
        $normalized['version'] = 2;
        unset($normalized['territory_transfer']);
        unset($normalized['turn_processing']['territory_influence']);

        $sourceTerritory = null;
        $targetTerritory = null;
        foreach ($source['command_definitions'] as $definition) {
            if ($definition['key'] === 'territory_expand') {
                $sourceTerritory = $definition;
                break;
            }
        }
        foreach ($target['command_definitions'] as $definition) {
            if ($definition['key'] === 'territory_expand') {
                $targetTerritory = $definition;
                break;
            }
        }
        if (! is_array($sourceTerritory)
            || $targetTerritory !== $this->approvedTerritoryDefinition($sourceTerritory)) {
            throw new RuntimeException('hakoniwa-2s-plus-v3 territory_expand differs from the approved exact definition.');
        }
        foreach ($normalized['command_definitions'] as $index => $definition) {
            if ($definition['key'] === 'territory_expand') {
                $normalized['command_definitions'][$index] = $sourceTerritory;
                break;
            }
        }
        if ($normalized !== $source) {
            throw new RuntimeException('hakoniwa-2s-plus-v3 contains changes outside the approved territory contracts.');
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function approvedTerritoryDefinition(array $source): array
    {
        return [
            ...$source,
            'description' => '自国領に隣接する中立陸地、またはactiveな他国の荒地・焼け野原を領有します。',
            'metadata' => [
                'consumes_turn' => true,
                'parameters' => [],
                'legacy_command' => 'Widen',
                'policy_version' => 3,
                'actor_states' => ['active'],
                'adjacency' => ['source_owner' => 'actor', 'directions' => 6],
                'neutral_target' => [
                    'allowed' => true,
                    'terrain_keys' => ['wasteland', 'scorched', 'plain', 'forest', 'mountain'],
                    'requires_empty_facility' => true,
                ],
                'foreign_target' => [
                    'owner_states' => ['active'],
                    'terrain_keys' => ['wasteland', 'scorched'],
                    'requires_empty_facility' => true,
                ],
                'monster_occupancy' => 'reject',
                'capital_core' => 'reject',
                'effect' => [
                    'owner' => 'actor',
                    'terrain' => 'preserve',
                    'population' => 'preserve',
                    'facility' => 'preserve',
                    'facility_scale' => 'preserve',
                    'resource_and_state' => 'preserve',
                ],
            ],
        ];
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
            throw new RuntimeException(
                'shared-world is attached to an unexpected ruleset; refusing an implicit production migration.',
            );
        }
        if ((int) $world->ruleset_version_id === $fromRulesetId) {
            $this->assertNoNextTurnRun($world);
        }

        DB::statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE monster_definitions IN SHARE MODE');
        DB::statement('LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE');
        $this->assertDefinitionSetsMatch('command_definitions', $fromRulesetId, $toRulesetId);
        $this->assertDefinitionSetsMatch('monster_definitions', $fromRulesetId, $toRulesetId);
        $this->assertKillStatGuardEnabled();

        if ((int) $world->ruleset_version_id === $toRulesetId) {
            $this->assertLiveReferencesUseRuleset((int) $world->id, $toRulesetId, 'already migrated');

            return;
        }

        $this->assertLiveReferencesUseRuleset((int) $world->id, $fromRulesetId, 'before migration');
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

    private function assertNoNextTurnRun(object $world): void
    {
        DB::statement('LOCK TABLE turn_runs IN SHARE ROW EXCLUSIVE MODE');
        $run = DB::table('turn_runs')
            ->where('world_id', $world->id)
            ->where('target_turn', (int) $world->current_turn + 1)
            ->where('is_dry_run', false)
            ->orderBy('id')->first(['id', 'target_turn', 'status']);
        if ($run !== null) {
            throw new RuntimeException(
                "Refusing v3 migration with unresolved non-dry TurnRun {$run->id}, target_turn={$run->target_turn}, status={$run->status}.",
            );
        }
    }

    private function assertDefinitionSetsMatch(string $table, int $fromRulesetId, int $toRulesetId): void
    {
        $source = DB::table($table)->where('ruleset_version_id', $fromRulesetId)->orderBy('key')->pluck('key')->all();
        $target = DB::table($table)->where('ruleset_version_id', $toRulesetId)->orderBy('key')->pluck('key')->all();
        if ($source !== $target || count($source) !== count(array_unique($source)) || count($target) !== count(array_unique($target))) {
            throw new RuntimeException("v2 and v3 have different or ambiguous {$table} stable-key sets.");
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
            ->where('tgname', self::KILL_STAT_GUARD)
            ->where('tgisinternal', false)
            ->get(['tgenabled']);
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
      WHERE nation.world_id = ? AND definition.ruleset_version_id <> ?) AS queue_mismatches,
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
                ."(queue={$counts->queue_mismatches}, instances={$counts->instance_mismatches}, kill_stats={$counts->stat_mismatches}).",
            );
        }
    }
};
