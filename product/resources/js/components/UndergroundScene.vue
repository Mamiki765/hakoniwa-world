<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import placeholder from '../../images/underground-placeholder.jpg';
import { sceneAssetVisible, type SceneAsset, type SceneActor, type ScenePlacement, type UndergroundScene } from './undergroundScenes';

const props = withDefaults(defineProps<{ scene?: UndergroundScene; showAi?: boolean; label?: string }>(), {
    showAi: true,
    scene: undefined,
    label: '地底の風景',
});
const failedUrls = ref(new Set<string>());
const creditDialog = ref<HTMLDialogElement | null>(null);
const visible = (asset: SceneAsset | null | undefined): asset is SceneAsset =>
    sceneAssetVisible(asset, props.showAi) && !failedUrls.value.has(asset.url!);
const background = computed(() => visible(props.scene?.background) ? props.scene!.background! : null);
const still = computed(() => visible(props.scene?.still) ? props.scene!.still! : null);
const actors = computed(() => (props.scene?.actors ?? []).filter(actor => visible(actor.asset)));
const credits = computed(() => [
    ...actors.value.filter(actor => !actor.own_character && actor.asset.show_credit).map(actor => ({
        key: `actor-${actor.key}`, title: '立ち絵の権利情報', name: actor.name, asset: actor.asset,
    })),
    ...(still.value?.show_credit ? [{ key: 'still', title: 'イベントスチルの権利情報', name: '', asset: still.value }] : []),
    ...(background.value?.show_credit ? [{ key: 'background', title: '背景の権利情報', name: '', asset: background.value }] : []),
]);
watch(credits, entries => { if (!entries.length) creditDialog.value?.close(); });

function placementStyle(actor: SceneActor): Record<string, string | number> {
    const css: Record<string, string | number> = {};
    for (const [prefix, placement] of [['', actor.placement], ['mobile-', { ...actor.placement, ...actor.mobile }]] as const) {
        const p: ScenePlacement = placement;
        css[`--actor-${prefix}x`] = `${p.x}%`;
        css[`--actor-${prefix}y`] = `${p.y}%`;
        css[`--actor-${prefix}height`] = `${p.height}%`;
        css[`--actor-${prefix}pivot-x`] = `${-(p.pivot_x ?? 50)}%`;
        css[`--actor-${prefix}pivot-y`] = `${-(p.pivot_y ?? 100)}%`;
    }
    css.zIndex = Math.min(5, Math.max(1, actor.placement.layer ?? 1));
    return css;
}

function imageFailed(url: string | null): void {
    if (url) failedUrls.value = new Set([...failedUrls.value, url]);
}

function creditLink(url: string | null | undefined): string | undefined {
    if (!url) return undefined;
    try {
        const parsed = new URL(url);
        return ['https:', 'http:'].includes(parsed.protocol) ? parsed.href : undefined;
    } catch { return undefined; }
}
</script>

<template>
    <section class="ug-scene" :aria-label="label">
        <img class="ug-scene-background" :src="background?.url ?? placeholder" alt="" @error="imageFailed(background?.url ?? null)">
        <div class="ug-scene-shade" aria-hidden="true"></div>
        <img v-if="still" class="ug-scene-still" :src="still.url!" alt="イベントスチル" @error="imageFailed(still.url)">
        <img v-for="actor in actors" :key="actor.key" class="ug-scene-actor" :src="actor.asset.url!" :alt="actor.name" :style="placementStyle(actor)" @error="imageFailed(actor.asset.url)">
        <div class="ug-scene-content"><slot /></div>
        <div v-if="credits.length" class="ug-scene-credits">
            <button type="button" aria-label="画像の権利情報" @click="creditDialog?.showModal()">i</button>
        </div>
        <dialog ref="creditDialog" class="ug-credit-dialog" aria-label="画像の権利情報" @click.self="creditDialog?.close()">
            <header><h2>画像の権利情報</h2><button type="button" aria-label="権利情報を閉じる" @click="creditDialog?.close()">×</button></header>
            <section v-for="entry in credits" :key="entry.key" class="ug-credit-entry">
                <h3>{{ entry.title }}</h3>
                <p v-if="entry.name">{{ entry.name }}</p>
                <p v-if="entry.asset.creation_method === 'ai_generated'">AI生成画像</p>
                <p v-if="entry.asset.credit">{{ entry.asset.credit }}</p>
                <a v-if="creditLink(entry.asset.credit_url)" :href="creditLink(entry.asset.credit_url)" target="_blank" rel="noopener noreferrer">権利者の案内を見る</a>
            </section>
        </dialog>
    </section>
</template>
