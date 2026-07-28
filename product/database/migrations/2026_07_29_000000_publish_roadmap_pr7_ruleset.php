<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
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
        $this->moveWorldAndQueueItems((int) $sourceId, $target->id);
    }

    public function down(): void
    {
        $sourceId = DB::table('ruleset_versions')->where('key', self::SOURCE_KEY)->value('id');
        $targetId = DB::table('ruleset_versions')->where('key', self::TARGET_KEY)->value('id');
        if ($sourceId === null || $targetId === null) {
            throw new RuntimeException('Cannot roll back the shared-world ruleset migration because a published ruleset is missing.');
        }

        $this->moveWorldAndQueueItems((int) $targetId, (int) $sourceId);
    }

    private function moveWorldAndQueueItems(int $fromRulesetId, int $toRulesetId): void
    {
        $world = DB::table('worlds')->where('key', self::WORLD_KEY)->lockForUpdate()->first(['id', 'ruleset_version_id']);
        if ($world === null) {
            return;
        }
        if ((int) $world->ruleset_version_id === $toRulesetId) {
            return;
        }
        if ((int) $world->ruleset_version_id !== $fromRulesetId) {
            throw new RuntimeException(
                'shared-world is attached to an unexpected ruleset; refusing an implicit ruleset migration.',
            );
        }

        $fromDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $fromRulesetId)
            ->pluck('key', 'id');
        $toDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $toRulesetId)
            ->pluck('id', 'key');
        $fromKeys = $fromDefinitions->values()->sort()->values()->all();
        $toKeys = $toDefinitions->keys()->sort()->values()->all();
        if ($fromKeys !== $toKeys) {
            throw new RuntimeException('Source and target rulesets have different command definition sets.');
        }

        $items = DB::table('nation_command_queue_items')
            ->join('nation_command_queues', 'nation_command_queues.id', '=', 'nation_command_queue_items.nation_command_queue_id')
            ->join('nations', 'nations.id', '=', 'nation_command_queues.nation_id')
            ->where('nations.world_id', $world->id)
            ->get(['nation_command_queue_items.id', 'nation_command_queue_items.command_definition_id']);

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
    }
};
