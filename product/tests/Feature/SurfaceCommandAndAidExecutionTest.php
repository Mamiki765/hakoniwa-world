<?php

namespace Tests\Feature;

use App\Application\CommandQueueService;
use App\Application\DomesticCommandExecutor;
use App\Application\PlayerIslandEventService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Map\GridCoordinate;
use App\Domain\Map\MapCellStateService;
use App\Domain\Secretary\SecretarySkillCatalog;
use App\Models\FacilityDefinition;
use App\Models\MapCell;
use App\Models\MonsterInstance;
use App\Models\MonsterOccupancy;
use App\Models\Nation;
use App\Models\TerrainDefinition;
use App\Models\User;
use App\Models\World;
use App\Services\MapCellPresenter;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\UsesReusableSurfaceWorld;
use Tests\Support\CommandAndMissileTestCase;

final class SurfaceCommandAndAidExecutionTest extends CommandAndMissileTestCase
{
    use UsesReusableSurfaceWorld;

    public function test_remaining_cell_commands_apply_their_audited_effects_and_exact_costs(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '残存開発国');
        $space = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail()->cell()->firstOrFail();
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)
            ->whereKeyNot($capital->id)->orderBy('id')->limit(6)->get();
        $this->assertCount(6, $owned);
        $plain = TerrainDefinition::query()->where('key', 'plain')->firstOrFail();
        foreach ($owned as $cell) {
            app(MapCellStateService::class)->setFacility($cell, null);
            app(MapCellStateService::class)->transitionTerrain($cell, $plain);
            $cell->population = 0;
            $cell->save();
        }
        $cityTarget = $owned[5];
        app(MapCellStateService::class)->setFacility(
            $cityTarget,
            FacilityDefinition::query()->where('key', 'city')->firstOrFail(),
        );
        $cityTarget->population = 12_345;
        $cityTarget->save();
        $oldCapitalPopulation = $capital->population;

        [$territoryTarget, $seabedTarget] = $this->neutralCellsNearTerritory($nation, $space, 2);
        app(MapCellStateService::class)->setFacility($territoryTarget, null);
        app(MapCellStateService::class)->transitionTerrain(
            $territoryTarget,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        app(MapCellStateService::class)->setFacility($seabedTarget, null);
        app(MapCellStateService::class)->transitionTerrain(
            $seabedTarget,
            TerrainDefinition::query()->where('key', 'sea')->firstOrFail(),
        );
        foreach ([$territoryTarget, $seabedTarget] as $cell) {
            $cell->owner_nation_id = null;
            $cell->population = 0;
            $cell->save();
        }

        $service = app(CommandQueueService::class);
        $items = [
            $this->queue($service, $user, $nation, $space, 'territory_expand', $territoryTarget),
            $this->queue($service, $user, $nation, $space, 'plant_forest', $owned[0]),
            $this->queue($service, $user, $nation, $space, 'build_missile_base', $owned[1]),
            $this->queue($service, $user, $nation, $space, 'build_defense_facility', $owned[2]),
            $this->queue($service, $user, $nation, $space, 'build_seabed_base', $seabedTarget),
            $this->queue($service, $user, $nation, $space, 'build_monument', $owned[3]),
            $this->queue($service, $user, $nation, $space, 'build_decoy', $owned[4]),
            $this->queue($service, $user, $nation, $space, 'relocate_capital', $cityTarget),
        ];
        $costs = [100, 50, 300, 800, 8_000, 9_999, 1, 1_000];
        foreach ($costs as $index => $cost) {
            Nation::query()->whereKey($nation->id)->update(['money' => 9_999]);
            app(DomesticCommandExecutor::class)->execute($this->context(
                $world,
                $index + 2,
                hash('sha256', "remaining-cell-command:{$index}"),
                [$nation->id],
            ));
            $this->assertSame(9_999 - $cost, $nation->fresh()->money);
            $this->assertSame('completed', $items[$index]->fresh()->status);
        }

        $this->assertSame($nation->id, $territoryTarget->fresh()->owner_nation_id);
        $this->assertSame('forest', $owned[0]->fresh()->terrain()->value('key'));
        $this->assertSame('missile_base', $owned[1]->fresh()->facility()->value('key'));
        $this->assertSame('defense', $owned[2]->fresh()->facility()->value('key'));
        $this->assertSame('seabed_base', $seabedTarget->fresh()->facility()->value('key'));
        $this->assertSame($nation->id, $seabedTarget->fresh()->owner_nation_id);
        $this->assertSame('monument', $owned[3]->fresh()->facility()->value('key'));
        $this->assertSame('peace', $owned[3]->fresh()->monumentDefinition()->value('key'));
        $this->assertSame('decoy', $owned[4]->fresh()->facility()->value('key'));
        $this->assertSame('capital', $cityTarget->fresh()->facility()->value('key'));
        $this->assertSame(12_345, $cityTarget->fresh()->population);
        $this->assertSame('city', $capital->fresh()->facility()->value('key'));
        $this->assertSame($oldCapitalPopulation, $capital->fresh()->population);
        $this->assertSame($cityTarget->id, $nation->capital()->value('map_cell_id'));

        $publicEvents = collect(app(PlayerIslandEventService::class)->publicNationPage($nation, 1, 9)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $forestMessages = $publicEvents->filter(
            fn (array $event): bool => str_contains($event['message'], 'どこかで森が増えた気がします。'),
        );
        $this->assertCount(2, $forestMessages);
        $this->assertTrue($forestMessages->every(
            fn (array $event): bool => $event['type'] === 'command.forest_planted_public'
                && ! str_contains($event['message'], '('),
        ));
        $this->assertFalse($publicEvents->contains(
            fn (array $event): bool => in_array($event['type'], [
                'command.missile_base_built_public',
                'command.decoy_built_public',
            ], true),
        ));
        $this->assertTrue($publicEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.seabed_base_built_public'
                && str_contains($event['message'], '(?,?)'),
        ));
        $this->assertSame(2, $publicEvents->where('type', 'command.facility_built_public')
            ->filter(fn (array $event): bool => str_contains($event['message'], '防衛施設'))->count());
        $this->assertTrue($publicEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.capital_relocated_public'
                && $event['message'] === sprintf(
                    '残存開発国の首都が(%d,%d)から(%d,%d)へ移転しました。',
                    $capital->x,
                    $capital->y,
                    $cityTarget->x,
                    $cityTarget->y,
                ),
        ));
    }

    public function test_undersea_city_command_is_atomic_near_territory_retry_safe_and_disguised(): void
    {
        $world = $this->lightweightWorld();
        [$user, $nation] = $this->nation($world, '海底都市国');
        $space = $this->surfaceMapSpace($world);
        $capital = $nation->capital()->firstOrFail()->cell()->with(['terrain', 'facility'])->firstOrFail();
        $nearTargets = $this->neutralCellsNearTerritory($nation, $space, 3);
        $owned = MapCell::query()->where('owner_nation_id', $nation->id)->get(['x', 'y']);
        $farTarget = MapCell::query()->where('map_space_id', $space->id)
            ->whereNull('owner_nation_id')->orderByDesc('id')->get()
            ->first(static function (MapCell $candidate) use ($owned): bool {
                $coordinate = new GridCoordinate($candidate->x, $candidate->y);

                return ! $owned->contains(static fn (MapCell $cell): bool => $coordinate->distanceTo(
                    new GridCoordinate($cell->x, $cell->y),
                ) <= 3);
            });
        $this->assertInstanceOf(MapCell::class, $farTarget);
        $sea = TerrainDefinition::query()->where('key', 'sea')->firstOrFail();
        foreach ([...$nearTargets, $farTarget] as $target) {
            app(MapCellStateService::class)->setFacility($target, null);
            app(MapCellStateService::class)->transitionTerrain($target, $sea);
            $target->owner_nation_id = null;
            $target->population = 0;
            $target->save();
        }

        $capital->update(['population' => 3100]);
        $nation->update(['money' => 999]);
        $insufficientFunds = $this->queue(
            app(CommandQueueService::class),
            $user,
            $nation->fresh(),
            $space,
            'build_undersea_city',
            $nearTargets[0],
        );
        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'undersea city insufficient funds'),
            [$nation->id],
        ));
        $this->assertSame('insufficient_funds', $insufficientFunds->fresh()->failure_code);
        $this->assertSame(3100, $capital->fresh()->population);
        $this->assertNull($nearTargets[0]->fresh()->facility_definition_id);
        $this->assertSame(1009, (int) $nation->fresh()->money, 'Failed command keeps funds; canonical automatic finance still applies.');

        $capital->update(['population' => 3099]);
        $nation->update(['money' => 1000]);
        $insufficientPopulation = $this->queue(
            app(CommandQueueService::class),
            $user,
            $nation->fresh(),
            $space,
            'build_undersea_city',
            $nearTargets[1],
        );
        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            3,
            hash('sha256', 'undersea city insufficient population'),
            [$nation->id],
        ));
        $this->assertSame('insufficient_population', $insufficientPopulation->fresh()->failure_code);
        $this->assertSame(3099, $capital->fresh()->population);
        $this->assertNull($nearTargets[1]->fresh()->facility_definition_id);
        $this->assertSame(1010, (int) $nation->fresh()->money);

        $capital->update(['population' => 3100]);
        $nation->update(['money' => 1000]);
        $tooFar = $this->queue(
            app(CommandQueueService::class),
            $user,
            $nation->fresh(),
            $space,
            'build_undersea_city',
            $farTarget,
        );
        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            4,
            hash('sha256', 'undersea city too far'),
            [$nation->id],
        ));
        $this->assertSame('missing_adjacent_territory', $tooFar->fresh()->failure_code);
        $this->assertSame(3100, $capital->fresh()->population);
        $this->assertNull($farTarget->fresh()->facility_definition_id);

        $nation->update(['money' => 1000]);
        $builtItem = $this->queue(
            app(CommandQueueService::class),
            $user,
            $nation->fresh(),
            $space,
            'build_undersea_city',
            $nearTargets[2],
        );
        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            5,
            hash('sha256', 'undersea city success'),
            [$nation->id],
        ));
        $built = $nearTargets[2]->fresh(['terrain', 'facility', 'ownerNation']);
        $this->assertSame('completed', $builtItem->fresh()->status);
        $moneyAfterBuild = (int) $nation->fresh()->money;
        $successMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.success')
            ->where('subject_id', $builtItem->id)
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1000, $successMetadata['cost_money']);
        $this->assertSame(100, $capital->fresh()->population);
        $this->assertSame('sea', $built->terrain->key);
        $this->assertSame('undersea_city', $built->facility?->key);
        $this->assertSame($nation->id, $built->owner_nation_id);
        $this->assertSame(3000, $built->population);

        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            6,
            hash('sha256', 'undersea city completed retry'),
            [$nation->id],
        ));
        $this->assertSame($moneyAfterBuild + 10, (int) $nation->fresh()->money,
            'Retry runs only canonical automatic finance.');
        $this->assertSame(100, $capital->fresh()->population);
        $this->assertSame(3000, $built->fresh()->population);
        $this->assertSame(1, MapCell::query()->where('facility_definition_id', $built->facility_definition_id)->count());

        $owner = app(MapCellPresenter::class)->present($built, $nation->id, 6);
        $public = app(MapCellPresenter::class)->present($built, null, 6);
        $this->assertSame(['sea', 'undersea_city', $nation->id], [
            $owner['terrain'], $owner['facility'], $owner['owner_nation_id'],
        ]);
        $this->assertSame(3000, collect($owner['details'])->firstWhere('key', 'population')['value'] ?? null);
        $this->assertSame(['sea', null, null], [
            $public['terrain'], $public['facility'], $public['owner_nation_id'],
        ]);
        $this->assertNull(collect($public['details'])->firstWhere('key', 'population'));

        $events = app(PlayerIslandEventService::class);
        $ownerEvents = collect($events->ownerPage($nation->fresh(), 1, 6)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $publicEvents = collect($events->publicNationPage($nation->fresh(), 1, 6)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertTrue($ownerEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.undersea_city_built_private'
                && str_contains($event['message'], "({$built->x},{$built->y})"),
        ));
        $publicEvent = $publicEvents->firstWhere('type', 'command.undersea_city_built_public');
        $this->assertIsArray($publicEvent);
        $this->assertStringContainsString('(?,?)', $publicEvent['message']);
        $this->assertStringNotContainsString("({$built->x},{$built->y})", $publicEvent['message']);
        $this->assertArrayNotHasKey('x', $publicEvent);
        $this->assertArrayNotHasKey('y', $publicEvent);
        $publicAuditMetadata = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.undersea_city_built_public')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('x', $publicAuditMetadata);
        $this->assertArrayNotHasKey('y', $publicAuditMetadata);
    }

    public function test_aid_attraction_and_monster_dispatch_execute_as_nation_commands(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '支援派遣国');
        [, $receiver] = $this->nation($world, '支援受領国');
        $space = $this->surfaceMapSpace($world);
        $sender->update(['money' => 9_999]);
        $receiver->update(['money' => 100]);
        $wheat = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheat);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheat],
            ['amount' => 5_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheat],
            ['amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $moneyAid = $this->queue($service, $user, $sender, $space, 'money_aid', null, 2, null, $parameters);
        $foodAid = $this->queue($service, $user, $sender, $space, 'food_aid', null, 2, null, $parameters);
        $dispatch = $this->queue($service, $user, $sender, $space, 'monster_dispatch', null, 1, null, $parameters);

        $context = $this->context($world, 2, hash('sha256', 'nation command transfer and dispatch'), [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($context);

        $this->assertSame(6_799, $sender->fresh()->money);
        $this->assertSame(300, $receiver->fresh()->money);
        $this->assertSame(3_000, (int) DB::table('nation_resources')
            ->where('nation_id', $sender->id)->where('resource_definition_id', $wheat)->value('amount'));
        $this->assertSame(2_000, (int) DB::table('nation_resources')
            ->where('nation_id', $receiver->id)->where('resource_definition_id', $wheat)->value('amount'));
        foreach ([$moneyAid, $foodAid, $dispatch] as $item) {
            $this->assertSame('completed', $item->fresh()->status);
        }
        $this->assertSame(1, MonsterInstance::query()->where('world_id', $world->id)
            ->whereHas('definition', fn ($query) => $query->where('key', 'mecha_inora'))->count());
        $this->assertSame(1, MonsterOccupancy::query()->whereHas(
            'cell',
            fn ($query) => $query->where('owner_nation_id', $receiver->id),
        )->count());
        Nation::query()->whereKey($sender->id)->update(['name' => '現在送信国']);
        Nation::query()->whereKey($receiver->id)->update(['name' => '現在受信国']);
        $senderMessages = collect(app(PlayerIslandEventService::class)->ownerPage($sender, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $receiverMessages = collect(app(PlayerIslandEventService::class)->ownerPage($receiver, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($senderMessages->contains(
            fn (string $message): bool => str_contains($message, '支援受領国へ資金援助として200億円'),
        ));
        $this->assertTrue($senderMessages->contains(
            fn (string $message): bool => str_contains($message, '支援受領国へ食料援助として2,000トン'),
        ));
        $this->assertTrue($receiverMessages->contains(
            fn (string $message): bool => str_contains($message, '支援派遣国から資金援助として200億円'),
        ));
        $this->assertTrue($receiverMessages->contains(
            fn (string $message): bool => str_contains($message, '支援派遣国から食料援助として2,000トン'),
        ));
        $eventService = app(PlayerIslandEventService::class);
        $worldAidEvents = collect($eventService->publicWorldPage($world, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $senderAidEvents = collect($eventService->publicNationPage($sender->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $receiverAidEvents = collect($eventService->publicNationPage($receiver->fresh(), 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])
            ->whereIn('type', ['command.money_aid_public', 'command.food_aid_public'])->values();
        $this->assertCount(2, $worldAidEvents);
        $this->assertSame($worldAidEvents->pluck('id')->all(), $senderAidEvents->pluck('id')->all());
        $this->assertSame($worldAidEvents->pluck('id')->all(), $receiverAidEvents->pluck('id')->all());
        $this->assertEqualsCanonicalizing([
            '支援派遣国から支援受領国へ200億円の資金援助が行われました。',
            '支援派遣国から支援受領国へ食料2,000トンの援助が行われました。',
        ], $worldAidEvents->pluck('message')->all());
        $publicAidJson = json_encode($worldAidEvents->all(), JSON_THROW_ON_ERROR);
        foreach (['requested_money', 'receiver_capacity', 'requested_food_tons', 'sender_money_before'] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $publicAidJson);
        }

        $sender->refresh();
        $attraction = $this->queue($service, $user, $sender, $space, 'attraction', null);
        $sender->update(['money' => 1_000]);
        $attractionContext = $this->context($world, 3, hash('sha256', 'attraction command'), [$sender->id]);
        app(DomesticCommandExecutor::class)->execute($attractionContext);
        $this->assertTrue($attractionContext->state->hasAttraction($sender->id));
        $this->assertSame(0, $sender->fresh()->money);
        $this->assertSame('completed', $attraction->fresh()->status);
        $attractionEvents = collect($eventService->publicNationPage($sender->fresh(), 1, 3)['groups'])
            ->flatMap(fn (array $group): array => $group['events']);
        $this->assertTrue($attractionEvents->contains(
            fn (array $event): bool => $event['type'] === 'command.attraction_started_public'
                && $event['message'] === '現在送信国で誘致活動が行われました。',
        ));
    }

    public function test_zero_effect_money_and_food_aid_preserve_assets_and_increment_idle_through_automatic_finance(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '援助送信国');
        [, $receiver] = $this->nation($world, '援助上限国');
        $space = $this->surfaceMapSpace($world);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $ruleset);
        $sender->update(['money' => 1_000, 'idle_counter' => 3]);
        $receiver->update(['money' => $capacity->money]);
        $wheatId = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheatId);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheatId],
            ['amount' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheatId],
            ['amount' => $capacity->foodTons, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $moneyAid = $this->queue($service, $user, $sender, $space, 'money_aid', null, 1, null, $parameters);
        $foodAid = $this->queue($service, $user, $sender, $space, 'food_aid', null, 1, null, $parameters);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'zero effect aid'),
            [$sender->id],
        ));

        $this->assertSame(2, $result['successes']);
        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame(1, $result['idle_counter_increments']);
        $this->assertSame(0, $result['idle_counter_resets']);
        $this->assertSame(1_010, $sender->fresh()->money);
        $this->assertSame($capacity->money, $receiver->fresh()->money);
        $this->assertSame(2_000, (int) DB::table('nation_resources')
            ->where('nation_id', $sender->id)->where('resource_definition_id', $wheatId)->value('amount'));
        $this->assertSame($capacity->foodTons, (int) DB::table('nation_resources')
            ->where('nation_id', $receiver->id)->where('resource_definition_id', $wheatId)->value('amount'));
        $this->assertSame(4, $sender->fresh()->idle_counter);
        $this->assertSame('completed', $moneyAid->fresh()->status);
        $this->assertSame('completed', $foodAid->fresh()->status);
        $this->assertSame(0, (int) DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')
            ->value(DB::raw("(metadata->>'transferred_money')::integer")));
        $this->assertSame(0, (int) DB::table('audit_events')->where('event_type', 'command.food_aid_transferred')
            ->value(DB::raw("(metadata->>'transferred_food_tons')::integer")));
        $this->assertSame(0, DB::table('audit_events')->whereIn('event_type', [
            'command.money_aid_public', 'command.food_aid_public',
        ])->count());
        $events = app(PlayerIslandEventService::class);
        $messages = collect($events->ownerPage($sender, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $receiverMessages = collect($events->ownerPage($receiver, 1, 2)['groups'])
            ->flatMap(fn (array $group): array => $group['events'])->pluck('message');
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, '資金収容上限')));
        $this->assertTrue($messages->contains(fn (string $message): bool => str_contains($message, '食料収容上限')));
        $this->assertTrue($receiverMessages->contains(fn (string $message): bool => str_contains($message, '資金収容上限')));
        $this->assertTrue($receiverMessages->contains(fn (string $message): bool => str_contains($message, '食料収容上限')));
    }

    public function test_partial_aid_is_meaningful_and_resets_idle_with_exact_transfer_and_overflow(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '部分援助送信国');
        [, $receiver] = $this->nation($world, '部分援助受信国');
        $space = $this->surfaceMapSpace($world);
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $ruleset);
        $sender->update(['money' => 1_000, 'idle_counter' => 5]);
        $receiver->update(['money' => $capacity->money - 50]);
        $wheatId = DB::table('resource_definitions')->where('key', 'wheat')->value('id');
        $this->assertIsInt($wheatId);
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $sender->id, 'resource_definition_id' => $wheatId],
            ['amount' => 2_000, 'created_at' => now(), 'updated_at' => now()],
        );
        DB::table('nation_resources')->updateOrInsert(
            ['nation_id' => $receiver->id, 'resource_definition_id' => $wheatId],
            ['amount' => $capacity->foodTons - 500, 'created_at' => now(), 'updated_at' => now()],
        );
        $parameters = ['target_nation_id' => $receiver->id];
        $service = app(CommandQueueService::class);
        $this->queue($service, $user, $sender, $space, 'money_aid', null, 2, null, $parameters);
        $this->queue($service, $user, $sender, $space, 'food_aid', null, 1, null, $parameters);

        $result = app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'partial effect aid'),
            [$sender->id],
        ));

        $this->assertSame(1, $result['automatic_finance']);
        $this->assertSame(0, $result['idle_counter_increments']);
        $this->assertSame(1, $result['idle_counter_resets']);
        $this->assertSame(0, $sender->fresh()->idle_counter);
        $this->assertSame(960, $sender->fresh()->money);
        $this->assertSame($capacity->money, $receiver->fresh()->money);
        $moneyEvent = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.money_aid_transferred')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $foodEvent = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'command.food_aid_transferred')->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(50, $moneyEvent['transferred_money']);
        $this->assertSame(150, $moneyEvent['receiver_capacity_overflow']);
        $this->assertSame(500, $foodEvent['transferred_food_tons']);
        $this->assertSame(500, $foodEvent['receiver_capacity_overflow_tons']);
    }

    public function test_zero_effect_aid_transaction_rollback_does_not_duplicate_idle_or_transfer_events(): void
    {
        $world = $this->lightweightWorld();
        [$user, $sender] = $this->nation($world, '援助再試行国');
        [, $receiver] = $this->nation($world, '援助再試行対象国');
        $space = $this->surfaceMapSpace($world);
        $capacity = app(NationCapacityResolver::class)->resolve($receiver, $world->rulesetVersion()->firstOrFail());
        $sender->update(['money' => 1_000, 'idle_counter' => 2]);
        $receiver->update(['money' => $capacity->money]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $user,
            $sender,
            $space,
            'money_aid',
            null,
            1,
            null,
            ['target_nation_id' => $receiver->id],
        );

        try {
            DB::transaction(function () use ($world, $sender): void {
                app(DomesticCommandExecutor::class)->execute($this->context(
                    $world,
                    2,
                    hash('sha256', 'rolled back zero aid'),
                    [$sender->id],
                ));
                throw new RuntimeException('force rollback');
            });
            $this->fail('The forced rollback did not occur.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertSame('queued', $item->fresh()->status);
        $this->assertSame(2, $sender->fresh()->idle_counter);
        $this->assertSame(0, DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')->count());

        app(DomesticCommandExecutor::class)->execute($this->context(
            $world,
            2,
            hash('sha256', 'retried zero aid'),
            [$sender->id],
        ));

        $this->assertSame(3, $sender->fresh()->idle_counter);
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'command.money_aid_transferred')->count());
        $this->assertSame(1, DB::table('audit_events')->where('event_type', 'nation.idle_counter_changed')->count());
    }

    public function test_v13_zero_point_monster_impact_still_reduces_victim_and_credits_alliance_money(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('karma-monster-impact');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 20]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')->with(['terrain', 'facility', 'ownerNation'])
            ->firstOrFail();
        app(MapCellStateService::class)->setFacility($cell, null);
        app(MapCellStateService::class)->transitionTerrain(
            $cell,
            TerrainDefinition::query()->where('key', 'wasteland')->firstOrFail(),
        );
        $cell->population = 0;
        $cell->save();
        $monster = $this->monster($world, $cell);
        $monster->update(['current_hp' => 2, 'spawned_max_hp' => 2]);
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'missile',
            $cell,
        );
        $cost = (int) $item->definition()->value('cost_money');

        $result = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $item,
            [$base],
            2,
            static function (): void {},
            $this->seedForImpactIndex($item, $cell, 2, $cell),
        );

        $this->assertSame(1, $result['shots_fired']);
        $this->assertTrue($result['classification']['anti_monster_context']);
        $this->assertSame(0, $result['crime_points']);
        $this->assertSame(1, (int) $monster->fresh()->current_hp);
        $this->assertSame(19, (int) $target->fresh()->karma);
        $this->assertSame(9_999 - $cost + 20, (int) $firing->fresh()->money);
        $impact = json_decode((string) DB::table('audit_events')
            ->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])
            ->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(0, $impact['impact_category_points']);
        $this->assertSame(0, $impact['crime_points']);
        $this->assertSame(20, $impact['alliance_money']);
    }

    public function test_v21_rank_two_ordinary_facility_damage_preserves_cell_and_adds_one_karma_point(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('rank-two-ordinary-facility');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);

        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'farm')->firstOrFail(),
            scale: 51,
            maximumScale: 100,
        );
        $cell->owner_nation_id = $target->id;
        $cell->population = 0;
        $cell->save();
        $before = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $beforeVersion = (int) $before->version;
        $context = $this->resolveKarmaMissileTurn(
            $world,
            $firingUser,
            $firing,
            $target,
            $base,
            'spp_missile',
            $cell,
            2,
        );

        $after = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $this->assertSame('farm', $after->facility?->key);
        $this->assertSame(50, $after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($target->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $this->assertSame($beforeVersion + 1, (int) $after->version);
        $this->assertSame([$after->map_chunk_id], $context->state->changedMapChunkIds());

        $impact = DB::table('audit_events')->where('event_type', 'missile.impact')
            ->whereRaw("metadata->>'effect' = 'facility_scale_damaged'")
            ->orderByDesc('id')->value('metadata');
        $this->assertIsString($impact);
        $impact = json_decode($impact, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('facility_scale_damaged', $impact['effect']);
        $this->assertSame('farm', $impact['facility_key']);
        $this->assertSame(51, $impact['before_scale']);
        $this->assertSame(50, $impact['after_scale']);
        $this->assertSame(1, $impact['scale_loss']);
        $this->assertArrayNotHasKey('removed_facility_key', $impact);

        $karma = DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'effect' = 'facility_scale_damaged'")
            ->orderByDesc('id')->value('metadata');
        $this->assertIsString($karma);
        $karma = json_decode($karma, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $karma['impact_category_points']);
        $this->assertSame(1, $karma['crime_points']);
        $this->assertSame(1, (int) $firing->fresh()->karma);
    }

    #[DataProvider('ordinaryMissileKeys')]
    public function test_v23_central_facility_resists_normal_pp_and_spp_missiles(string $missileKey): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants("central-resistance-{$missileKey}");
        $firing->update(['money' => 9_999, 'karma' => 0]);
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'central_bank')->firstOrFail(),
            scale: 12,
            maximumScale: 90,
        );
        $cell->save();
        $beforeVersion = (int) $cell->version;
        $item = $this->queue(app(CommandQueueService::class), $firingUser, $firing, $space, $missileKey, $cell);
        $deviationRadius = match ($missileKey) {
            'missile' => 2,
            'pp_missile' => 1,
            'spp_missile' => 0,
        };
        $seed = $this->seedForImpactIndex($item, $cell, $deviationRadius, $cell);

        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $item,
            [$base],
            2,
            static function (): void {},
            $seed,
        );

        $after = $cell->fresh(['terrain', 'facility']);
        $this->assertSame('central_bank', $after->facility?->key, $missileKey);
        $this->assertSame(12, $after->facility_scale, $missileKey);
        $this->assertSame('plain', $after->terrain->key, $missileKey);
        $this->assertSame($beforeVersion, (int) $after->version, $missileKey);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $impact = $detail['impacts'][0];
        $this->assertSame('central_facility_resisted', $impact['effect'], $missileKey);
        $this->assertSame(0, (int) $firing->fresh()->karma, $missileKey);
    }

    public function test_v23_land_destruction_missile_reduces_level_one_central_facility_to_shallow(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('central-land-destruction');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        DB::table('secretary_skills')->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);
        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'central_granary')->firstOrFail(),
            scale: 1,
            maximumScale: 90,
        );
        $cell->save();
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $cell,
        );

        $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $item,
            [$base],
            2,
            static function (): void {},
            $this->seedForImpactIndex($item, $cell, 2, $cell),
        );

        $after = $cell->fresh(['terrain', 'facility']);
        $this->assertNull($after->facility_definition_id);
        $this->assertNull($after->facility_scale);
        $this->assertSame('shallow', $after->terrain->key);
        $this->assertNull($after->owner_nation_id);
        $detail = json_decode((string) DB::table('audit_events')->where('event_type', 'missile.launch_detail')
            ->whereRaw("metadata->>'queue_item_id' = ?", [(string) $item->id])->value('metadata'), true, 512, JSON_THROW_ON_ERROR);
        $impact = $detail['impacts'][0];
        $this->assertSame('terrain_destroyed', $impact['effect']);
        $this->assertSame('central_granary', $impact['removed_facility_key']);
        $this->assertSame(1, $impact['scale_loss']);
    }

    public function test_v21_rank_two_land_facility_damage_preserves_cell_and_adds_three_karma_points(): void
    {
        [$world, $firingUser, $firing, $target] = $this->combatants('rank-two-land-facility');
        $firing->update(['money' => 9_999, 'karma' => 0]);
        $target->update(['karma' => 0]);
        DB::table('secretary_skills')
            ->where('skill_key', SecretarySkillCatalog::FINAL_DEFENSE_LINE)
            ->update(['level' => 0, 'experience' => 0]);

        $space = $this->surfaceMapSpace($world);
        $base = $this->missileBase($firing);
        $cell = MapCell::query()->where('owner_nation_id', $target->id)
            ->whereKeyNot($target->capital()->value('map_cell_id'))
            ->whereNull('facility_definition_id')
            ->whereHas('terrain', fn ($query) => $query->where('key', 'plain'))
            ->with(['terrain', 'facility', 'ownerNation'])->firstOrFail();
        app(MapCellStateService::class)->setFacility(
            $cell,
            FacilityDefinition::query()->where('key', 'farm')->firstOrFail(),
            scale: 51,
            maximumScale: 100,
        );
        $cell->owner_nation_id = $target->id;
        $cell->population = 0;
        $cell->save();
        $before = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $beforeVersion = (int) $before->version;
        $item = $this->queue(
            app(CommandQueueService::class),
            $firingUser,
            $firing,
            $space,
            'land_destruction_missile',
            $cell,
        );
        $seed = $this->seedForImpactIndex($item, $cell, 2, $cell);

        $result = $this->resolveKarmaLaunchWithBoundaryMutation(
            $world,
            $firing,
            $target,
            $item,
            [$base],
            2,
            static function (): void {},
            $seed,
        );

        $this->assertSame(1, $result['shots_fired']);
        $after = $cell->fresh(['terrain', 'facility', 'ownerNation']);
        $this->assertSame('farm', $after->facility?->key);
        $this->assertSame(48, $after->facility_scale);
        $this->assertSame('plain', $after->terrain->key);
        $this->assertSame($target->id, $after->owner_nation_id);
        $this->assertSame(0, $after->population);
        $this->assertSame($beforeVersion + 1, (int) $after->version);
        $this->assertSame([$after->map_chunk_id], $result['changed_map_chunk_ids']);

        $impact = DB::table('audit_events')->where('event_type', 'missile.impact')
            ->whereRaw("metadata->>'effect' = 'facility_scale_land_damaged'")
            ->orderByDesc('id')->value('metadata');
        $this->assertIsString($impact);
        $impact = json_decode($impact, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('facility_scale_land_damaged', $impact['effect']);
        $this->assertSame('farm', $impact['facility_key']);
        $this->assertSame(51, $impact['before_scale']);
        $this->assertSame(48, $impact['after_scale']);
        $this->assertSame(3, $impact['scale_loss']);
        $this->assertArrayNotHasKey('removed_facility_key', $impact);

        $karma = DB::table('audit_events')->where('event_type', 'karma.missile_impact')
            ->whereRaw("metadata->>'effect' = 'facility_scale_land_damaged'")
            ->orderByDesc('id')->value('metadata');
        $this->assertIsString($karma);
        $karma = json_decode($karma, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $karma['impact_category_points']);
        $this->assertSame(3, $karma['crime_points']);
        $this->assertSame(3, (int) $firing->fresh()->karma);
    }

    /** @return array{World, User, Nation, Nation} */
}
