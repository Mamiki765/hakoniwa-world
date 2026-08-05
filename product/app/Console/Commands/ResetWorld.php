<?php

namespace App\Console\Commands;

use App\Application\OceanWorldGenerator;
use App\Domain\Turn\TurnAlreadyRunningException;
use App\Domain\Turn\WorldTurnLock;
use App\Domain\World\WorldBounds;
use App\Domain\World\WorldGenerationProfile;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationResourceSalePolicy;
use App\Models\World;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class ResetWorld extends Command
{
    protected $signature = 'hakoniwa:world:reset
        {--world= : Exact world key to reset}
        {--profile=default : World generation profile: default or debug-32x32}
        {--confirm= : Must equal RESET-<world-key> before any mutation}
        {--dry-run : Show affected row counts without changing data}
        {--preserve-users : Accepted for clarity; users are always preserved}
        {--preserve-auth-identities : Accepted for clarity; auth identities are always preserved}';

    protected $description = 'Reset one configured world in local or testing while preserving users and authentication identities.';

    public function handle(OceanWorldGenerator $generator, WorldTurnLock $turnLock): int
    {
        if (app()->environment('production')) {
            $this->error('World reset is disabled in production. Use forward migrations or an explicit conversion path.');

            return self::FAILURE;
        }

        $worldKey = (string) $this->option('world');
        $profile = WorldGenerationProfile::tryFrom((string) $this->option('profile'));
        $configuredWorldKey = (string) config('hakoniwa.world.key');
        if ($worldKey === '' || $worldKey !== $configuredWorldKey) {
            $this->error("Only the configured world key '{$configuredWorldKey}' can be reset.");

            return self::FAILURE;
        }
        if ($profile === null) {
            $this->error('World profile must be default or debug-32x32.');

            return self::FAILURE;
        }
        try {
            $profile->assertAvailable(app()->environment());
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World '{$worldKey}' does not exist.");

            return self::FAILURE;
        }

        try {
            $turnLock->acquire($world);
        } catch (TurnAlreadyRunningException) {
            $this->error("World '{$worldKey}' is currently processing a turn. Reset was not started.");

            return self::FAILURE;
        }

        try {
            return $this->handleLockedWorld($world, $worldKey, $profile, $generator);
        } finally {
            $turnLock->release($world);
        }
    }

    private function handleLockedWorld(
        World $world,
        string $worldKey,
        WorldGenerationProfile $profile,
        OceanWorldGenerator $generator,
    ): int {
        $bounds = $profile->bounds(config('hakoniwa.ruleset'));
        $counts = $this->affectedCounts($world);
        $this->table(['target', 'rows'], array_map(
            static fn (string $target, int $rows): array => [$target, $rows],
            array_keys($counts),
            array_values($counts),
        ));
        $this->line('users and auth_identities are always preserved.');

        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run complete. No data was changed.');

            return self::SUCCESS;
        }

        $expectedConfirmation = 'RESET-'.$worldKey;
        if ((string) $this->option('confirm') !== $expectedConfirmation) {
            $this->error("Confirmation must exactly equal {$expectedConfirmation}.");

            return self::FAILURE;
        }

        $userCount = DB::table('users')->count();
        $identityCount = DB::table('auth_identities')->count();

        try {
            DB::transaction(function () use ($world, $worldKey, $profile, $bounds, $generator, $userCount, $identityCount): void {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $this->deleteWorldAuditEvents($lockedWorld);
                $this->deleteQueueItems($lockedWorld);
                $lockedWorld->delete();

                $generatedWorld = $generator->initialize($profile);
                if ($generatedWorld->key !== $worldKey) {
                    throw new RuntimeException('The generator recreated an unexpected world.');
                }

                $this->assertGeneratedWorld($generatedWorld, $bounds);

                if (DB::table('users')->count() !== $userCount) {
                    throw new RuntimeException('User count changed during reset.');
                }
                if (DB::table('auth_identities')->count() !== $identityCount) {
                    throw new RuntimeException('Authentication identity count changed during reset.');
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->error('World reset failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(
            "World {$worldKey} was reset to a verified {$bounds->width()} x {$bounds->height()} "
            ."staggered x/y ocean ({$profile->value}).",
        );

        return self::SUCCESS;
    }

    /** @return array<string, int> */
    private function affectedCounts(World $world): array
    {
        $nationIds = DB::table('nations')->where('world_id', $world->id)->pluck('id');
        $mapSpaceIds = DB::table('map_spaces')->where('world_id', $world->id)->pluck('id');
        $queueIds = DB::table('nation_command_queues')->whereIn('nation_id', $nationIds)->pluck('id');

        return [
            'audit_events' => $this->worldAuditEvents($world)->count(),
            'turn_runs' => DB::table('turn_runs')->where('world_id', $world->id)->count(),
            'nation_monster_kill_stats' => DB::table('nation_monster_kill_stats')->where('world_id', $world->id)->count(),
            'monster_instances' => DB::table('monster_instances')->where('world_id', $world->id)->count(),
            'monster_occupancies' => DB::table('monster_occupancies')
                ->join('monster_instances', 'monster_instances.id', '=', 'monster_occupancies.monster_instance_id')
                ->where('monster_instances.world_id', $world->id)
                ->count(),
            'nation_memberships' => DB::table('nation_memberships')->where('world_id', $world->id)->count(),
            'nation_resources' => DB::table('nation_resources')->whereIn('nation_id', $nationIds)->count(),
            'nation_resource_sale_policies' => DB::table('nation_resource_sale_policies')->whereIn('nation_id', $nationIds)->count(),
            'nation_command_queues' => $queueIds->count(),
            'nation_command_queue_items' => DB::table('nation_command_queue_items')->whereIn('nation_command_queue_id', $queueIds)->count(),
            'nation_capitals' => DB::table('nation_capitals')->whereIn('nation_id', $nationIds)->count(),
            'nation_creation_requests' => DB::table('nation_creation_requests')->where('world_id', $world->id)->count(),
            'nations' => $nationIds->count(),
            'map_cells' => DB::table('map_cells')->whereIn('map_space_id', $mapSpaceIds)->count(),
            'map_chunks' => DB::table('map_chunks')->whereIn('map_space_id', $mapSpaceIds)->count(),
            'world_generation_runs' => DB::table('world_generation_runs')->whereIn('map_space_id', $mapSpaceIds)->count(),
            'map_spaces' => $mapSpaceIds->count(),
            'worlds' => 1,
        ];
    }

    private function deleteWorldAuditEvents(World $world): void
    {
        $this->worldAuditEvents($world)->delete();
    }

    private function worldAuditEvents(World $world): Builder
    {
        $nationIds = DB::table('nations')->where('world_id', $world->id)->pluck('id');
        $queueIds = DB::table('nation_command_queues')->whereIn('nation_id', $nationIds)->pluck('id');
        $queueItemIds = DB::table('nation_command_queue_items')
            ->whereIn('nation_command_queue_id', $queueIds)
            ->pluck('id');
        $salePolicyIds = DB::table('nation_resource_sale_policies')
            ->whereIn('nation_id', $nationIds)
            ->pluck('id');

        return DB::table('audit_events')->where(function (Builder $query) use (
            $world,
            $nationIds,
            $queueIds,
            $queueItemIds,
            $salePolicyIds,
        ): void {
            $query->whereRaw("metadata->>'world_id' = ?", [(string) $world->id])
                ->orWhere(function (Builder $subject) use ($nationIds): void {
                    $subject->where('subject_type', Nation::class)->whereIn('subject_id', $nationIds);
                })
                ->orWhere(function (Builder $subject) use ($queueIds): void {
                    $subject->where('subject_type', NationCommandQueue::class)->whereIn('subject_id', $queueIds);
                })
                ->orWhere(function (Builder $subject) use ($queueItemIds): void {
                    $subject->where('subject_type', NationCommandQueueItem::class)->whereIn('subject_id', $queueItemIds);
                })
                ->orWhere(function (Builder $subject) use ($salePolicyIds): void {
                    $subject->where('subject_type', NationResourceSalePolicy::class)->whereIn('subject_id', $salePolicyIds);
                });
        });
    }

    private function deleteQueueItems(World $world): void
    {
        $nationIds = DB::table('nations')->where('world_id', $world->id)->pluck('id');
        $queueIds = DB::table('nation_command_queues')->whereIn('nation_id', $nationIds)->pluck('id');

        DB::table('nation_command_queue_items')
            ->whereIn('nation_command_queue_id', $queueIds)
            ->delete();
    }

    private function assertGeneratedWorld(World $world, WorldBounds $bounds): void
    {
        $mapSpace = MapSpace::query()
            ->where('world_id', $world->id)
            ->where('key', config('hakoniwa.world.map_space_key'))
            ->firstOrFail();
        $rowCounts = DB::table('map_cells')
            ->where('map_space_id', $mapSpace->id)
            ->selectRaw('y, COUNT(*) AS cell_count')
            ->groupBy('y')
            ->orderBy('y')
            ->pluck('cell_count', 'y');
        $columnCounts = DB::table('map_cells')
            ->where('map_space_id', $mapSpace->id)
            ->selectRaw('x, COUNT(*) AS cell_count')
            ->groupBy('x')
            ->orderBy('x')
            ->pluck('cell_count', 'x');

        $valid = $mapSpace->coordinate_system === 'staggered_square_offset'
            && $mapSpace->min_x === $bounds->minX && $mapSpace->max_x === $bounds->maxX
            && $mapSpace->min_y === $bounds->minY && $mapSpace->max_y === $bounds->maxY
            && $mapSpace->cells()->count() === $bounds->cellCount()
            && $mapSpace->chunks()->count() === $bounds->chunkCount()
            && $rowCounts->count() === $bounds->rowCount()
            && $rowCounts->keys()->map(static fn (mixed $value): int => (int) $value)->all() === $bounds->yCoordinates()
            && $rowCounts->every(static fn (mixed $count): bool => (int) $count === $bounds->columnCount())
            && $columnCounts->count() === $bounds->columnCount()
            && $columnCounts->keys()->map(static fn (mixed $value): int => (int) $value)->all() === $bounds->xCoordinates()
            && $columnCounts->every(static fn (mixed $count): bool => (int) $count === $bounds->rowCount())
            && (int) $mapSpace->cells()->min('x') === $bounds->minX
            && (int) $mapSpace->cells()->max('x') === $bounds->maxX
            && (int) $mapSpace->cells()->min('y') === $bounds->minY
            && (int) $mapSpace->cells()->max('y') === $bounds->maxY;

        if (! $valid) {
            throw new RuntimeException('Generated world verification failed.');
        }
    }
}
