<script setup lang="ts">
import stories from '../../stories/intro.json';
import loungeStories from '../../stories/lounge.json';
import UndergroundHome from './UndergroundHome.vue';
import UndergroundNavigation from './UndergroundNavigation.vue';
import UndergroundScene from './UndergroundScene.vue';
import UndergroundResidence from './UndergroundResidence.vue';
import { undergroundDestinations, type UndergroundDestination, type UndergroundVisuals, type ResidenceState } from './undergroundScenes';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import UndergroundAiEditor from './UndergroundAiEditor.vue';
import UndergroundSkillTree from './UndergroundSkillTree.vue';
import type { UndergroundSkillTree as SkillTree } from './undergroundSkills';
import UndergroundCombatantCard from './UndergroundCombatantCard.vue';
import UndergroundEquipmentShop from './UndergroundEquipmentShop.vue';
import UndergroundEquipmentVault from './UndergroundEquipmentVault.vue';
import UndergroundPartyBuilder, { type PartyCandidate } from './UndergroundPartyBuilder.vue';
import UndergroundPartyBattleCards from './UndergroundPartyBattleCards.vue';
import { shouldReleasePendingExplorationRequest } from './undergroundExplorationPending';
import type { EquipmentItem, EquipmentSlot } from './EquipmentItemCard.vue';
import type { UndergroundAiConfiguration } from './undergroundAi';
import type { DailyQuestProgress } from '../types';

const maximumBulkSkipExecutions = 1000;

type Stage = 'not_started' | 'initial_descent' | 'tutorial_ready' | 'escape_pending'
    | 'returned_after_tutorial' | 'shopkeeper_encounter' | 'shopkeeper_naming'
    | 'special_loss_pending' | 'special_loss_complete' | 'shop_explanation'
    | 'contract_ready' | 'crystal_selection' | 'growth_path_selected' | 'underground_open';

interface SimpleAction {
    round: number;
    side: string;
    actor_name?: string;
    target_name?: string;
    action_label?: string;
    amount: number;
}

interface RoundAction {
    type: string;
    side: string;
    kind?: string;
    action?: string;
    action_key?: string;
    effect_type?: string;
    action_id?: string | null;
    actor_id?: string | null;
    target_id?: string | null;
    target_ids?: string[];
    actor_name?: string;
    target_name?: string | null;
    target_names?: string[];
    skill_name?: string | null;
    skill_label?: string | null;
    action_label?: string | null;
    label: string;
    amount?: number;
    critical?: boolean;
    evaded?: boolean;
    guarded?: boolean;
    parried?: boolean;
    barrier_absorbed?: number;
    complete_guarded?: boolean;
    agility_combo_hits?: number | null;
    lines?: string[];
    revived?: boolean;
    revive_hp_bps?: number | null;
    important?: boolean;
}

interface RoundState {
    hp: number;
    max_hp: number;
    mp: number;
    barrier: number;
    statuses: Array<{ label: string; remaining: number; stacks: number }>;
    role_stacks: { fighting_spirit: number; grace: number };
    taunt?: { label?: string; remaining?: number } | null;
    awakened?: boolean;
    awakening_technique_used?: boolean;
    awakening_guard_rounds_remaining?: number;
    awakening_lifesteal_rounds_remaining?: number;
    awakening_unlocked?: boolean;
    awakening_gauge?: number;
    awakening_gauge_max?: number;
}

interface RoundStatePair {
    player: RoundState;
    enemy: RoundState;
}

type StateEnvelope = RoundStatePair | Record<string, RoundState>;

interface ImageReference {
    url?: string | null;
    credit?: string | null;
    creation_method_label?: string | null;
}

interface ImageReferences {
    compact?: ImageReference | null;
    awakening_compact?: ImageReference | null;
    normal?: ImageReference | null;
    awakening?: ImageReference | null;
}

interface CombatRound {
    round: number;
    actions: RoundAction[];
    start_state?: StateEnvelope | null;
    end_state: StateEnvelope | null;
    state_timing?: 'battle_start' | 'previous_round_end' | string;
}

interface Battle {
    id: string;
    context: 'tutorial' | 'scripted_loss' | 'playtest' | 'exploration' | 'trial' | 'guide_duel';
    party?: {
        members: Array<PartyBattleMember>;
        enemies?: Array<PartyBattleMember>;
    } | null;
    presentation_log_version?: number;
    portrait_events?: Array<{
        type: 'start' | 'awakening' | 'final';
        combatant_id: string;
        round?: number;
        event_id?: string;
        state?: RoundState | null;
        image_ref?: ImageReference | null;
        image_refs?: { compact?: ImageReference | null; normal?: ImageReference | null; awakening?: ImageReference | null };
    }>;
    player_display_name?: string;
    encounter_name: string;
    build_name?: string;
    result: 'victory' | 'defeat' | 'withdrawal' | 'stalemate';
    rounds?: number | CombatRound[];
    rounds_count?: number;
    xp_awarded: number;
    shard_delta: number;
    combat_level_before?: number;
    combat_level_after?: number;
    stp_awarded?: number;
    unspent_stp_after?: number;
    detail_available: boolean;
    actions?: SimpleAction[] | CombatRound[] | null;
    summary?: Record<string, unknown> | null;
    initial_state?: StateEnvelope | null;
    player_image_references?: ImageReferences | null;
    rewards?: { xp: number; shards: number; g?: number; drops?: unknown[] };
    detail_message?: string | null;
    trial_run_key?: string | null;
    trial_battle_index?: number | null;
    trial_total_battles?: number | null;
    trial_status?: 'active' | 'withdrawn' | 'defeated' | 'cleared' | null;
    trial_next_battle_index?: number | null;
    interbattle_heal_amount?: number;
    first_clear_story?: {
        title: string;
        body: string;
        system_messages: string[];
    } | null;
    challenge_intro?: string | null;
    duel_dialogue?: string[] | null;
    hunting_ground?: {
        key: string;
        name: string;
        content_identity: string;
        item_level_min: number;
        item_level_max: number;
    } | null;
    drop?: {
        identity: string;
        status: 'none' | 'ineligible' | 'granted' | 'vault_full';
        item?: {
            instance_identity: string;
            name: string;
            category: 'weapon' | 'armor' | 'accessory';
            item_level: number;
            rarity: 'common' | 'uncommon' | 'rare' | 'epic' | 'unique';
            rarity_label: string;
            affixes: Array<{ key: string; label: string; target: string; value: number }>;
        };
    } | null;
    treasure?: { found: boolean; base_g: number; multiplier: number; total_g: number } | null;
    shining_kingdom_key?: { balance_before: number; entry_cost?: number; awarded: number; balance_after: number } | null;
    daily_quest?: DailyQuestProgress;
}

interface PartyBattleMember {
    team: 'player' | 'enemy';
    combatant_id: string;
    display_name: string;
    icon_url?: string | null;
    portrait_url?: string | null;
    image_references?: ImageReferences | null;
    state?: RoundState;
    awakening_state?: 'ready' | 'awakened' | null;
}

interface HuntingGround {
    key: string;
    name: string;
    kind: 'hunting_ground' | 'vault';
    locked: boolean;
    unlock_condition: string | null;
    entry_key_cost: number;
    key_balance: number;
    disabled: boolean;
    unavailable_reason: string | null;
    item_level_min: number;
    item_level_max: number;
    skip: SkipProgress;
}

interface SkipProgress {
    actual_clear_count: number;
    total_clear_count: number;
    actual_clears_required: number;
    unlocked: boolean;
    ticket_cost: number;
}

interface SkipResult {
    id: string;
    duplicate: boolean;
    content_type: 'hunting_ground' | 'trial';
    content_key: string;
    execution_count?: number;
    ticket_cost: number;
    xp_awarded: number;
    shards_awarded: number;
    combat_level_before: number;
    combat_level_after: number;
    rewards?: {
        drops?: SkipDrop[];
        equipment_granted_count?: number;
        vault_full_count?: number;
        ticket_balance_after?: number;
        [key: string]: unknown;
    };
    ticket_balance?: number;
    remaining_ticket_balance?: number;
    settled_at: string;
    daily_quest?: DailyQuestProgress;
}

interface SkipDrop {
    status: 'none' | 'ineligible' | 'granted' | 'vault_full' | string;
    quantity?: number;
    count?: number;
    item?: {
        name?: string | null;
        item_level?: number | null;
        rarity_label?: string | null;
        affixes?: Array<{ label?: string | null }>;
    } | null;
}

interface TrialRun {
    key: string;
    label: string;
    run_key: string;
    status: 'active' | 'withdrawn' | 'defeated' | 'cleared';
    next_battle_index: number;
    total_battles: number;
}

interface TrialState {
    key: string;
    label: string;
    total_battles: number;
    first_cleared: boolean;
    active_run: TrialRun | null;
    trials?: TrialOption[];
}

interface TrialOption {
    key: string;
    label: string;
    total_battles: number;
    locked: boolean;
    unlock_condition: string | null;
    first_cleared: boolean;
    skip: SkipProgress;
}

interface AwakeningTechnique {
    key: string;
    name: string;
    summary: string;
    consumes_action: boolean;
}

interface AwakeningState {
    identity: string;
    unlocked: boolean;
    current: number;
    maximum: number;
    custom_message: string | null;
    default_message: string;
    technique: AwakeningTechnique | null;
    techniques: AwakeningTechnique[];
    selected_technique_key: string | null;
}

interface GrowthPath {
    key: string;
    label: string;
    color: string;
    description: string[];
    default_build_key: string;
    stats: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number>;
    max_hp: number;
    max_mp: number;
    natural_recovery: number;
    natural_growth: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number>;
    unspent_stp_per_level: number;
    points_per_level: number;
}

interface RespecState {
    cost: number;
    last_completed_at: string | null;
    next_available_at: string | null;
    growth_paths: GrowthPath[];
}

interface PlaytestOption {
    key: string;
    label: string;
    description: string;
}

interface PlaytestOptions {
    notice: string;
    default_build_key: string;
    builds: PlaytestOption[];
    enemies: PlaytestOption[];
}

interface PendingBankMutation {
    fingerprint: string;
    requestId: string;
}

interface PendingTrialRequest {
    requestId: string;
    runKey: string;
}

type StatKey = 'vitality' | 'might' | 'finesse' | 'spirit' | 'agility';

interface ActiveSkill {
    key: string;
    label: string;
    summary: string;
    mp_cost: number;
    cooldown: number;
    required_weapon_styles: string[];
}

interface PendingMutation {
    fingerprint: string;
    requestId: string;
}

interface PendingSkipRequest extends PendingMutation {
    contentType: 'hunting_ground' | 'trial';
    contentKey: string;
    executionCount: number;
}

interface RecollectionEntry {
    key: string;
    kind: 'historical' | 'past';
    title: string;
    experienced: boolean;
    locked: boolean;
    completed?: boolean;
    chapter?: number;
    body?: string[];
    trial_key?: string;
    battle_id?: number;
}

interface SeriousTalkChoice {
    key: string;
    label: string;
    next: string;
}

interface SeriousTalkScene {
    lines: string[];
    choices: SeriousTalkChoice[];
}

interface SeriousTalk {
    title: string;
    initial_scene: string;
    scenes: Record<string, SeriousTalkScene>;
}

interface RecollectionState {
    available: boolean;
    trial_02_first_cleared: boolean;
    past_available: boolean;
    max_completed: number;
    entries: RecollectionEntry[];
    serious_talk: SeriousTalk | null;
}

interface GuideConversationStart {
    topic_id: number;
    initial_line: string;
    choices: Array<{ position: number; text: string }>;
}

interface GuideConversationReply {
    topic_id: number;
    position: number;
    reply_line: string;
}

interface GuideConversationPunch {
    punch_line: string;
}

interface PendingExplorationRequest {
    requestId: string;
    huntingGroundKey: string;
    intentKey: string;
    borrowedSecretaryIds: number[];
}

interface LendingCandidatesPage {
    candidates: PartyCandidate[];
    next_after_id: number | null;
}

interface UndergroundState {
    visuals?: UndergroundVisuals | null;
    residence?: ResidenceState;
    stage: Stage;
    secretary_name: string;
    combat_level: number;
    combat_xp: number;
    next_level_xp: number;
    next_level_requirement: number;
    xp_to_next_level: number;
    shard_balance: number;
    banked_shard_balance: number;
    next_battle_at: string | null;
    current_hp: number | null;
    unspent_stp: number;
    allocated_stp: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number>;
    current_stats: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number> | null;
    combat_stats: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number> | null;
    status_breakdown: Record<StatKey, {
        baseline: number;
        natural_growth: number;
        allocated_stp: number;
        equipment: number;
        final: number;
    }> | null;
    equipment_summary: EquipmentSummary | null;
    skill_points_total: number;
    skill_points_unspent: number;
    skill_points_spent: number;
    skill_tree_identity: string | null;
    skill_rebuild_required?: boolean;
    rental_party?: Array<{ secretary_id: number; display_name: string; current_hp: number; max_hp: number; awakening_gauge: number }>;
    skill_trees: SkillTree[] | null;
    active_slots: Array<ActiveSkill | null>;
    passive_modifiers: Record<string, number | boolean | string>;
    shopkeeper_name: string | null;
    true_name_branch: boolean;
    tutorial_projection: {
        stats: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number>;
        weapon: string;
    };
    contract_completed: boolean;
    growth_paths: GrowthPath[] | null;
    growth_path: GrowthPath | null;
    respec: RespecState | null;
    playtest: PlaytestOptions | null;
    default_hunting_ground_key?: string | null;
    hunting_grounds?: HuntingGround[] | null;
    trial: TrialState | null;
    awakening: AwakeningState | null;
    recollections?: RecollectionState;
    guide_duel?: { unlocked: boolean; won: boolean; challenge_lines: string[]; accept_lines: string[]; cancel_lines: string[]; rematch_lines: string[]; solo_rematch_lines: string[] } | null;
    ai?: UndergroundAiConfiguration | null;
    battle: Battle | null;
    party_candidates?: PartyCandidate[];
    party_member_ids?: number[];
    lending?: {
        settings: { is_lendable?: boolean; is_public: boolean; is_available: boolean; battle_portrait_preference?: 'full_body' | 'bust' };
        candidates: PartyCandidate[];
        ticket_balance: number;
    } | null;
}

interface EquipmentSummary {
    used: number;
    capacity: number;
    equipped: Record<EquipmentSlot, EquipmentItem | null>;
}

interface EquipmentMutationState {
    shard_balance: number;
    banked_shard_balance: number;
    vault: EquipmentSummary;
}

const initialDescent = stories.underground_intro.body;

const tutorialAftermath = stories.tutorial_aftermath.body;

const encounterStory = stories.shopkeeper_encounter.body;

const trueNameBefore = stories.true_name_before.body;

const trueNameAfter = stories.true_name_after.body;

const shopStory = stories.shop_explanation.body;

const crystalOffer = stories.crystal_offer.body;

const commonEnding = stories.common_ending.body;

const statLabels = { vitality: '生命', might: '武力', finesse: '技巧', spirit: '精神', agility: '敏捷' } as const;
const equipmentSlots: EquipmentSlot[] = ['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'];
const equipmentSlotLabels: Record<EquipmentSlot, string> = {
    weapon: '武器',
    armor: '防具',
    accessory_1: 'アクセサリー1',
    accessory_2: 'アクセサリー2',
    accessory_3: 'アクセサリー3',
};
const huntingGroundPreferenceKey = 'hakoniwa.underground.selected-hunting-ground';
function equipmentSlotLabel(slot: EquipmentSlot): string { return equipmentSlotLabels[slot]; }
const props = defineProps<{ secretaryImageUrl?: string | null; userId?: number }>();
const emit = defineEmits<{
    returnToSecretary: [];
    dailyQuest: [quest: DailyQuestProgress];
}>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const mutationRejected = ref(false);
const error = ref('');
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const recentBattles = computed(() => battles.value.slice(0, 5));
const selectedBattle = ref<Battle | null>(null);
const selectedPartyMemberIds = ref<number[]>([]);
const detailVisible = ref(true);
const detailPreferenceKey = 'hakoniwa.underground.battle-detail-visible';
const lastScrolledBattleId = ref<string | null>(null);
const lendingEnabled = ref(false);
const partySelectionHydrated = ref(false);
const selectedBuild = ref('');
const selectedEnemy = ref('');
const bankAmount = ref<number | null>(1000);
const selectedHuntingGroundKey = ref('shallow_caves');
const selectedSkipHuntingGroundKey = ref('shallow_caves');
const selectedTrialKey = ref('trial_01');
const selectedSkipTrialKey = ref('trial_01');
const customHuntingGroundSkipCount = ref('');
const customTrialSkipCount = ref('');
const pendingExplorationRequest = ref<PendingExplorationRequest | null>(null);
const partyCandidateSearchOpen = ref(false);
const partyCandidateLoading = ref(false);
const partyCandidateLoadedOnce = ref(false);
const partyCandidateSearchComplete = ref(false);
const partyCandidateNextAfterId = ref<number | null>(null);
const loadedPartyCandidates = ref<PartyCandidate[]>([]);
const knownPartyCandidates = ref(new Map<string, PartyCandidate>());
const pendingTrialRequest = ref<PendingTrialRequest | null>(null);
const pendingSkipRequest = ref<PendingSkipRequest | null>(null);
const lastSkipResult = ref<SkipResult | null>(null);
const skipModalOpen = ref(false);
const skipError = ref('');
const pendingInnRequestId = ref<string | null>(null);
const pendingBankMutation = ref<PendingBankMutation | null>(null);
const innResting = ref(false);
const innRested = ref(false);
const stpDraft = ref<Record<StatKey, number>>({ vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 });
const loadoutDraft = ref<Array<string | null>>([null, null, null, null, null]);
const pendingStpMutation = ref<PendingMutation | null>(null);
const pendingSkillAcquire = ref<PendingMutation | null>(null);
const pendingLoadoutMutation = ref<PendingMutation | null>(null);
const pendingAwakeningMessageMutation = ref<PendingMutation | null>(null);
const pendingAwakeningTechniqueMutation = ref<PendingMutation | null>(null);
const awakeningMessageDraft = ref('');
const awakeningTechniqueDraft = ref<string | null>(null);
type View = 'home' | 'adventure' | 'trials' | 'secret' | 'history' | 'playtest' | 'character' | 'status' | 'skills' | 'shop' | 'bank' | 'guide' | 'ai' | 'vault' | 'party' | 'property' | 'villa' | 'recollections';
const equipmentView = ref<View>('home');
const tabs: Record<UndergroundDestination, Array<{ key: View; label: string }>> = {
    home: [],
    adventure: [{ key: 'adventure', label: '探索' }, { key: 'trials', label: '試練' }, { key: 'secret', label: '秘密の場所' }, { key: 'history', label: '戦闘履歴' }, { key: 'playtest', label: '力試し' }],
    character: [{ key: 'character', label: '能力' }, { key: 'status', label: 'STP配分' }, { key: 'skills', label: 'スキル・覚醒' }, { key: 'vault', label: '装備・保管庫' }, { key: 'ai', label: '戦法' }],
    shop: [{ key: 'shop', label: '装備を買う' }, { key: 'bank', label: '銀行' }, { key: 'guide', label: '案内人と話す' }],
    exchange: [{ key: 'party', label: 'パーティー' }, { key: 'property', label: '不動産' }],
    villa: [{ key: 'villa', label: '冒険日誌' }, { key: 'recollections', label: '回想' }],
};
const currentDestination = computed<UndergroundDestination>(() => undergroundDestinations.find(destination =>
    destination.key === equipmentView.value || tabs[destination.key].some(tab => tab.key === equipmentView.value))?.key ?? 'home');
const pageTabs = computed(() => tabs[currentDestination.value].filter(tab => tab.key !== 'playtest' || state.value?.playtest));
const pageTitle = computed(() => undergroundDestinations.find(destination => destination.key === currentDestination.value)?.label);
const exchangeGreeting = ref(loungeStories.greetings[0]);
const loungeReplay = ref<'exchange-1' | 'exchange-2' | 'mirror' | null>(null);
const activeLoungeEvent = computed(() => {
    const residence = state.value?.residence;
    if (!residence) return null;
    if (loungeReplay.value === 'mirror' || currentDestination.value === 'shop' && residence.mirror_owned && !residence.mirror_event_completed) {
        return { ...loungeStories.mirror, key: 'mirror', page: 1, scene: 'mirror' };
    }
    if (loungeReplay.value?.startsWith('exchange-') || currentDestination.value === 'exchange' && residence.exchange_intro_page < 2) {
        const page = loungeReplay.value === 'exchange-2' ? 2 : loungeReplay.value === 'exchange-1' ? 1 : residence.exchange_intro_page + 1;
        return { ...loungeStories.exchange[page - 1]!, key: 'exchange', page, scene: 'exchange-intro' };
    }
    return null;
});
const pendingLoungeMutation = ref<{ fingerprint: string; requestId: string; path: string; payload: Record<string, string | number> } | null>(null);
async function loungeMutation(path: string, payload: Record<string, string | number>): Promise<boolean> {
    if (busy.value) return false;
    const fingerprint = JSON.stringify({ path, ...payload });
    if (pendingLoungeMutation.value && pendingLoungeMutation.value.fingerprint !== fingerprint) {
        error.value = '前の操作の結果を確認してから続けてください。';
        return false;
    }
    const pending = pendingLoungeMutation.value ?? { fingerprint, requestId: requestId(), path, payload };
    pendingLoungeMutation.value = pending;
    const success = await mutate('/api/v1/me/underground/' + path, payload, pending.requestId);
    if (success || mutationRejected.value) pendingLoungeMutation.value = null;
    return success;
}
function navigate(destination: UndergroundDestination): void {
    loungeReplay.value = null;
    equipmentView.value = tabs[destination][0]?.key ?? 'home';
    if (destination === 'exchange') exchangeGreeting.value = loungeStories.greetings[Math.floor(Math.random() * loungeStories.greetings.length)]!;
}
function selectTab(view: View): void {
    loungeReplay.value = null;
    if (view === 'guide') openGuide();
    else if (view === 'recollections') {
        guideMode.value = 'recollections';
        selectedRecollectionKey.value = null;
        equipmentView.value = view;
    } else equipmentView.value = view;
}
async function advanceLounge(): Promise<void> {
    if (loungeReplay.value) { loungeReplay.value = null; return; }
    const event = activeLoungeEvent.value;
    if (event) await loungeMutation('events/advance', { event: event.key, page: event.page });
}

const guideMode = ref<'basic' | 'conversation' | 'recollections' | 'serious_talk' | 'respec' | 'guide_duel'>('basic');
const guideDuelCancelled = ref(false);
const pendingGuideDuel = ref<{ requestId: string; borrowedSecretaryIds: number[] } | null>(null);
const guideDuelLines = computed(() => {
    const duel = state.value?.guide_duel;
    if (!duel) return [];
    if (guideDuelCancelled.value) return duel.cancel_lines;
    if (!duel.won) return duel.challenge_lines;
    const partyIds = pendingGuideDuel.value?.borrowedSecretaryIds ?? confirmedPartyIds.value;
    return partyIds.length > 0 ? duel.rematch_lines : duel.solo_rematch_lines;
});
const guideConversation = ref<GuideConversationStart | null>(null);
const guideConversationLine = ref('');
const guideConversationPhase = ref<'topic' | 'reply' | 'punch'>('topic');
const selectedRecollectionKey = ref<string | null>(null);
const seriousTalkSceneKey = ref('root');
const selectedRespecPathKey = ref<string | null>(null);
const respecConfirmOpen = ref(false);
const pendingRespecMutation = ref<PendingMutation | null>(null);
const pendingRecollectionMutation = ref<PendingMutation | null>(null);
const cooldownNowMs = ref(Date.now());
let cooldownTimer: ReturnType<typeof window.setInterval> | null = null;
let huntingGroundPreferenceHydrated = false;
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);
const partySelectedIds = computed(() => selectedPartyMemberIds.value);
const pendingRentalMutation = ref<PendingMutation | null>(null);
const confirmedPartyIds = computed(() => (state.value?.rental_party ?? []).map((member) => member.secretary_id));
const rentalDraftChanged = computed(() => JSON.stringify(partySelectedIds.value) !== JSON.stringify(confirmedPartyIds.value));
const statePartyCandidates = computed(() => [
    ...(state.value?.lending?.candidates ?? []),
    ...(state.value?.party_candidates ?? []),
]);
const partyCandidates = computed<PartyCandidate[]>(() => {
    const rows = new Map<string, PartyCandidate>();
    for (const candidate of [...statePartyCandidates.value, ...loadedPartyCandidates.value]) {
        rows.set(`${candidate.source}:${candidate.secretary_id}`, candidate);
    }
    const loadedBorrowedIds = new Set(
        loadedPartyCandidates.value
            .filter((candidate) => candidate.source === 'borrowed_secretary')
            .map((candidate) => candidate.secretary_id),
    );
    for (const secretaryId of partySelectedIds.value) {
        const selected = [...rows.values()].find((candidate) => candidate.secretary_id === secretaryId);
        if (selected) {
            if (partyCandidateSearchComplete.value
                && selected.source === 'borrowed_secretary'
                && !loadedBorrowedIds.has(selected.secretary_id)) {
                rows.set(`${selected.source}:${selected.secretary_id}`, { ...selected, available: false });
            }
            continue;
        }
        const known = [...knownPartyCandidates.value.values()].find((candidate) => candidate.secretary_id === secretaryId);
        const unavailable: PartyCandidate = known ?? {
            secretary_id: secretaryId,
            source: 'borrowed_secretary',
            display_name: null,
            combat_level: null,
            available: false,
        };
        rows.set(`${unavailable.source}:${unavailable.secretary_id}`, { ...unavailable, available: false });
    }

    return [...rows.values()];
});
const availableBorrowedCandidates = computed(() => partyCandidates.value.filter((candidate) => candidate.source === 'borrowed_secretary'
    && candidate.available
    && !partySelectedIds.value.includes(candidate.secretary_id)));

function rememberPartyCandidates(candidates: PartyCandidate[]): void {
    for (const candidate of candidates) {
        knownPartyCandidates.value.set(`${candidate.source}:${candidate.secretary_id}`, candidate);
    }
}

watch(statePartyCandidates, (candidates) => rememberPartyCandidates(candidates), { deep: true, immediate: true });
const skipTicketBalance = computed(() => state.value?.lending?.ticket_balance ?? null);
const recollectionEntries = computed(() => (state.value?.recollections?.entries ?? [])
    .filter(entry => equipmentView.value === 'recollections' ? entry.experienced : entry.kind === 'past' && !entry.completed));
const selectedRecollection = computed(() => recollectionEntries.value.find((entry) => entry.key === selectedRecollectionKey.value) ?? null);
const seriousTalkScene = computed(() => {
    const talk = state.value?.recollections?.serious_talk;
    return talk?.scenes[seriousTalkSceneKey.value] ?? null;
});
const seriousTalkChoices = computed<SeriousTalkChoice[]>(() => {
    const choices = [...(seriousTalkScene.value?.choices ?? [{ key: 'leave', label: '戻る', next: 'guide' }])];
    if (seriousTalkSceneKey.value === 'root' && state.value?.guide_duel?.unlocked) {
        const leaveIndex = choices.findIndex((choice) => choice.next === 'guide');
        choices.splice(leaveIndex < 0 ? choices.length : leaveIndex, 0, { key: 'guide_duel', label: '勝負を挑む', next: 'guide_duel' });
    }
    return choices;
});
const unlockedHuntingGrounds = computed(() => (state.value?.hunting_grounds ?? [])
    .filter((ground) => !ground.locked));
const ordinaryHuntingGrounds = computed(() => unlockedHuntingGrounds.value
    .filter((ground) => ground.kind !== 'vault'));
const selectedHuntingGround = computed(() => ordinaryHuntingGrounds.value
    .find((ground) => ground.key === selectedHuntingGroundKey.value) ?? null);
const selectedSkipHuntingGround = computed(() => unlockedHuntingGrounds.value
    .find((ground) => ground.key === selectedSkipHuntingGroundKey.value) ?? unlockedHuntingGrounds.value[0] ?? null);
const shiningKingdomVault = computed(() => unlockedHuntingGrounds.value
    .find((ground) => ground.kind === 'vault') ?? null);
const trialOptions = computed<TrialOption[]>(() => {
    const trial = state.value?.trial;
    if (!trial) return [];
    return trial.trials ?? [{
        key: trial.key,
        label: trial.label,
        total_battles: trial.total_battles,
        locked: false,
        unlock_condition: null,
        first_cleared: trial.first_cleared,
        skip: {
            actual_clear_count: 0,
            total_clear_count: 0,
            actual_clears_required: 5,
            unlocked: false,
            ticket_cost: 10,
        },
    }];
});
const unlockedTrialOptions = computed(() => trialOptions.value.filter((trial) => !trial.locked));
const selectedTrial = computed(() => {
    const activeKey = state.value?.trial?.active_run?.key;

    return unlockedTrialOptions.value.find((trial) => trial.key === activeKey)
        ?? unlockedTrialOptions.value.find((trial) => trial.key === selectedTrialKey.value)
        ?? unlockedTrialOptions.value[0]
        ?? null;
});
const selectedSkipTrial = computed(() => unlockedTrialOptions.value.find((trial) => trial.key === selectedSkipTrialKey.value)
    ?? unlockedTrialOptions.value[0]
    ?? null);
const repeatableExplorationGroundKey = computed(() => {
    const battle = currentBattle.value;
    if (battle?.context !== 'exploration' || !battle.hunting_ground) return null;
    return unlockedHuntingGrounds.value.some((ground) => ground.key === battle.hunting_ground?.key)
        ? battle.hunting_ground.key
        : null;
});
const canAdvanceTrial = computed(() => {
    const battle = currentBattle.value;
    const activeRun = state.value?.trial?.active_run;
    return battle?.context === 'trial'
        && battle.result === 'victory'
        && battle.trial_status === 'active'
        && activeRun !== null
        && activeRun !== undefined
        && activeRun.run_key === battle.trial_run_key
        && activeRun.next_battle_index === battle.trial_next_battle_index
        && typeof battle.trial_battle_index === 'number'
        && typeof battle.trial_next_battle_index === 'number'
        && battle.trial_next_battle_index > battle.trial_battle_index
        && battle.trial_next_battle_index <= activeRun.total_battles;
});
const exploreCooldownSeconds = computed(() => {
    const nextBattleAt = state.value?.next_battle_at;
    if (!nextBattleAt) return 0;
    const timestamp = Date.parse(nextBattleAt);
    if (!Number.isFinite(timestamp)) return 0;
    return Math.max(0, Math.ceil((timestamp - cooldownNowMs.value) / 1_000));
});
const currentPlayerDisplayName = computed(() => currentBattle.value
    ? playerDisplayName(currentBattle.value)
    : state.value?.secretary_name ?? '秘書');
const currentStructuredRounds = computed(() => currentBattle.value ? structuredRounds(currentBattle.value) : []);
function isStatePair(value: StateEnvelope | null | undefined): value is RoundStatePair {
    return value !== null
        && value !== undefined
        && typeof value === 'object'
        && 'player' in value
        && 'enemy' in value;
}
function hasStateEnvelope(value: unknown): value is StateEnvelope {
    return value !== null
        && value !== undefined
        && typeof value === 'object'
        && Object.keys(value).length > 0;
}
function partyStateMap(value: StateEnvelope | null | undefined): Record<string, RoundState> | null {
    return hasStateEnvelope(value) && !isStatePair(value) ? value : null;
}
function soloState(value: StateEnvelope | null | undefined, side: 'player' | 'enemy'): RoundState | null {
    return isStatePair(value) ? value[side] : null;
}
const currentPartyActors = computed(() => {
    const party = currentBattle.value?.party;
    return party ? [...party.members, ...(party.enemies ?? [])] : [];
});
const finalBattleState = computed<StateEnvelope | null>(() => {
    const summaryFinalState = currentBattle.value?.summary?.final_state;
    if (hasStateEnvelope(summaryFinalState)) return summaryFinalState;
    const roundState = [...currentStructuredRounds.value]
        .reverse()
        .find((round) => hasStateEnvelope(round.end_state))?.end_state;
    if (hasStateEnvelope(roundState)) return roundState;
    return null;
});
const growthEnding = computed(() => state.value?.growth_path?.key === 'free_black'
    ? stories.growth_ending.free_black
    : stories.growth_ending.default);
const shopGreeting = computed(() => innRested.value
    ? '「いい夢は見られましたか？　それじゃ、頑張ってくださいね！」'
    : state.value?.true_name_branch
        ? '「いらっしゃいませ！　『雨宿り』箱庭ダンジョン支店です！」'
        : '「いらっしゃいませ！　あなたのコンビニ、箱庭ダンジョン店です！」');
const respecCooldownSeconds = computed(() => {
    const nextAvailableAt = state.value?.respec?.next_available_at;
    if (!nextAvailableAt) return 0;
    const timestamp = Date.parse(nextAvailableAt);
    if (!Number.isFinite(timestamp)) return 0;
    return Math.max(0, Math.ceil((timestamp - cooldownNowMs.value) / 1_000));
});
const respecActiveTrial = computed(() => Boolean(state.value?.trial?.active_run));
const respecInsufficientShards = computed(() => {
    const respec = state.value?.respec;
    return respec !== null && respec !== undefined && state.value !== null
        && state.value.shard_balance < respec.cost;
});
const respecUnavailable = computed(() => state.value?.respec === null
    || state.value?.respec === undefined
    || respecActiveTrial.value
    || respecCooldownSeconds.value > 0
    || respecInsufficientShards.value);
const respecUnavailableReason = computed(() => {
    if (state.value?.respec === null || state.value?.respec === undefined) return '再振りを利用できません。';
    if (respecActiveTrial.value) return 'active Trial中は再振りできません。封印の地から帰還してください。';
    if (respecCooldownSeconds.value > 0) return `次の再振りまであと${respecCooldownSeconds.value}秒です。`;
    if (respecInsufficientShards.value) return '手持ちの輝石のかけらが不足しています。';
    return '';
});
const respecPaths = computed(() => state.value?.respec?.growth_paths ?? []);
const selectedRespecPath = computed(() => respecPaths.value.find((path) => path.key === selectedRespecPathKey.value) ?? null);
const stpDraftTotal = computed(() => Object.values(stpDraft.value).reduce((sum, value) => sum + value, 0));
const stpDraftRemaining = computed(() => Math.max(0, (state.value?.unspent_stp ?? 0) - stpDraftTotal.value));
const acquiredActiveSkills = computed<ActiveSkill[]>(() => (state.value?.skill_trees ?? [])
    .flatMap((tree) => tree.nodes)
    .filter((node) => node.type === 'active' && node.rank > 0 && node.skill_key !== null)
    .map((node) => ({
        key: node.skill_key as string,
        label: node.label,
        summary: node.summary,
        mp_cost: node.mp_cost ?? 0,
        cooldown: node.cooldown ?? 0,
        required_weapon_styles: node.required_weapon_styles,
    })));
const currentWeaponStyle = computed(() => state.value?.equipment_summary?.equipped.weapon?.weapon_style ?? null);
const weaponStyleLabels: Record<string, string> = {
    dagger: '短剣',
    rapier: '細身剣',
    longsword: '長剣',
    crystal_staff: '輝石杖',
};
const summaryLabels: Record<string, string> = {
    result: '結果',
    damage_dealt: '与ダメージ',
    damage_received: '被ダメージ',
    effective_healing: '有効回復',
    damage_prevented: '防いだダメージ',
    mp_spent: 'MP消費',
    mp_natural_recovery: '自然回復MP',
    mp_skill_recovery: 'スキル回復MP',
    skill_unavailable_due_to_mp: 'MP不足回数',
};
const hiddenSummaryKeys = new Set([
    'rounds',
    'player_remaining_hp',
    'enemy_remaining_hp',
    'final_mp',
    'awakening_triggered',
    'awakening_technique_used',
]);

watch(() => state.value?.playtest, (playtest) => {
    if (!playtest) return;
    selectedBuild.value = playtest.default_build_key;
    selectedEnemy.value ||= playtest.enemies[0]?.key ?? '';
}, { immediate: true });
watch(() => state.value?.lending?.settings, (settings) => {
    if (!settings) return;
    lendingEnabled.value = settings.is_lendable ?? (settings.is_public && settings.is_available);
}, { deep: true, immediate: true });

watch(trialOptions, (trials) => {
    const activeKey = state.value?.trial?.active_run?.key;
    if (activeKey && trials.some((trial) => trial.key === activeKey)) {
        selectedTrialKey.value = activeKey;
    } else if (!trials.some((trial) => trial.key === selectedTrialKey.value && !trial.locked)) {
        selectedTrialKey.value = trials.find((trial) => !trial.locked)?.key ?? trials[0]?.key ?? 'trial_01';
    }
    if (!trials.some((trial) => trial.key === selectedSkipTrialKey.value && !trial.locked)) {
        selectedSkipTrialKey.value = trials.find((trial) => !trial.locked)?.key ?? trials[0]?.key ?? 'trial_01';
    }
}, { deep: true, immediate: true });

watch(state, (current) => {
    if (!current || partySelectionHydrated.value) return;
    selectedPartyMemberIds.value = (current.rental_party ?? []).map((member) => member.secretary_id);
    partySelectionHydrated.value = true;
}, { immediate: true });

watch(() => state.value?.active_slots, (slots) => {
    if (!slots || pendingLoadoutMutation.value) return;
    loadoutDraft.value = slots.map((slot) => slot?.key ?? null);
}, { deep: true, immediate: true });

watch(() => state.value?.awakening, (awakening) => {
    if (!awakening) return;
    if (!pendingAwakeningMessageMutation.value) {
        awakeningMessageDraft.value = awakening.custom_message ?? awakening.default_message;
    }
    if (!pendingAwakeningTechniqueMutation.value) {
        awakeningTechniqueDraft.value = awakening.selected_technique_key;
    }
}, { deep: true, immediate: true });

watch(() => state.value?.hunting_grounds, (grounds) => {
    if (!grounds) return;
    const unlocked = grounds.filter((ground) => !ground.locked && ground.kind !== 'vault');
    let preferredKey = selectedHuntingGroundKey.value;
    if (!huntingGroundPreferenceHydrated) {
        try {
            preferredKey = window.localStorage.getItem(huntingGroundPreferenceKey) ?? 'shallow_caves';
        } catch {
            preferredKey = 'shallow_caves';
        }
        huntingGroundPreferenceHydrated = true;
    }
    const nextKey = unlocked.some((ground) => ground.key === preferredKey)
        ? preferredKey
        : unlocked.find((ground) => ground.key === 'shallow_caves')?.key ?? unlocked[0]?.key ?? 'shallow_caves';
    selectedHuntingGroundKey.value = nextKey;
    try {
        window.localStorage.setItem(huntingGroundPreferenceKey, nextKey);
    } catch {
        // Browser storage may be unavailable; the in-memory safe fallback remains valid.
    }
}, { deep: true, immediate: true });

function requestId(): string {
    return crypto.randomUUID();
}

function selectHuntingGround(key: string): void {
    if (!ordinaryHuntingGrounds.value.some((ground) => ground.key === key)) return;
    selectedHuntingGroundKey.value = key;
    try {
        window.localStorage.setItem(huntingGroundPreferenceKey, key);
    } catch {
        // Browser storage is optional for this UI-only preference.
    }
}

function changeHuntingGround(event: Event): void {
    if (event.target instanceof HTMLSelectElement) selectHuntingGround(event.target.value);
}

function changeSkipHuntingGround(event: Event): void {
    const target = event.target;
    if (!(target instanceof HTMLSelectElement)) return;
    if (unlockedHuntingGrounds.value.some((ground) => ground.key === target.value)) {
        selectedSkipHuntingGroundKey.value = target.value;
    }
}

async function refresh(returnIfTutorialAlreadyFinished = true): Promise<void> {
    innRested.value = false;
    state.value = await api<UndergroundState>('/api/v1/me/underground');
    cooldownNowMs.value = Date.now();
    if (returnIfTutorialAlreadyFinished && state.value.stage === 'returned_after_tutorial') {
        emit('returnToSecretary');
        return;
    }
    if (state.value.stage === 'underground_open') await loadBattles();
}

async function mutate(
    path: string,
    body: Record<string, unknown> = {},
    mutationRequestId = requestId(),
    method: 'POST' | 'PUT' = 'POST',
): Promise<boolean> {
    if (busy.value) return false;
    busy.value = true;
    mutationRejected.value = false;
    error.value = '';
    innRested.value = false;
    try {
        state.value = await api<UndergroundState>(path, {
            method,
            body: JSON.stringify({ request_id: mutationRequestId, ...body }),
        });
        cooldownNowMs.value = Date.now();
        selectedBattle.value = null;
        if (state.value.stage === 'returned_after_tutorial') {
            emit('returnToSecretary');
            return true;
        }
        if (state.value.stage === 'underground_open') {
            try {
                await loadBattles();
            } catch (caught) {
                error.value = caught instanceof Error ? caught.message : '戦闘履歴を更新できませんでした。';
            }
        }
        return true;
    } catch (caught) {
        mutationRejected.value = caught instanceof ApiError && caught.status >= 400 && caught.status < 500 && caught.status !== 408;
        if (caught instanceof ApiError && caught.status === 409) {
            try { await refresh(); } catch { /* Keep the server's rejection visible. */ }
        }
        error.value = caught instanceof Error ? caught.message : '地下の状態を更新できませんでした。';
        return false;
    } finally {
        busy.value = false;
    }
}

async function submitName(): Promise<void> {
    await mutate('/api/v1/me/underground/shopkeeper/name', { name: shopkeeperName.value });
}

async function chooseGrowthPath(key: string): Promise<void> {
    await mutate('/api/v1/me/underground/growth-path', { growth_path_key: key });
}

function openGuide(mode: 'basic' | 'conversation' | 'recollections' | 'serious_talk' | 'respec' = 'basic'): void {
    equipmentView.value = 'guide';
    guideMode.value = mode;
    if (mode !== 'conversation') resetGuideConversation();
    if (mode === 'serious_talk') {
        seriousTalkSceneKey.value = state.value?.recollections?.serious_talk?.initial_scene ?? 'root';
    }
    if (mode !== 'respec') {
        selectedRespecPathKey.value = null;
        respecConfirmOpen.value = false;
    }
}

async function startGuideConversation(): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    guideMode.value = 'conversation';
    resetGuideConversation();
    try {
        const topic = await api<GuideConversationStart>('/api/v1/me/underground/guide-conversation/start', {
            method: 'POST',
        });
        guideConversation.value = topic;
        guideConversationLine.value = topic.initial_line;
        guideConversationPhase.value = 'topic';
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '案内人との会話を始められませんでした。';
    } finally {
        busy.value = false;
    }
}

async function chooseGuideConversationReply(position: number): Promise<void> {
    const topic = guideConversation.value;
    if (busy.value || topic === null || guideConversationPhase.value !== 'topic') return;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<GuideConversationReply>('/api/v1/me/underground/guide-conversation/reply', {
            method: 'POST',
            body: JSON.stringify({ topic_id: topic.topic_id, position }),
        });
        guideConversationLine.value = result.reply_line;
        guideConversationPhase.value = 'reply';
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '案内人へ返答できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function punchGuide(): Promise<void> {
    if (busy.value || guideConversation.value === null) return;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<GuideConversationPunch>('/api/v1/me/underground/guide-conversation/punch', {
            method: 'POST',
        });
        guideConversationLine.value = result.punch_line;
        guideConversationPhase.value = 'punch';
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'げんこつできませんでした。';
    } finally {
        busy.value = false;
    }
}

function stopGuideConversation(): void {
    resetGuideConversation();
    guideMode.value = 'basic';
}

function resetGuideConversation(): void {
    guideConversation.value = null;
    guideConversationLine.value = '';
    guideConversationPhase.value = 'topic';
}

function openRecollections(): void {
    if (state.value?.recollections?.available !== true) return;
    selectedRecollectionKey.value = null;
    openGuide('recollections');
}

function selectRecollection(entry: RecollectionEntry): void {
    if (entry.locked || !entry.experienced && entry.kind === 'historical') return;
    selectedRecollectionKey.value = entry.key;
}

function recollectionStartsSection(entry: RecollectionEntry): boolean {
    return entry.key === 'trial_01_start'
        || entry.key === 'trial_02_start'
        || entry.key === 'past_1';
}

async function completeRecollection(entry: RecollectionEntry): Promise<void> {
    const chapter = entry.chapter;
    const recollections = state.value?.recollections;
    if (chapter === undefined
        || entry.kind !== 'past'
        || entry.completed === true
        || entry.locked
        || recollections?.available !== true
        || chapter !== recollections.max_completed + 1
        || busy.value) {
        return;
    }
    selectedRecollectionKey.value = entry.key;
    const fingerprint = JSON.stringify({ chapter });
    const pending = pendingRecollectionMutation.value?.fingerprint === fingerprint
        ? pendingRecollectionMutation.value
        : { fingerprint, requestId: requestId() };
    pendingRecollectionMutation.value = pending;
    if (await mutate('/api/v1/me/underground/recollections/read', { chapter }, pending.requestId)) {
        pendingRecollectionMutation.value = null;
    }
}

function openSeriousTalk(): void {
    if (!state.value?.recollections?.serious_talk && !state.value?.guide_duel?.unlocked) return;
    openGuide('serious_talk');
}

async function runGuideDuel(): Promise<void> {
    if (busy.value || !state.value?.guide_duel?.unlocked) return;
    const pending = pendingGuideDuel.value ?? { requestId: requestId(), borrowedSecretaryIds: [...confirmedPartyIds.value] };
    pendingGuideDuel.value = pending;
    busy.value = true;
    error.value = '';
    try {
        const battle = await api<Battle>('/api/v1/me/underground/guide-duel', {
            method: 'POST', body: JSON.stringify({ request_id: pending.requestId, borrowed_secretary_ids: pending.borrowedSecretaryIds }),
        });
        await refresh(false);
        selectedBattle.value = battle;
        pendingGuideDuel.value = null;
    } catch (caught) {
        if (shouldReleasePendingExplorationRequest(caught)) pendingGuideDuel.value = null;
        error.value = caught instanceof Error ? caught.message : '決闘の結果を確認できませんでした。';
    } finally {
        busy.value = false;
    }
}

function chooseSeriousTalk(choice: SeriousTalkChoice): void {
    if (!seriousTalkChoices.value.some((candidate) => candidate.key === choice.key && candidate.next === choice.next)) {
        return;
    }
    if (choice.next === 'guide_duel') {
        guideMode.value = 'guide_duel';
        guideDuelCancelled.value = false;
        return;
    }
    if (choice.next === 'guide') {
        openGuide();
        return;
    }
    seriousTalkSceneKey.value = choice.next;
}

function selectRespecPath(key: string): void {
    if (respecUnavailable.value || busy.value || !respecPaths.value.some((path) => path.key === key)) return;
    selectedRespecPathKey.value = key;
}

function openRespecConfirmation(): void {
    if (respecUnavailable.value || busy.value || selectedRespecPath.value === null) return;
    respecConfirmOpen.value = true;
    error.value = '';
}

async function confirmRespec(): Promise<void> {
    const path = selectedRespecPath.value;
    if (path === null || respecUnavailable.value || busy.value) return;
    const fingerprint = JSON.stringify({ growth_path_key: path.key });
    const pending = pendingRespecMutation.value?.fingerprint === fingerprint
        ? pendingRespecMutation.value
        : { fingerprint, requestId: requestId() };
    pendingRespecMutation.value = pending;
    if (await mutate('/api/v1/me/underground/respec', { growth_path_key: path.key }, pending.requestId)) {
        pendingRespecMutation.value = null;
        stpDraft.value = { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 };
        pendingStpMutation.value = null;
        pendingSkillAcquire.value = null;
        pendingLoadoutMutation.value = null;
        loadoutDraft.value = state.value?.active_slots.map((slot) => slot?.key ?? null)
            ?? [null, null, null, null, null];
        selectedRespecPathKey.value = null;
        respecConfirmOpen.value = false;
    }
}

async function loadBattles(): Promise<void> {
    battles.value = await api<Battle[]>('/api/v1/me/underground/battles');
}

async function showBattle(battle: Battle): Promise<void> {
    innRested.value = false;
    error.value = '';
    try {
        selectedBattle.value = battle.detail_available
            ? await api<Battle>(`/api/v1/me/underground/battles/${battle.id}`)
            : battle;
    } catch (caught) {
        selectedBattle.value = battle;
        error.value = caught instanceof Error ? caught.message : '戦闘ログを読み込めませんでした。';
    }
}

async function loadPartyCandidates(reset = false): Promise<void> {
    if (partyCandidateLoading.value) return;
    if (!reset && partyCandidateLoadedOnce.value && partyCandidateNextAfterId.value === null) return;
    if (reset) {
        loadedPartyCandidates.value = [];
        partyCandidateNextAfterId.value = null;
        partyCandidateSearchComplete.value = false;
        partyCandidateLoadedOnce.value = false;
    }
    partyCandidateLoading.value = true;
    error.value = '';
    try {
        const afterId = reset ? null : partyCandidateNextAfterId.value;
        const query = afterId === null ? '' : `?after_id=${encodeURIComponent(String(afterId))}`;
        const page = await api<LendingCandidatesPage>(`/api/v1/me/underground/lending/candidates${query}`);
        rememberPartyCandidates(page.candidates ?? []);
        const merged = new Map<string, PartyCandidate>();
        for (const candidate of [...loadedPartyCandidates.value, ...(page.candidates ?? [])]) {
            merged.set(`${candidate.source}:${candidate.secretary_id}`, candidate);
        }
        loadedPartyCandidates.value = [...merged.values()];
        partyCandidateLoadedOnce.value = true;
        partyCandidateNextAfterId.value = page.next_after_id ?? null;
        partyCandidateSearchComplete.value = partyCandidateNextAfterId.value === null;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '貸出候補を読み込めませんでした。';
    } finally {
        partyCandidateLoading.value = false;
    }
}

async function togglePartyCandidateSearch(): Promise<void> {
    partyCandidateSearchOpen.value = !partyCandidateSearchOpen.value;
    if (partyCandidateSearchOpen.value) {
        await loadPartyCandidates(true);
    }
}

async function loadMorePartyCandidates(): Promise<void> {
    await loadPartyCandidates(false);
}

function togglePartyMember(candidate: PartyCandidate): void {
    const current = [...partySelectedIds.value];
    // A secretary can only occur once in a party, and the owner's secretary is
    // never a valid borrowed slot. The server remains authoritative on submit.
    if (candidate.source === 'borrowed_secretary' && partyCandidates.value.some((item) => (
        item.source === 'self' && item.secretary_id === candidate.secretary_id && current.includes(item.secretary_id)
    ))) return;
    const index = current.indexOf(candidate.secretary_id);
    if (index >= 0) current.splice(index, 1);
    else if (current.length < 3) current.push(candidate.secretary_id);
    selectedPartyMemberIds.value = current;
}

function toggleBattleDetails(): void {
    detailVisible.value = !detailVisible.value;
    try { window.localStorage.setItem(detailPreferenceKey, String(detailVisible.value)); } catch { /* optional */ }
}

async function saveRentalParty(): Promise<void> {
    if (busy.value || pendingExplorationRequest.value) return;
    const ids = [...partySelectedIds.value];
    const fingerprint = JSON.stringify(ids);
    const pending = pendingRentalMutation.value?.fingerprint === fingerprint
        ? pendingRentalMutation.value : { fingerprint, requestId: requestId() };
    pendingRentalMutation.value = pending;
    if (await mutate('/api/v1/me/underground/rental-party', { borrowed_secretary_ids: ids }, pending.requestId, 'PUT')) {
        pendingRentalMutation.value = null;
        selectedPartyMemberIds.value = [...confirmedPartyIds.value];
    }
}

async function saveLendingSettings(): Promise<void> {
    if (busy.value) return;
    await mutate('/api/v1/me/underground/lending', { is_lendable: lendingEnabled.value }, requestId(), 'PUT');
}

watch(() => currentBattle.value?.id, async (battleId) => {
    if (!battleId || battleId === lastScrolledBattleId.value) return;
    lastScrolledBattleId.value = battleId;
    await nextTick();
    document.getElementById('underground-battle-start')?.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
});

async function runPlaytest(): Promise<void> {
    if (busy.value || !selectedBuild.value || !selectedEnemy.value) return;
    innRested.value = false;
    busy.value = true;
    error.value = '';
    try {
        selectedBattle.value = await api<Battle>('/api/v1/me/underground/playtest', {
            method: 'POST',
            body: JSON.stringify({
                request_id: requestId(),
                build_key: selectedBuild.value,
                enemy_key: selectedEnemy.value,
            }),
        });
        await loadBattles();
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '力試しを開始できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function runExplore(huntingGroundKey: string, intentKey = 'selected-ground'): Promise<void> {
    if (busy.value) return;
    innRested.value = false;
    const currentPending = pendingExplorationRequest.value;
    if (state.value?.skill_rebuild_required && !currentPending) {
        error.value = 'SPを全返還しました。技を選び直し、装備枠を保存してください。';
        equipmentView.value = 'skills';
        return;
    }
    // Keep the whole intent (including borrowed IDs) until the server result is
    // recovered. A party change while a response is in flight must not mutate
    // the payload that a retry reuses.
    const pending = currentPending ?? {
        requestId: requestId(),
        huntingGroundKey,
        intentKey,
        borrowedSecretaryIds: [...confirmedPartyIds.value],
    };
    pendingExplorationRequest.value = pending;
    busy.value = true;
    error.value = '';
    try {
        const battle = await api<Battle>('/api/v1/me/underground/explore', {
            method: 'POST',
            body: JSON.stringify({
                request_id: pending.requestId,
                hunting_ground_key: pending.huntingGroundKey,
                borrowed_secretary_ids: [...pending.borrowedSecretaryIds],
            }),
        });
        showDailyQuestCompletion(battle.daily_quest);
        await refresh(false);
        selectedBattle.value = battle;
        pendingExplorationRequest.value = null;
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 409) await refresh(false);
        if (shouldReleasePendingExplorationRequest(caught)) pendingExplorationRequest.value = null;
        error.value = caught instanceof Error ? caught.message : '周囲を探索できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function runSelectedExploration(): Promise<void> {
    const groundKey = selectedHuntingGround.value?.key;
    if (!groundKey) return;
    await runExplore(groundKey);
}

async function runSkip(contentType: 'hunting_ground' | 'trial', contentKey: string, executionCount: number): Promise<void> {
    if (busy.value) return;
    if (!Number.isInteger(executionCount) || executionCount < 1 || executionCount > maximumBulkSkipExecutions) return;
    const fingerprint = `${contentType}:${contentKey}:${executionCount}`;
    const currentPending = pendingSkipRequest.value;
    lastSkipResult.value = null;
    skipError.value = '';
    if (currentPending && currentPending.fingerprint !== fingerprint) {
        skipError.value = '結果が不明なskipを同じ内容で再試行してから、別のskipを開始してください。';
        return;
    }
    const pending = currentPending ?? { fingerprint, requestId: requestId(), contentType, contentKey, executionCount };
    pendingSkipRequest.value = pending;
    await executePendingSkip(pending);
}

async function retryPendingSkip(): Promise<void> {
    const pending = pendingSkipRequest.value;
    if (busy.value || pending === null) return;
    lastSkipResult.value = null;
    skipError.value = '';
    await executePendingSkip(pending);
}

async function executePendingSkip(pending: PendingSkipRequest): Promise<void> {
    busy.value = true;
    error.value = '';
    try {
        const path = pending.contentType === 'hunting_ground'
            ? '/api/v1/me/underground/skip/hunting-ground'
            : '/api/v1/me/underground/skip/trial';
        const key = pending.contentType === 'hunting_ground'
            ? { hunting_ground_key: pending.contentKey }
            : { trial_key: pending.contentKey };
        const result = await api<SkipResult>(path, {
            method: 'POST',
            body: JSON.stringify({ request_id: pending.requestId, execution_count: pending.executionCount, ...key }),
        });
        showDailyQuestCompletion(result.daily_quest);
        lastSkipResult.value = result;
        pendingSkipRequest.value = null;
        const confirmedTicketBalance = result.rewards?.ticket_balance_after;
        if (state.value?.lending && typeof confirmedTicketBalance === 'number') {
            state.value.lending.ticket_balance = confirmedTicketBalance;
        }
        try {
            await refresh(false);
        } catch {
            skipError.value = 'skipは完了しましたが、最新状態を取得できませんでした。表示中の残高は確定済みの結果です。';
        }
    } catch (caught) {
        const message = caught instanceof Error ? caught.message : 'skipを実行できませんでした。';
        if (caught instanceof ApiError && caught.status === 409) {
            pendingSkipRequest.value = null;
            try {
                await refresh(false);
            } catch {
                // Keep the original settlement failure visible. A refresh error
                // must not hide the reason why the retry-safe request failed.
            }
        }
        skipError.value = message;
    } finally {
        busy.value = false;
    }
}

function skipDisabled(progress: SkipProgress, contentLocked = false): boolean {
    return busy.value
        || contentLocked
        || Boolean(state.value?.trial?.active_run)
        || !progress.unlocked
        || (skipTicketBalance.value ?? 0) < progress.ticket_cost;
}

function maximumSkipExecutions(progress: SkipProgress): number {
    return Math.min(
        maximumBulkSkipExecutions,
        Math.floor((skipTicketBalance.value ?? 0) / progress.ticket_cost),
    );
}

function shortcutSkipExecutions(progress: SkipProgress, fraction: 0.5 | 1): number {
    return Math.floor(maximumSkipExecutions(progress) * fraction);
}

function maximumHuntingGroundSkipExecutions(ground: HuntingGround): number {
    const keyLimit = ground.entry_key_cost > 0
        ? Math.floor(ground.key_balance / ground.entry_key_cost)
        : maximumBulkSkipExecutions;

    return Math.min(maximumSkipExecutions(ground.skip), keyLimit);
}

function shortcutHuntingGroundSkipExecutions(ground: HuntingGround, fraction: 0.5 | 1): number {
    return Math.floor(maximumHuntingGroundSkipExecutions(ground) * fraction);
}

function customSkipExecutions(value: string, maximum: number): number | null {
    if (!/^\d+$/.test(value)) return null;
    const count = Number(value);

    return Number.isSafeInteger(count) && count >= 1 && count <= maximum ? count : null;
}

function plannedSkipCost(value: string, maximum: number, unitCost: number): number {
    return (customSkipExecutions(value, maximum) ?? 0) * unitCost;
}

async function runCustomHuntingGroundSkip(ground: HuntingGround): Promise<void> {
    const count = customSkipExecutions(customHuntingGroundSkipCount.value, maximumHuntingGroundSkipExecutions(ground));
    if (count === null) return;
    await runSkip('hunting_ground', ground.key, count);
}

async function runCustomTrialSkip(trial: TrialOption): Promise<void> {
    const count = customSkipExecutions(customTrialSkipCount.value, maximumSkipExecutions(trial.skip));
    if (count === null) return;
    await runSkip('trial', trial.key, count);
}

function skipIntentBlocked(
    contentType: 'hunting_ground' | 'trial',
    contentKey: string,
    executionCount: number,
): boolean {
    const pending = pendingSkipRequest.value;
    return pending !== null && pending.fingerprint !== `${contentType}:${contentKey}:${executionCount}`;
}

function skipDrops(result: SkipResult): SkipDrop[] {
    return Array.isArray(result.rewards?.drops) ? result.rewards.drops : [];
}

function skipDropText(drop: SkipDrop): string {
    const name = drop.item?.name ?? '装備drop';
    const quantity = drop.quantity ?? drop.count ?? (drop.item ? 1 : null);
    const quantityText = quantity !== null && quantity !== undefined ? ` ×${quantity}` : '';
    if (drop.status === 'granted') return `獲得: ${name}${quantityText}`;
    if (drop.status === 'vault_full') return `取り逃し: ${name}${quantityText}（宝物庫が満杯）`;
    if (drop.status === 'none') return '装備drop: なし';
    if (drop.status === 'ineligible') return `装備drop: ${name}${quantityText}（対象外）`;

    return `装備drop: ${name}${quantityText}（${drop.status}）`;
}

function skipContentLabel(result: SkipResult): string {
    if (result.content_type === 'hunting_ground') {
        return state.value?.hunting_grounds?.find((ground) => ground.key === result.content_key)?.name ?? result.content_key;
    }

    return trialOptions.value.find((trial) => trial.key === result.content_key)?.label ?? result.content_key;
}

function skipRemainingTickets(result: SkipResult): number {
    return result.rewards?.ticket_balance_after
        ?? result.ticket_balance
        ?? result.remaining_ticket_balance
        ?? skipTicketBalance.value
        ?? 0;
}

function showDailyQuestCompletion(quest: DailyQuestProgress | undefined): void {
    if (quest?.completed_now !== true) return;
    emit('dailyQuest', quest);
}

async function repeatCurrentExploration(): Promise<void> {
    const battle = currentBattle.value;
    const groundKey = repeatableExplorationGroundKey.value;
    if (!battle || !groundKey) return;
    selectHuntingGround(groundKey);
    await runExplore(groundKey, `repeat-battle:${battle.id}`);
}

async function runTrial(trialKey?: string): Promise<void> {
    if (state.value?.skill_rebuild_required && !pendingTrialRequest.value) {
        error.value = 'SPを全返還しました。技を選び直し、装備枠を保存してください。';
        equipmentView.value = 'skills';
        return;
    }
    if (busy.value || !state.value?.trial) return;
    innRested.value = false;
    busy.value = true;
    error.value = '';
    try {
        let pending = pendingTrialRequest.value;
        if (!pending) {
            let run = state.value.trial.active_run;
            if (!run) {
                if (!trialKey) return;
                run = await api<TrialRun>('/api/v1/me/underground/trial/start', {
                    method: 'POST',
                    body: JSON.stringify({ trial_key: trialKey }),
                });
            }
            pending = { requestId: requestId(), runKey: run.run_key };
            pendingTrialRequest.value = pending;
        }
        const battle = await api<Battle>('/api/v1/me/underground/trial/fight', {
            method: 'POST',
            body: JSON.stringify({ run_key: pending.runKey, request_id: pending.requestId }),
        });
        showDailyQuestCompletion(battle.daily_quest);
        await refresh(false);
        selectedBattle.value = battle;
        pendingTrialRequest.value = null;
    } catch (caught) {
        await refresh(false);
        if (caught instanceof ApiError && [
            'underground_trial_run_stale',
            'underground_request_conflict',
        ].includes(caught.code ?? '')) {
            pendingTrialRequest.value = null;
        }
        error.value = caught instanceof Error ? caught.message : '封印の地へ入れませんでした。';
    } finally {
        busy.value = false;
    }
}

async function withdrawTrial(): Promise<void> {
    const run = state.value?.trial?.active_run;
    if (busy.value || !run) return;
    busy.value = true;
    error.value = '';
    try {
        await api<TrialRun>('/api/v1/me/underground/trial/withdraw', {
            method: 'POST',
            body: JSON.stringify({ run_key: run.run_key }),
        });
        pendingTrialRequest.value = null;
        await refresh(false);
    } catch (caught) {
        await refresh(false);
        error.value = caught instanceof Error ? caught.message : '封印の地から帰還できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function restAtInn(): Promise<void> {
    const innRequestId = pendingInnRequestId.value ?? requestId();
    pendingInnRequestId.value = innRequestId;
    innRested.value = false;
    innResting.value = true;
    try {
        if (await mutate('/api/v1/me/underground/inn/rest', {}, innRequestId)) {
            pendingInnRequestId.value = null;
            innRested.value = true;
        }
    } finally {
        innResting.value = false;
    }
}

async function runBankAction(action: 'deposit' | 'withdraw' | 'deposit_all' | 'withdraw_all'): Promise<void> {
    const body: Record<string, unknown> = { action };
    if (action === 'deposit' || action === 'withdraw') body.amount = Number(bankAmount.value);
    const fingerprint = JSON.stringify(body);
    const pending = pendingBankMutation.value?.fingerprint === fingerprint
        ? pendingBankMutation.value
        : { fingerprint, requestId: requestId() };
    pendingBankMutation.value = pending;
    if (await mutate('/api/v1/me/underground/bank/transfer', body, pending.requestId)) {
        pendingBankMutation.value = null;
    }
}

function maximumStpDraft(stat: StatKey): number {
    return Math.min(2_147_483_647, stpDraft.value[stat] + stpDraftRemaining.value);
}

function setStpShare(stat: StatKey, share: 0.5 | 1): void {
    stpDraft.value = { ...stpDraft.value, [stat]: Math.floor(maximumStpDraft(stat) * share) };
}

function setStpDraft(stat: StatKey, event: Event): void {
    const input = event.currentTarget;
    if (!(input instanceof HTMLInputElement)) return;
    const current = stpDraft.value[stat];
    const parsed = input.value === '' ? 0 : Number(input.value);
    if (!Number.isSafeInteger(parsed) || parsed < 0) {
        input.value = String(current);
        return;
    }
    const next = Math.min(parsed, maximumStpDraft(stat));
    stpDraft.value = { ...stpDraft.value, [stat]: next };
    if (next !== parsed) input.value = String(next);
}

async function confirmStp(): Promise<void> {
    const allocations = Object.fromEntries(
        Object.entries(stpDraft.value).filter(([, value]) => value > 0),
    );
    if (Object.keys(allocations).length === 0) return;
    const fingerprint = JSON.stringify(allocations);
    const pending = pendingStpMutation.value?.fingerprint === fingerprint
        ? pendingStpMutation.value
        : { fingerprint, requestId: requestId() };
    pendingStpMutation.value = pending;
    if (await mutate('/api/v1/me/underground/status/stp', { allocations }, pending.requestId)) {
        stpDraft.value = { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 };
        pendingStpMutation.value = null;
    }
}

async function acquireSkill(nodeKey: string): Promise<void> {
    const pending = pendingSkillAcquire.value?.fingerprint === nodeKey
        ? pendingSkillAcquire.value
        : { fingerprint: nodeKey, requestId: requestId() };
    pendingSkillAcquire.value = pending;
    if (await mutate('/api/v1/me/underground/skills/acquire', { node_key: nodeKey }, pending.requestId)) {
        pendingSkillAcquire.value = null;
    }
}

async function saveLoadout(): Promise<void> {
    const fingerprint = JSON.stringify(loadoutDraft.value);
    const pending = pendingLoadoutMutation.value?.fingerprint === fingerprint
        ? pendingLoadoutMutation.value
        : { fingerprint, requestId: requestId() };
    pendingLoadoutMutation.value = pending;
    if (await mutate('/api/v1/me/underground/skills/loadout', { slots: loadoutDraft.value }, pending.requestId, 'PUT')) {
        pendingLoadoutMutation.value = null;
    }
}

async function saveAwakeningMessage(): Promise<void> {
    const fingerprint = JSON.stringify({ message: awakeningMessageDraft.value });
    const pending = pendingAwakeningMessageMutation.value?.fingerprint === fingerprint
        ? pendingAwakeningMessageMutation.value
        : { fingerprint, requestId: requestId() };
    pendingAwakeningMessageMutation.value = pending;
    if (await mutate(
        '/api/v1/me/underground/awakening/message',
        { message: awakeningMessageDraft.value },
        pending.requestId,
        'PUT',
    )) {
        pendingAwakeningMessageMutation.value = null;
        const awakening = state.value?.awakening;
        if (awakening) {
            awakeningMessageDraft.value = awakening.custom_message ?? awakening.default_message;
        }
    }
}

async function saveAwakeningTechnique(): Promise<void> {
    if (!awakeningTechniqueDraft.value) return;
    const fingerprint = awakeningTechniqueDraft.value;
    const pending = pendingAwakeningTechniqueMutation.value?.fingerprint === fingerprint
        ? pendingAwakeningTechniqueMutation.value
        : { fingerprint, requestId: requestId() };
    pendingAwakeningTechniqueMutation.value = pending;
    if (await mutate(
        '/api/v1/me/underground/awakening/technique',
        { technique_key: awakeningTechniqueDraft.value },
        pending.requestId,
        'PUT',
    )) {
        pendingAwakeningTechniqueMutation.value = null;
        awakeningTechniqueDraft.value = state.value?.awakening?.selected_technique_key ?? null;
    }
}

function loadoutChoiceDisabled(skillKey: string, slotIndex: number): boolean {
    return loadoutDraft.value.some((equipped, index) => index !== slotIndex && equipped === skillKey);
}

function weaponStyleLabel(style: string): string {
    return weaponStyleLabels[style] ?? style;
}

function requiredWeaponText(styles: string[]): string {
    return `必要武器: ${styles.map(weaponStyleLabel).join(' / ')}`;
}

function activeSkillWeaponIncompatible(skill: Pick<ActiveSkill, 'required_weapon_styles'>): boolean {
    const current = currentWeaponStyle.value;
    return current !== null
        && skill.required_weapon_styles.length > 0
        && !skill.required_weapon_styles.includes(current);
}

async function focusActiveLoadout(): Promise<void> {
    await nextTick();
    const heading = document.getElementById('underground-loadout-title');
    if (!heading) return;
    heading.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
    heading.focus({ preventScroll: true });
}

async function enter(): Promise<void> {
    try {
        await refresh(false);
        if (state.value?.stage === 'not_started' || state.value?.stage === 'returned_after_tutorial') {
            await mutate('/api/v1/me/underground/entry');
        }
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '地下へ入れませんでした。';
    }
}

function battleResultLabel(result: Battle['result']): string {
    return result === 'victory' ? '勝利' : result === 'defeat' ? '敗北' : result === 'stalemate' ? '決着なし' : '撤退';
}

function battleRoundCount(battle: Battle): number {
    return battle.rounds_count ?? (typeof battle.rounds === 'number' ? battle.rounds : battle.rounds?.length ?? 0);
}

function structuredRounds(battle: Battle): CombatRound[] {
    if (Array.isArray(battle.rounds)) return battle.rounds;
    if (Array.isArray(battle.actions) && battle.actions[0] && 'actions' in battle.actions[0]) {
        return battle.actions as CombatRound[];
    }
    return [];
}

function summaryLabel(key: string): string {
    return summaryLabels[key] ?? key;
}

function summaryValue(key: string, value: boolean | number | string): boolean | number | string {
    return key === 'result' ? battleResultLabel(String(value) as Battle['result']) : value;
}

function visibleSummary(summary: Record<string, unknown>): Record<string, boolean | number | string> {
    const entries = Object.entries(summary).filter(([key, value]) => !hiddenSummaryKeys.has(key)
        && (typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string'));

    return Object.fromEntries(entries) as Record<string, boolean | number | string>;
}

function simpleActions(battle: Battle): SimpleAction[] {
    if (!Array.isArray(battle.actions) || (battle.actions[0] && 'actions' in battle.actions[0])) return [];
    return battle.actions as SimpleAction[];
}

function simpleRoundNumbers(battle: Battle): number[] {
    return [...new Set(simpleActions(battle).map((action) => action.round))];
}

function playerDisplayName(battle: Battle): string {
    return battle.player_display_name ?? state.value?.secretary_name ?? '秘書';
}

function imageUrl(reference: ImageReference | string | null | undefined): string | null {
    if (typeof reference === 'string') return reference;

    return reference?.url ?? null;
}

function battlePlayerImageUrl(battle: Battle, awakened = false): string | null {
    const references = battle.player_image_references;
    if (!references) return null;

    return awakened
        ? imageUrl(references.awakening) ?? imageUrl(references.normal)
        : imageUrl(references.normal);
}

function roundHasAwakening(round: CombatRound): boolean {
    return round.actions.some((action) => action.type === 'awakening'
        || action.type === 'awakening_technique'
        || action.kind === 'awakening'
        || action.kind === 'awakening_technique');
}

function roundStartLabel(round: CombatRound): string {
    return round.round === 1 || round.state_timing === 'battle_start'
        ? `第${round.round}ラウンド 開始時`
        : `第${round.round}ラウンド（前ラウンド終了時）`;
}

function actorName(side: string, battle: Battle): string {
    return side === '秘書' || side === 'player'
        ? playerDisplayName(battle)
        : side === '対戦相手' || side === 'enemy'
            ? battle.encounter_name
            : '戦闘';
}

function targetName(side: string, battle: Battle): string {
    return side === '秘書' || side === 'player'
        ? battle.encounter_name
        : playerDisplayName(battle);
}

const fallbackActionLabels: Record<string, string> = {
    normal_attack: '通常攻撃',
    defend: '防御',
    counter: '反撃',
    self_regeneration: '自己再生',
    lifesteal: '吸血',
    complete_guard: '完全防御',
    taunt: '挑発',
    awakening: '覚醒',
    decisive_heavenrend: '天断一閃',
    absolute_aegis: '絶対護界',
    life_requiem: '生命讃歌',
    limitless_reprise: '無窮再演',
    shura_bloodline: '修羅の血脈',
    fortress_strike: '城塞撃',
    judgment_light: '裁きの天光',
    formless_strike: '無相の一撃',
};

function actionKey(action: RoundAction): string | null {
    const key = action.action_key ?? action.action;

    return typeof key === 'string' && key !== '' ? key : null;
}

function actionLabel(action: RoundAction): string {
    const key = actionKey(action);
    const raw = action.skill_name ?? action.skill_label ?? action.action_label ?? action.label;
    const normalized = typeof raw === 'string' ? raw.trim() : '';
    const isInternal = normalized === ''
        || normalized === 'action'
        || normalized === 'decision'
        || (key !== null && normalized === key);

    return isInternal
        ? (key !== null ? fallbackActionLabels[key] ?? 'スキル' : '戦闘行動')
        : normalized;
}

function battlePartyMembers(battle: Battle): PartyBattleMember[] {
    const party = battle.party;

    return party ? [...party.members, ...(party.enemies ?? [])] : [];
}

function battleActorNameById(battle: Battle, combatantId: string): string | null {
    const actor = battlePartyMembers(battle).find((member) => member.combatant_id === combatantId);

    return actor?.display_name ?? null;
}

function actionActorName(action: RoundAction, battle: Battle): string {
    return (action.actor_id ? battleActorNameById(battle, action.actor_id) : null)
        ?? action.actor_name
        ?? actorName(action.side, battle);
}

function actionTargetNames(action: RoundAction, battle: Battle): string[] {
    const ids = action.target_ids?.filter((id) => id !== '')
        ?? (action.target_id ? [action.target_id] : []);
    const byId = ids.map((id) => battleActorNameById(battle, id) ?? id);
    const explicit = action.target_names?.filter((name) => name !== '') ?? [];
    const fallback = action.target_name ? [action.target_name] : [];
    const names = byId.length > 0 ? byId : explicit.length > 0 ? explicit : fallback;

    return [...new Set(names)];
}

function actionTargetsText(action: RoundAction, battle: Battle): string {
    const names = actionTargetNames(action, battle);

    return names.length > 0 ? names.join('、') : targetName(action.side, battle);
}

function actionNarrative(action: RoundAction, battle: Battle): string {
    const actor = actionActorName(action, battle);
    const target = actionTargetsText(action, battle);
    const amount = action.amount ?? 0;
    const revival = action.revived === true || action.kind === 'revival' || action.type === 'revival';
    if (revival) {
        const hp = action.revive_hp_bps !== undefined && action.revive_hp_bps !== null
            ? `（HP ${Math.round(action.revive_hp_bps / 100)}%）`
            : '';
        return `${actor}の「${actionLabel(action)}」。${target}が復活した！${hp}`;
    }
    if (action.lines && action.lines.length > 0) return action.lines.join('\n');
    if (action.type === 'warning' || action.type === 'phase_transition') return action.label;
    if (action.type === 'action' || action.type === 'decision' || action.kind === 'decision') return `${actor}は「${actionLabel(action)}」を使用した。`;
    if (action.type === 'mp_cost') return `${actor}はMPを${amount}消費した。`;
    if (action.type === 'mp_recovery') return `${actor}はMPを${amount}回復した。`;
    if (action.type === 'counter') return `${actor}の反撃。${target}に${amount}ダメージ。`;
    if (action.type === 'guard') return `${actor}は防御態勢を取った。`;
    if (action.type === 'barrier') {
        const recipient = actionTargetNames(action, battle).join('、') || actor;
        return recipient === actor
            ? `${actor}は「${actionLabel(action)}」で障壁を${amount}得た。`
            : `${actor}の「${actionLabel(action)}」で${recipient}は障壁を${amount}得た。`;
    }
    if (action.type === 'recovery') return `${actor}は「${actionLabel(action)}」で${target}のHPを${amount}回復した。`;
    if (action.type === 'role_stack_gain' || action.type === 'role_stack_spent') {
        const role = withoutActionPrefix(actionLabel(action), action.type === 'role_stack_gain' ? '増加:' : '消費:');
        return `${actor}の${role}が${amount}${action.type === 'role_stack_gain' ? '増加' : '消費'}した。`;
    }
    if (action.type === 'status_applied') return `${target}に${withoutActionPrefix(actionLabel(action), '付与:')}が付与された。`;
    if (action.type === 'status_expired') return `${actor}の${withoutActionPrefix(actionLabel(action), '消滅:')}が消滅した。`;
    if (action.type === 'status_resisted') return `${target}は${withoutActionPrefix(actionLabel(action), '抵抗:')}を防いだ。`;
    if (action.type === 'status_removed') return `${actor}は状態効果を${amount}個解除した。`;
    if (action.type === 'damage') {
        const qualifiers = [action.critical ? '会心' : '', action.guarded ? '防御' : '', action.parried ? '受け流し' : '']
            .filter(Boolean).join('・');
        if (action.complete_guarded) return `${actor}の「${actionLabel(action)}」。${target}は完全防御し、HPダメージは0。`;
        if (action.evaded) return `${actor}の「${actionLabel(action)}」。${target}は回避した。`;
        const damage = amount > 0 ? `${target}に${amount}ダメージ。` : `${target}のHPダメージは0。`;
        const barrier = action.barrier_absorbed ? `障壁が${action.barrier_absorbed}吸収。` : '';
        return `${actor}の「${actionLabel(action)}」。${qualifiers ? `${qualifiers}。` : ''}${damage}${barrier}`;
    }
    return `${actor}に「${actionLabel(action)}」の効果。`;
}

function withoutActionPrefix(label: string, prefix: string): string {
    return label.startsWith(prefix) ? label.slice(prefix.length).trimStart() : label;
}

function actionTone(action: RoundAction): string {
    if (action.type === 'warning' || action.type === 'phase_transition') return 'is-warning';
    if (action.type === 'awakening' || action.type === 'awakening_technique') return 'is-awakening';
    if (action.kind === 'revival' || action.type === 'revival' || action.revived === true) return 'is-recovery';
    if (action.type === 'mp_recovery') return 'is-support';
    if (action.type === 'recovery' || action.type === 'barrier') return 'is-recovery';
    if (action.critical) return 'is-critical';
    if (action.evaded || action.parried || action.complete_guarded || action.type === 'guard') return 'is-defense';
    if (action.type === 'damage' || action.type === 'counter') return 'is-impact';
    return 'is-neutral';
}

// Only coalesce an adjacent declaration/cost/role-stack/result with matching actor and label.
// Counterattacks, ongoing effects and ambiguous historical rows retain source order.
function actionGroups(actions: RoundAction[]): Array<{ action: RoundAction; source: RoundAction[]; cost: number | null }> {
    const groups: Array<{ action: RoundAction; source: RoundAction[]; cost: number | null }> = [];
    const sameActor = (left: RoundAction, right: RoundAction): boolean => {
        if (left.action_id && right.action_id) return left.action_id === right.action_id;
        if (left.actor_id && right.actor_id) return left.actor_id === right.actor_id;

        return left.side === right.side && left.actor_name === right.actor_name;
    };
    const sameLabel = (left: RoundAction, right: RoundAction): boolean => actionLabel(left) === actionLabel(right);
    for (let index = 0; index < actions.length; index++) {
        const declaration = actions[index]!;
        const cost = actions[index + 1];
        let effectIndex = index + 2;
        while (actions[effectIndex] && ['role_stack_gain', 'role_stack_spent'].includes(actions[effectIndex]!.type)
            && sameActor(actions[effectIndex]!, declaration)) effectIndex++;
        const effect = actions[effectIndex];
        if ((declaration.type === 'action' || declaration.kind === 'decision')
            && cost?.type === 'mp_cost' && cost.amount !== undefined
            && sameActor(cost, declaration)
            && effect && ['damage', 'recovery', 'barrier'].includes(effect.type)
            && sameActor(effect, declaration)
            && sameLabel(effect, declaration)) {
            groups.push({ action: effect, source: actions.slice(index, effectIndex + 1), cost: cost.amount });
            index = effectIndex;
        } else {
            groups.push({ action: declaration, source: [declaration], cost: null });
        }
    }
    return groups;
}

function actionHighlight(action: RoundAction): string | null {
    if (action.type === 'awakening' || action.type === 'awakening_technique') return '覚醒';
    if (action.kind === 'revival' || action.type === 'revival' || action.revived === true) return '蘇生';
    if (action.critical) return '会心';
    if (action.type === 'mp_recovery') return 'MP回復';
    if (action.type === 'recovery') return '回復';
    if (action.complete_guarded) return '完全防御';
    if (action.evaded) return '回避';
    if (action.parried) return '受け流し';
    if (action.type === 'warning' || action.type === 'phase_transition') return '警戒';
    return null;
}

function isImportantAction(action: RoundAction): boolean {
    return action.important === true || action.type === 'awakening' || action.type === 'awakening_technique'
        || action.kind === 'revival' || action.type === 'revival' || action.revived === true
        || action.type === 'warning' || action.type === 'phase_transition'
        || action.type === 'damage' && Boolean(action.critical)
        || action.type === 'victory' || action.type === 'defeat';
}

function simpleActionNarrative(action: SimpleAction, battle: Battle): string {
    const actor = action.actor_name ?? actorName(action.side, battle);
    const target = action.target_name ?? targetName(action.side, battle);
    const label = action.action_label ?? '戦闘行動';
    return action.amount > 0
        ? `${actor}の「${label}」。${target}に${action.amount}ダメージ。`
        : `${actor}は「${label}」を行った。`;
}

function closeBattle(): void {
    selectedBattle.value = null;
    innRested.value = false;
}

async function applyEquipmentMutation(result: EquipmentMutationState): Promise<void> {
    if (!state.value) return;
    state.value.shard_balance = result.shard_balance;
    state.value.banked_shard_balance = result.banked_shard_balance;
    state.value.equipment_summary = result.vault;
    try {
        await refresh(false);
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '装備変更後の状態を更新できませんでした。';
    }
}

function applyAiMutation(result: unknown): void {
    state.value = result as UndergroundState;
    cooldownNowMs.value = Date.now();
}

onMounted(() => {
    try {
        const saved = window.localStorage.getItem(detailPreferenceKey);
        if (saved === 'true' || saved === 'false') detailVisible.value = saved === 'true';
    } catch { /* optional */ }
    cooldownTimer = window.setInterval(() => { cooldownNowMs.value = Date.now(); }, 1_000);
    void enter();
});
onUnmounted(() => {
    if (cooldownTimer !== null) window.clearInterval(cooldownTimer);
});
</script>

<template>
    <section class="panel underground-panel" :class="{ 'ug-panel': state?.stage === 'underground_open' && !currentBattle }" aria-live="polite">
        <p v-if="busy && state === null" class="status">地下の状態を確認しています。</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="state && currentBattle">
            <section id="underground-battle-start" class="underground-battle-log" aria-label="戦闘ログ">
                <p v-if="currentBattle.challenge_intro" class="underground-trial-intro">{{ currentBattle.challenge_intro }}</p>
                <header class="underground-battle-opening">
                    <p class="eyebrow">遭遇</p>
                    <h1>{{ currentBattle.encounter_name }}</h1>
                    <p v-if="currentBattle.build_name">{{ currentBattle.build_name }}で戦闘を開始した。</p>
                    <p v-else>{{ currentPlayerDisplayName }}は戦闘を開始した。</p>
                    <button type="button" class="underground-battle-detail-toggle" :aria-pressed="detailVisible" @click="toggleBattleDetails">
                        戦闘詳細を{{ detailVisible ? '隠す' : '表示' }}
                    </button>
                    <a class="underground-log-jump" href="#underground-battle-result">末尾へ</a>
                </header>

                <UndergroundPartyBattleCards
                    v-if="currentPartyActors.length > 0"
                    :actors="currentPartyActors"
                    :state-by-id="partyStateMap(currentBattle.initial_state)"
                    :portrait-events="currentBattle.portrait_events"
                    portrait-event-type="start"
                />

                <section
                    v-if="currentPartyActors.length === 0 && soloState(currentBattle.initial_state, 'player') && soloState(currentBattle.initial_state, 'enemy')"
                    class="underground-round-start underground-solo-battle-start"
                    aria-label="戦闘開始時の状態"
                >
                    <div class="underground-matchup-grid">
                        <UndergroundCombatantCard
                            :name="currentPlayerDisplayName"
                            side="player"
                            :state="soloState(currentBattle.initial_state, 'player')!"
                            :image-url="battlePlayerImageUrl(currentBattle)"
                        />
                        <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                        <UndergroundCombatantCard
                            :name="currentBattle.encounter_name"
                            side="enemy"
                            :state="soloState(currentBattle.initial_state, 'enemy')!"
                        />
                    </div>
                </section>

                <div class="underground-rounds">
                    <p v-if="currentBattle.detail_message" class="status">{{ currentBattle.detail_message }}</p>
                    <article v-for="round in currentStructuredRounds" :key="round.round" class="underground-round">
                        <h2>{{ roundStartLabel(round) }}</h2>
                        <UndergroundPartyBattleCards
                            v-if="currentPartyActors.length > 0"
                            :actors="currentPartyActors"
                            :portrait-events="currentBattle.portrait_events"
                            :state-by-id="partyStateMap(round.start_state)"
                            portrait-event-type="awakening"
                            :portrait-round="round.round"
                        />
                        <section v-if="soloState(round.start_state, 'player') && soloState(round.start_state, 'enemy') && currentPartyActors.length === 0" class="underground-round-start" :aria-label="`第${round.round}ラウンド開始時の状態`">
                            <div class="underground-matchup-grid">
                                <UndergroundCombatantCard
                                    :name="currentPlayerDisplayName"
                                    side="player"
                                    :state="soloState(round.start_state, 'player')!"
                                    compact
                                />
                                <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                                <UndergroundCombatantCard :name="currentBattle.encounter_name" side="enemy" :state="soloState(round.start_state, 'enemy')!" compact />
                            </div>
                        </section>
                        <section
                            v-if="currentPartyActors.length === 0 && roundHasAwakening(round) && (soloState(round.end_state, 'player') ?? soloState(round.start_state, 'player'))"
                            class="underground-solo-awakening-art"
                            aria-label="覚醒時の状態"
                        >
                            <UndergroundCombatantCard
                                :name="currentPlayerDisplayName"
                                side="player"
                                :state="(soloState(round.end_state, 'player') ?? soloState(round.start_state, 'player'))!"
                                :image-url="battlePlayerImageUrl(currentBattle, true)"
                            />
                        </section>
                        <h3 class="underground-round-action-heading">第{{ round.round }}ラウンド 行動</h3>
                        <ul class="underground-action-log" :class="{ 'is-detail-hidden': !detailVisible }">
                            <li
                                v-for="(group, index) in actionGroups(round.actions)"
                                v-show="detailVisible || isImportantAction(group.action)"
                                :key="index"
                                :class="actionTone(group.action)"
                                :data-action-type="group.action.type"
                            >
                                <span class="underground-action-marker" aria-hidden="true" />
                                <div>
                                    <span v-if="actionHighlight(group.action)" class="underground-event-badge">{{ actionHighlight(group.action) }}</span>
                                    <small v-if="group.action.agility_combo_hits" class="underground-agility-combo">{{ group.action.agility_combo_hits }}連続ヒット！</small>
                                    <span v-if="group.cost !== null" class="underground-action-cost">MP −{{ group.cost.toLocaleString() }}</span>
                                    <p v-for="(supplement, supplementIndex) in group.source.slice(2, -1)" :key="supplementIndex" class="underground-action-supplement">{{ actionNarrative(supplement, currentBattle) }}</p>
                                    <p>{{ actionNarrative(group.action, currentBattle) }}</p>
                                    <details v-if="group.source.length > 1" class="underground-action-details">
                                        <summary>行動の全詳細</summary>
                                        <p v-for="(source, sourceIndex) in group.source" :key="sourceIndex">{{ actionNarrative(source, currentBattle) }}</p>
                                    </details>
                                </div>
                            </li>
                        </ul>
                        <details v-if="!round.start_state && soloState(round.end_state, 'player') && soloState(round.end_state, 'enemy') && currentPartyActors.length === 0" class="underground-round-state">
                            <summary>ラウンド{{ round.round }}終了時の状態</summary>
                            <div class="underground-matchup-grid">
                                <UndergroundCombatantCard :name="currentPlayerDisplayName" side="player" :state="soloState(round.end_state, 'player')!" compact />
                                <UndergroundCombatantCard :name="currentBattle.encounter_name" side="enemy" :state="soloState(round.end_state, 'enemy')!" compact />
                            </div>
                        </details>
                    </article>

                    <article v-for="roundNumber in simpleRoundNumbers(currentBattle)" :key="`simple-${roundNumber}`" class="underground-round">
                        <h2>Round {{ roundNumber }}</h2>
                        <ol class="underground-action-log">
                            <li v-for="(action, index) in simpleActions(currentBattle).filter((item) => item.round === roundNumber)" :key="index">
                                <span class="underground-action-marker" aria-hidden="true" />
                                <div><p>{{ simpleActionNarrative(action, currentBattle) }}</p></div>
                            </li>
                        </ol>
                    </article>
                </div>

                <footer id="underground-battle-result" class="underground-battle-result">
                    <p class="eyebrow">戦闘終了</p>
                    <h2>{{ battleResultLabel(currentBattle.result) }}</h2>
                    <UndergroundPartyBattleCards
                        v-if="currentPartyActors.length > 0"
                        :actors="currentPartyActors"
                        :state-by-id="partyStateMap(finalBattleState)"
                        :portrait-events="currentBattle.portrait_events"
                        portrait-event-type="final"
                    />
                    <section v-if="soloState(finalBattleState, 'player') && soloState(finalBattleState, 'enemy') && currentPartyActors.length === 0" class="underground-matchup underground-final-state" aria-labelledby="underground-final-state-title">
                        <div class="underground-matchup-heading">
                            <h3 id="underground-final-state-title">戦闘中の最終状態</h3>
                        </div>
                        <div class="underground-matchup-grid">
                            <UndergroundCombatantCard
                                :name="currentPlayerDisplayName"
                                side="player"
                                :state="soloState(finalBattleState, 'player')!"
                                :image-url="battlePlayerImageUrl(currentBattle, soloState(finalBattleState, 'player')?.awakened === true)"
                            />
                            <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                            <UndergroundCombatantCard :name="currentBattle.encounter_name" side="enemy" :state="soloState(finalBattleState, 'enemy')!" />
                        </div>
                    </section>
                    <p v-if="currentBattle.hunting_ground">狩場: {{ currentBattle.hunting_ground.name }}</p>
                    <p>{{ battleRoundCount(currentBattle) }}ラウンドで決着。</p>
                    <p>経験値 +{{ currentBattle.xp_awarded }}・輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}G<span v-if="currentBattle.context === 'playtest'">・ドロップなし</span></p>
                    <p v-if="currentBattle.treasure?.found" class="underground-equipment-drop" role="status">財宝を見つけた！ ×{{ currentBattle.treasure.multiplier }}</p>
                    <p v-if="(currentBattle.shining_kingdom_key?.awarded ?? 0) > 0" class="underground-equipment-drop" role="status">輝きの王国の鍵 +{{ currentBattle.shining_kingdom_key?.awarded }}</p>
                    <p v-if="currentBattle.drop?.status === 'granted' && currentBattle.drop.item" class="underground-equipment-drop" role="status">
                        装備drop: {{ currentBattle.drop.item.rarity_label }}・Item Lv {{ currentBattle.drop.item.item_level }}・{{ currentBattle.drop.item.name }}
                        <span v-if="currentBattle.drop.item.affixes.length > 0">（{{ currentBattle.drop.item.affixes.map((affix) => affix.label).join('、') }}）</span>
                    </p>
                    <p v-if="currentBattle.drop?.status === 'vault_full' && currentBattle.drop.item" class="underground-equipment-drop lost" role="alert">
                        宝物庫が満杯のため、{{ currentBattle.drop.item.rarity_label }}・Item Lv {{ currentBattle.drop.item.item_level }}・{{ currentBattle.drop.item.name }}を持ち帰れませんでした。
                    </p>
                    <p v-if="(currentBattle.interbattle_heal_amount ?? 0) > 0" class="underground-interbattle-heal">体力が少し回復した</p>
                    <div
                        v-if="currentBattle.combat_level_after !== undefined && currentBattle.combat_level_after !== currentBattle.combat_level_before"
                        class="underground-level-up"
                        role="status"
                        aria-label="レベルアップ"
                    >
                        <strong>LEVEL UP！</strong>
                        <span>戦闘Lv {{ currentBattle.combat_level_before }} → {{ currentBattle.combat_level_after }}</span>
                        <span>未使用STP +{{ currentBattle.stp_awarded ?? 0 }}（合計 {{ currentBattle.unspent_stp_after ?? 0 }}）</span>
                    </div>
                    <details v-if="currentBattle.summary && detailVisible" class="underground-combat-details">
                        <summary>戦闘詳細</summary>
                        <dl class="underground-combat-summary">
                            <div v-for="(value, key) in visibleSummary(currentBattle.summary)" :key="key"><dt>{{ summaryLabel(key) }}</dt><dd>{{ summaryValue(key, value) }}</dd></div>
                        </dl>
                    </details>
                    <a class="underground-log-jump" href="#underground-battle-start">先頭へ</a>
                </footer>
                <section v-if="currentBattle.first_clear_story" class="underground-first-clear-story" aria-labelledby="underground-first-clear-title">
                    <h2 id="underground-first-clear-title">{{ currentBattle.first_clear_story.title }}</h2>
                    <p class="underground-first-clear-body">{{ currentBattle.first_clear_story.body }}</p>
                    <div class="underground-first-clear-results" role="status">
                        <p v-for="message in currentBattle.first_clear_story.system_messages" :key="message">{{ message }}</p>
                    </div>
                </section>
                <section v-if="currentBattle.context === 'guide_duel'" class="underground-first-clear-story" aria-label="決闘の結末">
                    <p v-for="(line, index) in currentBattle.duel_dialogue ?? []" :key="index">{{ line }}</p>
                    <p>決闘前のHP・MP・覚醒状態に戻りました。経験値・Gの増減はありません。</p>
                </section>
            </section>

            <div v-if="state.stage === 'escape_pending'" class="underground-story underground-after-battle">
                <p v-for="line in tutorialAftermath" :key="line">{{ line }}</p>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'escape_complete' })">OK</button>
            </div>
            <div v-else-if="state.stage === 'special_loss_complete'" class="underground-story underground-after-battle">
                <p v-for="line in trueNameAfter" :key="line">{{ line }}</p>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'special_loss_aftermath_complete' })">OK</button>
            </div>
            <div v-else class="underground-battle-actions">
                <button
                    v-if="canAdvanceTrial"
                    class="button primary underground-trial-next"
                    type="button"
                    :disabled="busy || exploreCooldownSeconds > 0"
                    @click="runTrial()"
                >
                    次の階層へ<small v-if="exploreCooldownSeconds > 0">あと{{ exploreCooldownSeconds }}秒</small>
                </button>
                <button
                    v-if="repeatableExplorationGroundKey"
                    class="button primary underground-exploration-repeat"
                    type="button"
                    :disabled="busy || exploreCooldownSeconds > 0"
                    @click="repeatCurrentExploration"
                >
                    もう一度ここを探索する<small v-if="exploreCooldownSeconds > 0">あと{{ exploreCooldownSeconds }}秒</small>
                </button>
                <button class="button secondary underground-battle-back" type="button" @click="closeBattle">地下メインへ戻る</button>
            </div>
        </template>

        <template v-else-if="state?.stage === 'initial_descent'">
            <div class="underground-story"><p v-for="line in initialDescent" :key="line">{{ line }}</p></div>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'initial_story_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'tutorial_ready'">
            <div class="underground-battle-preview">
                <p>鼻を突く臭い。闇の中から巨大なネズミの様な怪物が襲い掛かってきた――！</p>
                <p class="eyebrow">遭遇</p><h1>ジャイアントラット</h1>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/tutorial')">戦闘開始</button>
            </div>
        </template>

        <template v-else-if="state?.stage === 'shopkeeper_encounter'">
            <div class="underground-story"><p v-for="line in encounterStory" :key="line">{{ line }}</p></div>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'shopkeeper_encounter_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'shopkeeper_naming'">
            <form class="underground-name-form" @submit.prevent="submitName">
                <label for="underground-shopkeeper-name">案内人に名前をつけてください</label>
                <input id="underground-shopkeeper-name" v-model="shopkeeperName" required maxlength="80" autocomplete="off" placeholder="メアリー・スー" :disabled="busy">
                <button class="button primary" type="submit" :disabled="busy">決定</button>
            </form>
        </template>

        <template v-else-if="state?.stage === 'special_loss_pending'">
            <div class="underground-story"><p v-for="line in trueNameBefore" :key="line">{{ line }}</p></div>
            <section class="underground-battle-preview" aria-labelledby="rika-battle-title">
                <p class="eyebrow">遭遇</p>
                <h1 id="rika-battle-title">リカ</h1>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/scripted-loss')">戦闘開始</button>
            </section>
        </template>

        <template v-else-if="state?.stage === 'shop_explanation'">
            <div class="underground-story"><p v-for="line in shopStory" :key="line">{{ line }}</p></div>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'shop_explanation_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'contract_ready'">
            <button class="button primary underground-contract" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/contract')">契約する</button>
        </template>

        <template v-else-if="state?.stage === 'crystal_selection'">
            <div class="underground-story"><p v-for="line in crystalOffer" :key="line">{{ line }}</p></div>
            <section class="underground-growth-grid" aria-label="初期輝石選択">
                <article v-for="path in state.growth_paths ?? []" :key="path.key" class="underground-growth-card" :data-color="path.color">
                    <h2>{{ path.label }}</h2><p v-for="line in path.description" :key="line">{{ line }}</p>
                    <dl><div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ path.stats[key] }}</dd></div><div><dt>HP</dt><dd>{{ path.max_hp }}</dd></div><div><dt>MP</dt><dd>{{ path.max_mp }}</dd></div></dl>
                    <p>Lv2以降: 自然成長 {{ Object.values(path.natural_growth).reduce((sum, value) => sum + value, 0) }} / 未使用STP +{{ path.unspent_stp_per_level }}</p>
                    <button class="button primary" type="button" :disabled="busy" @click="chooseGrowthPath(path.key)">{{ path.label }}を選ぶ</button>
                </article>
            </section>
        </template>

        <template v-else-if="state?.stage === 'growth_path_selected'">
            <div class="underground-story"><p>{{ growthEnding }}</p><p v-for="line in commonEnding" :key="line">{{ line }}</p></div>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'growth_path_story_complete' })">地下へ</button>
        </template>

        <template v-else-if="state?.stage === 'underground_open'">
            <div class="ug-shell">
                <div v-if="pendingLoungeMutation && !busy" class="ug-page-content" role="status">
                    <p>前の操作の結果を確認してください。</p>
                    <button type="button" @click="loungeMutation(pendingLoungeMutation.path, pendingLoungeMutation.payload)">前の操作の結果を確認する</button>
                </div>
                <header v-if="currentDestination !== 'home'" class="ug-page-heading"><h1>{{ pageTitle }}</h1><span>{{ state.shard_balance.toLocaleString('ja-JP') }} G</span></header>
                <nav v-if="pageTabs.length && !activeLoungeEvent" class="ug-tabs" aria-label="画面内の切り替え">
                    <button v-for="tab in pageTabs" :key="tab.key" type="button" :aria-current="equipmentView === tab.key ? 'page' : undefined" @click="selectTab(tab.key)">{{ tab.label }}</button>
                </nav>
                <template v-if="activeLoungeEvent">
                    <UndergroundScene :scene="state.visuals?.scenes[activeLoungeEvent.scene]" :show-ai="state.visuals?.show_ai ?? false" />
                    <section class="ug-page-content ug-event-story" aria-label="物語">
                        <p v-for="(line, index) in activeLoungeEvent.body" :key="index">{{ line }}</p>
                        <button class="ug-primary" type="button" :disabled="busy" @click="advanceLounge">{{ loungeReplay ? '回想に戻る' : activeLoungeEvent.choice }}</button>
                    </section>
                </template>
                <template v-else>
                    <p v-if="equipmentView === 'home' && innRested" class="ug-home-rest-result" role="status">HPが全回復しました。</p>
                    <UndergroundHome
                        v-if="equipmentView === 'home'"
                        :name="state.visuals?.display_name ?? state.secretary_name" :level="state.combat_level"
                        :hp="state.current_hp ?? state.growth_path?.max_hp ?? 0" :max-hp="state.growth_path?.max_hp ?? 0"
                        :awakening="state.awakening?.current" :awakening-max="state.awakening?.maximum"
                        :shards="state.shard_balance" :banked="state.banked_shard_balance" :tickets="skipTicketBalance ?? 0"
                        :xp-remaining="state.xp_to_next_level" :growth-path="state.growth_path?.label" :unspent-stp="state.unspent_stp"
                        :scene="state.visuals?.scenes.home" :portrait="state.visuals?.portrait" :awakened-portrait="state.visuals?.awakened_portrait"
                        :icon-url="state.visuals?.icon_url"
                        :show-ai="state.visuals?.show_ai ?? false" :companions="state.rental_party ?? []"
                        :backgrounds="state.visuals?.home_backgrounds" :background-key="state.visuals?.home_background_key" :busy="busy"
                        :resting="innResting" :rest-disabled="Boolean(state.trial?.active_run)"
                        :destination="selectedHuntingGround?.name" :active-trial="state.trial?.active_run ? '挑戦中の試練' : undefined"
                        :departure-disabled="busy || exploreCooldownSeconds > 0 || state.skill_rebuild_required"
                        :departure-reason="exploreCooldownSeconds > 0 ? '次の出発まであと' + exploreCooldownSeconds + '秒' : undefined"
                        @navigate="navigate" @depart="runSelectedExploration" @continue-trial="runTrial(state.trial?.active_run?.key)"
                        @rest="restAtInn"
                        @allocate-stp="selectTab('status')"
                        @background="loungeMutation('home-background', { key: $event })"
                    />
                    <template v-else>
                        <UndergroundScene v-if="['shop', 'exchange', 'villa'].includes(currentDestination)" :scene="state.visuals?.scenes[currentDestination]" :show-ai="state.visuals?.show_ai ?? false" />
                        <div class="ug-page-content">
                            <section v-if="state.skill_rebuild_required && currentDestination === 'character'" role="status">
                                <h2>SPを全返還しました</h2><p>技と戦法を選び直し、アクティブスキルの枠を保存すると戦闘を再開できます。</p>
                            </section>
                            <div v-if="currentDestination === 'shop'" class="ug-shop-rest">
                                <p>{{ shopGreeting }}</p>
                                <button class="ug-primary" type="button" :disabled="busy || innResting || Boolean(state.trial?.active_run)" @click="restAtInn">{{ innResting ? '休憩中…' : '宿で休む（10G）' }}</button>
                                <p v-if="innRested" role="status">HPが全回復しました。</p>
                                <p v-if="state.trial?.active_run" class="ug-muted">封印の地から帰還後に利用できます。</p>
                            </div>
                            <p v-if="equipmentView === 'party'" class="ug-exchange-greeting">{{ exchangeGreeting }}</p>
                            <UndergroundEquipmentShop v-if="equipmentView === 'shop'" @updated="applyEquipmentMutation" />
            <section v-if="equipmentView === 'guide' || equipmentView === 'recollections' && state.residence?.villa_owned" class="underground-guide-room" aria-label="案内人">
                <div v-if="equipmentView === 'guide'" class="underground-guide-actions">
                    <button
                        type="button"
                        :aria-pressed="guideMode === 'conversation'"
                        @click="startGuideConversation"
                    >
                        少しお話をする
                    </button>
                    <button
                        v-if="state.recollections?.past_available && (state.recollections?.max_completed ?? 0) < 5"
                        type="button"
                        :aria-pressed="guideMode === 'recollections'"
                        @click="openRecollections"
                    >
                        案内人の過去を聞く
                    </button>
                    <button
                        v-if="state.recollections?.serious_talk || state.guide_duel?.unlocked"
                        type="button"
                        :aria-pressed="guideMode === 'serious_talk'"
                        @click="openSeriousTalk"
                    >
                        案内人に真剣な話をする
                    </button>
                    <button
                        type="button"
                        :aria-pressed="guideMode === 'respec'"
                        :disabled="state.respec === null || state.respec === undefined"
                        @click="guideMode = 'respec'"
                    >
                        再振りをしたい
                    </button>
                </div>
                <section
                    v-if="guideMode === 'conversation' && guideConversation"
                    class="underground-guide-conversation"
                    aria-live="polite"
                >
                    <p class="underground-guide-conversation-line">
                        <template v-if="guideConversationPhase === 'punch'">{{ state.shopkeeper_name ?? '案内人' }}{{ guideConversationLine }}</template>
                        <template v-else>{{ state.shopkeeper_name ?? '案内人' }}「{{ guideConversationLine }}」</template>
                    </p>
                    <div v-if="guideConversationPhase === 'topic'" class="underground-guide-conversation-choices">
                        <button
                            v-for="choice in guideConversation.choices"
                            :key="choice.position"
                            type="button"
                            :disabled="busy"
                            @click="chooseGuideConversationReply(choice.position)"
                        >
                            {{ choice.text }}
                        </button>
                        <button class="underground-guide-punch" type="button" :disabled="busy" @click="punchGuide">げんこつ</button>
                    </div>
                    <div v-else-if="guideConversationPhase === 'punch'" class="underground-guide-conversation-choices">
                        <button class="underground-guide-punch" type="button" :disabled="busy" @click="punchGuide">もう一度げんこつ</button>
                        <button type="button" :disabled="busy" @click="stopGuideConversation">やめる</button>
                    </div>
                    <div v-else class="underground-guide-conversation-choices">
                        <button class="underground-guide-punch" type="button" :disabled="busy" @click="punchGuide">げんこつ</button>
                        <button type="button" :disabled="busy" @click="stopGuideConversation">話をやめる</button>
                    </div>
                </section>
                <p v-else-if="guideMode === 'conversation'" class="underground-guide-conversation">{{ busy ? '話題を選んでいます…' : '話題がまだ登録されていません。' }}</p>
                <section v-else-if="guideMode === 'recollections'" class="underground-guide-recollections" aria-labelledby="underground-recollections-title">
                    <header>
                        <h2 id="underground-recollections-title">過去のイベントを振り返る</h2>
                    </header>
                    <p v-if="!state.recollections?.past_available" class="underground-guide-conversation">
                        試練2を初回クリアすると、案内人の過去について問えるようになります。
                    </p>
                    <ul class="underground-recollection-list">
                        <li
                            v-for="entry in recollectionEntries"
                            :key="entry.key"
                            :class="{ 'underground-recollection-section-start': recollectionStartsSection(entry) }"
                        >
                            <button
                                type="button"
                                :disabled="entry.locked"
                                :aria-disabled="entry.locked ? 'true' : undefined"
                                @click="selectRecollection(entry)"
                            >
                                {{ entry.title }}<span v-if="entry.locked">（未解禁）</span><span v-else-if="entry.kind === 'past' && !entry.completed">（読む）</span>
                            </button>
                        </li>
                    </ul>
                    <article v-if="selectedRecollection" class="underground-recollection-detail" aria-live="polite">
                        <h3>{{ selectedRecollection.title }}</h3>
                        <div v-if="selectedRecollection.body" class="underground-story">
                            <p v-for="(line, index) in selectedRecollection.body" :key="`${selectedRecollection.key}-${index}`">{{ line }}</p>
                        </div>
                        <button
                            v-if="selectedRecollection.kind === 'past' && !selectedRecollection.completed"
                            class="button primary"
                            type="button"
                            :disabled="busy || selectedRecollection.locked"
                            @click="completeRecollection(selectedRecollection)"
                        >
                            読み終えた
                        </button>
                    </article>
                    <p v-else class="underground-guide-conversation">読める記録を選んでください。</p>
                </section>
                <section v-else-if="guideMode === 'serious_talk' && (seriousTalkScene || state.guide_duel?.unlocked)" class="underground-guide-serious-talk" aria-labelledby="underground-serious-talk-title">
                    <header>
                        <h2 id="underground-serious-talk-title">{{ state.recollections?.serious_talk?.title ?? '案内人に真剣な話をする' }}</h2>
                    </header>
                    <div class="underground-story">
                        <p v-for="(line, index) in seriousTalkScene?.lines ?? []" :key="`${seriousTalkSceneKey}-${index}`">{{ line }}</p>
                    </div>
                    <div class="underground-guide-actions underground-serious-talk-actions">
                        <button v-for="choice in seriousTalkChoices" :key="choice.key" type="button" @click="chooseSeriousTalk(choice)">{{ choice.label }}</button>
                    </div>
                </section>
                <section v-else-if="guideMode === 'guide_duel' && state.guide_duel?.unlocked" class="underground-guide-serious-talk" aria-label="夢の女王との決闘">
                    <h2>夢の女王との決闘</h2>
                    <p v-for="(line, index) in guideDuelLines" :key="index">{{ line }}</p>
                    <p>無料で挑戦できます。決闘後はHP・MP・覚醒が挑戦前の状態に戻り、敗北ペナルティはありません。</p>
                    <div class="underground-guide-actions">
                        <template v-if="!guideDuelCancelled">
                            <button type="button" :disabled="busy || Boolean(state.trial?.active_run) || state.skill_rebuild_required" @click="runGuideDuel">{{ pendingGuideDuel ? '決闘の結果を再確認する' : 'それでも構わない' }}</button>
                            <button type="button" :disabled="busy" @click="guideDuelCancelled = true">やめておく</button>
                        </template>
                        <button v-else type="button" @click="openSeriousTalk">戻る</button>
                    </div>
                </section>
                <section v-else-if="guideMode === 'respec'" class="underground-respec-panel" aria-labelledby="underground-respec-title">
                    <header>
                        <div>
                            <h2 id="underground-respec-title">再振り</h2>
                        </div>
                        <p v-if="state.respec">手持ち {{ state.shard_balance }} G</p>
                    </header>
                    <div v-if="state.respec" class="underground-respec-explanations">
                        <p>SP・STP・成長方針を再設定します。</p>
                        <p>輝石のかけらが Lv × 10 G 必要です。</p>
                        <p>一度行うと24時間は再び行うことができません。</p>
                    </div>
                    <template v-if="state.respec">
                        <dl class="underground-respec-summary">
                            <div><dt>今回の費用</dt><dd>{{ state.respec.cost }} G</dd></div>
                            <div><dt>次回利用</dt><dd>{{ respecCooldownSeconds > 0 ? `あと${respecCooldownSeconds}秒` : '現在利用可能' }}</dd></div>
                        </dl>
                        <p v-if="respecUnavailable" class="underground-respec-notice" role="status">{{ respecUnavailableReason }}</p>
                        <div class="underground-respec-growth-grid" role="radiogroup" aria-label="再振り後の成長方針">
                            <article
                                v-for="path in respecPaths"
                                :key="path.key"
                                class="underground-growth-card underground-respec-growth-card"
                                :data-color="path.color"
                                :data-selected="selectedRespecPathKey === path.key"
                            >
                                <h3>{{ path.label }}</h3>
                                <p v-for="line in path.description" :key="line">{{ line }}</p>
                                <dl>
                                    <div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ path.stats[key] }}</dd></div>
                                    <div><dt>HP</dt><dd>{{ path.max_hp }}</dd></div>
                                    <div><dt>MP</dt><dd>{{ path.max_mp }}</dd></div>
                                </dl>
                                <p>Lv2以降: 自然成長 {{ Object.values(path.natural_growth).reduce((sum, value) => sum + value, 0) }} / 未使用STP +{{ path.unspent_stp_per_level }}</p>
                                <button
                                    type="button"
                                    role="radio"
                                    :aria-checked="selectedRespecPathKey === path.key"
                                    :disabled="respecUnavailable || busy"
                                    @click="selectRespecPath(path.key)"
                                >
                                    {{ selectedRespecPathKey === path.key ? '選択中' : `${path.label}を選ぶ` }}
                                </button>
                            </article>
                        </div>
                        <p v-if="selectedRespecPath" class="underground-respec-selection" role="status">選択中: {{ selectedRespecPath.label }}</p>
                        <button
                            class="button primary underground-respec-submit"
                            type="button"
                            :disabled="respecUnavailable || busy || selectedRespecPath === null"
                            @click="openRespecConfirmation"
                        >
                            再振り内容を確認する
                        </button>
                    </template>
                </section>
            </section>

            <section v-if="equipmentView === 'party'" class="underground-party-settings-page" aria-labelledby="underground-party-settings-title">
                <header>
                    <div><h1 id="underground-party-settings-title">パーティー編成</h1></div>
                    <strong>{{ 1 + partySelectedIds.length }} / 4</strong>
                </header>
                <p>自分の秘書がリーダーです。同行者として他プレイヤーの秘書を最大3人まで選べます。試練はソロ専用ですが、保存中のPT編成は消えません。</p>
                <p>レンタル更新時に同行者全員のHPが満タン、覚醒が0になります。その後は戦闘間で持ち越し、宿屋ではHPだけが全回復します。</p>
                <ul v-if="state.rental_party?.length" aria-label="現在のレンタル状態">
                    <li v-for="member in state.rental_party" :key="member.secretary_id">{{ member.display_name }}：HP {{ member.current_hp }} / {{ member.max_hp }}・覚醒 {{ member.awakening_gauge }}</li>
                </ul>
                <p v-if="rentalDraftChanged" role="status">編成はまだ確定していません。出発時は保存済みの編成を使います。</p>
                <UndergroundPartyBuilder
                    :candidates="partyCandidates"
                    :selected-ids="partySelectedIds"
                    :leader-combat-level="state.combat_level"
                    :show-candidate-list="partyCandidateSearchOpen"
                    :disabled="busy || Boolean(state.trial?.active_run)"
                    @toggle="togglePartyMember"
                />
                <button class="button primary" type="button" :disabled="busy || Boolean(pendingExplorationRequest) || Boolean(state.trial?.active_run)" @click="saveRentalParty">レンタルを確定・更新</button>
                <p v-if="pendingExplorationRequest" role="status">結果が未確認の探索があります。結果を確認してからレンタルを更新してください。</p>
                <section class="underground-party-browser" aria-label="貸出秘書を探す">
                    <button type="button" :disabled="busy || Boolean(state.trial?.active_run)" @click="togglePartyCandidateSearch">
                        {{ partyCandidateSearchOpen ? '貸出候補を閉じる' : '貸出秘書を探す' }}
                    </button>
                    <p v-if="partyCandidateSearchOpen && partyCandidateLoading" class="status">貸出候補を読み込んでいます。</p>
                    <button v-if="partyCandidateSearchOpen && partyCandidateLoadedOnce && partyCandidateNextAfterId !== null" type="button" :disabled="partyCandidateLoading" @click="loadMorePartyCandidates">さらに表示</button>
                    <p v-if="partyCandidateSearchOpen && partyCandidateSearchComplete && availableBorrowedCandidates.length === 0" class="underground-party-empty">現在、貸出可能な秘書はいません。</p>
                </section>
                <section v-if="state.lending" class="underground-lending-settings" aria-labelledby="underground-lending-title">
                    <h2 id="underground-lending-title">自分の秘書の貸出設定</h2>
                    <label><input v-model="lendingEnabled" type="checkbox" :disabled="busy"> 他のプレイヤーに秘書を貸し出す</label>
                    <button type="button" :disabled="busy" @click="saveLendingSettings">貸出設定を保存</button>
                </section>
            </section>

            <UndergroundAiEditor
                v-if="equipmentView === 'ai' && state.ai"
                :configuration="state.ai"
                @updated="applyAiMutation"
            />
            <UndergroundEquipmentVault v-if="equipmentView === 'vault'" @updated="applyEquipmentMutation" />


                <section v-if="equipmentView === 'character'" class="underground-character-pane" aria-labelledby="underground-character-title">
                    <div class="underground-character-header">
                        <div><h1 id="underground-character-title">{{ state.visuals?.display_name ?? state.secretary_name }}</h1></div>
                        <img v-if="state.visuals?.icon_url" :src="state.visuals.icon_url" :alt="`${state.secretary_name}のアイコン`">
                    </div>
                    <dl class="underground-summary">
                        <div><dt>戦闘Lv</dt><dd>{{ state.combat_level }}</dd></div>
                        <div><dt>経験値</dt><dd>{{ state.combat_xp }} / {{ state.next_level_xp }}</dd></div>
                        <div v-if="state.growth_path"><dt>HP</dt><dd>{{ state.current_hp ?? state.growth_path.max_hp }} / {{ state.growth_path.max_hp }}</dd></div>
                        <div v-if="state.growth_path"><dt>戦闘開始MP</dt><dd>10000 / 10000</dd></div>
                        <div><dt>輝石の欠片</dt><dd>{{ state.shard_balance }} G</dd></div>
                        <div><dt>銀行預金</dt><dd>{{ state.banked_shard_balance }} G</dd></div>
                        <div><dt>未使用STP</dt><dd>{{ state.unspent_stp }}</dd></div>
                        <div v-if="state.growth_path"><dt>成長方針</dt><dd>{{ state.growth_path.label }}</dd></div>
                    </dl>
                    <section
                        v-if="state.awakening?.unlocked"
                        class="underground-awakening-gauge"
                        :data-full="state.awakening.current >= state.awakening.maximum"
                        aria-labelledby="underground-awakening-gauge-title"
                    >
                        <div><h2 id="underground-awakening-gauge-title">覚醒ゲージ</h2></div>
                        <progress :max="state.awakening.maximum" :value="state.awakening.current" />
                    </section>
                    <section v-if="state.growth_path" class="underground-growth-summary">
                        <h2>能力</h2>
                        <dl><div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ state.current_stats?.[key] ?? state.growth_path.stats[key] }}</dd></div></dl>
                        <p>自然回復 {{ state.growth_path.natural_recovery }} MP / ラウンド・Lv2以降 未使用STP +{{ state.growth_path.unspent_stp_per_level }}</p>
                    </section>
                    <section class="underground-equipment" aria-labelledby="underground-equipment-title">
                        <h2 id="underground-equipment-title">装備</h2>
                        <dl>
                            <div v-for="slot in equipmentSlots" :key="slot"><dt>{{ equipmentSlotLabel(slot) }}</dt><dd>{{ state.equipment_summary?.equipped[slot]?.name ?? '未設定' }}</dd></div>
                        </dl>
                        <p v-if="state.equipment_summary">装備保管庫 {{ state.equipment_summary.used }} / {{ state.equipment_summary.capacity }}</p>
                    </section>
                </section>

                        <form v-if="equipmentView === 'bank'" class="underground-bank" @submit.prevent>
                            <h3>銀行</h3>
                            <p>手持ち: {{ state.shard_balance }} G</p>
                            <p>預金: {{ state.banked_shard_balance }} G</p>
                            <label for="underground-bank-amount">1000G単位の金額</label>
                            <input id="underground-bank-amount" v-model.number="bankAmount" type="number" min="1000" step="1000" :disabled="busy">
                            <div class="underground-shop-entries">
                                <button type="button" :disabled="busy" @click="runBankAction('deposit')">預け入れ</button>
                                <button type="button" :disabled="busy" @click="runBankAction('withdraw')">引き出し</button>
                                <button type="button" :disabled="busy" @click="runBankAction('deposit_all')">すべて預ける</button>
                                <button type="button" :disabled="busy" @click="runBankAction('withdraw_all')">すべて引き出す</button>
                            </div>
                        </form>

                    <section v-if="['adventure', 'trials', 'secret'].includes(equipmentView)" class="underground-adventure" aria-labelledby="underground-adventure-title">
                        <header class="underground-adventure-heading">
                            <h2 id="underground-adventure-title">冒険</h2>
                            <div v-if="skipTicketBalance !== null" class="underground-skip-entry">
                                <span>🎫 所持 {{ skipTicketBalance }}枚</span>
                                <button type="button" :disabled="busy" @click="skipModalOpen = true">スキップ使用</button>
                            </div>
                        </header>
                        <p v-if="pendingExplorationRequest" class="underground-pending-request" role="status">
                            前回の探索結果を確認中です。編成を変えても、同じ同行者で結果を再確認します。
                        </p>
                        <div class="underground-adventure-sections underground-entries">
                            <section v-if="equipmentView === 'adventure'" class="underground-adventure-block" aria-labelledby="underground-hunting-ground-title">
                                <h3 id="underground-hunting-ground-title">狩場</h3>
                                <select class="underground-ground-selector" aria-label="狩場を選択" :value="selectedHuntingGroundKey" :disabled="busy || Boolean(state.trial?.active_run)" @change="changeHuntingGround">
                                    <option v-for="ground in ordinaryHuntingGrounds" :key="ground.key" :value="ground.key">{{ ground.name }}</option>
                                </select>
                                <button class="button primary underground-explore-button" type="button" :disabled="busy || exploreCooldownSeconds > 0 || Boolean(state.trial?.active_run) || !selectedHuntingGround" @click="runSelectedExploration">探索する</button>
                                <small v-if="exploreCooldownSeconds > 0">次の出発まであと{{ exploreCooldownSeconds }}秒</small>
                                <small v-else-if="state.trial?.active_run">進行中の試練から帰還すると探索できます。</small>
                                <small v-else>現在のPT {{ 1 + confirmedPartyIds.length }} / 4で出発します。</small>
                            </section>
                            <section v-if="equipmentView === 'trials'" class="underground-adventure-block" aria-labelledby="underground-trial-title">
                                <h3 id="underground-trial-title">試練</h3>
                                <select v-model="selectedTrialKey" aria-label="試練を選択" :disabled="busy || Boolean(state.trial?.active_run)">
                                    <option v-for="trial in unlockedTrialOptions" :key="trial.key" :value="trial.key">{{ trial.label }}</option>
                                </select>
                                <button class="button primary underground-trial-entry" type="button" :disabled="busy || exploreCooldownSeconds > 0 || !selectedTrial || Boolean(state.trial?.active_run && state.trial.active_run.key !== selectedTrial.key)" @click="runTrial(selectedTrial?.key)">{{ state.trial?.active_run ? '次の戦闘へ' : '試練を開始' }}</button>
                                <small v-if="state.trial?.active_run">進行中：{{ state.trial.active_run.next_battle_index }} / {{ state.trial.active_run.total_battles }}戦目</small>
                                <small v-else-if="selectedTrial">{{ selectedTrial.total_battles }}連戦・ソロ専用・{{ selectedTrial.first_cleared ? 'clear済み' : '未clear' }}</small>
                                <small v-else>解禁済みの試練はありません。</small>
                            </section>
                            <section v-if="equipmentView === 'secret'" class="underground-adventure-block" aria-labelledby="underground-vault-title">
                                <h3 id="underground-vault-title">秘密の場所</h3>
                                <strong>{{ shiningKingdomVault?.name ?? '未解禁' }}</strong>
                                <button class="button primary" type="button" :disabled="busy || exploreCooldownSeconds > 0 || Boolean(state.trial?.active_run) || !shiningKingdomVault || shiningKingdomVault.disabled" @click="shiningKingdomVault && runExplore(shiningKingdomVault.key, 'shining-kingdom-vault')">挑戦する</button>
                                <small v-if="shiningKingdomVault?.disabled">{{ shiningKingdomVault.unavailable_reason }}</small>
                                <small v-else-if="shiningKingdomVault">鍵 {{ shiningKingdomVault.key_balance }}個・1回につき{{ shiningKingdomVault.entry_key_cost }}個消費</small>
                                <small v-else>試練2を初回clearすると解禁されます。</small>
                            </section>
                        </div>
                        <button v-if="state.trial?.active_run" class="button secondary" type="button" :disabled="busy" @click="withdrawTrial">封印の地から帰還する</button>
                    </section>

                    <section v-if="equipmentView === 'playtest' && state.playtest" class="underground-playtest" aria-labelledby="underground-playtest-title">
                        <h2 id="underground-playtest-title">力試し（α）</h2>
                        <p>{{ state.playtest.notice }}</p>
                        <label for="underground-build">完成形ビルド</label>
                        <select id="underground-build" v-model="selectedBuild" :disabled="busy"><option v-for="build in state.playtest.builds" :key="build.key" :value="build.key">{{ build.label }} — {{ build.description }}</option></select>
                        <label for="underground-enemy">対戦相手</label>
                        <select id="underground-enemy" v-model="selectedEnemy" :disabled="busy"><option v-for="enemy in state.playtest.enemies" :key="enemy.key" :value="enemy.key">{{ enemy.label }} — {{ enemy.description }}</option></select>
                        <button class="button primary" type="button" :disabled="busy || !selectedBuild || !selectedEnemy" @click="runPlaytest">戦闘開始</button>
                        <p>報酬なし: XP 0・輝石の欠片 0G・ドロップなし。敗北ペナルティもありません。</p>
                    </section>

                    <section v-if="equipmentView === 'history'" class="underground-history" aria-labelledby="underground-history-title">
                        <h2 id="underground-history-title">戦闘履歴</h2>
                        <ul><li v-for="battle in recentBattles" :key="battle.id"><button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battleRoundCount(battle) }}ラウンド</button></li></ul>
                    </section>

                            <UndergroundResidence v-if="(equipmentView === 'property' || equipmentView === 'villa') && state.residence" :mode="equipmentView" :residence="state.residence" :busy="busy" :shards="state.shard_balance" @purchase="loungeMutation('residence/purchase', { item: $event })" @property="equipmentView = 'property'" />
                            <template v-if="equipmentView === 'recollections'">
                                <p v-if="!state.residence?.villa_owned">別荘を購入すると回想を読めます。</p>
                                <section v-else class="ug-event-replays" aria-label="交流場と鏡の回想">
                                    <button v-if="(state.residence.exchange_intro_page ?? 0) >= 1" type="button" @click="loungeReplay = 'exchange-1'">{{ loungeStories.exchange[0]!.title }}</button>
                                    <button v-if="state.residence.exchange_intro_page >= 2" type="button" @click="loungeReplay = 'exchange-2'">{{ loungeStories.exchange[1]!.title }}</button>
                                    <button v-if="state.residence.mirror_event_completed" type="button" @click="loungeReplay = 'mirror'">{{ loungeStories.mirror.title }}</button>
                                </section>
                            </template>
                        </div>
                    </template>
                </template>
            <div v-if="skipModalOpen" class="modal-backdrop" @click.self="!busy && (skipModalOpen = false)">
                <section class="underground-skip-dialog" role="dialog" aria-modal="true" aria-labelledby="underground-skip-dialog-title">
                    <header>
                        <div><h2 id="underground-skip-dialog-title">スキップを使用</h2></div>
                        <div><strong>🎫 {{ skipTicketBalance ?? 0 }}枚</strong><button type="button" aria-label="閉じる" :disabled="busy" @click="skipModalOpen = false">×</button></div>
                    </header>
                    <p v-if="skipError" class="status error underground-skip-error" role="alert">{{ skipError }}</p>
                    <div v-if="pendingSkipRequest" class="status underground-skip-pending" role="status">
                        <p>結果が不明なskipがあります。残高表示にかかわらず、前回と同じ内容を再確認できます。</p>
                        <button class="underground-skip-retry" type="button" :disabled="busy" @click="retryPendingSkip">前回のskip結果を再確認する</button>
                    </div>
                    <p class="field-hint">1回の操作で使える上限は1,000回（試練は1,000周）です。</p>
                    <section class="underground-skip-category" aria-labelledby="underground-skip-ground-title">
                        <h3 id="underground-skip-ground-title">狩場</h3>
                        <label>対象
                            <select :value="selectedSkipHuntingGroundKey" :disabled="busy" @change="changeSkipHuntingGround">
                                <option v-for="ground in unlockedHuntingGrounds" :key="`modal-ground:${ground.key}`" :value="ground.key">{{ ground.name }}</option>
                            </select>
                        </label>
                        <template v-if="selectedSkipHuntingGround">
                            <p>1回 = {{ selectedSkipHuntingGround.skip.ticket_cost }}枚・実戦 {{ selectedSkipHuntingGround.skip.actual_clear_count }} / {{ selectedSkipHuntingGround.skip.actual_clears_required }}勝</p>
                            <p v-if="selectedSkipHuntingGround.entry_key_cost > 0">さらに鍵{{ selectedSkipHuntingGround.entry_key_cost }}個 / 回（所持 {{ selectedSkipHuntingGround.key_balance }}個）</p>
                            <p v-if="!selectedSkipHuntingGround.skip.unlocked" class="field-hint">実戦clearがあと{{ selectedSkipHuntingGround.skip.actual_clears_required - selectedSkipHuntingGround.skip.actual_clear_count }}回必要です。</p>
                            <div class="underground-skip-shortcuts">
                                <button type="button" :disabled="selectedSkipHuntingGround.disabled || skipDisabled(selectedSkipHuntingGround.skip) || shortcutHuntingGroundSkipExecutions(selectedSkipHuntingGround, 0.5) < 1 || skipIntentBlocked('hunting_ground', selectedSkipHuntingGround.key, shortcutHuntingGroundSkipExecutions(selectedSkipHuntingGround, 0.5))" @click="runSkip('hunting_ground', selectedSkipHuntingGround.key, shortcutHuntingGroundSkipExecutions(selectedSkipHuntingGround, 0.5))">50%使用（{{ shortcutHuntingGroundSkipExecutions(selectedSkipHuntingGround, 0.5) }}回）</button>
                                <button type="button" :disabled="selectedSkipHuntingGround.disabled || skipDisabled(selectedSkipHuntingGround.skip) || maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround) < 1 || skipIntentBlocked('hunting_ground', selectedSkipHuntingGround.key, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround))" @click="runSkip('hunting_ground', selectedSkipHuntingGround.key, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround))">100%使用（{{ maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround) }}回）</button>
                            </div>
                            <div class="underground-skip-custom">
                                <label>任意回数
                                    <input v-model="customHuntingGroundSkipCount" inputmode="numeric" type="number" min="1" :max="maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround)" step="1" :disabled="selectedSkipHuntingGround.disabled || skipDisabled(selectedSkipHuntingGround.skip)">
                                </label>
                                <button type="button" :disabled="customSkipExecutions(customHuntingGroundSkipCount, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround)) === null || selectedSkipHuntingGround.disabled || skipDisabled(selectedSkipHuntingGround.skip) || skipIntentBlocked('hunting_ground', selectedSkipHuntingGround.key, customSkipExecutions(customHuntingGroundSkipCount, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround)) ?? 0)" @click="runCustomHuntingGroundSkip(selectedSkipHuntingGround)">指定回数を使用</button>
                            </div>
                            <p v-if="customSkipExecutions(customHuntingGroundSkipCount, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround)) !== null" class="underground-skip-plan">予定消費：🎫 {{ plannedSkipCost(customHuntingGroundSkipCount, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround), selectedSkipHuntingGround.skip.ticket_cost) }}枚<span v-if="selectedSkipHuntingGround.entry_key_cost > 0">・鍵 {{ plannedSkipCost(customHuntingGroundSkipCount, maximumHuntingGroundSkipExecutions(selectedSkipHuntingGround), selectedSkipHuntingGround.entry_key_cost) }}個</span></p>
                        </template>
                        <ul v-if="(state.hunting_grounds ?? []).some((ground) => ground.locked)" class="underground-skip-locked-list">
                            <li v-for="ground in (state.hunting_grounds ?? []).filter((item) => item.locked)" :key="`locked-ground:${ground.key}`">{{ ground.name }}：{{ ground.unlock_condition ?? '未解禁' }}</li>
                        </ul>
                    </section>
                    <section class="underground-skip-category" aria-labelledby="underground-skip-trial-title">
                        <h3 id="underground-skip-trial-title">試練</h3>
                        <label>対象
                            <select v-model="selectedSkipTrialKey" aria-label="スキップする試練を選択" :disabled="busy">
                                <option v-for="trial in unlockedTrialOptions" :key="`modal-trial:${trial.key}`" :value="trial.key">{{ trial.label }}</option>
                            </select>
                        </label>
                        <template v-if="selectedSkipTrial">
                            <p>1周（{{ selectedSkipTrial.total_battles }}連戦）= {{ selectedSkipTrial.skip.ticket_cost }}枚・実戦 {{ selectedSkipTrial.skip.actual_clear_count }} / {{ selectedSkipTrial.skip.actual_clears_required }}周</p>
                            <p v-if="!selectedSkipTrial.skip.unlocked" class="field-hint">実戦clearがあと{{ selectedSkipTrial.skip.actual_clears_required - selectedSkipTrial.skip.actual_clear_count }}周必要です。</p>
                            <div class="underground-skip-shortcuts">
                                <button type="button" :disabled="skipDisabled(selectedSkipTrial.skip) || shortcutSkipExecutions(selectedSkipTrial.skip, 0.5) < 1 || skipIntentBlocked('trial', selectedSkipTrial.key, shortcutSkipExecutions(selectedSkipTrial.skip, 0.5))" @click="runSkip('trial', selectedSkipTrial.key, shortcutSkipExecutions(selectedSkipTrial.skip, 0.5))">50%使用（{{ shortcutSkipExecutions(selectedSkipTrial.skip, 0.5) }}周）</button>
                                <button type="button" :disabled="skipDisabled(selectedSkipTrial.skip) || maximumSkipExecutions(selectedSkipTrial.skip) < 1 || skipIntentBlocked('trial', selectedSkipTrial.key, maximumSkipExecutions(selectedSkipTrial.skip))" @click="runSkip('trial', selectedSkipTrial.key, maximumSkipExecutions(selectedSkipTrial.skip))">100%使用（{{ maximumSkipExecutions(selectedSkipTrial.skip) }}周）</button>
                            </div>
                            <div class="underground-skip-custom">
                                <label>任意周回数
                                    <input v-model="customTrialSkipCount" inputmode="numeric" type="number" min="1" :max="maximumSkipExecutions(selectedSkipTrial.skip)" step="1" :disabled="skipDisabled(selectedSkipTrial.skip)">
                                </label>
                                <button type="button" :disabled="customSkipExecutions(customTrialSkipCount, maximumSkipExecutions(selectedSkipTrial.skip)) === null || skipDisabled(selectedSkipTrial.skip) || skipIntentBlocked('trial', selectedSkipTrial.key, customSkipExecutions(customTrialSkipCount, maximumSkipExecutions(selectedSkipTrial.skip)) ?? 0)" @click="runCustomTrialSkip(selectedSkipTrial)">指定周回数を使用</button>
                            </div>
                            <p v-if="customSkipExecutions(customTrialSkipCount, maximumSkipExecutions(selectedSkipTrial.skip)) !== null" class="underground-skip-plan">予定消費：🎫 {{ plannedSkipCost(customTrialSkipCount, maximumSkipExecutions(selectedSkipTrial.skip), selectedSkipTrial.skip.ticket_cost) }}枚</p>
                        </template>
                        <ul v-if="trialOptions.some((trial) => trial.locked)" class="underground-skip-locked-list">
                            <li v-for="trial in trialOptions.filter((item) => item.locked)" :key="`locked-trial:${trial.key}`">{{ trial.label }}：{{ trial.unlock_condition ?? '未解禁' }}</li>
                        </ul>
                    </section>
                    <article v-if="lastSkipResult" class="underground-skip-result" role="status">
                        <h3>{{ skipContentLabel(lastSkipResult) }}を{{ lastSkipResult.execution_count ?? 1 }}{{ lastSkipResult.content_type === 'trial' ? '周' : '回' }}スキップしました</h3>
                        <p v-if="lastSkipResult.duplicate">通信再送のため、前回確定した同じ結果を表示しています。</p>
                        <dl>
                            <div><dt>消費</dt><dd>{{ lastSkipResult.ticket_cost }}枚</dd></div>
                            <div><dt>EXP</dt><dd>+{{ lastSkipResult.xp_awarded }}</dd></div>
                            <div><dt>欠片</dt><dd>+{{ lastSkipResult.shards_awarded }}G</dd></div>
                            <div><dt>Lv</dt><dd>{{ lastSkipResult.combat_level_before }} → {{ lastSkipResult.combat_level_after }}</dd></div>
                            <div><dt>装備獲得</dt><dd>{{ lastSkipResult.rewards?.equipment_granted_count ?? skipDrops(lastSkipResult).filter((drop) => drop.status === 'granted').length }}個</dd></div>
                            <div><dt>取り逃し</dt><dd>{{ lastSkipResult.rewards?.vault_full_count ?? skipDrops(lastSkipResult).filter((drop) => drop.status === 'vault_full').length }}個（宝物庫満杯）</dd></div>
                            <div><dt>残りticket</dt><dd>{{ skipRemainingTickets(lastSkipResult) }}枚</dd></div>
                        </dl>
                        <details v-if="skipDrops(lastSkipResult).length > 0">
                            <summary>獲得装備の詳細</summary>
                            <ul><li v-for="(drop, index) in skipDrops(lastSkipResult)" :key="index">{{ skipDropText(drop) }}</li></ul>
                        </details>
                    </article>
                </section>
            </div>

            <section v-if="equipmentView === 'status' && state.status_breakdown" class="underground-progression-panel" aria-labelledby="underground-status-title">
                <header>
                    <div><h2 id="underground-status-title">ステータス</h2></div>
                    <p>未使用STP {{ state.unspent_stp }} / 仮配分後 {{ stpDraftRemaining }}</p>
                </header>
                <div class="underground-table-scroll">
                    <table class="underground-status-table">
                        <thead><tr><th scope="col">能力</th><th scope="col">現在値<br>（装備なし）</th><th scope="col">今回の配分</th></tr></thead>
                        <tbody>
                            <tr v-for="(label, key) in statLabels" :key="key">
                                <th scope="row">{{ label }}</th>
                                <td>{{ state.status_breakdown[key].baseline + state.status_breakdown[key].natural_growth + state.status_breakdown[key].allocated_stp }}</td>
                                <td class="underground-stp-control">
                                    <div class="ug-stp-inputs">
                                    <button type="button" :disabled="busy" :aria-label="`${label}に残りの50%を配分`" @click="setStpShare(key, 0.5)">50%</button>
                                    <input type="number" min="0" :max="maximumStpDraft(key)" step="1" inputmode="numeric" :value="stpDraft[key]" :disabled="busy" :aria-label="`${label}の今回の配分`" @input="setStpDraft(key, $event)">
                                    <button type="button" :disabled="busy" :aria-label="`${label}に残りの100%を配分`" @click="setStpShare(key, 1)">100%</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="underground-progression-note">50%・100%は、ほかの能力への仮配分を除いた残りを使います。50%の端数は切り捨てます。最後に一括確定してください。</p>
                <button class="button primary" type="button" :disabled="busy || stpDraftTotal === 0" @click="confirmStp">{{ stpDraftTotal }} STPを一括確定</button>
            </section>

            <section v-if="equipmentView === 'skills' && state.skill_trees" class="underground-progression-panel" aria-labelledby="underground-skills-title">
                <header>
                    <div><h2 id="underground-skills-title">Skill Tree</h2></div>
                    <div class="underground-skill-header-actions">
                        <p>SP {{ state.skill_points_unspent }} / {{ state.skill_points_total }}（使用済み {{ state.skill_points_spent }}）</p>
                        <button class="underground-skill-jump" type="button" @click="focusActiveLoadout">アクティブスキル設定へ</button>
                    </div>
                </header>
                <p class="underground-progression-note">SPを消費することでスキルを習得できます。</p>
                <UndergroundSkillTree :trees="state.skill_trees" :busy="busy" :user-id="userId" :growth-path="state.growth_path?.key" @acquire="acquireSkill" @equip="focusActiveLoadout" />

                <section id="underground-active-loadout" class="underground-active-loadout" aria-labelledby="underground-loadout-title">
                    <header><div><h3 id="underground-loadout-title" tabindex="-1">Active Skill</h3><p>取得済みskillを最大5個まで装備します。</p></div><p>基本行動: 通常攻撃 / 防御（常時利用可能）</p></header>
                    <div class="underground-loadout-grid">
                        <label v-for="(_, index) in loadoutDraft" :key="index">slot {{ index + 1 }}
                            <select v-model="loadoutDraft[index]" :disabled="busy">
                                <option :value="null">未設定</option>
                                <option v-for="skill in acquiredActiveSkills" :key="skill.key" :value="skill.key" :disabled="loadoutChoiceDisabled(skill.key, index)">{{ skill.label }}（MP {{ skill.mp_cost }} / CD {{ skill.cooldown }}R）</option>
                            </select>
                        </label>
                    </div>
                    <ul class="underground-active-skill-notes">
                        <li v-for="skill in acquiredActiveSkills" :key="skill.key" :class="{ 'underground-skill-incompatible': activeSkillWeaponIncompatible(skill) }">
                            <strong>{{ skill.label }}</strong>: {{ skill.summary }} / MP {{ skill.mp_cost }} / cooldown {{ skill.cooldown }}R
                            <span v-if="skill.required_weapon_styles.length > 0"> / {{ requiredWeaponText(skill.required_weapon_styles) }}</span>
                            <span v-if="activeSkillWeaponIncompatible(skill)" class="underground-node-unavailable">現在の武器では使用できません</span>
                        </li>
                    </ul>
                    <button class="button primary" type="button" :disabled="busy" @click="saveLoadout">slot 1～5を保存</button>
                </section>

                <section v-if="state.awakening?.unlocked && state.awakening.technique" class="underground-awakening-settings" aria-labelledby="underground-awakening-settings-title">
                    <header>
                        <div><h3 id="underground-awakening-settings-title">覚醒奥義設定</h3></div>
                        <strong>覚醒中に1度だけ使用可能</strong>
                    </header>
                    <p>戦闘へ持ち込む奥義を一つ選びます。戦闘と戦闘の間はいつでも変更できます。</p>
                    <div class="underground-awakening-technique-grid" role="radiogroup" aria-label="覚醒奥義">
                        <label
                            v-for="technique in state.awakening.techniques"
                            :key="technique.key"
                            :data-selected="awakeningTechniqueDraft === technique.key"
                        >
                            <span><input v-model="awakeningTechniqueDraft" type="radio" name="awakening-technique" :value="technique.key" :disabled="busy"><strong>{{ technique.name }}</strong></span>
                            <small>{{ technique.summary }}</small>
                            <small>{{ technique.consumes_action ? '通常actionを消費' : '通常actionを消費せず、そのまま行動' }}</small>
                        </label>
                    </div>
                    <button
                        class="button primary underground-awakening-technique-save"
                        type="button"
                        :disabled="busy || !awakeningTechniqueDraft || awakeningTechniqueDraft === state.awakening.selected_technique_key"
                        @click="saveAwakeningTechnique"
                    >
                        選んだ覚醒奥義を保存
                    </button>
                    <hr>
                    <label for="underground-awakening-message">覚醒時の最初の演出文</label>
                    <textarea id="underground-awakening-message" v-model="awakeningMessageDraft" maxlength="100" rows="3" :disabled="busy"></textarea>
                    <p class="underground-progression-note"><code>{secretary_name}</code> はbattle開始時の秘書名へ置換されます。空欄でdefaultへ戻ります。{{ awakeningMessageDraft.length }} / 100</p>
                    <button class="button primary underground-awakening-message-save" type="button" :disabled="busy" @click="saveAwakeningMessage">覚醒演出文を保存</button>
                </section>
            </section>

            <div v-if="respecConfirmOpen && selectedRespecPath && state.respec" class="modal-backdrop underground-confirm-backdrop" @click.self="!busy && (respecConfirmOpen = false)">
                <section class="underground-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="underground-respec-confirm-title">
                    <header>
                        <div>
                            <p class="eyebrow">確認</p>
                            <h2 id="underground-respec-confirm-title">再振りを実行しますか？</h2>
                        </div>
                        <button type="button" aria-label="確認を閉じる" :disabled="busy" @click="respecConfirmOpen = false">×</button>
                    </header>
                    <p>SP・STP・成長方針を再設定します。</p>
                    <p class="underground-confirm-item"><strong>{{ selectedRespecPath.label }}</strong><span>{{ state.respec.cost }} G</span></p>
                    <p class="underground-respec-destructive">この操作は取り消せません。実行後は24時間、再び再振りできません。</p>
                    <footer>
                        <button class="button secondary" type="button" :disabled="busy" @click="respecConfirmOpen = false">キャンセル</button>
                        <button class="button primary" type="button" :disabled="busy || respecUnavailable" @click="confirmRespec">再振りを実行する</button>
                    </footer>
                </section>
            </div>
                <UndergroundNavigation :current="currentDestination" :exchange-discovered="(state.residence?.exchange_intro_page ?? 0) >= 2" @navigate="navigate" />
            </div>
        </template>
    </section>
</template>
