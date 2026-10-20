import type { AccessorySlot, EquipmentSlot } from './EquipmentItemCard.vue';

export const equipmentSlots: EquipmentSlot[] = ['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3', 'resonance'];
export const accessorySlots: AccessorySlot[] = ['accessory_1', 'accessory_2', 'accessory_3'];

const equipmentSlotLabels: Record<EquipmentSlot, string> = {
    weapon: '武器',
    armor: '防具',
    accessory_1: 'アクセサリー1',
    accessory_2: 'アクセサリー2',
    accessory_3: 'アクセサリー3',
    resonance: '共鳴結晶',
};

// An item without a slot is an accessory waiting for the player to pick one.
export function equipmentSlotLabel(slot: EquipmentSlot | null | undefined): string {
    return slot ? equipmentSlotLabels[slot] : 'アクセサリー';
}
