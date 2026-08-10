<?php

namespace Tests\Unit;

use App\Domain\Command\TerritoryInfluencePolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TerritoryInfluencePolicyTest extends TestCase
{
    #[DataProvider('targetCases')]
    public function test_target_allowlist(
        ?int $owner,
        string $terrain,
        ?string $facility,
        bool $monster,
        bool $core,
        bool $expected,
    ): void {
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v3.turn_processing.territory_influence');

        $this->assertSame($expected, app(TerritoryInfluencePolicy::class)->targetEligible(
            $settings,
            $owner,
            $terrain,
            $facility,
            $monster,
            $core,
            [1 => true, 2 => true],
        ));
    }

    /** @return iterable<string, array{int|null, string, string|null, bool, bool, bool}> */
    public static function targetCases(): iterable
    {
        yield 'forest' => [1, 'forest', null, false, false, true];
        yield 'mountain' => [1, 'mountain', null, false, false, true];
        yield 'settlement' => [1, 'plain', 'city', false, false, true];
        yield 'farm' => [1, 'plain', 'farm', false, false, true];
        yield 'decoy is outside the approved target allowlist' => [1, 'plain', 'decoy', false, false, false];
        yield 'capital is protected rather than treated as a settlement target' => [1, 'plain', 'capital', false, false, false];
        yield 'neutral' => [null, 'forest', null, false, false, false];
        yield 'inactive owner' => [3, 'forest', null, false, false, false];
        yield 'wasteland' => [1, 'wasteland', null, false, false, false];
        yield 'scorched' => [1, 'scorched', null, false, false, false];
        yield 'plain without settlement' => [1, 'plain', null, false, false, false];
        yield 'monument' => [1, 'plain', 'monument', false, false, false];
        yield 'seabed base' => [1, 'sea', 'seabed_base', false, false, false];
        yield 'monster' => [1, 'forest', null, true, false, false];
        yield 'capital core' => [1, 'forest', null, false, true, false];
    }

    public function test_source_uses_a_separate_denylist_and_allows_monument(): void
    {
        $policy = app(TerritoryInfluencePolicy::class);
        $settings = config('hakoniwa.published_rulesets.hakoniwa-2s-plus-v3.turn_processing.territory_influence');
        $active = [1 => true, 2 => true];

        $this->assertTrue($policy->sourceEligible($settings, 2, 1, 'plain', 'monument', false, $active));
        $this->assertTrue($policy->sourceEligible($settings, 2, 1, 'plain', null, false, $active));
        $this->assertFalse($policy->sourceEligible($settings, 2, 1, 'wasteland', null, false, $active));
        $this->assertFalse($policy->sourceEligible($settings, 2, 1, 'plain', 'seabed_base', false, $active));
        $this->assertFalse($policy->sourceEligible($settings, 2, 1, 'plain', null, true, $active));
        $this->assertFalse($policy->sourceEligible($settings, 1, 1, 'plain', null, false, $active));
        $this->assertFalse($policy->sourceEligible($settings, 3, 1, 'plain', null, false, $active));
    }
}
