<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import { api } from '../api/client';
import type { PlayerIslandEventPage, PublicEventPage } from '../types';

const props = defineProps<{ nationId: number; audience: 'public' | 'owner' }>();
const result = ref<PlayerIslandEventPage | PublicEventPage | null>(null);
const currentPage = ref(1);
const anchorTurn = ref<number | null>(null);
const loading = ref(false);
const error = ref('');

function formatDelta(value: number): string {
    const sign = value > 0 ? '+' : value < 0 ? '-' : '±';
    return `${sign}${Math.abs(value).toLocaleString('ja-JP')}`;
}

function deltaClass(value: number): string {
    return value > 0 ? 'delta-positive' : value < 0 ? 'delta-negative' : 'delta-neutral';
}

async function loadPage(page: number): Promise<void> {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    const query = new URLSearchParams({ page: String(page) });
    if (anchorTurn.value !== null) query.set('anchor_turn', String(anchorTurn.value));

    try {
        const endpoint = props.audience === 'public'
            ? `/api/v1/public/nations/${props.nationId}/events`
            : `/api/v1/nations/${props.nationId}/events`;
        const next = await api<PlayerIslandEventPage | PublicEventPage>(
            `${endpoint}?${query.toString()}`,
        );
        result.value = next;
        currentPage.value = next.page;
        anchorTurn.value = next.anchor_turn;
    } catch {
        error.value = `${props.audience === 'public' ? '公開島ログ' : '島ログ'}を取得できませんでした。時間をおいて再度お試しください。`;
    } finally {
        loading.value = false;
    }
}

function resetAndLoad(): void {
    result.value = null;
    currentPage.value = 1;
    anchorTurn.value = null;
    void loadPage(1);
}

onMounted(resetAndLoad);
watch(() => [props.nationId, props.audience], resetAndLoad);
</script>

<template>
    <section class="island-events-panel" :aria-labelledby="`island-events-heading-${props.audience}`">
        <header class="island-events-heading">
            <div>
                <p class="eyebrow">{{ props.audience === 'public' ? 'PUBLIC ISLAND LOG' : 'ISLAND LOG' }}</p>
                <h2 :id="`island-events-heading-${props.audience}`">{{ props.audience === 'public' ? '公開島ログ' : '島ログ' }}</h2>
            </div>
            <span v-if="result?.turn_range">
                第{{ result.turn_range.start }}〜{{ result.turn_range.end }}ターン
            </span>
            <span v-else>{{ result?.turns_per_page ?? 12 }}ターンごと</span>
        </header>

        <p v-if="loading" class="island-events-status" role="status">出来事を読み込み中…</p>
        <div v-if="error" class="island-events-error" role="alert">
            <p>{{ error }}</p>
            <button type="button" :disabled="loading" @click="loadPage(currentPage)">再読み込み</button>
        </div>
        <p v-else-if="!loading && result?.groups.length === 0" class="empty-state">
            この{{ result?.turns_per_page ?? 12 }}ターンには表示できるログがありません。
        </p>

        <div v-if="result?.groups.length" class="island-event-groups">
            <section v-for="group in result.groups" :key="group.target_turn" class="island-event-group">
                <h3>第{{ group.target_turn }}ターン</h3>
                <ol>
                    <li
                        v-for="event in group.events"
                        :key="event.id"
                        :class="`importance-${event.importance}`"
                    >
                        <span class="island-event-mark" aria-hidden="true"></span>
                        <p>
                            <span v-if="'confidential' in event && event.confidential" class="event-confidential-label">秘密</span>
                            {{ event.message }}
                            <template v-if="'summary' in event && event.summary">
                                <span :class="deltaClass(event.summary.money.delta)"> 資金 {{ formatDelta(event.summary.money.delta) }}億円</span>
                                <span :class="deltaClass(event.summary.population.delta)">／人口 {{ formatDelta(event.summary.population.delta) }}人</span>
                                <span :class="deltaClass(event.summary.food.delta)">／食料 {{ formatDelta(event.summary.food.delta) }}トン</span>
                            </template>
                        </p>
                    </li>
                </ol>
            </section>
        </div>

        <nav v-if="result" class="island-event-pagination" :aria-label="`${props.audience === 'public' ? '公開島ログ' : '島ログ'}のページ`">
            <button
                type="button"
                :disabled="loading || !result.has_newer_page"
                @click="loadPage(currentPage - 1)"
            >
                新しい{{ result.turns_per_page }}ターン
            </button>
            <span>{{ currentPage }}ページ</span>
            <button
                type="button"
                :disabled="loading || !result.has_older_page"
                @click="loadPage(currentPage + 1)"
            >
                過去{{ result.turns_per_page }}ターン
            </button>
        </nav>
    </section>
</template>
