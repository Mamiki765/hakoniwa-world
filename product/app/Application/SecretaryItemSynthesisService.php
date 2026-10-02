<?php

namespace App\Application;

use App\Domain\Ruleset\CurrentRulesetGuard;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryItemSynthesisContract;
use App\Domain\World\WorldMutationLock;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use App\Models\World;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class SecretaryItemSynthesisService
{
    private const UNAVAILABLE = 'この組み合わせでは合成できません。最新の倉庫を確認してください。';

    public function __construct(
        private WorldMutationLock $worldMutationLock,
        private NextProductionTurnRunGuard $turnRunGuard,
        private CurrentRulesetGuard $rulesetGuard,
        private SecretaryItemSynthesisContract $recipes,
        private SecretaryItemCatalog $catalog,
        private SecretaryItemGameplayContract $gameplay,
        private SecretaryItemGrantService $grants,
    ) {}

    /** @return list<array<string, mixed>> */
    public function available(User $user): array
    {
        $world = World::query()->where('key', config('hakoniwa.world.key'))->firstOrFail();
        $ruleset = $world->rulesetVersion()->firstOrFail();
        $this->rulesetGuard->assertMutable($world, $ruleset);
        $recipe = $this->recipes->recipe($ruleset->settings);
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if ($recipe === null || ! $secretary instanceof Secretary) {
            return [];
        }
        $items = $secretary->itemInstances()->whereIn('item_key', $recipe['ingredient_keys'])
            ->where('level', $recipe['ingredient_level'])->orderBy('id')->get();
        $ingredients = [];
        foreach ($recipe['ingredient_keys'] as $key) {
            $owned = $items->where('item_key', $key);
            if ($owned->isEmpty()) {
                // No name, result, partial ingredients or recipe identity before discovery.
                return [];
            }
            $ingredients[] = [
                'key' => $key,
                'name' => $this->catalog->definition($key)['name'],
                'level' => $recipe['ingredient_level'],
                'candidate_ids' => $owned->filter(fn (SecretaryItemInstance $item): bool => $item->equipped_slot === null && ! $item->is_escrowed)
                    ->pluck('id')->values()->all(),
            ];
        }
        $definition = $this->catalog->definition($recipe['result_key']);

        return [[
            'key' => $recipe['key'],
            'ingredients' => $ingredients,
            'result' => [
                'name' => $definition['name'], 'level' => $recipe['result_level'],
                'rarity_label' => $definition['rarity_label'],
                'effect_text' => $this->gameplay->effectText($ruleset->settings, $recipe['result_key'], $recipe['result_level']),
            ],
            'cost_money' => $recipe['cost_money'],
        ]];
    }

    /**
     * @param  list<int>  $ingredientIds
     * @return array<string, mixed>
     */
    public function synthesize(User $user, string $recipeKey, array $ingredientIds, string $requestKey): array
    {
        sort($ingredientIds, SORT_NUMERIC);
        if (count($ingredientIds) !== 3 || count(array_unique($ingredientIds)) !== 3) {
            throw new DomainException(self::UNAVAILABLE);
        }
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary) {
            throw new DomainException(self::UNAVAILABLE);
        }
        // Committed receipts are private immutable results, not new mutations.
        // v27 intentionally has no receipt table; never query it blindly.
        $hasReceipts = Schema::hasTable('secretary_item_syntheses');
        if ($hasReceipts && ($replay = $this->replay($secretary, $recipeKey, $ingredientIds, $requestKey)) !== null) {
            return $replay;
        }
        $world = World::query()->where('key', config('hakoniwa.world.key'))->firstOrFail();
        $this->worldMutationLock->acquire($world);
        try {
            return DB::transaction(function () use ($user, $world, $recipeKey, $ingredientIds, $requestKey, $hasReceipts): array {
                $lockedWorld = World::query()->whereKey($world->id)->lockForUpdate()->firstOrFail();
                $secretary = Secretary::query()->where('user_id', $user->id)->first();
                if (! $secretary instanceof Secretary) {
                    throw new DomainException(self::UNAVAILABLE);
                }
                $secretary->lockSurfaceState();
                // The first lookup may have raced the original transaction's commit.
                if ($hasReceipts && ($replay = $this->replay($secretary, $recipeKey, $ingredientIds, $requestKey)) !== null) {
                    return $replay;
                }
                $ruleset = $lockedWorld->rulesetVersion()->firstOrFail();
                $this->rulesetGuard->assertMutable($lockedWorld, $ruleset);
                $this->turnRunGuard->assertClear($lockedWorld);
                $recipe = $this->recipes->recipe($ruleset->settings);
                if ($recipe === null || $recipe['key'] !== $recipeKey) {
                    throw new DomainException(self::UNAVAILABLE);
                }
                $items = $secretary->itemInstances()->whereIn('id', $ingredientIds)->orderBy('id')->lockForUpdate()->get();
                $keys = $items->pluck('item_key')->sort()->values()->all();
                $expectedKeys = $recipe['ingredient_keys'];
                sort($expectedKeys, SORT_STRING);
                if ($items->count() !== 3 || $keys !== $expectedKeys
                    || $items->contains(fn (SecretaryItemInstance $item): bool => $item->level !== $recipe['ingredient_level']
                        || $item->equipped_slot !== null || $item->is_escrowed)
                    || $secretary->itemInstances()->count() - 3 + 1 > SecretaryItemGrantService::INVENTORY_CAPACITY) {
                    throw new DomainException(self::UNAVAILABLE);
                }
                foreach ($items as $item) {
                    $item->delete();
                }
                $result = $this->grants->grant($secretary, $recipe['result_key'], $recipe['result_level'], null,
                    "item-synthesis:v1:{$secretary->id}:{$requestKey}");
                if (! $result instanceof SecretaryItemInstance) {
                    throw new DomainException(self::UNAVAILABLE);
                }
                $definition = $this->catalog->definition($result->item_key);
                $snapshot = [
                    'id' => $result->id, 'key' => $result->item_key,
                    'name' => $definition['name'], 'level' => $result->level, 'rarity' => $definition['rarity'],
                    'consumed_item_ids' => $ingredientIds,
                ];
                DB::table('secretary_item_syntheses')->insert([
                    'secretary_id' => $secretary->id, 'ruleset_version_id' => $ruleset->id,
                    'request_key' => $requestKey, 'recipe_key' => $recipeKey,
                    'ingredient_ids' => json_encode($ingredientIds, JSON_THROW_ON_ERROR),
                    'result_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                return $snapshot;
            }, 3);
        } finally {
            $this->worldMutationLock->release($world);
        }
    }

    /**
     * @param  list<int>  $ingredientIds
     * @return array<string, mixed>|null
     */
    private function replay(Secretary $secretary, string $recipeKey, array $ingredientIds, string $requestKey): ?array
    {
        $receipt = DB::table('secretary_item_syntheses')->where('secretary_id', $secretary->id)
            ->where('request_key', $requestKey)->first();
        if ($receipt === null) {
            return null;
        }
        if ($receipt->recipe_key !== $recipeKey
            || json_decode($receipt->ingredient_ids, true, 512, JSON_THROW_ON_ERROR) !== $ingredientIds) {
            throw new DomainException(self::UNAVAILABLE);
        }

        return json_decode($receipt->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }
}
