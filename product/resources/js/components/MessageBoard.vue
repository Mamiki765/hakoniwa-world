<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import type { MessageBoardEntry, MessageBoardTimeline } from '../types';

const props = defineProps<{
    nationId: number;
    context: 'development' | 'public';
}>();
const emit = defineEmits<{ posted: [] }>();

const timeline = ref<MessageBoardTimeline | null>(null);
const body = ref('');
const secretBody = ref('');
const loading = ref(true);
const posting = ref(false);
const error = ref('');
const postError = ref('');
const secretError = ref('');
const collapsed = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let loadGeneration = 0;

const bodyLength = computed(() => Array.from(body.value).length);
const secretBodyLength = computed(() => Array.from(secretBody.value).length);
const bodyInvalid = computed(() => bodyLength.value < 1 || bodyLength.value > 140);
const secretBodyInvalid = computed(() => secretBodyLength.value < 1 || secretBodyLength.value > 140);
const preferenceKey = computed(() => `hakoniwa.message-board.collapsed:${props.context}`);
const contentId = computed(() => `message-board-content-${props.context}-${props.nationId}`);

onMounted(() => {
    try {
        collapsed.value = localStorage.getItem(preferenceKey.value) === '1';
    } catch {
        collapsed.value = false;
    }
    void load();
    refreshTimer = setInterval(() => {
        if (!posting.value) void load(false);
    }, 60_000);
});

onUnmounted(() => {
    if (refreshTimer !== null) clearInterval(refreshTimer);
});

function toggleCollapsed(): void {
    collapsed.value = !collapsed.value;
    try {
        localStorage.setItem(preferenceKey.value, collapsed.value ? '1' : '0');
    } catch {
        // The board remains usable when private browsing disables localStorage.
    }
}

async function load(showLoading = true): Promise<void> {
    const generation = ++loadGeneration;
    if (showLoading) loading.value = true;
    error.value = '';
    try {
        const loaded = await api<MessageBoardTimeline>(`/api/v1/nations/${props.nationId}/message-board`);
        if (generation === loadGeneration && !posting.value) timeline.value = loaded;
    } catch (caught) {
        if (generation === loadGeneration && !posting.value) {
            error.value = caught instanceof Error ? caught.message : '伝言板を取得できませんでした。';
        }
    } finally {
        if (showLoading && generation === loadGeneration) loading.value = false;
    }
}

async function postPublic(): Promise<void> {
    if (bodyInvalid.value || posting.value) return;
    posting.value = true;
    loadGeneration++;
    postError.value = '';
    try {
        timeline.value = await api<MessageBoardTimeline>(`/api/v1/nations/${props.nationId}/message-board`, {
            method: 'POST',
            body: JSON.stringify({ body: body.value }),
        });
        body.value = '';
        emit('posted');
    } catch (caught) {
        postError.value = apiMessage(caught, '伝言を投稿できませんでした。');
    } finally {
        posting.value = false;
    }
}

async function postSecret(): Promise<void> {
    if (secretBodyInvalid.value || posting.value) return;
    posting.value = true;
    loadGeneration++;
    secretError.value = '';
    try {
        timeline.value = await api<MessageBoardTimeline>(`/api/v1/nations/${props.nationId}/message-board/secret`, {
            method: 'POST',
            body: JSON.stringify({ body: secretBody.value }),
        });
        secretBody.value = '';
        emit('posted');
    } catch (caught) {
        secretError.value = apiMessage(caught, '秘密通信を送信できませんでした。');
    } finally {
        posting.value = false;
    }
}

function apiMessage(caught: unknown, fallback: string): string {
    if (!(caught instanceof ApiError)) return caught instanceof Error ? caught.message : fallback;
    const validation = Object.values(caught.errors)[0]?.[0];

    return validation ?? caught.message;
}

function authorText(entry: Extract<MessageBoardEntry, { kind: 'public' }>): string {
    if (entry.author.type === 'visitor') return entry.author.display_name;
    if (entry.author.type === 'owner') return `島主・N${entry.author.nation.nation_number} ${entry.author.nation.name}`;

    return `他島・N${entry.author.nation.nation_number} ${entry.author.nation.name}`;
}

function secretText(entry: Extract<MessageBoardEntry, { kind: 'secret' }>): string {
    const direction = entry.direction === 'outgoing' ? 'への' : 'からの';

    return `[N${entry.counterpart.nation_number} ${entry.counterpart.name}${direction}秘密通信]`;
}

function formatDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        month: 'numeric', day: 'numeric', hour: '2-digit', minute: '2-digit',
        hour12: false, timeZone: 'Asia/Tokyo',
    }).format(new Date(value));
}
</script>

<template>
    <section class="message-board" aria-labelledby="message-board-heading">
        <header class="message-board-heading">
            <div>
                <p class="eyebrow">ISLAND MESSAGE BOARD</p>
                <h2 id="message-board-heading">伝言板</h2>
            </div>
            <button
                type="button"
                class="message-board-toggle"
                :aria-expanded="!collapsed"
                :aria-controls="contentId"
                @click="toggleCollapsed"
            >
                {{ collapsed ? '開く' : '閉じる' }}
            </button>
        </header>

        <div v-show="!collapsed" :id="contentId" class="message-board-content">
            <p v-if="loading" class="message-board-status" role="status">伝言板を読み込んでいます…</p>
            <p v-else-if="error" class="field-error" role="alert">{{ error }}</p>
            <ol v-else-if="timeline?.entries.length" class="message-board-timeline" aria-label="最新16件の伝言">
                <li
                    v-for="entry in timeline.entries"
                    :key="entry.key"
                    :class="[
                        `message-${entry.kind}`,
                        entry.kind === 'public' ? `message-author-${entry.author.type}` : '',
                    ]"
                >
                    <div class="message-board-meta">
                        <strong v-if="entry.kind === 'public'">{{ authorText(entry) }}</strong>
                        <strong v-else-if="entry.kind === 'secret'">{{ secretText(entry) }}</strong>
                        <strong v-else>秘密通信</strong>
                        <time :datetime="entry.created_at">{{ formatDate(entry.created_at) }}</time>
                    </div>
                    <p v-if="entry.kind === 'secret_placeholder'" class="message-board-body">{{ entry.text }}</p>
                    <p v-else class="message-board-body">{{ entry.body }}</p>
                </li>
            </ol>
            <p v-else class="empty-state">伝言はまだありません。</p>

            <form v-if="timeline?.viewer.can_post" class="message-board-form" @submit.prevent="postPublic">
                <label for="message-board-body">通常伝言（プレーンテキスト）</label>
                <textarea
                    id="message-board-body"
                    v-model="body"
                    rows="3"
                    :aria-invalid="bodyLength > 140"
                    aria-describedby="message-board-counter message-board-error"
                ></textarea>
                <div class="message-board-form-footer">
                    <span
                        id="message-board-counter"
                        class="character-counter"
                        :class="{ over: bodyLength > 140 }"
                    >{{ bodyLength }} / 140</span>
                    <button class="button primary" type="submit" :disabled="bodyInvalid || posting">投稿</button>
                </div>
                <p v-if="postError" id="message-board-error" class="field-error" role="alert">{{ postError }}</p>
            </form>
            <p v-else-if="timeline && !timeline.viewer.authenticated" class="message-board-read-only">
                閲覧のみです。投稿にはログインが必要です。
            </p>

            <form v-if="timeline?.viewer.can_send_secret" class="message-board-form secret-form" @submit.prevent="postSecret">
                <label for="message-board-secret-body">秘密通信（費用 {{ timeline.contract.secret_cost_display }}）</label>
                <textarea
                    id="message-board-secret-body"
                    v-model="secretBody"
                    rows="3"
                    :aria-invalid="secretBodyLength > 140"
                    aria-describedby="message-board-secret-counter message-board-secret-error"
                ></textarea>
                <div class="message-board-form-footer">
                    <span
                        id="message-board-secret-counter"
                        class="character-counter"
                        :class="{ over: secretBodyLength > 140 }"
                    >{{ secretBodyLength }} / 140</span>
                    <button class="button primary" type="submit" :disabled="secretBodyInvalid || posting">秘密通信を送る</button>
                </div>
                <p v-if="secretError" id="message-board-secret-error" class="field-error" role="alert">{{ secretError }}</p>
            </form>
        </div>
    </section>
</template>
