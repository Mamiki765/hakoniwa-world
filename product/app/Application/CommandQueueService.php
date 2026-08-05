<?php

namespace App\Application;

use App\Domain\Command\CommandParametersValidator;
use App\Domain\Command\CommandQueueLimit;
use App\Domain\Command\DevelopmentPlanQuantity;
use App\Domain\Concurrency\OptimisticLockException;
use App\Domain\Map\GridCoordinate;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Models\CommandDefinition;
use App\Models\MapCell;
use App\Models\MapSpace;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\RulesetVersion;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CommandQueueService
{
    public function __construct(
        private readonly CommandParametersValidator $parameters,
        private readonly CurrentRulesetGuard $rulesetGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{queue: NationCommandQueue, item: NationCommandQueueItem}
     */
    public function add(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        string $commandKey,
        ?int $targetX,
        ?int $targetY,
        string $requestKey,
        int $expectedVersion,
        int $quantity = DevelopmentPlanQuantity::DEFAULT,
        array $parameters = [],
        ?int $position = null,
    ): array {
        return DB::transaction(function () use ($user, $nation, $mapSpace, $commandKey, $targetX, $targetY, $requestKey, $expectedVersion, $quantity, $parameters, $position): array {
            $membership = $this->membership($user, $nation);
            $this->assertMapSpace($nation, $mapSpace);
            $world = $this->lockWorldForQueue($nation);
            $definition = CommandDefinition::query()
                ->where('ruleset_version_id', $world->ruleset_version_id)
                ->where('key', $commandKey)
                ->where('enabled', true)
                ->first();
            if ($definition === null) {
                throw new DomainException('利用できないcommandです。');
            }

            [$targetX, $targetY] = $this->resolveTargetCoordinates(
                $nation,
                $mapSpace,
                $definition,
                $targetX,
                $targetY,
            );

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
                ->lockForUpdate()
                ->first();
            if ($duplicate !== null) {
                return ['queue' => $queue, 'item' => $duplicate->load('definition')];
            }
            $this->assertVersion($queue, $expectedVersion);

            // A queue item is a future plan. Registration proves only that the
            // coordinate and parameters are structurally valid; the locked
            // target state and assets are revalidated immediately before execution.
            $this->targetCell($mapSpace, $targetX, $targetY);
            $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
            $schemas = $definition->metadata['parameters'] ?? [];
            if (! is_array($schemas)) {
                throw new DomainException('command parameter schemaが不正です。');
            }
            $parameters = $this->parameters->validate($schemas, $parameters);
            $activeItems = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->lockForUpdate()
                ->get();
            $limit = $this->queueLimit($world);
            if ($activeItems->count() >= $limit) {
                throw new DomainException("command queueの上限{$limit}件に達しています。");
            }
            $position ??= $this->firstAutomaticPosition($activeItems->pluck('queue_position')->all(), $limit);
            if ($position < 1 || $position > $limit) {
                throw new DomainException("挿入位置は1から{$limit}の範囲で指定してください。");
            }
            $byPosition = $activeItems->keyBy(
                static fn (NationCommandQueueItem $item): int => (int) $item->queue_position,
            );
            /** @var Collection<int, NationCommandQueueItem> $shifted */
            $shifted = new Collection;
            for ($shiftPosition = $position; $byPosition->has($shiftPosition); $shiftPosition++) {
                if ($shiftPosition >= $limit) {
                    throw new DomainException('選択した位置へ挿入すると開発計画の末尾を超えます。');
                }

                $shifted->push($byPosition->get($shiftPosition));
            }
            if ($shifted->isNotEmpty()) {
                NationCommandQueueItem::query()->whereIn('id', $shifted->modelKeys())->increment('queue_position', 1000);
                foreach ($shifted->sortByDesc('queue_position') as $shiftedItem) {
                    NationCommandQueueItem::query()->whereKey($shiftedItem->id)
                        ->update(['queue_position' => (int) $shiftedItem->queue_position + 1]);
                }
            }

            if ($definition->ruleset_version_id !== $world->ruleset_version_id) {
                throw new DomainException('Command definition no longer matches the locked World ruleset.');
            }

            $item = NationCommandQueueItem::query()->create([
                'nation_command_queue_id' => $queue->id,
                'command_definition_id' => $definition->id,
                'queue_position' => $position,
                'target_x' => $targetX,
                'target_y' => $targetY,
                'quantity' => $quantity,
                'parameters' => $parameters === [] ? (object) [] : $parameters,
                'status' => 'queued',
                'queued_by_membership_id' => $membership->id,
                'request_key' => $requestKey,
                'queued_at' => now(),
                'failure_metadata' => [],
            ]);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.queued', $item, [
                'command_key' => $commandKey,
                'x' => $targetX,
                'y' => $targetY,
                'quantity' => $quantity,
            ]);

            return ['queue' => $queue, 'item' => $item->load('definition')];
        }, 3);
    }

    /**
     * @param  array<int, array{id: int, position: int}>  $placements
     */
    public function reposition(User $user, Nation $nation, array $placements, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $placements, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $world = $this->lockWorldForQueue($nation);
            $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $currentIds = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $receivedIds = collect($placements)->pluck('id')->map(static fn (mixed $id): int => (int) $id)
                ->sort()->values()->all();
            $positions = collect($placements)->pluck('position')->all();
            $limit = $this->queueLimit($world);
            if ($currentIds !== $receivedIds || count($positions) !== count(array_unique($positions))) {
                throw new DomainException('並べ替え対象が現在の開発計画と一致しません。');
            }
            if (collect($positions)->contains(static fn (mixed $position): bool => $position < 1 || $position > $limit)) {
                throw new DomainException("開発計画の位置は1から{$limit}の範囲で指定してください。");
            }

            if ($currentIds !== []) {
                NationCommandQueueItem::query()->whereIn('id', $currentIds)->increment('queue_position', 1000);
                foreach ($placements as $placement) {
                    NationCommandQueueItem::query()->whereKey($placement['id'])
                        ->update(['queue_position' => $placement['position']]);
                }
            }
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.reordered', $queue, ['placements' => $placements]);

            return $queue;
        }, 3);
    }

    public function updateQuantity(
        User $user,
        Nation $nation,
        NationCommandQueueItem $item,
        int $quantity,
        int $expectedVersion,
    ): NationCommandQueue {
        return DB::transaction(function () use ($user, $nation, $item, $quantity, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $this->lockWorldForQueue($nation);
            $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $item = NationCommandQueueItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($item->nation_command_queue_id !== $queue->id || $item->status !== 'queued') {
                throw new DomainException('編集できないcommandです。');
            }
            $quantity = DevelopmentPlanQuantity::normalize($quantity, true);
            $oldQuantity = $item->quantity;
            $item->update(['quantity' => $quantity]);
            $queue->increment('version');
            $queue->refresh();
            $this->audit($user, 'command.quantity_updated', $item, [
                'old_quantity' => $oldQuantity,
                'new_quantity' => $quantity,
            ]);

            return $queue;
        }, 3);
    }

    /** @param array<int, int> $orderedIds */
    public function reorder(User $user, Nation $nation, array $orderedIds, int $expectedVersion): NationCommandQueue
    {
        return DB::transaction(function () use ($user, $nation, $orderedIds, $expectedVersion): NationCommandQueue {
            $this->membership($user, $nation);
            $this->lockWorldForQueue($nation);
            $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($queue, $expectedVersion);
            $current = NationCommandQueueItem::query()
                ->where('nation_command_queue_id', $queue->id)
                ->where('status', 'queued')
                ->orderBy('queue_position')
                ->lockForUpdate()
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
            $this->lockWorldForQueue($nation);
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

    public function queueFor(
        User $user,
        Nation $nation,
        MapSpace $mapSpace,
        bool $mutationPreflight = false,
    ): NationCommandQueue {
        $this->membership($user, $nation);
        $this->assertMapSpace($nation, $mapSpace);
        $world = World::query()->whereKey($nation->world_id)->with('rulesetVersion')->firstOrFail();
        if ($mutationPreflight) {
            $this->rulesetGuard->assertMutable($world, $world->rulesetVersion);
        }
        $this->assertUniversalQuantityRuleset($world);

        $queue = NationCommandQueue::query()->where('nation_id', $nation->id)->first();
        if ($queue === null) {
            $queue = new NationCommandQueue([
                'nation_id' => $nation->id,
                'map_space_id' => $mapSpace->id,
                'version' => 1,
            ]);
            $queue->setRelation('items', new Collection);

            return $queue;
        }

        return $queue->load([
            'items' => fn ($query) => $query->where('status', 'queued')->orderBy('queue_position'),
            'items.definition',
        ]);
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
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                throw new DomainException('他国所有の水域は埋め立てできません。');
            }
            if (! $this->hasOwnedCellWithin($nation, $mapSpace, $cell, 1, false)) {
                throw new DomainException('埋め立て対象の隣に自国領がありません。');
            }

            return;
        }
        if ($definition->key === 'excavate' && in_array($terrainKey, ['sea', 'shallow'], true)) {
            if ($cell->owner_nation_id !== null && $cell->owner_nation_id !== $nation->id) {
                throw new DomainException('他国所有の水域は掘削できません。');
            }
            if ($terrainKey === 'sea' && $cell->facility_definition_id !== null) {
                throw new DomainException('施設のある海では油田探索できません。');
            }
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

    private function lockWorldForQueue(Nation $nation): World
    {
        $world = World::query()->whereKey($nation->world_id)->lockForUpdate()->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $this->rulesetGuard->assertMutable($world, $ruleset);
        $world->setRelation('rulesetVersion', $ruleset);
        $this->assertUniversalQuantityRuleset($world);

        return $world;
    }

    private function queueLimit(World $world): int
    {
        $settings = $this->rulesetSettings($world);

        return CommandQueueLimit::fromRulesetSettings($settings);
    }

    private function assertUniversalQuantityRuleset(World $world): void
    {
        $settings = $this->rulesetSettings($world);
        if (! DevelopmentPlanQuantity::matchesContract($settings['development_plan_quantity'] ?? null)) {
            throw new DomainException('Worldのrulesetはuniversal quantity契約へ移行されていません。');
        }
    }

    /** @return array<string, mixed> */
    private function rulesetSettings(World $world): array
    {
        $ruleset = $world->relationLoaded('rulesetVersion')
            ? $world->getRelation('rulesetVersion')
            : $world->rulesetVersion()->firstOrFail();
        if (! $ruleset instanceof RulesetVersion) {
            throw new DomainException('World ruleset relation is invalid.');
        }

        return $ruleset->settings;
    }

    private function targetCell(MapSpace $mapSpace, int $x, int $y): MapCell
    {
        if ($x < $mapSpace->min_x || $x > $mapSpace->max_x || $y < $mapSpace->min_y || $y > $mapSpace->max_y) {
            throw new DomainException('target x/yがmap bounds外です。');
        }

        $cell = MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('x', $x)
            ->where('y', $y)
            ->with(['terrain', 'facility'])
            ->first();
        if ($cell === null) {
            throw new DomainException('target cellが存在しません。');
        }

        return $cell;
    }

    /** @return array{0: int, 1: int} */
    private function resolveTargetCoordinates(
        Nation $nation,
        MapSpace $mapSpace,
        CommandDefinition $definition,
        ?int $targetX,
        ?int $targetY,
    ): array {
        if ($definition->target_type === 'nation') {
            $capital = $nation->capital()->firstOrFail();
            $cell = $capital->cell()->firstOrFail();
            if ($cell->map_space_id !== $mapSpace->id) {
                throw new DomainException('Nationの首都とmap spaceが一致しません。');
            }

            return [(int) $capital->x, (int) $capital->y];
        }
        if ($targetX === null || $targetY === null) {
            throw new DomainException('cell対象commandにはtarget x/yが必要です。');
        }

        return [$targetX, $targetY];
    }

    private function hasOwnedCellWithin(Nation $nation, MapSpace $mapSpace, MapCell $cell, int $radius, bool $includeCenter = true): bool
    {
        $coordinates = (new GridCoordinate($cell->x, $cell->y))->radius($radius);
        if (! $includeCenter) {
            $coordinates = array_values(array_filter(
                $coordinates,
                static fn (GridCoordinate $coordinate): bool => $coordinate->x !== $cell->x || $coordinate->y !== $cell->y,
            ));
        }

        return MapCell::query()
            ->where('map_space_id', $mapSpace->id)
            ->where('owner_nation_id', $nation->id)
            ->where(function ($query) use ($coordinates): void {
                foreach ($coordinates as $coordinate) {
                    $query->orWhere(fn ($pair) => $pair->where('x', $coordinate->x)->where('y', $coordinate->y));
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

    /** @param array<int, int|null> $occupied */
    private function firstAutomaticPosition(array $occupied, int $limit): int
    {
        $positions = array_flip(array_map('intval', $occupied));
        for ($position = 1; $position <= $limit; $position++) {
            if (! isset($positions[$position])) {
                return $position;
            }
        }

        return $limit + 1;
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
