<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import { formatExactMoney } from '../formatters/money';
import type {
    CommandCatalog,
    CommandDefinition,
    CommandQueue,
    CommandQueueItem,
    DailyQuestProgress,
    EffectivePlanSlot,
    MapCell,
    Nation,
    ParadoxBalance,
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
    targetShipId: number | null;
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
    dailyQuest: [quest: DailyQuestProgress];
    paradox: [balance: ParadoxBalance | null];
}>();

const definitions = ref<CommandDefinition[]>([]);
const paradox = ref<ParadoxBalance | null>(null);
const ALL_COMMANDS_STORAGE_KEY = 'hakoniwa-surface-all-commands';
const allCommands = ref(readAllCommandsPreference());
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
const selectedItemId = ref<number | null>(null);
// 行を選んでいないあいだは、入れる位置が計画の末尾に付いていく。
const cursorPinned = ref(false);
const bulkOpen = ref(false);
const pendingNeedsConfirmation = ref(false);
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
// いま取りに行っている（または取り終えた）コマンド一覧の条件。同じ条件での取り直しを省く。
let requestedRefreshKey: string | null = null;
let requestedTargetKey: string | null = null;
let loadedQueueContext: string | null = null;
let activeRefreshController: AbortController | null = null;
// 変更の送信中に頼まれた取り直し。0: なし、1: コマンド一覧だけ、2: 計画も読み直す。
let refreshAfterMutation: 0 | 1 | 2 = 0;
let activeRefreshPositionOnly = false;
let disposed = false;

const basePath = (nationId = props.nationId, mapSpaceId = props.mapSpaceId) => `/api/v1/nations/${nationId}/map-spaces/${mapSpaceId}`;
// いまの地形では合わないコマンド。資金・輝石の不足だけの物は含めない（不足でも予約できる）。
function isLookahead(definition: CommandDefinition): boolean {
    return definition.execution_preview_status === 'currently_unavailable'
        && definition.shortfall_money === 0
        && (definition.shortfall_paradox ?? 0) === 0;
}
const listedDefinitions = computed(() => definitions.value.filter(
    (definition) => definition.applicable && (allCommands.value || !isLookahead(definition)),
));
const commandGroups = computed(() => ([
    { key: 'normal', label: '通常' },
    { key: 'paradox', label: '輝石' },
] as const).map((group) => ({
    ...group,
    definitions: listedDefinitions.value.filter((definition) => (definition.command_group ?? 'normal') === group.key),
})).filter((group) => group.definitions.length > 0));
const tailPosition = computed(() => {
    const limit = Math.max(1, queue.value.limit);
    const last = queue.value.items.reduce((position, item) => Math.max(position, item.queue_position), 0);
    if (last < limit) return last + 1;
    return queue.value.plan.find((slot) => slot.kind === 'automatic_finance')?.position ?? limit;
});
// 行を選んでいるとその後ろへ、空き枠を選んでいるとその枠へ、選んでいなければ末尾へ入る。
const insertPosition = computed(() => {
    if (!cursorPinned.value) return tailPosition.value;
    return clampPosition(selectedItemId.value === null ? selectedPosition.value : selectedPosition.value + 1);
});
const bulkPosition = computed(() => cursorPinned.value ? selectedPosition.value : 1);
const queuedOnSelected = computed(() => {
    const cell = props.selected;
    if (cell === null || (props.selectedUnderground ?? null) !== null) return [];
    return queue.value.items
        .filter((item) => item.target_context === 'surface_cell' && item.target_x === cell.x && item.target_y === cell.y)
        .sort((left, right) => left.queue_position - right.queue_position);
});
const queueIsFull = computed(() => queue.value.explicit_count >= queue.value.limit);
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
const selectedPlanSlot = computed(() => cursorPinned.value
    ? queue.value.plan.find((slot) => slot.position === selectedPosition.value) ?? null
    : null);
const selectedPlanItem = computed(() => {
    if (!cursorPinned.value) return null;
    const slot = queue.value.plan.find((slot) => slot.kind === 'explicit' && slot.id === selectedItemId.value);
    return slot?.kind === 'explicit' ? slot : null;
});
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

function targetKey(): string {
    return [
        props.nationId,
        props.mapSpaceId,
        props.selected?.x,
        props.selected?.y,
        props.selectedUnderground?.layer,
        props.selectedUnderground?.slot_index,
    ].join(':');
}

function refreshKey(position = insertPosition.value): string {
    return `${targetKey()}:${position}`;
}

watch(
    () => refreshKey(),
    (key) => {
        if (key !== requestedRefreshKey) requestRefresh(targetKey() === requestedTargetKey);
    },
    { immediate: true },
);

// positionOnly: 入れる位置だけが変わったとき。計画は手元の物が最新なので、コマンド一覧だけ取り直す。
function requestRefresh(positionOnly = false): void {
    if (mutating.value) {
        refreshAfterMutation = Math.max(refreshAfterMutation, positionOnly ? 1 : 2) as 0 | 1 | 2;
        return;
    }
    void refresh(positionOnly);
}

async function refresh(positionOnly = false): Promise<void> {
    const generation = ++refreshGeneration;
    activeRefreshController?.abort();
    const controller = new AbortController();
    activeRefreshController = controller;
    activeRefreshPositionOnly = positionOnly && loadedQueueContext === `${props.nationId}:${props.mapSpaceId}`;
    const underground = props.selectedUnderground ?? null;
    const selected = underground !== null || props.selected === null
        ? null
        : { x: props.selected.x, y: props.selected.y };
    const path = basePath(props.nationId, props.mapSpaceId);
    const queueContextKey = `${props.nationId}:${props.mapSpaceId}`;
    const definitionsUrl = (position: number): string => {
        const query = new URLSearchParams({ position: String(position) });
        if (selected !== null) {
            query.set('target_x', String(selected.x));
            query.set('target_y', String(selected.y));
        } else if (underground !== null) {
            query.set('target_layer', String(underground.layer));
            query.set('target_slot_index', String(underground.slot_index));
        }
        return `${path}/command-definitions?${query}`;
    };

    if (!activeRefreshPositionOnly) refreshing.value = true;
    try {
        let nextDefinitions: CommandCatalog;
        let nextQueue: CommandQueue | null = null;
        requestedTargetKey = targetKey();
        if (loadedQueueContext !== queueContextKey) {
            // 入れる位置（末尾）は計画を読むまで分からないので、最初だけ計画を先に読む。
            nextQueue = await api<CommandQueue>(`${path}/command-queue`, { signal: controller.signal });
            if (generation !== refreshGeneration) return;
            loadedQueueContext = queueContextKey;
            releaseCursor();
            applyServerQueue(nextQueue);
            requestedRefreshKey = refreshKey();
            nextDefinitions = await api<CommandCatalog>(definitionsUrl(insertPosition.value), { signal: controller.signal });
            nextQueue = null;
        } else if (activeRefreshPositionOnly) {
            requestedRefreshKey = refreshKey();
            nextDefinitions = await api<CommandCatalog>(definitionsUrl(insertPosition.value), { signal: controller.signal });
        } else {
            requestedRefreshKey = refreshKey();
            [nextDefinitions, nextQueue] = await Promise.all([
                api<CommandCatalog>(definitionsUrl(insertPosition.value), { signal: controller.signal }),
                api<CommandQueue>(`${path}/command-queue`, { signal: controller.signal }),
            ]);
        }

        if (generation !== refreshGeneration) return;
        definitions.value = nextDefinitions.commands;
        paradox.value = nextDefinitions.paradox ?? null;
        emit('paradox', paradox.value);
        quantityContract.value = nextDefinitions.quantity_contract;
        if (nextQueue !== null) applyServerQueue(nextQueue);
    } catch (error) {
        if (generation !== refreshGeneration || isAbortError(error)) return;
        requestedRefreshKey = null;
        requestedTargetKey = null;
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
        targetShipId: selected?.ship?.is_owner === true ? selected.ship.id : null,
        targetLayer: underground?.layer ?? null,
        targetSlotIndex: underground?.slot_index ?? null,
        position: insertPosition.value,
        queueVersion: queue.value.version,
    };
}

function prepareCommand(
    definition: CommandDefinition,
    trigger: HTMLElement | null = null,
    frozen = freezeCommandContext(),
): void {
    pendingNeedsConfirmation.value = false;
    pendingQuantity.value = definition.quantity_default;
    commandParameters.value = Object.fromEntries(Object.entries(definition.parameters).map(([key, schema]) => [
        key,
        schema.default ?? null,
    ]));
    if (definition.quantity_semantics !== 'unused' || Object.keys(definition.parameters).length > 0 || definition.execution_warnings.length > 0) {
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

type BulkAction = 'clear_all' | 'level_all' | 'reclaim_clear_all' | 'reclaim_level_all';

// 一括は入れる位置から差し込み、元の計画をその下へ送る。上限を超えた分は末尾から押し出されて消えるので、
// 後ろに計画があるときは送る前に確かめる。正確な件数はserverしか知らないため、ここでは数えない。
function requestBulk(action: BulkAction): void {
    bulkOpen.value = false;
    if (busy.value) return;
    const position = bulkPosition.value;
    if (!queue.value.items.some((item) => item.queue_position >= position)) {
        void bulkInsert(action, position);
        return;
    }
    confirmation.value = {
        message: `計画の${position}番から一括で入れ、いまの計画はその下へ送ります。空きは${queue.value.limit - queue.value.explicit_count}件です。入りきらない分は末尾の計画から押し出されて消えます。入れますか？`,
        confirmLabel: '一括で入れる',
        action: () => {
            confirmation.value = null;
            void bulkInsert(action, position);
        },
    };
}

async function bulkInsert(action: BulkAction, position = bulkPosition.value): Promise<void> {
    if (busy.value) return;
    const context = queueContext();
    beginMutation();
    try {
        const result = await api<{
            queue: CommandQueue;
            inserted_count: number;
            truncated_count: number;
            candidate_count: number;
            daily_quest: DailyQuestProgress | null;
        }>(`${basePath()}/command-queue/bulk`, {
            method: 'POST',
            body: JSON.stringify({
                action,
                position,
                request_key: crypto.randomUUID(),
                expected_version: queue.value.version,
            }),
        });
        if (!isCurrentQueueContext(context)) {
            refreshAfterMutation = 2;
            return;
        }
        applyServerQueue(result.queue);
        if (result.daily_quest?.completed_now === true) emit('dailyQuest', result.daily_quest);
        commandStatus.value = result.truncated_count > 0
            ? { kind: 'success', text: `${result.inserted_count}件を${position}番から入れ、${result.truncated_count}件が押し出されて消えました` }
            : { kind: 'success', text: `${result.inserted_count}件を${position}番から入れました` };
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) refreshAfterMutation = 2;
        else handleMutationError(error);
    } finally {
        await finishMutation();
    }
}

function confirmCancelFrom(): void {
    bulkOpen.value = false;
    if (!cursorPinned.value) return;
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
            refreshAfterMutation = 2;
            return;
        }
        applyServerQueue(result.queue);
        commandStatus.value = { kind: 'success', text: `${result.deleted_count}件を削除しました` };
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) refreshAfterMutation = 2;
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
    if (pendingNeedsConfirmation.value) return;
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
        const result = await api<{ queue: CommandQueue; daily_quest?: DailyQuestProgress | null }>(`${path}/command-queue`, {
            method: 'POST',
            body: JSON.stringify({
                command_key: definition.key,
                target_x: definition.target_type === 'cell' ? frozen.targetX : null,
                target_y: definition.target_type === 'cell' ? frozen.targetY : null,
                ...(definition.key === 'scuttle_ship' ? { target_ship_id: frozen.targetShipId } : {}),
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
            refreshAfterMutation = 2;
            return false;
        }
        // 行を選んで入れたときは、入れた行を選び直す（続けて入れると、その後ろへ順に並ぶ）。
        if (cursorPinned.value && insertPosition.value === submittedPosition) {
            selectedItemId.value = result.queue.items.find((item) => item.queue_position === submittedPosition)?.id ?? null;
            selectedPosition.value = clampPosition(submittedPosition, result.queue.limit);
        }
        applyServerQueue(result.queue);
        if (result.daily_quest?.completed_now === true) emit('dailyQuest', result.daily_quest);
        commandStatus.value = { kind: 'success', text: `計画の${submittedPosition}番に入れました` };
        return true;
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) {
            refreshAfterMutation = 2;
            return false;
        }
        handleMutationError(error);
        return false;
    } finally {
        await finishMutation();
    }
}

function closePendingCommand(): void {
    pendingNeedsConfirmation.value = false;
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
    if (cursorPinned.value && selectedPosition.value === slot.position) {
        releaseCursor();
        return;
    }
    cursorPinned.value = true;
    selectedPosition.value = slot.position;
    selectedItemId.value = slot.kind === 'explicit' ? slot.id : null;
}

function releaseCursor(): void {
    cursorPinned.value = false;
    selectedItemId.value = null;
    editingItem.value = null;
}

function readAllCommandsPreference(): boolean {
    try {
        return window.localStorage.getItem(ALL_COMMANDS_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function setAllCommands(value: boolean): void {
    allCommands.value = value;
    try {
        window.localStorage.setItem(ALL_COMMANDS_STORAGE_KEY, value ? '1' : '');
    } catch {
        // 保存できなくても動く
    }
}

function beginDrag(slot: EffectivePlanSlot): void {
    draggedItemId.value = slot.kind === 'explicit' ? slot.id : null;
}

async function dropAt(target: EffectivePlanSlot): Promise<void> {
    const sourceId = draggedItemId.value;
    draggedItemId.value = null;
    if (sourceId === null) return;
    cursorPinned.value = true;
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
    }, sourceId);
}

async function move(itemId: number, delta: number): Promise<void> {
    cursorPinned.value = true;
    selectedItemId.value = itemId;
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

async function mutateQueue(method: 'PUT' | 'PATCH' | 'DELETE', path: string, body: object, followItemId = selectedItemId.value): Promise<boolean> {
    if (busy.value) return false;
    const context = queueContext();
    beginMutation();
    try {
        const nextQueue = await api<CommandQueue>(path, { method, body: JSON.stringify(body) });
        if (!isCurrentQueueContext(context)) {
            refreshAfterMutation = 2;
            return false;
        }
        selectedItemId.value = followItemId;
        applyServerQueue(nextQueue);
        setCommandSuccess();
        return true;
    } catch (error) {
        if (!isCurrentQueueContext(context) || isAbortError(error)) {
            refreshAfterMutation = 2;
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
    if (selectedItemId.value !== null) {
        const selected = nextQueue.items.find((item) => item.id === selectedItemId.value);
        if (selected) selectedPosition.value = selected.queue_position;
        else selectedItemId.value = null;
    }
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
    // 取り直しの途中で変更を送るときは、変更のあとで同じ取り直しをやり直す。
    if (activeRefreshController !== null) {
        refreshAfterMutation = Math.max(refreshAfterMutation, activeRefreshPositionOnly ? 1 : 2) as 0 | 1 | 2;
        requestedRefreshKey = null;
    }
    activeRefreshController?.abort();
    activeRefreshController = null;
    refreshing.value = false;
    mutating.value = true;
}

async function finishMutation(): Promise<void> {
    while (!disposed) {
        await nextTick();
        if (refreshAfterMutation === 0) break;
        const positionOnly = refreshAfterMutation === 1;
        refreshAfterMutation = 0;
        await refresh(positionOnly);
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
        if (pendingCommandContext.value !== null) pendingNeedsConfirmation.value = true;
        setCommandError('開発計画が更新されたため再読み込みしました');
        refreshAfterMutation = 2;
        return;
    }
    setCommandError(playerFacingReason(error, '通信に失敗しました'));
    if (!(error instanceof ApiError) || error.status >= 500) refreshAfterMutation = 2;
}

function confirmPendingPlan(): void {
    const context = pendingCommandContext.value;
    if (busy.value || context === null || !isCurrentQueueContext(context) || queue.value.version <= context.queueVersion) return;
    pendingCommandContext.value = { ...context, queueVersion: queue.value.version };
    pendingNeedsConfirmation.value = false;
    commandStatus.value = { kind: 'idle', text: '最新の計画を確認しました。入力内容を確認して登録してください' };
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
        <aside class="command-panel sl-inspect" aria-label="セル情報と開発コマンド" :aria-busy="busy" :inert="pendingDefinition ? true : undefined">
            <section v-if="selectedUnderground" class="underground-target-summary sl-cell-info" aria-label="選択中の地下施設枠">
                <h3>地下{{ selectedUnderground.layer }}層・slot {{ selectedUnderground.slot_index }}</h3>
                <p>{{ selectedUnderground.coordinate_label }}・{{ selectedUnderground.facility_key === null ? '空き施設枠' : '建築済み施設枠' }}</p>
            </section>
            <CellDetails v-else :cell="selected" />
            <p v-if="queuedOnSelected.length" class="sl-queued"><b>予約</b>{{ queuedOnSelected.map((item) => `${item.queue_position}番 ${item.command_name}`).join('、') }}</p>
            <section v-if="ownShip" class="sl-ship" aria-label="選択中の自国Ship操作">
                <p v-if="(nationState ?? 'active') !== 'active'">休止・復興中は進路を変更できません。</p>
                <form v-else @submit.prevent="updateShipHeading">
                    <label for="sl-ship-heading">船の進路</label>
                    <select id="sl-ship-heading" v-model="shipHeading">
                        <option :value="null">random</option>
                        <option :value="0">東</option>
                        <option :value="1">北東</option>
                        <option :value="2">北西</option>
                        <option :value="3">西</option>
                        <option :value="4">南西</option>
                        <option :value="5">南東</option>
                    </select>
                    <button type="submit" :disabled="busy || shipHeading === ownShip.heading">進路を変更</button>
                    <p class="command-status" :class="`command-status--${shipStatus.kind}`" aria-live="polite">{{ shipStatus.text }}</p>
                </form>
            </section>
            <section class="available-commands sl-cmd-area">
                <div class="sl-cmd-head">
                    <h3>使えるコマンド</h3>
                    <label v-if="selected || selectedUnderground" class="sl-all" title="いまの地形では使えないコマンドも出します">
                        <input type="checkbox" :checked="allCommands" @change="setAllCommands(($event.target as HTMLInputElement).checked)"> 全部出す
                    </label>
                    <span class="sl-legend" aria-hidden="true"><span><i class="sl-mk" /> ターンを使う</span><span><i class="sl-mk free" /> 使わない</span></span>
                </div>
                <p class="sl-insert-at">
                    <span v-if="selectedPlanItem">入れる位置 <b class="num">{{ insertPosition }}番</b>（{{ selectedPlanItem.position }}番 {{ selectedPlanItem.command_name }}の後ろ）</span>
                    <span v-else-if="cursorPinned">入れる位置 <b class="num">{{ insertPosition }}番</b>（選んだ空き枠）</span>
                    <span v-else>入れる位置 <b class="num">{{ insertPosition }}番</b>（末尾）</span>
                    <button v-if="cursorPinned" type="button" class="sl-quiet" @click="releaseCursor">末尾に戻す</button>
                </p>
                <p
                    class="command-status"
                    :class="`command-status--${commandStatus.kind}`"
                    :role="commandStatus.kind === 'error' ? 'alert' : 'status'"
                    :aria-live="commandStatus.kind === 'error' ? 'assertive' : 'polite'"
                    aria-atomic="true"
                >
                    {{ commandStatus.text }}
                </p>
                <p v-if="queueIsFull" class="sl-empty">計画が{{ queue.limit }}件で一杯です。どれかを取り消すと追加できます。</p>
                <template v-for="group in commandGroups" :key="group.key">
                    <div class="sl-cmd-head sub">
                        <span>{{ group.label }}</span>
                        <span v-if="group.key === 'paradox' && paradox" class="pd num" :title="paradox.description">{{ paradox.name }} {{ paradox.balance.toLocaleString() }} {{ paradox.unit }}</span>
                    </div>
                    <div class="command-grid sl-cmds">
                        <button
                            v-for="definition in group.definitions"
                            :key="definition.key"
                            type="button"
                            class="sl-cmd"
                            :class="{ short: definition.shortfall_money > 0 || (definition.shortfall_paradox ?? 0) > 0, later: isLookahead(definition) }"
                            :disabled="busy || !definition.available || queueIsFull"
                            :title="definition.unavailable_reason ?? definition.description"
                            @click="chooseCommand(definition, $event)"
                        >
                            <i class="sl-mk" :class="{ free: !definition.consumes_turn }" :title="definition.consumes_turn ? 'ターンを使う' : 'ターンを使わない'" />
                            <span class="nm">
                                {{ definition.name }}<small
                                    v-if="definition.command_suffix"
                                    :class="{ 'danger-suffix': definition.command_suffix_tone === 'danger' }"
                                >{{ definition.command_suffix }}</small>
                                <small v-if="isLookahead(definition)" class="ltr" title="いまの地形では使えません。前の計画で地形が変わる前提で入れます。">先読み</small>
                                <small v-else-if="definition.execution_preview_status === 'executable_after_queue'" class="after" title="前の計画のあとなら実行できます。">計画後</small>
                                <small v-if="definition.initial_facility_capacity" class="cap">初期 {{ definition.initial_facility_capacity.formatted }}</small>
                            </span>
                            <span class="cost">
                                <span>{{ formatExactMoney(definition.cost_money) }}<em v-if="(definition.cost_paradox ?? 0) > 0"> {{ definition.cost_paradox }} Pd</em></span>
                                <span v-if="definition.shortfall_money > 0" class="shortfall">資金が{{ formatExactMoney(definition.shortfall_money) }}不足</span>
                                <span v-if="(definition.shortfall_paradox ?? 0) > 0" class="shortfall">輝石が{{ definition.shortfall_paradox }} Pd不足</span>
                            </span>
                        </button>
                    </div>
                </template>
                <p v-if="commandGroups.length === 0" class="empty-state sl-empty">
                    {{ selectedUnderground ? 'この地下施設枠で登録できるコマンドはありません。' : selected ? 'このセルでいま使えるコマンドはありません。「全部出す」で先読みのコマンドを出せます。' : '地図のマスを選ぶと、そのマスで使えるコマンドが出ます。' }}
                </p>
            </section>
        </aside>

        <aside class="plan-panel sl-plan" aria-label="開発計画" :aria-busy="busy" :inert="pendingDefinition ? true : undefined">
            <header class="plan-heading sl-pane-head">
                <h2>開発計画</h2>
                <span class="sl-plan-count num">{{ queue.explicit_count }}/{{ queue.limit }}件</span>
                <button v-if="!selectedUnderground" type="button" class="sl-quiet" :aria-expanded="bulkOpen" aria-controls="sl-bulk-menu" @click="bulkOpen = !bulkOpen">一括</button>
            </header>
            <div v-if="!selectedUnderground" v-show="bulkOpen" id="sl-bulk-menu" class="bulk-actions sl-menu" aria-label="開発計画の一括操作">
                <button type="button" :disabled="busy" @click="requestBulk('level_all')">荒地と焦土を全て地ならし</button>
                <button type="button" :disabled="busy" @click="requestBulk('clear_all')">荒地と焦土を全て整地</button>
                <button type="button" :disabled="busy" @click="requestBulk('reclaim_level_all')">浅瀬を全て埋め立て＋地ならし</button>
                <button type="button" :disabled="busy" @click="requestBulk('reclaim_clear_all')">浅瀬を全て埋め立て＋整地</button>
                <button type="button" class="danger-action" :disabled="busy || !cursorPinned" :title="cursorPinned ? undefined : '先に、取り消しを始める行を選んでください。'" @click="confirmCancelFrom">選んだ行から下を全て取消</button>
            </div>
            <section v-if="selectedPlanItem" class="plan-selection-toolbar sl-plan-tools" aria-label="選択中の計画を編集">
                <div class="sl-plan-tools-name">
                    <span>{{ selectedPlanItem.position }}番を選択中</span>
                    <strong>{{ selectedPlanItem.command_name }}</strong>
                </div>
                <div class="plan-selection-actions">
                    <button type="button" :disabled="busy || selectedPlanItem.position === 1" class="up" @click="move(selectedPlanItem.id, -1)">上へ</button>
                    <button type="button" :disabled="busy || selectedPlanItem.position === queue.limit" class="down" @click="move(selectedPlanItem.id, 1)">下へ</button>
                    <button v-if="selectedPlanItem.quantity_semantics === 'ordinary'" type="button" :disabled="busy" @click="openQuantityEditor(selectedPlanItem)">数量を変更</button>
                    <button type="button" class="danger-action" :disabled="busy" @click="cancel(selectedPlanItem.id)">取消</button>
                </div>
            </section>
            <p v-else-if="selectedPlanSlot?.kind === 'automatic_finance'" class="queue-notice plan-selection-note sl-plan-note">{{ selectedPlanSlot.position }}番は空き枠です（何も入れなければ資金繰り）。次のコマンドはここに入ります。</p>
            <form v-if="editingItem" class="parameter-popover plan-parameter-popover sl-qty" @submit.prevent="saveQuantity">
                <strong>{{ editingItem.command_name }}の数量</strong>
                <div class="preset-row">
                    <button v-for="preset in quantityContract.quick_presets" :key="preset" type="button" @click="editingQuantity = preset">{{ preset }}</button>
                </div>
                <label>数量
                    <input v-model.number="editingQuantity" type="number" step="1" :min="quantityContract.minimum" :max="quantityContract.maximum" required>
                </label>
                <div class="popover-actions">
                    <button type="button" class="sl-quiet" @click="editingItem = null">閉じる</button>
                    <button type="submit" class="sl-primary" :disabled="busy || !editingQuantityIsValid">保存</button>
                </div>
            </form>
            <ol class="plan-list sl-plan-body">
                <li
                    v-for="slot in queue.plan"
                    :key="slot.kind === 'explicit' ? `item-${slot.id}` : `auto-${slot.position}`"
                    class="plan-row sl-pr"
                    :class="{ selected: cursorPinned && selectedPosition === slot.position, cursor: insertPosition === slot.position, automatic: slot.kind === 'automatic_finance' }"
                    :draggable="slot.kind === 'explicit' && !busy"
                    tabindex="0"
                    :aria-current="cursorPinned && selectedPosition === slot.position ? 'true' : undefined"
                    @click="selectPlanSlot(slot)"
                    @dblclick="slot.kind === 'explicit' && openQuantityEditor(slot)"
                    @contextmenu.prevent="slot.kind === 'explicit' && cancel(slot.id)"
                    @keydown="planKeydown($event, slot)"
                    @dragstart="beginDrag(slot)"
                    @dragover.prevent
                    @drop.prevent="dropAt(slot)"
                >
                    <span class="plan-position sl-pr-n">{{ slot.position }}</span>
                    <i
                        class="plan-turn-marker sl-mk"
                        :class="{ free: !slot.consumes_turn }"
                        role="img"
                        :aria-label="slot.kind === 'automatic_finance' ? '自動' : slot.consumes_turn ? 'ターンを使う' : 'ターンを使わない'"
                        :title="slot.kind === 'automatic_finance' ? '自動' : slot.consumes_turn ? 'ターンを使う' : 'ターンを使わない'"
                    />
                    <span class="plan-command sl-pr-name">
                        {{ slot.command_name }}<span
                            v-if="slot.kind === 'explicit' && slot.command_suffix"
                            :class="{ 'danger-suffix': slot.command_suffix_tone === 'danger' }"
                        >{{ slot.command_suffix }}</span>
                        <template v-if="slot.kind === 'explicit' && slot.quantity_semantics === 'ordinary'"> ×{{ slot.quantity }}</template>
                        <template v-else-if="slot.kind === 'explicit' && slot.quantity_semantics === 'selector'">（{{ slot.quantity_label }}）</template>
                    </span>
                    <span v-if="slot.kind === 'explicit' && slot.target_context === 'underground_slot'" class="sl-pr-at">地下{{ slot.target_layer }}層・slot {{ slot.target_slot_index }}</span>
                    <span v-else-if="slot.kind === 'explicit' && slot.target_x !== null && slot.target_y !== null" class="sl-pr-at">({{ slot.target_x }}, {{ slot.target_y }})</span>
                    <span v-else-if="slot.kind === 'explicit'" class="sl-pr-at">島全体</span>
                    <span v-else class="sl-pr-at">空き枠</span>
                </li>
            </ol>
        </aside>
        <div v-if="pendingDefinition && pendingCommandContext" class="command-entry-backdrop sl-backdrop" role="presentation">
            <section
                ref="commandDialog"
                class="command-entry-sheet parameter-popover sl-dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="command-entry-title"
                @keydown.esc.stop.prevent="closePendingCommand"
                @keydown.tab="trapCommandDialogFocus"
            >
                <header class="command-entry-heading">
                    <h3 id="command-entry-title"><i class="sl-mk" :class="{ free: !pendingDefinition.consumes_turn }" aria-hidden="true" /> {{ pendingDefinition.name }}</h3>
                    <button type="button" aria-label="入力を閉じる" @click="closePendingCommand">×</button>
                </header>
                <dl class="command-entry-context">
                    <div><dt>対象</dt><dd>{{ pendingTargetLabel }}</dd></div>
                    <div><dt>入れる位置</dt><dd>{{ pendingCommandContext.position }}番</dd></div>
                    <div><dt>ターン</dt><dd>{{ pendingDefinition.consumes_turn ? '使う' : '使わない' }}</dd></div>
                </dl>
                <p v-if="commandStatus.kind === 'error'" class="command-status command-status--error" role="alert" aria-live="assertive">
                    {{ commandStatus.text }}
                </p>
                <form @submit.prevent="addPendingCommand">
                    <p v-for="warning in pendingDefinition.execution_warnings" :key="warning" class="shortfall">{{ warning }}</p>
                    <p v-if="(pendingDefinition.cost_paradox ?? 0) > 0" class="selector-cost">必要な輝石 {{ pendingDefinition.cost_paradox }} Pd</p>
                    <section v-if="pendingNeedsConfirmation" aria-label="最新計画の再確認">
                        <p>入力と対象は保持しています。最新の計画を確認してください（この位置へ挿入します）。</p>
                        <ol>
                            <li v-for="slot in queue.plan" :key="slot.position">
                                {{ slot.position }}番：{{ slot.command_name }}<strong v-if="slot.position === pendingCommandContext.position"> ← 挿入位置</strong>
                                <small v-if="slot.kind === 'explicit'">
                                    ×{{ slot.quantity }}・{{ slot.target_context === 'underground_slot' ? `地下${slot.target_layer}層 slot ${slot.target_slot_index}` : `x=${slot.target_x}, y=${slot.target_y}` }}
                                </small>
                            </li>
                        </ol>
                        <button type="button" :disabled="busy" @click="requestRefresh()">最新計画を再取得</button>
                        <button type="button" :disabled="busy || queue.version <= pendingCommandContext.queueVersion" @click="confirmPendingPlan">最新計画と挿入位置を確認した</button>
                    </section>
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
                        <button type="button" class="sl-quiet" @click="closePendingCommand">やめる</button>
                        <button type="submit" class="sl-primary" :disabled="busy || pendingNeedsConfirmation || !pendingQuantityIsValid || !parametersAreValid">計画に入れる</button>
                    </div>
                </form>
            </section>
        </div>
        <div v-if="confirmation" class="command-modal-backdrop sl-backdrop" role="presentation" @click.self="confirmation = null">
            <section class="command-modal sl-dialog" role="alertdialog" aria-modal="true" aria-labelledby="command-confirmation-title">
                <h3 id="command-confirmation-title">確認</h3>
                <p>{{ confirmation.message }}</p>
                <div class="popover-actions">
                    <button type="button" class="sl-quiet" @click="confirmation = null">やめる</button>
                    <button type="button" class="danger-action" @click="confirmation.action">{{ confirmation.confirmLabel }}</button>
                </div>
            </section>
        </div>
    </div>
</template>
