<?php

namespace Tests\Feature;

use App\Application\MonsterDamageService;
use App\Application\NationCreationService;
use App\Domain\Map\MapCellStateService;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationMonsterKillStat;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use App\Services\AssetManifestResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
use Tests\Support\CurrentRulesetFixture;
use Tests\TestCase;

class MonsterApiAssetTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private ?string $assetDirectory = null;

    protected function tearDown(): void
    {
        if ($this->assetDirectory !== null) {
            File::deleteDirectory($this->assetDirectory);
        }

        parent::tearDown();
    }

    public function test_monster_manifest_resolves_all_original_gif_names_through_the_external_asset_route(): void
    {
        $directory = $this->temporaryAssetDirectory();
        $onePixelGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==', true);
        $this->assertIsString($onePixelGif);
        foreach (range(0, 8) as $index) {
            file_put_contents($directory.DIRECTORY_SEPARATOR."monster{$index}.gif", $onePixelGif);
        }
        config([
            'hakoniwa.assets.path' => $directory,
            'hakoniwa.assets.base_url' => 'https://assets.example.test/hakoniwa-tiles',
            'hakoniwa.assets.allowed_extensions' => ['gif', 'png', 'webp'],
        ]);
        $resolver = new AssetManifestResolver;
        $expected = [
            'hakoniwa_original.monster.mecha_inora' => 'monster7.gif',
            'hakoniwa_original.monster.inora' => 'monster0.gif',
            'hakoniwa_original.monster.sanjira' => 'monster5.gif',
            'hakoniwa_original.monster.red_inora' => 'monster1.gif',
            'hakoniwa_original.monster.dark_inora' => 'monster2.gif',
            'hakoniwa_original.monster.inora_ghost' => 'monster8.gif',
            'hakoniwa_original.monster.kujira' => 'monster6.gif',
            'hakoniwa_original.monster.king_inora' => 'monster3.gif',
            'hakoniwa_original.monster.hardened' => 'monster4.gif',
        ];

        foreach ($expected as $assetKey => $filename) {
            $asset = $resolver->resolve($assetKey, '怪獣');
            $this->assertTrue($asset['available'], $assetKey);
            $this->assertStringContainsString('/'.$filename.'?v=', $asset['url']);
            $this->assertStringStartsWith('https://assets.example.test/hakoniwa-tiles/', $asset['url']);
            $this->assertStringNotContainsString('_references', $asset['url']);
            $this->assertSame('image/gif', $resolver->contentTypeForFilename($filename));
        }

        File::delete($directory.DIRECTORY_SEPARATOR.'monster4.gif');
        $fallback = (new AssetManifestResolver)->resolve('hakoniwa_original.monster.hardened', '硬化怪獣');
        $this->assertFalse($fallback['available']);
        $this->assertNull($fallback['url']);
        $this->assertSame('硬化怪獣', $fallback['fallback_label']);
    }

    public function test_public_chunk_projects_hardened_monster_with_current_host_number_and_no_internal_runtime_fields(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('表示国');
        $world->update(['current_turn' => 3]);
        config(['hakoniwa.assets.path' => storage_path('missing-monster-assets')]);
        $cell = $this->ownedNonCapitalCell($nation);
        $this->setWasteland($cell, $nation->id);
        $monster = $this->createMonster($world, $ruleset, $cell, 'sanjira', 2);

        $response = $this->getJson(
            "/api/v1/public/nations/{$nation->id}/map-spaces/{$space->id}/chunks/{$cell->chunk_x}/{$cell->chunk_y}",
        )->assertOk();
        $projected = collect($response->json('data.cells'))->first(
            static fn (array $candidate): bool => $candidate['x'] === $cell->x && $candidate['y'] === $cell->y,
        );

        $this->assertSame($monster->id, $projected['monster']['id']);
        $this->assertSame('sanjira', $projected['monster']['key']);
        $this->assertSame('hakoniwa_original.monster.hardened', $projected['monster']['asset_key']);
        $this->assertNull($projected['monster']['asset_url']);
        $this->assertFalse($projected['monster']['asset']['available']);
        $this->assertSame(2, $projected['monster']['current_hp']);
        $this->assertTrue($projected['monster']['hardened_now']);
        $this->assertSame(['nation_number' => $nation->nation_number, 'name' => $nation->name], $projected['monster']['host_nation']);
        $this->assertSame('N'.$nation->nation_number, $projected['monster']['host_label']);
        $this->assertStringContainsString('HP 2', $projected['aria_label']);
        $this->assertStringContainsString('N'.$nation->nation_number, $projected['aria_label']);
        $this->assertArrayNotHasKey('owner_nation_id', $projected['monster']);
        $content = $response->getContent();
        $this->assertStringNotContainsString('moves_taken', $content);
        $this->assertStringNotContainsString('random_seed', $content);
        $this->assertStringNotContainsString('skill_key', $content);
        $this->assertStringNotContainsString('skill_code', $content);
        $this->assertStringNotContainsString('source_metadata', $content);
    }

    public function test_origin_and_destination_chunks_refresh_host_and_hp_then_remove_overlay_after_death(): void
    {
        [$world, $originNation, $ruleset, $space] = $this->worldAndNation('出発国');
        $destinationNation = $this->createNation($world, '到着国');
        $origin = $this->ownedNonCapitalCell($originNation);
        $destination = MapCell::query()->where('map_space_id', $space->id)
            ->where('chunk_x', '!=', $origin->chunk_x)
            ->whereNotIn('id', $world->nations()->with('capital')->get()->pluck('capital.map_cell_id')->filter())
            ->with(['terrain', 'facility'])
            ->firstOrFail();
        $this->setWasteland($origin, $originNation->id);
        $this->setWasteland($destination, $destinationNation->id);
        $monster = $this->createMonster($world, $ruleset, $origin, 'red_inora', 3);
        $occupancy = $monster->occupancy()->firstOrFail();

        $occupancy->update(['map_cell_id' => $destination->id]);
        $originChunk = $this->publicChunk($originNation, $space, $origin);
        $destinationChunk = $this->publicChunk($originNation, $space, $destination);
        $this->assertNull($this->projectedCell($originChunk, $origin)['monster']);
        $projectedDestination = $this->projectedCell($destinationChunk, $destination);
        $this->assertSame('N'.$destinationNation->nation_number, $projectedDestination['monster']['host_label']);
        $this->assertSame(1, collect($destinationChunk)->whereNotNull('monster')->count());

        [$context] = $this->context($world, $ruleset, 2, 'api-damage', [$originNation->id, $destinationNation->id]);
        app(MonsterDamageService::class)->applyDamage(
            $monster, 1, 'monster_missile', $originNation, null, $destination, $context,
        );
        $this->assertSame(0, NationMonsterKillStat::query()->count());
        $afterDamage = $this->publicChunk($originNation, $space, $destination);
        $this->assertSame(2, $this->projectedCell($afterDamage, $destination)['monster']['current_hp']);

        app(MonsterDamageService::class)->applyDamage(
            $monster, 2, 'monster_missile', $originNation, null, $destination, $context,
        );
        $afterDeath = $this->publicChunk($originNation, $space, $destination);
        $this->assertNull($this->projectedCell($afterDeath, $destination)['monster']);
        $this->assertSame(0, collect($afterDeath)->whereNotNull('monster')->count());
    }

    public function test_neutral_monster_host_is_unaffiliated(): void
    {
        [$world, $nation, $ruleset, $space] = $this->worldAndNation('中立表示国');
        $cell = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->with(['terrain', 'facility'])->firstOrFail();
        $this->setWasteland($cell, null);
        $this->createMonster($world, $ruleset, $cell, 'inora', 1);

        $projected = $this->projectedCell($this->publicChunk($nation, $space, $cell), $cell);

        $this->assertNull($projected['monster']['host_nation']);
        $this->assertSame('無所属', $projected['monster']['host_label']);
    }

    public function test_public_nation_statistics_use_authoritative_species_aggregates(): void
    {
        [$world, $host, $ruleset] = $this->worldAndNation('統計所在国');
        $killer = $this->createNation($world, '統計撃破国');
        $cells = MapCell::query()->where('owner_nation_id', $host->id)
            ->whereNotIn('id', $host->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility'])->limit(3)->get();
        foreach ($cells as $cell) {
            $this->setWasteland($cell, $host->id);
        }
        $first = $this->createMonster($world, $ruleset, $cells[0], 'inora', 1);
        $second = $this->createMonster($world, $ruleset, $cells[1], 'inora', 1);
        $third = $this->createMonster($world, $ruleset, $cells[2], 'red_inora', 3);
        foreach ([[$first, $cells[0], 3, 1], [$second, $cells[1], 5, 1], [$third, $cells[2], 4, 3]] as [$monster, $cell, $turn, $damage]) {
            [$context] = $this->context($world, $ruleset, $turn, 'stats-'.$monster->id, [$host->id, $killer->id]);
            app(MonsterDamageService::class)->applyDamage(
                $monster, $damage, 'monster_missile', $killer, null, $cell, $context,
            );
        }

        $response = $this->getJson("/api/v1/public/nations/{$killer->id}")
            ->assertOk()
            ->assertJsonPath('data.monster_final_blow_count', 3)
            ->json('data');

        $this->assertSame([
            ['key' => 'inora', 'name' => 'いのら', 'kill_count' => 2, 'first_killed_turn' => 3, 'last_killed_turn' => 5],
            ['key' => 'red_inora', 'name' => 'レッドいのら', 'kill_count' => 1, 'first_killed_turn' => 4, 'last_killed_turn' => 4],
        ], $response['monster_kill_stats']);
        $this->assertArrayNotHasKey('monster_award', $response);
    }

    public function test_rankings_batch_monster_kill_stats_once_and_detail_queries_only_its_target(): void
    {
        [$world, $nation] = $this->worldAndNation('照会境界国');
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
        });

        $this->getJson("/api/v1/public/worlds/{$world->id}/summary")->assertOk();
        $this->assertCount(0, array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'nation_monster_kill_stats'),
        ));

        $queries = [];
        $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")->assertOk();
        $rankingQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'nation_monster_kill_stats'),
        ));
        $this->assertCount(1, $rankingQueries);
        $this->assertContains($nation->id, $rankingQueries[0]['bindings']);

        $queries = [];
        $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk();
        $statQueries = array_values(array_filter(
            $queries,
            static fn (array $query): bool => str_contains($query['sql'], 'nation_monster_kill_stats'),
        ));
        $this->assertCount(1, $statQueries);
        $this->assertContains($nation->id, $statQueries[0]['bindings']);
    }

    public function test_public_detail_and_rankings_project_all_species_by_effective_order_with_bounded_queries(): void
    {
        [$world, $nation, $ruleset] = $this->worldAndNation('十種討伐国');
        $secondNation = $this->createNation($world, '第二十種討伐国');
        $fixture = collect(CurrentRulesetFixture::settings()['monster_definitions'])->keyBy('key');
        $definitions = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)->get();
        foreach ($definitions as $index => $definition) {
            DB::table('nation_monster_kill_stats')->insert([
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                'monster_definition_id' => $definition->id,
                'kill_count' => 1,
                'first_killed_turn' => 2,
                'last_killed_turn' => 2,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('nation_monster_kill_stats')->insert([
                'world_id' => $world->id,
                'nation_id' => $secondNation->id,
                'monster_definition_id' => $definition->id,
                'kill_count' => 1,
                'first_killed_turn' => 2,
                'last_killed_turn' => 2,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach ($index === 0 ? [] : range(1, $index) as $increment) {
                DB::table('nation_monster_kill_stats')
                    ->where('world_id', $world->id)
                    ->where('nation_id', $nation->id)
                    ->where('monster_definition_id', $definition->id)
                    ->update([
                        'kill_count' => $increment + 1,
                        'last_killed_turn' => 3,
                        'version' => $increment + 1,
                        'updated_at' => now(),
                    ]);
            }
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $detail = $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk()->json('data');
        $expectedKeys = $fixture->sortBy('display_order')->keys()->values()->all();
        $this->assertSame($expectedKeys, array_column($detail['monster_kill_stats'], 'key'));
        $this->assertCount(10, $detail['monster_kill_stats']);
        $this->assertSame(array_sum(range(1, 10)), $detail['monster_final_blow_count']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'nation_monster_kill_stats'),
        )->count());
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "monster_definitions"'),
        )->count());

        $queries = [];
        $rankingRows = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->json('data');
        $ranking = collect($rankingRows)->firstWhere('id', $nation->id)['achievements']['monster_kills'];
        $this->assertSame($expectedKeys, array_column($ranking['species'], 'key'));
        $this->assertSame(array_sum(range(1, 10)), $ranking['total_count']);
        $this->assertSame('hakoniwa_original.monster.king_inora', $ranking['asset']['key']);
        $secondRanking = collect($rankingRows)->firstWhere('id', $secondNation->id)['achievements']['monster_kills'];
        $this->assertSame($expectedKeys, array_column($secondRanking['species'], 'key'));
        $this->assertSame(10, $secondRanking['total_count']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'nation_monster_kill_stats'),
        )->count());
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "monster_definitions"'),
        )->count());
    }

    public function test_public_detail_and_rankings_keep_the_same_query_bound_for_twenty_species(): void
    {
        [$world, $nation, $ruleset] = $this->worldAndNation('二十種討伐国');
        $template = CurrentRulesetFixture::newMonsterDefinitions()[0];
        foreach (range(1, 10) as $index) {
            $payload = $template;
            $payload['key'] = "synthetic_monster_{$index}";
            $payload['name'] = "試験怪獣{$index}";
            $payload['asset_key'] = "hakoniwa_custom.monster.synthetic_monster_{$index}";
            $payload['display_order'] = 700 + ($index * 100);
            MonsterDefinition::query()->create(['ruleset_version_id' => $ruleset->id, ...$payload]);
        }
        $definitions = MonsterDefinition::query()
            ->where('ruleset_version_id', $ruleset->id)
            ->get();
        $this->assertCount(20, $definitions);
        foreach ($definitions as $definition) {
            DB::table('nation_monster_kill_stats')->insert([
                'world_id' => $world->id,
                'nation_id' => $nation->id,
                'monster_definition_id' => $definition->id,
                'kill_count' => 1,
                'first_killed_turn' => 2,
                'last_killed_turn' => 2,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $detail = $this->getJson("/api/v1/public/nations/{$nation->id}")->assertOk()->json('data');
        $this->assertCount(20, $detail['monster_kill_stats']);
        $this->assertSame(20, $detail['monster_final_blow_count']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'nation_monster_kill_stats'),
        )->count());
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "monster_definitions"'),
        )->count());

        $queries = [];
        $ranking = $this->getJson("/api/v1/public/worlds/{$world->id}/rankings")
            ->assertOk()->json('data.0.achievements.monster_kills');
        $this->assertCount(20, $ranking['species']);
        $this->assertSame(20, $ranking['total_count']);
        $this->assertSame('hakoniwa_custom.monster.synthetic_monster_10', $ranking['asset']['key']);
        $this->assertFalse($ranking['asset']['available']);
        $this->assertNull($ranking['asset']['url']);
        $this->assertSame('試験怪獣10', $ranking['asset']['fallback_label']);
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'nation_monster_kill_stats'),
        )->count());
        $this->assertSame(1, collect($queries)->filter(
            static fn (string $sql): bool => str_contains($sql, 'from "monster_definitions"'),
        )->count());
    }

    private function temporaryAssetDirectory(): string
    {
        $this->assetDirectory = storage_path('framework/testing/monster-assets-'.Str::uuid());
        File::ensureDirectoryExists($this->assetDirectory);

        return $this->assetDirectory;
    }

    /** @return array{World, Nation, RulesetVersion, MapSpace} */
    private function worldAndNation(string $name): array
    {
        $world = $this->lightweightWorld();
        $nation = $this->createNation($world, $name);

        return [$world, $nation, $world->rulesetVersion()->firstOrFail(), $this->surfaceMapSpace($world)];
    }

    private function createNation(World $world, string $name): Nation
    {
        return app(NationCreationService::class)->create(User::factory()->create(), $world, $name, $name.'主');
    }

    private function createMonster(
        World $world,
        RulesetVersion $ruleset,
        MapCell $cell,
        string $key,
        int $hp,
    ): MonsterInstance {
        $definition = MonsterDefinition::query()->where('ruleset_version_id', $ruleset->id)
            ->where('key', $key)->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => $hp,
            'spawned_max_hp' => $hp,
            'state' => 'alive',
            'spawned_target_turn' => 2,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);

        return $monster->fresh(['definition', 'occupancy']);
    }

    private function ownedNonCapitalCell(Nation $nation): MapCell
    {
        return MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereNotIn('id', $nation->capital()->select('map_cell_id'))
            ->with(['terrain', 'facility'])->firstOrFail();
    }

    private function setWasteland(MapCell $cell, ?int $ownerNationId): void
    {
        $cell = $cell->fresh(['terrain', 'facility']);
        $states = app(MapCellStateService::class);
        $states->setFacility($cell, null);
        $states->transitionTerrain($cell, TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail());
        $cell->owner_nation_id = $ownerNationId;
        $cell->population = 0;
        $cell->version++;
        $cell->save();
    }

    /** @return array{TurnContext, TurnRun} */
    private function context(
        World $world,
        RulesetVersion $ruleset,
        int $targetTurn,
        string $label,
        array $nationIds,
    ): array {
        $seed = hash('sha256', $label);
        $run = TurnRun::query()->create([
            'world_id' => $world->id,
            'target_turn' => $targetTurn,
            'ruleset_version_id' => $ruleset->id,
            'random_seed' => $seed,
            'source' => 'manual',
            'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN,
            'attempt_count' => 1,
            'pipeline' => [],
            'phase_results' => [],
            'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds(array_values($nationIds));
        $state->setDevelopmentNationIds(array_values($nationIds));

        return [new TurnContext(
            $world, $run, $ruleset, $targetTurn, $seed, new TurnRandomStreamFactory($seed), $state,
        ), $run];
    }

    /** @return list<array<string, mixed>> */
    private function publicChunk(Nation $viewer, MapSpace $space, MapCell $cell): array
    {
        return $this->getJson(
            "/api/v1/public/nations/{$viewer->id}/map-spaces/{$space->id}/chunks/{$cell->chunk_x}/{$cell->chunk_y}",
        )->assertOk()->json('data.cells');
    }

    /** @param list<array<string, mixed>> $cells
     * @return array<string, mixed>
     */
    private function projectedCell(array $cells, MapCell $target): array
    {
        return collect($cells)->firstOrFail(
            static fn (array $cell): bool => $cell['x'] === $target->x && $cell['y'] === $target->y,
        );
    }
}
