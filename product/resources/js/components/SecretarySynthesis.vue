<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';

interface RecipeItemRef { item_key: string; name: string; level?: number; count?: number; owned_count?: number }
interface SynthesisRecipe {
    key: string;
    kind: 'fixed' | 'stack';
    name: string;
    rarity: string;
    rarity_label: string;
    inputs?: RecipeItemRef[];
    output?: RecipeItemRef & { effect_text?: string | null; flavor_text?: string | null };
    item_key?: string;
    level_cap?: number;
    effect_text?: string | null;
    crafted: boolean;
}
interface SynthesisCandidate {
    recipe_key: string;
    material_ids: number[];
    base_id: number | null;
    materials: Array<{ id: number; name: string; level: number; is_equipped: boolean }>;
    left_over_count?: number;
    preview: { result_name: string; result_level: number; consumed_count: number; warehouse_delta: number };
}
interface SynthesisResponse {
    recipes: SynthesisRecipe[];
    available: SynthesisCandidate[];
    warehouse: { used: number; capacity: number };
}
interface SynthesisResult {
    result: { item_id: number; item_key: string; name: string; level: number; equipped_slot: number | null; flavor_text?: string | null };
    consumed_ids: number[];
    base_id: number | null;
    warehouse: { used: number; capacity: number };
    first_craft: boolean;
}

const props = defineProps<{ warehouse?: { used: number; capacity: number } }>();
const emit = defineEmits<{ changed: [] ; expand: [] }>();
const data = ref<SynthesisResponse | null>(null);
const pane = ref<'craft' | 'recipes'>('craft');
const recipeFilter = ref<'all' | 'crafted'>('all');
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const confirm = ref<SynthesisCandidate | null>(null);
const celebration = ref<SynthesisResult | null>(null);
const highlighted = ref<string | null>(null);
const pending = ref<{ fingerprint: string; requestId: string } | null>(null);

const warehouse = computed(() => props.warehouse ?? data.value?.warehouse);
const recipeByKey = computed(() => new Map((data.value?.recipes ?? []).map(recipe => [recipe.key, recipe])));
const fixedRecipes = computed(() => (data.value?.recipes ?? []).filter(recipe => recipe.kind === 'fixed' && (recipeFilter.value === 'all' || recipe.crafted)));
const stackRecipes = computed(() => (data.value?.recipes ?? []).filter(recipe => recipe.kind === 'stack' && (recipeFilter.value === 'all' || recipe.crafted)));
const stackCandidates = computed(() => (data.value?.available ?? []).filter(candidate => recipeByKey.value.get(candidate.recipe_key)?.kind === 'stack'));
const fixedCandidates = computed(() => (data.value?.available ?? []).filter(candidate => recipeByKey.value.get(candidate.recipe_key)?.kind === 'fixed'));

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        data.value = await api<SynthesisResponse>('/api/v1/me/secretary/synthesis');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '合成の情報を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

async function openRecipe(key: string): Promise<void> {
    pane.value = 'recipes';
    recipeFilter.value = 'all';
    highlighted.value = key;
    await nextTick();
    document.getElementById(`synthesis-recipe-${key}`)?.scrollIntoView?.({ block: 'center' });
}

function warehouseText(delta: number): string {
    return delta < 0 ? `倉庫が${-delta}つ空きます` : delta > 0 ? `倉庫を${delta}つ使います` : '倉庫の数は変わりません';
}

async function synthesize(): Promise<void> {
    const candidate = confirm.value;
    if (!candidate || busy.value) return;
    const fingerprint = `${candidate.recipe_key}:${candidate.material_ids.join(',')}`;
    const current = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: crypto.randomUUID() };
    pending.value = current;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<SynthesisResult>('/api/v1/me/secretary/synthesis', {
            method: 'POST',
            body: JSON.stringify({ request_id: current.requestId, recipe_key: candidate.recipe_key, material_ids: candidate.material_ids, base_id: candidate.base_id }),
        });
        pending.value = null;
        confirm.value = null;
        celebration.value = result;
        emit('changed');
        await load();
    } catch (caught) {
        if (caught instanceof ApiError && caught.code === 'materials_invalid') {
            pending.value = null;
            confirm.value = null;
            await load();
            error.value = '倉庫の状態が変わっていたので、合成できる組を出し直しました。';
        } else {
            error.value = caught instanceof Error ? caught.message : '合成できませんでした。';
        }
    } finally {
        busy.value = false;
    }
}

const isRelicCelebration = computed(() => {
    const key = celebration.value?.result.item_key;
    return key ? (data.value?.recipes.find(recipe => recipe.output?.item_key === key)?.rarity === 'relic') : false;
});

onMounted(() => { void load(); });
</script>

<template>
    <div class="secretary-synthesis">
        <header class="secretary-synthesis-heading">
            <h3 class="secretary-section-title">合成</h3>
            <p v-if="warehouse">倉庫 {{ warehouse.used }} / {{ warehouse.capacity }} <button type="button" class="link-button" @click="emit('expand')">倉庫を広げる</button></p>
        </header>
        <nav class="secretary-synthesis-tabs" aria-label="合成の切り替え">
            <button type="button" :aria-pressed="pane === 'craft'" @click="pane = 'craft'">合成する</button>
            <button type="button" :aria-pressed="pane === 'recipes'" @click="pane = 'recipes'">レシピ帳</button>
        </nav>
        <p v-if="loading" class="status" role="status">読み込んでいます…</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="data && pane === 'craft'">
            <section class="secretary-synthesis-group" aria-labelledby="synthesis-stack-title">
                <h4 id="synthesis-stack-title">まとめられるアイテム</h4>
                <p v-if="stackCandidates.length === 0" class="empty-state">同じお守りやチケットが2つ以上あると、ここでまとめられます。</p>
                <ul class="secretary-synthesis-list">
                    <li v-for="candidate in stackCandidates" :key="candidate.recipe_key">
                        <div>
                            <strong>{{ candidate.preview.result_name }}</strong> ×{{ candidate.materials.length }}個
                            <span class="secretary-synthesis-levels">（Lv {{ candidate.materials.map(material => material.level).join('・') }}）</span>
                            <p>→ Lv{{ candidate.preview.result_level.toLocaleString('ja-JP') }} ×1個・{{ warehouseText(candidate.preview.warehouse_delta) }}</p>
                            <p v-if="candidate.left_over_count" class="secretary-synthesis-note">Lv9999まで。残り{{ candidate.left_over_count }}個はそのまま倉庫に残ります。</p>
                            <p v-if="candidate.materials.some(material => material.is_equipped)" class="secretary-synthesis-note">装備中の1個に足します。</p>
                        </div>
                        <div class="secretary-synthesis-actions">
                            <button type="button" class="button primary" :disabled="busy" @click="confirm = candidate">まとめる</button>
                            <button type="button" class="link-button" @click="openRecipe(candidate.recipe_key)">レシピ ›</button>
                        </div>
                    </li>
                </ul>
            </section>
            <section class="secretary-synthesis-group" aria-labelledby="synthesis-fixed-title">
                <h4 id="synthesis-fixed-title">作れるレシピ</h4>
                <p v-if="fixedCandidates.length === 0" class="empty-state">素材が揃ったレシピはまだありません。<button type="button" class="link-button" @click="pane = 'recipes'">レシピ帳を見る</button></p>
                <ul class="secretary-synthesis-list">
                    <li v-for="candidate in fixedCandidates" :key="candidate.recipe_key" :class="`rarity-${recipeByKey.get(candidate.recipe_key)?.rarity}`">
                        <div>
                            <strong>✦ {{ candidate.preview.result_name }}</strong>（{{ recipeByKey.get(candidate.recipe_key)?.rarity_label }}）
                            <p>{{ candidate.materials.map(material => `${material.name} ✓`).join(' ・ ') }}</p>
                        </div>
                        <div class="secretary-synthesis-actions">
                            <button type="button" class="button primary" :disabled="busy" @click="confirm = candidate">合成する</button>
                            <button type="button" class="link-button" @click="openRecipe(candidate.recipe_key)">レシピ ›</button>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <template v-if="data && pane === 'recipes'">
            <div class="secretary-synthesis-filter" role="group" aria-label="レシピの絞り込み">
                <button type="button" :aria-pressed="recipeFilter === 'all'" @click="recipeFilter = 'all'">全部</button>
                <button type="button" :aria-pressed="recipeFilter === 'crafted'" @click="recipeFilter = 'crafted'">作ったことがある</button>
            </div>
            <section class="secretary-synthesis-group" aria-labelledby="recipes-fixed-title">
                <h4 id="recipes-fixed-title">固定レシピ</h4>
                <article v-for="recipe in fixedRecipes" :id="`synthesis-recipe-${recipe.key}`" :key="recipe.key" class="secretary-recipe" :class="[`rarity-${recipe.rarity}`, { highlighted: highlighted === recipe.key }]">
                    <header><strong>✦ {{ recipe.name }}</strong><span>{{ recipe.rarity_label }}</span><span v-if="recipe.crafted" class="secretary-recipe-crafted">✓ 作成済み</span></header>
                    <p class="secretary-recipe-formula">
                        <template v-for="(input, index) in recipe.inputs ?? []" :key="input.item_key"><template v-if="index > 0"> ＋ </template><span :class="{ owned: (input.owned_count ?? 0) > 0 }">{{ input.name }}<template v-if="(input.owned_count ?? 0) > 0"> ✓</template></span></template>
                    </p>
                    <p v-if="recipe.output?.effect_text" class="item-effect">効果: {{ recipe.output.effect_text }}</p>
                    <p v-if="recipe.output?.flavor_text" class="item-flavor">{{ recipe.output.flavor_text }}</p>
                </article>
                <p v-if="fixedRecipes.length === 0" class="empty-state">該当するレシピはありません。</p>
            </section>
            <section class="secretary-synthesis-group" aria-labelledby="recipes-stack-title">
                <h4 id="recipes-stack-title">Lv合算</h4>
                <ul class="secretary-recipe-stack-list">
                    <li v-for="recipe in stackRecipes" :id="`synthesis-recipe-${recipe.key}`" :key="recipe.key" :class="{ highlighted: highlighted === recipe.key }">
                        <strong>{{ recipe.name }}</strong>
                        <span>同じ物どうしでLvを足す（最大{{ (recipe.level_cap ?? 9999).toLocaleString('ja-JP') }}）</span>
                        <span v-if="recipe.crafted" class="secretary-recipe-crafted">✓</span>
                    </li>
                </ul>
            </section>
        </template>

        <div v-if="confirm" class="modal-backdrop" @click.self="!busy && (confirm = null)">
            <section class="secretary-synthesis-dialog" role="dialog" aria-modal="true" aria-labelledby="synthesis-confirm-title">
                <h2 id="synthesis-confirm-title">{{ confirm.preview.result_name }}を{{ recipeByKey.get(confirm.recipe_key)?.kind === 'stack' ? 'まとめ' : '合成し' }}ますか？</h2>
                <dl>
                    <div><dt>消える素材</dt><dd>{{ confirm.preview.consumed_count }}個</dd></div>
                    <div><dt>できる物</dt><dd>{{ confirm.preview.result_name }} Lv{{ confirm.preview.result_level.toLocaleString('ja-JP') }}</dd></div>
                    <div><dt>倉庫</dt><dd>{{ warehouseText(confirm.preview.warehouse_delta) }}</dd></div>
                </dl>
                <ul class="secretary-synthesis-materials">
                    <li v-for="material in confirm.materials" :key="material.id">{{ material.name }} Lv{{ material.level }}<template v-if="material.id === confirm.base_id">（これが残ります）</template></li>
                </ul>
                <footer>
                    <button class="button secondary" type="button" :disabled="busy" @click="confirm = null">やめる</button>
                    <button class="button primary" type="button" :disabled="busy" @click="synthesize">{{ busy ? '処理中…' : '確定する' }}</button>
                </footer>
            </section>
        </div>

        <div v-if="celebration" class="modal-backdrop" @click.self="celebration = null">
            <section class="secretary-synthesis-dialog secretary-synthesis-done" :class="{ relic: isRelicCelebration, first: celebration.first_craft }" role="dialog" aria-modal="true" aria-labelledby="synthesis-done-title">
                <p class="secretary-synthesis-done-eyebrow">{{ celebration.first_craft ? 'はじめての合成' : '合成完了' }}</p>
                <h2 id="synthesis-done-title">{{ celebration.result.name }} Lv{{ celebration.result.level.toLocaleString('ja-JP') }}</h2>
                <p v-if="celebration.result.flavor_text" class="item-flavor">{{ celebration.result.flavor_text }}</p>
                <p>倉庫 {{ celebration.warehouse.used }} / {{ celebration.warehouse.capacity }}</p>
                <footer><button class="button primary" type="button" @click="celebration = null">閉じる</button></footer>
            </section>
        </div>
    </div>
</template>
