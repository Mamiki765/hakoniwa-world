<?php

namespace App\Application;

use App\Domain\Command\CommandParametersValidator;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\CommandDefinition;
use App\Models\NationCommandQueueItem;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final readonly class Ver240InstallUpgradeRebaseline
{
    public const CURRENT_KEY = 'hakoniwa-2s-plus-v11';

    public const CURRENT_VERSION = 11;

    public const CURRENT_CHECKSUM = '5c65c49ed3fd623375f004815ec6bba0b2f67524f61f0638c6fe528fe9599db8';

    public const SOURCE_MIGRATION = '2026_08_21_010000_publish_hakoniwa_2s_plus_v11';

    public const RESULT_FRESH_INSTALL = 'fresh_install';

    public const RESULT_PRODUCTION_UPGRADE = 'production_upgrade';

    private const WORLD_KEY = 'shared-world';

    private const INFRASTRUCTURE_TABLES = ['cache', 'cache_locks', 'migrations', 'sessions'];

    public function __construct(
        private CurrentCatalogInstaller $catalogs,
        private RulesetPublisher $publisher,
        private CommandParametersValidator $parameters,
        private SecretaryItemCatalog $items,
    ) {}

    public function run(): string
    {
        $sourceSettings = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v11.php');
        $currentSettings = config('hakoniwa.ruleset');
        if (($sourceSettings['key'] ?? null) !== self::CURRENT_KEY
            || ($sourceSettings['version'] ?? null) !== self::CURRENT_VERSION
            || $this->settingsChecksum($sourceSettings) !== self::CURRENT_CHECKSUM) {
            throw new RuntimeException('The authored current v11 Ruleset differs from the immutable ver 2.4.0 baseline.');
        }
        if (! is_array($currentSettings)) {
            throw new RuntimeException('The authored current Ruleset is missing.');
        }

        return DB::transaction(function () use ($sourceSettings, $currentSettings): string {
            $this->lockBusinessTables();
            if ($this->isFreshDatabase()) {
                $this->catalogs->install($currentSettings);
                $this->publisher->publish($currentSettings);
                $this->catalogs->assertInstalled($currentSettings);
                $this->publisher->assertPublished($currentSettings);

                return self::RESULT_FRESH_INSTALL;
            }

            $this->assertSupportedProductionSource($sourceSettings);

            return self::RESULT_PRODUCTION_UPGRADE;
        }, 1);
    }

    /** @param array<string, mixed> $settings */
    private function assertSupportedProductionSource(array $settings): void
    {
        if (! DB::table('migrations')->where('migration', self::SOURCE_MIGRATION)->exists()) {
            throw new RuntimeException('Upgrade blocked: the exact ver 2.3.1/v11 source migration is missing.');
        }

        $ruleset = $this->publisher->assertPublished($settings);
        $this->catalogs->assertInstalled($settings);
        $worlds = World::query()->orderBy('id')->get(['id', 'key', 'ruleset_version_id']);
        if ($worlds->count() !== 1 || $worlds->first()->key !== self::WORLD_KEY) {
            throw new RuntimeException('Upgrade blocked: the supported source has exactly one shared-world.');
        }
        $world = $worlds->first();
        if ((int) $world->ruleset_version_id !== (int) $ruleset->id) {
            throw new RuntimeException('Upgrade blocked: shared-world is not attached to exact current v11.');
        }

        $unresolved = TurnRun::query()->unresolvedProduction()->orderBy('id')->first(['id', 'status']);
        if ($unresolved instanceof TurnRun) {
            throw new RuntimeException(
                "Upgrade blocked: unresolved non-dry TurnRun {$unresolved->id} has status {$unresolved->status}.",
            );
        }

        $this->assertLiveDefinitionReferences((int) $world->id, (int) $ruleset->id);
        $this->assertRequestIdentity((int) $ruleset->id);
        $this->assertLegacyMonsterCycleSeeds();
        $this->assertSecretaryState();
    }

    private function assertLiveDefinitionReferences(int $worldId, int $rulesetId): void
    {
        $counts = DB::selectOne(<<<'SQL'
SELECT
    (SELECT count(*) FROM nation_command_queue_items item
      JOIN nation_command_queues queue ON queue.id = item.nation_command_queue_id
      JOIN nations nation ON nation.id = queue.nation_id
      JOIN command_definitions definition ON definition.id = item.command_definition_id
     WHERE nation.world_id = ? AND item.status = 'queued'
       AND definition.ruleset_version_id <> ?) AS queued_commands,
    (SELECT count(*) FROM monster_instances instance
      JOIN monster_definitions definition ON definition.id = instance.monster_definition_id
     WHERE instance.world_id = ? AND instance.state = 'alive'
       AND definition.ruleset_version_id <> ?) AS alive_monsters,
    (SELECT count(*) FROM nation_monster_kill_stats stat
      JOIN monster_definitions definition ON definition.id = stat.monster_definition_id
     WHERE stat.world_id = ? AND definition.ruleset_version_id <> ?) AS current_kill_stats
SQL, [$worldId, $rulesetId, $worldId, $rulesetId, $worldId, $rulesetId]);
        if ((int) $counts->queued_commands !== 0
            || (int) $counts->alive_monsters !== 0
            || (int) $counts->current_kill_stats !== 0) {
            throw new RuntimeException(
                "Upgrade blocked: live v11 reference mismatch (commands={$counts->queued_commands}, "
                ."monsters={$counts->alive_monsters}, stats={$counts->current_kill_stats}).",
            );
        }
    }

    private function assertRequestIdentity(int $currentRulesetId): void
    {
        $items = NationCommandQueueItem::query()->with(['definition', 'requestRulesetVersion'])->orderBy('id')->get();
        foreach ($items as $item) {
            if (! in_array($item->status, ['queued', 'completed', 'failed', 'cancelled'], true)
                || ! is_int($item->target_x) || ! is_int($item->target_y)
                || $item->quantity < 1 || $item->quantity > 99
                || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $item->request_key) !== 1
                || ($item->request_fingerprint !== null
                    && preg_match('/^[0-9a-f]{64}$/', $item->request_fingerprint) !== 1)) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} has malformed immutable request fields.");
            }
            $definition = $item->getRelation('definition');
            if (! $definition instanceof CommandDefinition) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} has no command definition.");
            }
            if ($item->status === 'queued'
                && ($definition->ruleset_version_id !== $currentRulesetId
                    || $item->request_ruleset_version_id === null
                    || ! is_int($item->queue_position)
                    || $item->queue_position < 1)) {
                throw new RuntimeException(
                    "Upgrade blocked: queued item {$item->id} lacks current execution identity or complete request provenance.",
                );
            }
            if ($item->request_fingerprint !== null && $item->request_ruleset_version_id === null) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} has fingerprint bytes without provenance.");
            }
            if ($item->request_ruleset_version_id === null) {
                continue;
            }

            $requestDefinition = CommandDefinition::query()
                ->where('ruleset_version_id', $item->request_ruleset_version_id)
                ->where('key', $definition->key)
                ->first();
            if (! $requestDefinition instanceof CommandDefinition) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} has contradictory request provenance.");
            }
            $schemas = $requestDefinition->metadata['parameters'] ?? [];
            if (! is_array($schemas)) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} references malformed parameter metadata.");
            }
            try {
                $validated = $this->parameters->validate($schemas, $item->parameters);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    "Upgrade blocked: queue item {$item->id} has malformed stored parameters.",
                    previous: $exception,
                );
            }
            if ($this->canonicalJson($validated) !== $this->canonicalJson($item->parameters)) {
                throw new RuntimeException("Upgrade blocked: queue item {$item->id} has non-canonical stored parameters.");
            }
        }
    }

    private function assertLegacyMonsterCycleSeeds(): void
    {
        $incomplete = DB::table('nation_monster_cycle_seed_requirements as requirement')
            ->leftJoin('nation_monster_cycle_stats as stat', function ($join): void {
                $join->on('stat.world_id', '=', 'requirement.world_id')
                    ->on('stat.nation_id', '=', 'requirement.nation_id')
                    ->on('stat.cycle_start_turn', '=', 'requirement.cycle_start_turn')
                    ->on('stat.cycle_end_turn', '=', 'requirement.cycle_end_turn');
            })
            ->where(function ($query): void {
                $query->whereNull('requirement.completed_at')
                    ->orWhereNull('stat.id')
                    ->orWhereNull('stat.seeded_at');
            })
            ->exists();
        if ($incomplete) {
            throw new RuntimeException('Upgrade blocked: legacy monster-cycle seed coverage is incomplete.');
        }
    }

    private function assertSecretaryState(): void
    {
        foreach (Secretary::query()->with(['skills', 'itemInstances'])->orderBy('id')->get() as $secretary) {
            $skillKeys = $secretary->skills->pluck('skill_key')->sort()->values()->all();
            $expectedKeys = collect(SecretarySkillCatalog::KEYS)->sort()->values()->all();
            if ($skillKeys !== $expectedKeys) {
                throw new RuntimeException("Upgrade blocked: Secretary {$secretary->id} has an incomplete skill catalog.");
            }
            foreach ($secretary->itemInstances as $item) {
                $definition = $this->items->definition($item->item_key);
                if ($item->level < 1 || $item->level > $definition['max_level']) {
                    throw new RuntimeException("Upgrade blocked: Secretary item {$item->id} has an invalid level.");
                }
            }
        }
    }

    private function lockBusinessTables(): void
    {
        $grammar = DB::connection()->getQueryGrammar();
        foreach ($this->businessTables() as $table) {
            DB::statement('LOCK TABLE '.$grammar->wrapTable($table).' IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function isFreshDatabase(): bool
    {
        foreach ($this->businessTables() as $table) {
            if (DB::table($table)->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function businessTables(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn (string $table): bool => ! in_array($table, self::INFRASTRUCTURE_TABLES, true),
        ));
        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @param array<string, mixed> $settings */
    private function settingsChecksum(array $settings): string
    {
        return hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = $this->canonicalize($nested);
        }

        return $value;
    }
}
