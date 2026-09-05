<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import { formatExactMoney } from '../formatters/money';
import type {
    CommandCatalog,
    CommandDefinition,
    CommandQueue,
    CommandQueueItem,
    EffectivePlanSlot,
    MapCell,
    Nation,
    ShipOverlay,
    UndergroundFacilityTarget,
} from '../types';
import CellDetails from './CellDetails.vue';

type CommandStatusKind = 'idle' | 'success' | 'error';

interface CommandStatus {
    kind: CommandStatusKind;
    text: string;
}

interface QueueContext {
    nationId: number;
    mapSpaceId: number;
}

interface FrozenCommandContext extends QueueContext {
    targetX: number | null;
    targetY: number | null;
    targetLayer: number | null;
    targetSlotIndex: number | null;
    position: number;
    queueVersion: number;
}

const props = defineProps<{
    nationId: number;
    mapSpaceId: number;
    selected: MapCell | null;
    selectedUnderground?: UndergroundFacilityTarget | null;
    nationState?: Nation['state'];
}>();
const emit = defineEmits<{
    queue: [queue: CommandQueue];
    ship: [ship: ShipOverlay];
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
const commandStatus = ref<CommandStatus>({ kind: 'idle', text: '未送信' });
const shipStatus = ref<CommandStatus>({ kind: 'idle', text: '未変更' });
const shipHeading = ref<number | null>(null);
const selectedPosition = ref(1);
const draggedItemId = ref<number | null>(null);
const pendingDefinition = ref<CommandDefinition | null>(null);
const pendingCommandContext = ref<FrozenCommandContext | null>(null);
const commandDialog = ref<HTMLElement | null>(null);
const commandTrigger = ref<HTMLElement | null>(null);
const editingItem = ref<CommandQueueItem | null>(null);
const pendingQuantity = ref<number | null>(1);
const editingQuantity = ref<number | null>(1);
const commandParameters = ref<Record<string, number | null>>({});
const confirmation = ref<{ message: string; confirmLabel: string; action: () => void } | null>(null);
let refreshGeneration = 0;
let activeRefreshController: AbortController | null = null;
let refreshRequestedAfterMutation = false;
let disposed = false;

const basePath = (nationId = props.nationId, mapSpaceId = props.mapSpaceId) => `/api/v1/nations/${nationId}/map-spaces/${mapSpaceId}`;
const applicableDefinitions = computed(() => definitions.value.filter((definition) => definition.applicable));
const pendingQuantityIsValid = computed(() => quantityIsValid(pendingQuantity.value));
const editingQuantityIsValid = computed(() => quantityIsValid(editingQuantity.value));
const pendingCostMoney = computed(() => {
    const definition = pendingDefinition.value;
    if (definition === null) return 0;
    if (definition.quantity_semantics !== 'selector') return definition.cost_money;
    return definition.quantity_options.find((option) => option.value === pendingQuantity.value)?.cost_money
        ?? definition.cost_money;
});
const ownShip = computed(() => props.selected?.ship?.is_owner === true ? props.selected.ship : null);
const selectedPlanSlot = computed(() => queue.value.plan.find((slot) => slot.position === selectedPosition.value) ?? null);
const selectedPlanItem = computed(() => selectedPlanSlot.value?.kind === 'explicit' ? selectedPlanSlot.value : null);
const pendingTargetLabel = computed(() => {
    const context = pendingCommandContext.value;
    if (context === null) return '';
    if (context.targetLayer !== null && context.targetSlotIndex !== null) {
        return `地下${context.targetLayer}層・slot ${context.targetSlotIndex}`;
    }
    if (context.targetX !== null && context.targetY !== null) return `x=${context.targetX}, y=${context.targetY}`;
    return '島全体';
});

watch(
    () => [ownShip.value?.id, ownShip.value?.heading],
    () => {
        shipHeading.value = ownShip.value?.heading ?? null;
        shipStatus.value = { kind: 'idle', text: '未変更' };
    },
    { immediate: true },
);

async function updateShipHeading(): Promise<void> {
    const ship = ownShip.value;
    if (busy.value || (props.nationState ?? 'active') !== 'active' || ship === null || ship.version === null) return;
    beginMutation();
    try {
        const result = await api<{ id: number; heading: number | null; version: number }>(
            `/api/v1/nations/${props.nationId}/ships/${ship.id}/heading`,
            {
                method: 'PATCH',
                body: JSON.stringify({ heading: shipHeading.value, expected_version: ship.version }),
            },
        );
        emit('ship', { ...ship, heading: result.heading, version: result.version });
        shipStatus.value = { kind: 'success', text: '進路を変更しました' };
    } catch (error) {
        shipStatus.value = {
            kind: 'error',
            text: error instanceof ApiError && error.status === 409
                ? 'Shipが更新されています。マップを再読込してください'
                : playerFacingReason(error, '進路を変更できませんでした'),
        };
    } finally {
        await finishMutation();
    }
}

watch(
    () => [
        props.selected?.x,
        props.selected?.y,
        props.selectedUnderground?.layer,
        props.selectedUnderground?.slot_index,
        props.nationId,
        props.mapSpaceId,
        selectedPosition.value,
    ],
    () => requestRefresh(),
    { immediate: true },
);

function requestRefresh(): void {
    if (mutating.value) {
        refreshRequestedAfterMutation = true;
        return;
    }
    void refresh();
}

async function refresh(): Promise<void> {
    const generation = ++refreshGeneration;
    activeRefreshController?.abort();
    const controller = new AbortController();
    activeRefreshController = controller;
    const underground = props.selectedUnderground ?? null;
    const selected = underground !== null || props.selected === null
        ? null
        : { x: props.selected.x, y: props.selected.y };
    const path = basePath(props.nationId, props.mapSpaceId);
    const query = new URLSearchParams({ position: String(selectedPosition.value) });
    if (selected !== null) {
        query.set('target_x', String(selected.x));
        query.set('target_y', String(selected.y));
    } else if (underground !== null) {
        query.set('target_layer', String(underground.layer));
        query.set('target_slot_index', String(underground.slot_index));
    }

    refreshing.value = true;
    try {
        const [nextDefinitions, nextQueue] = await Promise.all([
            api<CommandCatalog>(`${path}/command-definitions?${query}`, { signal: controller.signal }),
            api<CommandQueue>(`${path}/command-queue`, { signal: controller.signal }),
        ]);

        if (generation !== refreshGeneration) return;
        definitions.value = nextDefinitions.commands;
        quantityContract.value = nextDefinitions.quantity_contract;
        applyServerQueue(nextQueue);
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        setCommandError(playerFacingReason(error, '開発計画を取得できませんでした'));
    } finally {
        if (generation === refreshGeneration) {
            if (activeRefreshController === controller) activeRefreshController = null;
            refreshing.value = false;
        }
    }
}

function chooseCommand(definition: CommandDefinition, event?: Event): void {
    if (!definition.available || !hasSelectedTarget(definition)) return;
    const trigger = event?.currentTarget instanceof HTMLElement ? event.currentTarget : null;
    const frozen = freezeCommandContext();
    if (definition.confirmation_message) {
        confirmation.value = {
            message: definition.confirmation_message,
            confirmLabel: '自爆を登録',
            action: () => {
                confirmation.value = null;
                prepareCommand(definition, trigger, frozen);
            },
        };
        return;
    }
    prepareCommand(definition, trigger, frozen);
}

function freezeCommandContext(): FrozenCommandContext {
    const underground = props.selectedUnderground ?? null;
    const selected = underground !== null || props.selected === null ? null : props.selected;
    return {
        nationId: props.nationId,
        mapSpaceId: props.mapSpaceId,
        targetX: selected?.x ?? null,
        targetY: selected?.y ?? null,
        targetLayer: underground?.layer ?? null,
        targetSlotIndex: underground?.slot_index ?? null,
        position: selectedPosition.value,
        queueVersion: queue.value.version,
    };
}

function prepareCommand(
    definition: CommandDefinition,
    trigger: HTMLElement | null = null,
    frozen = freezeCommandContext(),
): void {
    pendingQuantity.value = definition.quantity_default;
    commandParameters.value = Object.fromEntries(Object.entries(definition.parameters).map(([key, schema]) => [
        key,
        schema.default ?? null,
    ]));
    if (definition.quantity_semantics !== 'unused' || Object.keys(definition.parameters).length > 0) {
        commandTrigger.value = trigger;
        pendingCommandContext.value = frozen;
        pendingDefinition.value = definition;
        void nextTick(() => {
            const focusTarget = commandDialog.value?.querySelector<HTMLElement>('input, select, button');
            focusTarget?.focus();
        });
        return;
    }
    pendingDefinition.value = null;
    pendingCommandContext.value = null;
    void addCommand(definition, definition.quantity_default ?? quantityContract.value.default, {}, frozen);
}

async function bulkInsert(action: 'clear_all' | 'level_all' | 'reclaim_clear_all' | 'reclaim_level_all'): Promise<void> {
    if (busy.value) return;
    const context = queueContext();
    beginMutation();
    try {
        const result = await api<{
            queue: CommandQueue;
            inserted_count: number;
            truncated_count: number;
            candidate_count: number;
        }>(`${basePath()}/command-queue/bulk`, {
            method: 'POST',
            body: JSON.stringify({
                action,
                position: selectedPosition.value,
                request_key: crypto.randomUUID(),
                expected_version: queue.value.version,
            }),
        });
        if (!isCurrentQueueContext(context)) {
            refreshRequestedAfterMutation = true;
            return;
        }
        applyServerQueue(result.queue);
        commandStatus.value = result.truncated_count > 0
            ? { kind: 'success', text: `${result.inserted_count}件を登録し、31件目以降の${result.truncated_count}件を末尾から切り捨てました` }
            : { kind: 'success', text: `${result.inserted_count}件を登録しました` };
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) refreshRequestedAfterMutation = true;
        else handleMutationError(error);
    } finally {
        await finishMutation();
    }
}

function confirmCancelFrom(): void {
    confirmation.value = {
        message: `開発計画の${selectedPosition.value}番以降をすべて削除します。この操作は元に戻せません。`,
        confirmLabel: 'ここから下を削除',
        action: () => {
            confirmation.value = null;
            void cancelFromSelected();
        },
    };
}

async function cancelFromSelected(): Promise<void> {
    if (busy.value) return;
    const context = queueContext();
    beginMutation();
    try {
        const result = await api<{ queue: CommandQueue; deleted_count: number }>(`${basePath()}/command-queue/from`, {
            method: 'DELETE',
            body: JSON.stringify({ position: selectedPosition.value, expected_version: queue.value.version }),
        });
        if (!isCurrentQueueContext(context)) {
            refreshRequestedAfterMutation = true;
            return;
        }
        applyServerQueue(result.queue);
        commandStatus.value = { kind: 'success', text: `${result.deleted_count}件を削除しました` };
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) refreshRequestedAfterMutation = true;
        else handleMutationError(error);
    } finally {
        await finishMutation();
    }
}

const parametersAreValid = computed(() => {
    const definition = pendingDefinition.value;
    if (definition === null) return true;

    return Object.entries(definition.parameters).every(([key, schema]) => {
        const value = commandParameters.value[key];
        if (value === null || value === undefined) return !schema.required || schema.nullable === true;
        if (!Number.isInteger(value) || value < schema.minimum || value > schema.maximum) return false;
        return schema.input_semantics !== 'nation_selector'
            || schema.options.some((option) => option.value === value);
    });
});

async function addPendingCommand(): Promise<void> {
    const definition = pendingDefinition.value;
    const frozen = pendingCommandContext.value;
    if (definition === null || frozen === null || !pendingQuantityIsValid.value || !parametersAreValid.value || pendingQuantity.value === null) return;
    const parameters: Record<string, number> = {};
    for (const [key, value] of Object.entries(commandParameters.value)) {
        if (value !== null) parameters[key] = value;
    }
    if (await addCommand(definition, pendingQuantity.value, parameters, frozen)) closePendingCommand();
}

async function addCommand(
    definition: CommandDefinition,
    requestedQuantity: number,
    parameters: Record<string, number>,
    frozen = freezeCommandContext(),
): Promise<boolean> {
    if (!definition.available) return false;
    if (busy.value) return false;
    const context: QueueContext = { nationId: frozen.nationId, mapSpaceId: frozen.mapSpaceId };
    const submittedPosition = frozen.position;
    const path = basePath(context.nationId, context.mapSpaceId);
    beginMutation();

    try {
        const result = await api<{ queue: CommandQueue }>(`${path}/command-queue`, {
            method: 'POST',
            body: JSON.stringify({
                command_key: definition.key,
                target_x: definition.target_type === 'cell' ? frozen.targetX : null,
                target_y: definition.target_type === 'cell' ? frozen.targetY : null,
                target_layer: definition.target_type === 'underground_slot' ? frozen.targetLayer : null,
                target_slot_index: definition.target_type === 'underground_slot' ? frozen.targetSlotIndex : null,
                position: submittedPosition,
                request_key: crypto.randomUUID(),
                expected_version: frozen.queueVersion,
                quantity: requestedQuantity,
                parameters,
            }),
        });
        if (!isCurrentQueueContext(context)) {
            refreshRequestedAfterMutation = true;
            return false;
        }
        applyServerQueue(result.queue);
        if (selectedPosition.value === submittedPosition) {
            selectedPosition.value = clampPosition(submittedPosition + 1, result.queue.limit);
        }
        setCommandSuccess();
        return true;
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) {
            refreshRequestedAfterMutation = true;
            return false;
        }
        handleMutationError(error);
        return false;
    } finally {
        await finishMutation();
    }
}

function closePendingCommand(): void {
    pendingDefinition.value = null;
    pendingCommandContext.value = null;
    const trigger = commandTrigger.value;
    commandTrigger.value = null;
    void nextTick(() => trigger?.focus());
}

function trapCommandDialogFocus(event: KeyboardEvent): void {
    if (event.key !== 'Tab' || commandDialog.value === null) return;
    const focusable = [...commandDialog.value.querySelectorAll<HTMLElement>(
        'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])',
    )];
    if (focusable.length === 0) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
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
    if (await mutateQueue('DELETE', `${basePath()}/command-queue/${itemId}`, { expected_version: queue.value.version })) {
        editingItem.value = null;
    }
}

function openQuantityEditor(item: CommandQueueItem): void {
    if (pendingDefinition.value !== null || item.quantity_semantics !== 'ordinary') return;
    editingItem.value = item;
    editingQuantity.value = item.quantity;
}

async function saveQuantity(): Promise<void> {
    const item = editingItem.value;
    if (item === null || !editingQuantityIsValid.value || editingQuantity.value === null) return;
    if (await mutateQueue('PATCH', `${basePath()}/command-queue/${item.id}`, {
        quantity: editingQuantity.value,
        expected_version: queue.value.version,
    })) editingItem.value = null;
}

function planKeydown(event: KeyboardEvent, slot: EffectivePlanSlot): void {
    if (event.key === 'Escape') {
        selectedPosition.value = clampPosition(selectedPosition.value, queue.value.limit);
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
    if ((event.key === 'Enter' || event.key.toLowerCase() === 'q') && slot.quantity_semantics === 'ordinary') {
        event.preventDefault();
        openQuantityEditor(slot);
    }
}

async function mutateQueue(method: 'PUT' | 'PATCH' | 'DELETE', path: string, body: object): Promise<boolean> {
    if (busy.value) return false;
    const context = queueContext();
    beginMutation();
    try {
        const nextQueue = await api<CommandQueue>(path, { method, body: JSON.stringify(body) });
        if (!isCurrentQueueContext(context)) {
            refreshRequestedAfterMutation = true;
            return false;
        }
        applyServerQueue(nextQueue);
        setCommandSuccess();
        return true;
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) {
            refreshRequestedAfterMutation = true;
            return false;
        }
        handleMutationError(error);
        synchronizeEditingItem(queue.value);
        return false;
    } finally {
        await finishMutation();
    }
}

function clampPosition(position: number, limit = queue.value.limit): number {
    return Math.max(1, Math.min(Math.max(1, limit), position));
}

function applyServerQueue(nextQueue: CommandQueue): void {
    queue.value = nextQueue;
    emit('queue', nextQueue);
    selectedPosition.value = clampPosition(selectedPosition.value, nextQueue.limit);
    synchronizeEditingItem(nextQueue);
}

function synchronizeEditingItem(nextQueue: CommandQueue): void {
    if (editingItem.value === null) return;
    const authoritative = nextQueue.items.find((item) => item.id === editingItem.value?.id);
    if (authoritative === undefined || authoritative.quantity_semantics !== 'ordinary') {
        editingItem.value = null;
        return;
    }
    editingItem.value = authoritative;
    editingQuantity.value = authoritative.quantity;
}

function quantityIsValid(value: number | null): value is number {
    return typeof value === 'number' && Number.isInteger(value)
        && value >= quantityContract.value.minimum
        && value <= quantityContract.value.maximum;
}

function hasSelectedTarget(definition: CommandDefinition): boolean {
    if (definition.target_type === 'cell') {
        return props.selected !== null && (props.selectedUnderground ?? null) === null;
    }
    if (definition.target_type === 'underground_slot') {
        return (props.selectedUnderground ?? null) !== null && props.selected === null;
    }

    return (props.selectedUnderground ?? null) === null;
}

function queueContext(): QueueContext {
    return { nationId: props.nationId, mapSpaceId: props.mapSpaceId };
}

function isCurrentQueueContext(context: QueueContext): boolean {
    return !disposed && context.nationId === props.nationId && context.mapSpaceId === props.mapSpaceId;
}

function beginMutation(): void {
    refreshGeneration++;
    activeRefreshController?.abort();
    activeRefreshController = null;
    refreshing.value = false;
    mutating.value = true;
}

async function finishMutation(): Promise<void> {
    while (!disposed) {
        await nextTick();
        if (!refreshRequestedAfterMutation) break;
        refreshRequestedAfterMutation = false;
        await refresh();
    }
    mutating.value = false;
}

function isAbortError(error: unknown): boolean {
    return error instanceof Error && error.name === 'AbortError';
}

function setCommandSuccess(): void {
    commandStatus.value = { kind: 'success', text: '送信完了' };
}

function setCommandError(reason: string): void {
    commandStatus.value = { kind: 'error', text: `送信エラー：${reason}` };
}

function handleMutationError(error: unknown): void {
    if (error instanceof ApiError && error.status === 409 && error.code === 'reset_required') {
        setCommandError('この島は現在のルールでは変更できません');
        return;
    }
    if (error instanceof ApiError && error.status === 409) {
        setCommandError('開発計画が更新されたため再読み込みしました');
        refreshRequestedAfterMutation = true;
        return;
    }
    setCommandError(playerFacingReason(error, '通信に失敗しました'));
    if (!(error instanceof ApiError) || error.status >= 500) refreshRequestedAfterMutation = true;
}

function playerFacingReason(error: unknown, fallback: string): string {
    if (!(error instanceof ApiError) || error.status !== 422) return fallback;
    const safeValidationMessage = Object.values(error.errors).flat().find((message) => message.trim() !== '');
    if (safeValidationMessage === undefined) return '入力内容を確認してください';
    const concise = safeValidationMessage.replace(/\s+/g, ' ').trim();
    if (concise === '') return fallback;
    return concise.length <= 100 ? concise : `${concise.slice(0, 99)}…`;
}

onBeforeUnmount(() => {
    disposed = true;
    refreshGeneration++;
    activeRefreshController?.abort();
    activeRefreshController = null;
});
</script>

<template>
    <div class="command-workspace">
        <aside class="command-panel" aria-label="セル情報と開発コマンド" :aria-busy="busy" :inert="pendingDefinition ? true : undefined">
            <div class="command-panel-body">
                <section v-if="selectedUnderground" class="underground-target-summary" aria-label="選択中の地下施設枠">
                    <p class="eyebrow">UNDERGROUND SLOT</p>
                    <h3>地下{{ selectedUnderground.layer }}層・slot {{ selectedUnderground.slot_index }}</h3>
                    <p>{{ selectedUnderground.coordinate_label }}</p>
                    <p>{{ selectedUnderground.facility_key === null ? '空き施設枠' : '建築済み施設枠' }}</p>
                </section>
                <CellDetails v-else :cell="selected" />
                <section v-if="ownShip" class="available-commands" aria-label="選択中の自国Ship操作">
                    <h3>Ship進路</h3>
                    <p v-if="(nationState ?? 'active') !== 'active'">休止・復興中は進路を変更できません。</p>
                    <form v-else class="parameter-popover" @submit.prevent="updateShipHeading">
                        <label>進行方向
                            <select v-model="shipHeading">
                                <option :value="null">random</option>
                                <option :value="0">東</option>
                                <option :value="1">北東</option>
                                <option :value="2">北西</option>
                                <option :value="3">西</option>
                                <option :value="4">南西</option>
                                <option :value="5">南東</option>
                            </select>
                        </label>
                        <button type="submit" :disabled="busy || shipHeading === ownShip.heading">進路を変更</button>
                        <p class="command-status" :class="`command-status--${shipStatus.kind}`" aria-live="polite">{{ shipStatus.text }}</p>
                    </form>
                </section>
                <section class="available-commands">
                    <h3>適用できるコマンド</h3>
                    <p
                        class="command-status"
                        :class="`command-status--${commandStatus.kind}`"
                        :role="commandStatus.kind === 'error' ? 'alert' : 'status'"
                        :aria-live="commandStatus.kind === 'error' ? 'assertive' : 'polite'"
                        aria-atomic="true"
                    >
                        {{ commandStatus.text }}
                    </p>
                    <div v-if="applicableDefinitions.length" class="command-grid">
                        <button
                            v-for="definition in applicableDefinitions"
                            :key="definition.key"
                            type="button"
                            :disabled="busy || !definition.available || queue.explicit_count >= queue.limit"
                            :title="definition.unavailable_reason ?? definition.description"
                            @click="chooseCommand(definition, $event)"
                        >
                            <strong>
                                {{ definition.name }}<span
                                    v-if="definition.command_suffix"
                                    :class="{ 'danger-suffix': definition.command_suffix_tone === 'danger' }"
                                >{{ definition.command_suffix }}</span>
                            </strong>
                            <span>{{ formatExactMoney(definition.cost_money) }}</span>
                            <span class="turn-cost-badge">{{ definition.consumes_turn ? '1ターン' : 'ターン消費なし' }}</span>
                            <span v-if="definition.initial_facility_capacity">初期 {{ definition.initial_facility_capacity.formatted }}</span>
                            <span v-if="definition.shortfall_money > 0" class="shortfall">資金が{{ formatExactMoney(definition.shortfall_money) }}不足</span>
                            <span v-for="warning in definition.execution_warnings" :key="warning" class="shortfall">{{ warning }}</span>
                        </button>
                    </div>
                    <p v-else class="empty-state">
                        {{ selectedUnderground ? 'この地下施設枠で登録できるコマンドはありません。' : 'このセルで登録できるコマンドはありません。' }}
                    </p>
                </section>
            </div>
        </aside>

        <aside class="plan-panel" aria-label="開発計画" :aria-busy="busy" :inert="pendingDefinition ? true : undefined">
            <div class="plan-panel-body">
                <div class="plan-heading">
                    <div>
                        <p class="eyebrow">DEVELOPMENT PLAN</p>
                        <h3>開発計画</h3>
                    </div>
                    <span>{{ queue.explicit_count }}件登録</span>
                </div>
                <div v-if="!selectedUnderground" class="bulk-actions" aria-label="開発計画の一括操作">
                    <button type="button" :disabled="busy" @click="bulkInsert('clear_all')">全て整地</button>
                    <button type="button" :disabled="busy" @click="bulkInsert('level_all')">全て地ならし</button>
                    <button type="button" :disabled="busy" @click="bulkInsert('reclaim_clear_all')">浅瀬全て埋め立て＋整地</button>
                    <button type="button" :disabled="busy" @click="bulkInsert('reclaim_level_all')">浅瀬全て埋め立て＋地ならし</button>
                    <button type="button" class="danger-action" :disabled="busy" @click="confirmCancelFrom">ここから下を削除</button>
                </div>
                <section v-if="selectedPlanItem" class="plan-selection-toolbar" aria-label="選択中の計画を編集">
                    <div>
                        <span>{{ selectedPlanItem.position }}番を選択中</span>
                        <strong>{{ selectedPlanItem.command_name }}</strong>
                    </div>
                    <div class="plan-selection-actions">
                        <button type="button" :disabled="busy || selectedPlanItem.position === 1" @click="move(selectedPlanItem.id, -1)">上へ</button>
                        <button type="button" :disabled="busy || selectedPlanItem.position === queue.limit" @click="move(selectedPlanItem.id, 1)">下へ</button>
                        <button v-if="selectedPlanItem.quantity_semantics === 'ordinary'" type="button" :disabled="busy" @click="openQuantityEditor(selectedPlanItem)">数量を変更</button>
                        <button type="button" class="danger-action" :disabled="busy" @click="cancel(selectedPlanItem.id)">取消</button>
                    </div>
                </section>
                <p v-else-if="selectedPlanSlot?.kind === 'automatic_finance'" class="queue-notice plan-selection-note">{{ selectedPlanSlot.position }}番は、空き枠で自動実行される資金繰りです。</p>
                <ol class="plan-list">
                    <li
                        v-for="slot in queue.plan"
                        :key="slot.kind === 'explicit' ? `item-${slot.id}` : `auto-${slot.position}`"
                        class="plan-row"
                        :class="{ selected: selectedPosition === slot.position, automatic: slot.kind === 'automatic_finance' }"
                        :draggable="slot.kind === 'explicit' && !busy"
                        tabindex="0"
                        :aria-current="selectedPosition === slot.position ? 'true' : undefined"
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
                            <strong>
                                {{ slot.command_name }}
                                <span
                                    v-if="slot.kind === 'explicit' && slot.command_suffix"
                                    :class="{ 'danger-suffix': slot.command_suffix_tone === 'danger' }"
                                >{{ slot.command_suffix }}</span>
                                <template v-if="slot.kind === 'explicit' && slot.quantity_semantics === 'ordinary'"> ×{{ slot.quantity }}</template>
                                <template v-else-if="slot.kind === 'explicit' && slot.quantity_semantics === 'selector'">（{{ slot.quantity_label }}）</template>
                                <span class="plan-turn-marker">{{ slot.consumes_turn ? '1T' : '自動' }}</span>
                            </strong>
                            <small v-if="slot.kind === 'explicit' && slot.target_context === 'underground_slot'">
                                地下{{ slot.target_layer }}層・slot {{ slot.target_slot_index }}
                            </small>
                            <small v-else-if="slot.kind === 'explicit'">
                                x={{ slot.target_x }}, y={{ slot.target_y }}
                            </small>
                            <small v-else>ターン消費なし</small>
                        </span>
                    </li>
                </ol>
                <form v-if="editingItem" class="parameter-popover plan-parameter-popover" @submit.prevent="saveQuantity">
                    <strong>{{ editingItem.command_name }}の数量</strong>
                    <div class="preset-row">
                        <button v-for="preset in quantityContract.quick_presets" :key="preset" type="button" @click="editingQuantity = preset">{{ preset }}</button>
                    </div>
                    <label>数量
                        <input v-model.number="editingQuantity" type="number" step="1" :min="quantityContract.minimum" :max="quantityContract.maximum" required>
                    </label>
                    <div class="popover-actions">
                        <button type="button" @click="editingItem = null">閉じる</button>
                        <button type="submit" :disabled="busy || !editingQuantityIsValid">保存</button>
                    </div>
                </form>
            </div>
        </aside>
        <div v-if="pendingDefinition && pendingCommandContext" class="command-entry-backdrop" role="presentation">
            <section
                ref="commandDialog"
                class="command-entry-sheet parameter-popover"
                role="dialog"
                aria-modal="true"
                aria-labelledby="command-entry-title"
                @keydown.esc.stop.prevent="closePendingCommand"
                @keydown.tab="trapCommandDialogFocus"
            >
                <header class="command-entry-heading">
                    <div>
                        <p class="eyebrow">COMMAND INPUT</p>
                        <h3 id="command-entry-title">{{ pendingDefinition.name }}</h3>
                    </div>
                    <button type="button" aria-label="入力を閉じる" @click="closePendingCommand">×</button>
                </header>
                <dl class="command-entry-context">
                    <div><dt>対象</dt><dd>{{ pendingTargetLabel }}</dd></div>
                    <div><dt>計画位置</dt><dd>{{ pendingCommandContext.position }}番</dd></div>
                </dl>
                <p v-if="commandStatus.kind === 'error'" class="command-status command-status--error" role="alert" aria-live="assertive">
                    {{ commandStatus.text }}
                </p>
                <form @submit.prevent="addPendingCommand">
                    <label v-if="pendingDefinition.quantity_semantics === 'selector'">種類
                        <select v-model.number="pendingQuantity" required>
                            <option :value="null" disabled>選択してください</option>
                            <option v-for="option in pendingDefinition.quantity_options" :key="option.key" :value="option.value">
                                {{ option.label }}<template v-if="option.cost_money !== undefined">（{{ formatExactMoney(option.cost_money) }}）</template>
                            </option>
                        </select>
                    </label>
                    <p v-if="pendingDefinition.quantity_semantics === 'selector'" class="selector-cost">必要資金 {{ formatExactMoney(pendingCostMoney) }}</p>
                    <template v-else-if="pendingDefinition.quantity_semantics === 'ordinary'">
                        <div class="preset-row" aria-label="数量の候補">
                            <button v-for="preset in quantityContract.quick_presets" :key="preset" type="button" @click="pendingQuantity = preset">{{ preset }}</button>
                        </div>
                        <label>数量
                            <input v-model.number="pendingQuantity" type="number" step="1" :min="quantityContract.minimum" :max="quantityContract.maximum" required>
                        </label>
                    </template>
                    <label v-for="(schema, key) in pendingDefinition.parameters" :key="key">
                        {{ schema.label }}
                        <select v-if="schema.input_semantics === 'nation_selector'" v-model.number="commandParameters[key]" class="nation-target-select" :required="schema.required && !schema.nullable">
                            <option :value="null">対象島なし</option>
                            <option v-for="option in schema.options" :key="option.value" :value="option.value">{{ option.label }} ({{ option.nation_number }})</option>
                        </select>
                        <input v-else v-model.number="commandParameters[key]" type="number" step="1" :min="schema.minimum" :max="schema.maximum" :required="schema.required && !schema.nullable">
                    </label>
                    <div class="popover-actions">
                        <button type="button" @click="closePendingCommand">キャンセル</button>
                        <button type="submit" :disabled="busy || !pendingQuantityIsValid || !parametersAreValid">計画へ登録</button>
                    </div>
                </form>
            </section>
        </div>
        <div v-if="confirmation" class="command-modal-backdrop" role="presentation" @click.self="confirmation = null">
            <section class="command-modal" role="alertdialog" aria-modal="true" aria-labelledby="command-confirmation-title">
                <h3 id="command-confirmation-title">確認</h3>
                <p>{{ confirmation.message }}</p>
                <div class="popover-actions">
                    <button type="button" @click="confirmation = null">キャンセル</button>
                    <button type="button" class="danger-action" @click="confirmation.action">{{ confirmation.confirmLabel }}</button>
                </div>
            </section>
        </div>
    </div>
</template>
