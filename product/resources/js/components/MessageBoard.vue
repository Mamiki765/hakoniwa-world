<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import { newestIncoming, type BoardMark } from '../state/ownBoardUnread';
import type { MessageBoardEntry, MessageBoardTimeline } from '../types';

const props = defineProps<{
    nationId: number;
    context: 'development' | 'public';
}>();
const emit = defineEmits<{ posted: []; latest: [mark: BoardMark | null]; shown: [shown: boolean] }>();

const timeline = ref<MessageBoardTimeline | null>(null);
const body = ref('');
// 入力欄はひとつ。チェックを入れたときだけ秘密通信として送る（費用がかかる）。
const secret = ref(false);
const loading = ref(true);
const posting = ref(false);
const error = ref('');
const postError = ref('');
const collapsed = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let loadGeneration = 0;

// いちばん新しい「届いた伝言」。親が「新着あり」の印を出すのに使う。
watch(timeline, (loaded) => {
    if (loaded !== null) emit('latest', newestIncoming(loaded));
});
// 本文（伝言の一覧）をいま出せているか。読み込み中と取得失敗のあいだは出せていない。
watch(() => timeline.value !== null && !loading.value && error.value === '', (shown) => emit('shown', shown), { immediate: true });

const canPost = computed(() => timeline.value?.viewer.can_post === true);
const canSendSecret = computed(() => timeline.value?.viewer.can_send_secret === true);
// 通常投稿ができず秘密通信だけ送れるときは、秘密に固定する。
const sendsSecret = computed(() => canSendSecret.value && (secret.value || !canPost.value));
const bodyLength = computed(() => Array.from(body.value).length);
const bodyInvalid = computed(() => bodyLength.value < 1 || bodyLength.value > 140);
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

async function post(): Promise<void> {
    if (bodyInvalid.value || posting.value) return;
    const asSecret = sendsSecret.value;
    if (!asSecret && !canPost.value) return;
    posting.value = true;
    loadGeneration++;
    postError.value = '';
    try {
        const path = `/api/v1/nations/${props.nationId}/message-board${asSecret ? '/secret' : ''}`;
        timeline.value = await api<MessageBoardTimeline>(path, {
            method: 'POST',
            body: JSON.stringify({ body: body.value }),
        });
        body.value = '';
        // 次の書き込みでうっかり費用を払わないよう、送れたらチェックを外す。
        secret.value = false;
        emit('posted');
    } catch (caught) {
        postError.value = apiMessage(caught, asSecret ? '秘密通信を送信できませんでした。' : '伝言を投稿できませんでした。');
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
            <form
                v-if="canPost || canSendSecret"
                class="message-board-form"
                :class="{ 'secret-form': sendsSecret }"
                @submit.prevent="post"
            >
                <label for="message-board-body" class="message-board-form-label">{{ sendsSecret ? '秘密通信' : '伝言を書く' }}</label>
                <textarea
                    id="message-board-body"
                    v-model="body"
                    rows="3"
                    :aria-invalid="bodyLength > 140"
                    aria-describedby="message-board-counter message-board-error"
                ></textarea>
                <div class="message-board-form-footer">
                    <label v-if="canSendSecret" class="message-board-secret-toggle">
                        <input
                            id="message-board-secret"
                            type="checkbox"
                            :checked="sendsSecret"
                            :disabled="!canPost"
                            @change="secret = ($event.target as HTMLInputElement).checked"
                        >
                        <span>秘密（{{ timeline?.contract.secret_cost_display }}）</span>
                    </label>
                    <span
                        id="message-board-counter"
                        class="character-counter"
                        :class="{ over: bodyLength > 140 }"
                    >{{ bodyLength }} / 140</span>
                    <button class="button primary" type="submit" :disabled="bodyInvalid || posting">
                        {{ sendsSecret ? '秘密通信を送る' : '投稿' }}
                    </button>
                </div>
                <p v-if="postError" id="message-board-error" class="field-error" role="alert">{{ postError }}</p>
            </form>
            <p v-else-if="timeline && !timeline.viewer.authenticated" class="message-board-read-only">
                閲覧のみです。投稿にはログインが必要です。
            </p>

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
        </div>
    </section>
</template>
