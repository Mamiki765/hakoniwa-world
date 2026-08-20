<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\NextProductionTurnRunGuard;
use App\Application\SecretaryEquipmentService;
use App\Domain\Nation\UserMembershipMutationLock;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryEquipmentValidationException;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class SecretaryEquipmentTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_api_equips_unequips_noops_and_rejects_stale_versions(): void
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        app(NationCreationService::class)->create($user, $world, '装備API島', '装備API島主');
        $secretary = $user->secretary()->firstOrFail();
        $bow = $secretary->itemInstances()->sole();

        $this->actingAs($user)->getJson('/api/v1/me/secretary/equipment/1/options')
            ->assertOk()
            ->assertJsonPath('data.slot', 1)
            ->assertJsonPath('data.equipment_version', 1)
            ->assertJsonPath('data.current_item.id', $bow->id)
            ->assertJsonPath('data.items.0.id', $bow->id)
            ->assertJsonPath('data.category_limits.0.category', 'bow')
            ->assertJsonMissingPath('data.items.0.flavor_text');

        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => null,
            'expected_version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.equipment_version', 2)
            ->assertJsonPath('data.equipment.slots.0.item', null);
        $this->assertNull($bow->fresh()->equipped_slot);
        $this->assertSame(1, $this->equipmentAuditCount($secretary));

        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => null,
            'expected_version' => 2,
        ])->assertOk()->assertJsonPath('data.equipment_version', 2);
        $this->assertSame(1, $this->equipmentAuditCount($secretary));

        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => $bow->id,
            'expected_version' => 1,
        ])->assertConflict()->assertJsonPath('code', 'secretary_equipment_version_conflict');
        $this->assertNull($bow->fresh()->equipped_slot);

        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => $bow->id,
            'expected_version' => 2,
        ])->assertOk()->assertJsonPath('data.equipment_version', 3)
            ->assertJsonPath('data.equipment.slots.0.item.id', $bow->id);
        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => $bow->id,
            'expected_version' => 3,
        ])->assertOk()->assertJsonPath('data.equipment_version', 3);

        $this->assertSame(1, $bow->fresh()->equipped_slot);
        $this->assertSame(2, $this->equipmentAuditCount($secretary));
    }

    public function test_invalid_slots_wrong_user_missing_items_and_cross_slot_move_are_rejected_without_mutation(): void
    {
        $first = $this->secretaryFixture();
        $second = $this->secretaryFixture();
        $firstBow = $first->itemInstances()->create($this->itemAttributes('old_bow', 2));
        $secondBow = $second->itemInstances()->create($this->itemAttributes('old_bow', null));
        $user = $first->user()->firstOrFail();

        foreach ([0, 6] as $slot) {
            $this->actingAs($user)->putJson("/api/v1/me/secretary/equipment/{$slot}", [
                'item_id' => null,
                'expected_version' => 1,
            ])->assertUnprocessable();
        }
        foreach ([$secondBow->id, 9_999_999] as $itemId) {
            $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
                'item_id' => $itemId,
                'expected_version' => 1,
            ])->assertUnprocessable()->assertJsonPath('code', 'secretary_equipment_invalid');
        }
        $this->actingAs($user)->putJson('/api/v1/me/secretary/equipment/1', [
            'item_id' => $firstBow->id,
            'expected_version' => 1,
        ])->assertUnprocessable()->assertJsonPath('code', 'secretary_equipment_invalid');

        $this->assertSame(2, $firstBow->fresh()->equipped_slot);
        $this->assertSame(1, $first->fresh()->equipment_version);
        $this->assertSame(0, $this->equipmentAuditCount($first));
    }

    public function test_options_and_mutation_use_the_same_final_state_category_and_same_item_policy(): void
    {
        $secretary = $this->secretaryFixture();
        $user = $secretary->user()->firstOrFail();
        $currentBow = $secretary->itemInstances()->create($this->itemAttributes('test_bow_a', 1));
        $replacementBow = $secretary->itemInstances()->create($this->itemAttributes('test_bow_b', null));
        $firstCharm = $secretary->itemInstances()->create($this->itemAttributes('test_charm', 2));
        $secondCharm = $secretary->itemInstances()->create($this->itemAttributes('test_charm', null));
        $service = $this->service(catalog: $this->equipmentPolicyCatalog());

        $slotTwo = $service->options($user, 2);
        $this->assertNotContains($replacementBow->id, array_column($slotTwo['items'], 'id'));
        $this->assertSame($firstCharm->id, $slotTwo['current_item']['id']);

        $slotOne = $service->options($user, 1);
        $this->assertSame([$currentBow->id, $replacementBow->id], array_column($slotOne['items'], 'id'));
        $service->mutate($user, 1, $replacementBow->id, 1);
        $this->assertNull($currentBow->fresh()->equipped_slot);
        $this->assertSame(1, $replacementBow->fresh()->equipped_slot);

        try {
            $service->mutate($user, 3, $secondCharm->id, 2);
            $this->fail('Expected same-item maximum to reject a second equipped charm.');
        } catch (SecretaryEquipmentValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($secondCharm->fresh()->equipped_slot);
        $this->assertSame(2, $secretary->fresh()->equipment_version);

        try {
            $service->mutate($user, 3, $currentBow->id, 2);
            $this->fail('Expected the bow category maximum to reject a forged second bow.');
        } catch (SecretaryEquipmentValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNull($currentBow->fresh()->equipped_slot);
    }

    public function test_failed_replacement_rolls_back_both_slots_version_and_audit(): void
    {
        $secretary = $this->secretaryFixture();
        $user = $secretary->user()->firstOrFail();
        $old = $secretary->itemInstances()->create($this->itemAttributes('test_bow_a', 1));
        $next = $secretary->itemInstances()->create($this->itemAttributes('test_bow_b', null));
        DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_equipment_version_update() RETURNS trigger AS $$
BEGIN
    IF NEW.equipment_version <> OLD.equipment_version THEN
        RAISE EXCEPTION 'injected equipment version failure';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER reject_equipment_version_update
BEFORE UPDATE ON secretaries
FOR EACH ROW EXECUTE FUNCTION reject_equipment_version_update();
SQL);

        try {
            $this->service(catalog: $this->equipmentPolicyCatalog())->mutate($user, 1, $next->id, 1);
            $this->fail('Expected injected Secretary update failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('injected equipment version failure', $exception->getMessage());
        } finally {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS reject_equipment_version_update ON secretaries;
DROP FUNCTION IF EXISTS reject_equipment_version_update();
SQL);
        }

        $this->assertSame(1, $old->fresh()->equipped_slot);
        $this->assertNull($next->fresh()->equipped_slot);
        $this->assertSame(1, $secretary->fresh()->equipment_version);
        $this->assertSame(0, $this->equipmentAuditCount($secretary));
    }

    #[DataProvider('unresolvedTurnStatuses')]
    public function test_each_unresolved_next_turn_status_blocks_equipment_without_mutation(string $status): void
    {
        [$user, $secretary, $world] = $this->affectedWorldFixture();
        $bow = $secretary->itemInstances()->create($this->itemAttributes('old_bow', 1));
        $this->turnRun($world, $status);

        try {
            app(SecretaryEquipmentService::class)->mutate($user, 1, null, 1);
            $this->fail("Expected {$status} TurnRun to block equipment mutation.");
        } catch (SecretaryEquipmentConflictException $exception) {
            $this->assertSame('secretary_equipment_turn_unresolved', $exception->errorCode);
        }

        $this->assertSame(1, $bow->fresh()->equipped_slot);
        $this->assertSame(1, $secretary->fresh()->equipment_version);
        $this->assertSame(0, $this->equipmentAuditCount($secretary));
    }

    public function test_zero_world_and_completed_next_turn_paths_allow_mutation(): void
    {
        $zeroWorldSecretary = $this->secretaryFixture();
        $zeroWorldBow = $zeroWorldSecretary->itemInstances()->create($this->itemAttributes('old_bow', 1));
        app(SecretaryEquipmentService::class)->mutate(
            $zeroWorldSecretary->user()->firstOrFail(),
            1,
            null,
            1,
        );
        $this->assertNull($zeroWorldBow->fresh()->equipped_slot);

        [$user, $secretary, $world] = $this->affectedWorldFixture();
        $bow = $secretary->itemInstances()->create($this->itemAttributes('old_bow', 1));
        $this->turnRun($world, TurnRun::STATUS_COMPLETED);
        app(SecretaryEquipmentService::class)->mutate($user, 1, null, 1);
        $this->assertNull($bow->fresh()->equipped_slot);
        $this->assertSame(2, $secretary->fresh()->equipment_version);
    }

    public function test_multi_world_locks_are_acquired_in_id_order_released_in_reverse_and_one_blocked_world_rejects_all(): void
    {
        [$user, $secretary, $first] = $this->affectedWorldFixture();
        $second = $this->additionalAffectedWorld($user, 'equipment-second-world');
        $bow = $secretary->itemInstances()->create($this->itemAttributes('old_bow', 1));
        $lock = new RecordingWorldMutationLock;
        $service = $this->service(worldLock: $lock);

        $service->mutate($user, 1, null, 1);
        $this->assertSame([$first->id, $second->id], $lock->acquired);
        $this->assertSame([$second->id, $first->id], $lock->released);
        $this->assertNull($bow->fresh()->equipped_slot);

        $bow->refresh();
        $bow->update(['equipped_slot' => 1]);
        $secretary->forceFill(['equipment_version' => 1])->save();
        $this->turnRun($second, TurnRun::STATUS_BLOCKED);
        $lock = new RecordingWorldMutationLock;
        try {
            $this->service(worldLock: $lock)->mutate($user, 1, null, 1);
            $this->fail('Expected one blocked World to reject the global equipment mutation.');
        } catch (SecretaryEquipmentConflictException $exception) {
            $this->assertSame('secretary_equipment_turn_unresolved', $exception->errorCode);
        }
        $this->assertSame([$first->id, $second->id], $lock->acquired);
        $this->assertSame([$second->id, $first->id], $lock->released);
        $this->assertSame(1, $bow->fresh()->equipped_slot);
    }

    public function test_partial_world_lock_failure_and_membership_set_change_release_locks_without_writes(): void
    {
        [$user, $secretary, $first] = $this->affectedWorldFixture();
        $second = $this->additionalAffectedWorld($user, 'equipment-partial-world');
        $bow = $secretary->itemInstances()->create($this->itemAttributes('old_bow', 1));

        $partial = new RecordingWorldMutationLock(failOnWorldId: $second->id);
        try {
            $this->service(worldLock: $partial)->mutate($user, 1, null, 1);
            $this->fail('Expected partial World lock failure.');
        } catch (SecretaryEquipmentConflictException $exception) {
            $this->assertSame('secretary_equipment_world_updating', $exception->errorCode);
        }
        $this->assertSame([$first->id, $second->id], $partial->acquired);
        $this->assertSame([$first->id], $partial->released);
        $this->assertSame(1, $bow->fresh()->equipped_slot);

        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('world_id', $second->id)
            ->firstOrFail();
        $changing = new RecordingWorldMutationLock(onFirstAcquire: static function () use ($membership): void {
            $membership->delete();
        });
        try {
            $this->service(worldLock: $changing)->mutate($user, 1, null, 1);
            $this->fail('Expected the authoritative membership re-read to reject a changed set.');
        } catch (SecretaryEquipmentConflictException $exception) {
            $this->assertSame('secretary_equipment_membership_changed', $exception->errorCode);
        }
        $this->assertSame([$second->id, $first->id], $changing->released);
        $this->assertSame(1, $bow->fresh()->equipped_slot);
        $this->assertSame(1, $secretary->fresh()->equipment_version);
    }

    public function test_options_query_count_is_two_for_empty_one_fifty_and_five_equipped_items(): void
    {
        $secretary = $this->secretaryFixture();
        $user = $secretary->user()->firstOrFail();
        $service = $this->service(catalog: $this->bulkCatalog());
        $counts = [];

        foreach ([0, 1, 50] as $count) {
            $secretary->itemInstances()->delete();
            for ($index = 1; $index <= $count; $index++) {
                $secretary->itemInstances()->create($this->itemAttributes('test_bulk', null));
            }
            DB::flushQueryLog();
            DB::enableQueryLog();
            $service->options($user, 1);
            $counts["items_{$count}"] = count(DB::getQueryLog());
            DB::disableQueryLog();
        }

        $secretary->itemInstances()->delete();
        foreach (range(1, 5) as $slot) {
            $secretary->itemInstances()->create($this->itemAttributes('test_bulk', $slot));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->options($user, 3);
        $counts['five_equipped'] = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame([
            'items_0' => 2,
            'items_1' => 2,
            'items_50' => 2,
            'five_equipped' => 2,
        ], $counts);
    }

    /** @return array<string, array{string}> */
    public static function unresolvedTurnStatuses(): array
    {
        return [
            'pending' => [TurnRun::STATUS_PENDING],
            'running' => [TurnRun::STATUS_RUNNING],
            'failed' => [TurnRun::STATUS_FAILED],
            'blocked' => [TurnRun::STATUS_BLOCKED],
        ];
    }

    private function secretaryFixture(): Secretary
    {
        return Secretary::query()->create(['user_id' => User::factory()->create()->id]);
    }

    /** @return array{User, Secretary, World} */
    private function affectedWorldFixture(): array
    {
        $world = $this->lightweightWorld();
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);
        $nation = $this->nation($world, '装備所属島');
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'role' => 'owner',
        ]);

        return [$user, $secretary, $world];
    }

    private function additionalAffectedWorld(User $user, string $key): World
    {
        $rulesetId = World::query()->valueOrFail('ruleset_version_id');
        $world = World::query()->create([
            'key' => $key,
            'name' => '追加World',
            'ruleset_version_id' => $rulesetId,
            'current_turn' => 1,
        ]);
        $nation = $this->nation($world, "{$key}島");
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'role' => 'owner',
        ]);

        return $world;
    }

    private function nation(World $world, string $name): Nation
    {
        return Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => (int) Nation::query()->where('world_id', $world->id)->max('nation_number') + 1,
            'registered_turn' => $world->current_turn,
            'name' => $name,
            'owner_name' => '装備島主',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 100,
        ]);
    }

    private function turnRun(World $world, string $status): TurnRun
    {
        return TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $world->current_turn + 1,
            'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => str_repeat('e', 64),
            'source' => 'manual',
            'is_dry_run' => false,
            'status' => $status,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
    }

    /** @return array<string, mixed> */
    private function itemAttributes(string $key, ?int $slot): array
    {
        return [
            'item_key' => $key,
            'level' => 1,
            'equipped_slot' => $slot,
            'grant_key' => null,
            'obtained_at' => now(),
        ];
    }

    private function service(
        ?SecretaryItemCatalog $catalog = null,
        ?WorldMutationLock $worldLock = null,
    ): SecretaryEquipmentService {
        $worldLock ??= app(WorldMutationLock::class);

        return new SecretaryEquipmentService(
            app(UserMembershipMutationLock::class),
            $worldLock,
            new NextProductionTurnRunGuard($worldLock),
            $catalog ?? app(SecretaryItemCatalog::class),
        );
    }

    private function equipmentPolicyCatalog(): SecretaryItemCatalog
    {
        return new class extends SecretaryItemCatalog
        {
            public function definitions(): array
            {
                return [
                    'test_bow_a' => $this->definitionRow('test_bow_a', '弓A', 'bow', '弓', 1, 1),
                    'test_bow_b' => $this->definitionRow('test_bow_b', '弓B', 'bow', '弓', 1, 1),
                    'test_charm' => $this->definitionRow('test_charm', '護符', 'charm', '護符', 5, 1),
                ];
            }

            /** @return array<string, mixed> */
            private function definitionRow(
                string $key,
                string $name,
                string $category,
                string $label,
                int $categoryMaximum,
                int $sameItemMaximum,
            ): array {
                return [
                    'key' => $key,
                    'category' => $category,
                    'category_label' => $label,
                    'category_max_equipped' => $categoryMaximum,
                    'max_level' => 10,
                    'name' => $name,
                    'flavor_text' => 'test only',
                    'unique_per_secretary' => false,
                    'same_item_max_equipped' => $sameItemMaximum,
                ];
            }
        };
    }

    private function bulkCatalog(): SecretaryItemCatalog
    {
        return new class extends SecretaryItemCatalog
        {
            public function definitions(): array
            {
                return [
                    'test_bulk' => [
                        'key' => 'test_bulk',
                        'category' => 'bulk',
                        'category_label' => '一括',
                        'category_max_equipped' => 5,
                        'max_level' => 1,
                        'name' => '一括fixture',
                        'flavor_text' => 'test only',
                        'unique_per_secretary' => false,
                        'same_item_max_equipped' => 5,
                    ],
                ];
            }
        };
    }

    private function equipmentAuditCount(Secretary $secretary): int
    {
        return DB::table('audit_events')
            ->where('event_type', 'secretary.equipment_changed')
            ->where('subject_id', $secretary->id)
            ->count();
    }
}

final class RecordingWorldMutationLock extends WorldMutationLock
{
    /** @var list<int> */
    public array $acquired = [];

    /** @var list<int> */
    public array $released = [];

    private bool $callbackRan = false;

    public function __construct(
        private readonly ?int $failOnWorldId = null,
        private readonly mixed $onFirstAcquire = null,
    ) {}

    public function acquire(World $world): void
    {
        $this->acquired[] = $world->id;
        if (! $this->callbackRan && is_callable($this->onFirstAcquire)) {
            $this->callbackRan = true;
            ($this->onFirstAcquire)();
        }
        if ($world->id === $this->failOnWorldId) {
            throw new TurnAlreadyRunningException('injected partial World lock failure');
        }
    }

    public function release(World $world): void
    {
        $this->released[] = $world->id;
    }

    public function assertHeld(World $world): void {}
}
