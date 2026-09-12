<?php

namespace Tests\Unit;

use App\Domain\Command\SettlementOverbuildPolicy;
use PHPUnit\Framework\TestCase;

final class SettlementOverbuildPolicyTest extends TestCase
{
    public function test_central_facility_settlement_overbuild_is_enabled_only_by_versioned_command_metadata(): void
    {
        $this->assertFalse(SettlementOverbuildPolicy::allows('build_central_bank', 'village'));
        $this->assertTrue(SettlementOverbuildPolicy::allows(
            'build_central_bank',
            'village',
            ['settlement_overbuild' => true],
        ));
        $this->assertFalse(SettlementOverbuildPolicy::allows(
            'build_central_bank',
            'farm',
            ['settlement_overbuild' => true],
        ));
        $this->assertTrue(SettlementOverbuildPolicy::protectsCapital(
            'build_central_bank',
            'capital',
            ['settlement_overbuild' => true],
        ));
    }
}
