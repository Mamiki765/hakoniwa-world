<script setup lang="ts">
import { ref, useId } from 'vue';
import type { AssetDescriptor, PublicRankingAchievements } from '../types';

const props = defineProps<{ achievements: PublicRankingAchievements }>();
const instanceId = useId();
const activeKey = ref<string | null>(null);
const pinnedKey = ref<string | null>(null);
const failedAssetUrls = ref<Set<string>>(new Set());

function tooltipId(key: string): string {
    return `${instanceId}-${key.replaceAll('.', '-')}`;
}

function show(key: string): void {
    activeKey.value = key;
}

function hide(key: string): void {
    if (pinnedKey.value !== key && activeKey.value === key) activeKey.value = pinnedKey.value;
}

function toggle(key: string): void {
    if (pinnedKey.value === key) {
        pinnedKey.value = null;
        activeKey.value = null;
        return;
    }
    pinnedKey.value = key;
    activeKey.value = key;
}

function close(): void {
    pinnedKey.value = null;
    activeKey.value = null;
}

function recurringCount(count: number): string {
    return count < 10 ? `×${count}` : count.toLocaleString('ja-JP');
}

function canRenderAsset(asset: AssetDescriptor): boolean {
    return asset.url !== null && !failedAssetUrls.value.has(asset.url);
}

function rejectAsset(url: string | null): void {
    if (url !== null) failedAssetUrls.value = new Set([...failedAssetUrls.value, url]);
}
</script>

<template>
    <span v-if="props.achievements.awards.length || props.achievements.monster_kills" class="ranking-achievements">
        <span v-for="award in props.achievements.awards" :key="award.key" class="achievement-badge">
            <button
                type="button"
                class="achievement-trigger"
                :aria-label="award.recurring ? `${award.name} ${award.count}回` : award.name"
                :aria-expanded="activeKey === award.key"
                :aria-describedby="activeKey === award.key ? tooltipId(award.key) : undefined"
                @mouseenter="show(award.key)"
                @mouseleave="hide(award.key)"
                @focus="show(award.key)"
                @blur="hide(award.key)"
                @click.stop="toggle(award.key)"
                @keydown.esc.stop="close"
            >
                <img v-if="canRenderAsset(award.asset)" :src="award.asset.url ?? undefined" alt="" class="achievement-icon" @error="rejectAsset(award.asset.url)">
                <span v-else class="achievement-fallback" aria-hidden="true">{{ award.asset.fallback_label.slice(0, 1) }}</span>
                <small v-if="award.recurring" class="achievement-count">{{ recurringCount(award.count) }}</small>
            </button>
            <span v-if="activeKey === award.key" :id="tooltipId(award.key)" class="achievement-tooltip" role="tooltip">
                <strong>{{ award.name }}</strong>
                <span v-if="award.recurring" class="achievement-turns">
                    <span v-for="turn in award.awarded_turns ?? []" :key="turn">{{ turn.toLocaleString('ja-JP') }}ターン</span>
                </span>
            </span>
        </span>
        <span v-if="props.achievements.monster_kills" class="achievement-badge monster-kill-badge">
            <button
                type="button"
                class="achievement-trigger"
                :aria-label="`怪獣討伐 ${props.achievements.monster_kills.total_count}体`"
                :aria-expanded="activeKey === 'monster-kills'"
                :aria-describedby="activeKey === 'monster-kills' ? tooltipId('monster-kills') : undefined"
                @mouseenter="show('monster-kills')"
                @mouseleave="hide('monster-kills')"
                @focus="show('monster-kills')"
                @blur="hide('monster-kills')"
                @click.stop="toggle('monster-kills')"
                @keydown.esc.stop="close"
            >
                <img v-if="canRenderAsset(props.achievements.monster_kills.asset)" :src="props.achievements.monster_kills.asset.url ?? undefined" alt="" class="achievement-icon monster-kill-icon" @error="rejectAsset(props.achievements.monster_kills.asset.url)">
                <span v-else class="achievement-fallback monster-kill-icon" aria-hidden="true">怪</span>
            </button>
            <span v-if="activeKey === 'monster-kills'" :id="tooltipId('monster-kills')" class="achievement-tooltip" role="tooltip">
                <strong>怪獣討伐</strong>
                <span class="achievement-turns">
                    <span v-for="species in props.achievements.monster_kills.species" :key="species.key">
                        {{ species.name }} ×{{ species.kill_count.toLocaleString('ja-JP') }}
                    </span>
                </span>
            </span>
        </span>
    </span>
</template>
