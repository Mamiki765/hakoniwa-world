<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import EquipmentItemCard, { type EquipmentItem } from './EquipmentItemCard.vue';

export interface MarketAttention {
    unclaimed_count: number;
    unclaimed_total: number;
    unseen_expired_count: number;
}
interface MarketListing {
    id: number;
    item: EquipmentItem;
    seller: { secretary_name: string; island_name: string | null };
    price: number;
    expires_at: string;
    is_own: boolean;
    purchasable: boolean;
    blocked_reasons: string[];
}
interface MarketResponse {
    purchase_il_cap: number;
    carried_shards: number;
    fee: { numerator: number; denominator: number };
    listings: MarketListing[];
    page: number;
    has_more: boolean;
}
type ListableItem = EquipmentItem & { market_minimum_price?: number; listable?: boolean; listable_reason?: string | null; is_listed?: boolean };
interface VaultResponse { items: ListableItem[] }
interface MineListing { id: number; item?: EquipmentItem; item_snapshot?: EquipmentItem; price: number; proceeds?: number; expires_at?: string; sold_at?: string; expired_at?: string; claimed?: boolean; seen?: boolean }
interface MineResponse {
    listing_limit: number;
    active: MineListing[];
    sold: MineListing[];
    expired: MineListing[];
    unclaimed_total: number;
}

const props = defineProps<{ attention?: MarketAttention | null }>();
const emit = defineEmits<{ balance: [carried: number]; attention: [] }>();

type Pane = 'browse' | 'sell' | 'mine';
const pane = ref<Pane>('browse');
const market = ref<MarketResponse | null>(null);
const vault = ref<ListableItem[]>([]);
const mine = ref<MineResponse | null>(null);
const loading = ref(false);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const pending = ref<{ fingerprint: string; requestId: string } | null>(null);

const category = ref<'all' | EquipmentItem['category']>('all');
const weaponStyle = ref('all');
const ilMin = ref<number | null>(null);
const qualityMin = ref<number | null>(null);
const purchasableOnly = ref(true);
const sort = ref<'newest' | 'price' | 'item_level'>('newest');

const confirmBuy = ref<MarketListing | null>(null);
const sellTarget = ref<ListableItem | null>(null);
const sellPrice = ref<number | null>(null);

const categoryLabels: Record<'all' | EquipmentItem['category'], string> = { all: 'すべて', weapon: '武器', armor: '防具', accessory: 'アクセサリー', resonance: '共鳴結晶' };
const styleLabels: Record<string, string> = { all: 'すべて', dagger: '短剣', rapier: '細身剣', longsword: '長剣', crystal_staff: '輝石杖' };
const reasonLabels: Record<string, string> = {
    il_cap_exceeded: 'IL上限を超えています',
    insufficient_carried_shards: '手持ちのGが足りません',
    vault_full: '保管庫がいっぱいです',
    resonance_full: '共鳴結晶の枠がいっぱいです',
    own_listing: '自分の出品です',
};
const reason = (code: string): string => reasonLabels[code] ?? code;
const yen = (value: number): string => `${value.toLocaleString('ja-JP')}G`;

const mineAttention = computed(() => (props.attention?.unclaimed_count ?? 0) + (props.attention?.unseen_expired_count ?? 0) > 0);
const fee = computed(() => market.value?.fee ?? { numerator: 1, denominator: 10 });
function proceedsOf(price: number): number {
    return price - Math.ceil(price * fee.value.numerator / fee.value.denominator);
}
const sellProceeds = computed(() => (sellPrice.value && sellPrice.value > 0 ? proceedsOf(sellPrice.value) : 0));
const sellPriceValid = computed(() => !!sellTarget.value && !!sellPrice.value && Number.isInteger(sellPrice.value)
    && sellPrice.value >= (sellTarget.value.market_minimum_price ?? 1));

function daysLeft(iso: string | undefined): string {
    if (!iso) return '';
    const hours = Math.max(0, Math.round((Date.parse(iso) - Date.now()) / 3_600_000));
    return hours >= 24 ? `残り${Math.floor(hours / 24)}日` : `残り${hours}時間`;
}
function requestId(): string { return crypto.randomUUID(); }
function action(fingerprint: string): { fingerprint: string; requestId: string } {
    const current = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: requestId() };
    pending.value = current;
    return current;
}
function message(caught: unknown, fallback: string): string {
    if (caught instanceof ApiError && caught.code === 'listing_unavailable') return 'ほかの人が先に買ったか、出品が取り下げられました。';
    if (caught instanceof ApiError && caught.code === 'price_changed') return '価格が変わっていました。一覧を更新したので、もう一度確認してください。';
    return caught instanceof Error ? caught.message : fallback;
}

async function loadMarket(): Promise<void> {
    const query = new URLSearchParams({ sort: sort.value, page: '1' });
    if (purchasableOnly.value) query.set('purchasable_only', '1');
    if (category.value !== 'all') query.set('category', category.value);
    if (category.value === 'weapon' && weaponStyle.value !== 'all') query.set('weapon_style', weaponStyle.value);
    if (ilMin.value) query.set('il_min', String(ilMin.value));
    if (qualityMin.value) query.set('quality_min', String(qualityMin.value));
    market.value = await api<MarketResponse>(`/api/v1/me/underground/market?${query}`);
    emit('balance', market.value.carried_shards);
}
async function loadVault(): Promise<void> {
    vault.value = (await api<VaultResponse>('/api/v1/me/underground/market/listable')).items;
}
async function loadMine(): Promise<void> {
    mine.value = await api<MineResponse>('/api/v1/me/underground/market/mine');
    const unseen = mine.value.expired.filter(listing => listing.seen === false).map(listing => listing.id);
    if (unseen.length > 0) {
        await api('/api/v1/me/underground/market/expired/seen', { method: 'POST', body: JSON.stringify({ listing_ids: unseen }) });
        emit('attention');
    }
}
async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        if (pane.value === 'browse') await loadMarket();
        else if (pane.value === 'sell') await Promise.all([loadVault(), market.value ? Promise.resolve() : loadMarket()]);
        else await loadMine();
    } catch (caught) {
        error.value = message(caught, '取引の情報を読み込めませんでした。');
    } finally {
        loading.value = false;
    }
}
function show(next: Pane): void {
    pane.value = next;
    notice.value = '';
    void load();
}

async function buy(): Promise<void> {
    const listing = confirmBuy.value;
    if (!listing || busy.value) return;
    const current = action(`buy:${listing.id}:${listing.price}`);
    busy.value = true;
    error.value = '';
    try {
        const result = await api<{ carried_shards: number }>(`/api/v1/me/underground/market/listings/${listing.id}/purchase`, {
            method: 'POST', body: JSON.stringify({ request_id: current.requestId, expected_price: listing.price }),
        });
        pending.value = null;
        confirmBuy.value = null;
        notice.value = `${listing.item.name}を買いました。保管庫に入っています。`;
        emit('balance', result.carried_shards);
        await loadMarket();
    } catch (caught) {
        error.value = message(caught, '購入できませんでした。');
        if (caught instanceof ApiError && caught.code !== 'request_conflict') { pending.value = null; confirmBuy.value = null; await loadMarket(); }
    } finally {
        busy.value = false;
    }
}

function startSell(item: ListableItem): void {
    sellTarget.value = item;
    sellPrice.value = item.market_minimum_price ?? null;
}
async function list(): Promise<void> {
    const item = sellTarget.value;
    if (!item || item.id === undefined || !sellPriceValid.value || busy.value) return;
    const current = action(`list:${item.id}:${sellPrice.value}`);
    busy.value = true;
    error.value = '';
    try {
        await api('/api/v1/me/underground/market/listings', {
            method: 'POST', body: JSON.stringify({ request_id: current.requestId, equipment_id: item.id, price: sellPrice.value }),
        });
        pending.value = null;
        notice.value = `${item.name}を${yen(sellPrice.value ?? 0)}で出品しました。`;
        sellTarget.value = null;
        await loadVault();
    } catch (caught) {
        error.value = message(caught, '出品できませんでした。');
        if (caught instanceof ApiError && caught.code !== 'request_conflict') pending.value = null;
    } finally {
        busy.value = false;
    }
}
async function cancel(listing: MineListing): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    try {
        await api(`/api/v1/me/underground/market/listings/${listing.id}`, { method: 'DELETE' });
        notice.value = '出品を取り下げました。品物は保管庫に戻っています。';
        await loadMine();
    } catch (caught) {
        error.value = message(caught, '取り下げできませんでした。');
    } finally {
        busy.value = false;
    }
}
async function claim(listingIds: number[] | null): Promise<void> {
    if (busy.value) return;
    const current = action(`claim:${listingIds?.join(',') ?? 'all'}`);
    busy.value = true;
    error.value = '';
    try {
        const result = await api<{ claimed: number; carried_shards: number }>('/api/v1/me/underground/market/proceeds/claim', {
            method: 'POST', body: JSON.stringify({ request_id: current.requestId, listing_ids: listingIds }),
        });
        pending.value = null;
        notice.value = `代金 ${yen(result.claimed)} を受け取りました。`;
        emit('balance', result.carried_shards);
        emit('attention');
        await loadMine();
    } catch (caught) {
        error.value = message(caught, '代金を受け取れませんでした。');
    } finally {
        busy.value = false;
    }
}

onMounted(() => { void load(); });
</script>

<template>
    <section class="underground-market" aria-labelledby="underground-market-title">
        <header class="underground-equipment-screen-heading">
            <div>
                <p class="eyebrow">Exchange Stalls</p>
                <h1 id="underground-market-title">露店</h1>
                <p>掘り当てた装備や結晶を、ほかの秘書と直接売り買いできます。売れると売値の90%が届きます。</p>
            </div>
            <dl class="underground-equipment-balances">
                <div><dt>手持ち</dt><dd>{{ market ? yen(market.carried_shards) : '—' }}</dd></div>
                <div><dt>買えるIL</dt><dd>{{ market ? market.purchase_il_cap : '—' }} まで</dd></div>
            </dl>
        </header>

        <nav class="underground-equipment-tabs" aria-label="露店の切り替え">
            <button type="button" :aria-pressed="pane === 'browse'" @click="show('browse')">探す</button>
            <button type="button" :aria-pressed="pane === 'sell'" @click="show('sell')">出品する</button>
            <button type="button" class="notification-anchor" :aria-pressed="pane === 'mine'" :aria-label="mineAttention ? '自分の出品（お知らせがあります）' : undefined" @click="show('mine')">自分の出品<span v-if="mineAttention" class="notification-dot" aria-hidden="true" /></button>
        </nav>

        <p v-if="loading" class="status" role="status">読み込んでいます…</p>
        <p v-if="notice" class="status" role="status">{{ notice }}</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="pane === 'browse' && market">
            <form class="underground-market-filters" @submit.prevent="loadMarket">
                <label>種類
                    <select v-model="category"><option v-for="(label, key) in categoryLabels" :key="key" :value="key">{{ label }}</option></select>
                </label>
                <label v-if="category === 'weapon'">武器種
                    <select v-model="weaponStyle"><option v-for="(label, key) in styleLabels" :key="key" :value="key">{{ label }}</option></select>
                </label>
                <label>IL <input v-model.number="ilMin" type="number" min="0" inputmode="numeric" placeholder="下限"> 以上</label>
                <label>品質 <input v-model.number="qualityMin" type="number" min="0" max="100" inputmode="numeric" placeholder="下限"> %以上</label>
                <label>並び
                    <select v-model="sort"><option value="newest">新しい順</option><option value="price">安い順</option><option value="item_level">ILの高い順</option></select>
                </label>
                <label class="underground-market-check"><input v-model="purchasableOnly" type="checkbox"> 買える物だけ</label>
                <button type="submit" :disabled="loading">この条件で探す</button>
            </form>
            <p class="underground-market-cap">あなたが買えるのは <strong>IL {{ market.purchase_il_cap }}</strong> までです（装備できる所持品の最高ILまで）。</p>
            <p v-if="market.listings.length === 0" class="status">条件に合う出品はありません。</p>
            <div class="underground-equipment-card-grid">
                <EquipmentItemCard
                    v-for="listing in market.listings"
                    :key="listing.id"
                    :item="listing.item"
                    :class="{ 'underground-market-over-cap': listing.blocked_reasons.includes('il_cap_exceeded') }"
                    :disabled="busy || listing.is_own || !listing.purchasable || listing.blocked_reasons.length > 0"
                    @action="confirmBuy = listing"
                >
                    <template #status><span>{{ listing.seller.secretary_name }}<template v-if="listing.seller.island_name">（{{ listing.seller.island_name }}）</template>・{{ daysLeft(listing.expires_at) }}</span></template>
                    <template #price>
                        <span class="underground-market-price">{{ yen(listing.price) }}</span>
                        <span v-for="code in listing.blocked_reasons" :key="code" class="underground-equipment-unaffordable">{{ reason(code) }}</span>
                    </template>
                    <template #action>{{ listing.is_own ? '自分の出品' : listing.blocked_reasons.length ? '買えません' : '買う' }}</template>
                </EquipmentItemCard>
            </div>
        </template>

        <template v-if="pane === 'sell'">
            <p class="underground-market-cap">出品は{{ mine?.listing_limit ?? 5 }}件まで、期限は7日です。売れると手数料10%を引いた額が届きます。</p>
            <p v-if="!loading && vault.length === 0" class="status">出品できる装備がありません。</p>
            <div class="underground-equipment-card-grid">
                <EquipmentItemCard v-for="item in vault" :key="item.id" :item="item" mode="owned" :disabled="busy || item.listable === false" @action="startSell(item)">
                    <template #status>
                        <span v-if="item.is_listed">出品中</span>
                        <span v-else-if="item.listable === false">{{ item.listable_reason ?? '出品できません' }}</span>
                        <span v-else-if="item.is_protected">★ 保護中（そのまま出品できます）</span>
                    </template>
                    <template #price><span>NPC売値 {{ yen(item.sell_price) }}</span></template>
                    <template #action>{{ item.is_listed ? '出品中' : item.listable === false ? '出品できません' : '出品する' }}</template>
                </EquipmentItemCard>
            </div>
        </template>

        <template v-if="pane === 'mine' && mine">
            <section v-if="mine.sold.length" class="underground-market-section" aria-labelledby="market-sold-title">
                <header>
                    <h2 id="market-sold-title">売れました</h2>
                    <button v-if="mine.unclaimed_total > 0" type="button" class="ug-primary" :disabled="busy" @click="claim(null)">代金をまとめて受け取る（{{ yen(mine.unclaimed_total) }}）</button>
                </header>
                <ul class="underground-market-rows">
                    <li v-for="listing in mine.sold" :key="listing.id">
                        <span>{{ listing.item_snapshot?.name }} IL{{ listing.item_snapshot?.item_level }}</span>
                        <span>{{ yen(listing.price) }} → 受取 {{ yen(listing.proceeds ?? 0) }}</span>
                        <button v-if="!listing.claimed" type="button" :disabled="busy" @click="claim([listing.id])">受け取る</button>
                        <span v-else class="underground-market-done">受取済み</span>
                    </li>
                </ul>
            </section>
            <section class="underground-market-section" aria-labelledby="market-active-title">
                <header><h2 id="market-active-title">出品中（{{ mine.active.length }} / {{ mine.listing_limit }}）</h2></header>
                <p v-if="mine.active.length === 0" class="status">出品中の品物はありません。</p>
                <ul class="underground-market-rows">
                    <li v-for="listing in mine.active" :key="listing.id">
                        <span>{{ listing.item?.name }} IL{{ listing.item?.item_level }}</span>
                        <span>{{ yen(listing.price) }}・{{ daysLeft(listing.expires_at) }}</span>
                        <button type="button" :disabled="busy" @click="cancel(listing)">取り下げる</button>
                    </li>
                </ul>
            </section>
            <section v-if="mine.expired.length" class="underground-market-section" aria-labelledby="market-expired-title">
                <header><h2 id="market-expired-title">期限切れ</h2></header>
                <p>売れ残った品物は保管庫に戻っています。</p>
                <ul class="underground-market-rows">
                    <li v-for="listing in mine.expired" :key="listing.id">
                        <span>{{ listing.item_snapshot?.name }} IL{{ listing.item_snapshot?.item_level }}</span>
                        <span>{{ yen(listing.price) }}</span>
                        <span v-if="listing.seen === false" class="underground-market-new">新着</span>
                    </li>
                </ul>
            </section>
        </template>

        <div v-if="confirmBuy" class="modal-backdrop underground-confirm-backdrop" @click.self="!busy && (confirmBuy = null)">
            <section class="underground-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="market-buy-title">
                <header><h2 id="market-buy-title">{{ confirmBuy.item.name }}を買いますか？</h2></header>
                <dl class="underground-market-summary">
                    <div><dt>価格</dt><dd>{{ yen(confirmBuy.price) }}</dd></div>
                    <div><dt>買った後の手持ち</dt><dd>{{ yen((market?.carried_shards ?? 0) - confirmBuy.price) }}</dd></div>
                    <div><dt>売り手</dt><dd>{{ confirmBuy.seller.secretary_name }}</dd></div>
                </dl>
                <footer>
                    <button class="button secondary" type="button" :disabled="busy" @click="confirmBuy = null">やめる</button>
                    <button class="button primary" type="button" :disabled="busy" @click="buy">{{ busy ? '処理中…' : '買う' }}</button>
                </footer>
            </section>
        </div>

        <div v-if="sellTarget" class="modal-backdrop underground-confirm-backdrop" @click.self="!busy && (sellTarget = null)">
            <form class="underground-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="market-sell-title" @submit.prevent="list">
                <header><h2 id="market-sell-title">{{ sellTarget.name }}を出品する</h2></header>
                <label class="underground-market-price-input">売値 <input v-model.number="sellPrice" type="number" :min="sellTarget.market_minimum_price ?? 1" inputmode="numeric" required> G</label>
                <dl class="underground-market-summary">
                    <div><dt>受け取れる額</dt><dd>{{ yen(sellProceeds) }}（手数料 {{ yen((sellPrice ?? 0) - sellProceeds) }}）</dd></div>
                    <div><dt>最低価格（NPC売値）</dt><dd>{{ yen(sellTarget.market_minimum_price ?? sellTarget.sell_price) }}</dd></div>
                </dl>
                <p v-if="sellPrice !== null && !sellPriceValid" class="status error">NPC売値以上の整数を入れてください。</p>
                <footer>
                    <button class="button secondary" type="button" :disabled="busy" @click="sellTarget = null">やめる</button>
                    <button class="button primary" type="submit" :disabled="busy || !sellPriceValid">{{ busy ? '処理中…' : '出品する' }}</button>
                </footer>
            </form>
        </div>
    </section>
</template>
