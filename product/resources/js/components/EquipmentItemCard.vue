<script setup lang="ts">
export type EquipmentSlot = 'weapon' | 'armor' | 'accessory_1' | 'accessory_2' | 'accessory_3';
export type AccessorySlot = 'accessory_1' | 'accessory_2' | 'accessory_3';

export interface EquipmentAffix {
    label: string;
    value: number;
    key?: string;
    kind?: string;
    target?: string;
}

export interface EquipmentItem {
    id?: number;
    key: string;
    name: string;
    category: 'weapon' | 'armor' | 'accessory';
    weapon_style?: string | null;
    rank: number;
    item_level: number;
    rarity: string;
    rarity_label?: string;
    buy_price?: number | null;
    sell_price: number;
    owned?: boolean;
    equipped_slot?: EquipmentSlot | null;
    weapon_power: number;
    physical_defense: number;
    magical_defense: number;
    max_hp: number;
    stats: Record<string, number>;
    affixes?: EquipmentAffix[];
    instance_kind?: 'fixed' | 'generated' | string;
    instance_identity?: string | null;
    identity?: string | null;
    generator_identity?: string | null;
    catalog_identity?: string;
    locked?: boolean;
    unlock_requirement?: string | null;
    effect_text?: string | null;
    acquired_at?: string;
}

const props = withDefaults(defineProps<{
    item: EquipmentItem;
    mode?: 'shop' | 'vault' | 'owned';
    disabled?: boolean;
}>(), { mode: 'shop', disabled: false });

const emit = defineEmits<{
    action: [];
}>();

const categoryLabels: Record<EquipmentItem['category'], string> = {
    weapon: '武器',
    armor: '防具',
    accessory: 'アクセサリー',
};
const statLabels: Record<string, string> = {
    vitality: '生命',
    might: '武力',
    finesse: '技巧',
    spirit: '精神',
    agility: '敏捷',
};
const styleLabels: Record<string, string> = {
    dagger: '短剣',
    rapier: '細身剣',
    longsword: '長剣',
    crystal_staff: '輝石杖',
};

function nonZeroStats(): Array<[string, number]> {
    return Object.entries(props.item.stats ?? {}).filter(([, value]) => value > 0);
}

function categoryLabel(): string {
    return categoryLabels[props.item.category] ?? props.item.category;
}

function styleLabel(): string {
    return props.item.weapon_style ? (styleLabels[props.item.weapon_style] ?? props.item.weapon_style) : '';
}

function statLabel(key: string): string {
    return statLabels[key] ?? key;
}

function rankLabel(): string {
    if (props.item.instance_kind === 'generated') return 'ドロップ装備';
    return props.item.rank > 0 ? `Rank ${props.item.rank}` : '初期装備';
}

function affixValue(affix: EquipmentAffix): string {
    if (affix.kind === 'modifier') {
        return `+${(affix.value / 100).toLocaleString('ja-JP', { maximumFractionDigits: 2 })}%`;
    }
    return `+${affix.value.toLocaleString('ja-JP')}`;
}
</script>

<template>
    <article
        class="underground-equipment-card"
        :data-category="item.category"
        :data-owned="item.owned === true"
        :data-instance-kind="item.instance_kind ?? 'fixed'"
        :data-locked="item.locked === true"
    >
        <header class="underground-equipment-card-heading">
            <div>
                <p class="underground-equipment-card-kicker">
                    {{ categoryLabel() }}<span v-if="styleLabel()">・{{ styleLabel() }}</span><span v-if="item.instance_kind === 'generated'">・生成装備</span>
                </p>
                <h3>{{ item.name }}</h3>
            </div>
            <span class="underground-equipment-rarity">{{ item.rarity_label ?? item.rarity }} / {{ rankLabel() }}</span>
        </header>
        <dl class="underground-equipment-card-stats">
            <div><dt>Item Lv</dt><dd>{{ item.item_level }}</dd></div>
            <div v-if="item.category === 'weapon'"><dt>武器力</dt><dd>{{ item.weapon_power }}</dd></div>
            <div v-if="item.physical_defense > 0"><dt>物防</dt><dd>{{ item.physical_defense }}</dd></div>
            <div v-if="item.magical_defense > 0"><dt>魔防</dt><dd>{{ item.magical_defense }}</dd></div>
            <div v-if="item.max_hp > 0"><dt>最大HP</dt><dd>+{{ item.max_hp }}</dd></div>
            <div v-for="([key, value]) in nonZeroStats()" :key="key"><dt>{{ statLabel(key) }}</dt><dd>+{{ value }}</dd></div>
        </dl>
        <ul v-if="item.affixes && item.affixes.length > 0" class="underground-equipment-card-affixes" aria-label="追加効果">
            <li v-for="(affix, index) in item.affixes" :key="affix.key ?? `${affix.label}-${affix.value}-${index}`">
                {{ affix.label }} {{ affixValue(affix) }}
            </li>
        </ul>
        <p v-if="item.effect_text" class="underground-equipment-card-effect">{{ item.effect_text }}</p>
        <footer class="underground-equipment-card-footer">
            <slot name="status"></slot>
            <slot name="price">
                <span v-if="mode === 'owned'">売却価格 {{ item.sell_price.toLocaleString('ja-JP') }}G</span>
            </slot>
            <button v-if="$slots.action" class="button secondary" type="button" :disabled="disabled" @click="emit('action')">
                <slot name="action"></slot>
            </button>
        </footer>
    </article>
</template>
