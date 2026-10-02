<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\NationCreationService;
use App\Application\RulesetPublisher;
use App\Application\SecretaryEquipmentService;
use App\Application\SecretaryItemGrantService;
use App\Application\SecretaryItemSynthesisService;
use App\Application\SecretaryService;
use App\Application\SecretaryTurnService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Domain\World\WorldMutationLock;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryItemSynthesisTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private World $world;

    private User $owner;

    private Secretary $secretary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->world = $this->lightweightWorld();
        $this->owner = User::factory()->create();
        $this->secretary = app(SecretaryService::class)->ensureForUser($this->owner);
        $this->secretary->update(['name' => '合成検証秘書', 'named_at' => now()]);
    }

    private function activateDraft(): void
    {
        // Explicitly opt into the unpublished migration; normal v27 migration
        // discovery and the baseline adoption tests remain unchanged.
        (require database_path('migrations/draft-synthesis/2026_10_02_010000_add_secretary_item_syntheses.php'))->up();
        $draft = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        config(['hakoniwa.ruleset' => $draft]);
        $ruleset = app(RulesetPublisher::class)->publish($draft);
        $this->world->update(['ruleset_version_id' => $ruleset->id]);
    }

    public function test_current_v27_is_closed_without_the_draft_table_or_result_disclosure(): void
    {
        $ids = $this->materials();
        $this->getJson('/api/v1/me/secretary/item-synthesis')->assertUnauthorized();
        $this->actingAs($this->owner)->getJson('/api/v1/me/secretary/item-synthesis')
            ->assertOk()->assertExactJson(['data' => ['recipes' => []]]);
        $this->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))->assertUnprocessable();
        $this->assertSame(3, $this->secretary->itemInstances()->whereIn('id', $ids)->count());
    }

    public function test_full_warehouse_consumption_grant_and_receipt_replay_are_one_operation(): void
    {
        $this->activateDraft();
        $ids = $this->materials();
        $replacement = $this->materials();
        while ($this->secretary->itemInstances()->count() < SecretaryItemGrantService::INVENTORY_CAPACITY) {
            $this->item('ring');
        }
        $payload = $this->payload($ids);
        $first = $this->actingAs($this->owner)->postJson('/api/v1/me/secretary/item-synthesis', $payload)
            ->assertOk()->assertJsonPath('data.item.key', SecretaryItemCatalog::SUCCUBUS_EMBLEM)
            ->assertJsonPath('data.item.level', 1)->json();
        $this->assertSame(SecretaryItemGrantService::INVENTORY_CAPACITY - 2, $this->secretary->itemInstances()->count());
        $this->assertSame(0, $this->secretary->itemInstances()->whereIn('id', $ids)->count());
        $payload['ingredient_ids'] = array_reverse($ids);
        $this->postJson('/api/v1/me/secretary/item-synthesis', $payload)->assertOk()->assertExactJson($first);
        $this->assertDatabaseCount('secretary_item_syntheses', 1);
        $this->assertSame(1, $this->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::SUCCUBUS_EMBLEM)->count());
        // A second UUID racing for the exact consumed instances cannot select
        // a replacement set, even if another complete set is now available.
        $this->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))->assertUnprocessable();
        $payload['ingredient_ids'] = $replacement;
        $this->postJson('/api/v1/me/secretary/item-synthesis', $payload)->assertUnprocessable();
        $this->assertSame(3, $this->secretary->itemInstances()->whereIn('id', $replacement)->count());
        // Receipt is self-contained after the result is subsequently removed.
        SecretaryItemInstance::query()->findOrFail($first['data']['item']['id'])->delete();
        $payload['ingredient_ids'] = $ids;
        $this->postJson('/api/v1/me/secretary/item-synthesis', $payload)->assertOk()->assertExactJson($first);
        $this->assertSame(0, $this->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::SUCCUBUS_EMBLEM)->count());
    }

    public function test_disclosure_requires_all_three_owned_materials_and_is_not_a_public_profile_field(): void
    {
        $this->activateDraft();
        $this->item(SecretaryItemCatalog::LOVE_EMBLEM);
        $this->actingAs($this->owner)->getJson('/api/v1/me/secretary/item-synthesis')
            ->assertOk()->assertExactJson(['data' => ['recipes' => []]]);
        $this->item(SecretaryItemCatalog::TWIN_STAR_EMBLEM);
        $this->item(SecretaryItemCatalog::CRESCENT_EMBLEM);
        $this->getJson('/api/v1/me/secretary/item-synthesis')->assertOk()
            ->assertJsonPath('data.recipes.0.key', SecretaryItemCatalog::SUCCUBUS_EMBLEM);
        $other = User::factory()->create();
        app(SecretaryService::class)->ensureForUser($other);
        $this->actingAs($other)->getJson('/api/v1/me/secretary/item-synthesis')
            ->assertOk()->assertExactJson(['data' => ['recipes' => []]]);
        $response = $this->getJson('/api/v1/secretaries/'.$this->secretary->id)->assertOk();
        $this->assertStringNotContainsString('succubus_emblem', $response->getContent());
    }

    #[DataProvider('unavailableMaterialCases')]
    public function test_ineligible_materials_cannot_be_consumed(string $case): void
    {
        $this->activateDraft();
        $ids = $this->materials();
        $item = SecretaryItemInstance::query()->findOrFail($ids[0]);
        if ($case === 'equipped') {
            $item->update(['equipped_slot' => 2]);
        } elseif ($case === 'escrow') {
            $item->update(['is_escrowed' => true]);
        } else {
            $other = app(SecretaryService::class)->ensureForUser(User::factory()->create());
            $item->update(['secretary_id' => $other->id]);
        }
        $this->actingAs($this->owner)->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))->assertUnprocessable();
        $this->assertSame(3, SecretaryItemInstance::query()->whereIn('id', $ids)->count());
        $this->assertDatabaseCount('secretary_item_syntheses', 0);
    }

    public static function unavailableMaterialCases(): array
    {
        return [['equipped'], ['escrow'], ['foreign']];
    }

    public function test_a_failed_grant_rolls_back_consumption_and_does_not_record_a_receipt(): void
    {
        $this->activateDraft();
        $ids = $this->materials();
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'insert into "secretary_item_instances"')
                && in_array(SecretaryItemCatalog::SUCCUBUS_EMBLEM, $query->bindings, true)) {
                throw new RuntimeException('Simulated grant failure');
            }
        });
        try {
            app(SecretaryItemSynthesisService::class)->synthesize($this->owner, SecretaryItemCatalog::SUCCUBUS_EMBLEM, $ids, (string) Str::uuid());
            $this->fail('A failed grant must not commit consumption.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated grant failure', $exception->getMessage());
        }
        $this->assertSame(3, $this->secretary->itemInstances()->whereIn('id', $ids)->count());
        $this->assertDatabaseCount('secretary_item_syntheses', 0);
        $this->assertSame(0, $this->secretary->itemInstances()->where('item_key', SecretaryItemCatalog::SUCCUBUS_EMBLEM)->count());
    }

    public function test_unresolved_turn_and_historical_world_cannot_consume_materials(): void
    {
        $current = config('hakoniwa.ruleset');
        $this->activateDraft();
        $ids = $this->materials();
        $run = TurnRun::query()->create([
            'world_id' => $this->world->id, 'target_turn' => $this->world->current_turn + 1,
            'ruleset_version_id' => $this->world->ruleset_version_id,
            'random_seed' => hash('sha256', 'unresolved-synthesis'), 'source' => 'manual', 'is_dry_run' => false,
            'status' => TurnRun::STATUS_FAILED, 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);
        $this->actingAs($this->owner)->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))->assertUnprocessable();
        $run->delete();
        config(['hakoniwa.ruleset' => $current]);
        $this->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))->assertUnprocessable();
        $this->assertSame(3, $this->secretary->itemInstances()->whereIn('id', $ids)->count());
        $this->assertDatabaseCount('secretary_item_syntheses', 0);
    }

    public function test_lock_contention_is_a_retryable_response_without_consumption(): void
    {
        $this->activateDraft();
        $ids = $this->materials();
        $this->mock(WorldMutationLock::class)->shouldReceive('acquire')->once()
            ->andThrow(new TurnAlreadyRunningException('Busy'));
        $this->actingAs($this->owner)->postJson('/api/v1/me/secretary/item-synthesis', $this->payload($ids))
            ->assertConflict()->assertJsonPath('code', 'secretary_item_synthesis_busy');
        $this->assertSame(3, $this->secretary->itemInstances()->whereIn('id', $ids)->count());
        $this->assertDatabaseCount('secretary_item_syntheses', 0);
    }

    public function test_synthesized_equipment_doubles_ordinary_and_attraction_growth_in_the_canonical_turn_path(): void
    {
        $this->activateDraft();
        $nation = app(NationCreationService::class)->create($this->owner, $this->world, '合成効果島', '検証島主');
        $result = app(SecretaryItemSynthesisService::class)->synthesize($this->owner, SecretaryItemCatalog::SUCCUBUS_EMBLEM,
            $this->materials(), (string) Str::uuid());
        app(SecretaryEquipmentService::class)->mutate($this->owner, 2, $result['id'], $this->secretary->surfaceState->equipment_version);
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)->whereKeyNot($nation->capital()->value('map_cell_id'))->firstOrFail();
        $ruleset = $this->world->rulesetVersion()->firstOrFail();
        $seed = hash('sha256', 'synthesized-emblem-population');
        foreach ([false, true] as $attraction) {
            $cell->refresh()->update([
                'terrain_definition_id' => TerrainDefinition::query()->where('key', 'plain')->value('id'),
                'facility_definition_id' => FacilityDefinition::query()->where('key', 'village')->value('id'),
                'population' => 1000, 'facility_scale' => 0,
            ]);
            $run = TurnRun::query()->create([
                'world_id' => $this->world->id, 'target_turn' => 2, 'ruleset_version_id' => $ruleset->id,
                'random_seed' => $seed, 'source' => 'manual', 'is_dry_run' => true,
                'status' => TurnRun::STATUS_DRY_RUN, 'attempt_count' => 1,
                'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
            ]);
            $state = new TurnState;
            $state->setStableNationIds([$nation->id]);
            $state->setDevelopmentNationIds([$nation->id]);
            $state->setSurfaceCellIds([$cell->id]);
            if ($attraction) {
                $state->markAttraction($nation->id);
            }
            $context = new TurnContext($this->world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), $state);
            app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);
            $growthRules = $ruleset->settings['turn_processing']['settlement'][$attraction ? 'attraction_growth' : 'ordinary_growth'];
            $draw = (new TurnRandomStreamFactory($seed))->stream(TurnRandomStreamFactory::POPULATION_GROWTH)
                ->integer($growthRules['minimum'], $growthRules['maximum']);
            app(CompleteTurnEngine::class)->execute('process_cells', $context);
            $this->assertSame(1000 + 2 * $draw, $cell->fresh()->population);
        }
        $love = $this->item(SecretaryItemCatalog::LOVE_EMBLEM);
        app(SecretaryEquipmentService::class)->mutate($this->owner, 3, $love->id, $this->secretary->surfaceState->refresh()->equipment_version);
        $state = new TurnState;
        $context = new TurnContext($this->world, $run, $ruleset, 2, $seed, new TurnRandomStreamFactory($seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);
        $this->assertSame(110, app(SecretaryItemEffectAggregator::class)->snapshotPopulationGrowthPercent($state, $nation->id));
    }

    /** @return list<int> */
    private function materials(): array
    {
        return array_map(fn (string $key): int => $this->item($key)->id,
            [SecretaryItemCatalog::LOVE_EMBLEM, SecretaryItemCatalog::TWIN_STAR_EMBLEM, SecretaryItemCatalog::CRESCENT_EMBLEM]);
    }

    private function item(string $key): SecretaryItemInstance
    {
        return $this->secretary->itemInstances()->create([
            'item_key' => $key, 'level' => 1, 'equipped_slot' => null,
            'grant_key' => (string) Str::uuid(), 'obtained_at' => now(),
        ]);
    }

    /** @param list<int> $ids @return array<string, mixed> */
    private function payload(array $ids): array
    {
        return ['recipe_key' => SecretaryItemCatalog::SUCCUBUS_EMBLEM,
            'ingredient_ids' => $ids, 'request_key' => (string) Str::uuid()];
    }
}
