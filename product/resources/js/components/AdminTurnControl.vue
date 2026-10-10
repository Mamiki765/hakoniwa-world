<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';

interface TurnStatus {
    current_turn: number;
    next_scheduled_turn_at: string;
    status: 'normal' | 'delayed' | 'failed' | 'blocked';
    can_attempt: boolean;
    calendar_initialized: boolean;
}
const props = defineProps<{ worldId: number }>();
const emit = defineEmits<{ updated: [] }>();
const status = ref<TurnStatus | null>(null);
const pendingTarget = ref<number | null>(null);
const busy = ref(false);
// 手動進行は取り消せないので、内容を確かめてから実行する。
// 確かめたときの対象ターン。再読込などで状態が変わったら、確認からやり直す。
const confirmTarget = ref<number | null>(null);
const confirming = computed(() => confirmTarget.value !== null);
const message = ref('');
const labels = { normal: '正常', delayed: '遅延', failed: '失敗・手動確認待ち', blocked: '停止・手動確認待ち' };

onMounted(refresh);

async function refresh(): Promise<void> {
    try {
        status.value = await api<TurnStatus>(`/api/v1/admin/worlds/${props.worldId}/turn-status`);
        if (confirmTarget.value !== null && (confirmTarget.value !== status.value.current_turn + 1 || !status.value.can_attempt)) {
            confirmTarget.value = null;
            message.value = '確認中にターンの状態が変わりました。内容を確かめ直してください。';
        }
    } catch {
        message.value = 'ターンの最新状態を取得できませんでした。';
    }
}

function requestAttempt(): void {
    if (busy.value || status.value === null) return;
    // 結果の確認・再試行は、同じターンをもう一度確かめるだけなのでそのまま進める。
    if (pendingTarget.value !== null) {
        void attempt();
        return;
    }
    confirmTarget.value = status.value.current_turn + 1;
}

async function attempt(): Promise<void> {
    if (busy.value || status.value === null) return;
    // 実行するのは、確かめた対象ターン（または結果を確認中のターン）だけ。いまの状態から対象を作り直さない。
    const target = pendingTarget.value ?? confirmTarget.value;
    confirmTarget.value = null;
    if (target === null) return;
    pendingTarget.value = target;
    busy.value = true;
    message.value = '';
    try {
        const result = await api<{ target_turn: number; status: string; failure_code?: string }>(`/api/v1/admin/worlds/${props.worldId}/turn-attempt`, {
            method: 'POST', body: JSON.stringify({ target_turn: target }),
        });
        pendingTarget.value = null;
        message.value = result.status === 'completed' ? `T${result.target_turn}の完了を確認しました。`
            : `T${result.target_turn}は未完了です（${result.failure_code ?? result.status}）。原因を確認してから再試行してください。`;
        emit('updated');
    } catch (failure) {
        if (failure instanceof ApiError && failure.status >= 400 && failure.status < 500) pendingTarget.value = null;
        message.value = failure instanceof Error ? failure.message : '進行結果を取得できませんでした。';
    } finally {
        await refresh();
        busy.value = false;
    }
}
</script>

<template>
    <section class="panel admin-turn" aria-label="管理者のTurn操作">
        <p v-if="status">現在 T{{ status.current_turn }} ／ 次回予定 {{ new Date(status.next_scheduled_turn_at).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo' }) }} JST ／ {{ labels[status.status] }}</p>
        <p v-if="message" role="status">{{ message }}</p>
        <p v-if="status && !status.calendar_initialized">Worldの予定起点が未設定です。運用手順に従って起点を設定すると手動進行を利用できます。</p>
        <button v-if="!confirming" :disabled="busy || (!status?.can_attempt && pendingTarget === null)" @click="requestAttempt">{{ busy ? '進行結果を確認中…' : pendingTarget !== null ? `T${pendingTarget}の結果を確認・再試行` : 'Turn進行を試みる' }}</button>
        <div v-if="confirming && status" class="admin-turn-confirm" role="alertdialog" aria-label="Turn進行の確認">
            <p><strong>T{{ confirmTarget }}への進行を試みます。</strong>全島の計画が実行され、元には戻せません。</p>
            <button class="button primary" :disabled="busy" @click="attempt">この内容で実行する</button>
            <button :disabled="busy" @click="confirmTarget = null">やめる</button>
        </div>
        <button :disabled="busy" @click="refresh">状態を再読込</button>
        <small>次回予定時刻を過ぎた場合だけ、既存の手動実行を1ターン分試みます。</small>
    </section>
</template>

<style scoped>
.admin-turn { margin-bottom: 1rem; }
.admin-turn-confirm { margin: .5rem 0; padding: .75rem 1rem; border: 1px solid var(--sl-turn, #aa8736); border-radius: var(--sl-radius, 4px); background: var(--warning-surface, #fff9e9); color: var(--ink, #3d3526); }
.admin-turn-confirm p { margin: 0 0 .5rem; }
button { margin-right: .5rem; }
small { display: block; margin-top: .5rem; }
</style>
