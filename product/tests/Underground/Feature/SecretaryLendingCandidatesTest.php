<?php

namespace Tests\Underground\Feature;

use App\Application\SecretaryLendingService;
use App\Application\Underground\UndergroundProfileService;
use App\Models\Secretary;
use App\Models\SecretaryLendingSetting;
use App\Models\UndergroundOwnedEquipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SecretaryLendingCandidatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_does_not_read_candidates_and_explicit_listing_only_reads_equipped_items(): void
    {
        $viewer = User::factory()->create();
        $self = Secretary::query()->create(['user_id' => $viewer->id, 'name' => '開始者', 'named_at' => now()]);
        $owner = User::factory()->create();
        $owner->forceFill(['visitor_code' => 'LENDTEST'])->save();
        $borrowed = Secretary::query()->create(['user_id' => $owner->id, 'name' => '貸出秘書', 'named_at' => now()]);
        $profile = app(UndergroundProfileService::class)->ensureForSecretary($borrowed);
        $profile->update([
            'growth_path_key' => 'martial_red', 'underground_contract_completed_at' => now(),
            'growth_path_identity' => 'secretary-underground-growth-alpha-v1', 'growth_path_selected_at' => now(),
            'skill_points_total' => 20, 'skill_points_unspent' => 20,
            'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
        ]);
        $service = app(SecretaryLendingService::class);
        $service->update($owner, true, true);
        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([], $service->state($viewer, $self)['candidates']);
        foreach (DB::getQueryLog() as $query) {
            $this->assertStringNotContainsString('underground_owned_equipment', $query['query']);
        }
        DB::flushQueryLog();
        $candidates = $service->publicCandidates($viewer, $self->id);
        $this->assertSame([$borrowed->id], array_column($candidates, 'secretary_id'));
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'underground_owned_equipment')) {
                $this->assertStringContainsString('"equipped_slot" is not null', $query['query']);
            }
        }
        DB::disableQueryLog();
    }

    public function test_projection_failure_keeps_a_monotonic_cursor_to_later_candidates(): void
    {
        $viewer = User::factory()->create();
        $self = Secretary::query()->create(['user_id' => $viewer->id, 'name' => '開始者', 'named_at' => now()]);
        $service = app(SecretaryLendingService::class);
        $secretaryIds = [];
        $corruptSecretaryId = null;

        for ($index = 1; $index <= 21; $index++) {
            $owner = User::factory()->create();
            $owner->forceFill(['visitor_code' => sprintf('LEND%04d', $index)])->save();
            $secretary = Secretary::query()->create([
                'user_id' => $owner->id,
                'name' => '貸出秘書'.$index,
                'named_at' => now(),
            ]);
            $profile = app(UndergroundProfileService::class)->ensureForSecretary($secretary);
            $profile->update([
                'growth_path_key' => 'martial_red',
                'underground_contract_completed_at' => now(),
                'growth_path_identity' => 'secretary-underground-growth-alpha-v1',
                'growth_path_selected_at' => now(),
                'skill_points_total' => 20,
                'skill_points_unspent' => 20,
                'skill_tree_identity' => 'secretary-underground-skill-tree-alpha-v1',
            ]);
            SecretaryLendingSetting::query()->create([
                'secretary_id' => $secretary->id,
                'is_public' => true,
                'is_available' => true,
            ]);
            $secretaryIds[] = $secretary->id;

            if ($index === 10) {
                UndergroundOwnedEquipment::query()->create([
                    'underground_profile_id' => $profile->id,
                    'definition_key' => 'projection_failure_fixture',
                    'catalog_identity' => 'unsupported-pagination-fixture',
                    'equipped_slot' => 'weapon',
                    'grant_key' => 'pagination-projection-failure',
                    'instance_kind' => 'fixed',
                    'acquired_at' => now(),
                ]);
                $corruptSecretaryId = $secretary->id;
            }
        }

        $first = $service->publicCandidatePage($viewer, $self->id);
        $this->assertCount(19, $first['candidates']);
        $this->assertTrue($first['has_more']);
        $this->assertSame($secretaryIds[19], $first['next_after_id']);
        $this->assertNotContains($corruptSecretaryId, array_column($first['candidates'], 'secretary_id'));

        $second = $service->publicCandidatePage($viewer, $self->id, (int) $first['next_after_id']);
        $this->assertSame([$secretaryIds[20]], array_column($second['candidates'], 'secretary_id'));
        $this->assertFalse($second['has_more']);
        $this->assertNull($second['next_after_id']);
    }
}
