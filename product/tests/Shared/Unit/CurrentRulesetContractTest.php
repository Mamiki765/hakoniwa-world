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

    private const V29_CHECKSUM = '13407ee130b7c5ff661bc264cb237e6acea68afe36df49857d28498779100348';

    private const V30_CHECKSUM = 'f3226711a8dd2ae137395098a27901608f12d7a6647ece15f9c0b7ff285e3205';

    private const V31_CHECKSUM = '08fefb852613edbf92acce62c7c0eb6c82cea27d3ad464afdc2f54da23fd0557';

    private const V32_CHECKSUM = 'c5d5116703d6aaca319de923575c58aee15f16323d7aa2a246a497ea2e472bdc';

    public function test_normal_config_loads_and_validates_current_without_changing_the_historical_snapshots(): void
    {
        $normalConfig = require config_path('hakoniwa.php');
        $current = $normalConfig['ruleset'];

        $this->assertSame([$current['key']], array_keys($normalConfig['published_rulesets']));
        $this->assertSame($current, $normalConfig['published_rulesets'][$current['key']]);
        $this->assertSame($current['secretary'], $normalConfig['current_catalogs']['secretary']);
        $this->assertSame('hakoniwa-2s-plus-v33', $current['key']);
        $this->assertSame(33, $current['version']);
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $v31 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v31.php');
        $this->assertSame(self::V31_CHECKSUM, $this->checksum($v31));
        $v32 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v32.php');
        $this->assertSame(self::V32_CHECKSUM, $this->checksum($v32));
        $expected = $v32;
        $expected['key'] = $current['key'];
        $expected['version'] = $current['version'];
        $withoutAchievements = $current;
        unset($withoutAchievements['user_achievements']);
        $this->assertSame($expected, $withoutAchievements);
        $v30 = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v30.php');
        $this->assertSame(self::V30_CHECKSUM, $this->checksum($v30));
        $predecessor = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v29.php');
        $this->assertSame(self::V29_CHECKSUM, $this->checksum($predecessor));
        $prior = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v28.php');
        $this->assertSame(self::V28_CHECKSUM, $this->checksum($prior));
        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame($current['key'], $summary['key']);
        $this->assertSame($current['version'], $summary['version']);
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
        $this->assertNotSame($this->checksum($current), $this->checksum($withAdditionalEmptyContainer));
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
