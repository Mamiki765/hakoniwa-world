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
                <dt>所有</dt><dd>{{ cell.owner_name ?? '中立' }}<span v-if="cell.owner_nation_number !== null">（N{{ cell.owner_nation_number }}）</span></dd>
                <template v-if="cell.ship">
                    <dt>船</dt><dd>{{ cell.ship.name }}</dd>
                    <dt>船HP</dt><dd>{{ cell.ship.current_hp }}/{{ cell.ship.max_hp }}</dd>
                    <dt>船舶所有</dt>
                    <dd>{{ cell.ship.owner_nation.name }}（N{{ cell.ship.owner_nation.nation_number }}）</dd>
                </template>
                <template v-for="detail in cell.details" :key="detail.key">
                    <dt>{{ detail.label }}</dt><dd>{{ detail.formatted }}</dd>
                </template>
                <template v-if="cell.monster">
                    <dt>怪獣</dt><dd>{{ cell.monster.name }}</dd>
                    <dt>現在HP</dt><dd>{{ cell.monster.current_hp }}</dd>
                    <dt>出現時HP</dt><dd>{{ cell.monster.spawned_max_hp }}（定義 {{ cell.monster.hp_range.min }}～{{ cell.monster.hp_range.max }}）</dd>
                    <dt>能力</dt><dd>{{ cell.monster.skill_description }}</dd>
                    <dt>硬化</dt><dd>{{ cell.monster.hardened_now ? '硬化中' : 'なし' }}</dd>
                    <dt>所在Nation</dt>
                    <dd>{{ cell.monster.host_nation?.name ?? '無所属' }}<span v-if="cell.monster.host_nation">（N{{ cell.monster.host_nation.nation_number }}）</span></dd>
                </template>
                <template v-if="usesWorkforce">
                    <dt>労働者割当</dt><dd>ターン処理未実装</dd>
                </template>
            </dl>
        </template>
        <p v-else>セルを選択してください。</p>
    </section>
</template>
