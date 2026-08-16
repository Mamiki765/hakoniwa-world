<?php

namespace Tests\Unit;

use App\Domain\Ruleset\RulesetAuthoringValidator;
use Tests\TestCase;

final class RulesetV6ContractTest extends TestCase
{
    private const SIXTH_PRODUCTION_PAYLOAD_HASH = '5f3567fb352379727878f83cd1f66c36885cb4485c9153baaf315bab4140dcb2';

    public function test_v6_is_a_new_immutable_snapshot_with_only_the_approved_contract_changes(): void
    {
        $v5 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v5');
        $v6 = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v6');

        $this->assertIsArray($v5);
        $this->assertIsArray($v6);
        $this->assertSame(
            self::SIXTH_PRODUCTION_PAYLOAD_HASH,
            hash('sha256', json_encode($v6, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)),
        );
        $this->assertSame('hakoniwa-2s-plus-v6', config('hakoniwa.ruleset.key'));
        $this->assertSame(6, config('hakoniwa.ruleset.version'));
        $this->assertSame('wasteland', $this->command($v5, 'logging')['result_terrain_key']);
        $this->assertSame('plain', $this->command($v6, 'logging')['result_terrain_key']);
        $this->assertArrayNotHasKey('owner_overbuild_effect', $this->command($v5, 'build_defense_facility')['metadata']);
        $this->assertSame(
            'defense_self_destruct',
            $this->command($v6, 'build_defense_facility')['metadata']['owner_overbuild_effect'],
        );
        $this->assertArrayNotHasKey('target_nation_id', $this->command($v5, 'build_monument')['metadata']['parameters'] ?? []);
        $this->assertSame(
            false,
            $this->command($v6, 'build_monument')['metadata']['parameters']['target_nation_id']['required'],
        );
        $this->assertSame([
            'facility_key' => 'defense',
            'ineffective_missile_keys' => ['spp_missile'],
        ], $v6['military']['defense_spp_resistance']);
        $this->assertArrayNotHasKey('defense_spp_resistance', $v5['military']);

        $validated = app(RulesetAuthoringValidator::class)->validate($v6);
        $this->assertSame('hakoniwa-2s-plus-v6', $validated['key']);
        $this->assertSame(6, $validated['version']);
    }

    /** @param array<string, mixed> $ruleset
     * @return array<string, mixed>
     */
    private function command(array $ruleset, string $key): array
    {
        foreach ($ruleset['command_definitions'] as $command) {
            if ($command['key'] === $key) {
                return $command;
            }
        }

        $this->fail("Missing command {$key}.");
    }
}
