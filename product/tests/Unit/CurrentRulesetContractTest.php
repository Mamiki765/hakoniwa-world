<?php

namespace Tests\Unit;

use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use ReflectionMethod;
use Tests\TestCase;

final class CurrentRulesetContractTest extends TestCase
{
    private const V16_CHECKSUM = '331d2d0e9456fa87a37ea0765313ecd9828b5d4912fa2b6637620806df80487d';

    /** @var array{domains: int, leaves: int, behavior: int, data: int, flavor: int} */
    private const COVERAGE = [
        'domains' => 10,
        'leaves' => 1841,
        'behavior' => 1210,
        'data' => 455,
        'flavor' => 176,
    ];

    public function test_normal_config_loads_only_the_explicit_current_v16_contract(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];
        $source = file_get_contents(config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v16.php'));

        $this->assertIsString($source);
        $this->assertSame(10, substr_count($source, "require __DIR__.'/current/"));
        $this->assertLessThan(100, substr_count($source, "\n"));
        $this->assertSame(['hakoniwa-2s-plus-v16'], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets']['hakoniwa-2s-plus-v16']);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v16', $current['key']);
        $this->assertSame(16, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $this->assertSame(self::V16_CHECKSUM, $this->checksum($current));

        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame('hakoniwa-2s-plus-v16', $summary['key']);
        $this->assertSame(16, $summary['version']);
        $this->assertSame(count($current['command_definitions']), $summary['commands']);
        $this->assertSame(count($current['production_definitions']), $summary['production']);
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $this->assertSame(
            self::COVERAGE,
            app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset')),
        );
    }

    public function test_absolute_selector_wildcard_rejects_string_and_numeric_associative_keys(): void
    {
        $leaves = new ReflectionMethod(CurrentRulesetAuthoringInspector::class, 'leaves');
        $matches = new ReflectionMethod(CurrentRulesetAuthoringInspector::class, 'matches');
        $inspector = app(CurrentRulesetAuthoringInspector::class);
        $listLeaf = $leaves->invoke($inspector, ['definitions' => [['key' => 'oil']]])['/definitions/0/key'];
        $numericMapLeaf = $leaves->invoke($inspector, ['definitions' => [1 => ['key' => 'oil']]])['/definitions/1/key'];

        $this->assertTrue($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/0/key',
            'key',
            $listLeaf['list_index_segments'],
        ));
        $this->assertFalse($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/1/key',
            'key',
            $numericMapLeaf['list_index_segments'],
        ));
        $this->assertFalse($matches->invoke(
            $inspector,
            '/definitions/*/key',
            '/definitions/oil/key',
            'key',
            [],
        ));
    }

    public function test_scalar_classification_does_not_replace_the_container_checksum_contract(): void
    {
        $current = config('hakoniwa.ruleset');
        $withAdditionalEmptyContainer = $current;
        $withAdditionalEmptyContainer['classification_boundary_probe'] = [];

        $this->assertSame(
            self::COVERAGE,
            app(CurrentRulesetAuthoringInspector::class)->inspect($withAdditionalEmptyContainer),
        );
        $this->assertSame(self::V16_CHECKSUM, $this->checksum($current));
        $this->assertNotSame(self::V16_CHECKSUM, $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
