<script setup lang="ts">
export interface PartyCandidate {
    secretary_id: number;
    source: 'self' | 'borrowed_secretary' | 'companion';
    display_name: string | null;
    icon_url?: string | null;
    icon?: string | null;
    combat_level: number | null;
    growth_path?: string | null;
    build_summary?: string | null;
    build?: { skills?: string[] } | string | null;
    equipment_summary?: string | null;
    equipment?: unknown;
    awakening?: string | null;
    available: boolean;
    owner_game_id?: string | null;
}

const props = defineProps<{
    candidates: PartyCandidate[];
    selectedIds: number[];
    disabled?: boolean;
}>();

const emit = defineEmits<{ toggle: [candidate: PartyCandidate] }>();

function selected(candidate: PartyCandidate): boolean {
    return props.selectedIds.includes(candidate.secretary_id);
}

function toggle(candidate: PartyCandidate): void {
    if (!candidate.available || props.disabled) return;
    emit('toggle', candidate);
}

function icon(candidate: PartyCandidate): string | null { return candidate.icon_url ?? candidate.icon ?? null; }
function build(candidate: PartyCandidate): string | null { return candidate.build_summary ?? (typeof candidate.build === 'string' ? candidate.build : candidate.build?.skills?.join('、') ?? null); }
function equipment(candidate: PartyCandidate): string | null { return candidate.equipment_summary ?? (Array.isArray(candidate.equipment) && candidate.equipment.length > 0 ? `${candidate.equipment.length}件` : null); }
</script>

<template>
    <section class="underground-party-builder" aria-labelledby="underground-party-title">
        <header>
            <div><p class="eyebrow">ASYNC PARTY</p><h2 id="underground-party-title">PT編成</h2></div>
            <p class="underground-party-count">{{ Math.min(4, selectedIds.length + 1) }} / 4人</p>
        </header>
        <p class="underground-party-note">自分の秘書1人は必須。借りた秘書は最大3人です。</p>
        <div class="underground-party-candidates" role="list">
            <button
                v-for="candidate in candidates"
                :key="`${candidate.source}:${candidate.secretary_id}`"
                type="button"
                role="listitem"
                class="underground-party-candidate"
                :class="{ 'is-selected': selected(candidate), 'is-unavailable': !candidate.available }"
                :aria-pressed="selected(candidate)"
                :disabled="disabled || !candidate.available || (!selected(candidate) && selectedIds.length >= 3)"
                @click="toggle(candidate)"
            >
                <img v-if="icon(candidate)" :src="icon(candidate)!" :alt="`${candidate.display_name}のアイコン`" class="underground-party-candidate-icon">
                <span v-else class="underground-party-candidate-icon underground-party-candidate-icon-fallback" aria-hidden="true">秘</span>
                <span class="underground-party-candidate-body">
                    <strong>{{ candidate.display_name ?? '名無しの秘書' }}</strong>
                    <small>{{ candidate.source === 'self' ? '自分の秘書' : candidate.source === 'borrowed_secretary' ? '借りた秘書' : '仲間' }}・戦闘Lv{{ candidate.combat_level }}</small>
                    <small v-if="candidate.source === 'borrowed_secretary'" class="underground-party-availability">貸出可</small>
                    <small v-if="candidate.growth_path">{{ candidate.growth_path }}<template v-if="build(candidate)">・{{ build(candidate) }}</template></small>
                    <small v-if="equipment(candidate)">装備: {{ equipment(candidate) }}</small>
                    <small v-if="candidate.awakening">覚醒: {{ candidate.awakening }}</small>
                    <small v-if="!candidate.available" class="underground-party-unavailable">貸出不可</small>
                </span>
            </button>
        </div>
    </section>
</template>
