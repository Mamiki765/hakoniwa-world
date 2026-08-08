<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SOURCE_KEY = 'hakoniwa-2s-plus-v1';

    private const TARGET_KEY = 'hakoniwa-2s-plus-v2';

    private const WORLD_KEY = 'shared-world';

    private const KILL_STAT_TRIGGER = 'nation_monster_kill_stat_guard';

    public function up(): void
    {
        DB::transaction(fn () => $this->repairLiveMonsterReferences());
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The hakoniwa-2s-plus-v2 live monster reference repair is forward-only and cannot be rolled back destructively.',
        );
    }

    private function repairLiveMonsterReferences(): void
    {
        $identity = DB::table('worlds')->where('key', self::WORLD_KEY)->first(['id', 'key']);
        if ($identity === null) {
            return;
        }

        $this->acquireWorldTurnMigrationLock($identity);
        $world = DB::table('worlds')->where('id', $identity->id)->lockForUpdate()
            ->first(['id', 'key', 'ruleset_version_id']);
        if ($world === null) {
            return;
        }

        $rulesets = DB::table('ruleset_versions')
            ->whereIn('key', [self::SOURCE_KEY, self::TARGET_KEY])
            ->pluck('id', 'key');
        if ($rulesets->count() !== 2) {
            throw new RuntimeException('The immutable v1 or v2 production ruleset row is missing.');
        }
        $sourceRulesetId = (int) $rulesets->get(self::SOURCE_KEY);
        $targetRulesetId = (int) $rulesets->get(self::TARGET_KEY);
        if ((int) $world->ruleset_version_id !== $targetRulesetId) {
            throw new RuntimeException(
                'shared-world must already use hakoniwa-2s-plus-v2 before repairing live monster references.',
            );
        }

        DB::statement('LOCK TABLE monster_definitions IN SHARE MODE');
        DB::statement('LOCK TABLE monster_instances IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('LOCK TABLE nation_monster_kill_stats IN SHARE ROW EXCLUSIVE MODE');

        $sourceDefinitions = $this->monsterDefinitionMapping(
            $sourceRulesetId,
            $targetRulesetId,
        );
        $this->assertMonsterReferencesUseExpectedRulesets(
            (int) $world->id,
            $sourceRulesetId,
            $targetRulesetId,
        );

        $instances = DB::table('monster_instances')->where('world_id', $world->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'monster_definition_id']);
        foreach ($instances as $instance) {
            $mapping = $sourceDefinitions[(int) $instance->monster_definition_id] ?? null;
            if ($mapping === null) {
                continue;
            }
            $updated = DB::table('monster_instances')->where('id', $instance->id)->update([
                'monster_definition_id' => $mapping['target_id'],
            ]);
            if ($updated !== 1) {
                throw new RuntimeException("Monster instance {$instance->id} was not repaired exactly once.");
            }
        }

        $stats = DB::table('nation_monster_kill_stats')->where('world_id', $world->id)
            ->orderBy('id')->lockForUpdate()->get(['id', 'nation_id', 'monster_definition_id']);
        $statsToRepair = [];
        foreach ($stats as $stat) {
            $mapping = $sourceDefinitions[(int) $stat->monster_definition_id] ?? null;
            if ($mapping === null) {
                continue;
            }
            $collision = DB::table('nation_monster_kill_stats')
                ->where('world_id', $world->id)
                ->where('nation_id', $stat->nation_id)
                ->where('monster_definition_id', $mapping['target_id'])
                ->where('id', '!=', $stat->id)
                ->value('id');
            if ($collision !== null) {
                throw new RuntimeException(
                    "Monster kill stat {$stat->id} collides with existing v2 stat {$collision}; refusing to merge aggregates.",
                );
            }
            $statsToRepair[] = [$stat, $mapping['target_id']];
        }

        if ($statsToRepair !== []) {
            $this->assertKillStatGuardEnabled();
            DB::statement('ALTER TABLE nation_monster_kill_stats DISABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            foreach ($statsToRepair as [$stat, $targetDefinitionId]) {
                $updated = DB::table('nation_monster_kill_stats')->where('id', $stat->id)->update([
                    'monster_definition_id' => $targetDefinitionId,
                ]);
                if ($updated !== 1) {
                    throw new RuntimeException("Monster kill stat {$stat->id} was not repaired exactly once.");
                }
            }
            DB::statement('ALTER TABLE nation_monster_kill_stats ENABLE TRIGGER '.self::KILL_STAT_TRIGGER);
            $this->assertKillStatGuardEnabled();
        }

        $this->assertAllLiveReferencesMatch((int) $world->id, $targetRulesetId);
    }

    /** @return array<int, array{key: string, target_id: int}> */
    private function monsterDefinitionMapping(int $sourceRulesetId, int $targetRulesetId): array
    {
        $sourceRows = DB::table('monster_definitions')->where('ruleset_version_id', $sourceRulesetId)
            ->orderBy('id')->get(['id', 'key']);
        $targetRows = DB::table('monster_definitions')->where('ruleset_version_id', $targetRulesetId)
            ->orderBy('id')->get(['id', 'key']);

        foreach ([self::SOURCE_KEY => $sourceRows, self::TARGET_KEY => $targetRows] as $rulesetKey => $rows) {
            $duplicates = $rows->groupBy('key')->filter(static fn ($group): bool => $group->count() !== 1)->keys();
            if ($duplicates->isNotEmpty()) {
                throw new RuntimeException(
                    "{$rulesetKey} has ambiguous monster definition keys: ".$duplicates->implode(', '),
                );
            }
        }

        $sourceKeys = $sourceRows->pluck('key')->sort()->values()->all();
        $targetKeys = $targetRows->pluck('key')->sort()->values()->all();
        if ($sourceKeys !== $targetKeys) {
            throw new RuntimeException('v1 and v2 have different monster definition sets.');
        }

        $targetByKey = $targetRows->keyBy('key');
        $sourceDefinitions = [];
        foreach ($sourceRows as $source) {
            $target = $targetByKey->get($source->key);
            if ($target === null) {
                throw new RuntimeException("Monster definition {$source->key} has no unique v2 mapping.");
            }
            $sourceDefinitions[(int) $source->id] = [
                'key' => (string) $source->key,
                'target_id' => (int) $target->id,
            ];
        }

        return $sourceDefinitions;
    }

    private function assertMonsterReferencesUseExpectedRulesets(
        int $worldId,
        int $sourceRulesetId,
        int $targetRulesetId,
    ): void {
        foreach ([
            'monster_instances' => 'monster instance',
            'nation_monster_kill_stats' => 'monster kill stat',
        ] as $table => $label) {
            $ids = DB::table($table)
                ->join('monster_definitions', 'monster_definitions.id', '=', "{$table}.monster_definition_id")
                ->where("{$table}.world_id", $worldId)
                ->whereNotIn('monster_definitions.ruleset_version_id', [$sourceRulesetId, $targetRulesetId])
                ->orderBy("{$table}.id")->limit(20)->pluck("{$table}.id");
            if ($ids->isNotEmpty()) {
                throw new RuntimeException(
                    "shared-world {$label} rows reference an unexpected ruleset; ids: ".$ids->implode(', '),
                );
            }
        }
    }

    private function assertAllLiveReferencesMatch(int $worldId, int $rulesetId): void
    {
        $checks = [
            'queue item' => DB::table('nation_command_queue_items')
                ->join('nation_command_queues', 'nation_command_queues.id', '=', 'nation_command_queue_items.nation_command_queue_id')
                ->join('nations', 'nations.id', '=', 'nation_command_queues.nation_id')
                ->join('command_definitions', 'command_definitions.id', '=', 'nation_command_queue_items.command_definition_id')
                ->where('nations.world_id', $worldId)
                ->where('command_definitions.ruleset_version_id', '!=', $rulesetId)
                ->orderBy('nation_command_queue_items.id')->limit(20)
                ->pluck('nation_command_queue_items.id'),
            'monster instance' => DB::table('monster_instances')
                ->join('monster_definitions', 'monster_definitions.id', '=', 'monster_instances.monster_definition_id')
                ->where('monster_instances.world_id', $worldId)
                ->where('monster_definitions.ruleset_version_id', '!=', $rulesetId)
                ->orderBy('monster_instances.id')->limit(20)->pluck('monster_instances.id'),
            'monster kill stat' => DB::table('nation_monster_kill_stats')
                ->join('monster_definitions', 'monster_definitions.id', '=', 'nation_monster_kill_stats.monster_definition_id')
                ->where('nation_monster_kill_stats.world_id', $worldId)
                ->where('monster_definitions.ruleset_version_id', '!=', $rulesetId)
                ->orderBy('nation_monster_kill_stats.id')->limit(20)->pluck('nation_monster_kill_stats.id'),
        ];

        foreach ($checks as $label => $ids) {
            if ($ids->isNotEmpty()) {
                throw new RuntimeException(
                    "shared-world {$label}/ruleset mismatch after repair; ids: ".$ids->implode(', '),
                );
            }
        }
    }

    private function assertKillStatGuardEnabled(): void
    {
        $trigger = DB::selectOne(
            <<<'SQL'
SELECT tgenabled
FROM pg_trigger
WHERE tgrelid = 'nation_monster_kill_stats'::regclass
  AND tgname = ?
  AND NOT tgisinternal
SQL,
            [self::KILL_STAT_TRIGGER],
        );
        if ($trigger?->tgenabled !== 'O') {
            throw new RuntimeException(self::KILL_STAT_TRIGGER.' must be enabled before and after the repair.');
        }
    }

    private function acquireWorldTurnMigrationLock(object $world): void
    {
        $lock = DB::selectOne(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
            ["hakoniwa.turn.world.{$world->id}"],
        );
        if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
            throw new RuntimeException(
                "Refusing to repair shared-world {$world->id} ({$world->key}) while a turn operation holds its advisory lock.",
            );
        }
    }
};
