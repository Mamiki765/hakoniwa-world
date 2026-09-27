<script setup lang="ts">
import { computed } from 'vue';
import UndergroundActiveStates from './UndergroundActiveStates.vue';

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
    awakening_lifesteal_rounds_remaining?: number;
    awakening_unlocked?: boolean;
    awakening_gauge?: number;
    awakening_gauge_max?: number;
}

const props = defineProps<{
    name: string;
    side: 'player' | 'enemy';
    state: CombatantState;
    imageUrl?: string | null;
    compact?: boolean;
}>();

const healthPercent = computed(() => props.state.max_hp > 0
    ? Math.max(0, Math.min(100, Math.round((props.state.hp / props.state.max_hp) * 100)))
    : 0);
const awakeningMaximum = computed(() => Math.max(1, props.state.awakening_gauge_max ?? 1000));
const awakeningGauge = computed(() => Math.max(0, Math.min(awakeningMaximum.value, props.state.awakening_gauge ?? 0)));
const awakeningPercent = computed(() => Math.round((awakeningGauge.value / awakeningMaximum.value) * 100));
</script>

<template>
    <article class="underground-matchup-card" :class="{ 'has-portrait': imageUrl && !compact, 'is-compact': compact }" :data-side="side">
        <div v-if="imageUrl && !compact" class="underground-matchup-portrait">
            <img :src="imageUrl" :alt="`${name}の登録画像`">
        </div>
        <div class="underground-matchup-content">
            <header>
                <h2>{{ name }}</h2>
            </header>
            <div class="underground-vitals underground-matchup-vitals">
                <label>
                    <span><strong>HP {{ state.hp.toLocaleString() }}<template v-if="state.barrier > 0"> +{{ state.barrier.toLocaleString() }}</template></strong><small>/ {{ state.max_hp.toLocaleString() }}</small></span>
                    <progress class="hp" :max="state.max_hp" :value="state.hp" :aria-label="`HP ${state.hp}/${state.max_hp}、${healthPercent}%`" />
                </label>
                <label>
                    <span><strong>MP {{ state.mp.toLocaleString() }}</strong></span>
                    <progress class="mp" max="10000" :value="state.mp" :aria-label="`MP ${state.mp}`" />
                </label>
            </div>
            <div
                v-if="side === 'player' && state.awakening_unlocked"
                class="underground-combatant-awakening"
                :data-full="awakeningGauge >= awakeningMaximum"
            >
                <span>覚醒ゲージ <strong v-if="state.awakened">Awaken!</strong><strong v-else-if="awakeningGauge >= awakeningMaximum">Ready</strong></span>
                <progress :max="awakeningMaximum" :value="awakeningGauge" aria-label="覚醒ゲージ" :aria-valuetext="`${awakeningPercent}%`" />
            </div>
            <UndergroundActiveStates :state="state" />
        </div>
    </article>
</template>
