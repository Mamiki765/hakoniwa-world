<?php

use App\Application\RulesetPublisher;
use App\Models\TurnRun;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const KILL_STAT_GUARD = 'nation_monster_kill_stat_guard';

    private const SOURCE_KEY = 'hakoniwa-2s-plus-v6';

    private const TARGET_KEY = 'hakoniwa-2s-plus-v7';

    private const WORLD_KEY = 'shared-world';

    public function up(): void
    {
        $sourceSettings = config('hakoniwa.published_rulesets.'.self::SOURCE_KEY);
        $targetSettings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($sourceSettings) || ! is_array($targetSettings)) {
            throw new RuntimeException('The immutable v6 or v7 production ruleset snapshot is missing.');
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
            'The hakoniwa-2s-plus-v7 production migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     */
    private function assertApprovedTargetDiff(array $source, array $target): void
    {
        $secretary = $target['secretary'] ?? null;
        unset($target['secretary']);
        $target['key'] = self::SOURCE_KEY;
        $target['version'] = 6;
        if ($target !== $source || $secretary !== $this->expectedSecretaryContract()) {
            throw new RuntimeException('hakoniwa-2s-plus-v7 contains changes outside the approved Secretary v1 contract.');
        }
    }

    /** @return array<string, mixed> */
    private function expectedSecretaryContract(): array
    {
        $production = static fn (string $key, string $name, string $resource, string $command): array => [
            'key' => $key,
            'name' => $name,
            'initial_level' => 0,
            'level_requirement' => ['basis' => 'next_level_squared', 'multiplier' => 1],
            'effect' => [
                'type' => 'production_multiplier',
                'resource_key' => $resource,
                'per_mille_per_level' => 1,
            ],
            'experience_source' => [
                'type' => 'successful_command_execution',
                'command_key' => $command,
                'points_per_execution' => 1,
                'quantity_multiplier' => false,
            ],
        ];

        return ['skills' => [
            'agricultural_policy' => $production(
                'agricultural_policy', '農業政策', 'wheat', 'build_farm',
            ),
            'specialty_development' => $production(
                'specialty_development', '特産品開発', 'industrial_goods', 'build_factory',
            ),
            'gold_vein_survey' => $production(
                'gold_vein_survey', '金鉱脈調査', 'minerals', 'build_mine',
            ),
            'final_defense_line' => [
                'key' => 'final_defense_line',
                'name' => '最終防衛ライン',
                'initial_level' => 1,
                'level_requirement' => ['basis' => 'current_level_squared', 'multiplier' => 100],
                'effect' => [
                    'type' => 'final_defense_line',
                    'interceptions_per_level_per_turn' => 1,
                    'normal_defense_resolves_first' => true,
                    'exclude_monster_occupied_cells' => true,
                ],
                'experience_source' => [
                    'type' => 'owned_cell_missile_arrival',
                    'points_per_missile' => 1,
                    'include_normal_defense_interception' => true,
                    'include_secretary_interception' => true,
                    'include_actual_impact' => true,
                    'include_self_fired_collateral' => true,
                    'independent_from_interception_eligibility' => true,
                ],
            ],
        ]];
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
            throw new RuntimeException('shared-world is attached to an unexpected ruleset; refusing an implicit v7 migration.');
        }

        DB::statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE monster_definitions IN SHARE MODE');
        DB::statement('LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE');
        $this->assertTriggerEnabled('nation_command_queue_items', self::CONSISTENCY_CONSTRAINT);
        $this->assertTriggerEnabled('nation_monster_kill_stats', self::KILL_STAT_GUARD);
        $this->assertDefinitionSetsMatch('command_definitions', $fromRulesetId, $toRulesetId);
        $this->assertDefinitionSetsMatch('monster_definitions', $fromRulesetId, $toRulesetId);

        if ((int) $world->ruleset_version_id === $toRulesetId) {
            $this->assertLiveReferencesUseRuleset((int) $world->id, $toRulesetId, 'already migrated');

            return;
        }

        $this->assertNoUnresolvedNextTurnRun($world);
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

        $this->assertTriggerEnabled('nation_monster_kill_stats', self::KILL_STAT_GUARD);
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
                "Refusing v7 migration with unresolved non-dry TurnRun {$run->id}, "
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
            throw new RuntimeException("v6 and v7 have different or ambiguous {$table} stable-key sets.");
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

    private function assertTriggerEnabled(string $table, string $trigger): void
    {
        $rows = DB::table('pg_trigger')
            ->where('tgrelid', DB::raw("'{$table}'::regclass"))
            ->where('tgname', $trigger)->where('tgisinternal', false)->get(['tgenabled']);
        if ($rows->count() !== 1 || $rows->first()->tgenabled !== 'O') {
            throw new RuntimeException("{$trigger} must exist exactly once and be enabled.");
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
      WHERE nation.world_id = ? AND item.status = 'queued'
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
