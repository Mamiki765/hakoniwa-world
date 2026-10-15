<?php

namespace Tests\Feature;

use App\Application\CompleteTurnEngine;
use App\Application\DomesticCommandExecutor;
use App\Application\EcopoService;
use App\Application\FoodOverflowResolver;
use App\Application\NationAbandonmentService;
use App\Application\ParadoxBalanceService;
use App\Application\PlayerIslandEventService;
use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\EcopoRules;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\NationResource;
use App\Models\ResourceDefinition;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class EcopoSettlementTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public function test_only_food_remaining_after_nutrition_and_sale_earns_points(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '残余食料');
        $context = $this->context($world, 2, hash('sha256', 'ecopo food'), [$nation->id]);
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $context->ruleset);
        $wheat = ResourceDefinition::query()->where('key', 'wheat')->sole();
        $rate = $context->ruleset->settings['inventory_sale_rates']['wheat'];
        $batch = $rate['inventory_units'];
        NationResource::query()->where('nation_id', $nation->id)
            ->whereHas('definition', fn ($query) => $query->where('category', 'food'))->update(['amount' => 0]);
        $balance = NationResource::query()->where('nation_id', $nation->id)->where('resource_definition_id', $wheat->id)->sole();
        $balance->update(['amount' => $capacity->foodTons]);
        $nation->update(['money' => $capacity->money - $rate['money_units']]);
        $credit = app(CapacityBoundedAssetService::class)->creditFoodProductionForTurn(
            $nation, $wheat, 2 * $batch + 17, $context->ruleset,
        );
        $balance->decrement('amount', $batch); // Nutrition consumes this before overflow becomes final.
        $resolution = app(FoodOverflowResolver::class)->resolveAfterNutrition($context, $nation, $wheat, $credit);
        $this->assertSame($batch, $resolution['sold_tons']);
        $this->assertSame(17, $resolution['discarded_tons']);
        $this->assertSame(EcopoRules::fromSettings($context->ruleset->settings)->resourcePoints('wheat', 17), (int) $nation->fresh()->ecopo);
        $this->assertSame($capacity->money, (int) $nation->fresh()->money);
        $this->assertSame($capacity->foodTons, (int) $balance->fresh()->amount);

        $consumed = app(CapacityBoundedAssetService::class)->creditFoodProductionForTurn($nation, $wheat, 23, $context->ruleset);
        $balance->decrement('amount', 23);
        $empty = app(FoodOverflowResolver::class)->resolveAfterNutrition($context, $nation, $wheat, $consumed);
        $this->assertSame(0, $empty['discarded_tons']);
        $this->assertSame(EcopoRules::fromSettings($context->ruleset->settings)->resourcePoints('wheat', 17), (int) $nation->fresh()->ecopo);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ecopo.credited')->count());
    }

    public function test_final_inventory_discard_and_lost_finance_count_once_and_preserved_assets_do_not(): void
    {
        $world = $this->lightweightWorld();
        [, $nation] = $this->nation($world, '最終破棄');
        $context = $this->context($world, 2, hash('sha256', 'ecopo inventory'), [$nation->id]);
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $context->ruleset);
        $rules = EcopoRules::fromSettings($context->ruleset->settings);
        $minerals = ResourceDefinition::query()->where('key', 'minerals')->sole();
        $power = ResourceDefinition::query()->where('key', 'power')->sole();
        NationResource::query()->where('nation_id', $nation->id)->where('resource_definition_id', $minerals->id)
            ->update(['amount' => $capacity->resource('minerals') + 31]);
        NationResource::query()->where('nation_id', $nation->id)->where('resource_definition_id', $power->id)
            ->update(['amount' => 100]);
        $nation->update(['money' => $capacity->money + 7]); // Historical over-cap money is preserved.
        app(CompleteTurnEngine::class)->execute('enforce_capacities', $context);
        $this->assertSame($rules->resourcePoints('minerals', 31), (int) $nation->fresh()->ecopo);
        $this->assertSame($capacity->money + 7, (int) $nation->fresh()->money);
        app(CompleteTurnEngine::class)->execute('enforce_capacities', $context);
        $this->assertSame($rules->resourcePoints('minerals', 31), (int) $nation->fresh()->ecopo);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'ecopo.credited')->count());

        app(DomesticCommandExecutor::class)->execute($context);
        $finance = json_decode((string) DB::table('audit_events')->where('event_type', 'command.automatic_finance')->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertGreaterThan(0, $finance['overflow']);
        $this->assertSame($rules->resourcePoints('minerals', 31) + $rules->moneyPoints($finance['overflow']), (int) $nation->fresh()->ecopo);
    }

    public function test_turn_end_exchange_keeps_remainder_is_idempotent_and_rolls_back_with_paradox(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '回収');
        $context = $this->context($world, 2, hash('sha256', 'ecopo exchange'), [$nation->id]);
        $rules = EcopoRules::fromSettings($context->ruleset->settings);
        $before = 2 * $rules->pointsPerParadox + 37;
        $nation->update(['ecopo' => $before]);
        $paradox = app(ParadoxBalanceService::class);
        $pdBefore = $paradox->balanceFor($user->id);
        $engine = app(CompleteTurnEngine::class);
        $engine->execute('prepare_turn', $context);
        $engine->execute('aggregate_nations', $context);
        $metrics = $engine->execute('finalize_turn', $context)->metrics;
        $this->assertSame(2, $metrics['ecopo_paradox']);
        $this->assertSame(37, (int) $nation->fresh()->ecopo);
        $this->assertSame($pdBefore + 2, $paradox->balanceFor($user->id));
        app(EcopoService::class)->exchangeForTurn($context);
        $this->assertSame($pdBefore + 2, $paradox->balanceFor($user->id));
        $this->assertSame(1, DB::table('user_paradox_ledger')->where('entry_key', "ecopo:{$world->id}:2:{$nation->id}")->count());
        $shown = collect(app(PlayerIslandEventService::class)->ownerPage($nation, 1, 2)['groups'])
            ->flatMap(fn ($group) => $group['events'])->firstWhere('type', 'ecopo.exchanged');
        $this->assertNotNull($shown); // Verify event wiring without fixing the flavor text.

        $next = $this->context($world, 3, hash('sha256', 'ecopo rollback'), [$nation->id]);
        $nation->update(['ecopo' => $rules->pointsPerParadox + 37]);
        $engine->execute('prepare_turn', $next);
        $engine->execute('aggregate_nations', $next);
        try {
            DB::transaction(function () use ($next): void {
                app(CompleteTurnEngine::class)->execute('finalize_turn', $next);
                throw new RuntimeException('rollback exchange');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('rollback exchange', $exception->getMessage());
        }
        $this->assertSame($rules->pointsPerParadox + 37, (int) $nation->fresh()->ecopo);
        $this->assertSame($pdBefore + 2, $paradox->balanceFor($user->id));
        $this->assertSame(0, DB::table('user_paradox_ledger')->where('entry_key', "ecopo:{$world->id}:3:{$nation->id}")->count());
        // TurnRunner rebuilds PHP state after rollback while retaining run, turn and seed.
        $retry = new TurnContext($next->world, $next->run, $next->ruleset, $next->targetTurn,
            $next->randomSeed, new TurnRandomStreamFactory($next->randomSeed), new TurnState);
        $engine->execute('prepare_turn', $retry);
        $engine->execute('aggregate_nations', $retry);
        $engine->execute('finalize_turn', $retry);
        $this->assertSame(37, (int) $nation->fresh()->ecopo);
        $this->assertSame($pdBefore + 3, $paradox->balanceFor($user->id));
    }

    public function test_owner_api_keeps_ecopo_out_of_resource_catalog_and_abandonment_does_not_award_it(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '島のポイント');
        $nation->update(['ecopo' => 41]);
        $response = $this->actingAs($user)->getJson("/api/v1/nations/{$nation->id}")->assertOk()->assertJsonPath('data.ecopo', 41);
        $this->assertNotContains('ecopo', array_column($response->json('data.resources'), 'key'));
        $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk()->assertJsonMissingPath('data.ecopo');
        app(NationAbandonmentService::class)->abandon($user, $nation, $nation->name);
        $this->assertSame(41, (int) $nation->fresh()->ecopo);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'ecopo.credited')->count());
    }
}
