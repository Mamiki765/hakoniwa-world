<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import EquipmentConfirmDialog from './EquipmentConfirmDialog.vue';
import EquipmentItemCard, { type EquipmentItem, type EquipmentSlot } from './EquipmentItemCard.vue';

interface ShopResponse {
    catalog_identity: string;
    currency_label: string;
    shard_balance: number;
    banked_shard_balance: number;
    bank_auto_withdraw: false;
    items: EquipmentItem[];
    owned_items: EquipmentItem[];
}
interface MutationResponse {
    shard_balance: number;
    banked_shard_balance: number;
    vault: { used: number; capacity: number; equipped: Record<EquipmentSlot, EquipmentItem | null> };
}

const emit = defineEmits<{ updated: [value: MutationResponse] }>();
const shop = ref<ShopResponse | null>(null);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const category = ref<EquipmentItem['category']>('weapon');
const weaponStyle = ref('all');
const confirmItem = ref<EquipmentItem | null>(null);
const pending = ref<{ fingerprint: string; requestId: string } | null>(null);

const categoryLabels: Record<EquipmentItem['category'], string> = { weapon: '武器', armor: '防具', accessory: 'アクセサリー' };
const styleLabels: Record<string, string> = { dagger: '短剣', rapier: '細身剣', longsword: '長剣', crystal_staff: '輝石杖' };
const slotLabels: Record<EquipmentSlot, string> = {
    weapon: '武器',
    armor: '防具',
    accessory_1: 'アクセサリー1',
    accessory_2: 'アクセサリー2',
    accessory_3: 'アクセサリー3',
};
function slotLabel(slot: EquipmentSlot | null | undefined): string {
    return slot ? slotLabels[slot] : 'アクセサリー';
}
const visibleItems = computed(() => (shop.value?.items ?? []).filter((item) => {
    if (item.category !== category.value) return false;
    return category.value !== 'weapon' || weaponStyle.value === 'all' || item.weapon_style === weaponStyle.value;
}));
const weaponStyles = computed(() => [...new Set((shop.value?.items ?? []).filter((item) => item.category === 'weapon').map((item) => item.weapon_style).filter((style): style is string => Boolean(style)))]);
const affordable = (item: EquipmentItem): boolean => !item.locked
    && (shop.value?.shard_balance ?? 0) >= (item.buy_price ?? Number.MAX_SAFE_INTEGER);

function requestId(): string { return crypto.randomUUID(); }

async function loadShop(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        shop.value = await api<ShopResponse>('/api/v1/me/underground/equipment/shop');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '装備ショップを読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function mutationError(caught: unknown): string {
    if (caught instanceof ApiError && caught.code === 'underground_request_conflict') {
        return `request IDの競合です。この操作は確定せず、同じIDを再利用できません。${caught.message}`;
    }
    return caught instanceof Error ? caught.message : '装備の処理に失敗しました。再試行してください。';
}

async function purchase(item: EquipmentItem): Promise<void> {
    if (busy.value || item.owned || item.locked || !affordable(item) || item.buy_price === null || item.buy_price === undefined) return;
    const fingerprint = `purchase:${item.key}`;
    const action = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: requestId() };
    pending.value = action;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<MutationResponse>('/api/v1/me/underground/equipment/shop/purchase', { method: 'POST', body: JSON.stringify({ request_id: action.requestId, definition_key: item.key }) });
        emit('updated', result);
        pending.value = null;
        await loadShop();
    } catch (caught) {
        error.value = mutationError(caught);
    } finally {
        busy.value = false;
    }
}

function askSell(item: EquipmentItem): void {
    if (busy.value || item.id === undefined || item.equipped_slot !== null || item.sell_price <= 0) return;
    confirmItem.value = item;
}

async function sell(): Promise<void> {
    const item = confirmItem.value;
    if (!item || busy.value) return;
    const fingerprint = `sell:${item.id}`;
    const action = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: requestId() };
    pending.value = action;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<MutationResponse>(`/api/v1/me/underground/equipment/items/${item.id}/sell`, { method: 'POST', body: JSON.stringify({ request_id: action.requestId }) });
        emit('updated', result);
        pending.value = null;
        confirmItem.value = null;
        await loadShop();
    } catch (caught) {
        error.value = mutationError(caught);
    } finally {
        busy.value = false;
    }
}

onMounted(() => { void loadShop(); });
</script>

<template>
    <section class="underground-equipment-screen" aria-labelledby="underground-equipment-shop-title">
        <header class="underground-equipment-screen-heading">
            <div><p class="eyebrow">Underground Equipment</p><h1 id="underground-equipment-shop-title">装備ショップ</h1><p>購入に使えるのは手持ちの輝石の欠片だけです。Gはgramを表し、銀行自動引き出しは<strong>{{ shop?.bank_auto_withdraw ? 'あり' : 'なし' }}</strong>です。</p></div>
            <dl class="underground-equipment-balances"><div><dt>手持ち</dt><dd>{{ shop?.shard_balance ?? '—' }}G</dd></div><div><dt>銀行</dt><dd>{{ shop?.banked_shard_balance ?? '—' }}G</dd></div></dl>
        </header>
        <p v-if="loading" class="status" role="status">商品を読み込んでいます…</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>
        <template v-if="shop">
            <nav class="underground-equipment-tabs" aria-label="装備カテゴリ">
                <button v-for="label in (Object.keys(categoryLabels) as EquipmentItem['category'][])" :key="label" type="button" :aria-pressed="category === label" @click="category = label">{{ categoryLabels[label] }}</button>
            </nav>
            <div v-if="category === 'weapon'" class="underground-equipment-style-tabs" aria-label="武器スタイル">
                <button type="button" :aria-pressed="weaponStyle === 'all'" @click="weaponStyle = 'all'">すべて</button>
                <button v-for="style in weaponStyles" :key="style" type="button" :aria-pressed="weaponStyle === style" @click="weaponStyle = style">{{ styleLabels[style] ?? style }}</button>
            </div>
            <p v-if="visibleItems.length === 0" class="status">このカテゴリの商品はありません。</p>
            <div class="underground-equipment-card-grid">
                <EquipmentItemCard v-for="item in visibleItems" :key="item.key" :item="item" :disabled="busy || item.owned || item.locked || !affordable(item)" @action="purchase(item)">
                    <template #price>
                        <span v-if="item.buy_price !== null && item.buy_price !== undefined">{{ item.buy_price.toLocaleString('ja-JP') }}G</span>
                        <span v-if="item.locked" class="underground-equipment-locked">🔒 {{ item.unlock_requirement ?? '解禁条件あり' }}</span>
                        <span v-else-if="item.owned" class="underground-equipment-owned">所有済み</span>
                        <span v-else-if="!affordable(item)" class="underground-equipment-unaffordable">手持ち不足</span>
                    </template>
                    <template #action>{{ item.locked ? 'ロック中' : item.owned ? '購入済み' : '購入する' }}</template>
                </EquipmentItemCard>
            </div>
            <section class="underground-equipment-owned-list" aria-labelledby="underground-equipment-owned-title">
                <header><div><p class="eyebrow">Owned Items</p><h2 id="underground-equipment-owned-title">売却リスト</h2></div><p>装備中のアイテムは外してから売却できます。</p></header>
                <p v-if="shop.owned_items.length === 0" class="status">所有アイテムはありません。</p>
                <div class="underground-equipment-card-grid">
                    <EquipmentItemCard v-for="item in shop.owned_items" :key="item.id" :item="item" mode="owned" :disabled="busy || item.id === undefined || item.equipped_slot !== null || item.sell_price <= 0" @action="askSell(item)">
                        <template #status><span v-if="item.equipped_slot" class="underground-equipment-equipped">{{ slotLabel(item.equipped_slot) }}として装備中</span><span v-else>未装備</span></template>
                        <template #price><span>購入価格 {{ item.buy_price === null || item.buy_price === undefined ? '非売品' : `${item.buy_price.toLocaleString('ja-JP')}G` }}</span><span>売却価格 {{ item.sell_price.toLocaleString('ja-JP') }}G</span></template>
                        <template #action>{{ item.equipped_slot ? '装備中' : item.sell_price <= 0 ? '売却不可' : '売却する' }}</template>
                    </EquipmentItemCard>
                </div>
            </section>
        </template>
        <EquipmentConfirmDialog v-if="confirmItem" :item="confirmItem" title="装備を売却しますか？" message="売却するとアイテムは宝物庫からなくなり、手持ち残高へ戻ります。" :submitting="busy" @cancel="confirmItem = null" @confirm="sell" />
    </section>
</template>
