<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { axialToStaggeredPixel, TILE_SIZE } from '../map/projection';
import type { MapCell } from '../types';
import CellDetails from './CellDetails.vue';
import CommandQueuePanel from './CommandQueuePanel.vue';

const props = defineProps<{
    cells: MapCell[];
    selected: MapCell | null;
    capital: { q: number; r: number };
    nationId: number;
    mapSpaceId: number;
    loading: boolean;
    error: string | null;
    emptyChunks: string[];
}>();
const emit = defineEmits<{ select: [cell: MapCell]; move: [direction: number] }>();

const zoom = ref(1);
const pan = ref({ x: 430, y: 270 });
const dragging = ref(false);
const pointer = ref({ x: 0, y: 0 });
const viewport = ref<HTMLElement | null>(null);
const viewportSize = ref({ width: 900, height: 600 });
let resizeObserver: ResizeObserver | null = null;

const positioned = computed(() => props.cells.map((cell) => {
    const pixel = axialToStaggeredPixel({ q: cell.q - props.capital.q, r: cell.r - props.capital.r });
    return { cell, x: pixel.x, y: pixel.y };
}));

// Keep the browser DOM bounded even when the API supplies many chunks.
const visiblePositioned = computed(() => positioned.value.filter((item) => {
    const screenX = pan.value.x + item.x * zoom.value;
    const screenY = pan.value.y + item.y * zoom.value;
    const padding = TILE_SIZE * 2;

    return screenX >= -padding && screenX <= viewportSize.value.width + padding
        && screenY >= -padding && screenY <= viewportSize.value.height + padding;
}));

onMounted(() => {
    if (viewport.value === null) return;
    const updateSize = (): void => {
        if (viewport.value !== null) {
            const width = viewport.value.clientWidth;
            const height = viewport.value.clientHeight;
            if (width > 0 && height > 0) viewportSize.value = { width, height };
        }
    };
    updateSize();
    if ('ResizeObserver' in window) {
        resizeObserver = new ResizeObserver(updateSize);
        resizeObserver.observe(viewport.value);
    }
});

onBeforeUnmount(() => resizeObserver?.disconnect());

function beginPan(event: PointerEvent): void {
    dragging.value = true;
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
</script>

<template>
    <section class="map-layout" aria-label="世界地図">
        <div class="map-toolbar">
            <button type="button" @click="zoom = Math.min(2, zoom + 0.1)">拡大</button>
            <button type="button" @click="zoom = Math.max(0.4, zoom - 0.1)">縮小</button>
            <span>{{ Math.round(zoom * 100) }}%</span>
            <span>表示 {{ visiblePositioned.length }}/{{ cells.length }}セル</span>
            <span v-if="loading" role="status">読み込み中…</span>
            <span v-if="error" class="error" role="alert">{{ error }}</span>
        </div>
        <div
            ref="viewport"
            class="map-viewport"
            tabindex="0"
            aria-label="六方向移動は矢印キーとPageUp・PageDownを使用します"
            @keydown="keydown"
            @pointerdown="beginPan"
            @pointermove="updatePan"
            @pointerup="dragging = false"
            @pointercancel="dragging = false"
        >
            <div class="map-plane" :style="{ transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})` }">
                <button
                    v-for="item in visiblePositioned"
                    :key="`${item.cell.q}:${item.cell.r}`"
                    class="map-cell"
                    :class="[`terrain-${item.cell.terrain}`, { selected: selected?.q === item.cell.q && selected?.r === item.cell.r, owned: item.cell.owner_nation_id !== null, capital: item.cell.facility === 'capital' }]"
                    :style="{ left: `${item.x}px`, top: `${item.y}px` }"
                    :aria-label="item.cell.aria_label"
                    type="button"
                    @pointerdown.stop
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
        </div>
        <aside class="cell-details">
            <CellDetails :cell="selected" />
            <p v-if="emptyChunks.length">未生成／空chunk: {{ emptyChunks.join(', ') }}</p>
            <CommandQueuePanel :nation-id="nationId" :map-space-id="mapSpaceId" :selected="selected" />
        </aside>
    </section>
</template>
