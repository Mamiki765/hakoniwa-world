<?php

namespace Tests\Feature;

use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\SecretaryTurnService;
use App\Application\SurfaceShipRemovalService;
use App\Domain\Nation\GivenName;
use App\Domain\Ship\SurfaceShipCatalog;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\MapCell;
use App\Models\Nation;
use App\Models\Ship;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class GivenNameTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    public function test_owner_names_one_ship_without_touching_its_course_and_everyone_sees_it_on_the_map(): void
    {
        [$world, $owner, $nation, $sea] = $this->fixture();
        $ship = $this->ship($world, $nation, $sea);
        $path = "/api/v1/nations/{$nation->id}/ships/{$ship->id}/name";

        $this->actingAs($owner)->patchJson($path, ['name' => '　エスポワール '])
            ->assertOk()->assertJsonPath('data.custom_name', 'エスポワール');
        $this->assertSame(['heading' => null, 'destination' => null, 'version' => 1], $ship->fresh()->course());
        $this->getJson("/api/v1/nations/{$nation->id}/ships")->assertOk()
            ->assertJsonPath('data.0.custom_name', 'エスポワール')->assertJsonPath('data.0.name', '漁船');

        // One representative per rejection class; the ship keeps its name.
        foreach ([str_repeat('名', GivenName::MAX_LENGTH + 1), "エス\nポワール", "エス\u{200B}ポワール"] as $invalid) {
            $this->patchJson($path, ['name' => $invalid])->assertUnprocessable();
        }
        $stranger = User::factory()->create();
        app(NationCreationService::class)->create($stranger, $world, '隣の国', '隣の島主');
        $this->actingAs($stranger)->patchJson($path, ['name' => '乗っ取り'])->assertForbidden();
        $this->assertSame('エスポワール', $ship->fresh()->custom_name);

        $chunk = "/api/v1/map-spaces/{$sea->map_space_id}/chunks/{$sea->chunk_x}/{$sea->chunk_y}";
        $cell = collect($this->getJson($chunk)->assertOk()->json('data.cells'))
            ->first(fn (array $row): bool => $row['x'] === $sea->x && $row['y'] === $sea->y);
        $this->assertSame(['漁船', 'エスポワール', false], [$cell['ship']['name'], $cell['ship']['custom_name'], $cell['ship']['is_owner']]);

        $this->actingAs($owner)->patchJson($path, ['name' => '  '])->assertOk()->assertJsonPath('data.custom_name', null);
        $this->assertNull($ship->fresh()->custom_name);
    }

    public function test_named_ship_appears_in_island_logs_while_unnamed_wording_stays(): void
    {
        [$world, , $nation, $sea] = $this->fixture();
        $named = $this->ship($world, $nation, $sea);
        $named->forceFill(['custom_name' => 'エスポワール'])->save();
        $other = MapCell::query()->where('map_space_id', $sea->map_space_id)->whereKeyNot($sea->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))->orderBy('id')->firstOrFail();
        $unnamed = $this->ship($world, $nation, $other);
        $context = $this->context($world, $nation, 2);
        $removal = app(SurfaceShipRemovalService::class);
        $removal->sinkLockedAtCell($context, $sea, $named->fresh(), 'fuel_exhaustion');
        $removal->sinkLockedAtCell($context, $other, $unnamed->fresh(), 'fuel_exhaustion');

        $events = app(PlayerIslandEventService::class);
        $owner = $this->messages($events->ownerPage($nation->fresh(), anchorTurn: 2), 'ship.sunk');
        $this->assertCount(1, $owner->filter(fn (string $message): bool => str_starts_with($message, '漁船「エスポワール」が')));
        $this->assertCount(1, $owner->filter(fn (string $message): bool => str_starts_with($message, '漁船が')));
    }

    public function test_capital_name_is_saved_from_the_profile_and_shown_on_the_real_capital_cell(): void
    {
        [, $owner, $nation] = $this->fixture();
        $path = "/api/v1/nations/{$nation->id}/profile";
        $this->actingAs($owner)->patchJson($path, ['capital_name' => 'ルミナ'])
            ->assertOk()->assertJsonPath('data.capital_name', 'ルミナ');
        $this->patchJson($path, ['capital_name' => str_repeat('都', GivenName::MAX_LENGTH + 1)])
            ->assertUnprocessable()->assertJsonValidationErrors('capital_name');

        $capital = $nation->capital()->firstOrFail();
        $cellModel = MapCell::query()->findOrFail($capital->map_cell_id);
        $chunk = "/api/v1/map-spaces/{$cellModel->map_space_id}/chunks/{$cellModel->chunk_x}/{$cellModel->chunk_y}";
        $cells = collect($this->getJson($chunk)->assertOk()->json('data.cells'));
        $this->assertSame('ルミナ', $cells->first(fn (array $row): bool => $row['x'] === $capital->x && $row['y'] === $capital->y)['capital_name']);
        $this->assertSame([null], $cells->reject(fn (array $row): bool => $row['x'] === $capital->x && $row['y'] === $capital->y)
            ->pluck('capital_name')->unique()->values()->all());

        // Omitting the field keeps it; an empty value removes the name.
        $this->patchJson($path, ['comment' => 'こんにちは'])->assertOk()->assertJsonPath('data.capital_name', 'ルミナ');
        $this->patchJson($path, ['capital_name' => ''])->assertOk()->assertJsonPath('data.capital_name', null);
    }

    /** @return array{World, User, Nation, MapCell} */
    private function fixture(): array
    {
        $world = $this->lightweightWorld();
        $owner = User::factory()->create();
        $nation = app(NationCreationService::class)->create($owner, $world, '命名国', '命名島主');
        $sea = MapCell::query()->where('map_space_id', $this->surfaceMapSpace($world)->id)
            ->whereNull('owner_nation_id')->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($q) => $q->where('key', 'sea'))->orderBy('id')->firstOrFail();

        return [$world, $owner, $nation, $sea];
    }

    private function ship(World $world, Nation $nation, MapCell $cell): Ship
    {
        $definition = collect(app(SurfaceShipCatalog::class)->definitions($world->rulesetVersion->settings))->firstWhere('key', 'fishing');

        return Ship::query()->create([
            'world_id' => $world->id, 'ruleset_version_id' => $world->ruleset_version_id,
            'nation_id' => $nation->id, 'map_cell_id' => $cell->id, 'ship_type_key' => 'fishing',
            'current_hp' => $definition->maximumHp, 'max_hp' => $definition->maximumHp,
            'state' => Ship::STATE_ACTIVE, 'version' => 1,
        ]);
    }

    private function context(World $world, Nation $nation, int $turn): TurnContext
    {
        $seed = hash('sha256', 'given-name-'.$turn);
        $run = TurnRun::query()->create([
            'world_id' => $world->id, 'target_turn' => $turn, 'ruleset_version_id' => $world->ruleset_version_id,
            'random_seed' => $seed, 'source' => 'manual', 'is_dry_run' => true,
            'status' => TurnRun::STATUS_DRY_RUN, 'attempt_count' => 1,
            'pipeline' => [], 'phase_results' => [], 'failure_context' => [],
        ]);
        $state = new TurnState;
        $state->setStableNationIds([$nation->id]);
        $state->setDevelopmentNationIds([$nation->id]);
        $context = new TurnContext($world, $run, $world->rulesetVersion, $turn, $seed, new TurnRandomStreamFactory($seed), $state);
        app(SecretaryTurnService::class)->loadAttemptSnapshots($context, [$nation->id]);

        return $context;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return Collection<int, string>
     */
    private function messages(array $page, string $type): Collection
    {
        return collect($page['groups'])->flatMap(fn (array $group): array => $group['events'])
            ->where('type', $type)->pluck('message')->values();
    }
}
