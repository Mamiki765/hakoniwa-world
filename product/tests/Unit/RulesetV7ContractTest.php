<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Secretary\SecretarySkillCatalog;
use Tests\TestCase;

final class RulesetV7ContractTest extends TestCase
{
    private const SIXTH_PRODUCTION_PAYLOAD_HASH = '5f3567fb352379727878f83cd1f66c36885cb4485c9153baaf315bab4140dcb2';

    private const SEVENTH_PRODUCTION_PAYLOAD_HASH = '6b9def1bb8921d233bd2080e5f89584cccf8a3a09184dcfac475ddb599f2a670';

    public function test_v7_preserves_v6_byte_for_byte_and_adds_only_the_exact_secretary_contract(): void
    {
        $v6 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v6');
        $v7 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v7');

        $this->assertSame(
            self::SIXTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v6, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $this->assertSame(
            self::SEVENTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v7, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $secretary = $v7['secretary'];
        unset($v7['secretary']);
        $v7['key'] = 'hakoniwa-2s-plus-v6';
        $v7['version'] = 6;
        $this->assertSame($v6, $v7);
        $this->assertSame(SecretarySkillCatalog::KEYS, array_keys($secretary['skills']));
        $this->assertSame(0, $secretary['skills']['agricultural_policy']['initial_level']);
        $this->assertFalse($secretary['skills']['agricultural_policy']['experience_source']['quantity_multiplier']);
        $this->assertSame(1, $secretary['skills']['final_defense_line']['initial_level']);
        $this->assertTrue($secretary['skills']['final_defense_line']['experience_source']['independent_from_interception_eligibility']);
        $validated = app(RulesetAuthoringValidator::class)->validate(
            config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v7'),
        );
        $this->assertSame('hakoniwa-2s-plus-v7', $validated['key']);
        $this->assertSame(7, $validated['version']);
    }
}
