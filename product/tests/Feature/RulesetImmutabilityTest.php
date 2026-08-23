<?php

namespace Tests\Feature;

use App\Application\OceanWorldGenerator;
use App\Application\RulesetPublisher;
use App\Models\CommandDefinition;
use App\Models\ProductionDefinition;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RulesetImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_publisher_reuses_the_exact_payload_and_rejects_snapshot_drift(): void
    {
        $settings = config('hakoniwa.ruleset');
        $publisher = app(RulesetPublisher::class);
        $published = RulesetVersion::query()->where('key', $settings['key'])->sole();
        $before = $published->getRawOriginal('settings');

        $this->assertSame($published->id, $publisher->publish($settings)->id);
        $different = $settings;
        $different['initial_money'] = $settings['initial_money'] + 1;

        try {
            $publisher->publish($different);
            $this->fail('Expected an immutable Ruleset payload collision.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different immutable payload', $exception->getMessage());
        }

        $this->assertSame($before, $published->fresh()->getRawOriginal('settings'));
    }

    public function test_current_publisher_rejects_definition_drift_without_repairing_it(): void
    {
        $settings = config('hakoniwa.ruleset');
        $publisher = app(RulesetPublisher::class);
        $published = RulesetVersion::query()->where('key', $settings['key'])->sole();
        $command = CommandDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->where('key', 'land_clear')
            ->sole();
        $command->update(['name' => 'drifted-command']);

        try {
            $publisher->publish($settings);
            $this->fail('Expected published definition drift failure.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('differs from its snapshot', $exception->getMessage());
        }
        $this->assertSame('drifted-command', $command->fresh()->name);
    }

    public function test_current_publisher_rejects_production_drift_without_repairing_it(): void
    {
        $settings = config('hakoniwa.ruleset');
        $publisher = app(RulesetPublisher::class);
        $published = RulesetVersion::query()->where('key', $settings['key'])->sole();
        $production = ProductionDefinition::query()
            ->where('ruleset_version_id', $published->id)
            ->where('key', 'farm_wheat')
            ->sole();
        $production->update(['production_per_scale' => 123]);

        try {
            $publisher->publish($settings);
            $this->fail('Expected published production drift failure.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('differs from its snapshot', $exception->getMessage());
        }
        $this->assertSame(123.0, $production->fresh()->production_per_scale);
    }

    public function test_initializer_is_current_idempotent_and_fails_closed_on_catalog_drift(): void
    {
        $world = app(OceanWorldGenerator::class)->initialize();
        $current = RulesetVersion::query()->where('key', config('hakoniwa.ruleset.key'))->sole();
        $this->assertSame($current->id, $world->ruleset_version_id);
        $this->assertSame($world->id, app(OceanWorldGenerator::class)->initialize()->id);

        $wheat = ResourceDefinition::query()
            ->where('key', 'wheat')
            ->sole();
        $wheat->update(['name' => 'drifted-name']);

        try {
            app(OceanWorldGenerator::class)->initialize();
            $this->fail('Expected catalog drift to stop initialization.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('explicit data migration', $exception->getMessage());
        }
        $this->assertSame('drifted-name', $wheat->fresh()->name);
    }
}
