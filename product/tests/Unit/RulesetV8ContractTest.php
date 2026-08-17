<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use Tests\TestCase;

final class RulesetV8ContractTest extends TestCase
{
    private const SEVENTH_PRODUCTION_PAYLOAD_HASH = '6b9def1bb8921d233bd2080e5f89584cccf8a3a09184dcfac475ddb599f2a670';

    private const EIGHTH_PRODUCTION_PAYLOAD_HASH = 'fdceaec1e45bad64ceb177f880e513adeb5c3816c96858b00d8a988ad347990d';

    public function test_v8_preserves_v7_and_adds_only_the_source_audited_defense_contract(): void
    {
        $v7 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v7');
        $v8 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v8');

        $this->assertSame(
            self::SEVENTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v7, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $this->assertSame(
            self::EIGHTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v8, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $validated = app(RulesetAuthoringValidator::class)->validate($v8);
        $this->assertSame('hakoniwa-2s-plus-v8', $validated['key']);
        $this->assertSame(8, $validated['version']);
        $interception = $v8['military']['defense_interception'];
        unset($v8['military']['defense_interception']);
        $v8['key'] = 'hakoniwa-2s-plus-v7';
        $v8['version'] = 7;
        $this->assertSame($v7, $v8);
        $this->assertSame([
            'facility_key' => 'defense',
            'radius' => 2,
            'exclude_center' => true,
            'defense_target_cells' => 'exclude',
            'missile_keys' => ['missile', 'pp_missile', 'land_destruction_missile', 'spp_missile'],
            'facility_owner_scope' => 'any',
            'monster_occupied_cells' => 'include',
            'self_fired_missiles' => 'include',
            'overlap_resolution' => 'single_interception',
            'resolve_before' => 'secretary',
        ], $interception);
    }
}
