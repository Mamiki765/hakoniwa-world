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
            ->assertJsonPath('data.groups.0.events.0.message', 'ニュース島ができました。');

        $this->assertCount(1, $response->json('data.groups.0.events'));
        $this->assertCount(15, collect($response->json('data.groups'))->flatMap(
            static fn (array $group): array => $group['events'],
        ));
        foreach (['award.granted', 'command.facility_built_public', 'turn.completed', 'secret-seed'] as $hidden) {
            $this->assertStringNotContainsString($hidden, (string) $response->getContent());
        }
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
        $this->assertContains('第一島(1,2)で農場が建設されました。', $messages);
        $this->assertContains('第一島(5,6)で採掘場が建設されました。', $messages);
        $this->assertFalse(collect($messages)->contains(
            static fn (string $message): bool => str_contains($message, '第二島'),
        ));
        $this->assertStringNotContainsString('occurred_at', $body);
        $this->assertStringNotContainsString('coordinate', $body);
    }

    public function test_owner_log_requires_membership_and_never_reuses_public_world_or_other_nation_events(): void
    {
        [$world, $owner, $nation] = $this->nation('所有島');
        [, $otherOwner, $other] = $this->nation('他島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $finance = $this->audit('command.finance', $nation, $nation, 'nation', 2, ['applied' => 50]);
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
            ->assertJsonPath('data.groups.0.events.0.id', $finance)
            ->assertJsonPath('data.groups.0.events.0.confidential', false);

        $body = (string) $response->getContent();
        foreach (['99,999', 'command.facility_built_public', 'message_board.secret_sent', '秘密通信本文', '誤分類されても非表示', 'money_before'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body);
        }
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
        $this->audit('command.seabed_base_built_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'x' => 22, 'y' => 23, 'cost_money' => 800,
        ]);
        $this->audit('command.seabed_base_built_private', $nation, $nation, 'private', 2, [
            'nation_name' => $nation->name, 'x' => 22, 'y' => 23,
        ]);
        $this->audit('command.facility_built_public', $nation, $nation, 'public', 2, [
            'nation_name' => $nation->name, 'facility_key' => 'defense', 'x' => 7, 'y' => 8,
            'actual_facility_key' => 'decoy',
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
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.confidential', true);
        $ownerMessages = $this->messages($ownerResponse->json('data.groups'));
        $this->assertContains('秘密島(22,23)で海底基地を建設しました。', $ownerMessages);
        $this->assertContains('秘密島(12,13)で植林しました。', $ownerMessages);
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
        foreach (['turn.summary', 'resource.automatic_sale', 'metadata'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $publicBody);
        }

        $owner = $this->actingAs($owner)->getJson("/api/v1/nations/{$nation->id}/events")->assertOk();
        $ownerMessages = $this->messages($owner->json('data.groups'));
        $this->assertContains('資産島(4,8)で伐採し、777億円を得ました。', $ownerMessages);
        $this->assertContains('鉱物を300売却し、600億円を得ました。', $ownerMessages);
        $this->assertContains('第2ターンの資源変化', $ownerMessages);
        $this->assertTrue(collect($owner->json('data.groups.0.events'))->contains(
            static fn (array $event): bool => $event['type'] === 'turn.summary'
                && $event['summary']['money']['delta'] === 600,
        ));
    }

    public function test_missile_public_projection_exposes_b10_summary_but_not_private_launch_detail(): void
    {
        [$world, $owner, $firing] = $this->nation('発射島');
        [, , $target] = $this->nation('被弾島', $world);
        $world->update(['current_turn' => 2]);
        DB::table('audit_events')->delete();

        $this->audit('missile.launched', $firing, $firing, 'public', 2, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile', 'fired_shots' => 3,
            'target_x' => 50, 'target_y' => 51, 'cost_money' => 900,
        ]);
        $this->audit('missile.impact', $target, $target, 'public', 2, [
            'firing_nation_name' => $firing->name, 'target_nation_name' => $target->name,
            'missile_key' => 'pp_missile', 'effect' => 'capital_damaged', 'x' => 12, 'y' => 8,
            'cost_money' => 900, 'all_impacts' => [['x' => 1, 'y' => 1]],
        ]);
        $this->audit('missile.ineffective_aggregated', $firing, $firing, 'public', 2, [
            'nation_name' => $firing->name, 'command_key' => 'pp_missile', 'ineffective_impacts' => 8,
        ]);
        $this->audit('missile.launch_detail', $firing, $firing, 'private', 2, [
            'command_key' => 'pp_missile', 'target_x' => 50, 'target_y' => 51,
            'cost_money' => 900, 'fired_shots' => 3,
            'impacts' => [
                ['x' => 12, 'y' => 8, 'effect' => 'capital_damaged', 'meaningful' => true],
                ['x' => 1, 'y' => 1, 'effect' => 'ineffective_sea', 'meaningful' => false],
            ],
        ]);

        $public = $this->getJson("/api/v1/public/worlds/{$world->id}/events")->assertOk();
        $publicBody = (string) $public->getContent();
        $publicMessages = $this->messages($public->json('data.groups'));
        $this->assertContains('発射島がPPミサイルを3発発射しました。', $publicMessages);
        $this->assertContains('被弾島(12,8)に発射島のPPミサイルが着弾し、首都人口へ被害を与えました。', $publicMessages);
        $this->assertTrue(collect($publicMessages)->contains(
            static fn (string $message): bool => str_contains($message, '効果のない着弾8件はまとめて記録されました。'),
        ));
        foreach (['(50,51)', '費用900', 'all_impacts', 'cost_money', 'target_x', 'impacts'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $publicBody);
        }

        $ownerResponse = $this->actingAs($owner)
            ->getJson("/api/v1/nations/{$firing->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.confidential', true);
        $ownerMessages = implode("\n", $this->messages($ownerResponse->json('data.groups')));
        $this->assertStringContainsString('狙点(50,51)', $ownerMessages);
        $this->assertStringContainsString('費用900億円', $ownerMessages);
        $this->assertStringContainsString('(12,8)', $ownerMessages);
        $this->assertStringContainsString('(1,1)', $ownerMessages);
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
