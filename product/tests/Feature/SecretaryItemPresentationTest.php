<?php

namespace Tests\Feature;

use App\Application\SecretaryEquipmentService;
use App\Application\SecretaryService;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CurrentRulesetFixture;
use Tests\TestCase;

final class SecretaryItemPresentationTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_item_effect_projection_is_explicit_owned_world_scoped_and_never_falls_back(): void
    {
        $historicalNoEffectsWorld = $this->lightweightWorld();
        $historicalNoEffectsSettings = CurrentRulesetFixture::withIdentity('historical-no-item-effects-v10', 10);
        unset(
            $historicalNoEffectsSettings['secretary']['item_rarities'],
            $historicalNoEffectsSettings['secretary']['item_categories'],
            $historicalNoEffectsSettings['secretary']['items'],
        );
        $historicalNoEffects = RulesetVersion::query()->create([
            'key' => $historicalNoEffectsSettings['key'],
            'version' => $historicalNoEffectsSettings['version'],
            'settings' => $historicalNoEffectsSettings,
            'is_active' => false,
        ]);
        $historicalNoEffectsWorld->update(['ruleset_version_id' => $historicalNoEffects->id]);
        $user = User::factory()->create();
        $v10Nation = Nation::query()->create([
            'world_id' => $historicalNoEffectsWorld->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => 'v10表示国',
            'owner_name' => '表示島主',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 100,
        ]);
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $historicalNoEffectsWorld->id,
            'nation_id' => $v10Nation->id,
            'role' => 'owner',
        ]);
        $secretary = app(SecretaryService::class)->ensureForUser($user);
        SecretaryItemInstance::query()->create([
            'secretary_id' => $secretary->id,
            'item_key' => SecretaryItemCatalog::RING,
            'level' => 3,
            'equipped_slot' => null,
            'grant_key' => 'presentation-ring',
            'obtained_at' => now(),
        ]);
        [$historicalWorld, $historicalNation] = $this->ownedFixtureWorld($user);

        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.effect_context', null)
            ->assertJsonPath('data.inventory.items.0.effect_text', null)
            ->assertJsonPath('data.inventory.items.1.effect_text', null);

        $this->actingAs($user)->getJson("/api/v1/me/secretary?world_id={$historicalNoEffectsWorld->id}")
            ->assertOk()
            ->assertJsonPath('data.effect_context.source', 'owned_world')
            ->assertJsonPath('data.effect_context.ruleset_version', 10)
            ->assertJsonPath('data.inventory.items.0.effect_text', null)
            ->assertJsonPath('data.inventory.items.1.effect_text', null);

        $this->actingAs($user)->getJson("/api/v1/me/secretary?world_id={$historicalWorld->id}")
            ->assertOk()
            ->assertJsonPath('data.effect_context.source', 'owned_world')
            ->assertJsonPath('data.effect_context.world_id', $historicalWorld->id)
            ->assertJsonPath('data.effect_context.ruleset_version_id', $historicalWorld->ruleset_version_id)
            ->assertJsonPath('data.effect_context.ruleset_key', 'historical-secretary-item-snapshot-v15')
            ->assertJsonPath('data.effect_context.ruleset_version', 15)
            ->assertJsonPath(
                'data.inventory.items.0.effect_text',
                '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            )->assertJsonPath(
                'data.inventory.items.1.effect_text',
                '資金繰りの際、追加で3億円を得る。',
            );

        $this->actingAs($user)
            ->getJson("/api/v1/me/secretary/equipment/1/options?world_id={$historicalWorld->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.effect_text',
                '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            )->assertJsonPath(
                'data.items.1.effect_text',
                '資金繰りの際、追加で3億円を得る。',
            );

        $this->actingAs($user)->postJson('/api/v1/me/secretary/name', ['name' => 'ペリドット'])
            ->assertOk()
            ->assertJsonPath('data.effect_context', null)
            ->assertJsonPath('data.inventory.items.0.effect_text', null);
        $this->actingAs($user)->getJson("/api/v1/me/secretary?world_id={$historicalWorld->id}")
            ->assertOk()
            ->assertJsonPath(
                'data.inventory.items.0.effect_text',
                '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            );

        $historicalNation->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $this->actingAs($user)->getJson('/api/v1/me/secretary')
            ->assertOk()
            ->assertJsonPath('data.effect_context', null)
            ->assertJsonPath('data.inventory.items.0.effect_text', null)
            ->assertJsonPath('data.inventory.items.1.effect_text', null);
        $this->actingAs($user)->getJson("/api/v1/me/secretary?world_id={$historicalWorld->id}")
            ->assertOk()
            ->assertJsonPath('data.effect_context.world_id', $historicalWorld->id)
            ->assertJsonPath(
                'data.inventory.items.0.effect_text',
                '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            )->assertJsonPath(
                'data.inventory.items.1.effect_text',
                '資金繰りの際、追加で3億円を得る。',
            );
        $this->actingAs($user)->getJson('/api/v1/me/secretary?world_id=999999')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'secretary_equipment_invalid');
        $this->actingAs($user)->getJson('/api/v1/me/secretary?world_id=invalid')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'secretary_equipment_invalid');
        $this->actingAs($user)->getJson('/api/v1/me/secretary?world_id=0')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'secretary_equipment_invalid');
    }

    public function test_historical_db_snapshot_effect_resolution_adds_no_per_item_presentation_queries(): void
    {
        $user = User::factory()->create();
        $secretary = $user->secretary()->create(['equipment_version' => 1]);
        $secretary->itemInstances()->create([
            'item_key' => SecretaryItemCatalog::OLD_BOW,
            'level' => 1,
            'equipped_slot' => 1,
            'grant_key' => 'presentation-query-bow',
            'obtained_at' => now(),
        ]);
        foreach (range(1, 49) as $number) {
            $secretary->itemInstances()->create([
                'item_key' => SecretaryItemCatalog::RING,
                'level' => ($number % 10) + 1,
                'equipped_slot' => null,
                'grant_key' => "presentation-query-ring-{$number}",
                'obtained_at' => now(),
            ]);
        }
        [$world] = $this->ownedFixtureWorld($user);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $options = app(SecretaryEquipmentService::class)->options($user, 1, $world->id);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(3, $queryCount);
        $this->assertCount(50, $options['items']);
        $this->assertSame(
            '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            $options['items'][0]['effect_text'],
        );
        $this->assertSame('資金繰りの際、追加で2億円を得る。', $options['items'][1]['effect_text']);
    }

    /** @return array{World, Nation} */
    private function ownedFixtureWorld(User $user): array
    {
        $settings = $this->historicalSecretaryItemSettings();
        $ruleset = RulesetVersion::query()->create([
            'key' => $settings['key'],
            'version' => $settings['version'],
            'settings' => $settings,
            'is_active' => false,
        ]);
        $world = World::query()->create([
            'key' => 'secretary-item-presentation-world',
            'name' => '装備表示World',
            'ruleset_version_id' => $ruleset->id,
            'current_turn' => 1,
        ]);
        $nation = Nation::query()->create([
            'world_id' => $world->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => '履歴表示国',
            'owner_name' => '表示島主',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 100,
        ]);
        NationMembership::query()->create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'role' => 'owner',
        ]);

        return [$world, $nation];
    }

    /** @return array<string, mixed> */
    private function historicalSecretaryItemSettings(): array
    {
        $settings = CurrentRulesetFixture::withIdentity('historical-secretary-item-snapshot-v15', 15);
        $oldBow = $settings['secretary']['items']['old_bow'];
        unset($oldBow['rarity'], $oldBow['tradable'], $oldBow['npc_tradable']);
        $oldBow['same_item_max_equipped'] = 1;
        $ring = $settings['secretary']['items']['ring'];
        unset($ring['rarity'], $ring['tradable'], $ring['npc_tradable']);
        $ring['category'] = 'ring';
        $ring['same_item_max_equipped'] = 5;
        unset($settings['secretary']['item_rarities']);
        $settings['secretary']['item_categories'] = [
            'bow' => ['key' => 'bow', 'max_equipped' => 1],
            'ring' => ['key' => 'ring', 'max_equipped' => 5],
        ];
        $settings['secretary']['items'] = [
            'old_bow' => $oldBow,
            'ring' => $ring,
        ];

        return $settings;
    }
}
