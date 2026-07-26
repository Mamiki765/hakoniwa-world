<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import { formatExactMoney } from '../formatters/money';
import type {
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

const automaticPlan = (): EffectivePlanSlot[] => Array.from({ length: 20 }, (_, index) => ({
    position: index + 1,
    kind: 'automatic_finance' as const,
    editable: false as const,
    command_name: '資金繰り' as const,
}));

const definitions = ref<CommandDefinition[]>([]);
const queue = ref<CommandQueue>({
    version: 1,
    limit: 20,
    explicit_count: 0,
    items: [],
    plan: automaticPlan(),
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
const mobileCommandExpanded = ref(false);
const mobilePlanExpanded = ref(false);
let refreshGeneration = 0;
let activeRefreshController: AbortController | null = null;

const basePath = (nationId = props.nationId, mapSpaceId = props.mapSpaceId) => `/api/v1/nations/${nationId}/map-spaces/${mapSpaceId}`;
const applicableDefinitions = computed(() => definitions.value.filter((definition) => definition.applicable));
const quantitySchema = computed(() => pendingDefinition.value?.parameter_schema.quantity ?? null);
const editingSchema = computed(() => {
    const item = editingItem.value;
    return item === null ? null : definitions.value.find((definition) => definition.key === item.command_key)?.parameter_schema.quantity ?? null;
});

watch(
    () => [props.selected?.x, props.selected?.y, props.nationId, props.mapSpaceId],
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
    const target = selected === null ? '' : `?target_x=${selected.x}&target_y=${selected.y}`;

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
    if (!definition.available || props.selected === null) return;
    const schema = definition.parameter_schema.quantity;
    if (schema !== undefined) {
        pendingDefinition.value = definition;
        quantity.value = schema.default;
        return;
    }
    void addCommand(definition, {});
}

async function addPendingCommand(): Promise<void> {
    const definition = pendingDefinition.value;
    if (definition === null) return;
    await addCommand(definition, { quantity: quantity.value });
    pendingDefinition.value = null;
}

async function addCommand(definition: CommandDefinition, parameters: Record<string, unknown>): Promise<void> {
    if (props.selected === null || !definition.available) return;
    const generation = refreshGeneration;
    const selected = { x: props.selected.x, y: props.selected.y };
    const path = basePath(props.nationId, props.mapSpaceId);
    mutating.value = true;

    try {
        const result = await api<{ queue: CommandQueue; message: string }>(`${path}/command-queue`, {
            method: 'POST',
            body: JSON.stringify({
                command_key: definition.key,
                target_x: selected.x,
                target_y: selected.y,
                position: selectedPosition.value,
                request_key: crypto.randomUUID(),
                expected_version: queue.value.version,
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

function openParameterEditor(item: CommandQueueItem): void {
    const schema = definitions.value.find((definition) => definition.key === item.command_key)?.parameter_schema.quantity;
    if (schema === undefined) return;
    editingItem.value = item;
    quantity.value = typeof item.parameters.quantity === 'number' ? item.parameters.quantity : schema.default;
}

async function saveParameters(): Promise<void> {
    const item = editingItem.value;
    if (item === null) return;
    await mutateQueue('PATCH', `${basePath()}/command-queue/${item.id}`, {
        parameters: { quantity: quantity.value },
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
                        </button>
                    </div>
                    <p v-else class="empty-state">このセルで登録できるコマンドはありません。</p>
                    <form v-if="pendingDefinition && quantitySchema" class="parameter-popover" @submit.prevent="addPendingCommand">
                        <strong>{{ pendingDefinition.name }}の数量</strong>
                        <div class="preset-row">
                            <button v-for="preset in quantitySchema.quick_presets" :key="preset" type="button" @click="quantity = preset">{{ preset }}</button>
                        </div>
                        <label>{{ quantitySchema.label }}
                            <input v-model.number="quantity" type="number" :min="quantitySchema.minimum" :max="quantitySchema.maximum" required>
                        </label>
                        <div class="popover-actions">
                            <button type="button" @click="pendingDefinition = null">閉じる</button>
                            <button type="submit">計画へ登録</button>
                        </div>
                    </form>
                </section>
            </div>
        </aside>

        <aside class="plan-panel" :class="{ expanded: mobilePlanExpanded }" aria-label="開発計画">
            <button class="mobile-panel-toggle" type="button" @click="togglePlanDrawer">
                開発計画 20枠 <span>{{ mobilePlanExpanded ? '閉じる' : '開く' }}</span>
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
                        @dblclick="slot.kind === 'explicit' && openParameterEditor(slot)"
                        @contextmenu.prevent="slot.kind === 'explicit' && cancel(slot.id)"
                        @keydown="planKeydown($event, slot)"
                        @dragstart="beginDrag(slot)"
                        @dragover.prevent
                        @drop.prevent="dropAt(slot)"
                    >
                        <span class="plan-position">{{ slot.position }}</span>
                        <span class="drag-handle" aria-hidden="true">⠿</span>
                        <span class="plan-command">
                            <strong>{{ slot.command_name }}</strong>
                            <small v-if="slot.kind === 'explicit'">
                                x={{ slot.target_x }}, y={{ slot.target_y }}
                                <template v-if="typeof slot.parameters.quantity === 'number'"> ／ 数量 {{ slot.parameters.quantity }}</template>
                            </small>
                            <small v-else>自動</small>
                        </span>
                        <span v-if="slot.kind === 'explicit'" class="plan-row-actions">
                            <button type="button" aria-label="前へ移動" :disabled="busy || slot.position === 1" @click.stop="move(slot.id, -1)">↑</button>
                            <button type="button" aria-label="後へ移動" :disabled="busy || slot.position === queue.limit" @click.stop="move(slot.id, 1)">↓</button>
                            <button v-if="definitions.find((definition) => definition.key === slot.command_key)?.parameter_schema.quantity" type="button" @click.stop="openParameterEditor(slot)">数量</button>
                            <button type="button" @click.stop="cancel(slot.id)">取消</button>
                        </span>
                    </li>
                </ol>
                <form v-if="editingItem && editingSchema" class="parameter-popover plan-parameter-popover" @submit.prevent="saveParameters">
                    <strong>{{ editingItem.command_name }}の数量</strong>
                    <div class="preset-row">
                        <button v-for="preset in editingSchema.quick_presets" :key="preset" type="button" @click="quantity = preset">{{ preset }}</button>
                    </div>
                    <label>{{ editingSchema.label }}
                        <input v-model.number="quantity" type="number" :min="editingSchema.minimum" :max="editingSchema.maximum" required>
                    </label>
                    <div class="popover-actions">
                        <button type="button" @click="editingItem = null">閉じる</button>
                        <button type="submit">保存</button>
                    </div>
                </form>
            </div>
        </aside>
    </div>
</template>
