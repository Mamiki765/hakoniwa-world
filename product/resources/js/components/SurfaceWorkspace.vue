<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

type Side = 'left' | 'right';
const storageKey = 'hakoniwa_surface_columns';
const defaults = { left: 264, right: 310 };
const widths = ref({ ...defaults });
try {
    const saved = JSON.parse(localStorage.getItem(storageKey) ?? 'null');
    if (saved && Number.isFinite(saved.left) && Number.isFinite(saved.right)) {
        widths.value = { left: clamp(saved.left), right: clamp(saved.right) };
    }
} catch { /* Use default widths when storage is unavailable. */ }
const panel = ref<'command' | 'plan' | 'log'>('command');
const expanded = ref(false);
const root = ref<HTMLElement | null>(null);
const columns = computed(() => ({ '--surface-left': `${widths.value.left}px`, '--surface-right': `${widths.value.right}px` }));
let drag: { side: Side; pointerId: number; x: number; width: number } | null = null;

function clamp(value: number): number { return Math.max(220, Math.min(440, value)); }
function save(): void {
    try { localStorage.setItem(storageKey, JSON.stringify(widths.value)); } catch { /* Resizing still works without persistence. */ }
}
function start(side: Side, event: PointerEvent): void {
    if (event.button !== 0) return;
    drag = { side, pointerId: event.pointerId, x: event.clientX, width: widths.value[side] };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    event.preventDefault();
}
function resize(event: PointerEvent): void {
    if (drag === null || event.pointerId !== drag.pointerId) return;
    widths.value[drag.side] = clamp(drag.width + (event.clientX - drag.x) * (drag.side === 'left' ? 1 : -1));
}
function finish(event: PointerEvent): void {
    if (drag?.pointerId !== event.pointerId) return;
    drag = null;
    save();
}
function reset(side: Side): void { widths.value[side] = defaults[side]; save(); }
function keyResize(side: Side, event: KeyboardEvent): void {
    if (!['ArrowLeft', 'ArrowRight', 'Home'].includes(event.key)) return;
    event.preventDefault();
    if (event.key === 'Home') reset(side);
    else {
        widths.value[side] = clamp(widths.value[side] + (event.key === 'ArrowRight' ? 16 : -16) * (side === 'left' ? 1 : -1));
        save();
    }
}
async function showCommands(): Promise<void> {
    panel.value = 'command';
    await nextTick();
    root.value?.scrollIntoView?.({ block: 'nearest', behavior: window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
}
defineExpose({ showCommands });
</script>

<template>
    <div ref="root" class="surface-workspace" :class="{ 'is-expanded': expanded }" :data-panel="panel" :style="columns">
        <nav class="surface-panel-tabs" aria-label="開発パネル">
            <button v-for="tab in (['command', 'plan', 'log'] as const)" :key="tab" type="button" :aria-pressed="panel === tab" @click="panel = tab">
                {{ tab === 'command' ? 'マス・コマンド' : tab === 'plan' ? '計画' : 'ログ' }}
            </button>
            <button type="button" :aria-expanded="expanded" :aria-label="expanded ? 'パネルを元の高さに戻す' : 'パネルを上まで広げる'" @click="expanded = !expanded">{{ expanded ? '︾' : '︽' }}</button>
        </nav>
        <div class="surface-ledger-grid">
            <slot />
            <div v-for="side in (['left', 'right'] as const)" :key="side" class="surface-column-grip" :class="`grip-${side}`" role="separator" aria-orientation="vertical" :aria-label="`${side === 'left' ? '左' : '右'}の列の幅`" :aria-valuenow="widths[side]" :aria-valuemin="220" :aria-valuemax="440" tabindex="0" @pointerdown="start(side, $event)" @pointermove="resize" @pointerup="finish" @pointercancel="finish" @dblclick="reset(side)" @keydown="keyResize(side, $event)" />
            <div class="surface-map-column">
                <div class="surface-map"><slot name="map" /></div>
                <div class="surface-log"><slot name="log" /></div>
            </div>
        </div>
    </div>
</template>
