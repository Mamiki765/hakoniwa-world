<?php

namespace Tests\Unit;

use App\Models\RulesetVersion;
use PHPUnit\Framework\TestCase;

final class RulesetVersionSettingsTest extends TestCase
{
    public function test_settings_round_trip_as_json(): void
    {
        $ruleset = new RulesetVersion(['settings' => ['turn_processing' => ['fire' => ['numerator' => 1]]]]);

        $this->assertSame('{"turn_processing":{"fire":{"numerator":1}}}', $ruleset->getAttributes()['settings']);
        $this->assertSame(['turn_processing' => ['fire' => ['numerator' => 1]]], $ruleset->settings);
        $this->assertSame($ruleset->settings, $ruleset->settings);
    }

    public function test_memoized_settings_follow_assignment_and_raw_reload(): void
    {
        $ruleset = new RulesetVersion(['settings' => ['a' => 1]]);
        $this->assertSame(['a' => 1], $ruleset->settings);

        $ruleset->settings = ['a' => 2];
        $this->assertSame(['a' => 2], $ruleset->settings);

        $ruleset->setRawAttributes(['settings' => '{"a": 3}'], true);
        $this->assertSame(['a' => 3], $ruleset->settings);

        $ruleset->settings = null;
        $this->assertNull($ruleset->settings);
    }
}
