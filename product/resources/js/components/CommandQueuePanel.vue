<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import { formatExactMoney } from '../formatters/money';
import type {
    CommandCatalog,
    CommandDefinition,
    CommandQueue,
    CommandQueueItem,
    EffectivePlanSlot,
    MapCell,
} from '../types';
import CellDetails from './CellDetails.vue';

const props = defineProps<{
    nationId: number;
    mapSpaceId: number;
    selected: MapCell | null;
}>();

const definitions = ref<CommandDefinition[]>([]);
const quantityContract = ref({
    type: 'integer' as const,
    minimum: 1,
    maximum: 99,
    default: 1,
    quick_presets: [1, 5, 10, 25, 50, 99],
});
const queue = ref<CommandQueue>({
    version: 1,
    limit: 1,
    explicit_count: 0,
    items: [],
    plan: [],
});
const refreshing = ref(false);
const mutating = ref(false);
const busy = computed(() => refreshing.value || mutating.value);
const message = ref('');
const selectedPosition = ref(1);
const draggedItemId = ref<number | null>(null);
const pendingDefinition = ref<CommandDefinition | null>(null);
const editingItem = ref<CommandQueueItem | null>(null);
const quantity = ref(1);
const commandParameters = ref<Record<string, number | null>>({});
const mobileCommandExpanded = ref(false);
const mobilePlanExpanded = ref(false);
let refreshGeneration = 0;
let activeRefreshController: AbortController | null = null;

const basePath = (nationId = props.nationId, mapSpaceId = props.mapSpaceId) => `/api/v1/nations/${nationId}/map-spaces/${mapSpaceId}`;
const applicableDefinitions = computed(() => definitions.value.filter((definition) => definition.applicable));
const quantityIsValid = computed(() => Number.isInteger(quantity.value)
    && quantity.value >= quantityContract.value.minimum
    && quantity.value <= quantityContract.value.maximum);

watch(
    () => [props.selected?.x, props.selected?.y, props.nationId, props.mapSpaceId, selectedPosition.value],
    () => void refresh(),
    { immediate: true },
);

async function refresh(): Promise<void> {
    const generation = ++refreshGeneration;
    activeRefreshController?.abort();
    const controller = new AbortController();
    activeRefreshController = controller;
    const selected = props.selected === null ? null : { x: props.selected.x, y: props.selected.y };
    const path = basePath(props.nationId, props.mapSpaceId);
    const query = new URLSearchParams({ position: String(selectedPosition.value) });
    if (selected !== null) {
        query.set('target_x', String(selected.x));
        query.set('target_y', String(selected.y));
    }

    refreshing.value = true;
    message.value = '';

    try {
        const [nextDefinitions, nextQueue] = await Promise.all([
            api<CommandCatalog>(`${path}/command-definitions?${query}`, { signal: controller.signal }),
            api<CommandQueue>(`${path}/command-queue`, { signal: controller.signal }),
        ]);

        if (generation !== refreshGeneration) return;
        definitions.value = nextDefinitions.commands;
        quantityContract.value = nextDefinitions.quantity_contract;
        queue.value = nextQueue;
        if (selectedPosition.value > nextQueue.limit) selectedPosition.value = nextQueue.limit;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof Error ? error.message : '開発計画を取得できませんでした。';
    } finally {
        if (generation === refreshGeneration) {
            if (activeRefreshController === controller) activeRefreshController = null;
            refreshing.value = false;
        }
    }
}

function chooseCommand(definition: CommandDefinition): void {
    if (!definition.available || (definition.target_type === 'cell' && props.selected === null)) return;
    pendingDefinition.value = definition;
    quantity.value = quantityContract.value.default;
    commandParameters.value = Object.fromEntries(Object.entries(definition.parameters).map(([key, schema]) => [
        key,
        schema.default ?? null,
    ]));
}

const parametersAreValid = computed(() => {
    const definition = pendingDefinition.value;
    if (definition === null) return true;

    return Object.entries(definition.parameters).every(([key, schema]) => {
        const value = commandParameters.value[key];
        if (value === null || value === undefined) return !schema.required || schema.nullable === true;
        return Number.isInteger(value) && value >= schema.minimum && value <= schema.maximum;
    });
});

async function addPendingCommand(): Promise<void> {
    const definition = pendingDefinition.value;
    if (definition === null || !quantityIsValid.value || !parametersAreValid.value) return;
    const parameters: Record<string, number> = {};
    for (const [key, value] of Object.entries(commandParameters.value)) {
        if (value !== null) parameters[key] = value;
    }
    await addCommand(definition, quantity.value, parameters);
    pendingDefinition.value = null;
}

async function addCommand(
    definition: CommandDefinition,
    requestedQuantity: number,
    parameters: Record<string, number>,
): Promise<void> {
    if ((definition.target_type === 'cell' && props.selected === null) || !definition.available) return;
    const generation = refreshGeneration;
    const selected = props.selected === null ? null : { x: props.selected.x, y: props.selected.y };
    const path = basePath(props.nationId, props.mapSpaceId);
    mutating.value = true;

    try {
        const result = await api<{ queue: CommandQueue; message: string }>(`${path}/command-queue`, {
            method: 'POST',
            body: JSON.stringify({
                command_key: definition.key,
                target_x: definition.target_type === 'cell' ? selected?.x : null,
                target_y: definition.target_type === 'cell' ? selected?.y : null,
                position: selectedPosition.value,
                request_key: crypto.randomUUID(),
                expected_version: queue.value.version,
                quantity: requestedQuantity,
                parameters,
            }),
        });
        if (generation !== refreshGeneration) return;
        queue.value = result.queue;
        message.value = result.message;
        mobileCommandExpanded.value = false;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof ApiError && error.status === 409
            ? '開発計画が更新されています。再読み込みしました。'
            : error instanceof Error ? error.message : '開発計画へ登録できませんでした。';
        if (error instanceof ApiError && error.status === 409) await refresh();
    } finally {
        mutating.value = false;
    }
}

function selectPlanSlot(slot: EffectivePlanSlot): void {
    selectedPosition.value = slot.position;
    if (slot.kind === 'explicit' && window.matchMedia?.('(max-width: 820px)').matches) {
        openQuantityEditor(slot);
    }
}

function beginDrag(slot: EffectivePlanSlot): void {
    draggedItemId.value = slot.kind === 'explicit' ? slot.id : null;
}

async function dropAt(target: EffectivePlanSlot): Promise<void> {
    const sourceId = draggedItemId.value;
    draggedItemId.value = null;
    if (sourceId === null) return;
    const source = queue.value.items.find((item) => item.id === sourceId);
    if (source === undefined || source.queue_position === target.position) return;

    const placements = queue.value.items.map((item) => {
        if (item.id === sourceId) return { id: item.id, position: target.position };
        if (target.kind === 'explicit' && item.id === target.id) {
            return { id: item.id, position: source.queue_position };
        }
        return { id: item.id, position: item.queue_position };
    });
    await mutateQueue('PUT', `${basePath()}/command-queue/reorder`, {
        placements,
        expected_version: queue.value.version,
    });
}

async function move(itemId: number, delta: number): Promise<void> {
    const source = queue.value.items.find((item) => item.id === itemId);
    if (source === undefined) return;
    const destination = source.queue_position + delta;
    if (destination < 1 || destination > queue.value.limit) return;
    const target = queue.value.plan[destination - 1];
    if (target !== undefined) await dropFromKeyboard(source.id, target);
}

async function dropFromKeyboard(sourceId: number, target: EffectivePlanSlot): Promise<void> {
    draggedItemId.value = sourceId;
    await dropAt(target);
}

async function cancel(itemId: number): Promise<void> {
    await mutateQueue('DELETE', `${basePath()}/command-queue/${itemId}`, { expected_version: queue.value.version });
    editingItem.value = null;
}

function openQuantityEditor(item: CommandQueueItem): void {
    editingItem.value = item;
    quantity.value = item.quantity;
}

async function saveQuantity(): Promise<void> {
    const item = editingItem.value;
    if (item === null || !quantityIsValid.value) return;
    await mutateQueue('PATCH', `${basePath()}/command-queue/${item.id}`, {
        quantity: quantity.value,
        expected_version: queue.value.version,
    });
    editingItem.value = null;
}

function planKeydown(event: KeyboardEvent, slot: EffectivePlanSlot): void {
    if (event.key === 'Escape') {
        selectedPosition.value = 0;
        editingItem.value = null;
        return;
    }
    if (slot.kind !== 'explicit') return;
    if (event.key === 'Delete') {
        event.preventDefault();
        void cancel(slot.id);
    }
    if (event.altKey && (event.key === 'ArrowUp' || event.key === 'ArrowDown')) {
        event.preventDefault();
        void move(slot.id, event.key === 'ArrowUp' ? -1 : 1);
    }
    if (event.key === 'Enter' || event.key.toLowerCase() === 'q') {
        event.preventDefault();
        openQuantityEditor(slot);
    }
}

function toggleCommandSheet(): void {
    mobileCommandExpanded.value = !mobileCommandExpanded.value;
    if (mobileCommandExpanded.value) mobilePlanExpanded.value = false;
}

function togglePlanDrawer(): void {
    mobilePlanExpanded.value = !mobilePlanExpanded.value;
    if (mobilePlanExpanded.value) mobileCommandExpanded.value = false;
}

async function mutateQueue(method: 'PUT' | 'PATCH' | 'DELETE', path: string, body: object): Promise<void> {
    const generation = refreshGeneration;
    mutating.value = true;
    message.value = '';
    try {
        const nextQueue = await api<CommandQueue>(path, { method, body: JSON.stringify(body) });
        if (generation !== refreshGeneration) return;
        queue.value = nextQueue;
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        message.value = error instanceof Error ? error.message : '開発計画を更新できませんでした。';
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
    <div class="command-workspace">
        <aside class="command-panel" :class="{ expanded: mobileCommandExpanded }" aria-label="セル情報と開発コマンド" :aria-busy="busy">
            <button class="mobile-panel-toggle" type="button" @click="toggleCommandSheet">
                セルとコマンド <span>{{ mobileCommandExpanded ? '閉じる' : '開く' }}</span>
            </button>
            <div class="command-panel-body">
                <CellDetails :cell="selected" />
                <section class="available-commands">
                    <h3>適用できるコマンド</h3>
                    <p class="queue-notice">開発計画に登録されます。実行はターン更新時に行われ、登録時点では資金・地形・施設は変化しません。</p>
                    <p v-if="message" class="compact-message" role="status">{{ message }}</p>
                    <div v-if="applicableDefinitions.length" class="command-grid">
                        <button
                            v-for="definition in applicableDefinitions"
                            :key="definition.key"
                            type="button"
                            :disabled="busy || !definition.available || queue.explicit_count >= queue.limit"
                            :title="definition.unavailable_reason ?? definition.description"
                            @click="chooseCommand(definition)"
                        >
                            <strong>{{ definition.name }}</strong>
                            <span>{{ formatExactMoney(definition.cost_money) }}</span>
                            <span v-if="definition.initial_facility_capacity">初期 {{ definition.initial_facility_capacity.formatted }}</span>
                            <span v-if="definition.shortfall_money > 0" class="shortfall">資金が{{ formatExactMoney(definition.shortfall_money) }}不足</span>
                            <span v-for="warning in definition.execution_warnings" :key="warning" class="shortfall">{{ warning }}</span>
                        </button>
                    </div>
                    <p v-else class="empty-state">このセルで登録できるコマンドはありません。</p>
                    <form v-if="pendingDefinition" class="parameter-popover" @submit.prevent="addPendingCommand">
                        <strong>{{ pendingDefinition.name }}の設定</strong>
                        <div class="preset-row">
                            <button v-for="preset in quantityContract.quick_presets" :key="preset" type="button" @click="quantity = preset">{{ preset }}</button>
                        </div>
                        <label>数量
                            <input v-model.number="quantity" type="number" step="1" :min="quantityContract.minimum" :max="quantityContract.maximum" required>
                        </label>
                        <label v-for="(schema, key) in pendingDefinition.parameters" :key="key">
                            {{ schema.label }}
                            <input
                                v-model.number="commandParameters[key]"
                                type="number"
                                step="1"
                                :min="schema.minimum"
                                :max="schema.maximum"
                                :required="schema.required && !schema.nullable"
                            >
                        </label>
                        <div class="popover-actions">
                            <button type="button" @click="pendingDefinition = null">閉じる</button>
                            <button type="submit" :disabled="!quantityIsValid || !parametersAreValid">計画へ登録</button>
                        </div>
                    </form>
                </section>
            </div>
        </aside>

        <aside class="plan-panel" :class="{ expanded: mobilePlanExpanded }" aria-label="開発計画">
            <button class="mobile-panel-toggle" type="button" @click="togglePlanDrawer">
                開発計画 {{ queue.limit }}枠 <span>{{ mobilePlanExpanded ? '閉じる' : '開く' }}</span>
            </button>
            <div class="plan-panel-body">
                <div class="plan-heading">
                    <div>
                        <p class="eyebrow">DEVELOPMENT PLAN</p>
                        <h3>開発計画</h3>
                    </div>
                    <span>{{ queue.explicit_count }}件登録</span>
                </div>
                <ol class="plan-list">
                    <li
                        v-for="slot in queue.plan"
                        :key="slot.kind === 'explicit' ? `item-${slot.id}` : `auto-${slot.position}`"
                        class="plan-row"
                        :class="{ selected: selectedPosition === slot.position, automatic: slot.kind === 'automatic_finance' }"
                        :draggable="slot.kind === 'explicit'"
                        tabindex="0"
                        @click="selectPlanSlot(slot)"
                        @dblclick="slot.kind === 'explicit' && openQuantityEditor(slot)"
                        @contextmenu.prevent="slot.kind === 'explicit' && cancel(slot.id)"
                        @keydown="planKeydown($event, slot)"
                        @dragstart="beginDrag(slot)"
                        @dragover.prevent
                        @drop.prevent="dropAt(slot)"
                    >
                        <span class="plan-position">{{ slot.position }}</span>
                        <span class="drag-handle" aria-hidden="true">⠿</span>
                        <span class="plan-command">
                            <strong>{{ slot.command_name }}<template v-if="slot.kind === 'explicit'"> ×{{ slot.quantity }}</template></strong>
                            <small v-if="slot.kind === 'explicit'">
                                x={{ slot.target_x }}, y={{ slot.target_y }}
                            </small>
                            <small v-else>自動</small>
                        </span>
                        <span v-if="slot.kind === 'explicit'" class="plan-row-actions">
                            <button type="button" aria-label="前へ移動" :disabled="busy || slot.position === 1" @click.stop="move(slot.id, -1)">↑</button>
                            <button type="button" aria-label="後へ移動" :disabled="busy || slot.position === queue.limit" @click.stop="move(slot.id, 1)">↓</button>
                            <button type="button" @click.stop="openQuantityEditor(slot)">数量</button>
                            <button type="button" @click.stop="cancel(slot.id)">取消</button>
                        </span>
                    </li>
                </ol>
                <form v-if="editingItem" class="parameter-popover plan-parameter-popover" @submit.prevent="saveQuantity">
                    <strong>{{ editingItem.command_name }}の数量</strong>
                    <div class="preset-row">
                        <button v-for="preset in quantityContract.quick_presets" :key="preset" type="button" @click="quantity = preset">{{ preset }}</button>
                    </div>
                    <label>数量
                        <input v-model.number="quantity" type="number" step="1" :min="quantityContract.minimum" :max="quantityContract.maximum" required>
                    </label>
                    <div class="popover-actions">
                        <button type="button" @click="editingItem = null">閉じる</button>
                        <button type="submit" :disabled="!quantityIsValid">保存</button>
                    </div>
                </form>
            </div>
        </aside>
    </div>
</template>
