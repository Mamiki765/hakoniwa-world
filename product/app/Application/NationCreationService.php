<?php

namespace App\Application;

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
    public const REGISTRATION_LOCK_NAMESPACE = 121315;

    public function __construct(
        private readonly CapitalPlacementService $placement,
        private readonly InitialIslandGenerator $islands,
        private readonly NationResourceService $resources,
    ) {}

    public function create(User $user, World $world, string $name): Nation
    {
        return DB::transaction(function () use ($user, $world, $name): Nation {
            $world = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
            $this->lockRegistration($world);
            $rules = $world->rulesetVersion()->firstOrFail()->settings;

            if (NationMembership::query()->where('user_id', $user->id)->where('world_id', $world->id)->exists()) {
                throw new DomainException('このWorldにはすでにNationがあります。');
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
            $requestKey = (string) Str::uuid();
            $seed = hash('sha256', implode(':', [
                $world->id, $user->id, mb_strtolower($name), config('hakoniwa.initial_island.generator_version'),
            ]));

            DB::table('nation_creation_requests')->insert([
                'request_key' => $requestKey, 'user_id' => $user->id, 'world_id' => $world->id,
                'status' => 'reserving', 'generation_seed' => $seed,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $center = $this->placement->choose($mapSpace);
            DB::table('nation_creation_requests')->where('request_key', $requestKey)->update([
                'reserved_x' => $center->x, 'reserved_y' => $center->y, 'updated_at' => now(),
            ]);

            $nation = Nation::query()->create([
                'world_id' => $world->id, 'nation_number' => $nationNumber, 'name' => $name,
                'money' => $rules['initial_money'],
                'state' => 'active',
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
                'actor_user_id' => $user->id, 'event_type' => 'nation.created',
                'subject_type' => Nation::class, 'subject_id' => $nation->id,
                'metadata' => json_encode([
                    'world_id' => $world->id,
                    'nation_number' => $nation->nation_number,
                    'x' => $center->x,
                    'y' => $center->y,
                ], JSON_THROW_ON_ERROR),
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);

            return $nation->load(['capital', 'resourceBalances.definition']);
        }, 3);
    }

    private function lockRegistration(World $world): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [self::REGISTRATION_LOCK_NAMESPACE, $world->id]);
        }
    }
}
