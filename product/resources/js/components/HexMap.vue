<script setup lang="ts">
import { computed, ref } from 'vue';
import { axialToPixel } from '../map/projection';
import type { MapCell } from '../types';

const props = defineProps<{
    cells: MapCell[];
    selected: MapCell | null;
    capital: { q: number; r: number };
    loading: boolean;
    error: string | null;
    emptyChunks: string[];
}>();
const emit = defineEmits<{ select: [cell: MapCell]; move: [direction: number] }>();

const zoom = ref(0.78);
const pan = ref({ x: 430, y: 270 });
const dragging = ref(false);
const pointer = ref({ x: 0, y: 0 });
const cellSize = 26;

const positioned = computed(() => props.cells.map((cell) => {
    const pixel = axialToPixel({ q: cell.q - props.capital.q, r: cell.r - props.capital.r }, cellSize);
    return { cell, x: pixel.x, y: pixel.y };
}));

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
            <button type="button" @click="zoom = Math.min(1.5, zoom + 0.1)">拡大</button>
            <button type="button" @click="zoom = Math.max(0.35, zoom - 0.1)">縮小</button>
            <span>{{ Math.round(zoom * 100) }}%</span>
            <span v-if="loading" role="status">読み込み中…</span>
            <span v-if="error" class="error" role="alert">{{ error }}</span>
        </div>
        <div
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
                    v-for="item in positioned"
                    :key="`${item.cell.q}:${item.cell.r}`"
                    class="hex-cell"
                    :class="[`terrain-${item.cell.terrain}`, { selected: selected?.q === item.cell.q && selected?.r === item.cell.r, owned: item.cell.owner_nation_id !== null, capital: item.cell.facility === 'capital' }]"
                    :style="{ left: `${item.x}px`, top: `${item.y}px` }"
                    :aria-label="`${item.cell.q},${item.cell.r} ${item.cell.asset.fallback_label} 所有:${item.cell.owner_name ?? 'なし'}`"
                    type="button"
                    @pointerdown.stop
                    @click="emit('select', item.cell)"
                >
                    <img v-if="item.cell.asset.available && item.cell.asset.url" :src="item.cell.asset.url" alt="" @error="($event.currentTarget as HTMLImageElement).hidden = true">
                    <span class="tile-label">{{ item.cell.facility === 'capital' ? '首' : item.cell.asset.fallback_label.slice(0, 1) }}</span>
                    <small v-if="item.cell.owner_nation_id !== null">N{{ item.cell.owner_nation_id }}</small>
                </button>
            </div>
        </div>
        <aside class="cell-details" aria-live="polite">
            <h3>選択セル</h3>
            <template v-if="selected">
                <dl>
                    <dt>座標</dt><dd>q={{ selected.q }}, r={{ selected.r }}</dd>
                    <dt>地形</dt><dd>{{ selected.terrain }}</dd>
                    <dt>施設</dt><dd>{{ selected.facility ?? 'なし' }}</dd>
                    <dt>所有</dt><dd>{{ selected.owner_name ?? '中立' }}<span v-if="selected.owner_nation_id">（N{{ selected.owner_nation_id }}）</span></dd>
                    <dt>人口</dt><dd>{{ selected.population.toLocaleString() }}</dd>
                </dl>
            </template>
            <p v-else>セルを選択してください。</p>
            <p v-if="emptyChunks.length">未生成／空chunk: {{ emptyChunks.join(', ') }}</p>
        </aside>
    </section>
</template>
