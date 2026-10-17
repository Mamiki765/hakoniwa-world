<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\CompleteTurnEngine;
use App\Application\DisasterTurnService;
use App\Application\NationCreationService;
use App\Application\SecretaryExperienceAwardService;
use App\Application\SecretaryTurnService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemEffectAggregator;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\NationResource;
use App\Models\SecretarySkill;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class PowerEconomyTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    public function test_turn_conserves_resources_matches_forecast_and_retry_and_awards_only_consumption(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '電力国', '発電島');
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        foreach (['wind_power', 'thermal_power', 'condenser', 'pizzeria'] as $key) {
            $this->facility($nation, $key);
        }
        $user->secretary()->sole()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::HOARDER_TALISMAN,
            'level' => 10,
            'equipped_slot' => 2,
            'grant_key' => 'test:power:hoarder',
            'obtained_at' => now(),
        ]);
        $nation->update(['money' => 0]);
        foreach (['wheat' => 10000, 'fish' => 0, 'monster_meat' => 0, 'oil' => 8, 'minerals' => 2000, 'power' => 1200] as $key => $amount) {
            $this->balance($nation, $key)->update(['amount' => $amount]);
        }
        $data = $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data');
        $forecast = $data['resource_forecast'];
        $powerCapacity = collect($data['resources'])->firstWhere('key', 'power')['capacity'];
        $this->assertGreaterThan(1200, $powerCapacity);
        $this->assertSame($powerCapacity, $forecast['power_summary']['capacity_mw']);
        $power = collect($forecast['rows'])->firstWhere('key', 'power');
        $this->assertGreaterThan(0, $power['production_range']['minimum']);
        $this->assertLessThanOrEqual($power['production_range']['maximum'], $power['production_range']['minimum']);
        $powerConsumption = $power['consumption_range']['minimum'];
        $this->assertGreaterThan(0, $powerConsumption);
        $this->assertSame($powerConsumption, $power['consumption_range']['maximum']);
        $this->assertSame(['minimum' => $powerCapacity, 'maximum' => $powerCapacity], $forecast['power_summary']['stored_after_mw']);
        $run = $this->createRun($world);
        DB::beginTransaction();
        $first = $this->context($world, $nation, $run);
        app(CompleteTurnEngine::class)->execute('nation_economy', $first);
        $result = $this->settlement($run);
        $balances = NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->pluck('amount', 'resource_definition_id')->all();
        DB::rollBack();
        $retry = $this->context($world->fresh(), $nation->fresh(), $run);
        app(CompleteTurnEngine::class)->execute('nation_economy', $retry);
        $this->assertSame($result, $this->settlement($run));
        $this->assertSame($balances, NationResource::query()->where('nation_id', $nation->id)->orderBy('id')->pluck('amount', 'resource_definition_id')->all());
        $this->assertLessThan(10_000, (int) $this->balance($nation, 'wheat')->amount);
        $this->assertSame($powerCapacity, (int) $this->balance($nation, 'power')->amount);
        $pizzeriaMoney = (int) $nation->fresh()->money;
        $this->assertGreaterThan(0, $pizzeriaMoney);
        $this->assertSame([['element_key' => 'pizzeria', 'resource_key' => 'money', 'amount' => $pizzeriaMoney, 'count' => null]], $retry->state->economicContributions($nation->id));
        $this->assertSame($first->state->economicContributions($nation->id), $retry->state->economicContributions($nation->id));
        $this->assertSame(2000, (int) $this->balance($nation, 'minerals')->amount);
        $this->assertSame(8 - $result['thermal']['oil_consumed'], (int) $this->balance($nation, 'oil')->amount);
        $this->assertSame(1200 + $result['generated_mw'], $result['consumed_mw'] + $result['stored_after_mw'] + $result['discarded_mw']);
        // Experience follows actual consumption, not generation.
        $this->assertSame($result['consumed_mw'], $powerConsumption);
        $this->assertSame($powerConsumption, $retry->state->pendingSecretaryExperience()[$nation->id]['energy_saving']);
        $flush = app(SecretaryTurnService::class)->flushExperience($retry);
        $this->assertSame($powerConsumption, $flush['experience_awarded']);
        // A successful operation at the money cap must report zero received, not requested revenue.
        $capacity = app(NationCapacityResolver::class)->resolve($nation, $world->rulesetVersion()->sole());
        $nation->update(['money' => $capacity->money]);
        $this->balance($nation, 'wheat')->update(['amount' => 10_000]);
        $this->balance($nation, 'power')->update(['amount' => 1_200]);
        $cappedRun = $this->createRun($world);
        $capped = $this->context($world, $nation, $cappedRun);
        app(CompleteTurnEngine::class)->execute('nation_economy', $capped);
        $this->assertGreaterThan(0, $this->settlement($cappedRun)['pizzeria_revenue']);
        $this->assertSame([['element_key' => 'pizzeria', 'resource_key' => 'money', 'amount' => 0, 'count' => null]], $capped->state->economicContributions($nation->id));
    }

    /** @return array<string, array{string}> */
    public static function energyExperienceSuits(): array
    {
        return [
            'general' => [SecretaryItemCatalog::SECRETARY_SUIT],
            'peace regular' => ['chancellor_suit'],
            'peace high quality' => ['grand_chancellor_suit'],
            'peace artifact' => ['star_chancellor_suit'],
        ];
    }

    #[DataProvider('energyExperienceSuits')]
    public function test_consumed_power_uses_one_snapshotted_suit_award_and_replays_after_rollback(string $itemKey): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, 'Energy suit nation', 'Energy suit island');
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        $this->facility($nation, 'pizzeria');
        $nation->update(['money' => 0]);
        foreach (['wheat' => 10_000, 'fish' => 0, 'monster_meat' => 0, 'power' => 1_200] as $key => $amount) {
            $this->balance($nation, $key)->update(['amount' => $amount]);
        }
        $secretary = $user->secretary()->sole();
        $suit = $secretary->itemInstances()->create([
            'item_key' => $itemKey,
            'level' => app(SecretaryItemCatalog::class)->definition($itemKey)['max_level'],
            'equipped_slot' => 2, 'grant_key' => 'test:power:experience-suit', 'obtained_at' => now(),
        ]);
        $run = $this->createRun($world);
        $effect = app(SecretaryItemEffectAggregator::class)->snapshotExperienceDouble(
            $this->context($world, $nation, $run)->state, $nation->id,
            SecretaryExperienceAwardService::PASSIVE_SKILL, SecretarySkillCatalog::ENERGY_SAVING,
        );
        $this->assertNotNull($effect);
        $this->assertGreaterThan(0, $effect['chance_percent']);
        $stream = TurnRandomStreamFactory::secretaryExperience(
            $nation->id, SecretaryExperienceAwardService::PASSIVE_SKILL, $effect['random_stream_version'],
        );
        $seed = null;
        for ($attempt = 0; $attempt < 10_000; $attempt++) {
            $candidate = hash('sha256', "power suit success {$attempt}");
            $random = new TurnRandomStreamFactory($candidate);
            if ($random->stream($stream)->integer(1, 100) <= $effect['chance_percent']) {
                $seed = $candidate;
                break;
            }
        }
        $this->assertIsString($seed);
        $run->update(['random_seed' => $seed]);
        // Reference state advances once for the whole consumed-MW batch, not once per MW.
        $expectedRandom = new TurnRandomStreamFactory($seed);
        $expectedRandom->stream($stream)->integer(1, 100);
        $attempts = [];
        foreach (['first', 'retry'] as $attempt) {
            DB::beginTransaction();
            try {
                $context = $this->context($world->fresh(), $nation->fresh(), $run);
                $suit->fresh()->update(['equipped_slot' => null]);
                $this->assertNull($suit->fresh()->equipped_slot);
                // Maximum-population XP remains excluded and must not advance the shared passive stream.
                app(SecretaryExperienceAwardService::class)->awardSkill(
                    $context, $nation->id, SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY,
                );
                app(CompleteTurnEngine::class)->execute('nation_economy', $context);
                $attempts[$attempt] = [
                    'settlement' => $this->settlement($run),
                    'balances' => NationResource::query()->where('nation_id', $nation->id)
                        ->orderBy('id')->pluck('amount', 'resource_definition_id')->all(),
                    'money' => (int) $nation->fresh()->money,
                    'pending' => $context->state->pendingSecretaryExperience(),
                    'random' => clone $context->random->stream($stream),
                    'flush' => app(SecretaryTurnService::class)->flushExperience($context),
                    'skills' => SecretarySkill::query()->where('secretary_id', $secretary->id)
                        ->orderBy('skill_key')->get(['skill_key', 'level', 'experience'])->toArray(),
                ];
            } finally {
                DB::rollBack();
            }
        }
        $this->assertEquals($attempts['first'], $attempts['retry']);
        $result = $attempts['retry'];
        $this->assertGreaterThan(1, $result['settlement']['consumed_mw']);
        $expectedExperience = $result['settlement']['consumed_mw'] * $effect['multiplier'];
        $this->assertSame([
            $nation->id => [
                SecretarySkillCatalog::DECLINING_BIRTHRATE_POLICY => 1,
                SecretarySkillCatalog::ENERGY_SAVING => $expectedExperience,
            ],
        ], $result['pending']);
        $this->assertSame($expectedExperience + 1, $result['flush']['experience_awarded']);
        $this->assertEquals($expectedRandom->stream($stream), $result['random']);
    }

    public function test_disaster_leaves_wind_for_half_price_queued_repair_without_expansion(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '修理国', '風車島');
        $wind = $this->facility($nation, 'wind_power');
        $run = $this->createRun($world);
        $context = $this->context($world, $nation, $run);
        // Exercise the real shared disaster mutation, independently of its chance.
        $damage = new \ReflectionMethod(DisasterTurnService::class, 'changeCell');
        $damage->invoke(app(DisasterTurnService::class), $context, $wind, 'earthquake', 'wasteland', false, 'disaster.cell_damaged');
        $this->assertSame('wind_power', $wind->fresh()->facility->key);
        $this->assertSame('damaged', $wind->fresh()->facility_operational_state);
        $this->assertSame(0, $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data.resource_forecast.power_summary.wind_expected_mw'));
        $nation->update(['money' => 100]);
        $space = $this->surfaceMapSpace($world);
        $previewUrl = "/api/v1/nations/{$nation->id}/map-spaces/{$space->id}/command-definitions?target_x={$wind->x}&target_y={$wind->y}";
        $beforeRepair = collect($this->getJson($previewUrl)->assertOk()->json('data.commands'))->firstWhere('key', 'build_wind_power');
        $this->assertSame(100, $beforeRepair['cost_money']);
        $this->assertSame(0, $beforeRepair['shortfall_money']);
        $this->assertSame('currently_executable', $beforeRepair['execution_preview_status']);
        $nation->update(['money' => 99]);
        $insufficient = collect($this->getJson($previewUrl)->assertOk()->json('data.commands'))->firstWhere('key', 'build_wind_power');
        $this->assertSame(1, $insufficient['shortfall_money']);
        $this->assertSame('currently_unavailable', $insufficient['execution_preview_status']);
        $nation->update(['money' => 100]);
        $queue = app(CommandQueueService::class)->add($user, $nation, $space, 'build_wind_power', $wind->x, $wind->y, (string) Str::uuid(), 1);
        $this->getJson("/api/v1/nations/{$nation->id}/map-spaces/{$space->id}/command-queue")
            ->assertOk()->assertJsonPath('data.items.0.effective_cost_money', 100);
        $firstRepair = collect($this->getJson($previewUrl.'&position=1')->assertOk()->json('data.commands'))->firstWhere('key', 'build_wind_power');
        $this->assertSame('currently_executable', $firstRepair['execution_preview_status']);
        $afterRepair = collect($this->getJson($previewUrl.'&position=2')->assertOk()->json('data.commands'))->firstWhere('key', 'build_wind_power');
        $this->assertSame(200, $afterRepair['cost_money']);
        $this->assertSame('currently_unavailable', $afterRepair['execution_preview_status']);
        app(CompleteTurnEngine::class)->execute('development_commands', $context);
        $this->assertSame('completed', $queue['item']->fresh()->status);
        $this->assertSame('operational', $wind->fresh()->facility_operational_state);
        $this->assertNull($wind->fresh()->facility_scale);
        $this->assertSame(0, (int) $nation->fresh()->money);
    }

    public function test_later_oil_production_does_not_fund_this_turn_and_generation_without_consumption_grants_no_xp(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, '境界国', '油田島');
        MapCell::query()->where('owner_nation_id', $nation->id)->update(['population' => 0]);
        foreach (['thermal_power', 'seabed_oil_field', 'pizzeria'] as $key) {
            $this->facility($nation, $key);
        }
        $user->secretary()->sole()->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::SECRETARY_SUIT,
            'level' => app(SecretaryItemCatalog::class)->definition(SecretaryItemCatalog::SECRETARY_SUIT)['max_level'],
            'equipped_slot' => 2, 'grant_key' => 'test:power:zero-consumption-suit', 'obtained_at' => now(),
        ]);
        foreach (['wheat' => 0, 'fish' => 0, 'monster_meat' => 0, 'oil' => 0, 'minerals' => 1000, 'power' => 35] as $key => $amount) {
            $this->balance($nation, $key)->update(['amount' => $amount]);
        }
        $forecast = $this->actingAs($user)->getJson('/api/v1/me/nation')->assertOk()->json('data.resource_forecast');
        $this->assertGreaterThan(0, collect($forecast['rows'])->firstWhere('key', 'oil')['production']);
        $power = collect($forecast['rows'])->firstWhere('key', 'power');
        $this->assertSame(['minimum' => 30, 'maximum' => 30], $power['production_range']);
        $this->assertSame(['minimum' => 0, 'maximum' => 0], $power['consumption_range']);
        $run = $this->createRun($world);
        $context = $this->context($world, $nation, $run);
        $effect = app(SecretaryItemEffectAggregator::class)->snapshotExperienceDouble(
            $context->state, $nation->id,
            SecretaryExperienceAwardService::PASSIVE_SKILL, SecretarySkillCatalog::ENERGY_SAVING,
        );
        $this->assertNotNull($effect);
        $stream = TurnRandomStreamFactory::secretaryExperience(
            $nation->id, SecretaryExperienceAwardService::PASSIVE_SKILL, $effect['random_stream_version'],
        );
        $expectedRandom = new TurnRandomStreamFactory($run->random_seed);
        app(CompleteTurnEngine::class)->execute('nation_economy', $context);
        $result = $this->settlement($run);
        $this->assertSame(65, $result['discarded_mw']);
        $this->assertSame(0, $result['pizzeria_revenue']);
        $this->assertSame([], $context->state->economicContributions($nation->id));
        $this->assertSame([], $context->state->pendingSecretaryExperience());
        $this->assertEquals($expectedRandom->stream($stream), $context->random->stream($stream));
        $this->assertSame(0, app(SecretaryTurnService::class)->flushExperience($context)['experience_awarded']);
        $this->assertSame(0, (int) $this->balance($nation, 'oil')->amount);
        $this->assertSame(0, (int) $this->balance($nation, 'minerals')->amount);
        $this->assertSame(0, (int) $this->balance($nation, 'power')->amount);
        $money = (int) $nation->fresh()->money;
        app(CompleteTurnEngine::class)->execute('resource_sales', $context);
        app(CompleteTurnEngine::class)->execute('enforce_capacities', $context);
        $this->assertSame($money, (int) $nation->fresh()->money);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'resource.automatic_sale')->whereRaw("metadata->>'resource_key' = 'power'")->count());
    }

    private function facility(Nation $nation, string $key): MapCell
    {
        $cell = MapCell::query()->where('owner_nation_id', $nation->id)->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))->orderBy('id')->firstOrFail();
        $definition = FacilityDefinition::query()->where('key', $key)->sole();
        if (! in_array($cell->terrain->key, $definition->buildable_terrain_keys, true)) {
            app(MapCellStateService::class)->transitionTerrain($cell, TerrainDefinition::query()->where('key', $definition->buildable_terrain_keys[0])->sole());
        }
        app(MapCellStateService::class)->setFacility($cell, $definition);
        $cell->save();

        return $cell;
    }

    private function balance(Nation $nation, string $key): NationResource
    {
        return NationResource::query()->where('nation_id', $nation->id)->whereHas('definition', fn ($query) => $query->where('key', $key))->sole();
    }

    private function createRun(World $world): TurnRun
    {
        return TurnRun::query()->create(['world_id' => $world->id, 'target_turn' => 2, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('ab', 32), 'source' => 'manual', 'is_dry_run' => true, 'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [], 'failure_context' => []]);
    }

    private function context(World $world, Nation $nation, TurnRun $run): TurnContext
    {
        $state = new TurnState;
        $state->setStableNationIds([$nation->id]);
        $state->setDevelopmentNationIds([$nation->id]);
        $context = new TurnContext($world, $run, $world->rulesetVersion()->sole(), 1, $run->random_seed, new TurnRandomStreamFactory($run->random_seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);

        return $context;
    }

    /** @return array<string, mixed> */
    private function settlement(TurnRun $run): array
    {
        return json_decode(DB::table('audit_events')->where('event_type', 'resource.power_settled')
            ->whereRaw("metadata->>'turn_run_id' = ?", [(string) $run->id])->sole()->metadata, true, 512, JSON_THROW_ON_ERROR);
    }
}
