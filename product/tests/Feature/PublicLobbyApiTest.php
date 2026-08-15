<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Map\NationLandAreaCalculator;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\Nation;
use App\Models\NationAward;
use App\Models\TerrainDefinition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

class PublicLobbyApiTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_guest_can_read_world_summary_rankings_and_safe_public_events(): void
    {
        $world = $this->lightweightWorld();
        $firstUser = User::factory()->create();
        $first = app(NationCreationService::class)->create($firstUser, $world, '第一国', '第一島主', '第一コメント');
        $second = app(NationCreationService::class)->create(
            User::factory()->create(), $world, '第二国', '第二島主', '第二コメント',
        );

        MapCell::query()->whereIn('owner_nation_id', [$first->id, $second->id])->update(['population' => 0]);
        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        MapCell::query()->where('owner_nation_id', $second->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        $neutral = MapCell::query()->whereNull('owner_nation_id')->firstOrFail();
        $neutral->update(['owner_nation_id' => $second->id]);
        $first->update(['money' => 62728]);
        $second->update(['money' => 700]);
        $this->placeScaleFacilities($second);
        $secondArea = app(NationLandAreaCalculator::class)->forNation($second);
        foreach ([100, 200] as $turn) {
            NationAward::query()->create([
                'world_id' => $world->id,
                'nation_id' => $second->id,
                'award_key' => 'award.turn',
                'awarded_turn' => $turn,
                'award_occurrence_key' => "turn:{$turn}",
            ]);
        }
        NationAward::query()->create([
            'world_id' => $world->id,
            'nation_id' => $second->id,
            'award_key' => 'award.prosperity',
            'awarded_turn' => 50,
            'award_occurrence_key' => 'once',
        ]);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $inora = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'inora')->firstOrFail();
        $mecha = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', 'mecha_inora')->firstOrFail();
        foreach ([$inora, $mecha] as $definition) {
            DB::table('nation_monster_kill_stats')->insert([
                'world_id' => $world->id,
                'nation_id' => $second->id,
                'monster_definition_id' => $definition->id,
                'kill_count' => 1,
                'first_killed_turn' => 10,
                'last_killed_turn' => 10,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $summary = $this->getJson("/api/v1/public/worlds/{$world->id}/summary")
            ->assertOk()
            ->assertJsonPath('data.current_turn', 1)
            ->assertJsonPath('data.nation_count', 2)
            ->assertJsonPath('data.total_population', 2000);
        $this->assertStringContainsString('public', (string) $summary->headers->get('Cache-Control'));

        $ranking = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.nation_number', 2)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.nation_number', 1)
            ->assertJsonPath('data.0.owner_name', '第二島主')
            ->assertJsonPath('data.0.comment', '第二コメント')
            ->assertJsonPath('data.1.owner_name', '第一島主')
            ->assertJsonPath('data.0.achievements.awards.0.key', 'award.turn')
            ->assertJsonPath('data.0.achievements.awards.0.count', 2)
            ->assertJsonPath('data.0.achievements.awards.0.awarded_turns', [100, 200])
            ->assertJsonPath('data.0.achievements.awards.1.key', 'award.prosperity')
            ->assertJsonPath('data.0.achievements.monster_kills.total_count', 2)
            ->assertJsonPath('data.0.achievements.monster_kills.species.0.key', 'mecha_inora')
            ->assertJsonPath('data.0.achievements.monster_kills.species.0.kill_count', 1)
            ->assertJsonPath('data.0.achievements.monster_kills.species.1.key', 'inora')
            ->assertJsonPath('data.0.achievements.monster_kills.asset.key', 'hakoniwa_original.monster.inora')
            ->assertJsonPath('data.1.achievements.awards', [])
            ->assertJsonPath('data.1.achievements.monster_kills', null)
            ->assertJsonPath('data.0.money_display', '約500億円')
            ->assertJsonPath('data.0.money_bucket', '500')
            ->assertJsonPath('data.0.food_total_tons', 10000)
            ->assertJsonPath('data.0.owned_land_cells', $secondArea)
            ->assertJsonPath('data.0.farm_capacity_people', 10000)
            ->assertJsonPath('data.0.factory_capacity_people', 30000)
            ->assertJsonPath('data.0.mine_capacity_people', 5000)
            ->assertJsonPath('data.1.money_display', '約62,000億円')
            ->assertJsonPath('data.1.money_bucket', '62000')
            ->assertJsonPath('data.0.survival_turns', 0)
            ->assertJsonPath('data.0.finance_only_turns', 100)
            ->assertJsonPath('data.0.activity_status', 'finance_only');
        $rankingBody = $ranking->getContent();
        $this->assertStringNotContainsString('"money":', $rankingBody);
        $this->assertStringNotContainsString('62728', $rankingBody);
        foreach (['food_resources', 'resources', 'food_capacity_tons', 'wheat', 'fish', 'monster_meat'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $rankingBody);
        }
        $this->assertStringNotContainsString('source_metadata', $rankingBody);
        $this->getJson("/api/v1/public/nations/{$second->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.achievements');

        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1500]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id);

        MapCell::query()->where('owner_nation_id', $first->id)->orderBy('id')->firstOrFail()->update(['population' => 1000]);
        $neutral->update(['owner_nation_id' => null]);
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()
            ->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.1.id', $second->id);

        DB::table('audit_events')->insert([
            'actor_user_id' => null,
            'world_id' => $world->id,
            'turn' => 1,
            'nation_id' => $first->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'public',
            'event_type' => 'disaster.triggered',
            'severity' => 'warning',
            'subject_type' => $world->getMorphClass(),
            'subject_id' => $world->getKey(),
            'metadata' => json_encode([
                'world_id' => $world->id,
                'target_turn' => 2,
                'disaster_key' => 'earthquake',
                'center_x' => 30,
                'center_y' => 31,
                'draw' => 17,
                'numerator' => 80,
                'denominator' => 2_000,
                'random_seed' => 'must-not-leak',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now()->addSecond(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $events = $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.anchor_turn', 1)
            ->assertJsonPath('data.groups.0.target_turn', 1)
            ->assertJsonPath('data.groups.0.events.0.type', 'disaster.triggered')
            ->assertJsonPath('data.groups.0.events.0.message', '地震発生！！ 震源地は(30,31)地点！！')
            ->assertJsonStructure(['data' => ['groups' => [['target_turn', 'events' => [
                ['id', 'type', 'message', 'importance', 'target_turn'],
            ]]]]]);
        $eventsBody = $events->getContent();
        $this->assertStringNotContainsString('occurred_at', $eventsBody);
        $this->assertStringNotContainsString('metadata', $eventsBody);
        $this->assertStringNotContainsString('"draw"', $eventsBody);
        $this->assertStringNotContainsString('"numerator"', $eventsBody);
        $this->assertStringNotContainsString('"denominator"', $eventsBody);
        $this->assertStringNotContainsString('must-not-leak', $eventsBody);
        $this->assertStringNotContainsString('stack', $eventsBody);
        $this->assertStringNotContainsString('exception', $eventsBody);

        $private = $this->actingAs($firstUser)->getJson('/api/v1/me/nation')
            ->assertOk()
            ->assertJsonPath('data.money', 62728)
            ->assertJsonPath('data.money_display', '62,728億円')
            ->assertHeader('Vary', 'Cookie');
        $this->assertStringContainsString('no-store', (string) $private->headers->get('Cache-Control'));
    }

    public function test_public_events_have_an_empty_boundary_when_nothing_is_publishable(): void
    {
        $world = $this->lightweightWorld();

        $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', [])
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.anchor_turn', 1)
            ->assertJsonPath('data.has_older_page', false);
    }

    public function test_public_news_never_projects_nation_private_or_admin_events(): void
    {
        $world = $this->lightweightWorld();
        foreach ([
            ['visibility' => 'public', 'event_type' => 'turn.completed', 'secret' => 'public-safe'],
            ['visibility' => 'public', 'event_type' => 'missile.launch_detail', 'secret' => 'misclassified-secret'],
            ['visibility' => 'nation', 'event_type' => 'missile.launch_detail', 'secret' => 'nation-secret'],
            ['visibility' => 'private', 'event_type' => 'missile.launch_detail', 'secret' => 'private-secret'],
            ['visibility' => 'admin', 'event_type' => 'missile.launch_detail', 'secret' => 'admin-secret'],
        ] as $event) {
            DB::table('audit_events')->insert([
                'actor_user_id' => null,
                'world_id' => $world->id,
                'turn' => 1,
                'nation_id' => null,
                'x' => null,
                'y' => null,
                'message' => null,
                'visibility' => $event['visibility'],
                'event_type' => $event['event_type'],
                'severity' => 'info',
                'subject_type' => $world->getMorphClass(),
                'subject_id' => $world->id,
                'metadata' => json_encode([
                    'target_turn' => 1,
                    'target_x' => 12,
                    'target_y' => 13,
                    'secret' => $event['secret'],
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);
        foreach (['public-safe', 'misclassified-secret', 'nation-secret', 'private-secret', 'admin-secret', 'target_x', 'target_y', 'metadata'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_public_monster_reward_event_is_hidden_when_killer_and_host_differ(): void
    {
        $this->assertPublicMonsterRewardHidden(987_654_321, 876_543_210);
    }

    public function test_public_monster_reward_event_is_hidden_when_killer_and_host_are_same(): void
    {
        $this->assertPublicMonsterRewardHidden(987_654_321, 987_654_321);
    }

    public function test_guest_nation_preview_uses_viewer_safe_cells_and_never_leaks_exact_money(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create(
            $owner, $world, '秘匿国', '秘匿島主', '公開コメント',
        );
        $nation->update(['money' => 62728]);
        $this->placeScaleFacilities($nation);
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $base = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('facility', fn ($query) => $query->where('key', 'missile_base'))->firstOrFail();
        $forest = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereHas('terrain', fn ($query) => $query->where('key', 'forest'))->firstOrFail();

        $nationResponse = $this->getJson("/api/v1/public/nations/{$nation->id}")
            ->assertOk()
            ->assertJsonPath('data.capital.x', $nation->capital()->value('x'))
            ->assertJsonPath('data.map_space.id', $mapSpace->id)
            ->assertJsonPath('data.map_space.bounds_revision', $mapSpace->boundsRevision())
            ->assertJsonPath('data.map_space.bounds.max_x', $mapSpace->max_x)
            ->assertJsonPath('data.money_display', '約62,000億円')
            ->assertJsonPath('data.food_total_tons', 10000)
            ->assertJsonPath('data.owned_land_cells', app(NationLandAreaCalculator::class)->forNation($nation))
            ->assertJsonPath('data.farm_capacity_people', 10000)
            ->assertJsonPath('data.factory_capacity_people', 30000)
            ->assertJsonPath('data.mine_capacity_people', 5000)
            ->assertJsonPath('data.owner_name', '秘匿島主')
            ->assertJsonPath('data.comment', '公開コメント');
        $this->assertStringNotContainsString('62728', $nationResponse->getContent());
        $this->assertStringNotContainsString('total_food_tons', $nationResponse->getContent());
        $this->assertStringNotContainsString('food_resources', $nationResponse->getContent());
        $this->assertStringNotContainsString('wheat', $nationResponse->getContent());
        $this->assertStringNotContainsString('user_id', $nationResponse->getContent());
        $this->assertStringNotContainsString('membership', $nationResponse->getContent());
        foreach (['food_capacity_tons', 'resources', 'industrial_goods', 'minerals'] as $privateField) {
            $this->assertStringNotContainsString($privateField, $nationResponse->getContent());
        }

        $url = "/api/v1/public/nations/{$nation->id}/map-spaces/{$mapSpace->id}/chunks/{$base->chunk_x}/{$base->chunk_y}";
        $response = $this->getJson($url)->assertOk();
        $publicBase = $this->cell($response->json('data.cells'), $base);
        $publicForest = $this->cell($response->json('data.cells'), $forest);
        $this->assertSame('forest', $publicBase['terrain']);
        $this->assertNull($publicBase['facility']);
        $this->assertSame($nation->id, $publicBase['owner_nation_id']);
        $this->assertSame($nation->nation_number, $publicBase['owner_nation_number']);
        $this->assertSame($nation->name, $publicBase['owner_name']);
        $this->assertSame($publicForest['details'], $publicBase['details']);
        $this->assertSame(
            'ペリドット海域',
            collect($publicBase['details'])->keyBy('key')['sea_area']['value'],
        );

        foreach (['x', 'y', 'aria_label'] as $key) {
            unset($publicBase[$key], $publicForest[$key]);
        }
        $this->assertSame($publicForest, $publicBase);
        $encoded = json_encode($publicBase, JSON_THROW_ON_ERROR);
        foreach (['missile', 'experience', 'level', 'operational', 'quantity', 'audit'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->has('Vary'));
    }

    public function test_seabed_base_is_indistinguishable_from_neutral_sea_to_public_viewers(): void
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '海底秘匿国', '海底島主');
        $outsider = User::factory()->create();
        app(NationCreationService::class)->create($outsider, $world, '海底外部国', '海底外部島主');
        $mapSpace = MapSpace::query()->where('world_id', $world->id)->firstOrFail();
        $seabedBase = MapCell::query()->where('map_space_id', $mapSpace->id)
            ->whereNull('owner_nation_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->firstOrFail();
        $neutralSea = MapCell::query()->where('map_space_id', $mapSpace->id)
            ->where('map_chunk_id', $seabedBase->map_chunk_id)
            ->whereKeyNot($seabedBase->id)
            ->whereNull('owner_nation_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'sea'))
            ->orderBy('id')->firstOrFail();

        $publicUrl = "/api/v1/public/nations/{$nation->id}/map-spaces/{$mapSpace->id}/chunks/{$seabedBase->chunk_x}/{$seabedBase->chunk_y}";
        $publicBefore = $this->getJson($publicUrl)->assertOk();
        $publicVersionBefore = $publicBefore->json('data.version');

        app(MapCellStateService::class)->setFacility(
            $seabedBase,
            FacilityDefinition::query()->where('key', 'seabed_base')->firstOrFail(),
            experience: 50,
        );
        $seabedBase->owner_nation_id = $nation->id;
        $seabedBase->save();

        $ownerResponse = $this->actingAs($owner)->getJson(
            "/api/v1/map-spaces/{$mapSpace->id}/chunks/{$seabedBase->chunk_x}/{$seabedBase->chunk_y}",
        )->assertOk();
        $ownerCell = $this->cell($ownerResponse->json('data.cells'), $seabedBase);
        $this->assertSame('seabed_base', $ownerCell['facility']);
        $this->assertSame($seabedBase->x, $ownerCell['x']);
        $this->assertSame($seabedBase->y, $ownerCell['y']);
        $this->assertSame($nation->id, $ownerCell['owner_nation_id']);
        $this->assertSame($nation->nation_number, $ownerCell['owner_nation_number']);
        $this->assertSame($nation->name, $ownerCell['owner_name']);
        $this->assertSame(
            [50, 2, 2],
            collect($ownerCell['details'])->whereIn('key', [
                'facility_experience', 'facility_level', 'launch_capacity',
            ])->pluck('value')->all(),
        );
        $this->assertStringContainsString('no-store', (string) $ownerResponse->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $ownerResponse->headers->get('Vary'));

        $nonOwnerResponse = $this->actingAs($outsider)->getJson(
            "/api/v1/map-spaces/{$mapSpace->id}/chunks/{$seabedBase->chunk_x}/{$seabedBase->chunk_y}",
        )->assertOk();
        $nonOwnerBase = $this->cell($nonOwnerResponse->json('data.cells'), $seabedBase);
        $this->assertStringContainsString('no-store', (string) $nonOwnerResponse->headers->get('Cache-Control'));
        $this->assertSame('Cookie', $nonOwnerResponse->headers->get('Vary'));

        $publicResponse = $this->getJson($publicUrl)->assertOk();
        $publicBase = $this->cell($publicResponse->json('data.cells'), $seabedBase);
        $publicSea = $this->cell($publicResponse->json('data.cells'), $neutralSea);
        foreach ([$nonOwnerBase, $publicBase] as $disguisedBase) {
            $this->assertSame('sea', $disguisedBase['terrain']);
            $this->assertNull($disguisedBase['facility']);
            $this->assertNull($disguisedBase['owner_nation_id']);
            $this->assertNull($disguisedBase['owner_nation_number']);
            $this->assertNull($disguisedBase['owner_name']);
            $this->assertSame(
                'ペリドット海域',
                collect($disguisedBase['details'])->keyBy('key')['sea_area']['value'],
            );
            $this->assertStringContainsString('所有 中立', $disguisedBase['aria_label']);
            $this->assertStringNotContainsString($nation->name, $disguisedBase['aria_label']);
            $this->assertStringNotContainsString('N'.$nation->nation_number, $disguisedBase['aria_label']);
        }

        $this->assertSame($publicBase, $nonOwnerBase);
        $this->assertSame($publicVersionBefore, $publicResponse->json('data.version'));
        $this->assertSame($publicResponse->json('data.version'), $nonOwnerResponse->json('data.version'));
        $this->assertNotSame($ownerResponse->json('data.version'), $publicResponse->json('data.version'));
        $this->assertStringContainsString('public', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertFalse($publicResponse->headers->has('Vary'));

        foreach (['x', 'y', 'aria_label'] as $key) {
            unset($publicBase[$key], $publicSea[$key]);
        }
        $this->assertSame($publicSea, $publicBase);
    }

    /** @param array<int, array<string, mixed>> $cells @return array<string, mixed> */
    private function placeScaleFacilities(Nation $nation): void
    {
        $cells = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNull('facility_definition_id')->limit(3)->get();
        foreach (['farm', 'factory', 'mine'] as $index => $facilityKey) {
            $cell = $cells[$index]->fresh(['terrain', 'facility']);
            app(MapCellStateService::class)->transitionTerrain(
                $cell,
                TerrainDefinition::query()->where('key', $facilityKey === 'mine' ? 'mountain' : 'plain')->firstOrFail(),
            );
            $facility = FacilityDefinition::query()->where('key', $facilityKey)->firstOrFail();
            app(MapCellStateService::class)->setFacility($cell, $facility, $facility->initial_scale);
            $cell->save();
        }
    }

    private function cell(array $cells, MapCell $expected): array
    {
        $cell = collect($cells)->first(
            fn (array $cell): bool => $cell['x'] === $expected->x && $cell['y'] === $expected->y,
        );
        $this->assertIsArray($cell);

        return $cell;
    }

    private function assertPublicMonsterRewardHidden(int $killerNationId, int $hostNationId): void
    {
        $world = $this->lightweightWorld();
        DB::table('audit_events')->insert([
            'actor_user_id' => null,
            'world_id' => $world->id,
            'turn' => 1,
            'nation_id' => null,
            'x' => 123_456_789,
            'y' => 223_456_789,
            'message' => null,
            'visibility' => 'public',
            'event_type' => 'monster.reward_distributed',
            'severity' => 'info',
            'subject_type' => $world->getMorphClass(),
            'subject_id' => $world->id,
            'metadata' => json_encode([
                'target_turn' => 1,
                'monster_key' => 'inora',
                'killer_nation_id' => $killerNationId,
                'host_nation_id' => $hostNationId,
                'target_x' => 323_456_789,
                'target_y' => 423_456_789,
                'killer_money' => ['applied' => 200],
                'host_meat_food' => ['applied' => 100_000],
                'private_marker' => 'monster-reward-internal-only',
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/public/worlds/{$world->id}/events")
            ->assertOk()
            ->assertJsonPath('data.groups', []);

        foreach ([
            'monster.reward_distributed',
            '撃破報酬',
            '受け取りました',
            (string) $killerNationId,
            (string) $hostNationId,
            '123456789',
            '223456789',
            '323456789',
            '423456789',
            'killer_nation_id',
            'host_nation_id',
            'target_x',
            'target_y',
            'metadata',
            'monster-reward-internal-only',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $response->getContent());
        }
    }
}
