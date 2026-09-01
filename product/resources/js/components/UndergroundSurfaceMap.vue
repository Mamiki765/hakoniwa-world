<script setup lang="ts">
import { computed } from 'vue';
import type {
    AssetDescriptor,
    UndergroundFacilityTarget,
    UndergroundSurfaceMap,
    UndergroundSurfaceMapSlot,
} from '../types';

const props = defineProps<{
    map: UndergroundSurfaceMap;
    selected: UndergroundFacilityTarget | null;
}>();
const emit = defineEmits<{
    select: [target: UndergroundFacilityTarget];
}>();

const soilStyle = computed(() => props.map.assets.soil.available && props.map.assets.soil.url
    ? { backgroundImage: `url(${props.map.assets.soil.url})` }
    : undefined);
const selectedSlot = computed(() => {
    if (props.selected === null) return null;
    return props.map.layers
        .find((layer) => layer.layer === props.selected?.layer)
        ?.slots.find((slot) => slot.slot_index === props.selected?.slot_index) ?? null;
});

function slotAsset(slot: UndergroundSurfaceMapSlot): AssetDescriptor {
    return slot.facility_key === null
        ? props.map.assets.road
        : props.map.assets[slot.facility_key];
}

function selectSlot(layer: number, slot: UndergroundSurfaceMapSlot): void {
    emit('select', {
        layer,
        slot_index: slot.slot_index,
        coordinate_label: slot.coordinate_label,
        facility_key: slot.facility_key,
    });
}

function isSelected(layer: number, slotIndex: number): boolean {
    return props.selected?.layer === layer && props.selected.slot_index === slotIndex;
}

function facilityLabel(slot: UndergroundSurfaceMapSlot): string {
    return slot.facility_key === null ? '空き施設枠' : {
        underground_city: '地底都市',
        underground_farm: '地底農場',
        underground_factory: '地底工場',
        underground_missile_base: '地底ミサイル基地',
    }[slot.facility_key];
}
</script>

<template>
    <section class="underground-map-card" aria-labelledby="underground-map-heading">
        <header>
            <div>
                <p class="eyebrow">UNDERGROUND</p>
                <h2 id="underground-map-heading">地底マップ</h2>
            </div>
            <p>{{ map.unlocked_layers }}層・{{ map.total_facility_slots }}施設枠</p>
        </header>
        <p class="underground-map-note">梯子と入口は施設枠に含まれません。</p>
        <div class="underground-map" aria-label="地底施設マップ">
            <div class="underground-ceiling-row" :style="soilStyle" aria-label="地上への入口">
                <span v-for="position in 2" :key="`left-soil-${position}`" class="underground-soil" aria-hidden="true"></span>
                <div class="underground-entrance">
                    <img v-if="map.assets.entrance.available && map.assets.entrance.url" :src="map.assets.entrance.url" alt="地上への入口">
                    <span v-else aria-hidden="true">入口</span>
                </div>
                <span v-for="position in 2" :key="`right-soil-${position}`" class="underground-soil" aria-hidden="true"></span>
            </div>
            <ol class="underground-layers">
                <li v-for="layer in map.layers" :key="layer.layer" class="underground-layer">
                    <div class="underground-layer-row" :style="soilStyle" :aria-label="`地下${layer.layer}層`">
                        <button
                            v-for="slot in layer.slots.slice(0, 2)"
                            :key="slot.slot_index"
                            type="button"
                            class="underground-slot"
                            :class="{ selected: isSelected(layer.layer, slot.slot_index) }"
                            :aria-pressed="isSelected(layer.layer, slot.slot_index)"
                            :aria-label="`${facilityLabel(slot)}、地下${layer.layer}層の施設枠${slot.slot_index + 1}`"
                            @click="selectSlot(layer.layer, slot)"
                        >
                            <img v-if="slotAsset(slot).available && slotAsset(slot).url" :src="slotAsset(slot).url ?? ''" :alt="facilityLabel(slot)">
                            <span v-else class="underground-slot-fallback" aria-hidden="true">{{ slotAsset(slot).fallback_label }}</span>
                            <span class="underground-slot-label">{{ facilityLabel(slot) }}</span>
                        </button>
                        <div class="underground-ladder" aria-label="固定梯子（施設枠外）">
                            <img v-if="map.assets.ladder.available && map.assets.ladder.url" :src="map.assets.ladder.url" alt="梯子">
                            <span v-else aria-hidden="true">梯</span>
                        </div>
                        <button
                            v-for="slot in layer.slots.slice(2)"
                            :key="slot.slot_index"
                            type="button"
                            class="underground-slot"
                            :class="{ selected: isSelected(layer.layer, slot.slot_index) }"
                            :aria-pressed="isSelected(layer.layer, slot.slot_index)"
                            :aria-label="`${facilityLabel(slot)}、地下${layer.layer}層の施設枠${slot.slot_index + 1}`"
                            @click="selectSlot(layer.layer, slot)"
                        >
                            <img v-if="slotAsset(slot).available && slotAsset(slot).url" :src="slotAsset(slot).url ?? ''" :alt="facilityLabel(slot)">
                            <span v-else class="underground-slot-fallback" aria-hidden="true">{{ slotAsset(slot).fallback_label }}</span>
                            <span class="underground-slot-label">{{ facilityLabel(slot) }}</span>
                        </button>
                    </div>
                </li>
            </ol>
        </div>
        <section v-if="selectedSlot" class="underground-map-detail" aria-label="選択中の地下施設情報">
            <p class="eyebrow">選択中</p>
            <h3>{{ facilityLabel(selectedSlot) }}</h3>
            <dl>
                <div><dt>階層</dt><dd>地下{{ selected?.layer }}層</dd></div>
                <div><dt>座標</dt><dd>{{ selectedSlot.coordinate_label }}</dd></div>
            </dl>
        </section>
    </section>
</template>

<style scoped>
.underground-map-card {
    margin: 0.85rem 0;
    padding: 0.9rem;
    border: 1px solid color-mix(in srgb, var(--line, #87937e) 72%, #6e4827);
    border-radius: 0.75rem;
    background: color-mix(in srgb, var(--panel, #f5f1e6) 90%, #5c3c22);
}
.underground-map-card > header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 0.75rem;
}
.underground-map-card h2,
.underground-map-card p { margin: 0; }
.underground-map-note { margin-top: 0.4rem !important; font-size: 0.86rem; }
.underground-map {
    width: min(100%, 25rem);
    margin: 0.75rem auto 0;
    overflow: hidden;
    border: 1px solid #5e4934;
    border-radius: 0.4rem;
    background: #443019;
}
.underground-ceiling-row,
.underground-layer-row {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 0;
    background-color: #443019;
    background-repeat: repeat;
}
.underground-soil,
.underground-entrance {
    display: grid;
    place-items: center;
    min-width: 0;
    aspect-ratio: 1;
    border: 1px solid color-mix(in srgb, #5e4934 76%, transparent);
}
.underground-soil { background: color-mix(in srgb, #5e4934 28%, transparent); }
.underground-entrance {
    background: linear-gradient(#bdc6c4, #67716f);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
}
.underground-entrance img,
.underground-ladder img,
.underground-slot img { width: 100%; height: 100%; object-fit: contain; image-rendering: pixelated; }
.underground-layers { margin: 0; padding: 0; list-style: none; }
.underground-slot {
    appearance: none;
    display: grid;
    grid-template-rows: minmax(0, 1fr) auto;
    align-items: center;
    min-width: 0;
    aspect-ratio: 1;
    padding: 0.25rem;
    border: 1px solid #6f6250;
    border-radius: 0;
    background: #6d665a;
    color: #fff;
    text-align: center;
    cursor: pointer;
}
.underground-slot.selected {
    z-index: 1;
    border-color: #b62f35;
    outline: 0.18rem solid color-mix(in srgb, #b62f35 82%, transparent);
    outline-offset: -0.18rem;
    background: #514044;
}
.underground-slot:focus-visible {
    z-index: 2;
    outline: 0.18rem solid #fff3b0;
    outline-offset: -0.18rem;
}
.underground-slot img,
.underground-slot-fallback { display: grid; place-items: center; width: 100%; min-height: 0; overflow: hidden; }
.underground-slot-fallback { border-radius: 0.2rem; background: #8b8b7a; font-weight: 700; }
.underground-slot-label { overflow-wrap: anywhere; line-height: 1.15; font-size: 0.65rem; }
.underground-ladder {
    display: grid;
    place-items: center;
    min-width: 0;
    aspect-ratio: 1;
    border-inline: 0.25rem solid #8f4d29;
    background: repeating-linear-gradient(to bottom, transparent 0 0.55rem, #c7773c 0.55rem 0.75rem);
    color: #fff;
    font-weight: 800;
}
.underground-map-detail {
    display: grid;
    gap: 0.35rem;
    width: min(100%, 25rem);
    margin: 0.75rem auto 0;
    padding: 0.75rem;
    border-left: 0.22rem solid #b62f35;
    background: color-mix(in srgb, var(--surface, #fff) 92%, #b62f35);
}
.underground-map-detail h3 { margin: 0; }
.underground-map-detail dl { display: grid; gap: 0.25rem; margin: 0; }
.underground-map-detail dl div { display: grid; grid-template-columns: 4rem 1fr; gap: 0.5rem; }
.underground-map-detail dd { margin: 0; }
@media (max-width: 420px) {
    .underground-map-card { padding-inline: 0.55rem; }
    .underground-slot { padding: 0.14rem; }
    .underground-slot-label { font-size: 0.56rem; }
}
</style>
