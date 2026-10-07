<script setup lang="ts">
import { computed, nextTick } from 'vue';
import type { SecretarySection } from '../types';

const props = defineProps<{ owner: boolean }>();
const section = defineModel<SecretarySection>({ required: true });
const tabs = computed<readonly SecretarySection[]>(() => props.owner
    ? ['main', 'skills', 'equipment', 'warehouse', 'achievements', 'settings']
    : ['main', 'equipment']);
const entries: Array<{ key: SecretarySection; label: string; icon: string }> = [
    { key: 'main', label: 'メイン', icon: 'M5 3h14v18H5z M8 7h8 M8 11h8 M8 15h5' },
    { key: 'skills', label: '熟練度', icon: 'M4 20V12h4v8 M10 20V8h4v12 M16 20V4h4v16' },
    { key: 'equipment', label: '装備', icon: 'M14 3l7 0-9 12-3-3z M3 21l6-9 M5 10l9 9' },
    { key: 'warehouse', label: '倉庫', icon: 'M3 8l9-5 9 5v13H3z M3 8l9 5 9-5 M12 13v8' },
    { key: 'achievements', label: '実績', icon: 'M7 3h10v6a5 5 0 01-10 0z M7 5H3v3a4 4 0 004 4 M17 5h4v3a4 4 0 01-4 4 M12 14v6 M8 21h8' },
    { key: 'settings', label: '設定', icon: 'M4 6h16 M4 12h16 M4 18h16 M8 3v6 M16 9v6 M10 15v6' },
];

async function navigate(event: KeyboardEvent): Promise<void> {
    const order: readonly SecretarySection[] = tabs.value;
    const index = order.indexOf(section.value);
    let target: number | null = null;
    if (event.key === 'ArrowRight') target = (index + 1) % order.length;
    if (event.key === 'ArrowLeft') target = (index - 1 + order.length) % order.length;
    if (event.key === 'Home') target = 0;
    if (event.key === 'End') target = order.length - 1;
    if (target === null) return;
    event.preventDefault();
    section.value = order[target]!;
    await nextTick();
    document.getElementById(`secretary-tab-${section.value}`)?.focus();
}
</script>

<template>
    <nav class="secretary-tabs" role="tablist" aria-label="秘書メニュー">
        <template v-for="entry in entries" :key="entry.key">
            <button
                v-if="tabs.includes(entry.key)"
                :id="`secretary-tab-${entry.key}`" type="button" role="tab"
                :class="{ 'secretary-tab-settings': entry.key === 'settings' }"
                :aria-controls="`secretary-panel-${entry.key}`" :aria-selected="section === entry.key"
                :tabindex="section === entry.key ? 0 : -1"
                @click="section = entry.key" @keydown="navigate"
            >
                <svg viewBox="0 0 24 24" aria-hidden="true"><path :d="entry.icon" /></svg>
                <span>{{ entry.label }}</span>
            </button>
            <button v-if="owner && entry.key === 'warehouse'" class="secretary-tab-synthesis" type="button" role="tab" aria-selected="false" aria-label="合成（未実装）" title="未実装" disabled tabindex="-1">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3h10 M9 3v7L4 19v2h16v-2l-5-9V3 M7 16h10" /></svg>
                <span>合成</span>
            </button>
        </template>
    </nav>
</template>
