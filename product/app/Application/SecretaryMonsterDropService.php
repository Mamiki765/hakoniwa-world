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

        $grantKey = "monster-drop:v1:{$monster->id}:{$recipient->id}";
        $result = $this->grants->grantGenerated(
            $this->recipientSecretaryId($context, $recipient),
            $grantKey,
            fn (): array => $this->drawItem($context, $monster, $table, $version),
        );
        if ($result['status'] === 'inventory_full') {
            $message = '倉庫がいっぱいのため、怪獣の戦利品を受け取れませんでした。';
            $this->events->record($context, 'monster.item_drop_inventory_full', $recipient, [
                'nation_id' => (int) $recipient->id,
                'inventory_capacity' => SecretaryItemGrantService::INVENTORY_CAPACITY,
                'inventory_used' => $result['inventory_used'],
            ], 'private', 'warning', $message);

            return ['status' => 'inventory_full', 'recipient_nation_id' => (int) $recipient->id];
        }
        $item = $result['item'] ?? null;
        if (! $item instanceof SecretaryItemInstance) {
            throw new DomainException('Monster drop grant did not return its Item.');
        }
        if ($result['status'] === 'already_granted') {
            return [
                'status' => 'already_granted',
                'recipient_nation_id' => (int) $recipient->id,
                'item_instance_id' => (int) $item->id,
            ];
        }
        $definition = $this->items->definition($item->item_key);
        $level = (int) $item->level;
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

    private function recipientSecretaryId(TurnContext $context, Nation $recipient): int
    {
        if ($context->state->hasSecretarySnapshot((int) $recipient->id)) {
            return $context->state->secretarySnapshot((int) $recipient->id)['secretary_id'];
        }

        // Direct callers without the Turn batch retain the original ownership lookup.
        $membership = NationMembership::query()
            ->where('world_id', $context->world->id)
            ->where('nation_id', $recipient->id)
            ->where('role', 'owner')
            ->orderBy('id')
            ->lockForUpdate()
            ->sole();

        return (int) Secretary::query()->where('user_id', $membership->user_id)->sole()->id;
    }

    /**
     * @param  array{rarity_weights: array<string, int>, level_cap_percent: int}  $table
     * @return array{item_key: string, level: int}
     */
    private function drawItem(TurnContext $context, MonsterInstance $monster, array $table, int $version): array
    {
        $settings = $context->ruleset->settings;
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
        if ($monster->definition->key === 'nyowamiya'
            && isset($settings['monster_system']['item_drop']['nyowamiya_love_emblem_replacement_percent'])
            && $context->random->stream(TurnRandomStreamFactory::monsterItemDrop(
                (int) $monster->id, 'emblem_replacement', $version,
            ))->integer(1, 100) <= (int) $settings['monster_system']['item_drop']['nyowamiya_love_emblem_replacement_percent']) {
            $itemKey = SecretaryItemCatalog::LOVE_EMBLEM;
            $level = 1;
        }

        return ['item_key' => $itemKey, 'level' => $level];
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
