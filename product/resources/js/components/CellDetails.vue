<script setup lang="ts">
import { computed } from 'vue';
import type { MapCell } from '../types';

const props = defineProps<{ cell: MapCell | null }>();
const usesWorkforce = computed(() => ['farm', 'factory', 'mine'].includes(props.cell?.facility ?? ''));
</script>

<template>
    <section class="selected-cell" aria-live="polite">
        <template v-if="cell">
            <h3>{{ cell.display_name }}</h3>
            <dl>
                <dt>座標</dt><dd>x={{ cell.x }}, y={{ cell.y }}</dd>
                <dt>地形</dt><dd>{{ cell.terrain_name }}</dd>
                <dt>施設</dt><dd>{{ cell.facility_name ?? 'なし' }}</dd>
                <dt>所有</dt><dd>{{ cell.owner_name ?? '中立' }}<span v-if="cell.owner_nation_id">（N{{ cell.owner_nation_id }}）</span></dd>
                <template v-for="detail in cell.details" :key="detail.key">
                    <dt>{{ detail.label }}</dt><dd>{{ detail.formatted }}</dd>
                </template>
                <template v-if="usesWorkforce">
                    <dt>労働者割当</dt><dd>ターン処理未実装</dd>
                </template>
            </dl>
        </template>
        <p v-else>セルを選択してください。</p>
    </section>
</template>
