<?php

namespace Tests\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemSynthesisContract;
use Tests\TestCase;

final class SecretaryItemSynthesisContractTest extends TestCase
{
    public function test_draft_is_validated_but_never_selected_by_normal_config(): void
    {
        $current = config('hakoniwa.ruleset');
        $this->assertNull(app(SecretaryItemSynthesisContract::class)->recipe($current));
        $draft = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        config(['hakoniwa.ruleset' => $draft]);
        app(RulesetAuthoringValidator::class)->validate($draft);
        app(CurrentRulesetAuthoringInspector::class)->inspect($draft);
        $effect = app(SecretaryItemGameplayContract::class)->resolvedEffects($draft, 'succubus_emblem', 1);
        $this->assertSame('population_growth_percent', $effect[0]['type']);
        $this->assertSame(100, $effect[0]['parameters']['percent']);
        $this->assertTrue($draft['secretary']['items']['succubus_emblem']['gacha_exception']);
        $this->assertSame($current['secretary']['ticket_gacha'], $draft['secretary']['ticket_gacha']);
    }
}
