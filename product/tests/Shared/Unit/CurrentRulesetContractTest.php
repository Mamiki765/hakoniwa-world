<?php

namespace Tests\Shared\Unit;

use App\Application\CommandQuantitySemantics;
use App\Domain\Ruleset\CurrentRulesetAuthoringInspector;
use App\Domain\Ruleset\RulesetAuthoringValidator;
use App\Models\CommandDefinition;
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
        // Production-used snapshots stay fixed independently of the current selection.
        $this->assertSame('f23a9c641323ae7067e8d898a00f22c5eaacecc229a8a085f71bd49f3eb6ac94', $this->checksum(require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v37.php')));
        $this->assertSame('7d4cd4232fe4ffcfe082db7ee5ab5e8c05f45538c5ae8ccc0abb2bf0e9fcce64', $this->checksum(require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v38.php')));
        $this->assertArrayNotHasKey('behavior', $current);
        $this->assertArrayNotHasKey('data', $current);
        $this->assertArrayNotHasKey('flavor', $current);
        $summary = app(RulesetAuthoringValidator::class)->validate($current);
        $this->assertSame($current['key'], $summary['key']);
        $this->assertSame($current['version'], $summary['version']);
    }

    public function test_attraction_repetition_is_authored_only_in_the_release_draft_not_the_frozen_predecessor(): void
    {
        $previous = require config_path('hakoniwa/rulesets/hakoniwa-2s-plus-v38.php');
        $semantics = app(CommandQuantitySemantics::class);
        $old = new CommandDefinition(collect($previous['command_definitions'])->firstWhere('key', 'attraction'));
        $current = new CommandDefinition(collect(config('hakoniwa.ruleset.command_definitions'))->firstWhere('key', 'attraction'));
        $this->assertSame(CommandQuantitySemantics::UNUSED, $semantics->for($old));
        $this->assertSame(CommandQuantitySemantics::ORDINARY, $semantics->for($current));
        $this->assertFalse($semantics->isRepeatedFacilityBuild($current->key));
    }

    public function test_current_domain_authoring_classifies_every_scalar_leaf_exactly_once(): void
    {
        $coverage = app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'));
        $this->assertSame($coverage['leaves'], $coverage['behavior'] + $coverage['data'] + $coverage['flavor']);

        // Omitting a fragment while preparing the next release must fail coverage.
        config(['hakoniwa.ruleset_authoring_domains' => array_slice(config('hakoniwa.ruleset_authoring_domains'), 1)]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('domain leaves do not exactly match the published payload');
        app(CurrentRulesetAuthoringInspector::class)->inspect(config('hakoniwa.ruleset'));
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
