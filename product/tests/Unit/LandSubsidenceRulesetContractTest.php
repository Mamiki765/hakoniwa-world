<?php

namespace Tests\Unit;

use App\Domain\Disaster\LandSubsidenceThresholdResolver;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\Nation;
use App\Models\RulesetVersion;
use Tests\TestCase;

class LandSubsidenceRulesetContractTest extends TestCase
{
    public function test_pr18_publishes_the_exact_land_subsidence_contract(): void
    {
        $settings = config('hakoniwa.published_rulesets.roadmap-pr18-v1');
        $validated = app(RulesetAuthoringValidator::class)->validate($settings);

        $this->assertSame('roadmap-pr18-v1', $validated['key']);
        $this->assertSame([
            'enabled' => true,
            'base_safe_land_cells' => 100,
            'probability' => ['numerator' => 2, 'denominator' => 100],
            'affected_shallow_result' => 'sea',
            'affected_coastal_land_result' => 'shallow',
            'mountain_immune' => true,
            'capital_damage_percentage' => 30,
            'out_of_bounds_is_water' => true,
            'stream_version' => 1,
        ], $settings['turn_processing']['disasters']['land_subsidence']);

        $ruleset = new RulesetVersion(['settings' => $settings]);
        $nation = new Nation(['world_id' => 1, 'nation_number' => 1, 'name' => '境界国', 'money' => 0, 'state' => 'active']);
        $this->assertSame(100, app(LandSubsidenceThresholdResolver::class)->resolve($ruleset, $nation));
    }

    public function test_two_percent_boundary_and_nation_streams_are_independent(): void
    {
        $nationId = 7;
        $label = TurnRandomStreamFactory::landSubsidenceTrigger($nationId, 1);
        $successSeed = $this->seedForDraw($label, 1);
        $failureSeed = $this->seedForDraw($label, 2);

        $this->assertLessThan(2, (new TurnRandomStreamFactory($successSeed))->stream($label)->integer(0, 99));
        $this->assertGreaterThanOrEqual(2, (new TurnRandomStreamFactory($failureSeed))->stream($label)->integer(0, 99));

        $seed = hash('sha256', 'land-subsidence-nation-isolation');
        $baseline = new TurnRandomStreamFactory($seed);
        $expected = $baseline->stream($label)->integer(0, 99);
        $withAnotherNation = new TurnRandomStreamFactory($seed);
        $withAnotherNation->stream(TurnRandomStreamFactory::landSubsidenceTrigger(99, 1))->integer(0, 99);
        $this->assertSame($expected, $withAnotherNation->stream($label)->integer(0, 99));
    }

    private function seedForDraw(string $label, int $expectedDraw): string
    {
        for ($attempt = 0; $attempt < 20_000; $attempt++) {
            $seed = hash('sha256', "land-subsidence-boundary:{$expectedDraw}:{$attempt}");
            if ((new TurnRandomStreamFactory($seed))->stream($label)->integer(0, 99) === $expectedDraw) {
                return $seed;
            }
        }

        $this->fail("Unable to find deterministic draw {$expectedDraw}.");
    }
}
