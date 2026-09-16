<script setup lang="ts">
import { ref, watch } from 'vue';
import { api } from '../api/client';
import type { ResidenceState } from './undergroundScenes';
import stories from '../../stories/lounge.json';

const props = defineProps<{ residence: ResidenceState; mode: 'property' | 'villa'; busy: boolean; shards: number }>();
defineEmits<{ purchase: [item: 'villa' | 'mirror']; property: [] }>();
const confirmation = ref<'villa' | 'mirror' | null>(null);
interface Journal {
    cleared_trials: Array<{ key: string; name: string }>;
    battle_count: number; victory_count: number;
    damage_dealt: number | null; damage_received: number | null;
    damage_dealt_unknown_battles: number; damage_received_unknown_battles: number;
    skip_tickets_used: number; guide_punch_count: number;
}
const journal = ref<Journal | null>(null);
const journalError = ref('');
const loading = ref(false);
const number = (value: number | null) => value === null ? '記録なし' : value.toLocaleString('ja-JP');
watch(() => [props.mode, props.residence.villa_owned, props.residence.mirror_owned] as const, async ([mode, owned], _, onCleanup) => {
    let cancelled = false;
    onCleanup(() => { cancelled = true; });
    confirmation.value = null;
    if (mode !== 'villa' || !owned) return;
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
        <article v-for="key in (residence.villa_owned ? ['villa', 'mirror'] : ['villa']) as Array<'villa' | 'mirror'>" :key="key" class="ug-property-item">
            <header><h2>{{ residence.items[key].name }}</h2><strong>{{ number(residence.items[key].price) }} G</strong></header>
            <p><em>{{ stories.flavor[key] }}</em></p>
            <strong v-if="key === 'villa' ? residence.villa_owned : residence.mirror_owned" class="ug-sold-out">SOLD OUT</strong>
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
    <section v-else class="ug-journal" aria-label="冒険日誌">
        <h2>冒険日誌</h2>
        <p v-if="loading" role="status">日誌を開いています。</p>
        <p v-else-if="journalError" role="alert">{{ journalError }}</p>
        <template v-else-if="journal">
            <h3>クリアした試練</h3>
            <ul v-if="journal.cleared_trials.length"><li v-for="trial in journal.cleared_trials" :key="trial.key">{{ trial.name }}</li></ul>
            <p v-else class="ug-muted">まだありません。</p>
            <dl>
                <div><dt>戦闘数</dt><dd>{{ number(journal.battle_count) }}</dd></div>
                <div><dt>勝利数</dt><dd>{{ number(journal.victory_count) }}</dd></div>
                <div><dt>与えたダメージ</dt><dd>{{ number(journal.damage_dealt) }}<small v-if="journal.damage_dealt_unknown_battles">（記録のある分）</small></dd></div>
                <div><dt>受けたダメージ</dt><dd>{{ number(journal.damage_received) }}<small v-if="journal.damage_received_unknown_battles">（記録のある分）</small></dd></div>
                <div><dt>スキップチケットを使った数</dt><dd>{{ number(journal.skip_tickets_used) }} 枚</dd></div>
                <div><dt>案内人をげんこつした回数</dt><dd>{{ number(journal.guide_punch_count) }} 回</dd></div>
            </dl>
        </template>
    </section>
</template>
