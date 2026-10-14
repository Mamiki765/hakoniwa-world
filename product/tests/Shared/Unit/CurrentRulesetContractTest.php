<?php

namespace Tests\Shared\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    public function test_normal_config_loads_and_validates_the_immutable_current_snapshot(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];

        $this->assertSame([$current['key']], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets'][$current['key']]);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v37', $current['key']);
        $this->assertSame(37, $current['version']);
        // Authoring the next generation must retain the complete immutable predecessor.
        $this->assertSame('bbe3b65812bf337da0617577b38528a6583439bd9d672efe83cf67b249004308', $this->checksum(require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v36.php')));
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame($current['key'], $summary['key']);
        $this->assertSame($current['version'], $summary['version']);
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
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
