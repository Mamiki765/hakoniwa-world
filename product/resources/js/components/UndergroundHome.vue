<script setup lang="ts">
import { computed, ref } from 'vue';
import UndergroundScene from './UndergroundScene.vue';
import { type SceneAsset, type UndergroundScene as SceneDefinition, type UndergroundDestination, type HomeBackground, sceneAssetVisible } from './undergroundScenes';

export interface HomeCompanion {
    secretary_id: number;
    display_name: string;
    current_hp: number;
    max_hp: number;
    awakening_gauge: number;
    icon_url?: string | null;
}

const props = withDefaults(defineProps<{
    name: string;
    level: number;
    hp: number;
    maxHp: number;
    awakening?: number;
    awakeningMax?: number;
    shards: number;
    banked: number;
    innCost: number;
    tickets: number;
    xpRemaining: number;
    unspentStp?: number;
    growthPath?: string;
    scene?: SceneDefinition;
    portrait?: SceneAsset | null;
    awakenedPortrait?: SceneAsset | null;
    showAi?: boolean;
    companions?: HomeCompanion[];
    destination?: string;
    departureDisabled?: boolean;
    departureReason?: string;
    activeTrial?: string;
    backgrounds?: HomeBackground[];
    backgroundKey?: string;
    busy?: boolean;
    restDisabled?: boolean;
    resting?: boolean;
    iconUrl?: string | null;
}>(), {
    awakening: 0, awakeningMax: 1000, showAi: true, companions: () => [],
    growthPath: '', scene: undefined, portrait: null, awakenedPortrait: null,
    destination: '', departureReason: '', activeTrial: '', backgrounds: undefined,
    backgroundKey: 'placeholder', iconUrl: null, unspentStp: 0,
});
defineEmits<{ navigate: [destination: UndergroundDestination]; depart: []; continueTrial: []; rest: []; allocateStp: []; background: [key: string] }>();
const awakenedView = ref(false);
const backgroundOpen = ref(false);
const backgroundOptions = computed(() => (props.backgrounds ?? [{ key: 'placeholder', name: '水晶の洞窟', asset: null }])
    .filter(option => option.asset === null || sceneAssetVisible(option.asset, props.showAi)));
const canSwitch = computed(() => sceneAssetVisible(props.awakenedPortrait, props.showAi)
    && props.awakenedPortrait?.url !== props.portrait?.url);
const chosenPortrait = computed(() => awakenedView.value && canSwitch.value ? props.awakenedPortrait : props.portrait);
const homeScene = computed<SceneDefinition>(() => ({
    background: props.scene?.background,
    actors: chosenPortrait.value ? [{
        key: 'self', name: props.name, asset: chosenPortrait.value, own_character: true,
        placement: { x: 76, y: 102, height: 96 }, mobile: { x: 80, height: 99 },
    }] : [],
}));
const number = (value: number) => value.toLocaleString('ja-JP');
const percentage = (value: number, max: number) => `${Math.min(100, Math.max(0, max > 0 ? value / max * 100 : 0))}%`;
</script>

<template>
    <div class="ug-home">
        <header class="ug-wallet" aria-label="所持品">
            <h1>地底</h1>
            <dl>
                <div><dt><span aria-hidden="true">◆</span> 手持ち</dt><dd>{{ number(shards) }} <small>G</small></dd></div>
                <div><dt>預金</dt><dd>{{ number(banked) }} <small>G</small></dd></div>
                <div><dt>スキップチケット</dt><dd>{{ number(tickets) }} <small>枚</small></dd></div>
            </dl>
        </header>
        <div class="ug-home-tools">
            <button class="ug-text-button" type="button" :aria-expanded="backgroundOpen" @click="backgroundOpen = !backgroundOpen">背景を切り替える</button>
            <div v-if="backgroundOpen" class="ug-background-options" aria-label="ホームの背景">
                <button v-for="option in backgroundOptions" :key="option.key" type="button" :disabled="busy" :aria-pressed="(backgroundOptions.some(item => item.key === backgroundKey) ? backgroundKey : 'placeholder') === option.key" @click="$emit('background', option.key)">{{ option.name }}</button>
                <p class="ug-muted">クリアしたエリアの背景を選べます。</p>
            </div>
        </div>
        <UndergroundScene :scene="homeScene" :show-ai="showAi" label="地底のホーム">
            <div class="ug-home-stage">
                <section class="ug-self-card" aria-label="自分の秘書">
                    <div class="ug-level-row">
                        <div class="ug-level">Lv. <strong>{{ level }}</strong></div>
                        <button v-if="unspentStp > 0" class="ug-stp-jump" type="button" :aria-label="`未配分STP ${number(unspentStp)}、配分する`" @click="$emit('allocateStp')">STP {{ number(unspentStp) }} <span aria-hidden="true">→</span></button>
                    </div>
                    <h2>{{ name }}</h2>
                    <p v-if="growthPath" class="ug-growth-path">{{ growthPath }}</p>
                    <div class="ug-gauge-label"><span class="ug-hp-actions">HP <button class="ug-quick-rest" type="button" :disabled="busy || restDisabled || resting" :aria-label="resting ? '休憩中' : `宿で休む（${number(innCost)}G）`" :title="`宿で休む（${number(innCost)}G・HPを全回復）`" @click="$emit('rest')">＋ <small>{{ number(innCost) }}G</small></button></span><strong>{{ number(hp) }} <small>/ {{ number(maxHp) }}</small></strong></div>
                    <div class="ug-meter" role="progressbar" aria-label="HP" :aria-valuenow="hp" :aria-valuemax="maxHp" :aria-valuemin="0"><span :style="{ width: percentage(hp, maxHp) }"></span></div>
                    <div class="ug-gauge-label"><span>覚醒</span></div>
                    <div class="ug-meter ug-meter-awakening" role="progressbar" aria-label="覚醒ゲージ" :aria-valuenow="awakening" :aria-valuemax="awakeningMax" :aria-valuemin="0"><span :style="{ width: percentage(awakening, awakeningMax) }"></span></div>
                    <p class="ug-next-level">次のレベルまで <strong>{{ number(xpRemaining) }}</strong> EXP</p>
                    <button class="ug-text-button" type="button" @click="$emit('navigate', 'character')">能力・装備を見る <span aria-hidden="true">→</span></button>
                </section>
                <button v-if="canSwitch" class="ug-art-switch" type="button" :aria-pressed="awakenedView" aria-label="通常の姿と覚醒の姿を切り替える" @click="awakenedView = !awakenedView">↻</button>
            </div>
        </UndergroundScene>
        <div class="ug-home-lower">
            <section class="ug-companions" aria-label="パーティー">
                <header><h2>パーティー</h2><button class="ug-text-button" type="button" @click="$emit('navigate', 'exchange')">編成する <span aria-hidden="true">→</span></button></header>
                <div class="ug-companion-list">
                    <article class="ug-companion ug-party-leader">
                        <img v-if="iconUrl" :src="iconUrl" alt="">
                        <div v-else class="ug-companion-initial" aria-hidden="true">{{ name.slice(0, 1) }}</div>
                        <div><strong class="ug-party-name"><span>{{ name }}</span><span class="ug-leader-label">リーダー</span></strong><small>HP {{ number(hp) }} / {{ number(maxHp) }}</small><div class="ug-meter"><span :style="{ width: percentage(hp, maxHp) }"></span></div></div>
                    </article>
                    <article v-for="member in companions" :key="member.secretary_id" class="ug-companion">
                        <img v-if="member.icon_url" :src="member.icon_url" alt="">
                        <div v-else class="ug-companion-initial" aria-hidden="true">{{ member.display_name.slice(0, 1) }}</div>
                        <div><strong>{{ member.display_name }}</strong><small>HP {{ number(member.current_hp) }} / {{ number(member.max_hp) }}</small><div class="ug-meter"><span :style="{ width: percentage(member.current_hp, member.max_hp) }"></span></div></div>
                    </article>
                </div>
            </section>
            <section class="ug-departure" aria-label="次の冒険">
                <template v-if="activeTrial"><span class="ug-muted">挑戦中の試練</span><h2>{{ activeTrial }}</h2><button class="ug-primary" type="button" :disabled="departureDisabled" @click="$emit('continueTrial')">試練を続ける <span aria-hidden="true">→</span></button></template>
                <template v-else><div><span class="ug-muted">次の冒険</span><h2>{{ destination ?? '行き先を選びましょう' }}</h2></div><button class="ug-primary" type="button" :disabled="departureDisabled || !destination" @click="$emit('depart')">冒険に出る <span aria-hidden="true">→</span></button></template>
                <p v-if="departureReason" class="ug-muted" role="status">{{ departureReason }}</p>
                <button class="ug-text-button" type="button" @click="$emit('navigate', 'adventure')">行き先を選ぶ</button>
            </section>
        </div>
    </div>
</template>
