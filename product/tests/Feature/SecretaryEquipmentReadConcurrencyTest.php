<?php

namespace Tests\Feature;

use App\Application\SecretaryEquipmentService;
use App\Domain\Secretary\SecretaryEquipmentConflictException;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecretaryEquipmentReadConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_cannot_authorize_a_stale_item_list_with_a_new_version(): void
    {
        $user = User::factory()->create();
        $secretary = Secretary::query()->create(['user_id' => $user->id]);
        $bow = $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::OLD_BOW,
            'level' => 1,
            'equipped_slot' => 1,
            'grant_key' => 'equipment-read-interleaving',
            'obtained_at' => now(),
        ]);
        $versionBefore = $secretary->surfaceState->equipment_version;
        $service = app(SecretaryEquipmentService::class);
        $injectChange = true;
        $changed = false;

        // The item rows have been fetched. Inject another tab's completed
        // unequip before options can return its optimistic-lock token.
        SecretaryItemInstance::retrieved(static function () use (&$injectChange, &$changed, $service, $user, $versionBefore): void {
            if (! $injectChange) {
                return;
            }
            $injectChange = false;
            $service->mutate($user, 1, null, $versionBefore);
            $changed = true;
        });
        try {
            $options = $service->options($user, 1);
        } finally {
            $injectChange = false;
        }

        $this->assertTrue($changed);
        $this->assertSame($bow->id, $options['current_item']['id']);
        $this->assertSame($versionBefore, $options['equipment_version']);
        try {
            $service->mutate($user, 1, $bow->id, $options['equipment_version']);
            $this->fail('An old item list must not overwrite the intervening equipment change.');
        } catch (SecretaryEquipmentConflictException $exception) {
            $this->assertSame('secretary_equipment_version_conflict', $exception->errorCode);
        }
        $this->assertNull($bow->fresh()->equipped_slot);
        $this->assertSame($versionBefore + 1, $secretary->fresh()->surfaceState->equipment_version);
    }
}
