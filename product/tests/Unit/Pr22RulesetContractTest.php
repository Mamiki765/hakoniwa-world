<?php

namespace Tests\Unit;

use App\Domain\Command\CommandFailureReason;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Turn\TurnPipeline;
use DomainException;
use Tests\TestCase;

final class Pr22RulesetContractTest extends TestCase
{
    public function test_pr22_publishes_the_audited_command_catalog_costs_and_turn_phase(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr22-v1');
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);
        $commands = collect($settings['command_definitions'])->mapWithKeys(
            static fn (array $definition): array => [$definition['key'] => $definition['cost_money']],
        )->all();

        $this->assertSame('roadmap-pr22-v1', $validated['key']);
        $this->assertSame(
            'neutral',
            $settings['facility_definitions']['seabed_base']['disguise_ownership_policy'],
        );
        $this->assertArrayNotHasKey(
            'disguise_ownership_policy',
            $settings['facility_definitions']['missile_base'],
        );
        $this->assertSame([
            'land_clear' => 5,
            'land_level' => 100,
            'reclaim' => 150,
            'excavate' => 200,
            'build_farm' => 20,
            'build_factory' => 100,
            'build_mine' => 300,
            'logging' => 0,
            'territory_expand' => 100,
            'plant_forest' => 50,
            'build_missile_base' => 300,
            'build_defense_facility' => 800,
            'build_seabed_base' => 8_000,
            'build_monument' => 9_999,
            'build_decoy' => 1,
            'missile' => 20,
            'pp_missile' => 50,
            'land_destruction_missile' => 100,
            'spp_missile' => 500,
            'monster_dispatch' => 3_000,
            'finance' => 0,
            'money_aid' => 0,
            'food_aid' => 0,
            'attraction' => 1_000,
            'relocate_capital' => 1_000,
        ], $commands);
        $this->assertSame(1_000, $settings['capital_relocation_cost_money']);
        $this->assertSame([
            'after' => 'nation_economy',
            'before' => 'development_commands',
        ], $settings['turn_processing']['resource_sale_phase']);
        $this->assertSame('resource_sales', TurnPipeline::CANONICAL_PHASE_KEYS[4]);
        $this->assertSame('development_commands', TurnPipeline::CANONICAL_PHASE_KEYS[5]);
        $this->assertCount(12, TurnPipeline::CANONICAL_PHASE_KEYS);
        $this->assertNotContains('automatic_land_clear_set', array_keys($commands));
    }

    public function test_pr22_missile_visibility_dormant_boundary_and_failure_enum_are_exact(): void
    {
        $military = config('hakoniwa.published_rulesets.roadmap-pr22-v1.military');

        $this->assertSame([
            'launch_summary' => 'public',
            'meaningful_impacts' => 'public',
            'ineffective_impacts' => 'aggregate_per_launch',
            'firing_nation_details' => 'private',
            'anonymous_missile_keys' => [],
        ], $military['visibility']);
        $this->assertSame([
            'explicit_target_state' => 'active',
            'no_effect_owner_states' => ['dormant_frozen', 'dormant_contestable', 'sunken_archived'],
            'preserve' => ['cell', 'facility', 'population', 'monster_occupancy'],
            'monster_exception' => false,
        ], $military['dormant_impact']);
        $this->assertSame([
            'missile' => [20, 2, 'scorched', true],
            'pp_missile' => [50, 1, 'scorched', true],
            'land_destruction_missile' => [100, 2, null, false],
            'spp_missile' => [500, 0, 'scorched', true],
        ], collect($military['missiles'])->map(static fn (array $missile): array => [
            $missile['cost_money_per_shot'],
            $missile['deviation_radius'],
            $missile['creates_terrain'],
            $missile['refugees'],
        ])->all());
        $this->assertSame([
            'insufficient_funds', 'insufficient_resource', 'invalid_terrain',
            'missing_adjacent_territory', 'no_adjacent_owned_land', 'foreign_adjacent_water', 'foreign_owned',
            'not_owned', 'already_owned', 'occupied_by_monster', 'facility_exists',
            'invalid_facility', 'invalid_facility_scale', 'capital_protected', 'no_target',
            'invalid_target_nation', 'same_nation_target', 'invalid_parameter',
            'no_launch_base', 'ruleset_mismatch',
        ], array_column(CommandFailureReason::cases(), 'value'));
    }

    public function test_capital_relocation_cost_is_bounded_by_the_approved_ruleset_range(): void
    {
        foreach ([999, 10_000] as $invalidCost) {
            $settings = config('hakoniwa.published_rulesets.roadmap-pr22-v1');
            $settings['capital_relocation_cost_money'] = $invalidCost;

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("Invalid Capital relocation cost {$invalidCost} was accepted.");
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_neutral_disguise_ownership_is_limited_to_disguised_water_representations(): void
    {
        foreach ([
            ['facility' => 'seabed_base', 'policy' => 'owner'],
            ['facility' => 'missile_base', 'policy' => 'neutral'],
        ] as $case) {
            $settings = config('hakoniwa.published_rulesets.roadmap-pr22-v1');
            $settings['facility_definitions'][$case['facility']]['disguise_ownership_policy'] = $case['policy'];

            try {
                app(RulesetAuthoringValidator::class)->validate($settings);
                $this->fail("Invalid {$case['facility']} disguise ownership policy was accepted.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString('disguise_ownership_policy', $exception->getMessage());
            }
        }
    }
}
