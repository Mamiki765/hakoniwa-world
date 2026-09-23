<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import EquipmentItemCard, { type EquipmentItem, type EquipmentSlot } from './EquipmentItemCard.vue';

interface PolishingState {
    shard_balance: number;
    item: EquipmentItem | null;
    next_item: EquipmentItem | null;
    next_price: number | null;
    maximum_level: number;
}
interface MutationState {
    shard_balance: number;
    banked_shard_balance: number;
    vault: { used: number; capacity: number; equipped: Record<EquipmentSlot, EquipmentItem | null> };
}
interface CrystalPage { items: EquipmentItem[]; page: number; last_page: number }
interface Pending { path: string; method: string; body: Record<string, string | number> }

const emit = defineEmits<{ updated: [value: MutationState] }>();
const props = defineProps<{ balance: number }>();
const state = ref<PolishingState | null>(null);
const vault = ref<CrystalPage | null>(null);
const chooser = ref<HTMLDialogElement | null>(null);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const pending = ref<Pending | null>(null);

async function load(): Promise<void> {
    state.value = await api<PolishingState>('/api/v1/me/underground/equipment/polishing');
}
async function loadPage(page = 1): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        vault.value = await api<CrystalPage>(`/api/v1/me/underground/equipment/vault?inventory=resonance&page=${page}`);
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '結晶庫を読み込めませんでした。';
    } finally { busy.value = false; }
}
async function openChooser(): Promise<void> {
    if (busy.value || pending.value) return;
    chooser.value?.showModal();
    await loadPage();
}
async function submit(operation: Pending): Promise<void> {
    if (busy.value) return;
    pending.value = operation;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const result = await api<MutationState>(operation.path, { method: operation.method, body: JSON.stringify(operation.body) });
        pending.value = null;
        if (chooser.value?.open) chooser.value.close();
        emit('updated', result);
        notice.value = operation.method === 'POST' ? '結晶を研磨しました。' : '装備する結晶を交換しました。';
        await load();
    } catch (caught) {
        if (caught instanceof ApiError && caught.status >= 400 && caught.status < 500 && ![408, 429].includes(caught.status)) {
            pending.value = null;
        }
        error.value = caught instanceof Error ? caught.message : '操作の結果を確認できませんでした。';
    } finally { busy.value = false; }
}
function polish(): void {
    const current = state.value;
    if (pending.value || !current?.item?.id || current.next_price === null) return;
    void submit({ path: '/api/v1/me/underground/equipment/polishing', method: 'POST', body: {
        request_id: crypto.randomUUID(), item_id: current.item.id, level: current.item.polish_level ?? 0, price: current.next_price,
    } });
}
function equip(item: EquipmentItem): void {
    if (pending.value || !item.id) return;
    void submit({ path: '/api/v1/me/underground/equipment/equipped', method: 'PUT',
        body: { request_id: crypto.randomUUID(), item_id: item.id, target_slot: 'resonance' } });
}
onMounted(async () => {
    try { await load(); }
    catch (caught) { error.value = caught instanceof Error ? caught.message : '研磨の情報を読み込めませんでした。'; }
});
</script>

<template>
    <section class="ug-polishing" aria-labelledby="ug-polishing-title">
        <h2 id="ug-polishing-title">魔石研磨</h2>
        <p>装備中の共鳴結晶を研磨します。結晶を選ぶと装備を交換できます。</p>
        <p v-if="error" role="alert">{{ error }}</p>
        <p v-if="notice" role="status">{{ notice }}</p>
        <button v-if="pending" type="button" :disabled="busy" @click="submit(pending)">前の操作の結果を確認する</button>
        <div v-if="state" class="ug-polishing-preview">
            <div>
                <h3>装備中</h3>
                <div
                    v-if="state.item" role="button" tabindex="0" aria-label="装備する共鳴結晶を選ぶ"
                    :aria-disabled="busy || Boolean(pending)" @click="openChooser" @keydown.enter.prevent="openChooser" @keydown.space.prevent="openChooser"
                >
                    <EquipmentItemCard :item="state.item" mode="owned"><template #price>選んで装備を交換</template></EquipmentItemCard>
                </div>
                <button v-else type="button" :disabled="busy || Boolean(pending)" @click="openChooser">共鳴結晶を装備する</button>
            </div>
            <div v-if="state.next_item">
                <h3>研磨後</h3>
                <EquipmentItemCard :item="state.next_item" mode="owned"><template #price>固定能力・固有効果・追加効果が上昇</template></EquipmentItemCard>
            </div>
        </div>
        <template v-if="state?.item">
            <template v-if="state.next_price !== null">
                <p>今回の研磨費用：{{ state.next_price.toLocaleString('ja-JP') }} G</p>
                <button class="ug-primary" type="button" :disabled="busy || Boolean(pending) || props.balance < state.next_price" @click="polish">
                    {{ busy ? '研磨中…' : '研磨する' }}
                </button>
                <p v-if="props.balance < state.next_price">手持ちのGが足りません。</p>
            </template>
            <p v-else>＋{{ state.maximum_level }}まで研磨済みです。</p>
        </template>
        <dialog ref="chooser" class="ug-polishing-chooser" aria-label="装備する共鳴結晶" @cancel="busy && $event.preventDefault()">
            <header><h2>装備する共鳴結晶</h2><button type="button" :disabled="busy" @click="chooser?.close()">閉じる</button></header>
            <p v-if="error" role="alert">{{ error }}</p>
            <button v-if="pending" type="button" :disabled="busy" @click="submit(pending)">前の操作の結果を確認する</button>
            <p v-if="vault?.items.length === 0">共鳴結晶を持っていません。</p>
            <div class="ug-polishing-crystals">
                <EquipmentItemCard
                    v-for="item in vault?.items ?? []" :key="item.id" :item="item" mode="owned"
                    :disabled="busy || Boolean(pending) || item.equipped_slot === 'resonance'" @action="equip(item)"
                >
                    <template #action>{{ item.equipped_slot === 'resonance' ? '装備中' : 'この結晶を装備する' }}</template>
                </EquipmentItemCard>
            </div>
            <nav v-if="vault && vault.last_page > 1" aria-label="結晶庫のページ">
                <button type="button" :disabled="busy || vault.page <= 1" @click="loadPage(vault.page - 1)">前へ</button>
                <span>{{ vault.page }} / {{ vault.last_page }}</span>
                <button type="button" :disabled="busy || vault.page >= vault.last_page" @click="loadPage(vault.page + 1)">次へ</button>
            </nav>
        </dialog>
    </section>
</template>

<style scoped>
.ug-polishing-preview, .ug-polishing-crystals { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 1rem; }
.ug-polishing-preview [role="button"] { cursor: pointer; }
.ug-polishing-preview [role="button"]:focus-visible { outline: 2px solid currentColor; outline-offset: 4px; }
.ug-polishing-chooser { max-width: 850px; width: calc(100% - 2rem); max-height: 85vh; overflow: auto; }
.ug-polishing-chooser header, .ug-polishing-chooser nav { display: flex; justify-content: space-between; align-items: center; gap: 1rem; }
</style>
