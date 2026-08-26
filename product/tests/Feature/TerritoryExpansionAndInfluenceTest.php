<?php

namespace Tests\Feature;

use App\Application\DomesticCommandExecutor;
use App\Application\NationCreationService;
use App\Application\PlayerIslandEventService;
use App\Application\TerritoryInfluenceService;
use App\Domain\Map\GridCoordinate;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Domain\Turn\TurnState;
use App\Models\CommandDefinition;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\MonsterDefinition;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\TerrainDefinition;
use App\Models\TurnRun;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTestWorlds;
use Tests\TestCase;

final class TerritoryExpansionAndInfluenceTest extends TestCase
{
    use CreatesTestWorlds;
    use RefreshDatabase;

    private int $targetTurn = 2;

    #[DataProvider('manualSuccessCases')]
    public function test_manual_expansion_accepts_neutral_and_active_foreign_waste_contracts(
        ?string $targetOwner,
        string $terrain,
    ): void {
        [$world, $space, $actorUser, $actor, $foreign] = $this->worldAndNations();
        [$target, $adjacent] = $this->remotePair($space, [$actor, $foreign]);
        $this->setCell($adjacent, 'plain', $actor->id);
        $this->setCell($target, $terrain, $targetOwner === 'foreign' ? $foreign->id : null);
        $target->update([
            'population' => 1_234,
            'terrain_quantity' => 77,
            'state' => 'generated',
        ]);
        $actor->update(['money' => 1_000]);
        $before = Arr::except($target->fresh()->getAttributes(), ['owner_nation_id', 'version', 'updated_at']);

        $preview = collect($this->actingAs($actorUser)->getJson(
            "/api/v1/nations/{$actor->id}/map-spaces/{$space->id}/command-definitions"
            ."?target_x={$target->x}&target_y={$target->y}",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'territory_expand');
        $this->assertSame('currently_executable', $preview['execution_preview_status']);

        $item = $this->queue($actorUser, $actor, $space, $target);
        $context = $this->context($world, [$actor->id, $foreign->id], hash('sha256', "manual-{$terrain}-{$targetOwner}"));
        $result = app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(1, $result['successes']);
        $this->assertSame(0, $result['failures']);
        $this->assertSame('completed', $item->fresh()->status);
        $this->assertSame(900, $actor->fresh()->money);
        $this->assertSame($actor->id, $target->fresh()->owner_nation_id);
        $this->assertSame($before, Arr::except(
            $target->fresh()->getAttributes(),
            ['owner_nation_id', 'version', 'updated_at'],
        ));

        $event = DB::table('audit_events')->where('event_type', 'command.territory_expanded')->firstOrFail();
        $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('public', $event->visibility);
        $this->assertSame($target->x, $metadata['x']);
        $this->assertSame($target->y, $metadata['y']);
        $this->assertSame($actor->id, $metadata['new_owner_nation_id']);
        $this->assertArrayNotHasKey('terrain_key', $metadata);
        $this->assertArrayNotHasKey('facility_key', $metadata);
    }

    /** @return iterable<string, array{string|null, string}> */
    public static function manualSuccessCases(): iterable
    {
        yield 'neutral existing wasteland' => [null, 'wasteland'];
        yield 'foreign wasteland' => ['foreign', 'wasteland'];
        yield 'foreign scorched' => ['foreign', 'scorched'];
    }

    public function test_manual_expansion_preview_projects_a_prior_foreign_wasteland_capture_for_adjacency(): void
    {
        [, $space, $actorUser, $actor, $foreign] = $this->worldAndNations();
        [$owned, $firstTarget, $secondTarget] = $this->remoteLine($space, [$actor, $foreign]);
        $this->setCell($owned, 'plain', $actor->id);
        $this->setCell($firstTarget, 'wasteland', $foreign->id);
        $this->setCell($secondTarget, 'scorched', $foreign->id);
        $this->queue($actorUser, $actor, $space, $firstTarget, 1);

        $preview = collect($this->actingAs($actorUser)->getJson(
            "/api/v1/nations/{$actor->id}/map-spaces/{$space->id}/command-definitions"
            ."?target_x={$secondTarget->x}&target_y={$secondTarget->y}&position=2",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'territory_expand');

        $this->assertSame('executable_after_queue', $preview['execution_preview_status']);
    }

    public function test_manual_expansion_failures_consume_items_without_cost_or_effect(): void
    {
        [$world, $space, $actorUser, $actor, $foreign] = $this->worldAndNations();
        $actor->update(['money' => 2_000]);
        [$baseTarget, $adjacent] = $this->remotePair($space, [$actor, $foreign]);
        $this->setCell($adjacent, 'plain', $actor->id);
        $targets = [$baseTarget];
        for ($direction = 0; count($targets) < 4; $direction++) {
            $coordinate = (new GridCoordinate($baseTarget->x, $baseTarget->y + 4 + $direction * 3));
            $candidate = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->first();
            if ($candidate instanceof MapCell && $this->outsideCapitalCores($candidate, [$actor, $foreign])) {
                $targets[] = $candidate;
            }
        }

        $this->setCell($targets[0], 'forest', $foreign->id);
        $this->setCell($targets[1], 'wasteland', $foreign->id, 'farm');
        $this->setCell($targets[2], 'wasteland', $foreign->id);
        $this->setCell($targets[3], 'wasteland', $foreign->id);

        $this->setAdjacentOwner($targets[0], $actor->id);
        $this->setAdjacentOwner($targets[1], $actor->id);
        // targets[2] deliberately has no adjacent actor territory.
        $this->setAdjacentOwner($targets[3], $actor->id);
        $this->occupy($world, $targets[3]);

        $coreTarget = $this->capitalCoreNeighbor($foreign, $space);
        $this->setCell($coreTarget, 'wasteland', $foreign->id);
        $this->setAdjacentOwner($coreTarget, $actor->id);

        foreach ([...$targets, $coreTarget] as $target) {
            $preview = collect($this->actingAs($actorUser)->getJson(
                "/api/v1/nations/{$actor->id}/map-spaces/{$space->id}/command-definitions"
                ."?target_x={$target->x}&target_y={$target->y}",
            )->assertOk()->json('data.commands'))->firstWhere('key', 'territory_expand');
            $this->assertNotSame('currently_executable', $preview['execution_preview_status']);
        }

        $queued = [];
        foreach ([...$targets, $coreTarget] as $position => $target) {
            $queued[] = $this->queue($actorUser, $actor, $space, $target, $position + 1);
        }
        $beforeOwners = collect([...$targets, $coreTarget])->mapWithKeys(
            static fn (MapCell $cell): array => [$cell->id => $cell->owner_nation_id],
        )->all();

        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$actor->id, $foreign->id], hash('sha256', 'manual-failures')),
        );

        $this->assertSame(count($queued), $result['failures']);
        $this->assertSame(2, $result['automatic_finance']);
        $this->assertSame(2_010, $actor->fresh()->money);
        foreach ($queued as $item) {
            $this->assertSame('failed', $item->fresh()->status);
            $this->assertNull($item->fresh()->queue_position);
        }
        foreach ($beforeOwners as $cellId => $ownerNationId) {
            $this->assertSame($ownerNationId, MapCell::query()->findOrFail($cellId)->owner_nation_id);
        }
        $this->assertContains($queued[0]->fresh()->failure_code, ['foreign_owned', 'invalid_target_nation']);
        $this->assertSame('facility_exists', $queued[1]->fresh()->failure_code);
        $this->assertSame('missing_adjacent_territory', $queued[2]->fresh()->failure_code);
        $this->assertSame('occupied_by_monster', $queued[3]->fresh()->failure_code);
        $this->assertSame('capital_protected', $queued[4]->fresh()->failure_code);
    }

    public function test_manual_expansion_cannot_take_a_cell_in_the_dormant_capital_radius(): void
    {
        [$world, $space, $actorUser, $actor, $dormant] = $this->worldAndNations();
        $capital = $dormant->capital()->firstOrFail();
        $coordinate = (new GridCoordinate($capital->x, $capital->y))->ring(2)[0];
        $target = MapCell::query()->where('map_space_id', $space->id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        $this->setCell($target, 'wasteland', $dormant->id);
        $this->setAdjacentOwner($target, $actor->id);
        $dormant->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $item = $this->queue($actorUser, $actor, $space, $target);
        $context = $this->context($world, [$actor->id], hash('sha256', 'dormant-territory-protection'));
        $context->state->setNationLifecycleSnapshot($dormant->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $capital->x,
            'capital_y' => $capital->y,
        ]);

        $result = app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(1, $result['failures']);
        $this->assertSame('capital_protected', $item->fresh()->failure_code);
        $this->assertSame($dormant->id, $target->fresh()->owner_nation_id);
    }

    public function test_manual_expansion_fails_closed_for_a_cross_world_owner_anomaly(): void
    {
        [$world, $space, $actorUser, $actor, $foreign] = $this->worldAndNations();
        [$target, $adjacent] = $this->remotePair($space, [$actor, $foreign]);
        $otherWorld = World::query()->create([
            'key' => 'cross-world-owner-anomaly',
            'name' => '別World',
            'ruleset_version_id' => $world->ruleset_version_id,
            'current_turn' => 1,
        ]);
        $externalOwner = Nation::query()->create([
            'world_id' => $otherWorld->id,
            'nation_number' => 1,
            'registered_turn' => 1,
            'name' => '別World active Nation',
            'owner_name' => '別World owner',
            'profile_comment' => '',
            'money' => 100,
            'state' => 'active',
            'idle_counter' => 0,
        ]);
        $this->setCell($adjacent, 'plain', $actor->id);
        $this->setCell($target, 'wasteland', $externalOwner->id);
        $actor->update(['money' => 1_000]);

        $preview = collect($this->actingAs($actorUser)->getJson(
            "/api/v1/nations/{$actor->id}/map-spaces/{$space->id}/command-definitions"
            ."?target_x={$target->x}&target_y={$target->y}",
        )->assertOk()->json('data.commands'))->firstWhere('key', 'territory_expand');
        $this->assertNotSame('currently_executable', $preview['execution_preview_status']);

        $item = $this->queue($actorUser, $actor, $space, $target);
        $result = app(DomesticCommandExecutor::class)->execute(
            $this->context($world, [$actor->id], hash('sha256', 'cross-world-owner-anomaly')),
        );

        $this->assertSame(1, $result['failures']);
        $this->assertSame('invalid_target_nation', $item->fresh()->failure_code);
        $this->assertSame($externalOwner->id, $target->fresh()->owner_nation_id);
        $this->assertSame(1_010, $actor->fresh()->money);
    }

    #[DataProvider('influenceCases')]
    public function test_influence_target_source_and_exclusion_matrix(
        string $case,
        bool $expectedMutation,
    ): void {
        [$world, $space, , $first, $second] = $this->worldAndNations();
        $this->resetSurface($space, [$first, $second]);
        [$target, $source] = $this->remotePair($space, [$first, $second]);
        $this->setCell($target, 'forest', $first->id);
        $this->setCell($source, 'plain', $second->id);

        if ($case === 'neutral') {
            $target->update(['owner_nation_id' => null]);
        } elseif ($case === 'dormant') {
            $first->update([
                'state' => 'dormant',
                'state_reason' => 'idle',
                'state_started_turn' => 1,
            ]);
        } elseif ($case === 'sunken') {
            $first->update(['state' => 'abandoned']);
        } elseif ($case === 'wasteland') {
            $this->setCell($target, 'wasteland', $first->id);
        } elseif ($case === 'monument_target') {
            $this->setCell($target, 'plain', $first->id, 'monument');
        } elseif ($case === 'decoy_target') {
            $this->setCell($target, 'plain', $first->id, 'decoy');
        } elseif ($case === 'monument_source') {
            $this->setCell($source, 'plain', $second->id, 'monument');
        } elseif ($case === 'monster_target') {
            $this->occupy($world, $target);
        } elseif ($case === 'monster_source') {
            $this->occupy($world, $source);
        } elseif ($case === 'capital_core') {
            $this->setCell($target, 'sea', null);
            $this->setCell($source, 'sea', null);
            $target = $this->capitalCoreNeighbor($first, $space);
            $this->setCell($target, 'forest', $first->id);
            $source = $this->setAdjacentOwner($target, $second->id);
        }

        $direction = $this->directionFrom($target, $source);
        $seed = $this->seedForDirections([$direction]);
        $context = $this->context($world, [$first->id, $second->id], $seed);
        $context->state->setSurfaceCellIds($this->surfaceOrder($space, [$target->id]));
        $result = app(TerritoryInfluenceService::class)->execute($context);

        $this->assertSame($expectedMutation ? 1 : 0, $result['mutations']);
        $this->assertSame(
            $expectedMutation ? $second->id : ($case === 'neutral' ? null : $first->id),
            $target->fresh()->owner_nation_id,
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function influenceCases(): iterable
    {
        yield 'ordinary active-active' => ['ordinary', true];
        yield 'neutral target' => ['neutral', false];
        yield 'dormant target outside protection' => ['dormant', true];
        yield 'sunken target' => ['sunken', false];
        yield 'wasteland target' => ['wasteland', false];
        yield 'monument target' => ['monument_target', false];
        yield 'decoy target' => ['decoy_target', false];
        yield 'monument source' => ['monument_source', true];
        yield 'monster target' => ['monster_target', false];
        yield 'monster source' => ['monster_source', false];
        yield 'Capital core target' => ['capital_core', false];
    }

    public function test_influence_does_not_reroll_when_the_selected_direction_is_outside_the_map(): void
    {
        [$world, $space, , $first, $second] = $this->worldAndNations();
        $this->resetSurface($space, [$first, $second]);
        [$target, $source, $missingDirection] = $this->boundaryPair($space, [$first, $second]);
        $this->setCell($target, 'forest', $first->id);
        $this->setCell($source, 'plain', $second->id);

        $context = $this->context(
            $world,
            [$first->id, $second->id],
            $this->seedForDirections([$missingDirection]),
        );
        $context->state->setSurfaceCellIds($this->surfaceOrder($space, [$target->id]));
        $result = app(TerritoryInfluenceService::class)->execute($context);

        $this->assertSame(1, $result['eligible_targets']);
        $this->assertSame(1, $result['direction_draws']);
        $this->assertSame(0, $result['mutations']);
        $this->assertSame($first->id, $target->fresh()->owner_nation_id);
    }

    public function test_capital_core_cell_remains_a_full_strength_influence_source(): void
    {
        [$world, $space, , $first, $second] = $this->worldAndNations();
        $this->resetSurface($space, [$first, $second]);
        [$target, $source] = $this->capitalCoreSourcePair($space, $second, [$first, $second]);
        $this->setCell($target, 'forest', $first->id);
        $this->setCell($source, 'plain', $second->id);

        $context = $this->context(
            $world,
            [$first->id, $second->id],
            $this->seedForDirections([$this->directionFrom($target, $source)]),
        );
        $context->state->setSurfaceCellIds($this->surfaceOrder($space, [$target->id]));
        $result = app(TerritoryInfluenceService::class)->execute($context);

        $this->assertSame(1, $result['mutations']);
        $this->assertSame($second->id, $target->fresh()->owner_nation_id);
    }

    public function test_influence_is_sequential_owner_only_reproducible_and_public_safe(): void
    {
        [$world, $space, , $first, $second] = $this->worldAndNations();
        $this->resetSurface($space, [$first, $second]);
        [$left, $middle, $right] = $this->remoteLine($space, [$first, $second]);
        $this->setCell($left, 'plain', $first->id, 'city', population: 4_321, scale: 8);
        $this->setCell($middle, 'plain', $first->id, 'farm', population: 765, scale: 12);
        $this->setCell($right, 'plain', $second->id, 'monument');
        $beforeLeft = Arr::except($left->fresh()->getAttributes(), ['owner_nation_id', 'version', 'updated_at']);
        $beforeMiddle = Arr::except($middle->fresh()->getAttributes(), ['owner_nation_id', 'version', 'updated_at']);
        $firstDirection = $this->directionFrom($middle, $right);
        $secondDirection = $this->directionFrom($left, $middle);
        $seed = $this->seedForDirections([$firstDirection, $secondDirection]);
        $order = $this->surfaceOrder($space, [$middle->id, $left->id]);

        $context = $this->context($world, [$first->id, $second->id], $seed);
        $context->state->setSurfaceCellIds($order);
        $result = app(TerritoryInfluenceService::class)->execute($context);

        $this->assertSame(2, $result['mutations']);
        $this->assertSame(2, $result['direction_draws']);
        $this->assertSame($second->id, $middle->fresh()->owner_nation_id);
        $this->assertSame($second->id, $left->fresh()->owner_nation_id);
        $this->assertSame($beforeMiddle, Arr::except(
            $middle->fresh()->getAttributes(),
            ['owner_nation_id', 'version', 'updated_at'],
        ));
        $this->assertSame($beforeLeft, Arr::except(
            $left->fresh()->getAttributes(),
            ['owner_nation_id', 'version', 'updated_at'],
        ));

        $events = DB::table('audit_events')->where('event_type', 'territory.influenced')->orderBy('id')->get();
        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $metadata = json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('public', $event->visibility);
            $this->assertTrue($metadata['ownership_changed']);
            $this->assertArrayNotHasKey('facility_key', $metadata);
            $this->assertArrayNotHasKey('terrain_key', $metadata);
            $this->assertArrayNotHasKey('facility_scale', $metadata);
        }
        $public = app(PlayerIslandEventService::class)->publicWorldPage($world, 1, $context->targetTurn);
        $publicInfluence = collect($public['groups'])
            ->flatMap(static fn (array $group): array => $group['events'])
            ->where('type', 'territory.influenced')
            ->values();
        $this->assertCount(2, $publicInfluence);
        $this->assertStringContainsString('領地となりました', $publicInfluence[0]['message']);
        $this->assertStringContainsString($first->name, $publicInfluence[0]['message']);
        $this->assertStringContainsString($second->name, $publicInfluence[0]['message']);

        MapCell::query()->whereKey([$left->id, $middle->id])->update(['owner_nation_id' => $first->id]);
        $retry = $this->context($world, [$first->id, $second->id], $seed);
        $retry->state->setSurfaceCellIds($order);
        $retryResult = app(TerritoryInfluenceService::class)->execute($retry);
        $this->assertSame(2, $retryResult['mutations']);
        $this->assertSame($second->id, $left->fresh()->owner_nation_id);
        $this->assertSame($second->id, $middle->fresh()->owner_nation_id);

        $dormant = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '領土休止保護国',
            '休止島主',
        );
        $dormant->update([
            'state' => 'dormant',
            'state_reason' => 'idle',
            'state_started_turn' => 1,
        ]);
        $this->resetSurface($space, [$first, $second, $dormant]);
        [$outsideTarget, $outsideSource] = $this->remotePair($space, [$first, $second, $dormant]);
        $this->setCell($outsideTarget, 'forest', $first->id);
        $this->setCell($outsideSource, 'plain', $second->id);
        $outsideDirection = $this->directionFrom($outsideTarget, $outsideSource);
        $dormantCapital = $dormant->capital()->firstOrFail();
        $capitalCellIds = array_map(
            static fn (Nation $nation): int => (int) $nation->capital()->value('map_cell_id'),
            [$first, $second, $dormant],
        );
        $protectedTarget = null;
        $protectedSource = null;
        $protectedDirection = null;
        foreach ((new GridCoordinate($dormantCapital->x, $dormantCapital->y))->ring(2) as $coordinate) {
            $targetCandidate = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->first();
            if (! $targetCandidate instanceof MapCell
                || in_array($targetCandidate->id, $capitalCellIds, true)
                || ! $this->outsideCapitalCores($targetCandidate, [$first, $second])) {
                continue;
            }
            foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
                if ($direction === $outsideDirection) {
                    continue;
                }
                $sourceCoordinate = $coordinate->neighbor($direction);
                $sourceCandidate = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $sourceCoordinate->x)->where('y', $sourceCoordinate->y)->first();
                if ($sourceCandidate instanceof MapCell
                    && ! in_array($sourceCandidate->id, $capitalCellIds, true)
                    && ! in_array($sourceCandidate->id, [$outsideTarget->id, $outsideSource->id], true)) {
                    $protectedTarget = $targetCandidate;
                    $protectedSource = $sourceCandidate;
                    $protectedDirection = $direction;
                    break 2;
                }
            }
        }
        $this->assertInstanceOf(MapCell::class, $protectedTarget);
        $this->assertInstanceOf(MapCell::class, $protectedSource);
        $this->assertNotNull($protectedDirection);
        $this->setCell($protectedTarget, 'forest', $first->id);
        $this->setCell($protectedSource, 'plain', $second->id);

        $protectedContext = $this->context(
            $world,
            [$first->id, $second->id, $dormant->id],
            $this->seedForDirections([$protectedDirection, $outsideDirection]),
        );
        $protectedContext->state->setNationLifecycleSnapshot($dormant->id, [
            'state' => 'dormant',
            'reason' => 'idle',
            'state_started_turn' => 1,
            'resume_at_turn' => null,
            'capital_x' => $dormantCapital->x,
            'capital_y' => $dormantCapital->y,
        ]);
        $protectedContext->state->setSurfaceCellIds(
            $this->surfaceOrder($space, [$protectedTarget->id, $outsideTarget->id]),
        );
        $protectedResult = app(TerritoryInfluenceService::class)->execute($protectedContext);

        $this->assertSame(2, $protectedResult['eligible_targets']);
        $this->assertSame(2, $protectedResult['direction_draws']);
        $this->assertSame(1, $protectedResult['mutations']);
        $this->assertSame($first->id, $protectedTarget->fresh()->owner_nation_id);
        $this->assertSame($second->id, $outsideTarget->fresh()->owner_nation_id);
    }

    /** @return array{World, MapSpace, User, Nation, Nation} */
    private function worldAndNations(): array
    {
        $world = $this->lightweightWorld();
        $actorUser = User::factory()->create();
        $actor = app(NationCreationService::class)->create($actorUser, $world, '領土試験A', '試験島主A');
        $foreign = app(NationCreationService::class)->create(
            User::factory()->create(),
            $world,
            '領土試験B',
            '試験島主B',
        );

        return [$world, $this->surfaceMapSpace($world), $actorUser, $actor, $foreign];
    }

    /** @param list<Nation> $nations @return array{MapCell, MapCell} */
    private function remotePair(MapSpace $space, array $nations): array
    {
        foreach (MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->get() as $cell) {
            $coordinate = (new GridCoordinate($cell->x, $cell->y))->neighbor(GridCoordinate::EAST);
            $neighbor = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->first();
            if ($neighbor instanceof MapCell
                && $this->outsideCapitalCores($cell, $nations)
                && $this->outsideCapitalCores($neighbor, $nations)) {
                return [$cell, $neighbor];
            }
        }

        $this->fail('No remote adjacent cell pair is available.');
    }

    /** @param list<Nation> $nations @return array{MapCell, MapCell, MapCell} */
    private function remoteLine(MapSpace $space, array $nations): array
    {
        foreach (MapCell::query()->where('map_space_id', $space->id)->orderBy('id')->get() as $left) {
            $middleCoordinate = (new GridCoordinate($left->x, $left->y))->neighbor(GridCoordinate::EAST);
            $rightCoordinate = $middleCoordinate->neighbor(GridCoordinate::EAST);
            $middle = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $middleCoordinate->x)->where('y', $middleCoordinate->y)->first();
            $right = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $rightCoordinate->x)->where('y', $rightCoordinate->y)->first();
            if ($middle instanceof MapCell && $right instanceof MapCell
                && $this->outsideCapitalCores($left, $nations)
                && $this->outsideCapitalCores($middle, $nations)
                && $this->outsideCapitalCores($right, $nations)) {
                return [$left, $middle, $right];
            }
        }

        $this->fail('No remote three-cell line is available.');
    }

    /** @param list<Nation> $nations */
    private function outsideCapitalCores(MapCell $cell, array $nations): bool
    {
        foreach ($nations as $nation) {
            $capital = $nation->capital()->firstOrFail();
            if ((new GridCoordinate($cell->x, $cell->y))->distanceTo(
                new GridCoordinate($capital->x, $capital->y),
            ) <= 2) {
                return false;
            }
        }

        return true;
    }

    private function capitalCoreNeighbor(Nation $nation, MapSpace $space): MapCell
    {
        $capital = $nation->capital()->firstOrFail();
        foreach ((new GridCoordinate($capital->x, $capital->y))->neighborsWithin(
            $space->min_x,
            $space->max_x,
            $space->min_y,
            $space->max_y,
        ) as $coordinate) {
            $cell = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $coordinate->x)->where('y', $coordinate->y)->first();
            if ($cell instanceof MapCell) {
                return $cell;
            }
        }

        $this->fail('Capital has no neighbor.');
    }

    /** @param list<Nation> $nations @return array{MapCell, MapCell, int} */
    private function boundaryPair(MapSpace $space, array $nations): array
    {
        $cells = MapCell::query()->where('map_space_id', $space->id)
            ->where(function ($query) use ($space): void {
                $query->where('x', $space->min_x)->orWhere('x', $space->max_x)
                    ->orWhere('y', $space->min_y)->orWhere('y', $space->max_y);
            })->orderBy('id')->get();
        foreach ($cells as $target) {
            if (! $this->outsideCapitalCores($target, $nations)) {
                continue;
            }
            $coordinate = new GridCoordinate($target->x, $target->y);
            $missing = null;
            $source = null;
            foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
                $neighbor = $coordinate->neighbor($direction);
                $candidate = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $neighbor->x)->where('y', $neighbor->y)->first();
                if (! $candidate instanceof MapCell) {
                    $missing ??= $direction;
                } elseif ($source === null && $this->outsideCapitalCores($candidate, $nations)) {
                    $source = $candidate;
                }
            }
            if ($missing !== null && $source instanceof MapCell) {
                return [$target, $source, $missing];
            }
        }

        $this->fail('No boundary target with both a missing direction and an eligible adjacent source exists.');
    }

    /** @param list<Nation> $nations @return array{MapCell, MapCell} */
    private function capitalCoreSourcePair(MapSpace $space, Nation $sourceNation, array $nations): array
    {
        $capital = $sourceNation->capital()->firstOrFail();
        $capitalCoordinate = new GridCoordinate($capital->x, $capital->y);
        foreach ($capitalCoordinate->ring(2) as $sourceCoordinate) {
            $source = MapCell::query()->where('map_space_id', $space->id)
                ->where('x', $sourceCoordinate->x)->where('y', $sourceCoordinate->y)->first();
            if (! $source instanceof MapCell) {
                continue;
            }
            foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
                $targetCoordinate = $sourceCoordinate->neighbor($direction);
                if ($capitalCoordinate->distanceTo($targetCoordinate) !== 3) {
                    continue;
                }
                $target = MapCell::query()->where('map_space_id', $space->id)
                    ->where('x', $targetCoordinate->x)->where('y', $targetCoordinate->y)->first();
                if ($target instanceof MapCell && $this->outsideCapitalCores($target, $nations)) {
                    return [$target, $source];
                }
            }
        }

        $this->fail('No Capital core source with an adjacent non-core target exists.');
    }

    private function setAdjacentOwner(MapCell $target, int $ownerNationId): MapCell
    {
        $coordinate = (new GridCoordinate($target->x, $target->y))->neighbor(GridCoordinate::EAST);
        $neighbor = MapCell::query()->where('map_space_id', $target->map_space_id)
            ->where('x', $coordinate->x)->where('y', $coordinate->y)->firstOrFail();
        $this->setCell($neighbor, 'plain', $ownerNationId);

        return $neighbor;
    }

    private function setCell(
        MapCell $cell,
        string $terrain,
        ?int $ownerNationId,
        ?string $facility = null,
        int $population = 0,
        ?int $scale = null,
    ): void {
        $facilityDefinition = $facility === null
            ? null
            : FacilityDefinition::query()->where('key', $facility)->firstOrFail();
        $cell->update([
            'terrain_definition_id' => TerrainDefinition::query()->where('key', $terrain)->value('id'),
            'facility_definition_id' => $facilityDefinition?->id,
            'monument_definition_id' => null,
            'owner_nation_id' => $ownerNationId,
            'population' => $population,
            'terrain_quantity' => null,
            'facility_scale' => $scale ?? $facilityDefinition?->initial_scale,
            'facility_experience' => $facility === 'missile_base' ? 0 : null,
            'facility_operational_state' => null,
        ]);
        $cell->refresh()->load(['terrain', 'facility']);
    }

    /** @param list<Nation> $nations */
    private function resetSurface(MapSpace $space, array $nations): void
    {
        MapCell::query()->where('map_space_id', $space->id)->update([
            'terrain_definition_id' => TerrainDefinition::query()->where('key', 'sea')->value('id'),
            'facility_definition_id' => null,
            'monument_definition_id' => null,
            'owner_nation_id' => null,
            'population' => 0,
            'terrain_quantity' => null,
            'facility_scale' => null,
            'facility_experience' => null,
            'facility_operational_state' => null,
        ]);
        foreach ($nations as $nation) {
            $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
            $this->setCell($capital, 'plain', $nation->id, 'capital', population: 10_000);
        }
    }

    private function occupy(World $world, MapCell $cell): void
    {
        $definition = MonsterDefinition::query()
            ->where('ruleset_version_id', $world->ruleset_version_id)
            ->where('key', 'inora')->firstOrFail();
        $monster = MonsterInstance::query()->create([
            'world_id' => $world->id,
            'monster_definition_id' => $definition->id,
            'current_hp' => 1,
            'spawned_max_hp' => 1,
            'state' => 'alive',
            'spawned_target_turn' => 1,
            'version' => 1,
        ]);
        MonsterOccupancy::query()->create([
            'monster_instance_id' => $monster->id,
            'map_cell_id' => $cell->id,
        ]);
    }

    private function queue(
        User $user,
        Nation $nation,
        MapSpace $space,
        MapCell $target,
        ?int $position = null,
    ): NationCommandQueueItem {
        $queue = NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $space->id, 'version' => 1],
        );
        $position ??= NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)->where('status', 'queued')->count() + 1;
        $definition = CommandDefinition::query()->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
            ->where('key', 'territory_expand')->firstOrFail();
        $membership = NationMembership::query()->where('user_id', $user->id)
            ->where('nation_id', $nation->id)->firstOrFail();

        return NationCommandQueueItem::query()->create([
            'nation_command_queue_id' => $queue->id,
            'command_definition_id' => $definition->id,
            'queue_position' => $position,
            'target_x' => $target->x,
            'target_y' => $target->y,
            'quantity' => 1,
            'parameters' => [],
            'status' => 'queued',
            'queued_by_membership_id' => $membership->id,
            'request_key' => (string) Str::uuid(),
            'queued_at' => now(),
            'failure_metadata' => [],
        ])->load('definition');
    }

    /** @param list<int> $nationIds */
    private function context(World $world, array $nationIds, string $seed): TurnContext
    {
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $targetTurn = $this->targetTurn++;
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
        $state->setStableNationIds($nationIds);
        $state->setDevelopmentNationIds($nationIds);
        foreach ($nationIds as $nationId) {
            $state->setKarmaStartSnapshot($nationId, 0);
        }

        return new TurnContext(
            $world,
            $run,
            $ruleset,
            $targetTurn,
            $seed,
            new TurnRandomStreamFactory($seed),
            $state,
        );
    }

    /** @param list<int> $firstCellIds @return list<int> */
    private function surfaceOrder(MapSpace $space, array $firstCellIds): array
    {
        $all = MapCell::query()->where('map_space_id', $space->id)->orderBy('id')
            ->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        return [...$firstCellIds, ...array_values(array_diff($all, $firstCellIds))];
    }

    private function directionFrom(MapCell $target, MapCell $source): int
    {
        $coordinate = new GridCoordinate($target->x, $target->y);
        foreach (array_keys(GridCoordinate::DIRECTION_NAMES) as $direction) {
            $neighbor = $coordinate->neighbor($direction);
            if ($neighbor->x === $source->x && $neighbor->y === $source->y) {
                return $direction;
            }
        }

        $this->fail('Source is not adjacent to target.');
    }

    /** @param non-empty-list<int> $directions */
    private function seedForDirections(array $directions): string
    {
        for ($candidate = 0; $candidate < 100_000; $candidate++) {
            $seed = hash('sha256', "territory-influence-{$candidate}");
            $stream = (new TurnRandomStreamFactory($seed))->stream(
                TurnRandomStreamFactory::TERRITORY_INFLUENCE_DIRECTION,
            );
            $matches = true;
            foreach ($directions as $direction) {
                if ($stream->integer(0, 5) !== $direction) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return $seed;
            }
        }

        $this->fail('Unable to find the requested deterministic territory influence directions.');
    }
}
