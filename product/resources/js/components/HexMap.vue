<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { gridToPixel, TILE_SIZE } from '../map/projection';
import type { MapCell } from '../types';

const props = defineProps<{
    cells: MapCell[];
    selected: MapCell | null;
    capital: { x: number; y: number };
    ownNationId?: number;
    loading: boolean;
    error: string | null;
    emptyChunks: string[];
}>();
const emit = defineEmits<{ select: [cell: MapCell]; move: [direction: number] }>();

const zoom = ref(1);
const pan = ref({ x: 0, y: 0 });
const dragging = ref(false);
const pointer = ref({ x: 0, y: 0 });
const viewport = ref<HTMLElement | null>(null);
const viewportSize = ref({ width: 900, height: 600 });
const tooltipCell = ref<MapCell | null>(null);
const tooltipPosition = ref({ x: 0, y: 0, placement: 'right' as 'right' | 'left' | 'bottom' | 'top' });
let resizeObserver: ResizeObserver | null = null;

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

const tooltipDetails = computed(() => {
    const cell = tooltipCell.value;
    if (cell === null) return [];

    return [
        `座標 x=${cell.x}, y=${cell.y}`,
        `所有: ${cell.owner_name ?? '中立'}`,
        ...(cell.facility === null ? ['施設: なし'] : []),
        ...cell.details.map((detail) => `${detail.label}: ${detail.formatted}`),
    ];
});

onMounted(() => {
    if (viewport.value === null) return;
    const updateSize = (): void => {
        if (viewport.value === null) return;
        const width = viewport.value.clientWidth || 900;
        const height = viewport.value.clientHeight || 600;
        viewportSize.value = { width, height };
        centerOnCapital();
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
    pan.value = {
        x: viewportSize.value.width / 2 - (TILE_SIZE * zoom.value) / 2,
        y: viewportSize.value.height / 2 - (TILE_SIZE * zoom.value) / 2,
    };
}

function changeZoom(delta: number): void {
    zoom.value = Math.min(2, Math.max(0.45, Number((zoom.value + delta).toFixed(2))));
    centerOnCapital();
}

function beginPan(event: PointerEvent): void {
    dragging.value = true;
    tooltipCell.value = null;
    pointer.value = { x: event.clientX, y: event.clientY };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
}

function updatePan(event: PointerEvent): void {
    if (!dragging.value) return;
    pan.value = {
        x: pan.value.x + event.clientX - pointer.value.x,
        y: pan.value.y + event.clientY - pointer.value.y,
    };
    pointer.value = { x: event.clientX, y: event.clientY };
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
</script>

<template>
    <section class="map-stage" aria-label="世界地図">
        <div class="map-toolbar">
            <button type="button" aria-label="自島へ戻る" @click="centerOnCapital">自島へ</button>
            <button type="button" aria-label="地図を拡大" @click="changeZoom(0.1)">＋</button>
            <button type="button" aria-label="地図を縮小" @click="changeZoom(-0.1)">−</button>
            <span>{{ Math.round(zoom * 100) }}%</span>
            <span class="map-cell-count">表示 {{ visiblePositioned.length }}/{{ cells.length }}セル</span>
            <span v-if="loading" role="status">読み込み中…</span>
            <span v-if="error" class="error" role="alert">{{ error }}</span>
        </div>
        <div
            ref="viewport"
            class="map-viewport"
            tabindex="0"
            aria-label="地図。六方向移動は矢印キーとPageUp・PageDownを使用します"
            @keydown="keydown"
            @pointerdown="beginPan"
            @pointermove="updatePan"
            @pointerup="dragging = false"
            @pointercancel="dragging = false"
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
                            capital: item.cell.facility === 'capital',
                        },
                    ]"
                    :style="{ left: `${item.x}px`, top: `${item.y}px` }"
                    :aria-label="item.cell.aria_label"
                    type="button"
                    @pointerdown.stop
                    @mouseenter="showTooltip(item.cell, $event)"
                    @mouseleave="tooltipCell = null"
                    @focus="showTooltip(item.cell, $event)"
                    @blur="tooltipCell = null"
                    @click="emit('select', item.cell)"
                >
                    <img v-if="item.cell.asset.available && item.cell.asset.url" :src="item.cell.asset.url" alt="" @error="($event.currentTarget as HTMLImageElement).hidden = true">
                    <template v-for="overlay in item.cell.overlays" :key="overlay.key">
                        <img v-if="overlay.available && overlay.url" class="tile-overlay" :src="overlay.url" alt="">
                    </template>
                    <span class="tile-label">{{ item.cell.facility === 'capital' ? '首' : item.cell.asset.fallback_label.slice(0, 1) }}</span>
                    <small v-if="item.cell.owner_nation_id !== null">N{{ item.cell.owner_nation_id }}</small>
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
