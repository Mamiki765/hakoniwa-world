<?php

namespace App\Application;

use App\Domain\Nation\NationProfileText;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\World\RegistrationWorldExpansionPlanner;
use App\Domain\World\WorldMutationLock;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NationCreationService
{
    public function __construct(
        private readonly CapitalPlacementService $placement,
        private readonly InitialIslandGenerator $islands,
        private readonly NationResourceService $resources,
        private readonly WorldExpansionService $expansion,
        private readonly RegistrationWorldExpansionPlanner $expansionPlanner,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly WorldMutationLock $worldMutationLock,
    ) {}

    public function create(
        User $user,
        World $world,
        string $name,
        string $ownerName,
        string $profileComment = '',
        ?string $requestKey = null,
    ): Nation {
        $ownerName = NationProfileText::ownerName($ownerName);
        $profileComment = NationProfileText::comment($profileComment);

        try {
            $this->worldMutationLock->acquire($world);
        } catch (TurnAlreadyRunningException $exception) {
            throw new DomainException('このWorldは現在更新中です。後でもう一度登録してください。', previous: $exception);
        }

        try {
            return DB::transaction(function () use ($user, $world, $name, $ownerName, $profileComment, $requestKey): Nation {
                $world = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $ruleset = $world->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($world, $ruleset);
                $rules = $ruleset->settings;

                $requestKey ??= (string) Str::uuid();
                $existingRequest = DB::table('nation_creation_requests')
                    ->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existingRequest !== null) {
                    if ((int) $existingRequest->user_id !== $user->id
                        || (int) $existingRequest->world_id !== $world->id) {
                        throw new DomainException('この登録request keyは別の登録要求で使用済みです。');
                    }
                    if ($existingRequest->status !== 'completed' || $existingRequest->nation_id === null) {
                        throw new DomainException('同一登録要求が未完了状態です。追加処理せず調査が必要です。');
                    }

                    return Nation::query()->whereKey($existingRequest->nation_id)
                        ->where('world_id', $world->id)
                        ->with(['capital', 'resourceBalances.definition'])
                        ->firstOrFail();
                }

                if (NationMembership::query()->where('user_id', $user->id)->where('world_id', $world->id)->exists()) {
                    throw new DomainException('このWorldにはすでにNationがあります。');
                }
                if (Nation::query()->where('world_id', $world->id)->where('name', $name)->exists()) {
                    throw new DomainException('この島名はすでに使用されています。');
                }

                $largestNationNumber = (int) Nation::query()
                    ->where('world_id', $world->id)
                    ->max('nation_number');
                if ($largestNationNumber >= 2_147_483_647) {
                    throw new DomainException('このWorldではこれ以上Nation番号を採番できません。');
                }
                $nationNumber = $largestNationNumber + 1;

                $mapSpace = MapSpace::query()
                    ->where('world_id', $world->id)
                    ->where('key', config('hakoniwa.world.map_space_key'))
                    ->firstOrFail();
                $seed = hash('sha256', implode(':', [
                    $world->id, $user->id, mb_strtolower($name), config('hakoniwa.initial_island.generator_version'),
                ]));

                DB::table('nation_creation_requests')->insert([
                    'request_key' => $requestKey, 'user_id' => $user->id, 'world_id' => $world->id,
                    'status' => 'reserving', 'generation_seed' => $seed,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                $candidates = $this->placement->candidates($mapSpace, 1);
                if ($candidates === []) {
                    $before = $mapSpace->currentBounds();
                    $target = $this->expansionPlanner->nextBounds($before);
                    $mapSpace = $this->expansion->expandWithinCurrentMutation(
                        $world,
                        $before,
                        $target,
                        $user,
                        'nation_registration_capacity',
                    );
                    $candidates = $this->placement->candidates($mapSpace->fresh(), 1);
                    if ($candidates === []) {
                        throw new DomainException(
                            'Worldを1chunk拡張しても初期島候補が生成されませんでした。登録処理を中止します。',
                        );
                    }
                }
                $center = $candidates[0];
                DB::table('nation_creation_requests')->where('request_key', $requestKey)->update([
                    'reserved_x' => $center->x, 'reserved_y' => $center->y, 'updated_at' => now(),
                ]);

                $nation = Nation::query()->create([
                    'world_id' => $world->id, 'nation_number' => $nationNumber,
                    'registered_turn' => $world->current_turn, 'name' => $name,
                    'owner_name' => $ownerName, 'profile_comment' => $profileComment,
                    'money' => $rules['initial_money'],
                    'state' => 'active',
                    'idle_counter' => 100,
                ]);
                $this->resources->initialize($nation);
                $this->islands->generate($mapSpace, $nation, $center, $seed);
                NationMembership::query()->create([
                    'user_id' => $user->id, 'world_id' => $world->id,
                    'nation_id' => $nation->id, 'role' => 'owner',
                ]);

                DB::table('nation_creation_requests')->where('request_key', $requestKey)->update([
                    'nation_id' => $nation->id, 'status' => 'completed', 'updated_at' => now(),
                ]);
                DB::table('audit_events')->insert([
                    'actor_user_id' => $user->id,
                    'world_id' => $world->id,
                    'turn' => $world->current_turn,
                    'nation_id' => $nation->id,
                    'x' => $center->x,
                    'y' => $center->y,
                    'message' => null,
                    'visibility' => 'public',
                    'event_type' => 'nation.created',
                    'severity' => 'info',
                    'subject_type' => Nation::class, 'subject_id' => $nation->id,
                    'metadata' => json_encode([
                        'world_id' => $world->id,
                        'target_turn' => $world->current_turn,
                        'nation_number' => $nation->nation_number,
                        'nation_name' => $nation->name,
                        'x' => $center->x,
                        'y' => $center->y,
                    ], JSON_THROW_ON_ERROR),
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);

                return $nation->load(['capital', 'resourceBalances.definition']);
            }, 3);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }
}
