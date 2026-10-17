<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\RulesetPublisher;
use App\Domain\Turn\UnresolvedNextTurnRunException;
use App\Models\MapCell;
use App\Models\NationResource;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CommandAndMissileTestCase;

final class EnergySavingExperienceUpgradeTest extends CommandAndMissileTestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_upgrade_refuses_the_next_official_run_and_preserves_existing_assets_and_source_identities(): void
    {
        // Exercise the exact supported predecessor, independently of later current Rulesets.
        $previous = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v38.php');
        $next = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v39.php');
        config(['hakoniwa.ruleset' => $previous]);
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, 'Energy upgrade nation');
        $item = $this->queue(app(CommandQueueService::class), $user, $nation, $this->surfaceMapSpace($world), 'attraction', null);
        $this->assertNotEmpty($item->request_fingerprint);
        $cell = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereDoesntHave('monsterOccupancy')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))->orderBy('id')->firstOrFail();
        $monster = $this->monster($world, $cell, 'aoi_inora');
        $monster->update(['current_hp' => 1]);
        $history = array_map(fn ($model): array => [$model, $model->fresh()->getAttributes()], [
            $world->rulesetVersion, $monster->definition, $monster, $monster->occupancy()->sole(),
            $nation, $nation->commandQueue()->sole(), $item,
        ]);
        $resources = NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->get()
            ->map(fn (NationResource $resource): array => $resource->getAttributes())->all();
        $assertPreserved = function () use ($history, $nation, $resources): void {
            foreach ($history as [$model, $attributes]) {
                $this->assertSame($attributes, $model->fresh()->getAttributes());
            }
            $this->assertSame($resources, NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->get()
                ->map(fn (NationResource $resource): array => $resource->getAttributes())->all());
        };
        $worldBefore = $world->fresh()->getAttributes();
        $rulesetCount = RulesetVersion::query()->count();
        $pending = $this->context($world, $world->current_turn + 1, hash('sha256', 'energy upgrade pending'), []);
        $pending->run->update(['is_dry_run' => false, 'status' => TurnRun::STATUS_PENDING]);
        config(['hakoniwa.ruleset' => $next]);
        $migration = require database_path('migrations/2026_10_17_030000_publish_energy_saving_experience_ruleset.php');
        try {
            $migration->up();
            $this->fail('Upgrade must refuse unresolved next official Turn state.');
        } catch (UnresolvedNextTurnRunException) {
            $this->assertSame($worldBefore, $world->fresh()->getAttributes());
            $this->assertSame($rulesetCount, RulesetVersion::query()->count());
            $assertPreserved();
        }

        $pending->run->update(['is_dry_run' => true, 'status' => TurnRun::STATUS_DRY_RUN]);
        $migration->up();
        $this->assertSame(app(RulesetPublisher::class)->assertPublishedSnapshot($next)->id, $world->fresh()->ruleset_version_id);
        $worldAfter = $world->fresh()->getAttributes();
        $expectedWorld = $worldBefore;
        $expectedWorld['ruleset_version_id'] = $worldAfter['ruleset_version_id'];
        $expectedWorld['updated_at'] = $worldAfter['updated_at'];
        $this->assertSame($expectedWorld, $worldAfter);
        $assertPreserved();
        $rulesetCount = RulesetVersion::query()->count();

        $migration->up();
        $this->assertSame($worldAfter, $world->fresh()->getAttributes());
        $this->assertSame($rulesetCount, RulesetVersion::query()->count());
        $assertPreserved();

        // The old request remains quantity 1, while edits use the new current definition.
        $originalDefinitionId = $item->command_definition_id;
        $originalRequestVersionId = $item->request_ruleset_version_id;
        $service = app(CommandQueueService::class);
        $service->updateQuantity($user, $nation, $item, 99, (int) $nation->commandQueue()->value('version'));
        $replayed = $service->add($user, $nation, $this->surfaceMapSpace($world), 'attraction', null, null,
            $item->request_key, 1, quantity: 1, quantityProvided: true);
        $this->assertTrue($replayed['duplicate']);
        $this->assertSame($item->id, $replayed['item']->id);
        $this->assertSame([99, $originalDefinitionId, $originalRequestVersionId], [
            $item->fresh()->quantity, $item->fresh()->command_definition_id, $item->fresh()->request_ruleset_version_id,
        ]);
    }
}
