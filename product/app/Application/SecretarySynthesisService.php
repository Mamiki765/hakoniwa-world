<?php

namespace App\Application;

use App\Domain\Secretary\SecretaryInventoryException;
use App\Domain\Secretary\SecretaryItemCatalog;
use App\Domain\Secretary\SecretaryItemGameplayContract;
use App\Domain\Secretary\SecretaryNotFoundException;
use App\Models\Secretary;
use App\Models\SecretaryItemInstance;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SecretarySynthesisService
{
    public function __construct(
        private SecretaryEquipmentService $equipment,
        private SecretaryItemCatalog $catalog,
        private SecretaryItemGameplayContract $gameplay,
        private SecretaryItemGrantService $grants,
        private SecretaryWarehouseService $warehouse,
    ) {}

    /** @return array<string, mixed> */
    public function index(User $user): array
    {
        $secretary = Secretary::query()->where('user_id', $user->id)->with(['surfaceState', 'itemInstances'])->first();
        if (! $secretary instanceof Secretary) {
            throw new SecretaryNotFoundException('秘書がまだ作成されていません。');
        }
        $crafted = DB::table('secretary_synthesis_receipts')->where('secretary_id', $secretary->id)
            ->distinct()->pluck('recipe_key')->all();
        $recipes = [];
        $available = [];
        foreach ($this->recipes() as $recipe) {
            $recipes[] = $this->presentRecipe($recipe, $secretary->itemInstances, in_array($recipe['key'], $crafted, true));
            $candidate = $this->candidate($recipe, $secretary->itemInstances);
            if ($candidate !== null) {
                $available[] = $candidate;
            }
        }

        return [
            'recipes' => $recipes,
            'crafted_recipe_keys' => $crafted,
            'available' => $available,
            'warehouse' => $this->warehouse->present($secretary, $secretary->itemInstances->count()),
        ];
    }

    /**
     * @param  list<int>  $materialIds
     * @return array<string, mixed>
     */
    public function synthesize(User $user, string $requestId, string $recipeKey, array $materialIds, ?int $baseId): array
    {
        sort($materialIds, SORT_NUMERIC);
        $fingerprint = hash('sha256', json_encode([$recipeKey, $materialIds, $baseId], JSON_THROW_ON_ERROR));
        $secretary = Secretary::query()->where('user_id', $user->id)->first();
        if (! $secretary instanceof Secretary) {
            throw new SecretaryNotFoundException('秘書がまだ作成されていません。');
        }
        // Re-reading an immutable receipt does not change assets, even during an unresolved Turn.
        $previous = $this->previous($secretary, $requestId, $fingerprint);
        if ($previous !== null) {
            return $previous;
        }

        return $this->equipment->withLockedSurfaceState($user, function (Secretary $secretary) use ($requestId, $recipeKey, $materialIds, $baseId, $fingerprint): array {
            $previous = $this->previous($secretary, $requestId, $fingerprint);
            if ($previous !== null) {
                return $previous;
            }
            $recipe = $this->recipes()[$recipeKey] ?? null;
            if ($recipe === null || count($materialIds) !== count(array_unique($materialIds))) {
                throw $this->invalidMaterials();
            }
            $items = $secretary->itemInstances()->orderBy('id')->lockForUpdate()->get();
            $candidate = $this->candidate($recipe, $items);
            $expectedIds = $candidate['material_ids'] ?? [];
            sort($expectedIds, SORT_NUMERIC);
            if ($candidate === null || $expectedIds !== $materialIds || $candidate['base_id'] !== $baseId) {
                if ($recipe['kind'] === 'stack') {
                    $selected = $items->whereIn('id', $materialIds);
                    if ($selected->count() === count($materialIds)
                        && $selected->every(fn (SecretaryItemInstance $item): bool => $item->item_key === $recipe['item_key'] && ! $item->is_escrowed)
                        && $selected->sum('level') > $recipe['level_cap']) {
                        throw new SecretaryInventoryException('level_cap_exceeded', '合成後のLvが上限を超えています。');
                    }
                }
                throw $this->invalidMaterials();
            }

            $firstCraft = ! DB::table('secretary_synthesis_receipts')->where('secretary_id', $secretary->id)
                ->where('recipe_key', $recipeKey)->exists();
            $consumedIds = array_values(array_filter($materialIds, static fn (int $id): bool => $id !== $baseId));
            if ($recipe['kind'] === 'stack') {
                $resultItem = $items->firstWhere('id', $baseId);
                if (! $resultItem instanceof SecretaryItemInstance) {
                    throw $this->invalidMaterials();
                }
                $resultItem->level = $candidate['preview']['result_level'];
                $resultItem->save();
                if ($resultItem->equipped_slot !== null) {
                    $secretary->surfaceState->equipment_version++;
                    $secretary->surfaceState->save();
                }
                $secretary->itemInstances()->whereIn('id', $consumedIds)->delete();
            } else {
                $secretary->itemInstances()->whereIn('id', $consumedIds)->delete();
                $resultItem = $this->grants->grant(
                    $secretary, $recipe['output']['item_key'], $recipe['output']['level'], null,
                    "synthesis:{$secretary->id}:{$requestId}",
                );
                if (! $resultItem instanceof SecretaryItemInstance) {
                    throw new DomainException('合成したアイテムを倉庫に追加できませんでした。');
                }
            }
            $definition = $this->catalog->definition($resultItem->item_key);
            $result = [
                'result' => [
                    'item_id' => $resultItem->id,
                    'item_key' => $resultItem->item_key,
                    'name' => $definition['name'],
                    'level' => $resultItem->level,
                    'equipped_slot' => $resultItem->equipped_slot,
                    'flavor_text' => $definition['flavor_text'],
                ],
                'consumed_ids' => $consumedIds,
                'base_id' => $baseId,
                'equipment_version' => $secretary->surfaceState->equipment_version,
                'warehouse' => $this->warehouse->present($secretary),
                'first_craft' => $firstCraft,
            ];
            DB::table('secretary_synthesis_receipts')->insert([
                'secretary_id' => $secretary->id,
                'request_id' => $requestId,
                'recipe_key' => $recipeKey,
                'request_fingerprint' => $fingerprint,
                'material_ids' => json_encode($materialIds, JSON_THROW_ON_ERROR),
                'result_item_instance_id' => $resultItem->id,
                'result_snapshot' => json_encode($result, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $result;
        });
    }

    /** @return array<string, mixed>|null */
    private function previous(Secretary $secretary, string $requestId, string $fingerprint): ?array
    {
        $previous = DB::table('secretary_synthesis_receipts')
            ->where('secretary_id', $secretary->id)->where('request_id', $requestId)->first();
        if ($previous === null) {
            return null;
        }
        if ($previous->request_fingerprint !== $fingerprint) {
            throw new SecretaryInventoryException('request_conflict', '同じ受付IDが別の合成操作に使用されています。', 409);
        }

        return json_decode($previous->result_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array<string, mixed>> */
    private function recipes(): array
    {
        $authored = config('hakoniwa.ruleset.secretary.synthesis.recipes', []);
        if (! is_array($authored)) {
            throw new DomainException('Secretary synthesis recipes are invalid.');
        }
        $recipes = [];
        foreach ($authored as $recipe) {
            if (($recipe['kind'] ?? null) === 'stack') {
                foreach ($recipe['item_keys'] ?? [$recipe['item_key']] as $itemKey) {
                    $key = 'stack:'.$itemKey;
                    $recipes[$key] = [...$recipe, 'key' => $key, 'item_key' => $itemKey];
                }
            } elseif (($recipe['kind'] ?? null) === 'fixed') {
                $recipes[$recipe['key']] = $recipe;
            } else {
                throw new DomainException('Secretary synthesis recipe kind is invalid.');
            }
        }

        return $recipes;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @param  Collection<int, SecretaryItemInstance>  $items
     * @return array<string, mixed>|null
     */
    private function candidate(array $recipe, Collection $items): ?array
    {
        $eligible = $items->filter(static fn (SecretaryItemInstance $item): bool => ! $item->is_escrowed);
        $baseId = null;
        $leftOver = 0;
        if ($recipe['kind'] === 'stack') {
            $eligible = $eligible->where('item_key', $recipe['item_key'])->sortBy('id')->values();
            $base = $eligible->first(static fn (SecretaryItemInstance $item): bool => $item->equipped_slot !== null) ?? $eligible->first();
            if (! $base instanceof SecretaryItemInstance || $base->level < 1 || $base->level >= $recipe['level_cap']) {
                return null;
            }
            $baseId = $base->id;
            $selected = [$base];
            $level = $base->level;
            $others = $eligible->filter(static fn (SecretaryItemInstance $item): bool => $item->id !== $baseId && $item->equipped_slot === null)
                ->sortBy([['level', 'asc'], ['id', 'asc']]);
            foreach ($others as $item) {
                if ($item->level >= 1 && $level + $item->level <= $recipe['level_cap']) {
                    $selected[] = $item;
                    $level += $item->level;
                }
            }
            if (count($selected) < 2) {
                return null;
            }
            $leftOver = $eligible->count() - count($selected);
            $resultKey = $recipe['item_key'];
            $consumed = count($selected) - 1;
        } else {
            $selected = [];
            foreach ($recipe['inputs'] as $input) {
                $matches = $this->matchingUnequippedMaterials($eligible, $input)->sortBy('id')->take($input['count']);
                if ($matches->count() !== $input['count']) {
                    return null;
                }
                array_push($selected, ...$matches->all());
            }
            $resultKey = $recipe['output']['item_key'];
            $level = $recipe['output']['level'];
            $consumed = count($selected);
        }
        $definition = $this->catalog->definition($resultKey);

        return [
            'recipe_key' => $recipe['key'],
            'material_ids' => array_map(static fn (SecretaryItemInstance $item): int => $item->id, $selected),
            'base_id' => $baseId,
            'materials' => array_map(fn (SecretaryItemInstance $item): array => [
                'id' => $item->id, 'name' => $this->catalog->definition($item->item_key)['name'],
                'level' => $item->level, 'is_equipped' => $item->equipped_slot !== null,
            ], $selected),
            'left_over_count' => $leftOver,
            'preview' => [
                'result_item_key' => $resultKey, 'result_name' => $definition['name'], 'result_level' => $level,
                'consumed_count' => $consumed, 'warehouse_delta' => 1 - count($selected),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @param  Collection<int, SecretaryItemInstance>  $items
     * @return array<string, mixed>
     */
    private function presentRecipe(array $recipe, Collection $items, bool $crafted): array
    {
        $resultKey = $recipe['kind'] === 'stack' ? $recipe['item_key'] : $recipe['output']['item_key'];
        $definition = $this->catalog->definition($resultKey);
        $presented = [
            ...$recipe,
            'name' => $definition['name'], 'rarity' => $definition['rarity'],
            'rarity_label' => $definition['rarity_label'], 'crafted' => $crafted,
        ];
        if ($recipe['kind'] === 'fixed') {
            $presented['inputs'] = array_map(fn (array $input): array => [
                ...$input, 'name' => $this->catalog->definition($input['item_key'])['name'],
                'owned_count' => $this->matchingUnequippedMaterials($items, $input)->where('is_escrowed', false)->count(),
            ], $recipe['inputs']);
            $presented['output'] = [
                ...$recipe['output'], 'name' => $definition['name'],
                'effect_text' => $this->gameplay->effectText(config('hakoniwa.ruleset'), $resultKey, $recipe['output']['level']),
                'flavor_text' => $definition['flavor_text'],
            ];
        }

        return $presented;
    }

    /**
     * @param  Collection<int, SecretaryItemInstance>  $items
     * @param  array<string, mixed>  $input
     * @return Collection<int, SecretaryItemInstance>
     */
    private function matchingUnequippedMaterials(Collection $items, array $input): Collection
    {
        return $items->where('item_key', $input['item_key'])->where('level', $input['level'])
            ->whereNull('equipped_slot');
    }

    private function invalidMaterials(): SecretaryInventoryException
    {
        return new SecretaryInventoryException('materials_invalid', '倉庫の状態が変わりました。合成する素材を確認し直してください。');
    }
}
