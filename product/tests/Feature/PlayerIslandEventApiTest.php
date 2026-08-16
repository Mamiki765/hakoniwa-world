<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Models\Nation;
use App\Models\User;
use App\Models\World;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class PlayerIslandEventApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_major_news_is_a_fixed_public_lifecycle_feed_and_excludes_commands_awards_and_turn_completion(): void
    {
        [$world, , $nation] = $this->nation('ニュース島');
        $world->update(['current_turn' => 9]);
        DB::table('audit_events')->delete();

        foreach (range(1, 16) as $index) {
            $this->audit('nation.created', $world, $nation, 'public', 1, [
                'nation_name' => "履歴{$index}島",
            ]);
        }
        $created = $this->audit('nation.created', $world, $nation, 'public', 9, [
            'nation_name' => $nation->name,
        ]);
        $this->audit('award.granted', $nation, $nation, 'public', 9, [
            'nation_name' => $nation->name, 'award_key' => 'prosperity',
        ]);
        $this->audit('command.facility_built_public', $nation, $nation, 'public', 9, [
            'nation_name' => $nation->name, 'facility_key' => 'farm', 'x' => 2, 'y' => 3,
        ]);
        $this->audit('turn.completed', $world, null, 'public', 9, ['random_seed' => 'secret-seed']);

        $response = $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")
            ->assertOk()
            ->assertJsonPath('data.limit', 15)
            ->assertJsonPath('data.groups.0.target_turn', 9)
            ->assertJsonPath('data.groups.0.events.0.id', $created)
            ->assertJsonPath('data.groups.0.events.0.message', 'ニュース島ができました。')
            ->assertJsonPath('data.groups.0.events.0.importance', 'notable');

        $this->assertCount(1, $response->json('data.groups.0.events'));
        $this->assertCount(15, collect($response->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        ));
        foreach (['award.granted', 'command.facility_built_public', 'turn.completed', 'secret-seed'] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $response->getContent());
        }
    }

    public function test_monster_spawn_failure_is_audit_only_across_every_player_projection(): void
    {
        [$world, $owner, $nation] = $this->nation('監査島');
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $eventId = $this->audit('monster.spawn_failed_no_settlement', $nation, $nation, 'public', 2, [
            'nation_id' => $nation->id,
            'nation_number' => $nation->nation_number,
            'owned_land_cells' => 987654321,
            'population' => 876543210,
        ]);

        $responses = [
            $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")->assertOk(),
            $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk(),
            $this->getJson("/api/v1/public/nations/{$nation->id}/events")->assertOk(),
            $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk(),
        ];

        foreach ($responses as $response) {
            $body = (string) $response->getContent();
            $eventIds = collect($response->json('data.groups'))->flatMap(
                static fn (array $group): array => $group['events'],
            )->pluck('id');

            $this->assertNotContains($eventId, $eventIds);
            foreach (['monster.spawn_failed_no_settlement', 'owned_land_cells', 'population', '987654321', '876543210'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $body);
            }
        }

        $this->assertDatabaseHas('audit_events', [
            'id' => $eventId,
            'event_type' => 'monster.spawn_failed_no_settlement',
            'visibility' => 'public',
            'nation_id' => $nation->id,
        ]);
    }

    public function test_world_public_log_pages_by_two_turns_and_island_public_log_filters_to_that_island(): void
    {
        [$world, , $first] = $this->nation('第一島');
        [, , $second] = $this->nation('第二島', $world);
        $world->update(['current_turn' => 5]);
        DB::table('audit_events')->delete();

        $this->publicFacility($first, 5, 1, 2, 'farm');
        $this->publicFacility($second, 4, 3, 4, 'factory');
        $this->publicFacility($first, 3, 5, 6, 'mine');

        $firstPage = $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.turns_per_page', 2)
            ->assertJsonPath('data.turn_range.start', 4)
            ->assertJsonPath('data.turn_range.end', 5)
            ->assertJsonPath('data.has_older_page', true);
        $this->assertSame([5, 4], $this->turns($firstPage->json('data.groups')));

        $secondPage = $this->getJson("/api/v1/public/worlds/{$world->id}/events?page=2&anchor_turn=5")
            ->assertOk()
            ->assertJsonPath('data.turn_range.start', 2)
            ->assertJsonPath('data.turn_range.end', 3);
        $this->assertSame([3], $this->turns($secondPage->json('data.groups')));

        $islandPage = $this->getJson("/api/v1/public/nations/{$first->id}/events")
            ->assertOk()
            ->assertJsonPath('data.turns_per_page', 24);
        $body = (string) $islandPage->getContent();
        $messages = $this->messages($islandPage->json('data.groups'));
        $this->assertContains('第一島(1,2)で農場整備が行われました。', $messages);
        $this->assertContains('第一島(5,6)で採掘場整備が行われました。', $messages);
        $this->assertFalse(collect($messages)->contains(
            static fn (string $message): bool => str_contains($message, '第二島'),
        ));
        $this->assertStringNotContainsString('occurred_at', $body);
        $this->assertStringNotContainsString('coordinate', $body);
    }

    public function test_monster_damage_uses_historical_host_metadata_and_missing_host_events_fail_closed(): void
    {
        [$world, , $attacker] = $this->nation('攻撃島');
        [, , $host] = $this->nation('怪獣島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $blockedWithoutHost = $this->audit('monster.damage_blocked', $attacker, $attacker, 'public', 2, [
            'monster_key' => 'inora', 'x' => 12, 'y' => 8,
        ]);
        $damagedWithoutHost = $this->audit('monster.damaged', $attacker, $attacker, 'public', 2, [
            'monster_key' => 'inora', 'x' => 12, 'y' => 8,
        ]);
        $damagedWithHost = $this->audit('monster.damaged', $attacker, $attacker, 'public', 2, [
            'monster_key' => 'inora', 'host_nation_id' => $host->id,
            'host_nation_name' => $host->name, 'x' => 12, 'y' => 8,
        ]);
        $legacyDamagedWithHostId = $this->audit('monster.damaged', $attacker, $attacker, 'public', 2, [
            'monster_key' => 'inora', 'host_nation_id' => $host->id, 'x' => 10, 'y' => 9,
        ]);
        $killedWithHost = $this->audit('monster.killed', $attacker, $attacker, 'public', 2, [
            'monster_key' => 'inora',
            'killer_nation_id' => $attacker->id,
            'host_nation_id' => $host->id,
            'host_nation_name' => $host->name,
            'x' => 12,
            'y' => 8,
        ]);
        $host->update(['name' => '現在怪獣島']);

        $worldResponse = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $worldEvents = collect($worldResponse->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        );
        $this->assertSame(
            [$killedWithHost, $legacyDamagedWithHostId, $damagedWithHost],
            $worldEvents->pluck('id')->all(),
        );
        $this->assertSame(
            '怪獣島(12,8)のいのらは力尽き、倒れました。怪獣は解体され、報酬が分配されました。',
            $worldEvents->first()['message'],
        );
        $this->assertContains(
            '怪獣島(12,8)のいのらに攻撃が命中し、苦しそうに咆哮しました。',
            $worldEvents->pluck('message')->all(),
        );
        $this->assertContains(
            '現在怪獣島(10,9)のいのらに攻撃が命中し、苦しそうに咆哮しました。',
            $worldEvents->pluck('message')->all(),
        );

        $hostResponse = $this->getJson("/api/v1/public/nations/{$host->id}/events")->assertOk();
        $hostEvents = collect($hostResponse->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        );
        $this->assertSame(
            [$killedWithHost, $legacyDamagedWithHostId, $damagedWithHost],
            $hostEvents->pluck('id')->all(),
        );

        $attackerResponse = $this->getJson("/api/v1/public/nations/{$attacker->id}/events")->assertOk();
        $attackerEventIds = collect($attackerResponse->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        )->pluck('id');
        $this->assertNotContains($blockedWithoutHost, $attackerEventIds);
        $this->assertNotContains($damagedWithoutHost, $attackerEventIds);
        $this->assertNotContains($damagedWithHost, $attackerEventIds);
        $this->assertNotContains($legacyDamagedWithHostId, $attackerEventIds);
        $this->assertNotContains($killedWithHost, $attackerEventIds);
    }

    public function test_public_aid_is_one_world_event_related_to_both_snapshot_nations_and_exposes_only_actual_transfer(): void
    {
        [$world, , $sender] = $this->nation('援助元島');
        [, , $receiver] = $this->nation('援助先島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $eventId = $this->audit('command.money_aid_public', $sender, $sender, 'public', 2, [
            'sender_nation_id' => $sender->id,
            'sender_nation_name' => '援助元島',
            'receiver_nation_id' => $receiver->id,
            'receiver_nation_name' => '援助先島',
            'transferred_money' => 100,
            'requested_money' => 9_876_543,
            'receiver_capacity' => 8_765_432,
            'sender_balance' => 7_654_321,
        ]);
        $sender->update(['name' => '現在援助元島']);
        $receiver->update(['name' => '現在援助先島']);

        $worldResponse = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $senderResponse = $this->getJson("/api/v1/public/nations/{$sender->id}/events")->assertOk();
        $receiverResponse = $this->getJson("/api/v1/public/nations/{$receiver->id}/events")->assertOk();
        foreach ([$worldResponse, $senderResponse, $receiverResponse] as $response) {
            $events = collect($response->json('data.groups'))->flatMap(
                static fn (array $group): array => $group['events'],
            );
            $this->assertSame([$eventId], $events->pluck('id')->all());
            $this->assertSame(
                '援助元島から援助先島へ100億円の資金援助が行われました。',
                $events->first()['message'],
            );
            $body = (string) $response->getContent();
            foreach (['requested_money', 'receiver_capacity', 'sender_balance', '9,876,543', '8,765,432', '7,654,321'] as $hidden) {
                $this->assertStringNotContainsString($hidden, $body);
            }
        }
    }

    public function test_monster_defense_self_destruct_uses_defense_cell_for_public_log(): void
    {
        [$world, , $originNation] = $this->nation('移動元島');
        [, , $defenseNation] = $this->nation('防衛島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $eventId = $this->audit(
            'monster.defense_self_destructed',
            $originNation,
            $originNation,
            'public',
            2,
            [
                'monster_key' => 'inora',
                'x' => 3,
                'y' => 4,
                'center_x' => 12,
                'center_y' => 8,
                'defense_owner_nation_id' => $defenseNation->id,
                'hardening_ignored' => true,
            ],
        );

        $worldResponse = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $worldEvents = collect($worldResponse->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        );
        $this->assertSame([$eventId], $worldEvents->pluck('id')->all());
        $this->assertSame(
            '防衛島(12,8)でいのらが防衛施設へ接触し、施設とともに消滅しました。',
            $worldEvents->first()['message'],
        );
        $this->assertStringNotContainsString('(3,4)', (string) $worldResponse->getContent());
        $this->assertStringNotContainsString('hardening_ignored', (string) $worldResponse->getContent());

        $this->getJson("/api/v1/public/nations/{$defenseNation->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.id', $eventId);
        $originResponse = $this->getJson("/api/v1/public/nations/{$originNation->id}/events")->assertOk();
        $this->assertSame([], $originResponse->json('data.groups'));
    }

    public function test_owner_log_requires_membership_and_includes_only_its_own_public_island_events(): void
    {
        [$world, $owner, $nation] = $this->nation('所有島');
        [, $otherOwner, $other] = $this->nation('他島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $finance = $this->audit('command.finance', $nation, $nation, 'nation', 2, ['applied' => 50]);
        $ownPublic = $this->publicFacility($nation, 2, 6, 7, 'farm');
        $this->audit('command.finance', $other, $other, 'nation', 2, ['applied' => 99_999]);
        $this->publicFacility($other, 2, 8, 8, 'farm');
        $this->audit('message_board.secret_sent', $nation, $nation, 'private', 2, [
            'body' => '秘密通信本文', 'money_before' => 9999,
        ]);
        $this->audit('message_board.secret_sent', $nation, $nation, 'public', 2, [
            'body' => '誤分類されても非表示', 'money_before' => 8888,
        ]);

        $this->getJson("/api/v1/nations/{$nation->id}/events")->assertUnauthorized();
        $this->actingAs($otherOwner)->getJson("/api/v1/nations/{$nation->id}/events")->assertForbidden();
        $response = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.id', $ownPublic)
            ->assertJsonPath('data.groups.0.events.0.confidential', false);

        $body = (string) $response->getContent();
        $this->assertContains('所有島(6,7)で農場整備が行われました。', $this->messages($response->json('data.groups')));
        $this->assertTrue(collect($response->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['id'] === $finance,
        ));
        foreach (['99,999', '(8,8)', 'message_board.secret_sent', '秘密通信本文', '誤分類されても非表示', 'money_before'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
    }

    public function test_legacy_public_monster_reward_remains_visible_only_to_related_owners(): void
    {
        [$world, $killerOwner, $killer] = $this->nation('旧撃破島');
        [, $hostOwner, $host] = $this->nation('旧所在島', $world);
        [, $spectatorOwner, $spectator] = $this->nation('無関係島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $rewardId = $this->audit('monster.reward_distributed', $killer, $killer, 'public', 2, [
            'monster_key' => 'inora',
            'killer_nation_id' => $killer->id,
            'host_nation_id' => $host->id,
            'killer_money' => ['applied' => 321],
            'host_meat_food' => ['applied' => 654],
        ]);

        $killerResponse = $this->actingAs($killerOwner)
            ->getJson("/api/v1/nations/{$killer->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.id', $rewardId);
        $this->assertContains(
            'いのらを撃破し、賞金321億円を受け取りました。',
            $this->messages($killerResponse->json('data.groups')),
        );
        $hostResponse = $this->actingAs($hostOwner)
            ->getJson("/api/v1/nations/{$host->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.id', $rewardId);
        $this->assertContains(
            'いのらが倒され、怪獣肉654トンを受け取りました。',
            $this->messages($hostResponse->json('data.groups')),
        );
        $this->actingAs($spectatorOwner)
            ->getJson("/api/v1/nations/{$spectator->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);
        $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);
        $this->getJson("/api/v1/public/nations/{$killer->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);
        $this->getJson("/api/v1/public/nations/{$host->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);
    }

    public function test_secret_facilities_are_blurred_publicly_and_exact_only_for_the_owner(): void
    {
        [$world, $owner, $nation] = $this->nation('秘密島');
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $this->audit('command.forest_planted_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'x' => 12, 'y' => 13, 'money' => 9999,
        ]);
        $this->audit('command.forest_planted_private', $nation, $nation, 'private', 2, [
            'nation_name' => $nation->name, 'x' => 12, 'y' => 13,
        ]);
        $this->audit('terrain.changed', $nation, $nation, 'private', 2, [
            'command_key' => 'plant_forest', 'x' => 12, 'y' => 13,
            'from_terrain_key' => 'plain', 'to_terrain_key' => 'forest',
        ]);
        $this->audit('command.seabed_base_built_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'x' => 22, 'y' => 23, 'cost_money' => 800,
        ]);
        $this->audit('command.seabed_base_built_private', $nation, $nation, 'private', 2, [
            'nation_name' => $nation->name, 'x' => 22, 'y' => 23,
        ]);
        $this->audit('facility.constructed', $nation, $nation, 'private', 2, [
            'command_key' => 'build_seabed_base', 'facility_key' => 'seabed_base', 'x' => 22, 'y' => 23,
        ]);
        $this->audit('command.facility_built_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'facility_key' => 'defense', 'x' => 7, 'y' => 8,
            'actual_facility_key' => 'decoy',
        ]);
        $this->audit('facility.constructed', $nation, $nation, 'private', 2, [
            'command_key' => 'build_decoy', 'facility_key' => 'decoy', 'x' => 7, 'y' => 8,
        ]);
        $this->audit('command.decoy_built_private', $nation, $nation, 'private', 2, [
            'nation_name' => $nation->name, 'command_key' => 'build_decoy',
            'facility_key' => 'decoy', 'x' => 7, 'y' => 8,
        ]);
        $this->audit('command.facility_built_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'facility_key' => 'missile_base', 'x' => 44, 'y' => 45,
        ]);
        $this->audit('monster.trampled', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'monster_key' => 'inora', 'x' => 9, 'y' => 9,
            'removed_facility_key' => 'decoy',
        ]);
        $this->audit('monster.trampled', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'monster_key' => 'inora', 'x' => 10, 'y' => 10,
            'removed_facility_key' => 'missile_base',
        ]);

        $public = $this->getJson("/api/v1/public/nations/{$nation->id}/events")->assertOk();
        $publicBody = (string) $public->getContent();
        $publicMessages = $this->messages($public->json('data.groups'));
        $this->assertContains('こころなしか、秘密島のどこかで森が増えた気がします。', $publicMessages);
        $this->assertContains('秘密島で海底基地が建設されたようです(?,?)。', $publicMessages);
        $this->assertContains('秘密島(7,8)で防衛施設が建設されました。', $publicMessages);
        $this->assertContains('秘密島(9,9)の防衛施設がいのらに踏み荒らされました。', $publicMessages);
        $this->assertContains('秘密島(10,10)の森がいのらに踏み荒らされました。', $publicMessages);
        foreach (['12,13', '22,23', '44,45', '9999', '800', 'actual_facility_key', 'decoy', 'missile_base', 'metadata'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $publicBody);
        }

        $ownerResponse = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$nation->id}/events")
            ->assertOk();
        $ownerMessages = $this->messages($ownerResponse->json('data.groups'));
        $this->assertContains('秘密島(22,23)で海底基地を建設しました。', $ownerMessages);
        $this->assertContains('秘密島(12,13)で植林しました。', $ownerMessages);
        $this->assertContains('秘密島(7,8)でハリボテを建設しました。', $ownerMessages);
        $ownerTypes = collect($ownerResponse->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        )->pluck('type');
        $this->assertFalse($ownerTypes->contains('command.forest_planted_public'));
        $this->assertFalse($ownerTypes->contains('command.seabed_base_built_public'));
        $this->assertFalse($ownerTypes->contains('terrain.changed'));
        $this->assertFalse($ownerTypes->contains('facility.constructed'));
        $this->assertTrue(collect($ownerResponse->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['confidential'] === true,
        ));
        $this->assertFalse(collect($ownerMessages)->contains(
            static fn (string $message): bool => str_contains($message, '（秘密）'),
        ));
    }

    public function test_logging_and_assets_remain_owner_only_with_exact_values(): void
    {
        [$world, $owner, $nation] = $this->nation('資産島');
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $this->audit('command.logging_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'x' => 4, 'y' => 8, 'applied_money' => 777,
        ]);
        $this->audit('command.logging_private', $nation, $nation, 'private', 2, [
            'nation_name' => $nation->name, 'x' => 4, 'y' => 8, 'applied_money' => 777,
        ]);
        $this->audit('command.monument_launched', $nation, $nation, 'nation', 2, [
            'source_queue_item_id' => 987_654_321,
            'firing_nation_id' => $nation->id,
            'target_nation_id' => 123_456_789,
            'source_x' => 6,
            'source_y' => 9,
        ]);
        $this->audit('resource.automatic_sale', $nation, $nation, 'nation', 2, [
            'resource_key' => 'minerals', 'sold' => 300, 'revenue' => 600,
        ]);
        $this->audit('turn.summary', $nation, $nation, 'nation', 2, [
            'summary' => [
                'money' => ['start' => 100, 'end' => 700, 'delta' => 600],
                'population' => ['start' => 1000, 'end' => 1000, 'delta' => 0],
                'food' => ['start' => 2000, 'end' => 1900, 'delta' => -100],
            ],
        ]);

        $publicResponse = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $publicBody = (string) $publicResponse->getContent();
        $publicMessages = $this->messages($publicResponse->json('data.groups'));
        $this->assertContains('こころなしか、資産島のどこかで森が減った気がします。', $publicMessages);
        foreach (['4,8', '777', '300', '600', '資源変化'] as $hidden) {
            $this->assertFalse(collect($publicMessages)->contains(
                static fn (string $message): bool => str_contains($message, $hidden),
            ));
        }
        foreach (['turn.summary', 'resource.automatic_sale', 'command.monument_launched', '987654321', '123456789', 'metadata'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $publicBody);
        }

        $owner = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $ownerMessages = $this->messages($owner->json('data.groups'));
        $this->assertContains('資産島(4,8)で伐採し、777億円を得ました。', $ownerMessages);
        $this->assertContains('座標(6,9)の記念碑を対象Nationへ発射しました。', $ownerMessages);
        $this->assertContains('鉱物を300売却し、600億円を得ました。', $ownerMessages);
        $this->assertContains('第2ターンの資源変化', $ownerMessages);
        $this->assertTrue(collect($owner->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['type'] === 'turn.summary'
                && $event['summary']['money']['delta'] === 600,
        ));
        $this->assertFalse(collect($owner->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['type'] === 'command.logging_public',
        ));
        $this->assertTrue(collect($owner->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['type'] === 'command.monument_launched'
                && $event['confidential'] === true,
        ));
        foreach (['987654321', '123456789', 'source_queue_item_id', 'target_nation_id', 'firing_nation_id'] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $owner->getContent());
        }
    }

    public function test_owner_log_hides_routine_turn_noise_but_keeps_summary_and_meaningful_results(): void
    {
        [$world, $owner, $nation] = $this->nation('通知整理島');
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $routine = [
            ['fire.prevented', ['x' => 1, 'y' => 1]],
            ['monster.stayed', ['x' => 2, 'y' => 2]],
            ['command.automatic_finance', ['applied' => 10]],
            ['nation.idle_counter_changed', ['before' => 0, 'after' => 1]],
            ['resource.food_produced', ['produced' => 0]],
            ['resource.food_produced', ['produced' => 100]],
            ['resource.industrial_produced', ['produced' => 200]],
            ['resource.mineral_produced', ['produced' => 300]],
            ['resource.food_consumed', ['consumed' => 32_775]],
            ['capacity.applied', ['resource_key' => 'money', 'applied' => 10]],
        ];
        foreach ($routine as [$type, $metadata]) {
            $this->audit($type, $nation, $nation, 'nation', 2, $metadata);
        }
        $this->audit('turn.summary', $nation, $nation, 'nation', 2, [
            'summary' => [
                'money' => ['start' => 100, 'end' => 110, 'delta' => 10],
                'population' => ['start' => 10_000, 'end' => 10_100, 'delta' => 100],
                'food' => ['start' => 50_000, 'end' => 17_225, 'delta' => -32_775],
            ],
        ]);
        $this->audit('fire.damaged', $nation, $nation, 'nation', 2, [
            'x' => 4, 'y' => 5, 'facility_key' => 'farm',
        ]);
        $this->audit('resource.food_shortage', $nation, $nation, 'nation', 2, [
            'required_food' => 40_000, 'available_food' => 10_000,
        ]);
        $this->audit('famine.applied', $nation, $nation, 'nation', 2, [
            'x' => 6, 'y' => 7, 'before' => 5_000, 'actual_loss' => 500, 'after' => 4_500,
        ]);
        $this->audit('population.decreased', $nation, $nation, 'nation', 2, [
            'x' => 6, 'y' => 7, 'reason' => 'famine', 'before' => 5_000,
            'actual_loss' => 500, 'after' => 4_500,
        ]);

        $response = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $types = collect($response->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        )->pluck('type');
        foreach (array_column($routine, 0) as $hiddenType) {
            $this->assertFalse($types->contains($hiddenType), "{$hiddenType} should remain audit-only.");
        }
        $this->assertTrue($types->contains('turn.summary'));
        $this->assertTrue($types->contains('fire.damaged'));
        $this->assertTrue($types->contains('resource.food_shortage'));
        $this->assertSame(1, $types->filter(
            static fn (string $type): bool => in_array($type, ['famine.applied', 'population.decreased'], true),
        )->count());
        $this->assertTrue($types->contains('famine.applied'));
        $this->assertSame(count($routine), DB::table('audit_events')->whereIn('event_type', array_column($routine, 0))->count());
    }

    public function test_missile_public_projection_exposes_b10_summary_but_not_private_launch_detail(): void
    {
        [$world, $owner, $firing] = $this->nation('発射島');
        [, , $target] = $this->nation('被弾島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $this->audit('missile.launched', $firing, $firing, 'public', 2, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile', 'fired_shots' => 3,
            'queue_item_id' => 88, 'target_x' => 50, 'target_y' => 51, 'cost_money' => 900,
        ]);
        $this->audit('missile.impact', $target, $target, 'public', 2, [
            'firing_nation_name' => $firing->name, 'target_nation_name' => $target->name,
            'missile_key' => 'pp_missile', 'effect' => 'capital_damaged', 'x' => 12, 'y' => 8,
            'cost_money' => 900, 'all_impacts' => [['x' => 1, 'y' => 1]],
        ]);
        $this->audit('missile.impact', $target, $target, 'public', 2, [
            'firing_nation_name' => $firing->name, 'target_nation_name' => $target->name,
            'missile_key' => 'pp_missile', 'effect' => 'land_scorched', 'x' => 13, 'y' => 9,
            'from_terrain_key' => 'wasteland', 'to_terrain_key' => 'scorched',
            'terrain_only' => true, 'terrain_scorched' => true,
        ]);
        $this->audit('missile.ineffective_aggregated', $firing, $firing, 'public', 2, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile',
            'queue_item_id' => 88, 'ineffective_impacts' => 8,
        ]);
        $this->audit('missile.ineffective_aggregated', $firing, $firing, 'public', 2, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile',
            'queue_item_id' => 99, 'ineffective_impacts' => 2,
        ]);
        $this->audit('missile.launch_detail', $firing, $firing, 'private', 2, [
            'command_key' => 'pp_missile', 'queue_item_id' => 88, 'target_x' => 50, 'target_y' => 51,
            'cost_money' => 900, 'fired_shots' => 3,
            'firing_bases' => [
                ['x' => 44, 'y' => 45, 'facility_key' => 'missile_base', 'fired_shots' => 3],
            ],
            'impacts' => [
                ['x' => 12, 'y' => 8, 'effect' => 'capital_damaged', 'meaningful' => true],
                ['x' => 1, 'y' => 1, 'effect' => 'ineffective_sea', 'meaningful' => false],
                ['x' => 13, 'y' => 9, 'effect' => 'killed', 'meaningful' => true, 'terrain_scorched' => true],
                ['x' => 14, 'y' => 10, 'effect' => 'damaged', 'meaningful' => true, 'terrain_scorched' => false],
                ['x' => 15, 'y' => 11, 'effect' => 'killed', 'meaningful' => true, 'terrain_scorched' => false],
            ],
        ]);

        $public = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $publicBody = (string) $public->getContent();
        $publicMessages = $this->messages($public->json('data.groups'));
        $this->assertContains('発射島がPPミサイルを3発発射しました。', $publicMessages);
        $this->assertContains('被弾島(12,8)に発射島のPPミサイルが着弾し、首都人口へ被害を与えました。', $publicMessages);
        $this->assertContains('被弾島(13,9)に発射島のPPミサイルが着弾し、土地を焼け跡にしました。', $publicMessages);
        $this->assertTrue(collect($publicMessages)->contains(
            static fn (string $message): bool => str_contains($message, 'PPミサイルのうち8発は効果がありませんでした。'),
        ));
        $this->assertTrue(collect($publicMessages)->contains(
            static fn (string $message): bool => str_contains($message, 'PPミサイルのうち2発は効果がありませんでした。'),
        ));
        foreach ([
            '(50,51)', '(44,45)', '費用900', 'all_impacts', 'cost_money', 'target_x',
            'firing_bases', 'impacts', 'from_terrain_key', 'to_terrain_key', 'terrain_only',
            'terrain_scorched', 'wasteland', 'scorched',
        ] as $hidden) {
            $this->assertStringNotContainsString($hidden, $publicBody);
        }

        $ownerResponse = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$firing->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.confidential', true);
        $ownerMessages = implode("\n", $this->messages($ownerResponse->json('data.groups')));
        $this->assertStringContainsString('狙点(50,51)', $ownerMessages);
        $this->assertStringContainsString('費用900億円', $ownerMessages);
        $this->assertStringContainsString('発射基地: (44,45)から3発', $ownerMessages);
        $this->assertStringContainsString('(12,8)', $ownerMessages);
        $this->assertStringContainsString('(1,1)', $ownerMessages);
        $this->assertStringContainsString('(13,9): 怪獣を撃破しました（怪獣がいた荒地は焦土化しました）', $ownerMessages);
        $this->assertStringContainsString('(14,10): 怪獣へ命中しました', $ownerMessages);
        $this->assertStringContainsString('(15,11): 怪獣を撃破しました', $ownerMessages);
        $this->assertSame(1, substr_count($ownerMessages, '怪獣がいた荒地は焦土化しました'));
        $ownerTypes = collect($ownerResponse->json('data.groups.0.events'))->pluck('type');
        $this->assertFalse($ownerTypes->contains('missile.launched'));
        $this->assertSame(1, $ownerTypes->filter(
            static fn (string $type): bool => $type === 'missile.ineffective_aggregated',
        )->count());
        $this->assertStringNotContainsString('PPミサイルのうち8発は効果がありませんでした。', $ownerMessages);
        $this->assertStringContainsString('PPミサイルのうち2発は効果がありませんでした。', $ownerMessages);
    }

    public function test_target_owner_gets_three_separate_aggregated_missile_outcome_classes(): void
    {
        [$world, $owner, $target] = $this->nation('防御島');
        [, , $firing] = $this->nation('発射島', $world);
        $world->update(['current_turn' => 7]);
        DB::table('audit_events')->delete();

        foreach ([1, 2, 3] as $x) {
            $this->audit('missile.defense_intercepted', $target, $target, 'nation', 7, [
                'nation_id' => $target->id, 'missile_key' => 'missile', 'x' => $x, 'y' => 4,
                'covering_defense_count' => 2, 'covering_defense_cell_ids' => [901, 902],
            ]);
        }
        $this->audit('secretary.missile_intercepted', $target, $target, 'nation', 7, [
            'nation_id' => $target->id, 'missile_key' => 'pp_missile', 'x' => 5, 'y' => 4,
            'secretary_name' => 'ペリドット', 'secretary_label' => '秘書のペリドット',
        ]);
        $this->audit('missile.ineffective_aggregated', $firing, $firing, 'public', 7, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile',
            'queue_item_id' => 91, 'ineffective_impacts' => 3,
        ]);

        $response = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$target->id}/events")
            ->assertOk();
        $events = collect($response->json('data.groups.0.events'));
        $this->assertSame(1, $events->where('type', 'missile.defense_intercepted')->count());
        $this->assertSame(1, $events->where('type', 'secretary.missile_intercepted')->count());
        $messages = $events->pluck('message')->all();
        $this->assertContains('防衛施設が3発のミサイルを迎撃しました。', $messages);
        $this->assertContains('秘書のペリドットが1発のミサイルを迎撃しました。', $messages);
        $this->assertNotContains('PPミサイルのうち3発は効果がありませんでした。', $messages);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('901', $body);
        $this->assertStringNotContainsString('covering_defense', $body);

        $public = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $publicTypes = collect($public->json('data.groups.0.events'))->pluck('type');
        $this->assertFalse($publicTypes->contains('missile.defense_intercepted'));
        $this->assertFalse($publicTypes->contains('secretary.missile_intercepted'));
        $this->assertTrue($publicTypes->contains('missile.ineffective_aggregated'));
        $this->assertContains(
            'PPミサイルのうち3発は効果がありませんでした。',
            $this->messages($public->json('data.groups')),
        );
    }

    /** @return array{World, User, Nation} */
    private function nation(string $name, ?World $world = null): array
    {
        $world ??= $this->lightweightWorld();
        $user = User::factory()->create();
        $nation = app(NationCreationService::class)->create($user, $world, $name, '試験島主');

        return [$world, $user, $nation];
    }

    private function publicFacility(Nation $nation, int $turn, int $x, int $y, string $facility): int
    {
        return $this->audit('command.facility_built_public', $nation, $nation, 'public', $turn, [
            'nation_name' => $nation->name,
            'facility_key' => $facility,
            'x' => $x,
            'y' => $y,
        ]);
    }

    /** @param array<string, mixed> $metadata */
    private function audit(
        string $eventType,
        Model $subject,
        ?Nation $nation,
        string $visibility,
        int $turn,
        array $metadata,
    ): int {
        return (int) DB::table('audit_events')->insertGetId([
            'actor_user_id' => null,
            'world_id' => $subject instanceof World ? $subject->id : $nation?->world_id,
            'turn' => $turn,
            'nation_id' => $nation?->id,
            'x' => is_numeric($metadata['x'] ?? null) ? (int) $metadata['x'] : null,
            'y' => is_numeric($metadata['y'] ?? null) ? (int) $metadata['y'] : null,
            'message' => null,
            'visibility' => $visibility,
            'event_type' => $eventType,
            'severity' => 'info',
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param list<array<string, mixed>> $groups @return list<int> */
    private function turns(array $groups): array
    {
        return array_map(static fn (array $group): int => (int) $group['target_turn'], $groups);
    }

    /** @param list<array<string, mixed>> $groups @return list<string> */
    private function messages(array $groups): array
    {
        return collect($groups)->flatMap(
            static fn (array $group): array => $group['events'],
        )->pluck('message')->all();
    }
}
