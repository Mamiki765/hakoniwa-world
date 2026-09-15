<?php

namespace Tests\Shared\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use DomainException;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    private const V26_CHECKSUM = '791ec7754ba794660fff3e27c1b287a481096055cf125d1080a6fe31c6f3d10b';

    public function test_normal_config_loads_and_validates_the_v26_identity_and_checksum(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];

        $this->assertSame(['hakoniwa-2s-plus-v26'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v26']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v26', $current['key']);
        $this->assertSame(26, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(self::V26_CHECKSUM, $this->checksum($current));
        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame('hakoniwa-2s-plus-v26', $summary['key']);
        $this->assertSame(26, $summary['version']);
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $this->assertSame(
            15,
            app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'))['domains'],
        );
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
        $this->assertSame(self::V26_CHECKSUM, $this->checksum($current));
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
