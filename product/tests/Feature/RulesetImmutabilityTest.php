<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Domain\Ruleset\ResetRequiredException;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesetImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_clean_database_publishes_immutable_legacy_snapshots_and_initializer_is_idempotent(): void
    {
        $source = RulesetVersion::query()->where('key', 'roadmap-pr2-v1')->firstOrFail();
        $pr6 = RulesetVersion::query()->where('key', 'roadmap-pr6-v1')->firstOrFail();
        $target = RulesetVersion::query()->where('key', 'roadmap-pr7-v1')->firstOrFail();
        $current = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        $sourceSnapshot = $source->settings;
        $sourceCommands = $this->commandSnapshot($source->id);
        $sourceProduction = $this->productionSnapshot($source->id);
        $pr6Snapshot = $pr6->settings;
        $pr6Commands = $this->commandSnapshot($pr6->id);
        $pr6Production = $this->productionSnapshot($pr6->id);
        $targetSnapshot = $target->settings;
        $targetCommands = $this->commandSnapshot($target->id);
        $targetProduction = $this->productionSnapshot($target->id);

        $this->assertArrayHasKey(
            'quantity',
            CommandDefinition::query()->where('ruleset_version_id', $source->id)
                ->where('key', 'excavate')->firstOrFail()->metadata['parameters'],
        );
        $this->assertArrayNotHasKey(
            'parameters',
            CommandDefinition::query()->where('ruleset_version_id', $pr6->id)
                ->where('key', 'excavate')->firstOrFail()->metadata,
        );
        $this->assertSame(9_999, $target->settings['base_money_capacity']);
        $this->assertSame(999_900, $target->settings['base_food_capacity_tons']);

        $world = app(OceanWorldGenerator::class)->initialize();
        $this->assertSame($current->id, $world->ruleset_version_id);
        app(OceanWorldGenerator::class)->initialize();

        $this->assertSame($sourceSnapshot, $source->fresh()->settings);
        $this->assertSame($pr6Snapshot, $pr6->fresh()->settings);
        $this->assertSame($targetSnapshot, $target->fresh()->settings);
        $this->assertSame($sourceCommands, $this->commandSnapshot($source->id));
        $this->assertSame($pr6Commands, $this->commandSnapshot($pr6->id));
        $this->assertSame($targetCommands, $this->commandSnapshot($target->id));
        $this->assertSame($sourceProduction, $this->productionSnapshot($source->id));
        $this->assertSame($pr6Production, $this->productionSnapshot($pr6->id));
        $this->assertSame($targetProduction, $this->productionSnapshot($target->id));
    }

    public function test_initializer_rejects_same_key_with_different_payload_without_mutating_published_ruleset(): void
    {
        $published = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        $before = $this->rulesetSnapshot($published);
        config(['hakoniwa.ruleset.initial_money' => 999]);

        try {
            app(OceanWorldGenerator::class)->initialize();
            $this->fail('Expected an immutable ruleset collision.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different immutable payload', $exception->getMessage());
        }

        $this->assertSame($before, $this->rulesetSnapshot($published->fresh()));
    }

    public function test_initializer_rejects_a_historical_world_without_repointing_or_repairing_catalog_drift(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $source = RulesetVersion::query()->where('key', 'roadmap-pr2-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $source->id]);

        try {
            app(OceanWorldGenerator::class)->initialize();
            $this->fail('Expected the historical World to require a reset.');
        } catch (ResetRequiredException $exception) {
            $this->assertStringContainsString('reset_required', $exception->getMessage());
        }
        $this->assertSame($source->id, $world->fresh()->ruleset_version_id);

        $current = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v1')->firstOrFail();
        $world->update(['ruleset_version_id' => $current->id]);
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->firstOrFail();
        $wheat->update(['name' => 'drifted-name']);
        try {
            app(OceanWorldGenerator::class)->initialize();
            $this->fail('Expected catalog drift to stop initialization.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explicit data migration', $exception->getMessage());
        }
        $this->assertSame('drifted-name', $wheat->fresh()->name);
    }

    public function test_ruleset_publisher_reuses_exact_payload_and_rejects_snapshot_or_definition_drift(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-publisher-v1';
        $publisher = app(RulesetPublisher::class);
        $published = $publisher->publish($settings);

        $this->assertSame($published->id, $publisher->publish($settings)->id);

        $different = $settings;
        $different['initial_money'] = 777;
        $this->expectException(DomainException::class);
        $publisher->publish($different);
    }

    public function test_ruleset_publisher_rejects_definition_drift_without_repairing_it(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-definition-drift-v1';
        $publisher = app(RulesetPublisher::class);
        $published = $publisher->publish($settings);
        $command = CommandDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'land_clear')->firstOrFail();
        $command->update(['name' => 'drifted-command']);

        try {
            $publisher->publish($settings);
            $this->fail('Expected published definition drift failure.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('differs from its snapshot', $exception->getMessage());
        }
        $this->assertSame('drifted-command', $command->fresh()->name);
    }

    public function test_ruleset_publisher_rejects_production_drift_without_repairing_it(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-production-drift-v1';
        $publisher = app(RulesetPublisher::class);
        $published = $publisher->publish($settings);
        $production = ProductionDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'farm_wheat')->firstOrFail();
        $production->update(['production_per_scale' => 123]);

        try {
            $publisher->publish($settings);
            $this->fail('Expected published production drift failure.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('differs from its snapshot', $exception->getMessage());
        }
        $this->assertSame(123.0, $production->fresh()->production_per_scale);
    }

    public function test_ruleset_publisher_preserves_exact_four_decimal_production_rates(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr6-v1');
        $settings['key'] = 'test-production-decimal-v1';
        $settings['production_definitions'][0]['production_per_scale'] = 1.2345;
        $settings['production_definitions'][1]['production_per_scale'] = 999_999_999_999.9999;
        $publisher = app(RulesetPublisher::class);

        $published = $publisher->publish($settings);
        $farmProduction = ProductionDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->where('key', 'farm_wheat')
            ->firstOrFail();
        $factoryProduction = ProductionDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->where('key', 'factory_industrial_goods')
            ->firstOrFail();

        $this->assertSame(1.2345, $farmProduction->production_per_scale);
        $this->assertSame(999_999_999_999.9999, $factoryProduction->production_per_scale);
        $this->assertSame(1.2345, $published->settings['production_definitions'][0]['production_per_scale']);
        $this->assertSame(
            999_999_999_999.9999,
            $published->settings['production_definitions'][1]['production_per_scale'],
        );
        $this->assertSame($published->id, $publisher->publish($settings)->id);
    }

    public function test_pr11_ruleset_is_idempotent_and_preserves_all_older_snapshots(): void
    {
        $older = RulesetVersion::query()->whereIn('key', [
            'roadmap-pr2-v1', 'roadmap-pr6-v1', 'roadmap-pr7-v1',
        ])->orderBy('key')->get()->mapWithKeys(fn (RulesetVersion $ruleset): array => [
            $ruleset->key => $this->rulesetSnapshot($ruleset),
        ])->all();
        $settings = config('hakoniwa.published_rulesets.roadmap-pr11-v1');
        $published = RulesetVersion::query()->where('key', 'roadmap-pr11-v1')->firstOrFail();
        $snapshot = $this->rulesetSnapshot($published);

        $republished = app(RulesetPublisher::class)->publish($settings);

        $this->assertSame($published->id, $republished->id);
        $this->assertSame($snapshot, $this->rulesetSnapshot($published->fresh()));
        $this->assertSame(30, $published->settings['command_queue_limit']);
        $this->assertSame(10_000, $published->settings['initial_resources']['wheat']);
        $landLevel = CommandDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'land_level')->firstOrFail();
        $this->assertFalse($landLevel->metadata['execution_deferred']);
        $this->assertArrayNotHasKey('earthquake_check_deferred', $landLevel->metadata);
        $this->assertArrayNotHasKey('earthquake_side_effect_deferred', $landLevel->metadata);
        $this->assertSame(
            $older,
            RulesetVersion::query()->whereIn('key', array_keys($older))->orderBy('key')->get()
                ->mapWithKeys(fn (RulesetVersion $ruleset): array => [
                    $ruleset->key => $this->rulesetSnapshot($ruleset),
                ])->all(),
        );
    }

    public function test_pr14_ruleset_is_idempotent_and_preserves_pr11_snapshot(): void
    {
        $pr11 = RulesetVersion::query()->where('key', 'roadmap-pr11-v1')->firstOrFail();
        $pr11Snapshot = $this->rulesetSnapshot($pr11);
        $settings = config('hakoniwa.published_rulesets.roadmap-pr14-v1');
        $published = RulesetVersion::query()->where('key', 'roadmap-pr14-v1')->firstOrFail();
        $snapshot = $this->rulesetSnapshot($published);

        $republished = app(RulesetPublisher::class)->publish($settings);

        $this->assertSame($published->id, $republished->id);
        $this->assertSame($snapshot, $this->rulesetSnapshot($published->fresh()));
        $this->assertSame($pr11Snapshot, $this->rulesetSnapshot($pr11->fresh()));
        $reclaim = CommandDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'reclaim')->firstOrFail();
        $excavate = CommandDefinition::query()->where('ruleset_version_id', $published->id)
            ->where('key', 'excavate')->firstOrFail();
        $this->assertSame(3, $reclaim->metadata['adjacent_water_spread_maximum']);
        $this->assertSame('seabed_oil_search', $excavate->metadata['oil_search_effect_key']);
        $this->assertArrayNotHasKey('oil_search_deferred', $excavate->metadata);
        $this->assertSame(
            ['sea'],
            FacilityDefinition::query()->where('key', 'seabed_oil_field')->firstOrFail()->buildable_terrain_keys,
        );
    }

    /** @return list<array<string, mixed>> */
    private function commandSnapshot(int $rulesetVersionId): array
    {
        return CommandDefinition::query()
            ->where('ruleset_version_id', $rulesetVersionId)
            ->orderBy('key')
            ->get()
            ->map(static fn (CommandDefinition $definition): array => [
                'id' => $definition->id,
                'key' => $definition->key,
                'metadata' => $definition->metadata,
                'updated_at' => $definition->updated_at?->toJSON(),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function productionSnapshot(int $rulesetVersionId): array
    {
        return ProductionDefinition::query()
            ->where('ruleset_version_id', $rulesetVersionId)
            ->orderBy('key')
            ->get()
            ->map(static fn (ProductionDefinition $definition): array => [
                'id' => $definition->id,
                'key' => $definition->key,
                'production_per_scale' => $definition->production_per_scale,
                'metadata' => $definition->metadata,
                'updated_at' => $definition->updated_at?->toJSON(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function rulesetSnapshot(RulesetVersion $ruleset): array
    {
        return [
            'version' => $ruleset->version,
            'settings' => $ruleset->settings,
            'is_active' => $ruleset->is_active,
            'updated_at' => $ruleset->updated_at?->toJSON(),
        ];
    }
}
