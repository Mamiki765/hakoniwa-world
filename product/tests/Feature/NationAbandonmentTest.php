<?php

namespace Tests\Feature;

use App\Application\CapitalPlacementService;
use App\Application\MessageBoardService;
use App\Application\NationAbandonmentService;
use App\Application\NationCreationService;
use App\Application\NationProfileService;
use App\Application\SalePolicyService;
use App\Application\WorldExpansionService;
use App\Domain\Economy\SalePolicy;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\MessageBoard\MessageBoardValidationException;
use App\Domain\World\MapBounds;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\IslandMessage;
use App\Models\MapCell;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\MonumentDefinition;
use App\Models\Nation;
use App\Models\NationAward;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationMonsterKillStat;
use App\Models\NationResourceSalePolicy;
use App\Models\ResourceDefinition;
use App\Models\Ship;
use App\Models\TerrainDefinition;
use App\Models\User;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\SyntheticHistoricalRulesetSnapshot;
use Tests\TestCase;

final class NationAbandonmentTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_only_the_owner_with_the_exact_locked_name_can_abandon_once(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $outsider = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '確認島', '確認島主');
        $otherNation = app(NationCreationService::class)->create($otherOwner, $world, '別島', '別島主');
        $endpoint = "/api/v1/nations/{$nation->id}/abandon";

        $this->postJson($endpoint, ['confirmation_name' => $nation->name])->assertUnauthorized();
        $this->actingAs($outsider)->postJson($endpoint, ['confirmation_name' => $nation->name])->assertForbidden();
        $this->actingAs($otherOwner)->postJson($endpoint, ['confirmation_name' => $nation->name])->assertForbidden();
        $this->actingAs($owner)->postJson("/api/v1/nations/{$otherNation->id}/abandon", [
            'confirmation_name' => $otherNation->name,
        ])->assertForbidden();

        foreach (['確認', '確認島 ', '確認しま', ''] as $confirmation) {
            $this->actingAs($owner)->postJson($endpoint, ['confirmation_name' => $confirmation])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('confirmation_name');
        }
        $this->assertSame('active', $nation->fresh()->state);

        $currentRulesetId = $world->ruleset_version_id;
        $historical = SyntheticHistoricalRulesetSnapshot::create('historical-abandonment-snapshot-v15', 15);
        $world->update(['ruleset_version_id' => $historical->id]);
        $this->actingAs($owner)->postJson($endpoint, ['confirmation_name' => $nation->name])
            ->assertConflict()
            ->assertJsonPath('code', 'reset_required');
        $this->assertSame('active', $nation->fresh()->state);
        $world->update(['ruleset_version_id' => $currentRulesetId]);

        $this->actingAs($owner)->postJson($endpoint, ['confirmation_name' => $nation->name])
            ->assertOk()
            ->assertJsonPath('data.state', 'abandoned');
        $this->actingAs($owner)->postJson($endpoint, ['confirmation_name' => $nation->name])
            ->assertConflict()
            ->assertJsonPath('code', 'nation_not_active');
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.abandoned')->count());
    }

    public function test_abandonment_atomically_resets_the_exact_surface_union_and_preserves_history(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '沈降島', '沈降島主');
        $otherNation = app(NationCreationService::class)->create($otherOwner, $world, '保護島', '保護島主');
        $surface = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail();
        $center = new GridCoordinate($capital->x, $capital->y);
        $cells = MapCell::query()->where('map_space_id', $surface->id)->orderBy('id')->get();

        $ownedInside = $cells->first(fn (MapCell $cell): bool => $cell->owner_nation_id === $nation->id
            && $cell->id !== $capital->map_cell_id && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= 5);
        $ownedOutside = $cells->first(fn (MapCell $cell): bool => $cell->owner_nation_id === null
            && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) > 5);
        $neutralInside = $cells->first(fn (MapCell $cell): bool => $cell->owner_nation_id === null
            && $cell->id !== $ownedOutside?->id
            && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= 5);
        $otherInside = $cells->first(fn (MapCell $cell): bool => $cell->owner_nation_id === null
            && ! in_array($cell->id, [$ownedOutside?->id, $neutralInside?->id], true)
            && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= 5);
        $neutralOutside = $cells->first(fn (MapCell $cell): bool => $cell->owner_nation_id === null
            && ! in_array($cell->id, [$ownedOutside?->id, $neutralInside?->id, $otherInside?->id], true)
            && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) > 5);
        foreach ([$ownedInside, $ownedOutside, $neutralInside, $otherInside, $neutralOutside] as $fixture) {
            $this->assertInstanceOf(MapCell::class, $fixture);
        }
        assert($ownedInside instanceof MapCell);
        assert($ownedOutside instanceof MapCell);
        assert($neutralInside instanceof MapCell);
        assert($otherInside instanceof MapCell);
        assert($neutralOutside instanceof MapCell);

        $this->setRichCell($ownedInside, $nation->id, 'forest', 'farm', 1200);
        $this->setRichCell($ownedOutside, $nation->id, 'forest', 'missile_base', 1300);
        $this->setRichCell($neutralInside, null, 'forest', 'monument', 1400);
        $this->setRichCell($otherInside, $otherNation->id, 'forest', 'farm', 1500);
        $this->setRichCell($neutralOutside, null, 'forest', 'farm', 1600);

        $affectedOwnedMonster = $this->monsterAt($world->id, $world->ruleset_version_id, $ownedOutside);
        $affectedNeutralMonster = $this->monsterAt($world->id, $world->ruleset_version_id, $neutralInside);
        $protectedOtherMonster = $this->monsterAt($world->id, $world->ruleset_version_id, $otherInside);
        $unaffectedMonster = $this->monsterAt($world->id, $world->ruleset_version_id, $neutralOutside);
        $shipCell = MapCell::query()->where('map_space_id', $surface->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereNotIn('id', [
                $ownedInside->id, $ownedOutside->id, $neutralInside->id, $otherInside->id, $neutralOutside->id,
            ])
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->firstOrFail();
        $ship = Ship::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'map_cell_id' => $shipCell->id,
            'ship_type_key' => 'fishing',
            'current_hp' => 1,
            'max_hp' => 1,
            'state' => Ship::STATE_ACTIVE,
            'version' => 1,
        ]);

        $membership = NationMembership::query()->where('nation_id', $nation->id)->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $surface->id,
            'version' => 1,
        ]);
        NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)->orderBy('id')->valueOrFail('id'),
            'queue_position' => 1,
            'target_x' => $ownedInside->x,
            'target_y' => $ownedInside->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
        ]);
        $nation->resourceBalances()->update(['amount' => 42]);
        $nation->update(['money' => 777, 'idle_counter' => 55]);
        NationAward::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'award_key' => 'award.peace',
            'awarded_turn' => 1,
            'award_occurrence_key' => 'once',
        ]);
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)->where('key', 'inora')->firstOrFail();
        NationMonsterKillStat::query()->create([
            'world_id' => $world->id,
            'nation_id' => $nation->id,
            'monster_definition_id' => $definition->id,
            'kill_count' => 1,
            'first_killed_turn' => 1,
            'last_killed_turn' => 1,
            'version' => 1,
        ]);
        app(MessageBoardService::class)->postPublic($owner, $nation, '破棄前の歴史メッセージ');
        $islandMessageId = IslandMessage::query()->where('target_nation_id', $nation->id)->valueOrFail('id');

        $beforeCells = MapCell::query()->where('map_space_id', $surface->id)->orderBy('id')->get();
        $expectedAffected = $beforeCells->filter(fn (MapCell $cell): bool => $cell->owner_nation_id === $nation->id
            || ($cell->owner_nation_id === null && $center->distanceTo(new GridCoordinate($cell->x, $cell->y)) <= 5));
        $expectedOwnedCount = $expectedAffected->where('owner_nation_id', $nation->id)->count();
        $expectedNeutralCount = $expectedAffected->whereNull('owner_nation_id')->count();
        $cellVersions = $expectedAffected->pluck('version', 'id');
        $affectedChunkIds = $expectedAffected->pluck('map_chunk_id')->unique()->sort()->values();
        $chunkVersions = DB::table('map_chunks')->pluck('version', 'id');
        $protectedOther = $otherInside->fresh()->only($this->cellStateFields());
        $protectedNeutral = $neutralOutside->fresh()->only($this->cellStateFields());
        $createdEventId = DB::table('audit_events')->where('event_type', 'nation.created')
            ->where('nation_id', $nation->id)->value('id');

        $response = $this->actingAs($owner)
            ->postJson("/api/v1/nations/{$nation->id}/abandon", ['confirmation_name' => $nation->name])
            ->assertOk()
            ->assertJsonPath('data.owned_cell_count', $expectedOwnedCount)
            ->assertJsonPath('data.neutral_cleanup_cell_count', $expectedNeutralCount)
            ->assertJsonPath('data.monster_removed_count', 2)
            ->assertJsonPath('data.ship_removed_count', 1)
            ->assertJsonPath('data.changed_chunk_count', $affectedChunkIds->count());
        $this->assertNotNull($response->json('data'));

        foreach ($expectedAffected->modelKeys() as $cellId) {
            $cell = MapCell::query()->with('terrain')->findOrFail($cellId);
            $this->assertSame('sea', $cell->terrain->key);
            $this->assertNull($cell->owner_nation_id);
            $this->assertSame(0, $cell->population);
            $this->assertNull($cell->facility_definition_id);
            $this->assertNull($cell->monument_definition_id);
            $this->assertNull($cell->terrain_quantity);
            $this->assertNull($cell->facility_scale);
            $this->assertNull($cell->facility_experience);
            $this->assertNull($cell->facility_operational_state);
            $this->assertSame((int) $cellVersions->get($cellId) + 1, $cell->version);
        }
        $this->assertSame($protectedOther, $otherInside->fresh()->only($this->cellStateFields()));
        $this->assertSame($protectedNeutral, $neutralOutside->fresh()->only($this->cellStateFields()));
        foreach ($chunkVersions as $chunkId => $beforeVersion) {
            $expected = (int) $beforeVersion + ($affectedChunkIds->contains((int) $chunkId) ? 1 : 0);
            $this->assertSame($expected, (int) DB::table('map_chunks')->where('id', $chunkId)->value('version'));
        }

        $this->assertSame('removed', $affectedOwnedMonster->fresh()->state);
        $this->assertSame('nation_abandoned', $affectedOwnedMonster->fresh()->removal_reason);
        $this->assertSame('removed', $affectedNeutralMonster->fresh()->state);
        $this->assertDatabaseMissing('monster_occupancies', ['monster_instance_id' => $affectedOwnedMonster->id]);
        $this->assertDatabaseMissing('monster_occupancies', ['monster_instance_id' => $affectedNeutralMonster->id]);
        $this->assertSame('alive', $unaffectedMonster->fresh()->state);
        $this->assertDatabaseHas('monster_occupancies', ['monster_instance_id' => $unaffectedMonster->id]);
        $this->assertSame('alive', $protectedOtherMonster->fresh()->state);
        $this->assertDatabaseHas('monster_occupancies', ['monster_instance_id' => $protectedOtherMonster->id]);
        $this->assertSame(Ship::STATE_REMOVED, $ship->fresh()->state);
        $this->assertNull($ship->fresh()->map_cell_id);
        $this->assertSame('nation_abandoned', $ship->fresh()->removal_reason);
        $this->assertNotNull($ship->fresh()->removed_at);

        $archived = Nation::query()->findOrFail($nation->id);
        $this->assertSame('abandoned', $archived->state);
        $this->assertSame(0, $archived->money);
        $this->assertSame(0, $archived->idle_counter);
        $this->assertSame(0, $archived->resourceBalances()->sum('amount'));
        $this->assertDatabaseMissing('nation_command_queues', ['nation_id' => $archived->id]);
        $this->assertDatabaseMissing('nation_capitals', ['nation_id' => $archived->id]);
        $this->assertDatabaseMissing('nation_memberships', ['nation_id' => $archived->id]);
        $this->assertSame(0, NationResourceSalePolicy::query()->where('nation_id', $archived->id)->count());
        $this->assertDatabaseHas('nation_awards', ['nation_id' => $archived->id, 'award_key' => 'award.peace']);
        $this->assertDatabaseHas('nation_monster_kill_stats', ['nation_id' => $archived->id, 'kill_count' => 1]);
        $this->assertDatabaseHas('island_messages', ['id' => $islandMessageId, 'target_nation_id' => $archived->id]);
        $this->assertDatabaseHas('audit_events', ['id' => $createdEventId, 'nation_id' => $archived->id]);
        $this->actingAs($owner)->getJson('/api/v1/me/nation')->assertOk()->assertJsonPath('data', null);
        $this->postJson("/api/v1/nations/{$archived->id}/map-spaces/{$surface->id}/command-queue", [
            'command_key' => 'land_clear',
            'target_x' => $capital->x,
            'target_y' => $capital->y,
            'request_key' => (string) Str::uuid(),
            'expected_version' => 1,
        ])->assertForbidden();
        $this->assertDatabaseMissing('nation_command_queues', ['nation_id' => $archived->id]);

        try {
            app(NationProfileService::class)->update($owner, $nation, ['owner_name' => '破棄後改変']);
            $this->fail('A stale active model must not update an abandoned Nation profile.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame('沈降島主', $archived->fresh()->owner_name);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.profile_updated')
            ->where('subject_id', $archived->id)->count());

        $tradable = ResourceDefinition::query()->where('tradable', true)->orderBy('id')->firstOrFail();
        try {
            app(SalePolicyService::class)->update(
                $owner,
                $nation,
                $tradable,
                SalePolicy::KeepAmount->value,
                0,
                1,
            );
            $this->fail('A stale active model must not recreate an abandoned Nation sale policy.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame(0, NationResourceSalePolicy::query()->where('nation_id', $archived->id)->count());

        try {
            app(MessageBoardService::class)->postPublic($owner, $nation, '破棄後の不正投稿');
            $this->fail('An abandoned Nation board must not accept a stale direct-service post.');
        } catch (MessageBoardValidationException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame(1, IslandMessage::query()->where('target_nation_id', $archived->id)->count());

        $event = DB::table('audit_events')->where('event_type', 'nation.abandoned')->sole();
        $metadata = json_decode((string) $event->metadata, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('沈降島は破棄され、忘れ去られた。', $event->message);
        $this->assertSame($nation->id, $metadata['nation_id']);
        $this->assertSame($nation->nation_number, $metadata['nation_number']);
        $this->assertSame('沈降島', $metadata['nation_name']);
        $this->assertSame($owner->id, $metadata['actor_user_id']);
        $this->assertSame($world->id, $metadata['world_id']);
        $this->assertSame($world->current_turn, $metadata['target_turn']);
        $this->assertSame($capital->map_cell_id, $metadata['old_capital_map_cell_id']);
        $this->assertSame($capital->x, $metadata['old_capital_x']);
        $this->assertSame($capital->y, $metadata['old_capital_y']);
        $this->assertSame($expectedOwnedCount, $metadata['affected_owned_cell_count']);
        $this->assertSame($expectedNeutralCount, $metadata['affected_neutral_cleanup_cell_count']);
        $this->assertSame(1, $metadata['removed_ship_count']);

        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()->assertJsonPath('data.nation_count', 1);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonMissing(['id' => $archived->id]);
        $this->getJson("/api/v1/public/nations/{$archived->id}")->assertNotFound();
        $this->getJson("/api/v1/public/nations/{$archived->id}/map-spaces/{$surface->id}/chunks/0/0")
            ->assertNotFound();
        $this->getJson("/api/v1/nations/{$archived->id}/message-board")->assertNotFound();
        $this->getJson("/api/v1/public/nations/{$archived->id}/events")->assertOk();
        $this->getJson("/api/v1/public/worlds/{$world->id}/major-news")
            ->assertOk()
            ->assertJsonPath('data.groups.0.events.0.type', 'nation.abandoned')
            ->assertJsonPath('data.groups.0.events.0.message', '沈降島は破棄され、忘れ去られた。');
    }

    public function test_same_user_can_create_a_new_named_nation_while_old_requests_and_numbers_remain_historical(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $oldRequestKey = (string) Str::uuid();
        $newRequestKey = (string) Str::uuid();
        $service = app(NationCreationService::class);
        $first = $service->create($owner, $world, '初代島', '初代島主', '', $oldRequestKey);

        app(NationAbandonmentService::class)->abandon($owner, $first, $first->name);
        $replayed = $service->create($owner, $world->fresh(), '別の入力', '別の入力', '', $oldRequestKey);
        $this->assertSame($first->id, $replayed->id);
        $this->assertSame('abandoned', $replayed->state);

        try {
            $service->create($owner, $world->fresh(), '初代島', '再利用島主', '', (string) Str::uuid());
            $this->fail('An abandoned Nation name must remain reserved in its World.');
        } catch (DomainException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $second = $service->create($owner, $world->fresh(), '二代目島', '二代目島主', '', $newRequestKey);
        $replayedAfterSecond = $service->create($owner, $world->fresh(), 'さらに別の入力', 'さらに別の入力', '', $oldRequestKey);
        $this->assertSame($first->id, $replayedAfterSecond->id);
        $this->assertSame('abandoned', $replayedAfterSecond->state);
        $this->assertSame('abandoned', $first->fresh()->state);
        $this->assertSame('active', $second->state);
        $this->assertNotNull($second->capital);
        $this->assertGreaterThan($first->nation_number, $second->nation_number);
        $this->assertDatabaseHas('nation_memberships', [
            'user_id' => $owner->id,
            'world_id' => $world->id,
            'nation_id' => $second->id,
        ]);
        $requests = DB::table('nation_creation_requests')
            ->where('user_id', $owner->id)->where('world_id', $world->id)->orderBy('id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame([$oldRequestKey, $newRequestKey], $requests->pluck('request_key')->all());
        $this->assertSame([$first->id, $second->id], $requests->pluck('nation_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['completed', 'completed'], $requests->pluck('status')->all());
    }

    public function test_negative_coordinate_capital_uses_the_same_radius_cleanup_contract(): void
    {
        $world = $this->lightweightWorld();
        $before = $this->boundsFor($world);
        app(WorldExpansionService::class)->expand(
            $world,
            $before,
            new MapBounds(-16, $before->maxX, -16, $before->maxY, $before->chunkSize),
        );
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world->fresh(), '負座標島', '負座標島主');
        $surface = $this->surfaceMapSpace($world);
        $negativeCapitalCell = MapCell::query()->where('map_space_id', $surface->id)
            ->where('x', -8)->where('y', -8)->firstOrFail();
        $neutralInside = MapCell::query()->where('map_space_id', $surface->id)
            ->where('x', -7)->where('y', -8)->firstOrFail();
        $neutralOutside = MapCell::query()->where('map_space_id', $surface->id)
            ->where('x', -16)->where('y', -16)->firstOrFail();
        $this->setRichCell($negativeCapitalCell, $nation->id, 'plain', 'capital', 1000);
        $this->setRichCell($neutralInside, null, 'forest', 'farm', 500);
        $this->setRichCell($neutralOutside, null, 'forest', 'farm', 600);
        $nation->capital()->firstOrFail()->update([
            'map_cell_id' => $negativeCapitalCell->id,
            'x' => $negativeCapitalCell->x,
            'y' => $negativeCapitalCell->y,
        ]);
        $outsideBefore = $neutralOutside->fresh()->only($this->cellStateFields());

        app(NationAbandonmentService::class)->abandon($owner, $nation, $nation->name);

        $this->assertSame('sea', $negativeCapitalCell->fresh('terrain')->terrain->key);
        $this->assertSame('sea', $neutralInside->fresh('terrain')->terrain->key);
        $this->assertSame($outsideBefore, $neutralOutside->fresh()->only($this->cellStateFields()));
    }

    public function test_old_capital_no_longer_blocks_a_new_placement_candidate(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '配置解除島', '配置解除島主');
        $surface = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail();

        app(NationAbandonmentService::class)->abandon($owner, $nation, $nation->name);
        $candidates = app(CapitalPlacementService::class)->candidates($surface->fresh(), 10_000);

        $this->assertContains(
            [$capital->x, $capital->y],
            array_map(static fn (GridCoordinate $candidate): array => [$candidate->x, $candidate->y], $candidates),
        );
    }

    public function test_event_insert_failure_rolls_back_every_abandonment_mutation(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '巻戻島', '巻戻島主');
        $capital = $nation->capital()->firstOrFail();
        $surface = $this->surfaceMapSpace($world);
        $capitalCell = MapCell::query()->findOrFail($capital->map_cell_id);
        $capitalCellBefore = $capitalCell->getAttributes();
        $monsterCell = MapCell::query()
            ->where('map_space_id', $surface->id)
            ->where('owner_nation_id', $nation->id)
            ->where('id', '!=', $capitalCell->id)
            ->firstOrFail();
        $monsterCellBefore = $monsterCell->getAttributes();
        $membership = NationMembership::query()->where('nation_id', $nation->id)->firstOrFail();
        $queue = NationCommandQueue::query()->create([
            'nation_id' => $nation->id,
            'map_space_id' => $surface->id,
            'version' => 1,
        ]);
        $queueItem = NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)->orderBy('id')->valueOrFail('id'),
            'queue_position' => 1,
            'target_x' => $capital->x,
            'target_y' => $capital->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
        ]);
        $nation->resourceBalances()->update(['amount' => 73]);
        $resourceAmountsBefore = $nation->resourceBalances()->pluck('amount', 'id')->all();
        $tradable = ResourceDefinition::query()->where('tradable', true)->orderBy('id')->firstOrFail();
        $salePolicy = NationResourceSalePolicy::query()
            ->where('nation_id', $nation->id)
            ->where('resource_definition_id', $tradable->id)
            ->firstOrFail();
        $salePolicy->update([
            'policy' => SalePolicy::KeepAmount->value,
            'keep_amount' => 12,
            'version' => $salePolicy->version + 1,
        ]);
        $monster = $this->monsterAt($world->id, $world->ruleset_version_id, $monsterCell);

        DB::unprepared(<<<'SQL'
CREATE FUNCTION test_reject_nation_abandonment_event() RETURNS trigger AS $$
BEGIN
    IF NEW.event_type = 'nation.abandoned' THEN
        RAISE EXCEPTION 'test abandonment event failure';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER test_reject_nation_abandonment_event
BEFORE INSERT ON audit_events
FOR EACH ROW EXECUTE FUNCTION test_reject_nation_abandonment_event();
SQL);

        try {
            app(NationAbandonmentService::class)->abandon($owner, $nation, $nation->name);
            $this->fail('The forced event failure must abort abandonment.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('test abandonment event failure', $exception->getMessage());
        } finally {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS test_reject_nation_abandonment_event ON audit_events;
DROP FUNCTION IF EXISTS test_reject_nation_abandonment_event();
SQL);
        }

        $this->assertSame('active', $nation->fresh()->state);
        $this->assertDatabaseHas('nation_memberships', ['nation_id' => $nation->id, 'user_id' => $owner->id]);
        $this->assertDatabaseHas('nation_capitals', ['nation_id' => $nation->id, 'map_cell_id' => $capital->map_cell_id]);
        $this->assertSame($capitalCellBefore, MapCell::query()->findOrFail($capital->map_cell_id)->getAttributes());
        $this->assertSame($monsterCellBefore, MapCell::query()->findOrFail($monsterCell->id)->getAttributes());
        $this->assertDatabaseHas('nation_command_queues', ['id' => $queue->id, 'nation_id' => $nation->id]);
        $this->assertDatabaseHas('nation_command_queue_items', ['id' => $queueItem->id, 'status' => 'queued']);
        $this->assertSame($resourceAmountsBefore, $nation->resourceBalances()->pluck('amount', 'id')->all());
        $this->assertDatabaseHas('nation_resource_sale_policies', [
            'id' => $salePolicy->id,
            'policy' => SalePolicy::KeepAmount->value,
            'keep_amount' => 12,
        ]);
        $this->assertSame('alive', $monster->fresh()->state);
        $this->assertDatabaseHas('monster_occupancies', [
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $monsterCell->id,
        ]);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'nation.abandoned')->count());
    }

    private function setRichCell(
        MapCell $cell,
        ?int $ownerNationId,
        string $terrainKey,
        string $facilityKey,
        int $population,
    ): void {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', $terrainKey)->firstOrFail());
        $states->setFacility($cell, FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail());
        if ($facilityKey === 'monument') {
            $cell->monument_definition_id = MonumentDefinition::query()->orderBy('id')->valueOrFail('id');
        }
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = $population;
        $cell->version++;
        $cell->save();
    }

    private function monsterAt(int $worldId, int $rulesetId, MapCell $cell): MonsterInstance
    {
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $rulesetId)
            ->where('key', 'inora')
            ->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $worldId,
            'monster_definition_id' => $definition->id,
            'current_hp' => $definition->base_hp,
            'spawned_max_hp' => $definition->base_hp,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster;
    }

    /** @return list<string> */
    private function cellStateFields(): array
    {
        return [
            'terrain_definition_id', 'facility_definition_id', 'monument_definition_id',
            'owner_nation_id', 'population', 'terrain_quantity', 'facility_scale',
            'facility_experience', 'facility_operational_state', 'version',
        ];
    }
}
