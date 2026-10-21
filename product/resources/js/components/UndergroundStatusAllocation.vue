<script setup lang="ts">
import { onUnmounted } from 'vue';
import { statLabels, type StatKey, type StpDraft } from './undergroundStpDraft';

interface StatBreakdown {
    baseline: number;
    natural_growth: number;
    allocated_stp: number;
}

const props = defineProps<{
    breakdown: Record<StatKey, StatBreakdown>;
    unspentStp: number;
    draft: StpDraft;
    busy: boolean;
}>();
const emit = defineEmits<{ confirm: [] }>();

// ＋−は押しっぱなしで加速する。最初は1ずつ、押し続けるほど間隔が短く、歩幅が大きくなる。
let holdTimer: ReturnType<typeof setTimeout> | null = null;

function beginHold(stat: StatKey, direction: 1 | -1, event: PointerEvent): void {
    if (event.button !== 0) return;
    endHold();
    let ticks = 0;
    const tick = (): void => {
        const stride = ticks < 12 ? 1 : ticks < 30 ? 5 : 25;
        if (!props.draft.step(stat, direction * stride) && !props.draft.step(stat, direction)) return;
        ticks++;
        holdTimer = setTimeout(tick, ticks === 1 ? 350 : Math.max(40, 140 - ticks * 8));
    };
    tick();
}

function endHold(): void {
    if (holdTimer !== null) clearTimeout(holdTimer);
    holdTimer = null;
}

// キーボード（EnterやSpace）で押したときは、1回ぶんだけ動かす。
function stepFromKeyboard(stat: StatKey, direction: 1 | -1, event: MouseEvent): void {
    if (event.detail === 0) props.draft.step(stat, direction);
}

onUnmounted(endHold);
</script>

<template>
    <section class="underground-progression-panel" aria-labelledby="underground-status-title">
        <header>
            <div><h2 id="underground-status-title">ステータス</h2></div>
            <p>未使用STP {{ unspentStp }} / 仮配分後 {{ draft.remaining.value }}</p>
        </header>
        <div class="underground-table-scroll">
            <table class="underground-status-table">
                <thead><tr><th scope="col">能力</th><th scope="col">現在値<br>（装備なし）</th><th scope="col">今回の配分</th></tr></thead>
                <tbody>
                    <tr v-for="(label, key) in statLabels" :key="key">
                        <th scope="row">{{ label }}</th>
                        <td>{{ breakdown[key].baseline + breakdown[key].natural_growth + breakdown[key].allocated_stp }}</td>
                        <td class="underground-stp-control">
                            <div class="ug-stp-inputs">
                            <button type="button" :disabled="busy" :aria-label="`${label}に残りの50%を配分`" @click="draft.setShare(key, 0.5)">50%</button>
                            <button type="button" class="ug-stp-step" :disabled="busy || draft.values.value[key] <= 0" :aria-label="`${label}の配分を1減らす（押し続けると速くなります）`" @pointerdown="beginHold(key, -1, $event)" @pointerup="endHold" @pointerleave="endHold" @pointercancel="endHold" @click="stepFromKeyboard(key, -1, $event)">−</button>
                            <input type="number" min="0" :max="draft.maximum(key)" step="1" inputmode="numeric" :value="draft.values.value[key]" :disabled="busy" :aria-label="`${label}の今回の配分`" @input="draft.setFromInput(key, $event)">
                            <button type="button" class="ug-stp-step" :disabled="busy || draft.values.value[key] >= draft.maximum(key)" :aria-label="`${label}の配分を1増やす（押し続けると速くなります）`" @pointerdown="beginHold(key, 1, $event)" @pointerup="endHold" @pointerleave="endHold" @pointercancel="endHold" @click="stepFromKeyboard(key, 1, $event)">＋</button>
                            <button type="button" :disabled="busy" :aria-label="`${label}に残りの100%を配分`" @click="draft.setShare(key, 1)">100%</button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="underground-progression-note">50%・100%は、ほかの能力への仮配分を除いた残りを使います。50%の端数は切り捨てます。最後に一括確定してください。</p>
        <button class="button primary" type="button" :disabled="busy || draft.total.value === 0" @click="emit('confirm')">{{ draft.total.value }} STPを一括確定</button>
    </section>
</template>
