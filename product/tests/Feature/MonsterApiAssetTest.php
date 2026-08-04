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
use App\Models\MonsterKillRecord;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\RulesetVersion;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use App\Services\AssetManifestResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTestWorlds;
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
        $this->assertSame(0, MonsterKillRecord::query()->count());
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

    public function test_public_nation_statistics_use_authoritative_final_blows_and_distinct_first_kill_marks(): void
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
        foreach ([[$first, $cells[0], 5, 1], [$second, $cells[1], 3, 1], [$third, $cells[2], 4, 3]] as [$monster, $cell, $turn, $damage]) {
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
            ['key' => 'inora', 'name' => 'いのら', 'first_kill_turn' => 3],
            ['key' => 'red_inora', 'name' => 'レッドいのら', 'first_kill_turn' => 4],
        ], $response['monster_kill_marks']);
        $this->assertArrayNotHasKey('monster_kill_counts_by_species', $response);
        $this->assertArrayNotHasKey('monster_award', $response);
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
