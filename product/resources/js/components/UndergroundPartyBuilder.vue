<script setup lang="ts">
import { computed } from 'vue';

export interface PartyCandidate {
    secretary_id: number;
    source: 'self' | 'borrowed_secretary' | 'companion';
    display_name: string | null;
    icon_url?: string | null;
    icon?: string | null;
    combat_level: number | null;
    effective_combat_level?: number | null;
    growth_path?: string | null;
    build_summary?: string | null;
    build?: { skills?: Array<string | { label?: string | null }> } | string | null;
    equipment_summary?: string | null;
    equipment?: unknown;
    awakening?: string | null;
    available: boolean;
    owner_game_id?: string | null;
}

const props = withDefaults(defineProps<{
    candidates: PartyCandidate[];
    selectedIds: number[];
    disabled?: boolean;
    showCandidateList?: boolean;
    leaderCombatLevel?: number | null;
}>(), {
    showCandidateList: true,
    leaderCombatLevel: null,
});

const emit = defineEmits<{ toggle: [candidate: PartyCandidate] }>();

function selected(candidate: PartyCandidate): boolean {
    return props.selectedIds.includes(candidate.secretary_id);
}

function toggle(candidate: PartyCandidate): void {
    // A selected candidate may become unavailable after the owner disables
    // lending. Keep that row actionable so the player can remove it.
    if (props.disabled || (!candidate.available && !selected(candidate))) return;
    emit('toggle', candidate);
}

function icon(candidate: PartyCandidate): string | null { return candidate.icon_url ?? candidate.icon ?? null; }
function build(candidate: PartyCandidate): string | null {
    if (candidate.build_summary) return candidate.build_summary;
    if (typeof candidate.build === 'string') return candidate.build;
    if (candidate.build?.skills && candidate.build.skills.length > 0) {
        const labels = candidate.build.skills
            .map((skill) => typeof skill === 'string' ? null : skill.label)
            .filter((label): label is string => Boolean(label));
        return labels.length > 0 ? labels.join('、') : `スキル${candidate.build.skills.length}種`;
    }

    return null;
}
function equipment(candidate: PartyCandidate): string | null { return candidate.equipment_summary ?? (Array.isArray(candidate.equipment) && candidate.equipment.length > 0 ? `${candidate.equipment.length}件` : null); }
const selectedCandidates = computed(() => props.candidates.filter((candidate) => selected(candidate)));
const availableCandidates = computed(() => props.candidates.filter((candidate) => !selected(candidate)));
const showCandidateListEnabled = computed(() => props.showCandidateList);
function candidateName(candidate: PartyCandidate): string { return candidate.display_name ?? (selected(candidate) ? '選択中の秘書' : '名無しの秘書'); }
function combatLevelText(candidate: PartyCandidate): string {
    if (candidate.combat_level === null || candidate.combat_level === undefined) return '戦闘Lv—';
    if (candidate.source !== 'borrowed_secretary') return `戦闘Lv${candidate.combat_level}`;
    const effective = candidate.effective_combat_level
        ?? (props.leaderCombatLevel === null || props.leaderCombatLevel === undefined
            ? candidate.combat_level
            : Math.min(candidate.combat_level, props.leaderCombatLevel));

    return `元Lv${candidate.combat_level} → 同期Lv${effective}`;
}
function candidateSource(candidate: PartyCandidate): string {
    return candidate.source === 'self' ? '自分の秘書' : candidate.source === 'borrowed_secretary' ? '借りた秘書' : '仲間';
}
</script>

<template>
    <section class="underground-party-builder" aria-labelledby="underground-party-title">
        <header>
            <div><p class="eyebrow">ASYNC PARTY</p><h2 id="underground-party-title">PT編成</h2></div>
            <p class="underground-party-count">{{ Math.min(4, selectedIds.length + 1) }} / 4人</p>
        </header>
        <p class="underground-party-note">自分の秘書1人は必須。借りた秘書は最大3人です。</p>
        <section v-if="selectedCandidates.length > 0" class="underground-party-selected" aria-label="選択済みPT">
            <h3>選択済みPT</h3>
            <div class="underground-party-candidates" role="list">
                <button
                    v-for="candidate in selectedCandidates"
                    :key="`selected:${candidate.source}:${candidate.secretary_id}`"
                    type="button"
                    role="listitem"
                    class="underground-party-candidate is-selected"
                    :class="{ 'is-unavailable': !candidate.available }"
                    :aria-pressed="true"
                    :aria-label="`${candidateName(candidate)}をPTから解除`"
                    :disabled="disabled"
                    @click="toggle(candidate)"
                >
                    <img v-if="icon(candidate)" :src="icon(candidate)!" :alt="`${candidateName(candidate)}のアイコン`" class="underground-party-candidate-icon">
                    <span v-else class="underground-party-candidate-icon underground-party-candidate-icon-fallback" aria-hidden="true">秘</span>
                    <span class="underground-party-candidate-body">
                        <strong>{{ candidateName(candidate) }}</strong>
                        <small>{{ candidateSource(candidate) }}・{{ combatLevelText(candidate) }}</small>
                        <small v-if="!candidate.available" class="underground-party-unavailable">貸出不可・クリックで解除</small>
                        <small v-else>クリックで解除</small>
                        <small v-if="candidate.growth_path">{{ candidate.growth_path }}<template v-if="build(candidate)">・{{ build(candidate) }}</template></small>
                        <small v-if="equipment(candidate)">装備: {{ equipment(candidate) }}</small>
                        <small v-if="candidate.awakening">覚醒: {{ candidate.awakening }}</small>
                    </span>
                </button>
            </div>
        </section>
        <section v-if="showCandidateListEnabled" class="underground-party-available" aria-label="貸出候補">
            <h3>貸出候補</h3>
            <div v-if="availableCandidates.length > 0" class="underground-party-candidates" role="list">
                <button
                    v-for="candidate in availableCandidates"
                    :key="`${candidate.source}:${candidate.secretary_id}`"
                    type="button"
                    role="listitem"
                    class="underground-party-candidate"
                    :class="{ 'is-unavailable': !candidate.available }"
                    :aria-pressed="selected(candidate)"
                    :aria-label="`${candidateName(candidate)}をPTに${candidate.available ? '追加' : '追加できません'}`"
                    :disabled="disabled || !candidate.available || selectedIds.length >= 3"
                    @click="toggle(candidate)"
                >
                    <img v-if="icon(candidate)" :src="icon(candidate)!" :alt="`${candidateName(candidate)}のアイコン`" class="underground-party-candidate-icon">
                    <span v-else class="underground-party-candidate-icon underground-party-candidate-icon-fallback" aria-hidden="true">秘</span>
                    <span class="underground-party-candidate-body">
                        <strong>{{ candidateName(candidate) }}</strong>
                        <small>{{ candidateSource(candidate) }}・{{ combatLevelText(candidate) }}</small>
                        <small v-if="candidate.source === 'borrowed_secretary' && candidate.available" class="underground-party-availability">貸出可</small>
                        <small v-if="candidate.growth_path">{{ candidate.growth_path }}<template v-if="build(candidate)">・{{ build(candidate) }}</template></small>
                        <small v-if="equipment(candidate)">装備: {{ equipment(candidate) }}</small>
                        <small v-if="candidate.awakening">覚醒: {{ candidate.awakening }}</small>
                        <small v-if="!candidate.available" class="underground-party-unavailable">貸出不可</small>
                    </span>
                </button>
            </div>
            <p v-else class="underground-party-empty">現在、表示できる貸出候補はいません。</p>
        </section>
        <p v-else class="underground-party-candidate-hint">「貸出秘書を探す」から候補を表示できます。</p>
    </section>
</template>
