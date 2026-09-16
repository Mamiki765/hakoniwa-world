<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import UndergroundHome from '../components/UndergroundHome.vue';
import UndergroundNavigation from '../components/UndergroundNavigation.vue';
import UndergroundScene from '../components/UndergroundScene.vue';
import shallowCaves from '../../scene-assets/background/430-shallow-caves.jpg';
import blackCrystalCave from '../../scene-assets/background/430-black-crystal-cave.jpg';
import shiningKingdom from '../../scene-assets/background/430-shining-kingdom.jpg';
import guideBackground from '../../scene-assets/background/430-guide-shop.jpg';
import exchangeBackground from '../../scene-assets/background/430-aki-exchange.jpg';
import guideArt from '../../scene-assets/npc/430-guide.png';
import akiArt from '../../scene-assets/npc/430-aki.png';
import mirrorArt from '../../scene-assets/event/430-transparent-mirror.png';
import sceneManifest from '../../scene-assets/scene-assets.json';
import loungeStories from '../../stories/lounge.json';
import { type SceneAsset, type UndergroundDestination, type UndergroundScene as SceneDefinition } from '../components/undergroundScenes';

const theme = ref('light');
const showAi = ref(true);
const mobile = ref(false);
const mode = ref<'home' | 'scene' | 'guide' | 'exchange' | 'mirror'>('home');
const mirrorOwned = ref(true);
const destination = ref<UndergroundDestination>('home');
const message = ref('');
const backgroundChoices = [
    { key: 'placeholder', name: '水晶の洞窟', asset: null },
    { key: 'shallow_caves', name: '浅い洞窟', asset: { id: 'bg.shallow_caves', url: shallowCaves, creation_method: 'ai_generated' as const, show_credit: true } },
    { key: 'black_crystal_cave', name: '黒晶洞', asset: { id: 'bg.black_crystal_cave', url: blackCrystalCave, creation_method: 'ai_generated' as const, show_credit: true } },
    { key: 'shining_kingdom', name: '輝きの王国', asset: { id: 'bg.shining_kingdom', url: shiningKingdom, creation_method: 'ai_generated' as const, show_credit: true } },
];
const backgroundKey = ref('shallow_caves');
const background = ref<SceneAsset | null>(backgroundChoices[1]!.asset);
const portrait = ref<SceneAsset | null>({ id: 'local-self-sample', url: '/tools/scene-preview/local-assets/sample-peridot.png', creation_method: null });
const actorX = ref(65);
const actorY = ref(102);
const actorHeight = ref(96);
const mobileX = ref(65);
const mobileY = ref(102);
const mobileHeight = ref(96);
const assetKind = ref<'npc' | 'background' | 'event'>('npc');
const assetId = ref('npc.new_character');
const assetFile = ref('npc/new-character.png');
const sceneKey = ref('exchange');
const method = ref<SceneAsset['creation_method']>(null);
const credit = ref('');
const creditUrl = ref('');
const showCredit = ref(true);
const actorName = ref('登場人物');
const localUrls: string[] = [];
const previewScene = computed<SceneDefinition>(() => ({
    background: assetKind.value === 'background' && mode.value === 'scene' ? editedAsset.value : background.value,
    still: assetKind.value === 'event' && mode.value === 'scene' ? editedAsset.value : null,
    actors: portrait.value && (mode.value === 'home' || assetKind.value === 'npc') ? [{
        key: 'preview', name: actorName.value,
        asset: editedAsset.value!,
        placement: { x: actorX.value, y: actorY.value, height: actorHeight.value },
        mobile: { x: mobileX.value, y: mobileY.value, height: mobileHeight.value },
    }] : [],
}));
const editedAsset = computed<SceneAsset | null>(() => portrait.value ? {
    ...portrait.value, creation_method: method.value, credit: credit.value,
    credit_url: creditUrl.value, show_credit: showCredit.value,
} : null);
const registration = computed(() => {
    if (!method.value || !new RegExp(`^${assetKind.value}/[a-zA-Z0-9_-]+\\.(png|jpe?g|webp)$`).test(assetFile.value)
        || !/^[a-zA-Z0-9_.-]+$/.test(assetId.value) || !/^[a-zA-Z0-9_.-]+$/.test(sceneKey.value)) return '';
    const asset = { file: assetFile.value, creation_method: method.value, credit: credit.value || undefined,
        credit_url: creditUrl.value || undefined, show_credit: showCredit.value };
    const scene = assetKind.value === 'npc' ? { actors: [{ asset: assetId.value, name: actorName.value,
        placement: { x: actorX.value, y: actorY.value, height: actorHeight.value },
        mobile: { x: mobileX.value, y: mobileY.value, height: mobileHeight.value } }] }
        : { [assetKind.value === 'event' ? 'still' : 'background']: assetId.value };
    return JSON.stringify({ assets: { [assetId.value]: asset }, scenes: { [sceneKey.value]: scene } }, null, 2);
});
function registeredPortrait(key: 'shop' | 'exchange', url: string): SceneDefinition {
    const definition = sceneManifest.scenes[key];
    return {
        background: { ...sceneManifest.assets[definition.background as 'bg.guide_shop' | 'bg.aki_exchange'], id: definition.background,
            url: key === 'shop' ? guideBackground : exchangeBackground } as SceneAsset,
        actors: definition.actors.map((actor, index) => ({
            ...actor, key: String(index),
            asset: { ...sceneManifest.assets[actor.asset as 'npc.guide' | 'npc.aki'], id: actor.asset, url } as SceneAsset,
        })),
    };
}
const guideScene = computed<SceneDefinition>(() => ({ ...registeredPortrait('shop', guideArt),
    ...(!mirrorOwned.value ? { actors: [] } : {}),
}));
const exchangeScene = computed<SceneDefinition>(() => registeredPortrait('exchange', akiArt));
const mirrorScene = computed<SceneDefinition>(() => ({
    background: background.value, actors: [],
    still: { ...sceneManifest.assets['event.transparent_mirror'], id: 'event.transparent_mirror', url: mirrorArt } as SceneAsset,
}));
watch(theme, value => { document.documentElement.dataset.theme = value; }, { immediate: true });
onUnmounted(() => localUrls.forEach(url => URL.revokeObjectURL(url)));

function chooseImage(event: Event, kind: 'background' | 'portrait'): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    localUrls.push(url);
    const asset: SceneAsset = { id: file.name.replace(/\.[^.]+$/, ''), url, creation_method: kind === 'background' ? 'other' : method.value };
    if (kind === 'background') background.value = asset;
    else portrait.value = asset;
}

function navigate(next: UndergroundDestination): void {
    destination.value = next;
    message.value = next === 'home' ? '' : '現在はホームの原型と素材配置を確認できます。';
}

function selectBackground(key: string): void {
    backgroundKey.value = key;
    background.value = backgroundChoices.find(option => option.key === key)?.asset ?? null;
}
</script>

<template>
    <header class="preview-header"><strong>箱庭諸島 2S＋</strong><span>4.3.0 地底UIプレビュー</span></header>
    <main class="preview-main">
        <div class="preview-toolbar" aria-label="プレビュー設定">
            <label>表示<select v-model="mode"><option value="home">ホーム</option><option value="guide">ショップ：案内人</option><option value="exchange">交流場：アキ</option><option value="mirror">透明な鏡：イベント</option><option value="scene">NPC配置</option></select></label>
            <label>テーマ<select v-model="theme"><option value="light">Light</option><option value="dark">Dark</option></select></label>
            <label><input v-model="showAi" type="checkbox"> AI画像を表示</label>
            <label><input v-model="mobile" type="checkbox"> スマホ幅</label>
            <label v-if="mode === 'guide'"><input v-model="mirrorOwned" type="checkbox"> 透明な鏡を購入済み</label>
            <details v-if="mode === 'home' || mode === 'scene'">
<summary>手元の画像で確認・登録</summary><div class="preview-image-fields">
                <label>背景<input type="file" accept="image/png,image/jpeg,image/webp" @change="chooseImage($event, 'background')"></label>
                <label>登録する画像<input type="file" accept="image/png,image/jpeg,image/webp" @change="chooseImage($event, 'portrait')"></label>
                <label>制作区分<select v-model="method"><option :value="null">未指定（プレビューのみ）</option><option value="commissioned_or_permitted">委託・許諾</option><option value="self_made">自作</option><option value="ai_generated">AI生成</option><option value="other">その他の非AI素材</option></select></label>
                <label>権利表記<input v-model="credit"></label>
                <template v-if="mode === 'scene'">
                    <label>種類<select v-model="assetKind"><option value="npc">立ち絵</option><option value="background">背景</option><option value="event">イベントスチル</option></select></label>
                    <label>素材ID<input v-model="assetId"></label>
                    <label>配置先ファイル<input v-model="assetFile"></label>
                    <label>場面ID<input v-model="sceneKey"></label>
                    <label>権利表記のリンク<input v-model="creditUrl" type="url"></label>
                    <label><input v-model="showCredit" type="checkbox"> 権利情報を表示</label>
                    <template v-if="assetKind === 'npc'">
                        <label>人物名<input v-model="actorName"></label>
                        <fieldset><legend>PC配置（%）</legend><label>横位置<input v-model.number="actorX" type="number" min="-100" max="200"></label><label>足元<input v-model.number="actorY" type="number" min="-100" max="200"></label><label>高さ<input v-model.number="actorHeight" type="number" min="1" max="300"></label></fieldset>
                        <fieldset><legend>スマホ配置（%）</legend><label>横位置<input v-model.number="mobileX" type="number" min="-100" max="200"></label><label>足元<input v-model.number="mobileY" type="number" min="-100" max="200"></label><label>高さ<input v-model.number="mobileHeight" type="number" min="1" max="300"></label></fieldset>
                    </template>
                    <label v-if="registration">登録用JSON<textarea :value="registration" readonly rows="12" /></label>
                    <p v-else>制作区分・素材ID・場面ID・種類に合う配置先ファイルを指定すると、登録用JSONを表示します。</p>
                    <p>JSONは既存manifestの該当項目へ追加してください。同じ場面のほかの素材は残します。</p>
                </template>
                <p>画像はこの端末内で表示します。サーバーへ送信しません。</p>
            </div>
</details>
        </div>
        <div class="preview-frame ug-shell" :class="{ 'preview-mobile': mobile }">
            <template v-if="mode === 'home'">
                <UndergroundHome name="ペリドット" :level="42" :hp="2680" :max-hp="3240" :awakening="630" :awakening-max="1000" :shards="128450" :banked="360000" :tickets="24" :xp-remaining="1860" growth-path="勇敢なる戦士" destination="輝きの王国" :show-ai="showAi" :backgrounds="backgroundChoices" :background-key="backgroundKey" :scene="previewScene" :portrait="previewScene.actors[0]?.asset" :companions="[{ secretary_id: 2, display_name: '同行者A', current_hp: 1920, max_hp: 2180, awakening_gauge: 280 }, { secretary_id: 3, display_name: '同行者B', current_hp: 2410, max_hp: 2410, awakening_gauge: 510 }, { secretary_id: 4, display_name: '同行者C', current_hp: 1780, max_hp: 1920, awakening_gauge: 800 }]" @background="selectBackground" @navigate="navigate" @depart="message = '表示用のサンプルです。戦闘や資産の操作は実行しません。'" />
                <UndergroundNavigation :current="destination" :exchange-discovered="true" @navigate="navigate" />
            </template>
            <template v-else-if="mode === 'guide'">
                <header class="ug-page-heading"><h1>ショップ</h1></header>
                <div class="ug-tabs"><button type="button" aria-current="page">装備を買う</button><button type="button">銀行</button><button type="button">案内人と話す</button></div>
                <UndergroundScene :scene="guideScene" :show-ai="showAi" />
                <div class="ug-page-content"><button class="ug-primary" type="button" @click="message = '配置確認用の表示です。'">宿で休む（10G）</button></div>
            </template>
            <template v-else-if="mode === 'exchange'">
                <header class="ug-page-heading"><h1>交流場</h1></header>
                <div class="ug-tabs"><button type="button" aria-current="page">パーティー</button><button type="button">不動産</button></div>
                <UndergroundScene :scene="exchangeScene" :show-ai="showAi" />
                <div class="ug-page-content"><p>{{ loungeStories.greetings[0] }}</p></div>
            </template>
            <template v-else-if="mode === 'mirror'">
                <UndergroundScene :scene="mirrorScene" :show-ai="showAi" />
                <div class="ug-page-content ug-event-story"><p>「……」</p><p>「……アンタ」</p><p>「見えてるわね？」</p></div>
            </template>
            <template v-else><div class="ug-tabs"><button type="button" aria-selected="true">パーティー</button><button type="button">不動産</button></div><UndergroundScene :scene="previewScene" :show-ai="showAi" /><div class="ug-page-content"><p>{{ actorName }}</p></div></template>
        </div>
        <p class="preview-note">本番と同じ描画コンポーネントを使用しています。名前・数値・同行者は表示用サンプルです。</p>
        <p v-if="message" class="preview-note" role="status">{{ message }}</p>
    </main>
</template>

<style scoped>
.preview-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 20px max(24px, calc((100% - 1120px) / 2)); color: var(--ink); border-bottom: 1px solid var(--line); background: var(--paper); }
.preview-header strong { font-family: 'Yu Mincho', serif; font-size: 1.15rem; letter-spacing: .08em; }
.preview-header span { color: var(--muted); font-size: .78rem; }
.preview-main { padding: 20px 24px 40px; }
.preview-toolbar { width: min(1120px, 100%); margin: 0 auto 16px; display: flex; align-items: center; flex-wrap: wrap; gap: 14px; color: var(--muted); font-size: .78rem; }
.preview-toolbar label { display: flex; align-items: center; gap: 7px; }
.preview-toolbar input, .preview-toolbar select { color: var(--ink); background: var(--input-surface); border: 1px solid var(--input-border); padding: 5px; border-radius: 4px; }
.preview-toolbar details { margin-left: auto; }
.preview-toolbar summary { cursor: pointer; }
.preview-image-fields { display: grid; gap: 12px; padding: 18px 0; }
.preview-image-fields input { min-width: 0; }
.preview-image-fields fieldset { display: flex; flex-wrap: wrap; gap: 12px; border: 1px solid var(--line); }
.preview-image-fields input[type=number] { width: 5em; }
.preview-image-fields textarea { width: min(600px, 100%); color: var(--ink); background: var(--paper); }
.preview-mobile { max-width: 390px; }
.preview-note { width: min(1120px, 100%); margin: 14px auto 0; color: var(--muted); font-size: .72rem; }
@media (max-width: 700px) { .preview-main { padding: 12px 8px; } .preview-header { padding: 16px; } .preview-header span { font-size: .6rem; } }
</style>
