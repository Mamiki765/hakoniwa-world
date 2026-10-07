<script setup lang="ts">
import type { SecretaryProfile } from '../types';

defineProps<{ profile: SecretaryProfile }>();
const slots = {
    weapon: '武器', armor: '防具', accessory_1: '装飾品1',
    accessory_2: '装飾品2', accessory_3: '装飾品3', resonance: '共鳴石',
} as const;
</script>

<template>
    <div class="secretary-equipped-groups">
        <section class="secretary-profile-equipment" aria-label="地上の装備">
            <h3>地上の装備</h3>
            <ol>
                <li v-for="slot in profile.equipment.slots" :key="slot.slot">
                    <span class="secretary-profile-slot">{{ slot.slot }}</span>
                    <template v-if="slot.item">
                        <span class="secretary-profile-item-icon" aria-hidden="true">{{ slot.item.category_label.slice(0, 1) }}</span>
                        <div>
                            <strong>{{ slot.item.name }} <small>Lv.{{ slot.item.level }}</small></strong>
                            <p>{{ slot.item.effect_text || slot.item.category_label }}</p>
                        </div>
                    </template>
                    <span v-else class="empty-state">装備なし</span>
                </li>
            </ol>
        </section>
        <section v-if="profile.underground_status" class="secretary-underground-equipment" aria-label="地底の装備">
            <h3>地底の装備</h3>
            <dl>
                <div v-for="(label, slot) in slots" :key="slot">
                    <dt>{{ label }}</dt>
                    <dd v-if="profile.underground_status.equipped[slot]">
                        <strong>{{ profile.underground_status.equipped[slot]!.label }}</strong>
                        <small>IL {{ profile.underground_status.equipped[slot]!.item_level }}<template v-if="profile.underground_status.equipped[slot]!.quality_percent !== null"> · Quality {{ profile.underground_status.equipped[slot]!.quality_percent }}%</template></small>
                    </dd>
                    <dd v-else class="empty-state">装備なし</dd>
                </div>
            </dl>
        </section>
    </div>
</template>
