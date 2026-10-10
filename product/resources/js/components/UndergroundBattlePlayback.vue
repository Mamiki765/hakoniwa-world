<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

export interface PlaybackVitals {
    hp: number;
    max_hp: number;
}

export interface PlaybackHit {
    targetId: string;
    kind: 'damage' | 'heal' | 'miss';
    amount: number;
    critical: boolean;
}

export interface PlaybackStep {
    text: string;
    tone: string;
    highlight: string | null;
    hits: PlaybackHit[];
    updates: Array<{ targetId: string; hp: number | null; max_hp?: number }>;
}

export interface PlaybackRound {
    round: number;
    start: Record<string, PlaybackVitals> | null;
    end: Record<string, PlaybackVitals> | null;
    steps: PlaybackStep[];
}

export interface PlaybackCombatant {
    id: string;
    name: string;
    side: 'player' | 'enemy';
}

const props = defineProps<{
    rounds: PlaybackRound[];
    combatants: PlaybackCombatant[];
    resultLabel: string;
}>();

interface Popup {
    id: number;
    targetId: string;
    text: string;
    kind: PlaybackHit['kind'];
    critical: boolean;
    // 小さな数字が重ならないよう、出る位置を少しずつずらす。
    offset: number;
}

interface RoundTotal {
    damage: number;
    heal: number;
    critical: boolean;
}

const roundIndex = ref(0);
// -1 はラウンドの開始時（まだ誰も動いていない）。
const stepIndex = ref(-1);
const playing = ref(false);
const speed = ref(1);
const finished = ref(false);
// ラウンドの行動が出そろい、そのラウンドの合計を大きく出している状態。
const settled = ref(false);
const vitals = ref<Record<string, PlaybackVitals>>({});
const totals = ref<Record<string, RoundTotal>>({});
const popups = ref<Popup[]>([]);
const flashing = ref<Set<string>>(new Set());
let popupSequence = 0;
let timer: ReturnType<typeof setTimeout> | null = null;
const transientTimers = new Set<ReturnType<typeof setTimeout>>();

const currentRound = computed(() => props.rounds[roundIndex.value] ?? null);
const currentStep = computed(() => currentRound.value?.steps[stepIndex.value] ?? null);
const recentSteps = computed(() => currentRound.value?.steps.slice(Math.max(0, stepIndex.value - 3), stepIndex.value + 1) ?? []);
const players = computed(() => props.combatants.filter((combatant) => combatant.side === 'player'));
const enemies = computed(() => props.combatants.filter((combatant) => combatant.side === 'enemy'));
const roundDone = computed(() => currentRound.value !== null && stepIndex.value >= currentRound.value.steps.length - 1);

function cloneVitals(source: Record<string, PlaybackVitals> | null): Record<string, PlaybackVitals> {
    return Object.fromEntries(Object.entries(source ?? {}).map(([id, value]) => [id, { hp: value.hp, max_hp: value.max_hp }]));
}

function later(callback: () => void, delay: number): void {
    const handle = setTimeout(() => {
        transientTimers.delete(handle);
        callback();
    }, delay);
    transientTimers.add(handle);
}

function clearTransient(): void {
    for (const handle of transientTimers) clearTimeout(handle);
    transientTimers.clear();
    popups.value = [];
    flashing.value = new Set();
}

// ラウンドの開始時は、serverが持っている正しい値に合わせる。
function enterRound(index: number): void {
    roundIndex.value = index;
    stepIndex.value = -1;
    settled.value = false;
    totals.value = {};
    const round = props.rounds[index];
    if (round?.start) vitals.value = { ...vitals.value, ...cloneVitals(round.start) };
}

function reset(): void {
    stop();
    clearTransient();
    finished.value = false;
    vitals.value = {};
    enterRound(0);
}

function showPopup(hit: PlaybackHit): void {
    const id = ++popupSequence;
    const text = hit.kind === 'miss' ? 'MISS' : `${hit.kind === 'heal' ? '+' : '−'}${hit.amount.toLocaleString('ja-JP')}`;
    popups.value = [...popups.value, { id, targetId: hit.targetId, text, kind: hit.kind, critical: hit.critical, offset: (id * 37) % 70 }];
    later(() => {
        popups.value = popups.value.filter((popup) => popup.id !== id);
    }, 900);
    if (hit.critical) {
        flashing.value = new Set([...flashing.value, hit.targetId]);
        later(() => {
            flashing.value = new Set([...flashing.value].filter((targetId) => targetId !== hit.targetId));
        }, 450);
    }
}

// 行動の途中のHPは、ログの数字を足し引きした見込み。ラウンドの区切りで正しい値に戻す。
function applyStep(step: PlaybackStep): void {
    const nextVitals = { ...vitals.value };
    const nextTotals = { ...totals.value };
    for (const hit of step.hits) {
        showPopup(hit);
        if (hit.kind === 'miss') continue;
        const total = nextTotals[hit.targetId] ?? { damage: 0, heal: 0, critical: false };
        nextTotals[hit.targetId] = hit.kind === 'heal'
            ? { ...total, heal: total.heal + hit.amount }
            : { ...total, damage: total.damage + hit.amount, critical: total.critical || hit.critical };
        const target = nextVitals[hit.targetId];
        if (target === undefined) continue;
        const hp = hit.kind === 'heal' ? target.hp + hit.amount : target.hp - hit.amount;
        nextVitals[hit.targetId] = { ...target, hp: Math.max(0, Math.min(target.max_hp, hp)) };
    }
    for (const update of step.updates) {
        if (update.hp === null) {
            delete nextVitals[update.targetId];
            continue;
        }
        const maxHp = update.max_hp ?? nextVitals[update.targetId]?.max_hp;
        if (maxHp !== undefined) nextVitals[update.targetId] = { hp: update.hp, max_hp: maxHp };
    }
    vitals.value = nextVitals;
    totals.value = nextTotals;
}

function settleRound(): void {
    const round = currentRound.value;
    if (round?.end) vitals.value = { ...vitals.value, ...cloneVitals(round.end) };
    settled.value = true;
    if (roundIndex.value + 1 >= props.rounds.length) finished.value = true;
}

// 1ラウンドぶんの行動を続けざまに出す。出そろったら、そのラウンドの合計を大きく残す。
function runRound(continueAfter: boolean): void {
    if (finished.value) {
        playing.value = false;
        return;
    }
    if (roundDone.value && settled.value) enterRound(roundIndex.value + 1);
    const tick = (): void => {
        timer = null;
        const round = currentRound.value;
        if (round === null) return;
        if (stepIndex.value + 1 < round.steps.length) {
            stepIndex.value++;
            applyStep(round.steps[stepIndex.value]!);
        }
        if (stepIndex.value >= round.steps.length - 1) {
            timer = setTimeout(() => {
                timer = null;
                settleRound();
                if (continueAfter && playing.value && !finished.value) timer = setTimeout(() => runRound(true), 1400 / speed.value);
                else playing.value = false;
            }, 500 / speed.value);
            return;
        }
        timer = setTimeout(tick, 170 / speed.value);
    };
    playing.value = true;
    tick();
}

function stop(): void {
    playing.value = false;
    if (timer !== null) clearTimeout(timer);
    timer = null;
}

function togglePlay(): void {
    if (playing.value) {
        stop();
        return;
    }
    if (finished.value) reset();
    runRound(true);
}

function playOneRound(): void {
    stop();
    runRound(false);
}

function jumpToResult(): void {
    stop();
    clearTransient();
    const last = props.rounds.length - 1;
    if (last < 0) return;
    for (const round of props.rounds) {
        if (round.start) vitals.value = { ...vitals.value, ...cloneVitals(round.start) };
        if (round.end) vitals.value = { ...vitals.value, ...cloneVitals(round.end) };
    }
    roundIndex.value = last;
    stepIndex.value = props.rounds[last]!.steps.length - 1;
    totals.value = {};
    settled.value = true;
    finished.value = true;
}

function hpPercent(id: string): number {
    const value = vitals.value[id];
    return value === undefined || value.max_hp <= 0 ? 0 : Math.max(0, Math.min(100, value.hp / value.max_hp * 100));
}

watch(() => props.rounds, reset, { immediate: true });
onBeforeUnmount(() => {
    stop();
    clearTransient();
});
</script>

<template>
    <section class="ug-playback" aria-label="戦闘の再生">
        <div class="ug-playback-controls">
            <button type="button" class="ug-playback-play" :aria-pressed="playing" @click="togglePlay">{{ playing ? '⏸ 止める' : finished ? '↺ 最初から' : '▶ 再生' }}</button>
            <button type="button" :disabled="finished || playing" @click="playOneRound">1ラウンド送る</button>
            <button type="button" :disabled="finished" @click="jumpToResult">結果へ</button>
            <span class="ug-playback-speed" role="group" aria-label="再生の速さ">
                <button v-for="option in [1, 2, 4]" :key="option" type="button" :aria-pressed="speed === option" @click="speed = option">×{{ option }}</button>
            </span>
        </div>
        <p class="ug-playback-round" aria-live="polite">
            <strong v-if="currentRound">第{{ currentRound.round }}ラウンド</strong>
            <span v-if="finished">{{ resultLabel }}</span>
            <span v-else-if="currentRound">{{ Math.max(0, stepIndex + 1) }} / {{ currentRound.steps.length }} 行動</span>
        </p>
        <div class="ug-playback-field">
            <ul v-for="(team, teamIndex) in [players, enemies]" :key="teamIndex" class="ug-playback-team" :class="teamIndex === 0 ? 'is-player' : 'is-enemy'">
                <li
                    v-for="combatant in team"
                    :key="combatant.id"
                    class="ug-playback-card"
                    :class="{ 'is-down': vitals[combatant.id]?.hp === 0, 'is-flashing': flashing.has(combatant.id) }"
                >
                    <strong>{{ combatant.name }}</strong>
                    <span v-if="vitals[combatant.id]" class="ug-playback-hp">HP {{ vitals[combatant.id]!.hp.toLocaleString('ja-JP') }}<small> / {{ vitals[combatant.id]!.max_hp.toLocaleString('ja-JP') }}</small></span>
                    <span v-else class="ug-playback-hp">HP —</span>
                    <span class="ug-playback-bar" :class="{ 'is-unknown': !vitals[combatant.id] }" aria-hidden="true"><i v-if="vitals[combatant.id]" :style="{ width: `${hpPercent(combatant.id)}%` }" /></span>
                    <span
                        v-for="popup in popups.filter((candidate) => candidate.targetId === combatant.id)"
                        :key="popup.id"
                        class="ug-playback-popup"
                        :class="[`is-${popup.kind}`, { 'is-critical': popup.critical }]"
                        :style="{ right: `${8 + popup.offset}px` }"
                        aria-hidden="true"
                    >{{ popup.text }}</span>
                    <span v-if="settled && totals[combatant.id]" class="ug-playback-total" :class="{ 'is-critical': totals[combatant.id]!.critical }">
                        <b v-if="totals[combatant.id]!.damage > 0" class="is-damage">−{{ totals[combatant.id]!.damage.toLocaleString('ja-JP') }}</b>
                        <b v-if="totals[combatant.id]!.heal > 0" class="is-heal">+{{ totals[combatant.id]!.heal.toLocaleString('ja-JP') }}</b>
                        <small>このラウンドの合計</small>
                    </span>
                </li>
            </ul>
        </div>
        <ol class="ug-playback-lines" aria-live="polite">
            <li v-for="(step, index) in recentSteps" :key="`${roundIndex}:${stepIndex - recentSteps.length + 1 + index}`" :class="[step.tone, { 'is-current': step === currentStep }]">
                <span v-if="step.highlight" class="underground-event-badge">{{ step.highlight }}</span>{{ step.text }}
            </li>
            <li v-if="recentSteps.length === 0" class="ug-muted">ラウンドの開始時です。</li>
        </ol>
    </section>
</template>
