<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';

interface Recipe {
    key: string;
    ingredients: { key: string; name: string; level: number; candidate_ids: number[] }[];
    result: { name: string; level: number; rarity_label: string; effect_text: string };
    cost_money: number;
}
interface Pending {
    recipe_key: string;
    ingredient_ids: number[];
    request_key: string;
}
const props = defineProps<{ secretaryId: number; busy: boolean }>();
const emit = defineEmits<{ busy: [value: boolean]; synthesized: [] }>();
const storageKey = `hakoniwa:item-synthesis:${props.secretaryId}`;
const recipes = ref<Recipe[]>([]);
const choices = ref<Record<string, number>>({});
const pending = ref<Pending | null>(null);
const submitting = ref(false);
const error = ref('');
const message = ref('');
const recipe = computed(() => recipes.value[0]);
const ready = computed(() => recipe.value?.ingredients.every(item => item.candidate_ids.includes(choices.value[item.key]!)) ?? false);

function persist(): void {
    try {
        if (pending.value) sessionStorage.setItem(storageKey, JSON.stringify(pending.value));
        else sessionStorage.removeItem(storageKey);
    } catch { /* A blocked storage still retains the current page's intent. */ }
}

async function load(): Promise<void> {
    try {
        recipes.value = (await api<{ recipes: Recipe[] }>('/api/v1/me/secretary/item-synthesis')).recipes;
        choices.value = {};
        for (const ingredient of recipe.value?.ingredients ?? []) {
            if (ingredient.candidate_ids[0] !== undefined) choices.value[ingredient.key] = ingredient.candidate_ids[0];
        }
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '合成候補を取得できませんでした。';
    }
}

onMounted(async () => {
    try {
        const saved: unknown = JSON.parse(sessionStorage.getItem(storageKey) ?? 'null');
        if (saved && typeof saved === 'object' && 'recipe_key' in saved && 'ingredient_ids' in saved && 'request_key' in saved
            && typeof saved.recipe_key === 'string' && typeof saved.request_key === 'string'
            && Array.isArray(saved.ingredient_ids) && saved.ingredient_ids.length === 3
            && saved.ingredient_ids.every(id => Number.isInteger(id) && id > 0)) {
            pending.value = saved as Pending;
        }
    } catch { /* Ignore invalid or unavailable local storage. */ }
    await load();
});

async function submit(): Promise<void> {
    if (props.busy || submitting.value) return;
    if (!pending.value) {
        if (!recipe.value || !ready.value) return;
        const selected = recipe.value;
        if (!window.confirm(`${selected.ingredients.map(item => `${item.name} Lv${item.level}`).join('、')}を各1個消費し、${selected.result.name} Lv${selected.result.level}を1個作ります。追加費用は${selected.cost_money}億円です。合成しますか？`)) return;
        pending.value = {
            recipe_key: selected.key,
            ingredient_ids: selected.ingredients.map(item => choices.value[item.key]!).sort((a, b) => a - b),
            request_key: crypto.randomUUID(),
        };
        persist();
    }
    const request = { ...pending.value, ingredient_ids: [...pending.value.ingredient_ids] };
    submitting.value = true;
    emit('busy', true);
    error.value = '';
    message.value = '';
    try {
        const result = await api<{ item: { name: string; level: number } }>('/api/v1/me/secretary/item-synthesis', {
            method: 'POST', body: JSON.stringify(request),
        });
        pending.value = null;
        persist();
        message.value = `${result.item.name} Lv${result.item.level}を合成しました。`;
        emit('synthesized');
        await load();
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '合成の結果を確認できませんでした。';
        // Only a definitive rejection releases the intent. Unknown results keep
        // the exact IDs/UUID across refreshes and cannot silently consume another set.
        if (cause instanceof ApiError && [409, 422].includes(cause.status)
            && cause.code !== 'secretary_item_synthesis_busy') {
            pending.value = null;
            persist();
            await load();
        }
    } finally {
        submitting.value = false;
        emit('busy', false);
    }
}
</script>

<template>
    <section v-if="recipe || pending || error || message" class="item-synthesis" aria-label="アイテム合成">
        <h3 class="secretary-section-title">アイテム合成</h3>
        <template v-if="recipe && !pending">
            <p>{{ recipe.result.rarity_label }}・{{ recipe.result.name }} Lv{{ recipe.result.level }}</p>
            <p>{{ recipe.result.effect_text }}</p>
            <div class="synthesis-ingredients">
                <label v-for="ingredient in recipe.ingredients" :key="ingredient.key">
                    {{ ingredient.name }} Lv{{ ingredient.level }} × 1
                    <select v-model="choices[ingredient.key]" :disabled="busy || submitting">
                        <option v-if="ingredient.candidate_ids.length === 0" :value="undefined">装備・出品を解除してください</option>
                        <option v-for="(id, index) in ingredient.candidate_ids" :key="id" :value="id">未装備の候補 {{ index + 1 }}</option>
                    </select>
                </label>
            </div>
            <p>選んだ素材は各1個消費されます。追加費用：{{ recipe.cost_money }}億円</p>
        </template>
        <p v-if="pending">送信済みの合成の結果を確認します。素材の組み合わせは変更できません。</p>
        <button v-if="recipe || pending" class="button primary" type="button" :disabled="busy || submitting || (!pending && !ready)" @click="submit">
            {{ submitting ? '確認しています…' : pending ? '送信済みの結果を確認' : '素材を確認して合成' }}
        </button>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="message" role="status">{{ message }}</p>
    </section>
</template>

<style scoped>
.item-synthesis { border: 1px solid currentColor; border-radius: 0.75rem; padding: 1rem; margin-bottom: 1rem; }
.synthesis-ingredients { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 13rem), 1fr)); gap: 0.75rem; }
.synthesis-ingredients label { display: grid; gap: 0.3rem; }
.synthesis-ingredients select { width: 100%; min-width: 0; padding: 0.5rem; }
.item-synthesis p { overflow-wrap: anywhere; }
</style>
