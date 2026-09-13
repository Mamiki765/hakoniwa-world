<script setup lang="ts">
defineProps<{ body: string; format?: 'plain_text' | 'markdown'; html?: string | null }>();
</script>

<template>
    <!-- Only the server-rendered announcement HTML is inserted; raw body stays text. -->
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div v-if="format === 'markdown' && html != null" class="announcement-body announcement-markdown" v-html="html"></div>
    <p v-else class="announcement-body">{{ body }}</p>
</template>
