<?php

use App\Application\RulesetPublisher;
use App\Application\SecretaryV1MigrationSafetyGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_KEY = 'hakoniwa-2s-plus-v7';

    private const TARGET_KEY = 'hakoniwa-2s-plus-v8';

    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const KILL_STAT_GUARD = 'nation_monster_kill_stat_guard';

    private const REVIEWED_MISSILE_REBIND_ENV = 'HAKONIWA_V8_REBIND_REVIEWED_MISSILE_ITEMS';

    private const REVIEWED_MISSILE_REBIND_VALUE = 'CONFIRM_REVIEWED_V7_MISSILES_TO_V8';

    public function up(): void
    {
        $sourceSettings = config('hakoniwa.published_rulesets.'.self::SOURCE_KEY);
        $targetSettings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($sourceSettings) || ! is_array($targetSettings)) {
            throw new RuntimeException('The immutable v7 or v8 production ruleset snapshot is missing.');
        }
        $interception = $targetSettings['military']['defense_interception'] ?? null;
        unset($targetSettings['military']['defense_interception']);
        $targetSettings['key'] = self::SOURCE_KEY;
        $targetSettings['version'] = 7;
        if ($targetSettings !== $sourceSettings || $interception !== $this->expectedDefenseInterception()) {
            throw new RuntimeException('hakoniwa-2s-plus-v8 contains changes outside the approved defense interception contract.');
        }

        $publisher = app(RulesetPublisher::class);
        $source = $publisher->publish($sourceSettings);
        $target = $publisher->publish(config('hakoniwa.published_rulesets.'.self::TARGET_KEY));
        DB::transaction(fn () => $this->moveLiveReferences($source->id, $target->id));
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v8 production migration is forward-only; restore through an explicit reviewed conversion.',
        );
    }

    /** @return array<string, mixed> */
    private function expectedDefenseInterception(): array
    {
        return [
            'facility_key' => 'defense',
            'radius' => 2,
            'exclude_center' => true,
            'defense_target_cells' => 'exclude',
            'missile_keys' => ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'],
            'facility_owner_scope' => 'any',
            'monster_occupied_cells' => 'include',
            'self_fired_missiles' => 'include',
            'overlap_resolution' => 'single_interception',
            'resolve_before' => 'secretary',
        ];
    }

    private function moveLiveReferences(int $fromRulesetId, int $toRulesetId): void
    {
        $world = app(SecretaryV1MigrationSafetyGuard::class)
            ->lockAndAssertNoUnresolvedNextTurnRun('v8 migration');
        if ($world === null) {
            return;
        }
        if (! in_array((int) $world->ruleset_version_id, [$fromRulesetId, $toRulesetId], true)) {
            throw new RuntimeException('shared-world is attached to an unexpected ruleset; refusing an implicit v8 migration.');
        }

        DB::statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE monster_definitions IN SHARE MODE');
        DB::statement('LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE');
        $this->assertDefinitionSetsMatch('command_definitions', $fromRulesetId, $toRulesetId);
        $this->assertDefinitionSetsMatch('monster_definitions', $fromRulesetId, $toRulesetId);

        if ((int) $world->ruleset_version_id === $toRulesetId) {
            return;
        }
        $queuedMissiles = DB::table('nation_command_queue_items as item')
            ->join('nation_command_queues as queue', 'queue.id', '=', 'item.nation_command_queue_id')
            ->join('nations as nation', 'nation.id', '=', 'queue.nation_id')
            ->join('command_definitions as definition', 'definition.id', '=', 'item.command_definition_id')
            ->where('nation.world_id', $world->id)
            ->where('item.status', 'queued')
            ->where('definition.ruleset_version_id', $fromRulesetId)
            ->whereIn('definition.key', ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'])
            ->count();
        if ($queuedMissiles > 0
            && getenv(self::REVIEWED_MISSILE_REBIND_ENV) !== self::REVIEWED_MISSILE_REBIND_VALUE) {
            throw new RuntimeException(
                "v8 changes missile interception while {$queuedMissiles} missile queue item(s) remain. "
                .'Review them explicitly and set '.self::REVIEWED_MISSILE_REBIND_ENV.' for this migration only.',
            );
        }

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
    ON target.ruleset_version_id = ? AND target.key = source.key
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
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE instance.world_id = ?
   AND instance.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$toRulesetId, $world->id, $fromRulesetId]);

        $stats = DB::table('nation_monster_kill_stats as stat')
            ->join('monster_definitions as definition', 'definition.id', '=', 'stat.monster_definition_id')
            ->where('stat.world_id', $world->id)
            ->where('definition.ruleset_version_id', $fromRulesetId)
            ->count();
        if ($stats > 0) {
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_GUARD);
            DB::update(<<<'SQL'
UPDATE nation_monster_kill_stats stat
   SET monster_definition_id = target.id
  FROM monster_definitions source
  JOIN monster_definitions target
    ON target.ruleset_version_id = ? AND target.key = source.key
 WHERE stat.world_id = ?
   AND stat.monster_definition_id = source.id
   AND source.ruleset_version_id = ?
SQL, [$toRulesetId, $world->id, $fromRulesetId]);
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_GUARD);
        }
        DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' IMMEDIATE');
    }

    private function assertDefinitionSetsMatch(string $table, int $fromRulesetId, int $toRulesetId): void
    {
        $source = DB::table($table)->where('ruleset_version_id', $fromRulesetId)->orderBy('key')->pluck('key')->all();
        $target = DB::table($table)->where('ruleset_version_id', $toRulesetId)->orderBy('key')->pluck('key')->all();
        if ($source !== $target || count($source) !== count(array_unique($source))) {
            throw new RuntimeException("v7 and v8 have different or ambiguous {$table} stable-key sets.");
        }
    }
};
