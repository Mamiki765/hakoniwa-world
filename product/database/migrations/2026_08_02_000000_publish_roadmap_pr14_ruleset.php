<?php

use App\Application\RulesetPublisher;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CONSISTENCY_CONSTRAINT = 'nation_command_queue_items_world_ruleset_match';

    private const SOURCE_KEY = 'roadmap-pr11-v1';

    private const TARGET_KEY = 'roadmap-pr14-v1';

    private const WORLD_KEY = 'shared-world';

    public function up(): void
    {
        $sourceId = DB::table('ruleset_versions')->where('key', self::SOURCE_KEY)->value('id');
        if ($sourceId === null) {
            throw new RuntimeException('The immutable roadmap-pr11-v1 ruleset snapshot is missing.');
        }

        $settings = config('hakoniwa.published_rulesets.'.self::TARGET_KEY);
        if (! is_array($settings)) {
            throw new RuntimeException('The immutable roadmap-pr14-v1 ruleset snapshot is missing.');
        }

        $this->ensureSeabedOilFieldDefinition($settings);
        $target = app(RulesetPublisher::class)->publish($settings);
        DB::transaction(fn () => $this->moveWorldAndQueueItems((int) $sourceId, $target->id));
    }

    /** @param array<string, mixed> $settings */
    private function ensureSeabedOilFieldDefinition(array $settings): void
    {
        $key = 'seabed_oil_field';
        $definition = $settings['facility_definitions'][$key] ?? null;
        if (! is_array($definition)) {
            throw new RuntimeException('The roadmap-pr14-v1 seabed_oil_field facility definition is missing.');
        }
        $expected = [
            'key' => $key,
            'name' => $definition['name'],
            'asset_key' => $definition['asset_key'],
            'enabled' => true,
            'build_command_key' => $definition['build_command_key'],
            'visibility_policy' => $definition['visibility_policy'],
            'disguise_terrain_key' => null,
            'disguise_asset_key' => null,
            'scale_unit_people' => $definition['scale_unit_people'],
            'initial_scale' => $definition['initial_scale'],
            'scale_increment' => $definition['scale_increment'],
            'maximum_scale' => $definition['maximum_scale'],
            'workforce_per_scale_people' => $definition['workforce_per_scale_people'],
            'production_definition_key' => $definition['production_definition_key'],
            'buildable_terrain_keys' => json_encode($definition['buildable_terrain_keys'], JSON_THROW_ON_ERROR),
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
        ];
        $existing = DB::table('facility_definitions')->where('key', $key)->first();
        if ($existing === null) {
            DB::table('facility_definitions')->insert([
                ...$expected,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        foreach ($expected as $field => $value) {
            $stored = $existing->{$field};
            if (in_array($field, ['buildable_terrain_keys', 'metadata'], true)) {
                $stored = json_encode(json_decode((string) $stored, true, 512, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
            }
            if ($stored !== $value) {
                throw new RuntimeException("Existing facility definition {$key} differs at {$field}; refusing overwrite.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException(
            'The shared-world roadmap-pr14-v1 migration is forward-only; restore from an explicit backup instead.',
        );
    }

    private function moveWorldAndQueueItems(int $fromRulesetId, int $toRulesetId): void
    {
        $worldIdentity = DB::table('worlds')->where('key', self::WORLD_KEY)->first(['id', 'key']);
        if ($worldIdentity === null) {
            return;
        }

        $this->acquireWorldTurnMigrationLock($worldIdentity);
        $world = DB::table('worlds')->where('id', $worldIdentity->id)->lockForUpdate()
            ->first(['id', 'key', 'current_turn', 'ruleset_version_id']);
        if ($world === null) {
            return;
        }

        if (! in_array((int) $world->ruleset_version_id, [$fromRulesetId, $toRulesetId], true)) {
            throw new RuntimeException(
                'shared-world is attached to an unexpected ruleset; refusing an implicit ruleset migration.',
            );
        }
        if ((int) $world->ruleset_version_id === $fromRulesetId) {
            $this->assertNoNextTurnRun($world);
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
            DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' IMMEDIATE');

            return;
        }
        $this->assertQueueItemsUseRuleset((int) $world->id, $fromRulesetId, 'before migration');

        $fromDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $fromRulesetId)
            ->pluck('key', 'id');
        $toDefinitions = DB::table('command_definitions')->where('ruleset_version_id', $toRulesetId)
            ->pluck('id', 'key');
        if ($fromDefinitions->values()->sort()->values()->all() !== $toDefinitions->keys()->sort()->values()->all()) {
            throw new RuntimeException('Source and target rulesets have different command definition sets.');
        }

        foreach ($items as $item) {
            $commandKey = $fromDefinitions->get($item->command_definition_id);
            if (! is_string($commandKey) || ! $toDefinitions->has($commandKey)) {
                throw new RuntimeException("Queue item {$item->id} does not map cleanly between published rulesets.");
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
        DB::statement('SET CONSTRAINTS '.self::CONSISTENCY_CONSTRAINT.' IMMEDIATE');
    }

    private function acquireWorldTurnMigrationLock(object $world): void
    {
        $lockKey = "hakoniwa.turn.world.{$world->id}";
        $lock = DB::selectOne(
            'SELECT pg_try_advisory_xact_lock(hashtextextended(?, 0)) AS acquired',
            [$lockKey],
        );
        if (! in_array($lock?->acquired, [true, 1, '1', 't'], true)) {
            throw new RuntimeException(
                "Refusing to migrate shared-world {$world->id} ({$world->key}) while a turn operation "
                .'holds its advisory lock.',
            );
        }
    }

    private function assertNoNextTurnRun(object $world): void
    {
        DB::statement('LOCK TABLE turn_runs IN SHARE ROW EXCLUSIVE MODE');
        $targetTurn = (int) $world->current_turn + 1;
        $run = DB::table('turn_runs')
            ->where('world_id', $world->id)
            ->where('target_turn', $targetTurn)
            ->where('is_dry_run', false)
            ->orderBy('id')
            ->first(['id', 'target_turn', 'status']);

        if ($run === null) {
            return;
        }

        throw new RuntimeException(
            "Refusing to migrate shared-world {$world->id} ({$world->key}) with non-dry-run "
            ."turn run {$run->id}, target_turn={$run->target_turn}, status={$run->status}. "
            .'Resolve or retry the recorded run under roadmap-pr11-v1 before retrying this migration.',
        );
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
