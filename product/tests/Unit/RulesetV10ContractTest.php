<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use Tests\TestCase;

final class RulesetV10ContractTest extends TestCase
{
    private const CHECKSUM = '6a0f5354f8894081bacdb8eaaba328d3e4ee80a2c4136819b16cfa924f485dc1';

    public function test_v10_preserves_v9_and_adds_only_the_food_overflow_stage_contract(): void
    {
        $v9 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v9');
        $v10 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v10');
        $this->assertIsArray($v9);
        $this->assertIsArray($v10);

        $stage = $v10['turn_processing']['food']['production_overflow_resolution_stage'];
        unset($v10['turn_processing']['food']['production_overflow_resolution_stage']);
        $v10['key'] = 'hakoniwa-2s-plus-v9';
        $v10['version'] = 9;
        $this->assertSame($v9, $v10);
        $this->assertSame('after_population_nutrition_consumption', $stage);
        $validated = app(RulesetAuthoringValidator::class)->validate(
            config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v10'),
        );
        $this->assertSame('hakoniwa-2s-plus-v10', $validated['key']);
        $this->assertSame(10, $validated['version']);
        $this->assertSame(
            self::CHECKSUM,
            hash('sha256', json_encode(
                config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v10'),
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
            )),
        );
    }
}
