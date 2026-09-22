<script setup lang="ts">
import { onMounted, ref } from 'vue';
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
const message = ref('');
const labels = { normal: '正常', delayed: '遅延', failed: '失敗・手動確認待ち', blocked: '停止・手動確認待ち' };

onMounted(refresh);

async function refresh(): Promise<void> {
    try {
        status.value = await api<TurnStatus>(`/api/v1/admin/worlds/${props.worldId}/turn-status`);
    } catch {
        message.value = 'ターンの最新状態を取得できませんでした。';
    }
}

async function attempt(): Promise<void> {
    if (busy.value || status.value === null) return;
    const target = pendingTarget.value ?? status.value.current_turn + 1;
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
        <button :disabled="busy || (!status?.can_attempt && pendingTarget === null)" @click="attempt">{{ busy ? '進行結果を確認中…' : pendingTarget !== null ? `T${pendingTarget}の結果を確認・再試行` : 'Turn進行を試みる' }}</button>
        <button :disabled="busy" @click="refresh">状態を再読込</button>
        <small>次回予定時刻を過ぎた場合だけ、既存の手動実行を1ターン分試みます。</small>
    </section>
</template>

<style scoped>
.admin-turn { margin-bottom: 1rem; }
button { margin-right: .5rem; }
small { display: block; margin-top: .5rem; }
</style>
