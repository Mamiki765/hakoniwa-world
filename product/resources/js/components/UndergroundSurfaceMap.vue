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
        <p class="underground-map-note">
            首都 ({{ map.capital.x }}, {{ map.capital.y }}) の地下。梯子と入口は施設枠に含まれません。
        </p>
        <div class="underground-entrance" aria-label="地上との入口">
            <img v-if="map.assets.entrance.available && map.assets.entrance.url" :src="map.assets.entrance.url" alt="地底入口">
            <span v-else aria-hidden="true">入口</span>
        </div>
        <ol class="underground-layers">
            <li v-for="layer in map.layers" :key="layer.layer" class="underground-layer">
                <p><strong>地下{{ layer.layer }}層</strong> <span>z = {{ layer.z }}</span></p>
                <div class="underground-layer-row" :style="soilStyle">
                    <button
                        v-for="slot in layer.slots.slice(0, 2)"
                        :key="slot.slot_index"
                        type="button"
                        class="underground-slot"
                        :class="{ selected: isSelected(layer.layer, slot.slot_index) }"
                        :aria-pressed="isSelected(layer.layer, slot.slot_index)"
                        :aria-label="`${facilityLabel(slot)} ${slot.coordinate_label}`"
                        @click="selectSlot(layer.layer, slot)"
                    >
                        <img v-if="slotAsset(slot).available && slotAsset(slot).url" :src="slotAsset(slot).url ?? ''" :alt="facilityLabel(slot)">
                        <span v-else class="underground-slot-fallback" aria-hidden="true">{{ slotAsset(slot).fallback_label }}</span>
                        <strong>{{ slot.coordinate_label }}</strong>
                        <small>{{ slot.relative_label }}</small>
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
                        :aria-label="`${facilityLabel(slot)} ${slot.coordinate_label}`"
                        @click="selectSlot(layer.layer, slot)"
                    >
                        <img v-if="slotAsset(slot).available && slotAsset(slot).url" :src="slotAsset(slot).url ?? ''" :alt="facilityLabel(slot)">
                        <span v-else class="underground-slot-fallback" aria-hidden="true">{{ slotAsset(slot).fallback_label }}</span>
                        <strong>{{ slot.coordinate_label }}</strong>
                        <small>{{ slot.relative_label }}</small>
                    </button>
                </div>
            </li>
        </ol>
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
.underground-entrance {
    display: grid;
    place-items: center;
    width: 2.75rem;
    height: 2.1rem;
    margin: 0.65rem auto 0;
    border-radius: 0.4rem 0.4rem 0 0;
    background: linear-gradient(#bdc6c4, #67716f);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
}
.underground-entrance img,
.underground-ladder img,
.underground-slot img { width: 100%; height: 100%; object-fit: contain; image-rendering: pixelated; }
.underground-layers { display: grid; gap: 0.55rem; margin: 0; padding: 0; list-style: none; }
.underground-layer > p { display: flex; justify-content: space-between; margin-bottom: 0.2rem; font-size: 0.8rem; }
.underground-layer-row {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr)) 2.6rem repeat(2, minmax(0, 1fr));
    gap: 0.35rem;
    align-items: stretch;
    padding: 0.45rem;
    border-radius: 0.45rem;
    background-color: #443019;
    background-repeat: repeat;
}
.underground-slot {
    appearance: none;
    min-width: 0;
    padding: 0.35rem 0.2rem;
    border: 1px solid #8b7b62;
    border-radius: 0.3rem;
    background: #6d665a;
    color: #fff;
    text-align: center;
    cursor: pointer;
}
.underground-slot.selected {
    border-color: #ffe07a;
    outline: 0.2rem solid color-mix(in srgb, #ffe07a 72%, transparent);
    background: #514224;
}
.underground-slot:focus-visible {
    outline: 0.2rem solid #fff3b0;
    outline-offset: 0.1rem;
}
.underground-slot img,
.underground-slot-fallback { display: grid; place-items: center; width: 2rem; height: 2rem; margin: 0 auto 0.2rem; }
.underground-slot-fallback { border-radius: 0.2rem; background: #8b8b7a; font-weight: 700; }
.underground-slot strong,
.underground-slot small { display: block; overflow-wrap: anywhere; line-height: 1.25; }
.underground-slot strong { font-size: 0.72rem; }
.underground-slot small { margin-top: 0.12rem; color: #eee2c7; font-size: 0.64rem; }
.underground-ladder {
    display: grid;
    place-items: center;
    min-height: 4.9rem;
    border-inline: 0.25rem solid #8f4d29;
    background: repeating-linear-gradient(to bottom, transparent 0 0.55rem, #c7773c 0.55rem 0.75rem);
    color: #fff;
    font-weight: 800;
}
@media (max-width: 420px) {
    .underground-map-card { padding-inline: 0.55rem; }
    .underground-layer-row { grid-template-columns: repeat(2, minmax(0, 1fr)) 1.8rem repeat(2, minmax(0, 1fr)); gap: 0.18rem; padding: 0.28rem; }
    .underground-slot { padding-inline: 0.08rem; }
    .underground-slot strong { font-size: 0.62rem; }
    .underground-slot small { font-size: 0.56rem; }
}
</style>
