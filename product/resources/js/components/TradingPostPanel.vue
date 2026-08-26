<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { api } from '../api/client';
import type { TradingPostData, TradingPostListing } from '../types';
import ItemEffectInfo from './ItemEffectInfo.vue';

const props = defineProps<{ nationId: number; worldId: number }>();
const market = ref<TradingPostData | null>(null);
const busy = ref(false);
const error = ref('');
const bidAmounts = reactive<Record<number, number>>({});
const form = reactive({
    product_type: 'resource' as 'resource' | 'item',
    resource_definition_id: null as number | null,
    item_instance_id: null as number | null,
    quantity: 1,
    start_price: 100,
    duration_turns: 6,
    auto_relist: false,
});
const selectedSellableItem = computed(() => market.value?.sellable_items.find(
    (item) => item.id === form.item_instance_id,
) ?? null);

function productLabel(listing: TradingPostListing): string {
    if (listing.product.type === 'item') {
        return `${listing.product.name} Lv${listing.product.item_level}（${listing.product.rarity_label}）`;
    }

    return `${listing.product.name} ${listing.product.quantity?.toLocaleString('ja-JP')}${listing.product.unit_label ?? ''}`;
}

function bidStatusLabel(status: TradingPostListing['viewer_bid_status']): string {
    return {
        seller: '自分の出品',
        none: '未入札',
        highest: 'あなたが最高額入札中',
        outbid: '入札済み・現在は他国が最高額',
    }[status];
}

function errorMessage(cause: unknown, fallback: string): string {
    return cause instanceof Error ? cause.message : fallback;
}

async function load(): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        market.value = await api<TradingPostData>(`/api/v1/worlds/${props.worldId}/trading-post`);
        for (const listing of market.value.listings) {
            bidAmounts[listing.id] = listing.minimum_bid;
        }
        form.resource_definition_id ??= market.value.sellable_resources[0]?.id ?? null;
        form.item_instance_id ??= market.value.sellable_items[0]?.id ?? null;
    } catch (cause) {
        error.value = errorMessage(cause, '交易場を読み込めませんでした。');
    } finally {
        busy.value = false;
    }
}

async function createListing(): Promise<void> {
    if (market.value === null || !market.value.permissions.can_mutate) return;
    busy.value = true;
    error.value = '';
    try {
        await api(`/api/v1/nations/${props.nationId}/trading-post/listings`, {
            method: 'POST',
            body: JSON.stringify({
                product_type: form.product_type,
                resource_definition_id: form.product_type === 'resource' ? form.resource_definition_id : null,
                item_instance_id: form.product_type === 'item' ? form.item_instance_id : null,
                quantity: form.product_type === 'resource' ? form.quantity : null,
                start_price: form.start_price,
                duration_turns: form.duration_turns,
                auto_relist: form.auto_relist,
            }),
        });
        await load();
    } catch (cause) {
        error.value = errorMessage(cause, '商品を出品できませんでした。');
        busy.value = false;
    }
}

async function placeBid(listing: TradingPostListing): Promise<void> {
    if (!listing.can_bid) return;
    busy.value = true;
    error.value = '';
    try {
        await api(`/api/v1/nations/${props.nationId}/trading-post/listings/${listing.id}/bids`, {
            method: 'POST',
            body: JSON.stringify({ amount: bidAmounts[listing.id] }),
        });
        await load();
    } catch (cause) {
        error.value = errorMessage(cause, '入札できませんでした。');
        busy.value = false;
    }
}

async function cancelListing(listing: TradingPostListing): Promise<void> {
    if (!listing.can_cancel) return;
    busy.value = true;
    error.value = '';
    try {
        await api(`/api/v1/nations/${props.nationId}/trading-post/listings/${listing.id}`, {
            method: 'DELETE',
        });
        await load();
    } catch (cause) {
        error.value = errorMessage(cause, '出品をキャンセルできませんでした。');
        busy.value = false;
    }
}

onMounted(load);
</script>

<template>
    <section class="panel trading-post-panel">
        <header class="trading-post-heading">
            <div>
                <p class="eyebrow">TRADING POST</p>
                <h1>交易場</h1>
            </div>
            <p v-if="market" class="trading-post-balance">所持資金 {{ market.nation.money.toLocaleString('ja-JP') }}億円</p>
        </header>
        <p class="trading-post-capacity-note">
            最高入札中の預託資金は資金上限の使用量に含まれ、出品中の資源も保管容量に含まれます。
            <a href="/manual/trading-post">交易場のルール</a>
        </p>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p v-if="busy && market === null" role="status">交易場を読み込んでいます…</p>

        <template v-if="market">
            <section aria-labelledby="trading-post-active-title">
                <h2 id="trading-post-active-title">現在出品中の商品</h2>
                <div class="trading-post-table-wrap">
                    <table class="trading-post-table">
                        <thead>
                            <tr><th>商品</th><th>出品者</th><th>開始価格</th><th>現在の最高入札額</th><th>残り／終了</th><th>入札</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="listing in market.listings" :key="listing.id">
                                <td>
                                    <template v-if="listing.product.type === 'item'">
                                        {{ productLabel(listing) }}
                                        <ItemEffectInfo
                                            v-if="listing.product.effect_text"
                                            :item-name="listing.product.name"
                                            :effect-text="listing.product.effect_text"
                                        />
                                    </template>
                                    <template v-else>{{ productLabel(listing) }}</template>
                                </td>
                                <td>{{ listing.seller.name }}</td>
                                <td>{{ listing.start_price.toLocaleString('ja-JP') }}億円</td>
                                <td class="trading-post-price-cell">
                                    <span>{{ listing.current_price === null ? '入札なし' : `${listing.current_price.toLocaleString('ja-JP')}億円` }}</span>
                                    <small class="trading-post-secondary-line">最高額入札者：{{ listing.highest_bidder?.name ?? 'なし' }}</small>
                                </td>
                                <td>残り{{ listing.remaining_turns }}ターン<br><small>終了 T{{ listing.ends_turn }}</small></td>
                                <td>
                                    <div class="trading-post-bid-cell">
                                        <span class="trading-post-bid-status" :class="`trading-post-bid-status-${listing.viewer_bid_status}`">
                                            {{ bidStatusLabel(listing.viewer_bid_status) }}
                                        </span>
                                        <form v-if="listing.can_bid" class="trading-post-bid" @submit.prevent="placeBid(listing)">
                                            <input v-model.number="bidAmounts[listing.id]" type="number" :min="listing.minimum_bid" required :disabled="busy" :aria-label="`${listing.product.name}の入札額`">
                                            <span>億円</span>
                                            <button class="button primary" type="submit" :disabled="busy">入札</button>
                                        </form>
                                        <span v-else-if="!listing.is_mine">現在は入札不可</span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="market.listings.length === 0" class="empty-state">現在出品中の商品はありません。</p>
            </section>

            <div class="trading-post-lower-grid">
                <section aria-labelledby="trading-post-mine-title">
                    <h2 id="trading-post-mine-title">自分の出品</h2>
                    <p>{{ market.my_listings.length }} / {{ market.contract.active_listing_limit }}件</p>
                    <ul v-if="market.my_listings.length" class="trading-post-my-listings">
                        <li v-for="listing in market.my_listings" :key="listing.id">
                            <span class="trading-post-my-listing-details">
                                <span>
                                    <template v-if="listing.product.type === 'item'">
                                        {{ productLabel(listing) }}
                                        <ItemEffectInfo
                                            v-if="listing.product.effect_text"
                                            :item-name="listing.product.name"
                                            :effect-text="listing.product.effect_text"
                                        />
                                    </template>
                                    <template v-else>{{ productLabel(listing) }}</template>
                                    （終了 T{{ listing.ends_turn }}）
                                </span>
                                <small>現在価格：{{ listing.current_price === null ? '入札なし' : `${listing.current_price.toLocaleString('ja-JP')}億円` }}・最高額入札者：{{ listing.highest_bidder?.name ?? 'なし' }}</small>
                            </span>
                            <button v-if="listing.can_cancel" class="button secondary" type="button" :disabled="busy" @click="cancelListing(listing)">キャンセル</button>
                            <small v-else-if="listing.bid_count > 0">入札済みのためキャンセル不可</small>
                            <small v-else>現在はキャンセル不可</small>
                        </li>
                    </ul>
                    <p v-else class="empty-state">出品中の商品はありません。</p>
                </section>

                <section aria-labelledby="trading-post-new-title">
                    <h2 id="trading-post-new-title">新規出品</h2>
                    <p v-if="!market.permissions.can_mutate" class="empty-state">休眠中は新規出品できません。</p>
                    <form class="trading-post-listing-form" @submit.prevent="createListing">
                        <label>商品種別
                            <select v-model="form.product_type" :disabled="busy || !market.permissions.can_mutate">
                                <option value="resource">国家資源</option>
                                <option value="item">秘書アイテム</option>
                            </select>
                        </label>
                        <label v-if="form.product_type === 'resource'">商品
                            <select v-model.number="form.resource_definition_id" required :disabled="busy || !market.permissions.can_mutate">
                                <option v-for="resource in market.sellable_resources" :key="resource.id" :value="resource.id">
                                    {{ resource.name }}（{{ resource.amount.toLocaleString('ja-JP') }}{{ resource.unit_label ?? '' }}）
                                </option>
                            </select>
                        </label>
                        <label v-else>商品
                            <select v-model.number="form.item_instance_id" required :disabled="busy || !market.permissions.can_mutate">
                                <option v-for="item in market.sellable_items" :key="item.id" :value="item.id">
                                    {{ item.name }} Lv{{ item.level }}（{{ item.rarity_label }}）
                                </option>
                            </select>
                        </label>
                        <div v-if="form.product_type === 'item' && selectedSellableItem?.effect_text" class="trading-post-selected-item-effect">
                            <span>選択中の効果</span>
                            <ItemEffectInfo
                                :item-name="selectedSellableItem.name"
                                :effect-text="selectedSellableItem.effect_text"
                            />
                        </div>
                        <label v-if="form.product_type === 'resource'">数量
                            <input v-model.number="form.quantity" type="number" min="1" required :disabled="busy || !market.permissions.can_mutate">
                        </label>
                        <label>開始価格（億円）
                            <input v-model.number="form.start_price" type="number" min="1" required :disabled="busy || !market.permissions.can_mutate">
                        </label>
                        <label>出品期間（ターン）
                            <input v-model.number="form.duration_turns" type="number" :min="market.contract.minimum_duration_turns" :max="market.contract.maximum_duration_turns" required :disabled="busy || !market.permissions.can_mutate">
                        </label>
                        <label class="trading-post-checkbox"><input v-model="form.auto_relist" type="checkbox" :disabled="busy || !market.permissions.can_mutate"> 入札0で期限切れなら自動再出品</label>
                        <button class="button primary" type="submit" :disabled="busy || !market.permissions.can_mutate || market.my_listings.length >= market.contract.active_listing_limit">出品する</button>
                    </form>
                </section>
            </div>
        </template>
    </section>
</template>
