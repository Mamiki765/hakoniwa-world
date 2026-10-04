<?php

namespace Tests\Feature;

use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Application\OilDiscoveryExperienceBackfill;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\TestCase;

final class OilDiscoveryExperienceBackfillTest extends TestCase
{
    use UsesReusableSurfaceWorld;

    public function test_preview_and_one_time_apply_use_retained_success_evidence_and_all_supported_owner_sources(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $active = app(NationCreationService::class)->create($owner, $world, '油田現役', '島主');
        $oldOwner = User::factory()->create();
        $old = app(NationCreationService::class)->create($oldOwner, $world, '油田破棄', '島主');
        app(NationAbandonmentService::class)->abandon($oldOwner, $old, $old->name);
        $actorOwner = User::factory()->create();
        $actorNation = app(NationCreationService::class)->create($actorOwner, $world, '油田旧監査', '島主');
        app(NationAbandonmentService::class)->abandon($actorOwner, $actorNation, $actorNation->name);
        DB::table('nation_creation_requests')->where('nation_id', $actorNation->id)->delete();
        $run = $this->historicalRun($world, 29, 1);
        foreach ([$active, $old, $actorNation] as $index => $nation) {
            $this->discovery($world, $nation, $run, $index + 1);
        }
        $this->discovery($world, $active, $run, 4, ['found' => false]);
        $this->discovery($world, $active, $this->historicalRun($world, 29, 1, true), 5);
        $this->discovery($world, $active, $this->historicalRun($world, 30, 2), 6);
        DB::table('oil_discovery_backfills')->insert([
            'world_id' => $world->id, 'cutoff_turn' => 2,
            'cutoff_audit_id' => DB::table('audit_events')->max('id'),
        ]);
        // A later discovery and its already-earned live XP cannot enter the frozen batch.
        $this->discovery($world, $active, $this->historicalRun($world, 30, 3), 7);
        $skill = $owner->secretary()->sole()->skills()->where('skill_key', 'oil_development')->sole();
        $skill->update(['level' => 1, 'experience' => 2]);
        $service = app(OilDiscoveryExperienceBackfill::class);
        $preview = $service->preview($world);
        $this->assertCount(3, $preview['candidates']);
        $this->assertCount(2, $preview['excluded']);
        $this->assertSame([], $preview['skipped']);
        $this->assertSame([1, 2], [$skill->fresh()->level, $skill->fresh()->experience]);
        $this->assertNull(DB::table('oil_discovery_backfills')->where('world_id', $world->id)->value('applied_at'));
        $this->artisan('hakoniwa:backfill-oil-discovery', ['--world' => $world->id, '--dry-run' => true])->assertSuccessful();
        $this->assertTrue($service->apply($world)['applied']);
        $this->assertSame([1, 3], [$skill->fresh()->level, $skill->fresh()->experience]);
        foreach ([$oldOwner, $actorOwner] as $user) {
            $row = $user->secretary()->sole()->skills()->where('skill_key', 'oil_development')->sole();
            $this->assertSame([1, 0], [$row->level, $row->experience]);
        }
        $this->assertFalse($service->apply($world)['applied']);
        $this->assertSame([1, 3], [$skill->fresh()->level, $skill->fresh()->experience]);
    }

    public function test_missing_identity_and_ambiguous_abandoned_owner_remain_unapplied(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '帰属不明油田', '島主');
        $run = $this->historicalRun($world, 29, 1);
        $this->discovery($world, $nation, $run, 1, ['turn_run_id' => null]);
        app(NationAbandonmentService::class)->abandon($owner, $nation, $nation->name);
        $other = User::factory()->create();
        DB::table('audit_events')->insert([
            'world_id' => $world->id, 'nation_id' => $nation->id, 'turn' => 1,
            'actor_user_id' => $other->id, 'event_type' => 'nation.created',
            'visibility' => 'admin', 'severity' => 'info', 'metadata' => '{}', 'occurred_at' => now(), 'created_at' => now(),
        ]);
        $this->discovery($world, $nation, $run, 2);
        DB::table('oil_discovery_backfills')->insert([
            'world_id' => $world->id, 'cutoff_turn' => 1, 'cutoff_audit_id' => DB::table('audit_events')->max('id'),
        ]);
        $service = app(OilDiscoveryExperienceBackfill::class);
        $preview = $service->preview($world);
        $this->assertSame([], $preview['awards']);
        $this->assertCount(2, $preview['skipped']);
        try {
            $service->apply($world);
            $this->fail('Unresolved attribution must not invent XP or close the batch.');
        } catch (RuntimeException) {
            $this->assertNull(DB::table('oil_discovery_backfills')->where('world_id', $world->id)->value('applied_at'));
            $this->assertSame(0, $owner->secretary()->sole()->skills()->where('skill_key', 'oil_development')->value('level'));
        }
    }

    private function historicalRun(World $world, int $version, int $turn, bool $dry = false): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id, 'target_turn' => $turn,
            'ruleset_version_id' => RulesetVersion::query()->where('version', $version)->sole()->id,
            'random_seed' => hash('sha256', "backfill {$turn}"), 'source' => 'manual', 'is_dry_run' => $dry,
            'status' => $dry ? TurnRun::STATUS_DRY_RUN : TurnRun::STATUS_COMPLETED,
            'attempt_count' => 1, 'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function discovery(World $world, Nation $nation, TurnRun $run, int $queueId, array $overrides = []): void
    {
        DB::table('audit_events')->insert([
            'world_id' => $world->id, 'nation_id' => $nation->id, 'turn' => $run->target_turn,
            'event_type' => 'command.seabed_oil_search', 'visibility' => 'nation', 'severity' => 'info',
            'metadata' => json_encode([
                'nation_id' => $nation->id, 'queue_item_id' => $queueId,
                'turn_run_id' => $run->id, 'found' => true, ...$overrides,
            ], JSON_THROW_ON_ERROR), 'occurred_at' => now(), 'created_at' => now(),
        ]);
    }
}
