<?php

namespace App\Application;

use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\World\WorldMutationLock;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class SecretaryTicketGachaService
{
    public function __construct(
        private WorldMutationLock $worldMutationLock,
        private NextProductionTurnRunGuard $turnRunGuard,
        private CurrentRulesetGuard $rulesetGuard,
        private SecretaryItemCatalog $catalog,
        private SecretaryItemGrantService $grants,
    ) {}

    /** @return array<string, mixed> */
    public function draw(User $user, int $ticketId, string $requestKey, ?int $requestedDrawCount = null): array
    {
        $world = World::query()->where('key', config('hakoniwa.world.key'))->firstOrFail();
        $this->worldMutationLock->acquire($world);
        try {
            return DB::transaction(function () use ($user, $world, $ticketId, $requestKey, $requestedDrawCount): array {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                $this->turnRunGuard->assertClear($lockedWorld);

                $secretary = Secretary::query()->where('user_id', $user->id)->first();
                if (! $secretary instanceof Secretary) {
                    throw new DomainException('秘書がまだ作成されていません。');
                }
                $secretary->lockSurfaceState();

                $previous = DB::table('secretary_gacha_draws')
                    ->where('secretary_id', $secretary->id)
                    ->where('request_key', $requestKey)
                    ->lockForUpdate()->first();
                if ($previous !== null) {
                    $snapshot = json_decode($previous->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
                    // Pre-5.5.0 receipts contain only the drawn item list.
                    $sameCount = array_is_list($snapshot)
                        ? $requestedDrawCount === null || $requestedDrawCount === (int) $previous->ticket_level
                        : ($snapshot['request_draw_count'] ?? null) === $requestedDrawCount;
                    if (! $sameCount) {
                        throw new DomainException('同じ受付キーが別の抽選回数に使用されています。', 409);
                    }
                    if ((int) $previous->ticket_item_instance_id !== $ticketId) {
                        throw new DomainException('この抽選IDは別のチケットに使用されています。', 409);
                    }

                    return array_is_list($snapshot) ? ['items' => $snapshot] : $snapshot['result'];
                }

                $ticket = SecretaryItemInstance::query()->whereKey($ticketId)
                    ->where('secretary_id', $secretary->id)->lockForUpdate()->first();
                if (! $ticket instanceof SecretaryItemInstance
                    || ! in_array($ticket->item_key, [SecretaryItemCatalog::WAKUWAKU_TICKET, SecretaryItemCatalog::DOKIDOKI_TICKET], true)
                    || $ticket->equipped_slot !== null || $ticket->is_escrowed) {
                    throw new DomainException('使用できるチケットを選んでください。');
                }
                $settings = $ruleset->settings['secretary']['ticket_gacha'][$ticket->item_key] ?? null;
                $authoredItems = $ruleset->settings['secretary']['items'] ?? null;
                if (! is_array($settings) || ! is_array($authoredItems)) {
                    throw new DomainException('このRulesetではチケット抽選を利用できません。');
                }
                $ticketLevel = (int) $ticket->level;
                $drawCount = $requestedDrawCount ?? $ticketLevel;
                $used = $secretary->itemInstances()->count();
                $capacity = $this->grants->capacity($secretary);
                if ($ticketLevel < 1 || $ticketLevel > $this->catalog->maximumRetainedLevel($ticket->item_key)
                    || $drawCount < 1 || $drawCount > $ticketLevel
                    || $drawCount > self::maximumDrawCount($ticketLevel, $used, $capacity)) {
                    throw new DomainException('抽選結果を受け取る倉庫の空きがありません。');
                }

                $weights = $settings['rarity_weights_basis_points'] ?? null;
                if (! is_array($weights) || array_sum($weights) !== 10_000) {
                    throw new DomainException('チケット抽選の排出率が不正です。');
                }
                $pools = [];
                foreach ($authoredItems as $itemKey => $authored) {
                    if (! is_array($authored) || ($authored['gacha_exception'] ?? true) !== false) {
                        continue;
                    }
                    $rarity = $authored['rarity'] ?? null;
                    if (is_string($rarity)) {
                        $pools[$rarity][] = $itemKey;
                    }
                }

                $remainingLevel = $ticketLevel - $drawCount;
                if ($remainingLevel === 0) {
                    $ticket->delete();
                } else {
                    $ticket->level = $remainingLevel;
                    $ticket->save();
                }
                $result = [];
                for ($index = 1; $index <= $drawCount; $index++) {
                    $rarity = $this->drawRarity($weights);
                    $pool = $pools[$rarity] ?? [];
                    if ($pool === []) {
                        throw new DomainException('抽選枠にアイテムが登録されていません。');
                    }
                    $itemKey = $pool[random_int(0, count($pool) - 1)];
                    $definition = $this->catalog->definition($itemKey);
                    $level = random_int(1, $definition['max_level']);
                    $item = $this->grants->grant(
                        $secretary, $itemKey, $level, null,
                        "ticket-gacha:v1:{$secretary->id}:{$requestKey}:{$index}",
                    );
                    if (! $item instanceof SecretaryItemInstance) {
                        throw new DomainException('抽選結果の倉庫への追加に失敗しました。');
                    }
                    $result[] = [
                        'key' => $itemKey,
                        'name' => $definition['name'],
                        'level' => $level,
                        'rarity' => $rarity,
                    ];
                }

                $response = [
                    'items' => $result,
                    'ticket' => $remainingLevel === 0 ? null : [
                        'item_id' => $ticketId, 'remaining_level' => $remainingLevel,
                    ],
                    'warehouse' => ['used' => $used + $drawCount - ($remainingLevel === 0 ? 1 : 0), 'capacity' => $capacity],
                ];
                DB::table('secretary_gacha_draws')->insert([
                    'secretary_id' => $secretary->id,
                    'request_key' => $requestKey,
                    'ticket_item_instance_id' => $ticketId,
                    'ticket_key' => $ticket->item_key,
                    'ticket_level' => $ticketLevel,
                    'result_snapshot' => json_encode([
                        'request_draw_count' => $requestedDrawCount,
                        'result' => $response,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $response;
            }, 3);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    public static function maximumDrawCount(int $ticketLevel, int $used, int $capacity): int
    {
        $free = $capacity - $used;

        return $ticketLevel <= $free + 1 ? $ticketLevel : max(0, $free);
    }

    /** @param array<string, mixed> $weights */
    private function drawRarity(array $weights): string
    {
        $draw = random_int(1, 10_000);
        $cumulative = 0;
        foreach ($weights as $rarity => $weight) {
            if (! is_int($weight) || $weight < 0) {
                throw new DomainException('チケット抽選の排出率が不正です。');
            }
            $cumulative += $weight;
            if ($draw <= $cumulative) {
                return $rarity;
            }
        }

        throw new DomainException('チケット抽選の結果を決定できません。');
    }
}
