<script setup lang="ts">
import { computed } from 'vue';

interface CombatantState {
    hp: number;
    max_hp: number;
    mp: number;
    barrier: number;
    statuses: Array<{ label: string; remaining: number; stacks: number }>;
    role_stacks: { fighting_spirit: number; grace: number };
    taunt?: { label?: string; remaining?: number } | null;
    awakened?: boolean;
    awakening_technique_used?: boolean;
    awakening_guard_rounds_remaining?: number;
}

const props = defineProps<{
    name: string;
    side: 'player' | 'enemy';
    state: CombatantState;
    imageUrl?: string | null;
}>();

const visibleStatuses = computed(() => props.state.statuses.filter((status) => status.remaining > 0 || status.stacks > 0));
const healthPercent = computed(() => props.state.max_hp > 0
    ? Math.max(0, Math.min(100, Math.round((props.state.hp / props.state.max_hp) * 100)))
    : 0);
</script>

<template>
    <article class="underground-matchup-card" :class="{ 'has-portrait': imageUrl }" :data-side="side">
        <div v-if="imageUrl" class="underground-matchup-portrait">
            <img :src="imageUrl" :alt="`${name}の登録画像`">
        </div>
        <div class="underground-matchup-content">
            <header>
                <h2>{{ name }}</h2>
            </header>
            <div class="underground-vitals underground-matchup-vitals">
                <label>
                    <span><strong>HP {{ state.hp.toLocaleString() }}</strong><small>/ {{ state.max_hp.toLocaleString() }}</small></span>
                    <progress class="hp" :max="state.max_hp" :value="state.hp" :aria-label="`HP ${state.hp}/${state.max_hp}、${healthPercent}%`" />
                </label>
                <label>
                    <span><strong>MP {{ state.mp.toLocaleString() }}</strong><small class="visually-hidden">/ 10,000</small></span>
                    <progress class="mp" max="10000" :value="state.mp" :aria-label="`MP ${state.mp}/10000`" />
                </label>
            </div>
            <ul v-if="state.barrier > 0 || visibleStatuses.length > 0 || state.role_stacks.fighting_spirit > 0 || state.role_stacks.grace > 0 || state.awakened || (state.awakening_guard_rounds_remaining ?? 0) > 0 || state.taunt" class="underground-active-state" aria-label="有効な状態">
                <li v-if="state.barrier > 0">障壁 {{ state.barrier }}</li>
                <li v-for="status in visibleStatuses" :key="`${status.label}-${status.remaining}-${status.stacks}`">
                    {{ status.label }}<template v-if="status.stacks > 1"> {{ status.stacks }}段階</template><template v-if="status.remaining > 0"> 残{{ status.remaining }}</template>
                </li>
                <li v-if="state.role_stacks.fighting_spirit > 0">闘志 {{ state.role_stacks.fighting_spirit }}</li>
                <li v-if="state.role_stacks.grace > 0">恩寵 {{ state.role_stacks.grace }}</li>
                <li v-if="state.taunt">{{ state.taunt.label ?? '挑発' }}<template v-if="state.taunt.remaining"> 残{{ state.taunt.remaining }}</template></li>
                <li v-if="state.awakened">覚醒中</li>
                <li v-if="(state.awakening_guard_rounds_remaining ?? 0) > 0">覚醒防御 残{{ state.awakening_guard_rounds_remaining }}</li>
            </ul>
        </div>
    </article>
</template>
