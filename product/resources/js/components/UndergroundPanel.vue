<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';

type Stage = 'not_started' | 'initial_descent' | 'tutorial_ready' | 'escape_pending'
    | 'returned_after_tutorial' | 'shopkeeper_encounter' | 'shopkeeper_naming'
    | 'special_loss_pending' | 'special_loss_complete' | 'shop_explanation' | 'underground_open';

interface BattleAction {
    round: number;
    side: string;
    action: string;
    amount: number;
}

interface Battle {
    id: string;
    context: 'tutorial' | 'scripted_loss';
    encounter_name: string;
    result: 'victory' | 'defeat';
    rounds: number;
    xp_awarded: number;
    shard_delta: number;
    detail_available: boolean;
    actions: BattleAction[] | null;
}

interface UndergroundState {
    stage: Stage;
    secretary_name: string;
    combat_level: number;
    combat_xp: number;
    next_level_xp: number;
    shard_balance: number;
    shopkeeper_name: string | null;
    battle: Battle | null;
}

const emit = defineEmits<{ returnToSecretary: [] }>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const error = ref('');
const scenePage = ref(0);
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const selectedBattle = ref<Battle | null>(null);
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);

function requestId(): string {
    return crypto.randomUUID();
}

async function refresh(returnIfTutorialAlreadyFinished = true): Promise<void> {
    state.value = await api<UndergroundState>('/api/v1/me/underground');
    scenePage.value = 0;
    if (returnIfTutorialAlreadyFinished && state.value.stage === 'returned_after_tutorial') {
        emit('returnToSecretary');
        return;
    }
    if (state.value.stage === 'underground_open') await loadBattles();
}

async function mutate(path: string, body: Record<string, unknown> = {}): Promise<boolean> {
    if (busy.value) return false;
    busy.value = true;
    error.value = '';
    try {
        state.value = await api<UndergroundState>(path, {
            method: 'POST',
            body: JSON.stringify({ request_id: requestId(), ...body }),
        });
        scenePage.value = 0;
        if (state.value.stage === 'returned_after_tutorial') {
            emit('returnToSecretary');
            return true;
        }
        if (state.value.stage === 'underground_open') await loadBattles();
        return true;
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 409) await refresh();
        error.value = caught instanceof Error ? caught.message : '（ダミー）';
        return false;
    } finally {
        busy.value = false;
    }
}

async function nextDescentPage(): Promise<void> {
    if (scenePage.value < 3) {
        scenePage.value++;
        return;
    }
    await mutate('/api/v1/me/underground/story/advance', { action: 'initial_story_complete' });
}

async function nextEncounterPage(): Promise<void> {
    if (scenePage.value < 1) {
        scenePage.value++;
        return;
    }
    await mutate('/api/v1/me/underground/story/advance', { action: 'shopkeeper_encounter_complete' });
}

async function completeEscape(): Promise<void> {
    await mutate('/api/v1/me/underground/story/advance', { action: 'escape_complete' });
}

async function submitName(): Promise<void> {
    await mutate('/api/v1/me/underground/shopkeeper/name', { name: shopkeeperName.value });
}

async function loadBattles(): Promise<void> {
    battles.value = await api<Battle[]>('/api/v1/me/underground/battles');
}

async function showBattle(battle: Battle): Promise<void> {
    error.value = '';
    try {
        selectedBattle.value = battle.detail_available
            ? await api<Battle>(`/api/v1/me/underground/battles/${battle.id}`)
            : battle;
    } catch (caught) {
        selectedBattle.value = battle;
        error.value = caught instanceof Error ? caught.message : '戦闘ログを読み込めませんでした。';
    }
}

async function enter(): Promise<void> {
    try {
        await refresh(false);
        if (state.value?.stage === 'not_started' || state.value?.stage === 'returned_after_tutorial') {
            await mutate('/api/v1/me/underground/entry');
        }
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '（ダミー）';
    }
}

function battleResultLabel(result: Battle['result']): string {
    return result === 'victory' ? '勝利' : '敗北';
}

onMounted(() => { void enter(); });
</script>

<template>
    <section class="panel underground-panel" aria-live="polite">
        <p v-if="busy && state === null" class="status">（ダミー）</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="state?.stage === 'initial_descent'">
            <p class="underground-story">（ダミー）</p>
            <button class="button primary" type="button" :disabled="busy" @click="nextDescentPage">OK</button>
        </template>

        <template v-else-if="state?.stage === 'tutorial_ready'">
            <p class="underground-story">（ダミー）</p>
            <h1>ジャイアントラット</h1>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/tutorial')">戦闘開始</button>
        </template>

        <template v-else-if="state?.stage === 'escape_pending'">
            <p class="underground-story">（ダミー）</p>
            <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                <h2>戦闘ログ</h2>
                <p>{{ currentBattle.encounter_name }} / {{ currentBattle.rounds }}</p>
                <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}</p>
                <ol v-if="currentBattle.actions">
                    <li v-for="(action, index) in currentBattle.actions" :key="`${action.round}-${index}`">
                        {{ action.round }} / {{ action.side }} / {{ action.action }} / {{ action.amount }}
                    </li>
                </ol>
            </section>
            <button class="button primary" type="button" :disabled="busy" @click="completeEscape">OK</button>
        </template>

        <template v-else-if="state?.stage === 'shopkeeper_encounter'">
            <p class="underground-story">（ダミー）</p>
            <button class="button primary" type="button" :disabled="busy" @click="nextEncounterPage">OK</button>
        </template>

        <template v-else-if="state?.stage === 'shopkeeper_naming'">
            <p class="underground-story">（ダミー）</p>
            <form class="underground-name-form" @submit.prevent="submitName">
                <label for="underground-shopkeeper-name">名前を入力</label>
                <input id="underground-shopkeeper-name" v-model="shopkeeperName" required autocomplete="off" :disabled="busy">
                <button class="button primary" type="submit" :disabled="busy">決定</button>
            </form>
        </template>

        <template v-else-if="state?.stage === 'special_loss_pending'">
            <p class="underground-story">（ダミー）</p>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/scripted-loss')">戦闘開始</button>
        </template>

        <template v-else-if="state?.stage === 'special_loss_complete'">
            <p class="underground-story">（ダミー）</p>
            <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                <h2>戦闘ログ</h2>
                <p>{{ currentBattle.encounter_name }} / {{ currentBattle.rounds }}</p>
                <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}</p>
                <ol v-if="currentBattle.actions">
                    <li v-for="(action, index) in currentBattle.actions" :key="`${action.round}-${index}`">
                        {{ action.round }} / {{ action.side }} / {{ action.action }} / {{ action.amount }}
                    </li>
                </ol>
            </section>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'special_loss_aftermath_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'shop_explanation'">
            <p class="underground-story">（ダミー）</p>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'shop_explanation_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'underground_open'">
            <h1>{{ state.secretary_name }}</h1>
            <dl class="underground-summary">
                <div><dt>戦闘Lv</dt><dd>{{ state.combat_level }}</dd></div>
                <div><dt>経験値</dt><dd>{{ state.combat_xp }} / {{ state.next_level_xp }}</dd></div>
                <div><dt>輝石の欠片</dt><dd>{{ state.shard_balance }}</dd></div>
                <div><dt>ショップ</dt><dd>{{ state.shopkeeper_name }}</dd></div>
            </dl>
            <div class="underground-entries">
                <button type="button" disabled>周囲を探索<br><small>準備中</small></button>
                <button type="button" disabled>試練<br><small>準備中</small></button>
                <button type="button" disabled>ショップ<br><small>準備中</small></button>
            </div>
            <section class="underground-history" aria-labelledby="underground-history-title">
                <h2 id="underground-history-title">戦闘ログ</h2>
                <ul>
                    <li v-for="battle in battles" :key="battle.id">
                        <button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battle.rounds }}</button>
                    </li>
                </ul>
                <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                    <p>{{ currentBattle.encounter_name }} / {{ currentBattle.rounds }}</p>
                    <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                    <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}</p>
                    <ol v-if="currentBattle.actions">
                        <li v-for="(action, index) in currentBattle.actions" :key="`${action.round}-${index}`">
                            {{ action.round }} / {{ action.side }} / {{ action.action }} / {{ action.amount }}
                        </li>
                    </ol>
                </section>
            </section>
        </template>
    </section>
</template>
