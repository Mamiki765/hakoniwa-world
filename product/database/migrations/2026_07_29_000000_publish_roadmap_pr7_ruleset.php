<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const SOURCE_KEY = 'roadmap-pr6-v1';

    private const TARGET_KEY = 'roadmap-pr7-v1';

    private const WORLD_KEY = 'shared-world';

    public function up(): void
    {
        $sourceId = DB::table('ruleset_versions')->where('key', self::SOURCE_KEY)->value('id');
        if ($sourceId === null) {
            throw new RuntimeException('The immutable roadmap-pr6-v1 ruleset snapshot is missing.');
        }

        $settings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr7-v1 ruleset snapshot is missing.');
        }

        $target = app(RulesetPublisher::class)->publish($settings);
        DB::transaction(function () use ($sourceId, $target): void {
            $this->moveWorldAndQueueItems((int) $sourceId, $target->id);
        });
    }

    public function down(): void
    {
        $sourceId = DB::table('ruleset_versions')->where('key', self::SOURCE_KEY)->value('id');
        $targetId = DB::table('ruleset_versions')->where('key', self::TARGET_KEY)->value('id');
        if ($sourceId === null || $targetId === null) {
            throw new RuntimeException('Cannot roll back the shared-world ruleset migration because a published ruleset is missing.');
        }

        DB::transaction(function () use ($sourceId, $targetId): void {
            $this->moveWorldAndQueueItems((int) $targetId, (int) $sourceId);
        });
    }

    private function moveWorldAndQueueItems(int $fromRulesetId, int $toRulesetId): void
    {
        $world = DB::table('worlds')->where('key', self::WORLD_KEY)->lockForUpdate()->first(['id', 'ruleset_version_id']);
        if ($world === null) {
            return;
        }

        DB::statement('LOCK TABLE nation_command_queues IN SHARE ROW EXCLUSIVE MODE');
        $queueIds = DB::table('nation_command_queues')
            ->whereIn('nation_id', DB::table('nations')->where('world_id', $world->id)->select('id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        DB::statement('LOCK TABLE nation_command_queue_items IN SHARE ROW EXCLUSIVE MODE');
        DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' DEFERRED');
        $items = DB::table('nation_command_queue_items')
            ->whereIn('nation_command_queue_id', $queueIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'command_definition_id']);

        if ((int) $world->ruleset_version_id === $toRulesetId) {
            $this->assertQueueItemsUseRuleset((int) $world->id, $toRulesetId, 'already migrated');

            return;
        }
        if ((int) $world->ruleset_version_id !== $fromRulesetId) {
            throw new RuntimeException(
                'shared-world is attached to an unexpected ruleset; refusing an implicit ruleset migration.',
            );
        }
        $this->assertQueueItemsUseRuleset((int) $world->id, $fromRulesetId, 'before migration');

        $fromDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $fromRulesetId)
            ->pluck('key', 'id');
        $toDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $toRulesetId)
            ->pluck('id', 'key');
        $fromKeys = $fromDefinitions->values()->sort()->values()->all();
        $toKeys = $toDefinitions->keys()->sort()->values()->all();
        if ($fromKeys !== $toKeys) {
            throw new RuntimeException('Source and target rulesets have different command definition sets.');
        }

        foreach ($items as $item) {
            $commandKey = $fromDefinitions->get($item->command_definition_id);
            if (! is_string($commandKey) || ! $toDefinitions->has($commandKey)) {
                throw new RuntimeException(
                    "Queue item {$item->id} does not map cleanly between published rulesets.",
                );
            }
            DB::table('nation_command_queue_items')->where('id', $item->id)->update([
                'command_definition_id' => $toDefinitions->get($commandKey),
            ]);
        }

        DB::table('worlds')->where('id', $world->id)->update([
            'ruleset_version_id' => $toRulesetId,
            'updated_at' => now(),
        ]);

        $this->assertQueueItemsUseRuleset((int) $world->id, $toRulesetId, 'after migration');
    }

    private function assertQueueItemsUseRuleset(int $worldId, int $rulesetId, string $stage): void
    {
        $mismatches = DB::table('nation_command_queue_items')
            ->join('nation_command_queues', 'nation_command_queues.id', '=', 'nation_command_queue_items.nation_command_queue_id')
            ->join('nations', 'nations.id', '=', 'nation_command_queues.nation_id')
            ->join('command_definitions', 'command_definitions.id', '=', 'nation_command_queue_items.command_definition_id')
            ->where('nations.world_id', $worldId)
            ->where('command_definitions.ruleset_version_id', '!=', $rulesetId)
            ->orderBy('nation_command_queue_items.id')
            ->limit(20)
            ->pluck('nation_command_queue_items.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($mismatches !== []) {
            throw new RuntimeException(
                "shared-world queue/ruleset mismatch {$stage}; item ids: ".implode(', ', $mismatches),
            );
        }
    }
};
