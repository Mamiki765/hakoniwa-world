<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\NationCreationService;
use App\Application\RulesetPublisher;
use App\Application\TurnRunner;
use App\Application\Ver240DormancyRulesetUpgrade;
use App\Domain\Ruleset\RulesetUpgradeAuthoringCatalog;
use App\Models\MapCell;
use App\Models\NationCommandQueueItem;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;
use Throwable;

final class Ver240InstallUpgradeRebaselineTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private const MIGRATION = '2026_08_23_000000_add_nation_dormancy_and_publish_v12';

    public function test_supported_v11_source_upgrade_preserves_provenance_and_remains_runnable(): void
    {
        [$world, $item, $target] = $this->supportedSourceWithQueuedCommand();
        app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $fingerprint = $item->fresh()->request_fingerprint;
        $idleCounter = (int) $world->nations()->sole()->idle_counter;
        $this->assertSame(100, $idleCounter);
        $secretaryDigest = $this->secretaryDigest();

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();

        $this->assertSame($fingerprint, $item->fresh()->request_fingerprint);
        $this->assertSame(100, (int) $world->nations()->sole()->idle_counter);
        $this->assertSame($secretaryDigest, $this->secretaryDigest());
        $this->assertSame(Ver240DormancyRulesetUpgrade::TARGET_KEY, $world->fresh()->rulesetVersion()->value('key'));
        $this->assertSame(
            Ver240DormancyRulesetUpgrade::TARGET_KEY,
            $item->fresh()->definition()->firstOrFail()->rulesetVersion()->value('key'),
        );
        $this->assertDatabaseHas('audit_events', ['event_type' => 'ruleset.v12_activated', 'visibility' => 'admin']);
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);

        $postUpgradeRun = app(TurnRunner::class)->run($world->fresh());
        $this->assertSame(TurnRun::STATUS_COMPLETED, $postUpgradeRun->status);
        $this->assertSame(2, $world->fresh()->current_turn);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame('plain', $target->fresh()->terrain()->value('key'));
    }

    #[DataProvider('unresolvedStatuses')]
    public function test_every_global_unresolved_non_dry_status_rejects_without_partial_mutation(string $status): void
    {
        $world = $this->exactV11World();
        TurnRun::query()->create($this->turnRunState($world->id, $world->ruleset_version_id, $status, false));
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked("status {$status}");

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    public function test_dry_run_status_is_excluded_from_upgrade_cutoff(): void
    {
        $world = $this->exactV11World();
        TurnRun::query()->create($this->turnRunState(
            $world->id,
            $world->ruleset_version_id,
            TurnRun::STATUS_FAILED,
            true,
        ));
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->artisan('migrate', ['--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    public function test_missing_exact_source_migration_is_rejected_without_partial_mutation(): void
    {
        $this->exactV11World();
        DB::table('migrations')->whereIn('migration', [
            Ver240DormancyRulesetUpgrade::SOURCE_MIGRATION,
            self::MIGRATION,
        ])->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('requires the exact supported ver 2.4.0/v11 source');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_non_v11_world_is_rejected_without_ruleset_rebind_or_data_mutation(): void
    {
        $world = $this->lightweightWorld();
        app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v11'),
        );
        $v10 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get('hakoniwa-2s-plus-v10'),
        );
        $world->update(['ruleset_version_id' => $v10->id]);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('requires the exact supported ver 2.4.0/v11 source');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($v10->id, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_conflicting_v11_payload_is_rejected_without_repair(): void
    {
        $world = $this->exactV11World();
        $v11 = RulesetVersion::query()->where('key', Ver240DormancyRulesetUpgrade::SOURCE_KEY)->sole();
        $settings = $v11->settings;
        $settings['initial_money']++;
        $v11->update(['settings' => $settings]);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->businessSnapshot();

        $this->assertMigrationBlocked('different immutable payload');

        $this->assertSame($before, $this->businessSnapshot());
        $this->assertSame($settings, $v11->fresh()->settings);
        $this->assertSame($v11->id, $world->fresh()->ruleset_version_id);
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    /** @return array{World, NationCommandQueueItem, MapCell} */
    private function supportedSourceWithQueuedCommand(): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '保持国', '保持島主');
        $nation->update(['idle_counter' => 100]);
        $target = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))
            ->firstOrFail();
        $item = app(CommandQueueService::class)->add(
            user: $user,
            nation: $nation,
            mapSpace: $this->surfaceMapSpace($world),
            commandKey: 'land_clear',
            targetX: $target->x,
            targetY: $target->y,
            requestKey: (string) Str::uuid(),
            expectedVersion: 1,
        )['item'];
        $this->attachExactV11($world);

        return [$world, $item, $target];
    }

    private function exactV11World(): World
    {
        $world = $this->lightweightWorld();
        $this->attachExactV11($world);

        return $world;
    }

    private function attachExactV11(World $world): void
    {
        $v11 = app(RulesetPublisher::class)->publish(
            app(RulesetUpgradeAuthoringCatalog::class)->get(Ver240DormancyRulesetUpgrade::SOURCE_KEY),
        );
        DB::transaction(function () use ($world, $v11): void {
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match DEFERRED');
            DB::update(<<<'SQL'
UPDATE nation_command_queue_items AS item
SET command_definition_id = target.id
FROM command_definitions AS source,
     command_definitions AS target,
     nation_command_queues AS queue,
     nations AS nation
WHERE source.id = item.command_definition_id
  AND target.key = source.key
  AND target.ruleset_version_id = ?
  AND queue.id = item.nation_command_queue_id
  AND nation.id = queue.nation_id
  AND nation.world_id = ?
SQL, [$v11->id, $world->id]);
            $world->update(['ruleset_version_id' => $v11->id]);
            DB::statement('SET CONSTRAINTS nation_command_queue_items_world_ruleset_match IMMEDIATE');
        });
    }

    private function secretaryDigest(): string
    {
        return hash('sha256', json_encode([
            'secretaries' => DB::table('secretaries')->orderBy('id')->get()->all(),
            'skills' => DB::table('secretary_skills')->orderBy('id')->get()->all(),
            'items' => DB::table('secretary_item_instances')->orderBy('id')->get()->all(),
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, string> */
    private function businessSnapshot(): array
    {
        $tables = array_values(array_filter(
            Schema::getTableListing(schemaQualified: false),
            static fn (string $table): bool => ! in_array($table, ['cache', 'cache_locks', 'migrations', 'sessions'], true),
        ));
        sort($tables, SORT_STRING);
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = DB::table($table)->orderBy('id')->get()->map(
                static fn (object $row): array => (array) $row,
            )->all();
            $snapshot[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        }

        return $snapshot;
    }

    private function assertMigrationBlocked(string $expectedMessage): void
    {
        try {
            $this->artisan('migrate', [
                '--path' => 'database/migrations/'.self::MIGRATION.'.php',
                '--force' => true,
                '--no-interaction' => true,
            ])->execute();
            $this->fail('Expected the ver 2.4.0 migration preflight to block the upgrade.');
        } catch (Throwable $exception) {
            $this->assertStringContainsString($expectedMessage, $exception->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function turnRunState(int $worldId, int $rulesetId, string $status, bool $dryRun): array
    {
        return [
            'world_id' => $worldId,
            'target_turn' => 2,
            'ruleset_version_id' => $rulesetId,
            'random_seed' => str_repeat('a', 64),
            'source' => 'manual',
            'is_dry_run' => $dryRun,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ];
    }
}
