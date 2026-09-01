<?php

namespace App\Application\Underground;

use App\Domain\Command\PlayerFacingCommandException;
use App\Domain\Underground\Facility\UndergroundCommandCatalog;
use App\Domain\Underground\Facility\UndergroundCommandDefinition;
use App\Models\Nation;
use App\Models\NationCommandQueue;
use App\Models\NationCommandQueueItem;
use App\Models\NationMembership;
use App\Models\NationUndergroundFacility;
use App\Models\RulesetVersion;
use App\Models\Secretary;
use App\Models\UndergroundProfile;
use App\Models\UndergroundTrialProgress;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final readonly class UndergroundFacilityService
{
    public function __construct(private UndergroundCommandCatalog $catalog) {}

    public function assertEntitled(User $user, Nation $nation, int $layer, int $slotIndex): void
    {
        $membership = NationMembership::query()
            ->where('user_id', $user->id)
            ->where('nation_id', $nation->id)
            ->where('role', 'owner')
            ->first();
        if (! $membership instanceof NationMembership) {
            throw new PlayerFacingCommandException('現在所有している島の地下施設だけを開発できます。');
        }
        $this->assertMembershipEntitled($membership, $nation, $layer, $slotIndex);
    }

    public function assertMembershipEntitled(
        NationMembership $membership,
        Nation $nation,
        int $layer,
        int $slotIndex,
    ): void {
        if ($membership->nation_id !== $nation->id || $membership->role !== 'owner') {
            throw new PlayerFacingCommandException('現在所有している島の地下施設だけを開発できます。');
        }
        if ($slotIndex < 0 || $slotIndex > 3 || $layer < 1) {
            throw new PlayerFacingCommandException('地下施設枠の指定が不正です。');
        }
        $secretary = Secretary::query()
            ->where('user_id', $membership->user_id)
            ->with(['undergroundProfile.trialProgresses'])
            ->first();
        $profile = $secretary?->undergroundProfile;
        if (! $profile instanceof UndergroundProfile || $layer > $profile->unlocked_area_layers) {
            throw new PlayerFacingCommandException('未解放の地下階層は開発できません。');
        }
        $trialOne = $profile->trialProgresses
            ->first(fn (UndergroundTrialProgress $progress): bool => $progress->trial_key === 'trial_01');
        if (! $trialOne instanceof UndergroundTrialProgress || $trialOne->first_cleared_at === null) {
            throw new PlayerFacingCommandException('地下施設の開発にはTrial 1初回clearが必要です。');
        }
    }

    public function currentFacilityKey(int $nationId, int $layer, int $slotIndex, bool $lock = false): ?string
    {
        $query = NationUndergroundFacility::query()
            ->where('nation_id', $nationId)
            ->where('layer', $layer)
            ->where('slot_index', $slotIndex);
        if ($lock) {
            $query->lockForUpdate();
        }

        $facilityKey = $query->value('facility_key');
        if ($facilityKey !== null && ! is_string($facilityKey)) {
            throw new DomainException('Underground facility persistence contains an invalid key.');
        }

        return $facilityKey;
    }

    /** @param Collection<int, NationCommandQueueItem> $items */
    public function assertProjectedSequences(NationCommandQueue $queue, Collection $items): void
    {
        /** @var array<string, string|null> $facilityKeys */
        $facilityKeys = [];
        foreach ($items->sortBy('queue_position') as $item) {
            if ($item->target_context !== 'underground_slot') {
                continue;
            }
            if (! is_int($item->target_layer) || ! is_int($item->target_slot_index)
                || ! is_string($item->underground_command_key)) {
                throw new PlayerFacingCommandException('地下施設commandの予約情報が不正です。');
            }
            $slotKey = $item->target_layer.':'.$item->target_slot_index;
            if (! array_key_exists($slotKey, $facilityKeys)) {
                $facilityKeys[$slotKey] = $this->currentFacilityKey(
                    $queue->nation_id,
                    $item->target_layer,
                    $item->target_slot_index,
                );
            }
            $definition = $this->definitionForItem($item);
            $this->assertProjectedCommand($definition, $facilityKeys[$slotKey]);
            $facilityKeys[$slotKey] = $definition->action === 'remove'
                ? null
                : $definition->facility_key;
        }
    }

    public function projectedFacilityKey(
        NationCommandQueue $queue,
        int $layer,
        int $slotIndex,
        int $beforePosition,
    ): ?string {
        $facilityKey = $this->currentFacilityKey($queue->nation_id, $layer, $slotIndex);
        $items = $queue->relationLoaded('items')
            ? $queue->items
            : $queue->items()->where('status', 'queued')->with('definition')->get();
        foreach ($items->sortBy('queue_position') as $item) {
            if ($item->status !== 'queued'
                || (int) $item->queue_position >= $beforePosition
                || $item->target_context !== 'underground_slot'
                || $item->target_layer !== $layer
                || $item->target_slot_index !== $slotIndex) {
                continue;
            }
            if (! is_string($item->underground_command_key)) {
                continue;
            }
            $definition = $this->definitionForItem($item);
            $action = $definition->action;
            if ($action === 'build' && $facilityKey === null) {
                $facilityKey = $definition->facility_key;
            } elseif ($action === 'remove' && $facilityKey !== null) {
                $facilityKey = null;
            }
        }

        return $facilityKey;
    }

    public function assertProjectedCommand(UndergroundCommandDefinition $definition, ?string $facilityKey): void
    {
        $action = $definition->action;
        if ($action === 'build' && $facilityKey !== null) {
            throw new PlayerFacingCommandException('建築済みの地下施設枠へ直接上書きはできません。先に撤去してください。');
        }
        if ($action === 'remove' && $facilityKey === null) {
            throw new PlayerFacingCommandException('空き地下施設枠は撤去できません。');
        }
    }

    public function execute(
        Nation $nation,
        UndergroundCommandDefinition $definition,
        int $rulesetVersionId,
        int $layer,
        int $slotIndex,
    ): void {
        $current = $this->currentFacilityKey($nation->id, $layer, $slotIndex, true);
        $this->assertProjectedCommand($definition, $current);
        if ($definition->action === 'remove') {
            $deleted = NationUndergroundFacility::query()
                ->where('nation_id', $nation->id)
                ->where('layer', $layer)
                ->where('slot_index', $slotIndex)
                ->delete();
            if ($deleted !== 1) {
                throw new DomainException('The locked Underground facility changed during execution.');
            }

            return;
        }
        NationUndergroundFacility::query()->create([
            'nation_id' => $nation->id,
            'ruleset_version_id' => $rulesetVersionId,
            'layer' => $layer,
            'slot_index' => $slotIndex,
            'facility_key' => $definition->facility_key,
        ]);
    }

    private function definitionForItem(NationCommandQueueItem $item): UndergroundCommandDefinition
    {
        if (! is_string($item->underground_command_key)) {
            throw new DomainException('Underground queue item command identity is invalid.');
        }
        $ruleset = $item->relationLoaded('requestRulesetVersion')
            ? $item->requestRulesetVersion
            : $item->requestRulesetVersion()->first();
        if (! $ruleset instanceof RulesetVersion) {
            throw new DomainException('Underground queue item Ruleset provenance is missing.');
        }

        return $this->catalog->get($ruleset->settings, $item->underground_command_key);
    }
}
