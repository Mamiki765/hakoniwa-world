<?php

namespace App\Application;

use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Hex\HexCoordinate;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CommandQueueService
{
    /** @return array{queue: NationCommandQueue, item: NationCommandQueueItem} */
    public function add(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        string $commandKey,
        int $targetQ,
        int $targetR,
        string $requestKey,
        int $expectedVersion,
        array $parameters = [],
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $commandKey, $targetQ, $targetR, $requestKey, $expectedVersion, $parameters): array {
            $membership = $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $definition = CommandDefinition::query()
                ->where('ruleset_version_id', $nation->world()->value('ruleset_version_id'))
                ->where('key', $commandKey)
                ->where('enabled', true)
                ->first();
            if ($definition === null) {
                throw new DomainException('利用できないcommandです。');
            }

            $queue = NationCommandQueue::query()->firstOrCreate(
                ['nation_id' => $nation->id],
                ['map_space_id' => $mapSpace->id, 'version' => 1],
            );
            $queue = NationCommandQueue::query()->whereKey($queue->id)->lockForUpdate()->firstOrFail();
            if ($queue->map_space_id !== $mapSpace->id) {
                throw new DomainException('queueとmap spaceが一致しません。');
            }

            $duplicate = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('request_key', $requestKey)
                ->first();
            if ($duplicate !== null) {
                return ['queue' => $queue, 'item' => $duplicate->load('definition')];
            }
            $this->assertVersion($queue, $expectedVersion);

            $cell = $this->targetCell($mapSpace, $targetQ, $targetR);
            $this->validateTarget($nation, $mapSpace, $definition, $cell);
            $active = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->count();
            $limit = (int) config('hakoniwa.ruleset.command_queue_limit', 20);
            if ($active >= $limit) {
                throw new DomainException("command queueの上限{$limit}件に達しています。");
            }

            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => $active + 1,
                'target_q' => $targetQ,
                'target_r' => $targetR,
                'parameters' => $parameters,
                'status' => 'queued',
                'queued_by_membership_id' => $membership->id,
                'request_key' => $requestKey,
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.queued', $item, ['command_key' => $commandKey, 'q' => $targetQ, 'r' => $targetR]);

            return ['queue' => $queue, 'item' => $item->load('definition')];
        }, 3);
    }

    /** @param array<int, int> $orderedIds */
    public function reorder(User $user, Nation $nation, array $orderedIds, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $orderedIds, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $current = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
            $expected = $current;
            $received = $orderedIds;
            sort($expected);
            sort($received);
            if ($expected !== $received) {
                throw new DomainException('reorder対象が現在のqueueと一致しません。');
            }

            NationCommandQueueItem::query()->whereIn('id', $orderedIds)->increment('queue_position', 1000);
            foreach ($orderedIds as $index => $id) {
                NationCommandQueueItem::query()->whereKey($id)->update(['queue_position' => $index + 1]);
            }
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.reordered', $queue, ['ordered_ids' => $orderedIds]);

            return $queue;
        }, 3);
    }

    public function cancel(User $user, Nation $nation, NationCommandQueueItem $item, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $item, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new DomainException('取消できないcommandです。');
            }
            $item->update(['status' => 'cancelled', 'queue_position' => null, 'cancelled_at' => now()]);
            $this->compact($queue);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.cancelled', $item, []);

            return $queue;
        }, 3);
    }

    public function queueFor(User $user, Nation $nation, MapSpace $mapSpace): NationCommandQueue
    {
        $this->membership($user, $nation);
        $this->assertMapSpace($nation, $mapSpace);

        return NationCommandQueue::query()->firstOrCreate(
            ['nation_id' => $nation->id],
            ['map_space_id' => $mapSpace->id, 'version' => 1],
        )->load(['items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'), 'items.definition']);
    }

    public function validateTarget(Nation $nation, MapSpace $mapSpace, CommandDefinition $definition, MapCell $cell): void
    {
        $terrainKey = $cell->terrain->key;
        $facilityKey = $cell->facility?->key;
        if (! in_array($terrainKey, $definition->target_terrain_keys, true)) {
            throw new DomainException('対象地形ではこのcommandをqueueへ追加できません。');
        }
        if ($definition->requires_empty_facility && $facilityKey !== null) {
            throw new DomainException('施設のあるcellにはこのcommandをqueueへ追加できません。');
        }
        if ($definition->target_facility_keys !== [] && ! in_array($facilityKey, $definition->target_facility_keys, true)) {
            throw new DomainException('対象施設ではこのcommandをqueueへ追加できません。');
        }

        if (in_array($definition->key, ['reclaim'], true)) {
            if (! $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 1, false)) {
                throw new DomainException('埋め立て対象の隣に自国領がありません。');
            }

            return;
        }
        if ($definition->key === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true)) {
            if (! $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 3)) {
                throw new DomainException('掘削対象の3hex以内に自国領がありません。');
            }

            return;
        }
        if ($cell->owner_nation_id !== $nation->id) {
            throw new DomainException('自国領のcellだけを対象にできます。');
        }
    }

    private function membership(User $user, Nation $nation): NationMembership
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $nation->id)
            ->where('world_id', $nation->world_id)
            ->first();
        if ($membership === null) {
            throw new AuthorizationException('自国のcommand queueだけを操作できます。');
        }

        return $membership;
    }

    private function assertMapSpace(Nation $nation, MapSpace $mapSpace): void
    {
        if ($mapSpace->world_id !== $nation->world_id) {
            throw new DomainException('Nationとmap spaceのworldが一致しません。');
        }
    }

    private function targetCell(MapSpace $mapSpace, int $q, int $r): MapCell
    {
        if ($q < $mapSpace->min_q || $q > $mapSpace->max_q || $r < $mapSpace->min_r || $r > $mapSpace->max_r) {
            throw new DomainException('target q/rがmap bounds外です。');
        }

        $cell = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('q', $q)
            ->where('r', $r)
            ->with(['terrain', 'facility'])
            ->first();
        if ($cell === null) {
            throw new DomainException('target cellが存在しません。');
        }

        return $cell;
    }

    private function hasOwnedCellWithin(Nation $nation, MapSpace $mapSpace, MapCell $cell, int $radius, bool $includeCenter = true): bool
    {
        $coordinates = (new HexCoordinate($cell->q, $cell->r))->radius($radius);
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (HexCoordinate $coordinate): bool => $coordinate->q !== $cell->q || $coordinate->r !== $cell->r,
            ));
        }

        return MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('q', $coordinate->q)->where('r', $coordinate->r));
                }
            })
            ->exists();
    }

    private function assertVersion(NationCommandQueue $queue, int $expectedVersion): void
    {
        if ($queue->version !== $expectedVersion) {
            throw new OptimisticLockException('command queueが他の操作で更新されました。再読込してください。');
        }
    }

    private function compact(NationCommandQueue $queue): void
    {
        $items = NationCommandQueueItem::query()
            ->where('nation_command_queue_id', $queue->id)
            ->where('status', 'queued')
            ->orderBy('queue_position')
            ->get();
        if ($items->isEmpty()) {
            return;
        }

        NationCommandQueueItem::query()->whereIn('id', $items->modelKeys())->increment('queue_position', 1000);
        foreach ($items as $index => $item) {
            $item->update(['queue_position' => $index + 1]);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function audit(User $user, string $eventType, Model $subject, array $metadata): void
    {
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'event_type' => $eventType,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
