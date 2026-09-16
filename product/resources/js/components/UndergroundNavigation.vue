<script setup lang="ts">
import { undergroundDestinations, type UndergroundDestination } from './undergroundScenes';
defineProps<{ current: UndergroundDestination; exchangeDiscovered?: boolean }>();
defineEmits<{ navigate: [destination: UndergroundDestination] }>();
</script>

<template>
    <nav class="ug-navigation" aria-label="地底の行き先">
        <button v-for="destination in undergroundDestinations" :key="destination.key" type="button" :aria-current="current === destination.key ? 'page' : undefined" @click="$emit('navigate', destination.key)">
            <span class="ug-navigation-icon" aria-hidden="true">{{ destination.icon }}</span>
            <span>{{ destination.key === 'exchange' && exchangeDiscovered === false ? '？？？' : destination.label }}</span>
        </button>
    </nav>
</template>
