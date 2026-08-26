<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryMonsterDropContract;
use App\Domain\Turn\TurnContext;
use App\Domain\Turn\TurnRandomStreamFactory;
use App\Models\MonsterInstance;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use DomainException;

final class SecretaryMonsterDropService
{
    public function __construct(
        private readonly SecretaryMonsterDropContract $contract,
        private readonly SecretaryItemCatalog $items,
        private readonly SecretaryItemGrantService $grants,
        private readonly TurnEventRecorder $events,
    ) {}

    /** @return array{status: string, recipient_nation_id?: int, item_instance_id?: int} */
    public function grantForKill(
        TurnContext $context,
        MonsterInstance $monster,
        Nation $killer,
        ?Nation $host,
    ): array {
        $settings = $context->ruleset->settings;
        if (! $this->contract->exists($settings)) {
            return ['status' => 'not_authored'];
        }
        $table = $this->contract->table($settings, $monster->definition->key);
        if ($table === null) {
            return ['status' => 'ineligible'];
        }
        $version = (int) $settings['monster_system']['item_drop']['random_stream_version'];
        $recipientDraw = $context->random->stream(TurnRandomStreamFactory::monsterItemDrop(
            (int) $monster->id, 'recipient', $version,
        ))->integer(1, 100);
        $recipient = $host !== null && (int) $host->id !== (int) $killer->id && $recipientDraw > 75
            ? $host
            : $killer;

        $membership = NationMembership::query()
            ->where('world_id', $context->world->id)
            ->where('nation_id', $recipient->id)
            ->where('role', 'owner')
            ->orderBy('id')
            ->lockForUpdate()
            ->sole();
        $secretary = Secretary::query()->where('user_id', $membership->user_id)
            ->lockForUpdate()->sole();
        $grantKey = "monster-drop:v1:{$monster->id}:{$recipient->id}";
        $existing = $secretary->itemInstances()->where('grant_key', $grantKey)->first();
        if ($existing instanceof SecretaryItemInstance) {
            return [
                'status' => 'already_granted',
                'recipient_nation_id' => (int) $recipient->id,
                'item_instance_id' => (int) $existing->id,
            ];
        }
        $used = $secretary->itemInstances()->count();
        if ($used >= SecretaryItemGrantService::INVENTORY_CAPACITY) {
            $message = '倉庫がいっぱいのため、怪獣の戦利品を受け取れませんでした。';
            $this->events->record($context, 'monster.item_drop_inventory_full', $recipient, [
                'nation_id' => (int) $recipient->id,
                'inventory_capacity' => SecretaryItemGrantService::INVENTORY_CAPACITY,
                'inventory_used' => $used,
            ], 'private', 'warning', $message);

            return ['status' => 'inventory_full', 'recipient_nation_id' => (int) $recipient->id];
        }

        $rarity = $this->weightedRarity(
            $table['rarity_weights'],
            $context->random->stream(TurnRandomStreamFactory::monsterItemDrop(
                (int) $monster->id, 'rarity', $version,
            ))->integer(1, 100),
        );
        $pool = $this->contract->pool($settings, $rarity);
        $itemKey = $pool[$context->random->stream(TurnRandomStreamFactory::monsterItemDrop(
            (int) $monster->id, 'item', $version,
        ))->integer(0, count($pool) - 1)];
        $definition = $this->items->definition($itemKey);
        $effectiveMaximum = max(1, intdiv(
            (int) $definition['max_level'] * (int) $table['level_cap_percent'],
            100,
        ));
        $level = $context->random->stream(TurnRandomStreamFactory::monsterItemDrop(
            (int) $monster->id, 'level', $version,
        ))->integer(1, $effectiveMaximum);
        $item = $this->grants->grant($secretary, $itemKey, $level, null, $grantKey);
        if (! $item instanceof SecretaryItemInstance) {
            throw new DomainException('Monster drop inventory changed after its locked pre-draw capacity check.');
        }
        $message = '怪獣の戦利品として「'.$definition['name'].' Lv'.$level.'」を入手しました。';
        $this->events->record($context, 'monster.item_drop_received', $recipient, [
            'nation_id' => (int) $recipient->id,
            'item_name' => $definition['name'],
            'item_level' => $level,
        ], 'private', 'info', $message);

        return [
            'status' => 'granted',
            'recipient_nation_id' => (int) $recipient->id,
            'item_instance_id' => (int) $item->id,
        ];
    }

    /** @param array<string, int> $weights */
    private function weightedRarity(array $weights, int $draw): string
    {
        $cumulative = 0;
        foreach ($weights as $rarity => $weight) {
            $cumulative += $weight;
            if ($draw <= $cumulative) {
                return $rarity;
            }
        }

        throw new DomainException('Monster drop rarity draw exceeded the validated weight table.');
    }
}
