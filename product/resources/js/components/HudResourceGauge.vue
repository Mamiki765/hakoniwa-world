<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    label: string;
    value: number;
    capacity: number;
    warning?: boolean;
    danger?: boolean;
}>();
const filled = computed(() => props.capacity > 0 ? Math.min(100, Math.max(0, props.value / props.capacity * 100)) : props.value > 0 ? 100 : 0);
const valueText = computed(() => `${props.value.toLocaleString('ja-JP')} / ${props.capacity.toLocaleString('ja-JP')}${props.danger ? ' DANGER' : ''}`);
</script>

<template>
    <span
        class="hud-gauge"
        :class="{ 'is-warning': warning || danger }"
        role="meter"
        :aria-label="label"
        aria-valuemin="0"
        :aria-valuemax="Math.max(1, capacity)"
        :aria-valuenow="Math.min(Math.max(1, capacity), Math.max(0, value))"
        :aria-valuetext="valueText"
        :title="`${label} ${valueText}`"
    >
        <span class="hud-gauge-fill" :style="{ width: `${filled}%` }" />
        <strong v-if="danger" class="hud-gauge-danger" aria-hidden="true">DANGER</strong>
    </span>
</template>
