<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '../api/client';
import type { ResidenceItemKey, ResidenceState } from './undergroundScenes';
import stories from '../../stories/lounge.json';

const props = defineProps<{ residence: ResidenceState; mode: 'property' | 'villa' | 'trophies'; busy: boolean; shards: number }>();
defineEmits<{ purchase: [item: ResidenceItemKey]; property: [] }>();
const confirmation = ref<ResidenceItemKey | null>(null);
const itemOwned = (key: ResidenceItemKey) => props.residence[`${key}_owned`];
interface JournalMetric {
    value: number | null; known_battles: number; unknown_battles: number;
}
interface Journal {
    cleared_trials: Array<{ key: string; name: string }>;
    trophies: Array<{ key: string; name: string; achievement: string; achieved_at: string | null }>;
    battle_count: number; victory_count: number;
    damage_dealt: number | null; damage_received: number | null;
    damage_dealt_unknown_battles: number; damage_received_unknown_battles: number;
    skip_tickets_used: number; guide_punch_count: number;
    maximum_hit: JournalMetric & { action_name: string | null };
    favorite_skills: { entries: Array<{ key: string; name: string | null; count: number }>; known_battles: number; unknown_battles: number };
    combat_support: Record<'self' | 'party', Record<'effective_healing' | 'damage_prevented' | 'revivals', JournalMetric>>;
    content_clears: Array<{ type: string; key: string; name: string | null; actual_clear_count: number | null; skip_clear_count: number | null }>;
    lending_participation_count: number;
}
const supportMetrics = [
    { key: 'effective_healing', label: '実回復HP', unit: 'HP' },
    { key: 'damage_prevented', label: '防いだダメージ', unit: '' },
    { key: 'revivals', label: '蘇生した回数', unit: '回' },
] as const;
const journal = ref<Journal | null>(null);
const journalError = ref('');
const loading = ref(false);
const number = (value: number | null) => value === null ? '記録なし' : value.toLocaleString('ja-JP');
const achievedAt = (value: string) => new Date(value).toLocaleString('ja-JP', {
    timeZone: 'Asia/Tokyo', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false,
});
watch(() => [props.mode, props.residence.villa_owned, props.residence.trophy_shelf_owned, props.residence.mirror_owned] as const, async ([mode, owned, shelfOwned], _, onCleanup) => {
    let cancelled = false;
    onCleanup(() => { cancelled = true; });
    confirmation.value = null;
    if (mode === 'property' || !owned || mode === 'trophies' && !shelfOwned) return;
    loading.value = true;
    journalError.value = '';
    try {
        const result = await api<Journal>('/api/v1/me/underground/journal');
        if (!cancelled) journal.value = result;
    } catch (caught) {
        if (!cancelled) journalError.value = caught instanceof Error ? caught.message : '日誌を開けませんでした。';
    } finally { if (!cancelled) loading.value = false; }
}, { immediate: true });
</script>

<template>
    <section v-if="mode === 'property'" class="ug-property">
        <p>{{ residence.villa_owned ? stories.property_greetings.after : stories.property_greetings.before }}</p>
        <p class="ug-muted">手持ち {{ number(shards) }} G</p>
        <article v-for="key in (residence.villa_owned ? ['villa', 'mirror', 'trophy_shelf', 'vault_expansion', 'resonance_expansion'] : ['villa']) as ResidenceItemKey[]" :key="key" class="ug-property-item">
            <header><h2>{{ residence.items[key].name }}</h2><strong>{{ number(residence.items[key].price) }} G</strong></header>
            <p v-if="residence.items[key].capacity_after !== undefined">収納数 {{ residence.items[key].capacity_before }} → {{ residence.items[key].capacity_after }}</p>
            <p><em>{{ stories.flavor[key] }}</em></p>
            <strong v-if="itemOwned(key)" class="ug-sold-out">SOLD OUT</strong>
            <button v-else class="ug-primary" type="button" :disabled="busy || shards < residence.items[key].price" @click="confirmation = key">購入する</button>
        </article>
        <section v-if="confirmation" class="ug-purchase-confirm" aria-label="購入内容の確認">
            <p>{{ residence.items[confirmation].name }}を {{ number(residence.items[confirmation].price) }} Gで購入します。</p>
            <button type="button" :disabled="busy" @click="confirmation = null">やめる</button>
            <button class="ug-primary" type="button" :disabled="busy" @click="$emit('purchase', confirmation)">購入を確定する</button>
        </section>
    </section>
    <section v-else-if="!residence.villa_owned" class="ug-villa">
        <h2>別荘</h2>
        <p>交流場の不動産で別荘を購入すると、冒険日誌と回想を読めます。</p>
        <button class="ug-primary" type="button" @click="$emit('property')">不動産へ</button>
    </section>
    <section v-else-if="mode === 'trophies'" class="ug-trophies" aria-label="トロフィー棚">
        <h2>トロフィー棚</h2>
        <template v-if="!residence.trophy_shelf_owned">
            <p>交流場の不動産でトロフィー棚を購入すると、これまで初めて倒した強敵の記念品を飾れます。</p>
            <button class="ug-primary" type="button" @click="$emit('property')">不動産へ · {{ number(residence.items.trophy_shelf.price) }} G</button>
        </template>
        <p v-else-if="loading" role="status">トロフィー棚を開いています。</p>
        <p v-else-if="journalError" role="alert">{{ journalError }}</p>
        <template v-else-if="journal">
            <p class="ug-muted">初撃破日時（日本時間）</p>
            <ul v-if="journal.trophies.length" class="ug-trophy-list">
                <li v-for="trophy in journal.trophies" :key="trophy.key">
                    <h3>{{ trophy.name }}</h3>
                    <p>{{ trophy.achievement }}</p>
                    <time v-if="trophy.achieved_at" :datetime="trophy.achieved_at">{{ achievedAt(trophy.achieved_at) }}</time>
                    <span v-else>初撃破日時の記録なし</span>
                </li>
            </ul>
            <p v-else class="ug-muted">まだトロフィーはありません。強敵を初めて倒すと、ここに記念品が増えていきます。</p>
        </template>
    </section>
    <section v-else class="ug-journal" aria-label="冒険日誌">
        <h2>冒険日誌</h2>
        <p v-if="loading" role="status">日誌を開いています。</p>
        <p v-else-if="journalError" role="alert">{{ journalError }}</p>
        <template v-else-if="journal">
            <p class="ug-muted">自分の秘書の冒険記録です。戦績には初めてのジャイアントラットと実験場を含み、スキップ・命名の物語・案内人との決闘は含みません。</p>
            <h3>クリアした試練</h3>
            <ul v-if="journal.cleared_trials.length"><li v-for="trial in journal.cleared_trials" :key="trial.key">{{ trial.name }}</li></ul>
            <p v-else class="ug-muted">まだありません。</p>
            <dl>
                <div><dt>戦闘数</dt><dd>{{ number(journal.battle_count) }}</dd></div>
                <div><dt>勝利数</dt><dd>{{ number(journal.victory_count) }}</dd></div>
                <div><dt>本人が与えたダメージ</dt><dd>{{ number(journal.damage_dealt) }}<small v-if="journal.damage_dealt !== null && journal.damage_dealt_unknown_battles">（記録のある分）</small></dd></div>
                <div><dt>本人が受けたダメージ</dt><dd>{{ number(journal.damage_received) }}<small v-if="journal.damage_received !== null && journal.damage_received_unknown_battles">（記録のある分）</small></dd></div>
                <div><dt>スキップチケットを使った数</dt><dd>{{ number(journal.skip_tickets_used) }} 枚</dd></div>
                <div><dt>案内人をげんこつした回数</dt><dd>{{ number(journal.guide_punch_count) }} 回</dd></div>
            </dl>
            <h3>本人の最大一撃</h3>
            <dl>
                <div><dt>最大ダメージ</dt><dd>{{ number(journal.maximum_hit.value) }}<small v-if="journal.maximum_hit.value !== null && journal.maximum_hit.unknown_battles">記録のある {{ number(journal.maximum_hit.known_battles) }} 戦での最大</small></dd></div>
                <div v-if="journal.maximum_hit.value !== null"><dt>技名</dt><dd>{{ journal.maximum_hit.action_name ?? '技名の記録なし' }}</dd></div>
            </dl>
            <p v-if="journal.maximum_hit.unknown_battles" class="ug-muted">{{ number(journal.maximum_hit.unknown_battles) }} 戦は最大一撃の記録なし。</p>

            <h3>本人の愛用技 TOP3</h3>
            <p class="ug-muted">通常攻撃を除く使用回数順。覚醒技を含みます。</p>
            <ol v-if="journal.favorite_skills.entries.length" class="ug-journal-skills">
                <li v-for="skill in journal.favorite_skills.entries" :key="skill.key"><span>{{ skill.name ?? '名称不明の技' }}</span><strong>{{ number(skill.count) }} 回</strong></li>
            </ol>
            <p v-else class="ug-muted">{{ journal.favorite_skills.known_battles ? '記録のある戦闘では、まだ技を使っていません。' : '使用技の記録なし。' }}</p>
            <p v-if="journal.favorite_skills.unknown_battles" class="ug-muted">記録のある {{ number(journal.favorite_skills.known_battles) }} 戦での集計。{{ number(journal.favorite_skills.unknown_battles) }} 戦は使用技の記録なし。</p>

            <h3>回復・防御・蘇生</h3>
            <p class="ug-muted">本人は自分の秘書の分、PT合計は同行者を含む自分の冒険の合計です。実回復HPは実際に回復させたHP、蘇生は復活させた回数です。</p>
            <div class="ug-journal-table-wrap">
                <table class="ug-journal-table">
                    <thead><tr><th scope="col">記録</th><th scope="col">本人</th><th scope="col">PT合計</th></tr></thead>
                    <tbody>
                        <tr v-for="metric in supportMetrics" :key="metric.key">
                            <th scope="row">{{ metric.label }}</th>
                            <td v-for="scope in (['self', 'party'] as const)" :key="scope">
                                {{ number(journal.combat_support[scope][metric.key].value) }}<template v-if="journal.combat_support[scope][metric.key].value !== null"> {{ metric.unit }}</template>
                                <small v-if="journal.combat_support[scope][metric.key].unknown_battles">{{ journal.combat_support[scope][metric.key].value !== null ? '記録のある分・' : '' }}{{ number(journal.combat_support[scope][metric.key].unknown_battles) }} 戦は記録なし</small>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <h3>冒険先ごとのクリア</h3>
            <p class="ug-muted">保存されている回数です。回数の記録がない過去のクリアは「記録なし」と表示します。</p>
            <div v-if="journal.content_clears.length" class="ug-journal-table-wrap">
                <table class="ug-journal-table">
                    <thead><tr><th scope="col">冒険先</th><th scope="col">実戦</th><th scope="col">スキップ</th></tr></thead>
                    <tbody>
                        <tr v-for="clear in journal.content_clears" :key="`${clear.type}:${clear.key}`">
                            <th scope="row">{{ clear.name ?? '名称不明の冒険先' }}</th>
                            <td>{{ number(clear.actual_clear_count) }}<template v-if="clear.actual_clear_count !== null"> 回</template></td>
                            <td>{{ number(clear.skip_clear_count) }}<template v-if="clear.skip_clear_count !== null"> 回</template></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="ug-muted">クリア回数の記録はまだありません。</p>

            <h3>自分の秘書の助っ人参加</h3>
            <dl><div><dt>助っ人参加累計</dt><dd>{{ number(journal.lending_participation_count) }} 回</dd></div></dl>
            <p class="ug-muted">他の冒険に助っ人として参加し、決算された回数です。勝敗や報酬チケットの上限にかかわらず数えます。</p>
        </template>
    </section>
</template>
