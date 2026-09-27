<script setup lang="ts">
import { undergroundDestinations, type UndergroundDestination } from './undergroundScenes';
defineProps<{ current: UndergroundDestination; exchangeDiscovered?: boolean; distortedStoneReminder?: boolean }>();
defineEmits<{ navigate: [destination: UndergroundDestination] }>();
</script>

<template>
    <nav class="ug-navigation" aria-label="地底の行き先">
        <button v-for="destination in undergroundDestinations" :key="destination.key" type="button" :class="{ 'notification-anchor': destination.key === 'shop' && distortedStoneReminder }" :aria-current="current === destination.key ? 'page' : undefined" :aria-label="destination.key === 'shop' && distortedStoneReminder ? 'ショップ（歪んだ輝石の初回受取があります）' : undefined" @click="$emit('navigate', destination.key)">
            <span class="ug-navigation-icon" aria-hidden="true">{{ destination.icon }}</span>
            <span>{{ destination.key === 'exchange' && exchangeDiscovered === false ? '？？？' : destination.label }}</span>
            <span v-if="destination.key === 'shop' && distortedStoneReminder" class="notification-dot" aria-hidden="true" />
        </button>
    </nav>
</template>
