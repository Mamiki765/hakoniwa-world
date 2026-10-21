<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { api } from '../api/client';

interface HiddenMonster { registered: false; hint: string }
interface MonsterEntry {
    registered: true;
    key: string;
    name: string;
    hp: string;
    experience: string;
    appearance: string;
    note: string;
    first_turn: number | null;
    claimed: boolean;
}
interface ItemRarity { category: string; category_label: string; rarity: string; rarity_label: string }
interface HiddenItem extends ItemRarity { registered: false }
interface ItemEntry extends ItemRarity {
    registered: true;
    key: string;
    name: string;
    max_level: number;
    synthesis_level_cap: number | null;
    effect_text: string | null;
    flavor_text: string | null;
    fixed_sale_price_money: number;
    tradable: boolean;
    registered_at: string | null;
    claimed: boolean;
}
interface CollectionResponse {
    summary: { registered: number; total: number; unclaimed: number; next_bonus_remaining: number | null; entry_paradox: number; bonus_paradox: number };
    monsters: Array<HiddenMonster | MonsterEntry>;
    items: Array<HiddenItem | ItemEntry>;
    bonuses: Array<{ milestone: number; reached: boolean; claimed: boolean }>;
    paradox_balance: number;
}
type Selection = { kind: 'monster'; entry: MonsterEntry } | { kind: 'item'; entry: ItemEntry } | { kind: 'hint'; hint: string };

const emit = defineEmits<{ changed: [] }>();
const data = ref<CollectionResponse | null>(null);
const pane = ref<'monster' | 'item'>('monster');
const selectedKey = ref<string | null>(null);
const loading = ref(true);
const busy = ref(false);
const error = ref('');
const notice = ref('');

const entries = computed(() => pane.value === 'monster' ? data.value?.monsters ?? [] : data.value?.items ?? []);
const pendingIn = (list: Array<{ registered: boolean; claimed?: boolean }>): boolean => list.some(entry => entry.registered && !entry.claimed);
const monsterPending = computed(() => pendingIn(data.value?.monsters ?? []));
const itemPending = computed(() => pendingIn(data.value?.items ?? []));
const cellKey = (entry: { registered: boolean; key?: string }, index: number): string => entry.registered ? entry.key! : `hidden-${index}`;
const selected = computed<Selection | null>(() => {
    // An undiscovered monster opens only its appearance hint; its name stays hidden.
    const hidden = data.value?.monsters.find((entry, index) => pane.value === 'monster' && !entry.registered && cellKey(entry, index) === selectedKey.value);
    if (hidden && !hidden.registered) return { kind: 'hint', hint: hidden.hint };
    const entry = entries.value.find(candidate => candidate.registered && candidate.key === selectedKey.value);
    if (!entry?.registered) return null;
    return pane.value === 'monster' ? { kind: 'monster', entry: entry as MonsterEntry } : { kind: 'item', entry: entry as ItemEntry };
});

async function load(): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        data.value = await api<CollectionResponse>('/api/v1/me/secretary/collection');
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '図鑑を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

async function receive(path: string, body?: Record<string, string>): Promise<void> {
    if (busy.value || !data.value) return;
    busy.value = true;
    error.value = '';
    notice.value = '';
    const before = data.value.paradox_balance;
    try {
        data.value = await api<CollectionResponse>(path, { method: 'POST', body: body ? JSON.stringify(body) : undefined });
        const gained = data.value.paradox_balance - before;
        if (gained > 0) notice.value = `${gained.toLocaleString('ja-JP')}Pdを受け取りました。`;
        emit('changed');
    } catch (caught) {
        const message = caught instanceof Error ? caught.message : '受け取れませんでした。';
        await load();
        error.value = message;
    } finally {
        busy.value = false;
    }
}

async function choose(key: string | undefined): Promise<void> {
    selectedKey.value = key === undefined || selectedKey.value === key ? null : key;
    if (selectedKey.value === null) return;
    // On a phone the detail sits below the whole grid, so bring it into view.
    await nextTick();
    document.getElementById('collection-detail')?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' });
}

function switchPane(next: 'monster' | 'item'): void {
    pane.value = next;
    selectedKey.value = null;
}

function formatDate(value: string | null): string {
    return value ? new Date(value).toLocaleDateString('ja-JP') : '—';
}

onMounted(() => { void load(); });
</script>

<template>
    <div class="secretary-collection">
        <header class="secretary-synthesis-heading">
            <h3 class="secretary-section-title">図鑑</h3>
            <p v-if="data">登録 {{ data.summary.registered }} / {{ data.summary.total }}</p>
        </header>
        <p v-if="loading && !data" class="status" role="status">読み込んでいます…</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>
        <p v-if="notice" class="status" role="status">{{ notice }}</p>

        <template v-if="data">
            <div class="secretary-collection-summary">
                <p>
                    受け取れるプレゼント <strong>{{ data.summary.unclaimed }}件</strong>
                    <template v-if="data.summary.next_bonus_remaining !== null">・次のボーナスまであと{{ data.summary.next_bonus_remaining }}個</template>
                </p>
                <button type="button" class="button primary" :disabled="busy || data.summary.unclaimed === 0" @click="receive('/api/v1/me/secretary/collection/claim-all')">まとめて受け取る</button>
            </div>

            <nav class="secretary-synthesis-tabs" aria-label="図鑑の切り替え">
                <button type="button" class="notification-anchor" :aria-pressed="pane === 'monster'" @click="switchPane('monster')">怪獣<span v-if="monsterPending" class="notification-dot" aria-hidden="true" /></button>
                <button type="button" class="notification-anchor" :aria-pressed="pane === 'item'" @click="switchPane('item')">地上アイテム<span v-if="itemPending" class="notification-dot" aria-hidden="true" /></button>
            </nav>

            <ul class="secretary-collection-grid">
                <li v-for="(entry, index) in entries" :key="cellKey(entry, index)">
                    <button
                        type="button"
                        class="secretary-collection-cell"
                        :class="['rarity' in entry ? `rarity-${entry.rarity}` : 'rarity-monster', { hidden: !entry.registered, hinted: 'hint' in entry, selected: cellKey(entry, index) === selectedKey }]"
                        :disabled="!entry.registered && !('hint' in entry)"
                        :aria-pressed="entry.registered || 'hint' in entry ? cellKey(entry, index) === selectedKey : undefined"
                        @click="choose(entry.registered || 'hint' in entry ? cellKey(entry, index) : undefined)"
                    >
                        <strong>{{ entry.registered ? entry.name : '？？？' }}</strong>
                        <span>{{ 'category_label' in entry ? entry.category_label : '怪獣' }}</span>
                        <span v-if="entry.registered && !entry.claimed" class="secretary-collection-badge">{{ data.summary.entry_paradox }}Pd</span>
                    </button>
                </li>
            </ul>

            <article v-if="selected?.kind === 'hint'" id="collection-detail" class="secretary-collection-detail rarity-monster">
                <header>
                    <strong>？？？</strong>
                    <span>怪獣・未登録</span>
                </header>
                <dl>
                    <div><dt>出現</dt><dd>{{ selected.hint }}</dd></div>
                </dl>
                <p>自分の島の攻撃でHPを減らすと登録されます。</p>
            </article>
            <article v-else-if="selected" id="collection-detail" class="secretary-collection-detail" :class="selected.kind === 'item' ? `rarity-${selected.entry.rarity}` : 'rarity-monster'">
                <header>
                    <strong>{{ selected.entry.name }}</strong>
                    <span>{{ selected.kind === 'item' ? `${selected.entry.rarity_label}・${selected.entry.category_label}` : '怪獣' }}</span>
                </header>
                <dl v-if="selected.kind === 'item'">
                    <div><dt>最大Lv</dt><dd>{{ selected.entry.max_level.toLocaleString('ja-JP') }}<template v-if="selected.entry.synthesis_level_cap">（合成で{{ selected.entry.synthesis_level_cap.toLocaleString('ja-JP') }}まで）</template></dd></div>
                    <div><dt>売値</dt><dd>{{ selected.entry.fixed_sale_price_money.toLocaleString('ja-JP') }}億円</dd></div>
                    <div><dt>交易場</dt><dd>{{ selected.entry.tradable ? '出品できる' : '出品できない' }}</dd></div>
                    <div><dt>初めて手に入れた日</dt><dd>{{ formatDate(selected.entry.registered_at) }}</dd></div>
                </dl>
                <dl v-else>
                    <div><dt>体力</dt><dd>{{ selected.entry.hp }}</dd></div>
                    <div><dt>経験値</dt><dd>{{ selected.entry.experience }}</dd></div>
                    <div><dt>出現</dt><dd>{{ selected.entry.appearance }}</dd></div>
                    <div><dt>初めてHPを減らしたターン</dt><dd>{{ selected.entry.first_turn === null ? '—' : `ターン${selected.entry.first_turn}` }}</dd></div>
                </dl>
                <p v-if="selected.kind === 'item' && selected.entry.effect_text" class="item-effect">効果（最大Lv）: {{ selected.entry.effect_text }}</p>
                <p v-if="selected.kind === 'item' && selected.entry.flavor_text" class="item-flavor">{{ selected.entry.flavor_text }}</p>
                <p v-if="selected.kind === 'monster' && selected.entry.note">{{ selected.entry.note }}</p>
                <footer>
                    <button
                        v-if="!selected.entry.claimed" type="button" class="button primary" :disabled="busy"
                        @click="receive('/api/v1/me/secretary/collection/claim', { kind: selected.kind, key: selected.entry.key })"
                    >
                        プレゼント {{ data.summary.entry_paradox }}Pd
                    </button>
                    <span v-else class="secretary-recipe-crafted">✓ 受け取り済み</span>
                </footer>
            </article>
            <p v-else class="empty-state">登録したマスを押すと、くわしい中身が見られます。<template v-if="pane === 'monster'">未登録の怪獣は出現の目安が見られます。</template></p>

            <section class="secretary-synthesis-group" aria-labelledby="collection-bonus-title">
                <h4 id="collection-bonus-title">登録ボーナス（怪獣とアイテムを合わせて{{ data.bonuses[0]?.milestone ?? 10 }}個ごと）</h4>
                <ul class="secretary-recipe-stack-list">
                    <li v-for="bonus in data.bonuses" :key="bonus.milestone">
                        <strong>{{ bonus.milestone }}個</strong>
                        <span>{{ data.summary.bonus_paradox }}Pd</span>
                        <span v-if="bonus.claimed" class="secretary-recipe-crafted">✓ 受け取り済み</span>
                        <button
                            v-else-if="bonus.reached" type="button" class="button primary" :disabled="busy"
                            @click="receive('/api/v1/me/secretary/collection/claim', { kind: 'bonus', key: String(bonus.milestone) })"
                        >
                            プレゼント {{ data.summary.bonus_paradox }}Pd
                        </button>
                        <span v-else>あと{{ bonus.milestone - data.summary.registered }}個</span>
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
