<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use Tests\TestCase;

final class RulesetV9ContractTest extends TestCase
{
    private const EIGHTH_PRODUCTION_PAYLOAD_HASH = 'fdceaec1e45bad64ceb177f880e513adeb5c3816c96858b00d8a988ad347990d';

    private const NINTH_PRODUCTION_PAYLOAD_HASH = '78b55d34ce3148eb1e4b6dd97939468cee9df508d28f4084947a09cdd10fd883';

    public function test_v9_preserves_v8_and_adds_only_the_normal_monster_stage_contract(): void
    {
        $v8 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v8');
        $v9 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v9');

        $this->assertSame(
            self::EIGHTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v8, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $turnResolution = $v9['turn_resolution'];
        $this->assertSame(
            self::NINTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v9, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        unset($v9['turn_resolution']);
        $v9['key'] = 'hakoniwa-2s-plus-v8';
        $v9['version'] = 8;
        $this->assertSame($v8, $v9);
        $this->assertSame([
            'normal_monster_stage' => 'after_ordinary_surface_cell_events',
        ], $turnResolution);
        $validated = app(RulesetAuthoringValidator::class)->validate(
            config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v9'),
        );
        $this->assertSame('hakoniwa-2s-plus-v9', $validated['key']);
        $this->assertSame(9, $validated['version']);
    }
}
