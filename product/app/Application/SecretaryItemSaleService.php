<?php

namespace App\Application;

use App\Domain\Economy\CapacityBoundedAssetService;
use App\Domain\Economy\NationCapacityResolver;
use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\World\WorldMutationLock;
use App\Models\Nation;
use App\Models\NationMembership;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;

final class SecretaryItemSaleService
{
    public function __construct(
        private readonly WorldMutationLock $worldMutationLock,
        private readonly NextProductionTurnRunGuard $turnRunGuard,
        private readonly CurrentRulesetGuard $rulesetGuard,
        private readonly SecretaryItemCatalog $catalog,
        private readonly NationCapacityResolver $capacities,
        private readonly CapacityBoundedAssetService $boundedAssets,
    ) {}

    /** @return array{secretary: Secretary, nation: Nation} */
    public function sell(User $user, int $worldId, int $itemId): array
    {
        $nation = Nation::query()
            ->where('world_id', $worldId)
            ->whereIn('id', NationMembership::query()
                ->select('nation_id')
                ->where('world_id', $worldId)
                ->where('user_id', $user->id)
                ->where('role', 'owner'))
            ->first();
        if (! $nation instanceof Nation) {
            throw new DomainException('所有する島を確認できません。');
        }
        $world = World::query()->findOrFail($worldId);
        $this->worldMutationLock->acquire($world);

        try {
            return DB::transaction(function () use ($user, $world, $nation, $itemId): array {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                $this->turnRunGuard->assertClear($lockedWorld);

                $lockedNation = Nation::query()->whereKey($nation->id)
                    ->where('world_id', $lockedWorld->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $ownsNation = NationMembership::query()
                    ->where('nation_id', $lockedNation->id)
                    ->where('world_id', $lockedWorld->id)
                    ->where('user_id', $user->id)
                    ->where('role', 'owner')
                    ->lockForUpdate()
                    ->exists();
                if (! $ownsNation) {
                    throw new DomainException('所有する島を確認できません。');
                }

                $secretary = Secretary::query()->where('user_id', $user->id)->lockForUpdate()->first();
                if (! $secretary instanceof Secretary) {
                    throw new DomainException('秘書がまだ作成されていません。');
                }
                $item = SecretaryItemInstance::query()
                    ->whereKey($itemId)
                    ->where('secretary_id', $secretary->id)
                    ->lockForUpdate()
                    ->first();
                if (! $item instanceof SecretaryItemInstance) {
                    throw new DomainException('所有していないアイテムは売却できません。');
                }

                $definition = $this->catalog->definition($item->item_key);
                if ($item->item_key === SecretaryItemCatalog::OLD_BOW) {
                    throw new DomainException(($secretary->name ?? '秘書').'が嫌がっています…');
                }
                if ($item->equipped_slot !== null) {
                    throw new DomainException('装備中のアイテムは売却できません。');
                }
                if ($item->is_escrowed) {
                    throw new DomainException('交易場へ出品中のアイテムは売却できません。');
                }

                $price = $this->catalog->fixedSalePrice($item->item_key);
                $capacity = $this->capacities->resolve($lockedNation, $ruleset)->money;
                $credit = $this->boundedAssets->planMoneyCredit($lockedNation, $price, $capacity);
                if ($credit->applied !== $price || $credit->overflow !== 0) {
                    throw new DomainException('資金上限まで全額を受け取れないため売却できません。');
                }

                $itemSnapshot = [
                    'item_instance_id' => $item->id,
                    'item_key' => $item->item_key,
                    'item_name' => $definition['name'],
                    'item_level' => $item->level,
                    'rarity' => $definition['rarity'],
                    'sale_price_money' => $price,
                ];
                $lockedNation->increment('money', $price);
                $item->delete();
                $this->recordSale($user, $lockedWorld, $lockedNation, $secretary, $itemSnapshot);

                return [
                    'secretary' => $secretary->fresh(['skills', 'itemInstances']),
                    'nation' => $lockedNation->fresh(['capital', 'resourceBalances.definition']),
                ];
            }, 3);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    /** @param array<string, int|string> $item */
    private function recordSale(
        User $user,
        World $world,
        Nation $nation,
        Secretary $secretary,
        array $item,
    ): void {
        $now = now();
        DB::table('audit_events')->insert([
            'actor_user_id' => $user->id,
            'world_id' => $world->id,
            'turn' => $world->current_turn,
            'nation_id' => $nation->id,
            'x' => null,
            'y' => null,
            'message' => null,
            'visibility' => 'private',
            'event_type' => 'secretary.item_sold',
            'severity' => 'info',
            'subject_type' => Secretary::class,
            'subject_id' => $secretary->id,
            'metadata' => json_encode([
                'secretary_id' => $secretary->id,
                'nation_id' => $nation->id,
                ...$item,
            ], JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
