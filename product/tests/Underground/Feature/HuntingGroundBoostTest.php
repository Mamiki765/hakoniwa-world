<?php

namespace Tests\Underground\Feature;

use App\Application\Underground\HuntingGroundBoost;
use App\Application\Underground\UndergroundAlphaV1PlayerCatalog;
use App\Application\Underground\UndergroundRuntimeService;
use App\Domain\Underground\Combat\UndergroundRandom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use ReflectionMethod;
use Tests\Concerns\CreatesBoostSchedules;
use Tests\TestCase;

final class HuntingGroundBoostTest extends TestCase
{
    use CreatesBoostSchedules;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_boundary_scope_and_acceptance_snapshot_are_fixed_without_stacking(): void
    {
        Carbon::setTestNow('2026-11-01T01:00:00+09:00');
        $row = $this->boostOccurrence();
        $row->update(['ends_at' => now()->addSecond()]);
        $this->boostOccurrence(); // Defensive non-stacking even if imported data contains overlapping rows.
        $effect = HuntingGroundBoost::capture(['kind' => 'hunting_ground']);
        $this->assertSame(2, $effect->multiplier());
        $this->assertSame(10_000, $effect->chance(6000));
        $this->assertSame(1, HuntingGroundBoost::capture(['kind' => 'vault'])->multiplier());
        $this->assertSame(1, HuntingGroundBoost::capture(['kind' => 'otherworld'])->multiplier());
        Carbon::setTestNow('2026-11-02T01:00:00+09:00');
        $this->assertSame(1, HuntingGroundBoost::capture(['kind' => 'hunting_ground'])->multiplier());
        $this->assertSame(2, $effect->multiplier());
        $this->assertCount(2, $effect->snapshot()['occurrence_ids']);
    }

    public function test_weighted_rare_probability_doubles_and_caps_without_changing_normal_distribution(): void
    {
        $runtime = app(UndergroundRuntimeService::class);
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $method = new ReflectionMethod($runtime, 'boostedWeightedEncounter');
        $entries = $catalog->explorationEncounters('shallow_caves');
        $rareKeys = array_column(array_filter($entries, fn (array $entry): bool => $entry['drop_profile'] === 'rare'), 'key');
        $baseWeight = array_sum(array_column(array_filter($entries, fn (array $entry): bool => $entry['drop_profile'] === 'rare'), 'weight'));
        $boost = new HuntingGroundBoost([1]);
        $upper = min(10_000, $baseWeight * 2);
        $this->assertContains($method->invoke($runtime, 'shallow_caves', 1, $boost), $rareKeys);
        $this->assertContains($method->invoke($runtime, 'shallow_caves', $upper, $boost), $rareKeys);
        $this->assertNotContains($method->invoke($runtime, 'shallow_caves', $upper + 1, $boost), $rareKeys);
        $this->assertNotContains($method->invoke($runtime, 'shallow_caves', 10_000, $boost), $rareKeys);
    }

    public function test_normal_key_chance_doubles_but_rare_quantity_and_gold_remain_unchanged(): void
    {
        $runtime = app(UndergroundRuntimeService::class);
        $catalog = app(UndergroundAlphaV1PlayerCatalog::class);
        $ground = $catalog->explorationHuntingGround('shining_kingdom');
        $entry = $catalog->explorationEncounters('shining_kingdom')[0];
        $method = new ReflectionMethod($runtime, 'explorationVictoryReward');
        $seed = 1;
        while (true) {
            $roll = (new UndergroundRandom($seed))->integer('reward:shining-kingdom-key', 1, 10_000);
            if ($roll > $ground['key_reward']['normal_chance_bps'] && $roll <= $ground['key_reward']['normal_chance_bps'] * 2) {
                break;
            }
            $seed++;
        }
        $plain = $method->invoke($runtime, $ground, $entry['key'], $entry, $seed, true);
        $boost = $method->invoke($runtime, $ground, $entry['key'], $entry, $seed, true, null, new HuntingGroundBoost([1]));
        $this->assertSame(0, $plain['keys']);
        $this->assertSame(1, $boost['keys']);
        $this->assertSame($plain['shards'], $boost['shards']);
        $rare = $catalog->explorationEncounter($ground['rare_encounter']['key'], 'shining_kingdom');
        $rarePlain = $method->invoke($runtime, $ground, $rare['key'], $rare, $seed, true);
        $rareBoost = $method->invoke($runtime, $ground, $rare['key'], $rare, $seed, true, null, new HuntingGroundBoost([1]));
        $this->assertSame($rarePlain, $rareBoost);
    }
}
