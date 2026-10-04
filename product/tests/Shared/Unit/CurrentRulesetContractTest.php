<?php

namespace Tests\Shared\Unit;

use App\Application\RulesetPublisher;
use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    private const V28_CHECKSUM = '708aebb3dbf392e64a5349528240268d18b78a45b005a702f29e4ce155fd5bc8';

    private const V29_CHECKSUM = 'b1377c85878f7895cb5093a7cb9374dd962b808a39ee43efbeae5228816b4bdf';

    public function test_normal_config_loads_and_validates_v29_without_changing_the_v28_snapshot(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];

        $this->assertSame(['hakoniwa-2s-plus-v29'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v29']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v29', $current['key']);
        $this->assertSame(29, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(self::V29_CHECKSUM, $this->checksum($current));
        $prior = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        $this->assertSame(self::V28_CHECKSUM, $this->checksum($prior));
        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame('hakoniwa-2s-plus-v29', $summary['key']);
        $this->assertSame(29, $summary['version']);
        $prior['power_economy']['pizzeria_maintenance'] = 0;
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('exact immutable v28 snapshot');
        app(RulesetPublisher::class)->publishPowerIntroduction($prior);
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $coverage = app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'));
        $this->assertSame($coverage['leaves'], $coverage['behavior'] + $coverage['data'] + $coverage['flavor']);
    }

    public function test_public_inspector_rejects_string_and_numeric_associative_collections(): void
    {
        $inspector = app(CurrentRulesetAuthoringInspector::class);
        $current = config('hakoniwa.ruleset');
        $variants = [
            'string map' => array_column($current['command_definitions'], null, 'key'),
            'numeric associative map' => array_combine(
                range(1, count($current['command_definitions'])),
                $current['command_definitions'],
            ),
        ];

        foreach ($variants as $label => $definitions) {
            $invalid = $current;
            $invalid['command_definitions'] = $definitions;
            try {
                $inspector->inspect($invalid);
                $this->fail("Public inspector accepted a {$label} where the authored contract requires a list.");
            } catch (DomainException $exception) {
                $this->assertStringContainsString(
                    'domain leaves do not exactly match the published payload',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function test_scalar_classification_does_not_replace_the_container_checksum_contract(): void
    {
        $current = config('hakoniwa.ruleset');
        $withAdditionalEmptyContainer = $current;
        $withAdditionalEmptyContainer['classification_boundary_probe'] = [];

        $this->assertSame(
            app(CurrentRulesetAuthoringInspector::class)->inspect($current),
            app(CurrentRulesetAuthoringInspector::class)->inspect($withAdditionalEmptyContainer),
        );
        $this->assertSame(self::V29_CHECKSUM, $this->checksum($current));
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
