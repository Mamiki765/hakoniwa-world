<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class TurnEconomyTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_food_production_is_consumed_before_residual_hard_cap_overflow_is_resolved(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '食料超過国', '試験島主');
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $world->rulesetVersion()->firstOrFail());
        $this->facilityCell($nation, 'farm', 2);
        $this->setPopulation($nation, 1_100);
        $this->setResources($nation, ['wheat' => $capacity->foodTons, 'fish' => 0, 'monster_meat' => 0]);
        $this->setPolicy($nation, 'wheat', 'keep_amount', $capacity->foodTons + 10_000);
        $nation->update(['money' => 0]);
        [$context, $run] = $this->context($world, $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(0, $economy->metrics['food_overflow_sold']);
        $this->assertSame(0, $economy->metrics['food_overflow_revenue']);
        $this->assertSame(880, $economy->metrics['food_overflow_discarded']);
        $this->assertSame(0, (int) $nation->fresh()->money);
        $this->assertSame($capacity->foodTons, $this->resourceAmount($nation, 'wheat'));
        $production = $this->event($run, 'resource.food_produced', 'wheat');
        $this->assertSame(1_100, $production['requested_tons']);
        $this->assertSame(1_100, $production['applied_tons']);
        $this->assertSame(0, $production['overflow_tons']);
        $this->assertSame(1_100, $production['pre_nutrition_over_capacity_tons']);
        $this->assertSame(
            'after_population_nutrition_consumption',
            $production['overflow_resolution_stage'],
        );
        $consumption = $this->event($run, 'resource.food_consumed');
        $this->assertSame(220, $consumption['required_nutrition']);
        $this->assertSame(220, $this->foodRow($this->foodRows($consumption), 'wheat')['consumed_units']);
        $overflow = $this->event($run, 'resource.food_overflow_resolved', 'wheat');
        $this->assertSame(880, $overflow['requested_overflow_tons']);
        $this->assertSame(0, $overflow['sold_tons']);
        $this->assertSame(0, $overflow['revenue']);
        $this->assertSame(880, $overflow['discarded_tons']);
        $this->assertSame($capacity->foodTons, $overflow['food_capacity_tons']);
        $this->assertSame($capacity->money, $overflow['money_capacity']);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => 'resource.food_overflow_resolved',
            'nation_id' => $nation->id,
            'visibility' => 'nation',
        ]);

        $sales = app(CompleteTurnEngine::class)->execute('resource_sales', $context);
        $this->assertSame(0, $sales->metrics['sales']);
        $this->assertSame(0, (int) $nation->fresh()->money);
        $normalSale = $this->event($run, 'resource.automatic_sale', 'wheat');
        $this->assertSame(0, $normalSale['requested']);
        $this->assertSame(0, $normalSale['sold']);

        $events = app(PlayerIslandEventService::class);
        $ownerOverflow = collect($events->ownerPage($nation->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->firstWhere('type', 'resource.food_overflow_resolved');
        $this->assertIsArray($ownerOverflow);
        $this->assertSame(
            '食料上限を超えた小麦880トンのうち0トンを売却して0億円を得て、880トンを破棄しました。',
            $ownerOverflow['message'],
        );
        $publicEvents = collect($events->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertSame(0, $publicEvents->where('type', 'resource.food_overflow_resolved')->count());
        $this->assertStringNotContainsString('880', json_encode($publicEvents->all(), JSON_THROW_ON_ERROR));
    }

    public function test_food_hard_cap_overflow_is_discarded_when_money_capacity_is_full(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '資金上限国', '試験島主');
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $world->rulesetVersion()->firstOrFail());
        $this->facilityCell($nation, 'farm', 2);
        $this->setPopulation($nation, 1_100);
        $this->setResources($nation, ['wheat' => $capacity->foodTons, 'fish' => 0, 'monster_meat' => 0]);
        $this->setPolicy($nation, 'wheat', 'keep_amount', $capacity->foodTons + 10_000);
        $nation->update(['money' => $capacity->money]);
        [$context, $run] = $this->context($world, $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(0, $economy->metrics['food_overflow_sold']);
        $this->assertSame(0, $economy->metrics['food_overflow_revenue']);
        $this->assertSame(880, $economy->metrics['food_overflow_discarded']);
        $this->assertSame($capacity->money, (int) $nation->fresh()->money);
        $overflow = $this->event($run, 'resource.food_overflow_resolved', 'wheat');
        $this->assertSame(0, $overflow['sold_tons']);
        $this->assertSame(0, $overflow['revenue']);
        $this->assertSame(880, $overflow['discarded_tons']);
    }

    public function test_residual_food_overflow_uses_thousand_ton_sale_batches_and_discards_the_remainder(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '残余売却国',
            '試験島主',
        );
        $capacity = app(NationCapacityResolver::class)->resolve(
            $nation,
            $world->rulesetVersion()->firstOrFail(),
        );
        $this->facilityCell($nation, 'farm', 2);
        $this->setPopulation($nation, 2_000);
        $this->setResources($nation, [
            'wheat' => $capacity->foodTons,
            'fish' => 0,
            'monster_meat' => 0,
        ]);
        $nation->update(['money' => 0]);
        [$context, $run] = $this->context($world, $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(1_000, $economy->metrics['food_overflow_sold']);
        $this->assertSame(1, $economy->metrics['food_overflow_revenue']);
        $this->assertSame(600, $economy->metrics['food_overflow_discarded']);
        $this->assertSame(1, (int) $nation->fresh()->money);
        $this->assertSame($capacity->foodTons, $this->resourceAmount($nation, 'wheat'));
        $overflow = $this->event($run, 'resource.food_overflow_resolved', 'wheat');
        $this->assertSame(1_600, $overflow['requested_overflow_tons']);
        $this->assertSame(1_000, $overflow['sold_tons']);
        $this->assertSame(1, $overflow['revenue']);
        $this->assertSame(600, $overflow['discarded_tons']);
        $this->assertSame($capacity->foodTons, $overflow['food_capacity_tons']);
        $this->assertSame($capacity->money, $overflow['money_capacity']);
    }

    public function test_food_total_falls_below_resolved_capacity_when_production_is_less_than_consumption(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '生産不足国',
            '試験島主',
        );
        $capacity = app(NationCapacityResolver::class)->resolve(
            $nation,
            $world->rulesetVersion()->firstOrFail(),
        );
        $this->facilityCell($nation, 'farm', 1);
        $this->setPopulation($nation, 6_000);
        $this->setResources($nation, [
            'wheat' => $capacity->foodTons,
            'fish' => 0,
            'monster_meat' => 0,
        ]);
        [$context, $run] = $this->context($world, $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(1_000, $economy->metrics['wheat_produced']);
        $this->assertSame(1_200, $this->event($run, 'resource.food_consumed')['required_nutrition']);
        $this->assertSame($capacity->foodTons - 200, $this->resourceAmount($nation, 'wheat'));
        $this->assertSame(0, $economy->metrics['food_overflow_sold']);
        $this->assertSame(0, $economy->metrics['food_overflow_discarded']);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'resource.food_overflow_resolved')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])
            ->count());
    }

    public function test_v9_retry_keeps_the_published_pre_nutrition_overflow_order(): void
    {
        $world = $this->lightweightWorld();
        $v9 = RulesetVersion::query()->where('key', 'hakoniwa-2s-plus-v9')->firstOrFail();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            'v9再試行国',
            '試験島主',
        );
        $world->update(['ruleset_version_id' => $v9->id]);
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $v9);
        $this->facilityCell($nation, 'farm', 2);
        $this->setPopulation($nation, 1_100);
        $this->setResources($nation, ['wheat' => $capacity->foodTons, 'fish' => 0, 'monster_meat' => 0]);
        $nation->update(['money' => 0]);
        [$context, $run] = $this->context($world->fresh(), $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(1_000, $economy->metrics['food_overflow_sold']);
        $this->assertSame(100, $economy->metrics['food_overflow_discarded']);
        $this->assertSame($capacity->foodTons - 220, $this->resourceAmount($nation, 'wheat'));
        $this->assertSame(1_100, $this->event($run, 'resource.food_overflow_resolved', 'wheat')['requested_overflow_tons']);
    }

    public function test_v10_preserves_preexisting_overcapacity_and_resolves_only_residual_production(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '既存超過保全国',
            '試験島主',
        );
        $capacity = app(NationCapacityResolver::class)->resolve($nation);
        $this->facilityCell($nation, 'farm', 2);
        $this->setPopulation($nation, 1_100);
        $this->setResources($nation, [
            'wheat' => $capacity->foodTons + 500,
            'fish' => 0,
            'monster_meat' => 0,
        ]);
        [$context, $run] = $this->context($world, $nation);

        $economy = app(CompleteTurnEngine::class)->execute('nation_economy', $context);

        $this->assertSame(0, $economy->metrics['food_overflow_sold']);
        $this->assertSame(880, $economy->metrics['food_overflow_discarded']);
        $this->assertSame($capacity->foodTons + 500, $this->resourceAmount($nation, 'wheat'));
        $overflow = $this->event($run, 'resource.food_overflow_resolved', 'wheat');
        $this->assertSame(880, $overflow['requested_overflow_tons']);
        $this->assertSame(880, $overflow['discarded_tons']);
    }

    public function test_keep_amount_below_current_and_no_hard_cap_overflow_keep_existing_sale_semantics(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '通常売却国', '試験島主');
        $this->setPopulation($nation, 0);
        $this->setResources($nation, ['wheat' => 5_000, 'fish' => 0, 'monster_meat' => 0]);
        $this->setPolicy($nation, 'wheat', 'keep_amount', 1_000);
        $nation->update(['money' => 0]);
        [$context, $run] = $this->context($world, $nation);

        app(CompleteTurnEngine::class)->execute('nation_economy', $context);
        $this->assertSame(0, DB::table('audit_events')
            ->where('event_type', 'resource.food_overflow_resolved')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->count());

        $sales = app(CompleteTurnEngine::class)->execute('resource_sales', $context);
        $this->assertSame(1, $sales->metrics['sales']);
        $this->assertSame(4, $sales->metrics['revenue']);
        $this->assertSame(1_000, $this->resourceAmount($nation, 'wheat'));
        $this->assertSame(4, (int) $nation->fresh()->money);
        $normalSale = $this->event($run, 'resource.automatic_sale', 'wheat');
        $this->assertSame(4_000, $normalSale['requested']);
        $this->assertSame(4_000, $normalSale['sold']);
    }

    public function test_integer_workforce_food_sale_and_capacity_contracts(): void
    {
        $world = $this->lightweightWorld();
        $nation = app(NationCreationService::class)->create(User::factory()->create(), $world, '経済検証国', '試験島主');
        $engine = app(CompleteTurnEngine::class);
        $farm = $this->facilityCell($nation, 'farm', 1);
        $factory = $this->facilityCell($nation, 'factory', 1);
        $mine = $this->facilityCell($nation, 'mine', 2);

        $this->setPopulation($nation, 1);
        $this->setResources($nation, ['wheat' => 0, 'fish' => 0, 'monster_meat' => 0]);
        [$oneWorkerContext, $oneWorkerRun] = $this->context($world, $nation);
        $result = $engine->execute('nation_economy', $oneWorkerContext);
        $this->assertSame(1, $result->metrics['wheat_produced']);
        $this->assertSame(1, $this->resourceAmount($nation, 'wheat'));
        $oneWorkerProduction = $this->event($oneWorkerRun, 'resource.food_produced');
        $this->assertSame(1, $oneWorkerProduction['workers']);
        $this->assertSame(1, $oneWorkerProduction['applied_tons']);

        $this->setPopulation($nation, 200);
        $this->setResources($nation, ['wheat' => 0, 'fish' => 0, 'monster_meat' => 0]);
        [$partialContext, $partialRun] = $this->context($world, $nation);
        $engine->execute('nation_economy', $partialContext);
        $partialProduction = $this->event($partialRun, 'resource.food_produced');
        $this->assertSame(200, $partialProduction['workers']);
        $this->assertSame(200, $partialProduction['applied_tons']);
        $this->assertSame(40, $this->event($partialRun, 'resource.food_consumed')['required_nutrition']);
        $this->assertSame(160, $this->resourceAmount($nation, 'wheat'));

        $this->setPopulation($nation, 1_000);
        $this->setResources($nation, ['wheat' => 0, 'fish' => 0, 'monster_meat' => 0]);
        [$thousandContext, $thousandRun] = $this->context($world, $nation);
        $engine->execute('nation_economy', $thousandContext);
        $this->assertSame(1_000, $this->event($thousandRun, 'resource.food_produced')['applied_tons']);
        $this->assertSame(200, $this->event($thousandRun, 'resource.food_consumed')['required_nutrition']);
        $this->assertSame(800, $this->resourceAmount($nation, 'wheat'));

        $this->removeFacility($farm);
        $this->setPopulation($nation, 100);
        $this->setResources($nation, ['wheat' => 5, 'fish' => 3, 'monster_meat' => 10]);
        [$priorityContext, $priorityRun] = $this->context($world, $nation);
        $engine->execute('nation_economy', $priorityContext);
        $consumption = $this->event($priorityRun, 'resource.food_consumed');
        $priorityRows = $this->foodRows($consumption);
        $this->assertSame(20, $consumption['required_nutrition']);
        $this->assertSame(['wheat', 'fish', 'monster_meat'], array_column($priorityRows, 'resource_key'));
        $this->assertSame([5, 3, 6], array_column($priorityRows, 'consumed_units'));
        $this->assertSame([0, 0, 4], array_column($priorityRows, 'after'));
        $this->assertSame(0, $consumption['shortage']);

        $this->setPopulation($nation, 45);
        $this->setResources($nation, ['wheat' => 0, 'fish' => 0, 'monster_meat' => 5]);
        [$roundingContext, $roundingRun] = $this->context($world, $nation);
        $engine->execute('nation_economy', $roundingContext);
        $rounding = $this->event($roundingRun, 'resource.food_consumed');
        $monsterConsumption = $this->foodRow($this->foodRows($rounding), 'monster_meat');
        $this->assertSame(9, $rounding['required_nutrition']);
        $this->assertSame(5, $monsterConsumption['consumed_units']);
        $this->assertSame(10, $monsterConsumption['supplied_nutrition']);
        $this->assertSame(10, $rounding['supplied_nutrition']);
        $this->assertSame(0, $rounding['shortage']);

        $this->setPopulation($nation, 100);
        $this->setResources($nation, ['wheat' => 0, 'fish' => 0, 'monster_meat' => 0]);
        [$famineContext, $famineRun] = $this->context($world, $nation);
        $famineResult = $engine->execute('nation_economy', $famineContext);
        $this->assertSame(20, $famineResult->metrics['nutrition_shortage']);
        $this->assertTrue($famineContext->state->isFamine($nation->id));
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'resource.food_shortage')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $famineRun->id])->count());

        $this->setPopulation($nation, 10);
        $this->setResources($nation, [
            'wheat' => 0, 'fish' => 0, 'monster_meat' => 0,
            'industrial_goods' => 0, 'minerals' => 0,
        ]);
        [$allocationContext, $allocationRun] = $this->context($world, $nation);
        $allocation = $engine->execute('nation_economy', $allocationContext);
        $this->assertSame(3, $allocation->metrics['industrial_goods_produced']);
        $this->assertSame(7, $allocation->metrics['minerals_produced']);
        $this->assertSame(3, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(7, $this->resourceAmount($nation, 'minerals'));
        $this->assertSame(3, $this->event($allocationRun, 'resource.industrial_produced')['workers']);
        $this->assertSame(7, $this->event($allocationRun, 'resource.mineral_produced')['workers']);

        $this->restoreFacility($farm, 'farm', 1);
        $this->setPopulation($nation, 1_500);
        $this->setResources($nation, [
            'wheat' => 0, 'fish' => 0, 'monster_meat' => 0,
            'industrial_goods' => 0, 'minerals' => 0,
        ]);
        [$priorityAllocationContext, $priorityAllocationRun] = $this->context($world, $nation);
        $priorityAllocation = $engine->execute('nation_economy', $priorityAllocationContext);
        $this->assertSame(1_000, $priorityAllocation->metrics['wheat_produced']);
        $this->assertSame(167, $priorityAllocation->metrics['industrial_goods_produced']);
        $this->assertSame(333, $priorityAllocation->metrics['minerals_produced']);
        $this->assertSame(1_000, $this->event($priorityAllocationRun, 'resource.food_produced')['workers']);

        $this->setResources($nation, ['industrial_goods' => 2_500, 'minerals' => 3_500]);
        $this->setPolicy($nation, 'industrial_goods', 'sell_all', null);
        $this->setPolicy($nation, 'minerals', 'keep_amount', 1_500);
        Nation::query()->whereKey($nation->id)->update(['money' => 9_990]);
        [$saleContext, $saleRun] = $this->context($world, $nation);
        $sale = $engine->execute('resource_sales', $saleContext);
        $engine->execute('enforce_capacities', $saleContext);
        $this->assertSame(2, $sale->metrics['sales']);
        $this->assertSame(4, $sale->metrics['revenue']);
        $this->assertSame(500, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(1_500, $this->resourceAmount($nation, 'minerals'));
        $this->assertSame(9_994, $nation->fresh()->money);
        $industrialSale = $this->event($saleRun, 'resource.automatic_sale', 'industrial_goods');
        $this->assertSame(2_500, $industrialSale['before']);
        $this->assertSame(2_500, $industrialSale['requested']);
        $this->assertSame(2_000, $industrialSale['sold']);
        $this->assertSame(2, $industrialSale['revenue']);
        $this->assertSame(500, $industrialSale['after']);
        $mineralSale = $this->event($saleRun, 'resource.automatic_sale', 'minerals');
        $this->assertSame(2_000, $mineralSale['sold']);
        $this->assertSame(2, $mineralSale['revenue']);

        $this->setResources($nation, ['industrial_goods' => 2_500, 'minerals' => 3_500]);
        Nation::query()->whereKey($nation->id)->update(['money' => 9_998]);
        [$limitedContext, $limitedRun] = $this->context($world, $nation);
        $engine->execute('resource_sales', $limitedContext);
        $engine->execute('enforce_capacities', $limitedContext);
        $this->assertSame(1_500, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(3_500, $this->resourceAmount($nation, 'minerals'));
        $this->assertSame(9_999, $nation->fresh()->money);
        $limitedMinerals = $this->event($limitedRun, 'resource.automatic_sale', 'minerals');
        $this->assertSame(2_000, $limitedMinerals['requested']);
        $this->assertSame(0, $limitedMinerals['sold']);
        $this->assertSame(3_500, $limitedMinerals['after']);

        $this->setPolicy($nation, 'industrial_goods', 'stockpile', null);
        $this->setPolicy($nation, 'minerals', 'stockpile', null);
        Nation::query()->whereKey($nation->id)->update(['money' => 0]);
        $this->setResources($nation, [
            'industrial_goods' => 9_998_999,
            'minerals' => 9_999_000,
        ]);
        [$withinContext, $withinRun] = $this->context($world, $nation);
        $engine->execute('resource_sales', $withinContext);
        $within = $engine->execute('enforce_capacities', $withinContext);
        $this->assertSame(0, $within->metrics['overflow_reports']);
        $this->assertSame(9_998_999, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(9_999_000, $this->resourceAmount($nation, 'minerals'));
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'capacity.overflow')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $withinRun->id])->count());

        $this->setResources($nation, [
            'industrial_goods' => 9_999_123,
            'minerals' => 10_000_000,
        ]);
        [$resourceOverflowContext, $resourceOverflowRun] = $this->context($world, $nation);
        $resourceSale = $engine->execute('resource_sales', $resourceOverflowContext);
        $resourceOverflow = $engine->execute('enforce_capacities', $resourceOverflowContext);
        $this->assertSame(1, $resourceSale->metrics['sales']);
        $this->assertSame(1, $resourceSale->metrics['revenue']);
        $this->assertSame(1, $resourceOverflow->metrics['overflow_reports']);
        $this->assertSame(9_999_000, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(9_999_000, $this->resourceAmount($nation, 'minerals'));
        $industrialSale = $this->event($resourceOverflowRun, 'resource.automatic_sale', 'industrial_goods');
        $mineralSale = $this->event($resourceOverflowRun, 'resource.automatic_sale', 'minerals');
        $industrialOverflow = $this->event($resourceOverflowRun, 'capacity.overflow', 'industrial_goods');
        $this->assertSame(123, $industrialSale['requested']);
        $this->assertSame(0, $industrialSale['sold']);
        $this->assertSame('capacity_overflow', $industrialSale['sale_reason']);
        $this->assertSame(9_999_000, $industrialSale['resource_capacity']);
        $this->assertSame(1_000, $mineralSale['requested']);
        $this->assertSame(1_000, $mineralSale['sold']);
        $this->assertSame('capacity_overflow', $mineralSale['sale_reason']);
        $this->assertSame(123, $industrialOverflow['overflow']);
        $this->assertTrue($industrialOverflow['discarded']);
        $this->assertSame('post_sale_inventory_capacity', $industrialOverflow['source']);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'capacity.overflow')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $resourceOverflowRun->id])
            ->whereRaw("metadata->>'resource_key' = 'minerals'")->count());
        $this->assertSame(1, $nation->fresh()->money);

        $this->setPolicy($nation, 'industrial_goods', 'keep_amount', 10_000_000);
        Nation::query()->whereKey($nation->id)->update(['money' => 0]);
        $this->setResources($nation, ['industrial_goods' => 10_001_000, 'minerals' => 0]);
        [$saleBeforeCapacityContext, $saleBeforeCapacityRun] = $this->context($world, $nation);
        $engine->execute('resource_sales', $saleBeforeCapacityContext);
        $engine->execute('enforce_capacities', $saleBeforeCapacityContext);
        $saleBeforeCapacity = $this->event(
            $saleBeforeCapacityRun, 'resource.automatic_sale', 'industrial_goods',
        );
        $postSaleOverflow = $this->event(
            $saleBeforeCapacityRun, 'capacity.overflow', 'industrial_goods',
        );
        $this->assertSame(1_000, $saleBeforeCapacity['sold']);
        $this->assertSame(10_000_000, $saleBeforeCapacity['after']);
        $this->assertSame(1_000, $postSaleOverflow['overflow']);
        $this->assertSame(9_999_000, $postSaleOverflow['after']);
        $this->assertSame(1, $nation->fresh()->money);
        $this->assertLessThan(
            $postSaleOverflow['_event_id'],
            $saleBeforeCapacity['_event_id'],
            'Automatic sale must be recorded before resource capacity overflow.',
        );

        $this->setPolicy($nation, 'industrial_goods', 'stockpile', null);
        $this->setPolicy($nation, 'minerals', 'stockpile', null);
        Nation::query()->whereKey($nation->id)->update(['money' => 9_998]);
        $this->setResources($nation, [
            'industrial_goods' => 10_001_000,
            'minerals' => 10_001_000,
        ]);
        [$limitedOverflowContext, $limitedOverflowRun] = $this->context($world, $nation);
        $limitedSale = $engine->execute('resource_sales', $limitedOverflowContext);
        $limitedOverflow = $engine->execute('enforce_capacities', $limitedOverflowContext);
        $this->assertSame(1, $limitedSale->metrics['sales']);
        $this->assertSame(1, $limitedSale->metrics['revenue']);
        $this->assertSame(2, $limitedOverflow->metrics['overflow_reports']);
        $this->assertSame(9_999, $nation->fresh()->money);
        $this->assertSame(9_999_000, $this->resourceAmount($nation, 'industrial_goods'));
        $this->assertSame(9_999_000, $this->resourceAmount($nation, 'minerals'));
        $limitedIndustrialSale = $this->event(
            $limitedOverflowRun, 'resource.automatic_sale', 'industrial_goods',
        );
        $limitedMineralSale = $this->event(
            $limitedOverflowRun, 'resource.automatic_sale', 'minerals',
        );
        $this->assertSame(2_000, $limitedIndustrialSale['requested']);
        $this->assertSame(1_000, $limitedIndustrialSale['sold']);
        $this->assertSame(2_000, $limitedMineralSale['requested']);
        $this->assertSame(0, $limitedMineralSale['sold']);
        $this->assertSame(1_000, $this->event(
            $limitedOverflowRun, 'capacity.overflow', 'industrial_goods',
        )['overflow']);
        $this->assertSame(2_000, $this->event(
            $limitedOverflowRun, 'capacity.overflow', 'minerals',
        )['overflow']);

        Nation::query()->whereKey($nation->id)->update(['money' => 10_000]);
        $this->setResources($nation, ['wheat' => 1_000_000, 'fish' => 0, 'monster_meat' => 0]);
        $this->setPolicy($nation, 'industrial_goods', 'stockpile', null);
        $this->setPolicy($nation, 'minerals', 'stockpile', null);
        [$overflowContext, $overflowRun] = $this->context($world, $nation);
        $overflow = $engine->execute('enforce_capacities', $overflowContext);
        $this->assertSame(2, $overflow->metrics['overflow_reports']);
        $this->assertSame(10_000, $nation->fresh()->money);
        $this->assertSame(1_000_000, $this->resourceAmount($nation, 'wheat'));
        $this->assertSame(2, DB::table('audit_events')->where('event_type', 'capacity.overflow')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $overflowRun->id])->count());

        $this->setPolicy($nation, 'wheat', 'sell_all', null);
        [$forbiddenContext] = $this->context($world, $nation);
        $this->assertNotNull($factory->fresh()->facility_definition_id);
        $this->assertNotNull($mine->fresh()->facility_definition_id);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Stored sell_all policy is forbidden for wheat');
        $engine->execute('resource_sales', $forbiddenContext);
    }

    private function facilityCell(Nation $nation, string $facilityKey, int $scale): MapCell
    {
        $terrainKey = $facilityKey === 'mine' ? 'mountain' : 'plain';
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', $terrainKey))
            ->first();
        if ($cell === null) {
            $cell = MapCell::query()->where('owner_nation_id', $nation->id)
                ->whereNull('facility_definition_id')->firstOrFail();
            $cell = $cell->fresh(['terrain', 'facility']);
            app(MapCellStateService::class)->transitionTerrain(
                $cell,
                TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail(),
            );
            $cell->save();
        }
        $this->restoreFacility($cell, $facilityKey, $scale);

        return $cell;
    }

    private function restoreFacility(MapCell $cell, string $facilityKey, int $scale): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail(),
        );
        $cell->facility_scale = $scale;
        $cell->save();
    }

    private function removeFacility(MapCell $cell): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        app(MapCellStateService::class)->setFacility($cell, null);
        $cell->save();
    }

    private function setPopulation(Nation $nation, int $population): void
    {
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        $nation->capital()->firstOrFail()->cell()->update(['population' => $population]);
    }

    /** @param array<string, int> $amounts */
    private function setResources(Nation $nation, array $amounts): void
    {
        foreach ($amounts as $resourceKey => $amount) {
            NationResource::query()->where('nation_id', $nation->id)
                ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
                ->update(['amount' => $amount]);
        }
    }

    private function resourceAmount(Nation $nation, string $resourceKey): int
    {
        return (int) NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('key', $resourceKey))
            ->value('amount');
    }

    private function setPolicy(Nation $nation, string $resourceKey, string $policy, ?int $keepAmount): void
    {
        $resource = ResourceDefinition::query()->where('key', $resourceKey)->firstOrFail();
        NationResourceSalePolicy::query()->where('nation_id', $nation->id)
            ->where('resource_definition_id', $resource->id)
            ->update(['policy' => $policy, 'keep_amount' => $keepAmount]);
    }

    /** @return array{TurnContext, TurnRun} */
    private function context(World $world, Nation $nation): array
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $seed = hash('sha256', 'economy:'.TurnRun::query()->count());
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => 2,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds([$nation->id]);

        return [
            new TurnContext($world, $run, $ruleset, 1, $seed, new TurnRandomStreamFactory($seed), $state),
            $run,
        ];
    }

    /** @return array<string, mixed> */
    private function event(TurnRun $run, string $eventType, ?string $resourceKey = null): array
    {
        $query = DB::table('audit_events')->where('event_type', $eventType)
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id]);
        if ($resourceKey !== null) {
            $query->whereRaw("metadata->>'resource_key' = ?", [$resourceKey]);
        }

        $event = $query->first(['id', 'metadata']);
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $metadata['_event_id'] = (int) $event->id;

        return $metadata;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<array{resource_key: string, before: int, consumed_units: int, nutrition_per_unit: int, supplied_nutrition: int, after: int}>
     */
    private function foodRows(array $event): array
    {
        $rows = $event['resources'] ?? null;
        if (! is_array($rows)) {
            $this->fail('Food consumption event does not contain resource rows.');
        }

        /** @var list<array{resource_key: string, before: int, consumed_units: int, nutrition_per_unit: int, supplied_nutrition: int, after: int}> $rows */
        return $rows;
    }

    /**
     * @param  list<array{resource_key: string, before: int, consumed_units: int, nutrition_per_unit: int, supplied_nutrition: int, after: int}>  $rows
     * @return array{resource_key: string, before: int, consumed_units: int, nutrition_per_unit: int, supplied_nutrition: int, after: int}
     */
    private function foodRow(array $rows, string $resourceKey): array
    {
        foreach ($rows as $row) {
            if ($row['resource_key'] === $resourceKey) {
                return $row;
            }
        }

        $this->fail("Food consumption event is missing {$resourceKey}.");
    }
}
