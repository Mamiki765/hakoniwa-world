<script setup lang="ts">
import { undergroundDestinations, type UndergroundDestination } from './undergroundScenes';
const props = defineProps<{ current: UndergroundDestination; exchangeDiscovered?: boolean; distortedStoneReminder?: boolean; marketAttention?: boolean }>();
function hasDot(key: UndergroundDestination): boolean {
    return (key === 'shop' && !!props.distortedStoneReminder) || (key === 'exchange' && !!props.marketAttention);
}
function dotLabel(key: UndergroundDestination): string | undefined {
    if (key === 'shop' && props.distortedStoneReminder) return 'ショップ（歪んだ輝石の初回受取があります）';
    if (key === 'exchange' && props.marketAttention) return '交流場（露店にお知らせがあります）';
    return undefined;
}
defineEmits<{ navigate: [destination: UndergroundDestination] }>();
</script>

<template>
    <nav class="ug-navigation" aria-label="地底の行き先">
        <button v-for="destination in undergroundDestinations" :key="destination.key" type="button" :class="{ 'notification-anchor': hasDot(destination.key) }" :aria-current="current === destination.key ? 'page' : undefined" :aria-label="dotLabel(destination.key)" @click="$emit('navigate', destination.key)">
            <span class="ug-navigation-icon" aria-hidden="true">{{ destination.icon }}</span>
            <span>{{ destination.key === 'exchange' && exchangeDiscovered === false ? '？？？' : destination.label }}</span>
            <span v-if="hasDot(destination.key)" class="notification-dot" aria-hidden="true" />
        </button>
    </nav>
</template>
