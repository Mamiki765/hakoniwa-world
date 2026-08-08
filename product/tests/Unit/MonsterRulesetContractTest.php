<?php

namespace Tests\Unit;

use App\Domain\Monster\MonsterHardening;
use App\Domain\Monster\MonsterNaturalSpawnPolicy;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MonsterDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonsterRulesetContractTest extends TestCase
{
    public function test_production_ruleset_publishes_the_exact_audited_eight_monster_catalog(): void
    {
        $settings = config('hakoniwa.published_rulesets')['hakoniwa-2s-plus-v1'];
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('hakoniwa-2s-plus-v1', $validated['key']);
        $this->assertSame(8, $validated['monsters']);
        $this->assertSame([
            'mecha_inora' => [0, 'monster7.gif', 2, 0, 'none', 1, null, 0, 5],
            'inora' => [1, 'monster0.gif', 1, 1, 'none', 1, 1, 400, 5],
            'sanjira' => [2, 'monster5.gif', 1, 1, 'harden_odd', 1, 1, 500, 7],
            'red_inora' => [3, 'monster1.gif', 3, 1, 'none', 1, 2, 1_000, 12],
            'dark_inora' => [4, 'monster2.gif', 2, 1, 'move_2', 2, 2, 800, 15],
            'inora_ghost' => [5, 'monster8.gif', 1, 0, 'move_9999', 9_999, 2, 300, 10],
            'whale' => [6, 'monster6.gif', 4, 1, 'harden_even', 1, 3, 1_500, 20],
            'king_inora' => [7, 'monster3.gif', 5, 1, 'none', 1, 3, 2_000, 30],
        ], collect($settings['monster_definitions'])->mapWithKeys(static fn (array $definition): array => [
            $definition['key'] => [
                $definition['source_metadata']['kind'],
                $definition['source_metadata']['filename'],
                $definition['base_hp'],
                $definition['hp_variation'],
                $definition['skill_key'],
                $definition['movement_limit'],
                $definition['natural_spawn_tier'],
                $definition['wreckage_value_money'],
                $definition['missile_base_experience'],
            ],
        ])->all());
    }

    public function test_normal_assets_are_unique_and_monster4_is_hardening_only(): void
    {
        $definitions = collect(config('hakoniwa.ruleset.monster_definitions'));

        $this->assertCount(8, $definitions->pluck('asset_key')->unique());
        $this->assertNotContains('monster4.gif', $definitions->pluck('source_metadata.filename')->all());
        $this->assertSame(
            ['sanjira', 'whale'],
            $definitions->filter(static fn (array $definition): bool => $definition['hardened_asset_key'] !== null)
                ->pluck('key')->values()->all(),
        );
        $this->assertSame(
            ['hakoniwa_original.monster.hardened'],
            $definitions->pluck('hardened_asset_key')->filter()->unique()->values()->all(),
        );
    }

    public function test_spawn_movement_reward_and_terrain_event_contracts_are_exact(): void
    {
        $system = config('hakoniwa.ruleset.monster_system');

        $this->assertSame(['numerator' => 2, 'denominator' => 10_000], $system['natural_spawn']['probability_per_land_cell']);
        $this->assertSame(10_000, $system['natural_spawn']['maximum_probability_numerator']);
        $this->assertSame('active', $system['natural_spawn']['eligible_nation_state']);
        $this->assertSame(100_000, $system['natural_spawn']['minimum_population']);
        $this->assertSame([
            [100_000, ['inora', 'sanjira']],
            [250_000, ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost']],
            [400_000, ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost', 'whale', 'king_inora']],
        ], collect($system['natural_spawn']['population_tiers'])->map(static fn (array $tier): array => [
            $tier['minimum_population'], $tier['monster_keys'],
        ])->all());
        $this->assertSame(3, $system['movement']['candidate_attempts_per_action']);
        $this->assertSame(['sea', 'shallow', 'mountain'], $system['movement']['blocked_terrain_keys']);
        $this->assertContains('capital', $system['movement']['blocked_facility_keys']);
        $this->assertContains('mine', $system['movement']['blocked_facility_keys']);
        $this->assertSame('defense', $system['movement']['defense_facility_key']);
        $this->assertSame('floor_half', $system['reward']['killer_money_share']);
        $meatSaleRate = config('hakoniwa.ruleset.inventory_sale_rates.monster_meat');
        $this->assertSame(0, $meatSaleRate['inventory_units'] % $meatSaleRate['money_units']);
        $this->assertSame(
            intdiv($meatSaleRate['inventory_units'], $meatSaleRate['money_units']),
            $system['reward']['food_tons_per_money_unit'],
        );
        $this->assertSame(500, $system['reward']['food_tons_per_money_unit']);
        $this->assertSame(200, $system['reward']['missile_base_experience_maximum']);
        $this->assertSame([
            'scope' => 'nation_monster_definition',
            'increment_on_attributed_final_blow' => true,
            'authoritative_for_final_blow_count' => true,
            'authoritative_for_kill_marks' => true,
            'maximum_species_rows_per_nation' => 8,
        ], $system['kill_stats']);
        $this->assertSame(['earthquake', 'tsunami', 'typhoon'], $system['terrain_events']['preserve_occupancy']);
        $this->assertSame(
            ['meteor_shower', 'huge_meteor', 'eruption', 'land_subsidence', 'defense_self_destruct', 'terrain_destruction_missile'],
            $system['terrain_events']['remove_without_rewards'],
        );
    }

    public function test_monster_meat_reward_requires_an_exact_versioned_sale_rate_conversion(): void
    {
        $settings = config('hakoniwa.ruleset');
        $settings['inventory_sale_rates']['monster_meat']['money_units'] = 3;

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('must convert one money unit to exact integer tons');

        app(RulesetAuthoringValidator::class)->validate($settings);
    }

    #[DataProvider('spawnProbabilityProvider')]
    public function test_nation_spawn_probability_uses_exact_integer_boundaries(
        int $ownedLandCells,
        int $expectedNumerator,
    ): void {
        $settings = config('hakoniwa.ruleset.monster_system.natural_spawn');

        $this->assertSame(
            ['numerator' => $expectedNumerator, 'denominator' => 10_000],
            app(MonsterNaturalSpawnPolicy::class)->probabilityForLand($settings, $ownedLandCells),
        );
    }

    /** @return array<string, array{int, int}> */
    public static function spawnProbabilityProvider(): array
    {
        return [
            'zero land' => [0, 0],
            'one cell is 0.02 percent' => [1, 2],
            'fifty cells is one percent' => [50, 100],
            'one hundred cells is two percent' => [100, 200],
            'one hundred one cells is 2.02 percent' => [101, 202],
            'five hundred cells is ten percent' => [500, 1_000],
            'numerator cap' => [5_000, 10_000],
            'above numerator cap' => [60_000, 10_000],
        ];
    }

    #[DataProvider('populationPoolProvider')]
    public function test_population_pool_uses_exact_boundaries(int $population, array $expected): void
    {
        $settings = config('hakoniwa.ruleset.monster_system.natural_spawn');
        $pool = app(MonsterNaturalSpawnPolicy::class)->poolForPopulation($settings, $population);

        $this->assertSame($expected, $pool);
        $this->assertNotContains('mecha_inora', $pool);
    }

    /** @return array<string, array{int, list<string>}> */
    public static function populationPoolProvider(): array
    {
        $level1 = ['inora', 'sanjira'];
        $level2 = ['inora', 'sanjira', 'red_inora', 'dark_inora', 'inora_ghost'];
        $level3 = [...$level2, 'whale', 'king_inora'];

        return [
            'below minimum' => [99_999, []],
            'level one minimum' => [100_000, $level1],
            'level one maximum' => [249_999, $level1],
            'level two minimum' => [250_000, $level2],
            'level two maximum' => [399_999, $level2],
            'level three minimum' => [400_000, $level3],
        ];
    }

    #[DataProvider('hardeningProvider')]
    public function test_hardening_uses_target_turn_parity(
        string $skill,
        int $turn,
        bool $expected,
    ): void {
        $definition = new MonsterDefinition(['skill_key' => $skill]);

        $this->assertSame($expected, app(MonsterHardening::class)->isHardened($definition, $turn));
    }

    /** @return array<string, array{string, int, bool}> */
    public static function hardeningProvider(): array
    {
        return [
            'sanjira odd' => ['harden_odd', 3, true],
            'sanjira even' => ['harden_odd', 4, false],
            'whale even' => ['harden_even', 4, true],
            'whale odd' => ['harden_even', 5, false],
            'normal never' => ['none', 3, false],
        ];
    }

    public function test_monster_random_streams_are_independent_and_stable(): void
    {
        $this->assertSame('process_cells:monster:7:movement:v1', TurnRandomStreamFactory::monsterMovement(7, 1));
        $this->assertSame(
            'global_disasters:monster_spawn:nation:3:trigger:v1',
            TurnRandomStreamFactory::monsterSpawn(3, 'trigger', 1),
        );
        $this->assertNotSame(
            TurnRandomStreamFactory::monsterSpawn(3, 'candidate', 1),
            TurnRandomStreamFactory::monsterSpawn(3, 'type', 1),
        );
        $this->assertNotSame(
            TurnRandomStreamFactory::monsterSpawn(3, 'trigger', 1),
            TurnRandomStreamFactory::monsterSpawn(4, 'trigger', 1),
        );

        $seed = hash('sha256', 'Nation stream insertion stability');
        $baseline = (new TurnRandomStreamFactory($seed))->stream(
            TurnRandomStreamFactory::monsterSpawn(3, 'trigger', 1),
        )->integer(0, 9_999);
        $withAnotherNation = new TurnRandomStreamFactory($seed);
        $withAnotherNation->stream(
            TurnRandomStreamFactory::monsterSpawn(99, 'trigger', 1),
        )->integer(0, 9_999);
        $this->assertSame($baseline, $withAnotherNation->stream(
            TurnRandomStreamFactory::monsterSpawn(3, 'trigger', 1),
        )->integer(0, 9_999));
    }
}
