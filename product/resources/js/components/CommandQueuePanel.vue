<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import type { CommandDefinition, CommandQueue, MapCell } from '../types';

const props = defineProps<{
    nationId: number;
    mapSpaceId: number;
    selected: MapCell | null;
}>();

const definitions = ref<CommandDefinition[]>([]);
const queue = ref<CommandQueue>({ version: 1, limit: 20, items: [] });
const refreshing = ref(false);
const mutating = ref(false);
const busy = computed(() => refreshing.value || mutating.value);
const message = ref('');
let refreshGeneration = 0;
let activeRefreshController: AbortController | null = null;

const basePath = (nationId = props.nationId, mapSpaceId = props.mapSpaceId) => `/api/v1/nations/${nationId}/map-spaces/${mapSpaceId}`;

watch(
    () => [props.selected?.q, props.selected?.r, props.nationId, props.mapSpaceId],
    () => void refresh(),
    { immediate: true },
);

async function refresh(): Promise<void> {
    const generation = ++refreshGeneration;
    activeRefreshController?.abort();
    const controller = new AbortController();
    activeRefreshController = controller;
    const selected = props.selected === null ? null : { q: props.selected.q, r: props.selected.r };
    const path = basePath(props.nationId, props.mapSpaceId);
    const target = selected === null ? '' : `?target_q=${selected.q}&target_r=${selected.r}`;

    refreshing.value = true;
    message.value = '';

    try {
        const [nextDefinitions, nextQueue] = await Promise.all([
            api<CommandDefinition[]>(`${path}/command-definitions${target}`, { signal: controller.signal }),
            api<CommandQueue>(`${path}/command-queue`, { signal: controller.signal }),
        ]);

        if (generation !== refreshGeneration) return;
        definitions.value = nextDefinitions;
        queue.value = nextQueue;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof Error ? error.message : 'コマンド情報を取得できませんでした。';
    } finally {
        if (generation === refreshGeneration) {
            if (activeRefreshController === controller) activeRefreshController = null;
            refreshing.value = false;
        }
    }
}

async function addCommand(definition: CommandDefinition): Promise<void> {
    if (props.selected === null || !definition.available) return;
    const generation = refreshGeneration;
    const selected = { q: props.selected.q, r: props.selected.r };
    const path = basePath(props.nationId, props.mapSpaceId);
    const expectedVersion = queue.value.version;
    mutating.value = true;

    try {
        const result = await api<{ queue: CommandQueue; message: string }>(`${path}/command-queue`, {
            method: 'POST',
            body: JSON.stringify({
                command_key: definition.key,
                target_q: selected.q,
                target_r: selected.r,
                request_key: crypto.randomUUID(),
                expected_version: expectedVersion,
                parameters: {},
            }),
        });
        if (generation !== refreshGeneration) return;
        queue.value = result.queue;
        message.value = result.message;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof ApiError && error.status === 409
            ? 'キューが更新されています。再読み込みしました。'
            : error instanceof Error ? error.message : 'コマンドを追加できませんでした。';
        if (error instanceof ApiError && error.status === 409) await refresh();
    } finally {
        mutating.value = false;
    }
}

async function move(itemId: number, delta: number): Promise<void> {
    const current = queue.value.items.findIndex((item) => item.id === itemId);
    const destination = current + delta;
    if (current < 0 || destination < 0 || destination >= queue.value.items.length) return;
    const ordered = [...queue.value.items];
    [ordered[current], ordered[destination]] = [ordered[destination]!, ordered[current]!];
    await mutateQueue('PUT', `${basePath()}/command-queue/reorder`, {
        ordered_ids: ordered.map((item) => item.id),
        expected_version: queue.value.version,
    });
}

async function cancel(itemId: number): Promise<void> {
    await mutateQueue('DELETE', `${basePath()}/command-queue/${itemId}`, { expected_version: queue.value.version });
}

async function mutateQueue(method: 'PUT' | 'DELETE', path: string, body: object): Promise<void> {
    const generation = refreshGeneration;
    mutating.value = true;
    message.value = '';
    try {
        const nextQueue = await api<CommandQueue>(path, { method, body: JSON.stringify(body) });
        if (generation !== refreshGeneration) return;
        queue.value = nextQueue;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof Error ? error.message : 'キューを更新できませんでした。';
        if (error instanceof ApiError && error.status === 409) await refresh();
    } finally {
        mutating.value = false;
    }
}

function isAbortError(error: unknown): boolean {
    return error instanceof Error && error.name === 'AbortError';
}

onBeforeUnmount(() => {
    refreshGeneration++;
    activeRefreshController?.abort();
    activeRefreshController = null;
});
</script>

<template>
    <section class="command-panel" aria-label="開発コマンド" :aria-busy="busy">
        <h3>開発コマンド</h3>
        <p class="queue-notice">commandはqueueへ登録されただけで、まだ実行されていません。登録時点では資金・地形・施設は変化しません。</p>
        <p v-if="message" class="compact-message" role="status">{{ message }}</p>
        <div class="command-grid">
            <button
                v-for="definition in definitions"
                :key="definition.key"
                type="button"
                :disabled="busy || !definition.available || queue.items.length >= queue.limit"
                :title="definition.unavailable_reason ?? definition.description"
                @click="addCommand(definition)"
            >
                <strong>{{ definition.name }}</strong>
                <span>{{ definition.cost_money.toLocaleString() }}円</span>
                <span v-if="definition.initial_facility_capacity">初期 {{ definition.initial_facility_capacity.formatted }}</span>
            </button>
        </div>
        <h4>予約 {{ queue.items.length }}/{{ queue.limit }}</h4>
        <ol class="queue-list">
            <li v-for="(item, index) in queue.items" :key="item.id">
                <span>{{ item.command_name }}（{{ item.target_q }}, {{ item.target_r }}）</span>
                <span class="queue-actions">
                    <button type="button" :disabled="busy || index === 0" aria-label="上へ" @click="move(item.id, -1)">↑</button>
                    <button type="button" :disabled="busy || index === queue.items.length - 1" aria-label="下へ" @click="move(item.id, 1)">↓</button>
                    <button type="button" :disabled="busy" @click="cancel(item.id)">取消</button>
                </span>
            </li>
        </ol>
    </section>
</template>
