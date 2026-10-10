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
}

const roundIndex = ref(0);
// -1 はラウンドの開始時（まだ誰も動いていない）。
const stepIndex = ref(-1);
const playing = ref(false);
const speed = ref(1);
const finished = ref(false);
const vitals = ref<Record<string, PlaybackVitals>>({});
const popups = ref<Popup[]>([]);
let popupSequence = 0;
let timer: ReturnType<typeof setTimeout> | null = null;
const popupTimers = new Set<ReturnType<typeof setTimeout>>();

const currentRound = computed(() => props.rounds[roundIndex.value] ?? null);
const currentStep = computed(() => currentRound.value?.steps[stepIndex.value] ?? null);
const recentSteps = computed(() => currentRound.value?.steps.slice(Math.max(0, stepIndex.value - 3), stepIndex.value + 1) ?? []);
const players = computed(() => props.combatants.filter((combatant) => combatant.side === 'player'));
const enemies = computed(() => props.combatants.filter((combatant) => combatant.side === 'enemy'));

function cloneVitals(source: Record<string, PlaybackVitals> | null): Record<string, PlaybackVitals> {
    return Object.fromEntries(Object.entries(source ?? {}).map(([id, value]) => [id, { hp: value.hp, max_hp: value.max_hp }]));
}

// ラウンドの開始時は、serverが持っている正しい値に合わせる。
function enterRound(index: number): void {
    roundIndex.value = index;
    stepIndex.value = -1;
    const round = props.rounds[index];
    if (round?.start) vitals.value = { ...vitals.value, ...cloneVitals(round.start) };
}

function clearPopups(): void {
    for (const popupTimer of popupTimers) clearTimeout(popupTimer);
    popupTimers.clear();
    popups.value = [];
}

function reset(): void {
    stop();
    clearPopups();
    finished.value = false;
    vitals.value = {};
    enterRound(0);
}

function showPopup(hit: PlaybackHit): void {
    const id = ++popupSequence;
    const text = hit.kind === 'miss' ? 'MISS' : `${hit.kind === 'heal' ? '+' : '−'}${hit.amount.toLocaleString('ja-JP')}`;
    popups.value = [...popups.value, { id, targetId: hit.targetId, text, kind: hit.kind, critical: hit.critical }];
    const popupTimer = setTimeout(() => {
        popupTimers.delete(popupTimer);
        popups.value = popups.value.filter((popup) => popup.id !== id);
    }, 1100);
    popupTimers.add(popupTimer);
}

// 行動の途中のHPは、ログの数字を足し引きした見込み。ラウンドの区切りで正しい値に戻す。
function applyStep(step: PlaybackStep): void {
    const next = { ...vitals.value };
    for (const hit of step.hits) {
        showPopup(hit);
        const target = next[hit.targetId];
        if (target === undefined || hit.kind === 'miss') continue;
        const hp = hit.kind === 'heal' ? target.hp + hit.amount : target.hp - hit.amount;
        next[hit.targetId] = { ...target, hp: Math.max(0, Math.min(target.max_hp, hp)) };
    }
    vitals.value = next;
}

function advance(): boolean {
    const round = currentRound.value;
    if (round === null || finished.value) return false;
    if (stepIndex.value + 1 < round.steps.length) {
        stepIndex.value++;
        applyStep(round.steps[stepIndex.value]!);
        if (stepIndex.value === round.steps.length - 1 && round.end) vitals.value = { ...vitals.value, ...cloneVitals(round.end) };
        return true;
    }
    if (roundIndex.value + 1 < props.rounds.length) {
        enterRound(roundIndex.value + 1);
        return true;
    }
    finished.value = true;
    return false;
}

function nextRound(): void {
    stop();
    clearPopups();
    const round = currentRound.value;
    if (round?.end) vitals.value = { ...vitals.value, ...cloneVitals(round.end) };
    if (roundIndex.value + 1 < props.rounds.length) enterRound(roundIndex.value + 1);
    else jumpToResult();
}

function jumpToResult(): void {
    stop();
    clearPopups();
    const last = props.rounds.length - 1;
    if (last < 0) return;
    roundIndex.value = last;
    stepIndex.value = props.rounds[last]!.steps.length - 1;
    for (const round of props.rounds) {
        if (round.start) vitals.value = { ...vitals.value, ...cloneVitals(round.start) };
        if (round.end) vitals.value = { ...vitals.value, ...cloneVitals(round.end) };
    }
    finished.value = true;
}

function schedule(): void {
    if (!playing.value) return;
    timer = setTimeout(() => {
        timer = null;
        if (!advance()) {
            playing.value = false;
            return;
        }
        schedule();
    }, 900 / speed.value);
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
    playing.value = true;
    if (advance()) schedule();
    else playing.value = false;
}

function stepOnce(): void {
    stop();
    advance();
}

function hpPercent(id: string): number {
    const value = vitals.value[id];
    return value === undefined || value.max_hp <= 0 ? 0 : Math.max(0, Math.min(100, value.hp / value.max_hp * 100));
}

watch(() => props.rounds, reset, { immediate: true });
onBeforeUnmount(() => {
    stop();
    clearPopups();
});
</script>

<template>
    <section class="ug-playback" aria-label="戦闘の再生">
        <div class="ug-playback-controls">
            <button type="button" class="ug-playback-play" :aria-pressed="playing" @click="togglePlay">{{ playing ? '⏸ 止める' : finished ? '↺ 最初から' : '▶ 再生' }}</button>
            <button type="button" :disabled="finished" @click="stepOnce">1行動送る</button>
            <button type="button" :disabled="finished" @click="nextRound">ラウンド送り</button>
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
                <li v-for="combatant in team" :key="combatant.id" class="ug-playback-card" :class="{ 'is-down': vitals[combatant.id]?.hp === 0 }">
                    <strong>{{ combatant.name }}</strong>
                    <span class="ug-playback-hp">HP {{ (vitals[combatant.id]?.hp ?? 0).toLocaleString('ja-JP') }}<small> / {{ (vitals[combatant.id]?.max_hp ?? 0).toLocaleString('ja-JP') }}</small></span>
                    <span class="ug-playback-bar" aria-hidden="true"><i :style="{ width: `${hpPercent(combatant.id)}%` }" /></span>
                    <span
                        v-for="popup in popups.filter((candidate) => candidate.targetId === combatant.id)"
                        :key="popup.id"
                        class="ug-playback-popup"
                        :class="[`is-${popup.kind}`, { 'is-critical': popup.critical }]"
                        aria-hidden="true"
                    >{{ popup.text }}</span>
                </li>
            </ul>
        </div>
        <ol class="ug-playback-lines" aria-live="polite">
            <li v-for="(step, index) in recentSteps" :key="`${roundIndex}:${stepIndex - recentSteps.length + 1 + index}`" :class="[step.tone, { 'is-current': step === currentStep }]">
                <span v-if="step.highlight" class="underground-event-badge">{{ step.highlight }}</span>{{ step.text }}
            </li>
            <li v-if="recentSteps.length === 0" class="ug-muted">ラウンドの開始時です。</li>
        </ol>
        <p class="ug-playback-note">行動の途中のHPは、ログの数字を足し引きした見込みです。ラウンドの区切りで正しい値に合わせます。</p>
    </section>
</template>
