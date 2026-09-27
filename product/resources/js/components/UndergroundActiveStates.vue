<script setup lang="ts">
import { computed } from 'vue';

interface ActiveState {
    barrier?: number;
    statuses?: Array<{ label?: string; key?: string; remaining: number; stacks: number }>;
    role_stacks?: { fighting_spirit: number; grace: number };
    taunt?: { label?: string; remaining?: number } | null;
    awakened?: boolean;
    awakening_guard_rounds_remaining?: number;
    awakening_lifesteal_rounds_remaining?: number;
}

const props = defineProps<{ state: ActiveState }>();
const visibleStatuses = computed(() => (props.state.statuses ?? []).filter(status => status.remaining > 0 || status.stacks > 0));
const hasActiveState = computed(() => (props.state.barrier ?? 0) > 0 || visibleStatuses.value.length > 0
    || (props.state.role_stacks?.fighting_spirit ?? 0) > 0 || (props.state.role_stacks?.grace ?? 0) > 0
    || props.state.awakened === true || (props.state.awakening_guard_rounds_remaining ?? 0) > 0
    || (props.state.awakening_lifesteal_rounds_remaining ?? 0) > 0 || props.state.taunt != null);
</script>

<template>
    <ul v-if="hasActiveState" class="underground-active-state" aria-label="有効な状態">
        <li v-if="(state.barrier ?? 0) > 0">障壁 {{ state.barrier }}</li>
        <li v-for="status in visibleStatuses" :key="`${status.label ?? status.key}-${status.remaining}-${status.stacks}`">
            {{ status.label ?? status.key }}<template v-if="status.stacks > 1"> {{ status.stacks }}段階</template><template v-if="status.remaining > 0"> 残{{ status.remaining }}</template>
        </li>
        <li v-if="(state.role_stacks?.fighting_spirit ?? 0) > 0">闘志 {{ state.role_stacks?.fighting_spirit }}</li>
        <li v-if="(state.role_stacks?.grace ?? 0) > 0">恩寵 {{ state.role_stacks?.grace }}</li>
        <li v-if="state.taunt">{{ state.taunt.label ?? '挑発' }}<template v-if="state.taunt.remaining"> 残{{ state.taunt.remaining }}</template></li>
        <li v-if="state.awakened">Awaken!</li>
        <li v-if="(state.awakening_guard_rounds_remaining ?? 0) > 0">覚醒防御 残{{ state.awakening_guard_rounds_remaining }}</li>
        <li v-if="(state.awakening_lifesteal_rounds_remaining ?? 0) > 0">修羅の血脈 残{{ state.awakening_lifesteal_rounds_remaining }}</li>
    </ul>
</template>
