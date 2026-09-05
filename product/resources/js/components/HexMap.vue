<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { floorMod, gridToPixel, TILE_SIZE } from '../map/projection';
import type { CommandQueue, CommandQueueItem, MapBounds, MapCell } from '../types';

const props = defineProps<{
    cells: MapCell[];
    selected: MapCell | null;
    capital: { x: number; y: number };
    bounds: MapBounds;
    ownNationId?: number;
    commandQueue?: CommandQueue | null;
    loading: boolean;
    error: string | null;
    emptyChunks: string[];
}>();
const emit = defineEmits<{
    select: [cell: MapCell];
    move: [direction: number];
    requestRange: [range: { minX: number; maxX: number; minY: number; maxY: number }];
    requestAll: [];
}>();

const zoom = ref(1);
const pan = ref({ x: 0, y: 0 });
const dragging = ref(false);
const viewport = ref<HTMLElement | null>(null);
const viewportSize = ref({ width: 900, height: 600 });
const tooltipCell = ref<MapCell | null>(null);
const wholeWorldView = ref(false);
const showVisibility = ref(false);
const tooltipPosition = ref({ x: 0, y: 0, placement: 'right' as 'right' | 'left' | 'bottom' | 'top' });
const failedAssets = ref<Set<string>>(new Set());
let resizeObserver: ResizeObserver | null = null;
const PAN_THRESHOLD = 6;
let activePointer: {
    id: number;
    startX: number;
    startY: number;
    lastX: number;
    lastY: number;
    captureOwner: HTMLElement;
    originCell: HTMLElement | null;
} | null = null;
let suppressNextCellClick = false;

const positioned = computed(() => props.cells.map((cell) => {
    const pixel = gridToPixel(cell);
    const capitalPixel = gridToPixel(props.capital);
    return { cell, x: pixel.x - capitalPixel.x, y: pixel.y - capitalPixel.y };
}));

const visiblePositioned = computed(() => positioned.value.filter((item) => {
    const screenX = pan.value.x + item.x * zoom.value;
    const screenY = pan.value.y + item.y * zoom.value;
    const padding = TILE_SIZE * 2;

    return screenX >= -padding && screenX <= viewportSize.value.width + padding
        && screenY >= -padding && screenY <= viewportSize.value.height + padding;
}));

const queuedCommandsByCoordinate = computed(() => {
    const index = new Map<string, CommandQueueItem[]>();
    for (const item of props.commandQueue?.items ?? []) {
        if (!Number.isInteger(item.target_x) || !Number.isInteger(item.target_y)) continue;
        const key = `${item.target_x}:${item.target_y}`;
        const items = index.get(key) ?? [];
        items.push(item);
        index.set(key, items);
    }
    for (const items of index.values()) {
        items.sort((left, right) => left.queue_position - right.queue_position || left.id - right.id);
    }

    return index;
});

function queuedCommandLines(cell: MapCell): string[] {
    return (queuedCommandsByCoordinate.value.get(`${cell.x}:${cell.y}`) ?? []).map((item) => {
        const quantity = item.quantity_semantics === 'ordinary' ? item.quantity : 1;

        return `[${item.queue_position}] ${item.command_name} ×${quantity}`;
    });
}

const tooltipDetails = computed(() => {
    const cell = tooltipCell.value;
    if (cell === null) return [];

    const detailLines: string[] = [];
    for (const detail of cell.details) {
        detailLines.push(`${detail.label}: ${detail.formatted}`);
        if (detail.key === 'sea_area') detailLines.push(...queuedCommandLines(cell));
    }

    return [
        `座標 x=${cell.x}, y=${cell.y}`,
        ...(cell.ship == null ? [] : [
            `船の所有者: ${cell.ship.owner_nation.name} (N${cell.ship.owner_nation.nation_number})`,
            `HP: ${cell.ship.current_hp}/${cell.ship.max_hp}`,
            `地形: ${cell.terrain_name}`,
        ]),
        `${cell.ship == null ? '所有' : 'このマスの所有者'}: ${cell.owner_name ?? '中立'}${cell.owner_nation_number === null ? '' : ` (N${cell.owner_nation_number})`}`,
        ...(cell.monster === null ? [] : [
            `怪獣: ${cell.monster.name}`,
            `HP: ${cell.monster.current_hp}/${cell.monster.spawned_max_hp}`,
            `所在: ${cell.monster.host_nation?.name ?? '無所属'} (${cell.monster.host_label})`,
            ...(cell.monster.hardened_now ? ['状態: 硬化中'] : []),
        ]),
        ...(cell.facility === null ? ['施設: なし'] : []),
        ...detailLines,
    ];
});

onMounted(() => {
    if (viewport.value === null) return;
    const updateSize = (): void => {
        if (viewport.value === null) return;
        const width = viewport.value.clientWidth || 900;
        const height = viewport.value.clientHeight || 600;
        viewportSize.value = { width, height };
        if (wholeWorldView.value) {
            fitWholeWorld(false);
        } else {
            centerOnCapital();
            requestVisibleChunks();
        }
    };
    updateSize();
    if ('ResizeObserver' in window) {
        resizeObserver = new ResizeObserver(updateSize);
        resizeObserver.observe(viewport.value);
    }
});

watch(() => [props.capital.x, props.capital.y], () => void nextTick(centerOnCapital));
onBeforeUnmount(() => resizeObserver?.disconnect());

function centerOnCapital(): void {
    wholeWorldView.value = false;
    pan.value = {
        x: viewportSize.value.width / 2 - (TILE_SIZE * zoom.value) / 2,
        y: viewportSize.value.height / 2 - (TILE_SIZE * zoom.value) / 2,
    };
}

function returnToCapital(): void {
    zoom.value = 1;
    centerOnCapital();
}

function wholeWorldTransform(): { zoom: number; pan: { x: number; y: number } } {
    const rowOffsets = props.bounds.min_y === props.bounds.max_y
        ? [floorMod(props.bounds.min_y, 2) === 0 ? TILE_SIZE / 2 : 0]
        : [0, TILE_SIZE / 2];
    const worldLeft = props.bounds.min_x * TILE_SIZE + Math.min(...rowOffsets);
    const worldRight = props.bounds.max_x * TILE_SIZE + Math.max(...rowOffsets) + TILE_SIZE;
    const worldTop = props.bounds.min_y * TILE_SIZE;
    const worldBottom = props.bounds.max_y * TILE_SIZE + TILE_SIZE;
    const worldWidth = Math.max(TILE_SIZE, worldRight - worldLeft);
    const worldHeight = Math.max(TILE_SIZE, worldBottom - worldTop);
    const padding = 20;
    const availableWidth = Math.max(TILE_SIZE, viewportSize.value.width - padding * 2);
    const availableHeight = Math.max(TILE_SIZE, viewportSize.value.height - padding * 2);
    const nextZoom = Math.max(0.05, Math.min(1, availableWidth / worldWidth, availableHeight / worldHeight));
    const capitalPixel = gridToPixel(props.capital);
    const relativeLeft = worldLeft - capitalPixel.x;
    const relativeTop = worldTop - capitalPixel.y;

    return {
        zoom: nextZoom,
        pan: {
            x: (viewportSize.value.width - worldWidth * nextZoom) / 2 - relativeLeft * nextZoom,
            y: (viewportSize.value.height - worldHeight * nextZoom) / 2 - relativeTop * nextZoom,
        },
    };
}

function fitWholeWorld(loadAll: boolean): void {
    const transform = wholeWorldTransform();
    wholeWorldView.value = true;
    zoom.value = transform.zoom;
    pan.value = transform.pan;
    tooltipCell.value = null;
    if (loadAll) emit('requestAll');
}

function changeZoom(delta: number): void {
    const minimumZoom = Math.min(0.45, wholeWorldTransform().zoom);
    zoom.value = Math.min(2, Math.max(minimumZoom, Number((zoom.value + delta).toFixed(2))));
    centerOnCapital();
    requestVisibleChunks();
}

function requestVisibleChunks(): void {
    const capitalPixel = gridToPixel(props.capital);
    const margin = TILE_SIZE * 4;
    const absoluteLeft = capitalPixel.x + (-pan.value.x - margin) / zoom.value;
    const absoluteRight = capitalPixel.x + (viewportSize.value.width - pan.value.x + margin) / zoom.value;
    const absoluteTop = capitalPixel.y + (-pan.value.y - margin) / zoom.value;
    const absoluteBottom = capitalPixel.y + (viewportSize.value.height - pan.value.y + margin) / zoom.value;

    emit('requestRange', {
        minX: Math.floor(absoluteLeft / TILE_SIZE) - 1,
        maxX: Math.ceil(absoluteRight / TILE_SIZE) + 1,
        minY: Math.floor(absoluteTop / TILE_SIZE) - 1,
        maxY: Math.ceil(absoluteBottom / TILE_SIZE) + 1,
    });
}

function isPanExcludedTarget(target: EventTarget | null): boolean {
    if (!(target instanceof Element)) return false;

    const interactive = target.closest('button, a, input, select, textarea, [contenteditable="true"], [role="button"]');

    return interactive !== null && !interactive.classList.contains('map-cell');
}

function beginPan(event: PointerEvent): void {
    if (activePointer !== null || event.isPrimary === false || event.button !== 0 || isPanExcludedTarget(event.target)) return;

    const captureOwner = viewport.value;
    if (captureOwner === null) return;

    const originCell = event.target instanceof Element
        ? event.target.closest<HTMLElement>('.map-cell')
        : null;
    suppressNextCellClick = false;
    activePointer = {
        id: event.pointerId,
        startX: event.clientX,
        startY: event.clientY,
        lastX: event.clientX,
        lastY: event.clientY,
        captureOwner,
        originCell,
    };
    captureOwner.setPointerCapture?.(event.pointerId);
}

function updatePan(event: PointerEvent): void {
    if (activePointer === null || activePointer.id !== event.pointerId) return;

    if (!dragging.value) {
        const distance = Math.hypot(
            event.clientX - activePointer.startX,
            event.clientY - activePointer.startY,
        );
        if (distance < PAN_THRESHOLD) return;

        dragging.value = true;
        wholeWorldView.value = false;
        suppressNextCellClick = true;
        tooltipCell.value = null;
    }

    event.preventDefault();
    pan.value = {
        x: pan.value.x + event.clientX - activePointer.lastX,
        y: pan.value.y + event.clientY - activePointer.lastY,
    };
    activePointer.lastX = event.clientX;
    activePointer.lastY = event.clientY;
    requestVisibleChunks();
}

function finishPan(event: PointerEvent, cancelled: boolean): void {
    if (activePointer === null || activePointer.id !== event.pointerId) return;

    const { captureOwner, originCell } = activePointer;
    const wasDragging = dragging.value;
    if (cancelled) suppressNextCellClick = false;
    try {
        if (captureOwner.hasPointerCapture?.(event.pointerId)) {
            captureOwner.releasePointerCapture?.(event.pointerId);
        }
    } finally {
        activePointer = null;
        dragging.value = false;
    }
    if (!cancelled && !wasDragging && originCell !== null) {
        originCell.click();
        suppressNextCellClick = true;
    }
}

function endPan(event: PointerEvent): void {
    finishPan(event, false);
}

function cancelPan(event: PointerEvent): void {
    finishPan(event, true);
}

function suppressDraggedCellClick(event: MouseEvent): void {
    if (!suppressNextCellClick) return;

    suppressNextCellClick = false;
    if (event.detail === 0 || !(event.target instanceof Element) || event.target.closest('.map-cell') === null) return;

    event.preventDefault();
    event.stopPropagation();
}

function keydown(event: KeyboardEvent): void {
    const directions: Record<string, number> = {
        ArrowRight: 0, PageUp: 1, ArrowUp: 2,
        ArrowLeft: 3, PageDown: 4, ArrowDown: 5,
    };
    const direction = directions[event.key];
    if (direction !== undefined) {
        event.preventDefault();
        emit('move', direction);
    }
}

function showTooltip(cell: MapCell, event: Event): void {
    const target = event.currentTarget as HTMLElement;
    const viewportElement = viewport.value;
    if (viewportElement === null) return;
    const cellRect = target.getBoundingClientRect();
    const bounds = viewportElement.getBoundingClientRect();
    const width = 220;
    const height = 128;
    const gap = 10;
    let placement: 'right' | 'left' | 'bottom' | 'top' = 'right';
    let x = cellRect.right - bounds.left + gap;
    let y = cellRect.top - bounds.top;

    if (x + width > bounds.width) {
        placement = 'left';
        x = cellRect.left - bounds.left - width - gap;
    }
    if (x < 0) {
        placement = 'bottom';
        x = Math.max(8, Math.min(cellRect.left - bounds.left, bounds.width - width - 8));
        y = cellRect.bottom - bounds.top + gap;
    }
    if (y + height > bounds.height) {
        placement = 'top';
        y = cellRect.top - bounds.top - height - gap;
    }

    tooltipCell.value = cell;
    tooltipPosition.value = { x: Math.max(8, x), y: Math.max(8, y), placement };
}

function assetIdentity(cell: MapCell): string {
    return `${cell.x}:${cell.y}:${cell.asset.key}:${cell.asset.url ?? ''}`;
}

function assetIsRenderable(cell: MapCell): boolean {
    return cell.asset.available && cell.asset.url !== null && !failedAssets.value.has(assetIdentity(cell));
}

function markAssetFailed(cell: MapCell): void {
    failedAssets.value = new Set([...failedAssets.value, assetIdentity(cell)]);
}
</script>

<template>
    <section class="map-stage" aria-label="世界地図">
        <div class="map-toolbar">
            <button type="button" aria-label="自島へ戻る" @click="returnToCapital">自島へ</button>
            <button type="button" aria-label="世界全体を表示" @click="fitWholeWorld(true)">世界全体</button>
            <button type="button" aria-label="地図を拡大" @click="changeZoom(0.1)">＋</button>
            <button type="button" aria-label="地図を縮小" @click="changeZoom(-0.1)">−</button>
            <span>{{ Math.round(zoom * 100) }}%</span>
            <button
                v-if="ownNationId !== undefined"
                type="button"
                class="visibility-toggle"
                :aria-pressed="showVisibility"
                @click="showVisibility = !showVisibility"
            >
                視界表示
            </button>
            <span class="map-cell-count">表示 {{ visiblePositioned.length }}/{{ cells.length }}セル</span>
            <span v-if="loading" role="status">読み込み中…</span>
            <span v-if="error" class="error" role="alert">{{ error }}</span>
        </div>
        <div
            ref="viewport"
            class="map-viewport"
            :class="{ 'is-dragging': dragging }"
            tabindex="0"
            aria-label="地図。六方向移動は矢印キーとPageUp・PageDownを使用します"
            @keydown="keydown"
            @pointerdown="beginPan"
            @pointermove="updatePan"
            @pointerup="endPan"
            @pointercancel="cancelPan"
            @click.capture="suppressDraggedCellClick"
        >
            <div class="map-plane" :style="{ transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})` }">
                <button
                    v-for="item in visiblePositioned"
                    :key="`${item.cell.x}:${item.cell.y}`"
                    class="map-cell"
                    :class="[
                        `terrain-${item.cell.terrain}`,
                        {
                            selected: selected?.x === item.cell.x && selected?.y === item.cell.y,
                            owned: item.cell.owner_nation_id !== null,
                            'owned-by-me': ownNationId !== undefined && item.cell.owner_nation_id === ownNationId,
                            'within-viewer-visibility': showVisibility && item.cell.within_viewer_visibility,
                            capital: item.cell.facility === 'capital',
                        },
                    ]"
                    :style="{ left: `${item.x}px`, top: `${item.y}px` }"
                    :aria-label="item.cell.aria_label"
                    type="button"
                    @mouseenter="showTooltip(item.cell, $event)"
                    @mouseleave="tooltipCell = null"
                    @focus="showTooltip(item.cell, $event)"
                    @blur="tooltipCell = null"
                    @click="emit('select', item.cell)"
                >
                    <img v-if="assetIsRenderable(item.cell)" :src="item.cell.asset.url ?? ''" alt="" draggable="false" @error="markAssetFailed(item.cell)">
                    <template v-for="overlay in item.cell.overlays" :key="overlay.key">
                        <img v-if="overlay.available && overlay.url" class="tile-overlay" :src="overlay.url" alt="" draggable="false">
                    </template>
                    <span v-if="!assetIsRenderable(item.cell)" class="tile-label">{{ item.cell.facility === 'capital' ? '首' : item.cell.asset.fallback_label.slice(0, 1) }}</span>
                    <small v-if="item.cell.ship != null || item.cell.owner_nation_number !== null">
                        N{{ item.cell.ship?.owner_nation.nation_number ?? item.cell.owner_nation_number }}
                    </small>
                    <span v-if="item.cell.monster" class="monster-overlay" aria-hidden="true">
                        <span class="monster-fallback">{{ item.cell.monster.name.slice(0, 1) }}</span>
                        <img
                            v-if="item.cell.monster.asset.available && item.cell.monster.asset_url"
                            class="monster-image"
                            :src="item.cell.monster.asset_url"
                            alt=""
                            draggable="false"
                            @error="($event.currentTarget as HTMLImageElement).hidden = true"
                        >
                        <span class="monster-hp">HP {{ item.cell.monster.current_hp }}</span>
                        <span class="monster-host">{{ item.cell.monster.host_label }}</span>
                        <span v-if="item.cell.monster.hardened_now" class="monster-hardened">硬</span>
                    </span>
                </button>
            </div>
            <div
                v-if="tooltipCell"
                class="cell-tooltip"
                :class="`placement-${tooltipPosition.placement}`"
                :style="{ left: `${tooltipPosition.x}px`, top: `${tooltipPosition.y}px` }"
                role="tooltip"
            >
                <strong>{{ tooltipCell.display_name }}</strong>
                <span v-for="line in tooltipDetails" :key="line">{{ line }}</span>
            </div>
        </div>
        <p v-if="emptyChunks.length" class="map-empty-note">未生成／空chunk: {{ emptyChunks.join(', ') }}</p>
    </section>
</template>
