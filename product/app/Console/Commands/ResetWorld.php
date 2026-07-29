<?php

namespace App\Console\Commands;

use App\Application\OceanWorldGenerator;
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
        {--confirm= : Must equal RESET-<world-key> before any mutation}
        {--dry-run : Show affected row counts without changing data}
        {--preserve-users : Accepted for clarity; users are always preserved}
        {--preserve-auth-identities : Accepted for clarity; auth identities are always preserved}';

    protected $description = 'Safely reset one configured world while preserving users and authentication identities.';

    public function handle(OceanWorldGenerator $generator): int
    {
        $worldKey = (string) $this->option('world');
        $configuredWorldKey = (string) config('hakoniwa.world.key');
        if ($worldKey === '' || $worldKey !== $configuredWorldKey) {
            $this->error("Only the configured world key '{$configuredWorldKey}' can be reset.");

            return self::FAILURE;
        }

        $world = World::query()->where('key', $worldKey)->first();
        if ($world === null) {
            $this->error("World '{$worldKey}' does not exist.");

            return self::FAILURE;
        }

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
            DB::transaction(function () use ($world, $worldKey, $generator, $userCount, $identityCount): void {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $this->deleteWorldAuditEvents($lockedWorld);
                $this->deleteQueueItems($lockedWorld);
                $lockedWorld->delete();

                $generatedWorld = $generator->initialize();
                if ($generatedWorld->key !== $worldKey) {
                    throw new RuntimeException('The generator recreated an unexpected world.');
                }

                $this->assertGeneratedWorld($generatedWorld);

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

        $this->info("World {$worldKey} was reset to a verified 60 x 60 staggered x/y ocean.");

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

    private function assertGeneratedWorld(World $world): void
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

        $valid = $mapSpace->coordinate_system === 'staggered_square_offset'
            && $mapSpace->min_x === 0 && $mapSpace->max_x === 59
            && $mapSpace->min_y === 0 && $mapSpace->max_y === 59
            && $mapSpace->cells()->count() === 3600
            && $rowCounts->count() === 60
            && $rowCounts->keys()->map(static fn (mixed $value): int => (int) $value)->all() === range(0, 59)
            && $rowCounts->every(static fn (mixed $count): bool => (int) $count === 60)
            && (int) DB::table('map_cells')->where('map_space_id', $mapSpace->id)->min('x') === 0
            && (int) DB::table('map_cells')->where('map_space_id', $mapSpace->id)->max('x') === 59;

        if (! $valid) {
            throw new RuntimeException('Generated world verification failed.');
        }
    }
}
