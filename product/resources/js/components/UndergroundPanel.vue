<script setup lang="ts">
import stories from '../../stories/intro.json';
import loungeStories from '../../stories/lounge.json';
import otherworldStory from '../../stories/otherworld.json';
import yunagiHarborStory from '../../stories/yunagi-harbor.json';
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
import UndergroundBattleLog from './UndergroundBattleLog.vue';
import { battleRoundCount, type Battle } from './undergroundBattleLog';
import UndergroundEquipmentShop from './UndergroundEquipmentShop.vue';
import UndergroundMarket, { type MarketAttention } from './UndergroundMarket.vue';
import UndergroundEquipmentVault from './UndergroundEquipmentVault.vue';
import UndergroundEquipmentPolishing from './UndergroundEquipmentPolishing.vue';
import UndergroundPartyBuilder, { type PartyCandidate } from './UndergroundPartyBuilder.vue';
import { shouldReleasePendingExplorationRequest } from './undergroundExplorationPending';
import { renderUndergroundStory } from '../undergroundStoryMarkup';
import type { EquipmentItem, EquipmentSlot } from './EquipmentItemCard.vue';
import { equipmentSlotLabel, equipmentSlots } from './undergroundEquipmentSlots';
import UndergroundStatusAllocation from './UndergroundStatusAllocation.vue';
import { statLabels, useStpDraft, type StatKey } from './undergroundStpDraft';
import type { UndergroundAiConfiguration } from './undergroundAi';
import type { DailyQuestProgress, MerchantConversationTopic } from '../types';

const maximumBulkSkipExecutions = 1000;

type Stage = 'not_started' | 'initial_descent' | 'tutorial_ready' | 'escape_pending'
    | 'returned_after_tutorial' | 'shopkeeper_encounter' | 'shopkeeper_naming'
    | 'special_loss_pending' | 'special_loss_complete' | 'shop_explanation'
    | 'contract_ready' | 'crystal_selection' | 'growth_path_selected' | 'underground_open';


interface HuntingGround {
    key: string;
    name: string;
    kind: 'hunting_ground' | 'vault';
    locked: boolean;
    unlock_condition: string | null;
    entry_key_cost: number;
    key_balance: number;
    key_label: string;
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
        distorted_stones?: number;
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
    upcoming_story?: string | null;
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
    paradox_balance: number;
    cooldown_bypass_cost_pd: number;
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

interface PendingRespecMutation extends PendingMutation {
    growthPathKey: string;
    bypassCooldownWithPd: boolean;
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
    scene?: string;
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
    otherworld: boolean;
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
    inn_cost: number;
    bank_transfer_unit: number;
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
    rental_party?: Array<{ secretary_id: number; display_name: string; current_hp: number; max_hp: number; awakening_gauge: number; icon_url?: string | null }>;
    skill_trees: SkillTree[] | null;
    active_slots: Array<ActiveSkill | null>;
    passive_modifiers: Record<string, number | boolean | string>;
    shopkeeper_name: string | null;
    distorted_stone_shop?: { balance: number; day: string; reset_after_ms: number; purchased_today: number; daily_limit: number; next_price: number | null; unlocked: boolean };
    distorted_stone_reminder?: boolean;
    market_attention?: MarketAttention;
    polishing_tutorial_completed?: boolean;
    otherworld?: {
        stages: Array<{ key: string; name: string; recommended_level: number; item_level: number; locked: boolean; unlock_condition: string | null; cleared: boolean }>;
        distorted_stone_balance: number;
    } | null;
    otherworld_intro_available?: boolean;
    yunagi_harbor_intro_available?: boolean;
    otherworld_unlocked?: boolean;
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
    mad_moon?: { name: string; intro_pending: boolean; retry_available: boolean; cleared: boolean; victory_pending: boolean; can_fight: boolean; story: { title: string; body: string[] } | null } | null;
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

const huntingGroundPreferenceKey = 'hakoniwa.underground.selected-hunting-ground';
const props = defineProps<{ secretaryImageUrl?: string | null; userId?: number }>();
const emit = defineEmits<{
    returnToSecretary: [];
    dailyQuest: [quest: DailyQuestProgress];
    distortedStoneReminder: [unclaimed: boolean];
    marketAttention: [attention: boolean];
}>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const mutationRejected = ref(false);
const error = ref('');
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const battleHistoryLoading = ref(false);
const battleHistoryHasMore = ref(false);
const selectedBattle = ref<Battle | null>(null);
const selectedPartyMemberIds = ref<number[]>([]);
const lastScrolledBattleId = ref<string | null>(null);
const lendingEnabled = ref(false);
const partySelectionHydrated = ref(false);
const selectedBuild = ref('');
const selectedEnemy = ref('');
const bankAmount = ref<number | null>(null);
const selectedHuntingGroundKey = ref('shallow_caves');
const selectedOtherworldStage = ref('bahamul_beginner_1');
const selectedSkipHuntingGroundKey = ref('shallow_caves');
const selectedTrialKey = ref('trial_01');
const selectedSkipTrialKey = ref('trial_01');
const customHuntingGroundSkipCount = ref('');
const customTrialSkipCount = ref('');
const pendingExplorationRequest = ref<PendingExplorationRequest | null>(null);
const pendingPlaytestRequest = ref<{ requestId: string; buildKey: string; enemyKey: string } | null>(null);
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
const loadoutDraft = ref<Array<string | null>>([null, null, null, null, null]);
const pendingStpMutation = ref<PendingMutation | null>(null);
const pendingSkillAcquire = ref<PendingMutation | null>(null);
const pendingLoadoutMutation = ref<PendingMutation | null>(null);
const pendingAwakeningMessageMutation = ref<PendingMutation | null>(null);
const pendingAwakeningTechniqueMutation = ref<PendingMutation | null>(null);
const awakeningMessageDraft = ref('');
const awakeningTechniqueDraft = ref<string | null>(null);
type View = 'home' | 'adventure' | 'otherworld' | 'trials' | 'secret' | 'history' | 'playtest' | 'character' | 'status' | 'skills' | 'shop' | 'polishing' | 'bank' | 'guide' | 'ai' | 'vault' | 'party' | 'property' | 'market' | 'merchant' | 'villa' | 'recollections' | 'trophies';
const equipmentView = ref<View>('home');
const marketInitialPane = ref<'browse' | 'sell'>('browse');
const tabs: Record<UndergroundDestination, Array<{ key: View; label: string }>> = {
    home: [],
    adventure: [{ key: 'adventure', label: '探索' }, { key: 'trials', label: '試練' }, { key: 'secret', label: '秘密の場所' }, { key: 'otherworld', label: '異世界の戦い' }, { key: 'history', label: '戦闘履歴' }, { key: 'playtest', label: '力試し' }],
    character: [{ key: 'character', label: '能力' }, { key: 'status', label: 'STP配分' }, { key: 'skills', label: 'スキル・覚醒' }, { key: 'vault', label: '装備・保管庫' }, { key: 'ai', label: '戦法' }],
    shop: [{ key: 'shop', label: '装備を買う' }, { key: 'polishing', label: '魔石研磨' }, { key: 'bank', label: '銀行' }, { key: 'guide', label: '案内人と話す' }],
    exchange: [{ key: 'party', label: 'パーティー' }, { key: 'property', label: '不動産' }, { key: 'market', label: '露店' }, { key: 'merchant', label: '行商人と話す' }],
    villa: [{ key: 'villa', label: '冒険日誌' }, { key: 'recollections', label: '回想' }, { key: 'trophies', label: 'トロフィー棚' }],
};
const currentDestination = computed<UndergroundDestination>(() => undergroundDestinations.find(destination =>
    destination.key === equipmentView.value || tabs[destination.key].some(tab => tab.key === equipmentView.value))?.key ?? 'home');
const pageTabs = computed(() => tabs[currentDestination.value].filter(tab => (tab.key !== 'playtest' || state.value?.playtest)
    && (!['polishing', 'otherworld'].includes(tab.key) || state.value?.otherworld_unlocked)));
const pageTitle = computed(() => undergroundDestinations.find(destination => destination.key === currentDestination.value)?.label);
const exchangeGreeting = ref(loungeStories.greetings[0]);
const merchantTopics = ref<Array<Pick<MerchantConversationTopic, 'id' | 'question' | 'answer'>>>([]);
const merchantLoading = ref(false);
const merchantError = ref('');
async function loadMerchantTopics(): Promise<void> {
    if (merchantLoading.value) return;
    merchantLoading.value = true;
    merchantError.value = '';
    merchantTopics.value = [];
    try {
        const result = await api<{ topics: typeof merchantTopics.value }>('/api/v1/me/underground/merchant-conversation-topics');
        merchantTopics.value = result.topics;
    } catch (caught) {
        merchantError.value = caught instanceof Error ? caught.message : '話題を読み込めませんでした。';
    } finally {
        merchantLoading.value = false;
    }
}
const loungeReplay = ref<'exchange-1' | 'exchange-2' | 'mirror' | 'otherworld' | null>(null);
const activeLoungeEvent = computed(() => {
    const residence = state.value?.residence;
    if (!residence) return null;
    if (currentDestination.value === 'home' && state.value?.yunagi_harbor_intro_available) {
        return {
            ...yunagiHarborStory,
            body: yunagiHarborStory.body.map(line => line.replaceAll('(秘書名)', () => state.value?.secretary_name ?? '')),
            key: 'yunagi_harbor', page: 1, scene: 'yunagi-harbor-intro',
        };
    }
    if (loungeReplay.value === 'otherworld' || currentDestination.value === 'shop' && state.value?.otherworld_intro_available) {
        return { ...otherworldStory, key: 'otherworld', page: 1, scene: 'otherworld-intro' };
    }
    if (loungeReplay.value === 'mirror' || currentDestination.value === 'shop' && residence.mirror_owned && !residence.mirror_event_completed) {
        return { ...loungeStories.mirror, key: 'mirror', page: 1, scene: 'mirror' };
    }
    if (loungeReplay.value?.startsWith('exchange-') || currentDestination.value === 'exchange' && residence.exchange_intro_page < 2) {
        const page = loungeReplay.value === 'exchange-2' ? 2 : loungeReplay.value === 'exchange-1' ? 1 : residence.exchange_intro_page + 1;
        return { ...loungeStories.exchange[page - 1]!, key: 'exchange', page, scene: 'exchange-intro' };
    }
    if (currentDestination.value === 'shop' && state.value?.otherworld_unlocked && !state.value.polishing_tutorial_completed) {
        return { ...loungeStories.polishing, key: 'polishing', page: 1, scene: 'shop' };
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
    selectedRecollectionKey.value = null;
    equipmentView.value = tabs[destination][0]?.key ?? 'home';
    if (destination === 'exchange') exchangeGreeting.value = loungeStories.greetings[Math.floor(Math.random() * loungeStories.greetings.length)]!;
}
function selectTab(view: View): void {
    loungeReplay.value = null;
    selectedRecollectionKey.value = null;
    if (view === 'market') marketInitialPane.value = 'browse';
    if (view === 'merchant') { equipmentView.value = view; void loadMerchantTopics(); }
    else if (view === 'guide') openGuide();
    else if (view === 'recollections') {
        guideMode.value = 'recollections';
        selectedRecollectionKey.value = null;
        equipmentView.value = view;
    } else equipmentView.value = view;
}
function openMarketSell(): void {
    selectTab('market');
    marketInitialPane.value = 'sell';
}
async function advanceLounge(): Promise<void> {
    if (loungeReplay.value) { loungeReplay.value = null; return; }
    const event = activeLoungeEvent.value;
    if (event && await loungeMutation('events/advance', { event: event.key, page: event.page })) {
        if (event.key === 'otherworld') equipmentView.value = 'otherworld';
        if (event.key === 'polishing') equipmentView.value = 'polishing';
    }
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
type GuideConversationAction = 'start' | 'reply' | 'punch';
const pendingGuideConversation = ref<{ action: GuideConversationAction; payload: Record<string, number>; requestId: string } | null>(null);
const selectedRecollectionKey = ref<string | null>(null);
const seriousTalkSceneKey = ref('root');
const selectedRespecPathKey = ref<string | null>(null);
const respecConfirmOpen = ref(false);
const pendingRespecMutation = ref<PendingRespecMutation | null>(null);
const pendingRecollectionMutation = ref<PendingMutation | null>(null);
const cooldownNowMs = ref(Date.now());
let cooldownTimer: ReturnType<typeof window.setInterval> | null = null;
let huntingGroundPreferenceHydrated = false;
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);
const activeMadMoonStory = computed(() => state.value?.mad_moon?.story ?? null);
const pendingMadMoonRequest = ref<string | null>(null);
const otherworldStage = computed(() => state.value?.otherworld?.stages.find(stage => stage.key === selectedOtherworldStage.value));
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
const ordinaryHuntingGrounds = computed<HuntingGround[]>(() => {
    const grounds = unlockedHuntingGrounds.value.filter((ground) => ground.kind !== 'vault');
    if (state.value?.mad_moon?.retry_available) {
        grounds.push({ key: 'mad_moon', name: state.value.mad_moon.name, kind: 'hunting_ground', locked: false,
            unlock_condition: null, entry_key_cost: 0, key_balance: 0, key_label: '', disabled: !state.value.mad_moon.can_fight,
            unavailable_reason: null, item_level_min: 0, item_level_max: 0,
            skip: { actual_clear_count: 0, total_clear_count: 0, actual_clears_required: 0, unlocked: false, ticket_cost: 0 } });
    }
    return grounds;
});
const selectedHuntingGround = computed(() => ordinaryHuntingGrounds.value
    .find((ground) => ground.key === selectedHuntingGroundKey.value) ?? null);
const selectedSkipHuntingGround = computed(() => unlockedHuntingGrounds.value
    .find((ground) => ground.key === selectedSkipHuntingGroundKey.value) ?? unlockedHuntingGrounds.value[0] ?? null);
function openSkipModal(): void {
    const preferred = [...unlockedHuntingGrounds.value].reverse().find((ground) =>
        !ground.disabled && ground.skip.unlocked
        && (skipTicketBalance.value ?? 0) >= ground.skip.ticket_cost
        && ground.key_balance >= ground.entry_key_cost);
    selectedSkipHuntingGroundKey.value = (preferred ?? unlockedHuntingGrounds.value[0])?.key ?? 'shallow_caves';
    skipModalOpen.value = true;
}
const unlockedVaults = computed(() => unlockedHuntingGrounds.value
    .filter((ground) => ground.kind === 'vault'));
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
const respecInsufficientPd = computed(() => respecCooldownSeconds.value > 0
    && (state.value?.respec?.paradox_balance ?? 0) < (state.value?.respec?.cooldown_bypass_cost_pd ?? 10));
const respecUnavailable = computed(() => state.value?.respec === null
    || state.value?.respec === undefined
    || respecActiveTrial.value
    || respecInsufficientShards.value
    || respecInsufficientPd.value);
const respecUnavailableReason = computed(() => {
    if (state.value?.respec === null || state.value?.respec === undefined) return '再振りを利用できません。';
    if (respecActiveTrial.value) return 'active Trial中は再振りできません。封印の地から帰還してください。';
    if (respecInsufficientShards.value) return '手持ちの輝石のかけらが不足しています。';
    if (respecInsufficientPd.value) return `待ち時間の解除には${state.value.respec.cooldown_bypass_cost_pd}Pdが必要です。`;
    return '';
});
const respecPaths = computed(() => state.value?.respec?.growth_paths ?? []);
const selectedRespecPath = computed(() => respecPaths.value.find((path) => path.key === selectedRespecPathKey.value) ?? null);
const stpDraft = useStpDraft(() => state.value?.unspent_stp ?? 0);
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

watch(() => state.value?.playtest, (playtest) => {
    if (!playtest) return;
    selectedBuild.value = playtest.default_build_key;
    selectedEnemy.value ||= playtest.enemies[0]?.key ?? '';
}, { immediate: true });
watch(() => state.value?.lending?.settings, (settings) => {
    if (!settings) return;
    lendingEnabled.value = settings.is_lendable ?? (settings.is_public && settings.is_available);
}, { deep: true, immediate: true });
watch(() => state.value?.bank_transfer_unit, (unit) => {
    if (!unit) return;
    if (bankAmount.value === null || bankAmount.value <= 0 || bankAmount.value % unit !== 0) {
        bankAmount.value = unit;
    }
}, { immediate: true });

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
    if (typeof current?.distorted_stone_reminder === 'boolean') {
        emit('distortedStoneReminder', current.distorted_stone_reminder);
    }
    if (current?.market_attention) emit('marketAttention', hasMarketAttention(current.market_attention));
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

let stateRefreshQueue: Promise<void> = Promise.resolve();

function applyStateWithLatestStoneDay(incoming: UndergroundState): UndergroundState {
    const latestShop = state.value?.distorted_stone_shop;
    const incomingShop = incoming.distorted_stone_shop;
    if (latestShop && incomingShop && latestShop.day > incomingShop.day) {
        incoming = {
            ...incoming,
            distorted_stone_shop: { ...latestShop, balance: incomingShop.balance, unlocked: incomingShop.unlocked },
            distorted_stone_reminder: state.value?.distorted_stone_reminder,
        };
    }
    state.value = incoming;
    return incoming;
}

function refresh(returnIfTutorialAlreadyFinished = true): Promise<void> {
    const pending = stateRefreshQueue.then(async () => {
        innRested.value = false;
        const current = applyStateWithLatestStoneDay(await api<UndergroundState>('/api/v1/me/underground'));
        cooldownNowMs.value = Date.now();
        if (returnIfTutorialAlreadyFinished && current.stage === 'returned_after_tutorial') {
            emit('returnToSecretary');
            return;
        }
        if (current.stage === 'underground_open') await loadBattles();
    });
    stateRefreshQueue = pending.catch(() => {});
    return pending;
}

function hasMarketAttention(attention: MarketAttention | null | undefined): boolean {
    return (attention?.unclaimed_count ?? 0) + (attention?.unseen_expired_count ?? 0) > 0;
}
const marketAttentionVisible = computed(() => hasMarketAttention(state.value?.market_attention));
async function refreshMarketAttention(): Promise<void> {
    try {
        const current = await api<UndergroundState>('/api/v1/me/underground', { cache: 'no-store' });
        if (state.value && current.market_attention) state.value = { ...state.value, market_attention: current.market_attention };
    } catch {
        // The red dot is a convenience; the market screen itself shows the latest listings.
    }
}
function applyMarketBalance(carried: number): void {
    if (state.value) state.value = { ...state.value, shard_balance: carried };
}
function tabHasDot(key: View): boolean {
    return (key === 'polishing' && !!state.value?.distorted_stone_reminder) || (key === 'market' && marketAttentionVisible.value);
}

function refreshDistortedStoneReminder(): Promise<{ unclaimed: boolean; day: string; reset_after_ms: number; market_attention?: MarketAttention }> {
    const pending = stateRefreshQueue.then(async () => {
        const previousState = state.value;
        const current = await api<UndergroundState>('/api/v1/me/underground', { cache: 'no-store' });
        if (state.value && (state.value === previousState
            || (current.distorted_stone_shop && state.value.distorted_stone_shop
                && current.distorted_stone_shop.day > state.value.distorted_stone_shop.day))) {
            state.value = {
                ...state.value,
                distorted_stone_shop: current.distorted_stone_shop,
                distorted_stone_reminder: current.distorted_stone_reminder,
                market_attention: current.market_attention,
            };
        }
        const shop = state.value?.distorted_stone_shop ?? current.distorted_stone_shop;
        if (!shop) throw new Error('Distorted stone shop state is unavailable.');
        return {
            unclaimed: state.value?.distorted_stone_reminder ?? current.distorted_stone_reminder ?? false,
            day: shop.day,
            reset_after_ms: shop.reset_after_ms,
            market_attention: state.value?.market_attention ?? current.market_attention,
        };
    });
    stateRefreshQueue = pending.then(() => {}, () => {});
    return pending;
}

defineExpose({ refreshDistortedStoneReminder });

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
        const current = applyStateWithLatestStoneDay(await api<UndergroundState>(path, {
            method,
            body: JSON.stringify({ request_id: mutationRequestId, ...body }),
        }));
        cooldownNowMs.value = Date.now();
        selectedBattle.value = null;
        if (current.stage === 'returned_after_tutorial') {
            emit('returnToSecretary');
            return true;
        }
        if (current.stage === 'underground_open') {
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
    await runGuideConversation('start');
}

async function chooseGuideConversationReply(position: number): Promise<void> {
    const topic = guideConversation.value;
    if (busy.value || topic === null || guideConversationPhase.value !== 'topic') return;
    await runGuideConversation('reply', { topic_id: topic.topic_id, position });
}

async function punchGuide(): Promise<void> {
    if (busy.value || guideConversation.value === null) return;
    await runGuideConversation('punch');
}

async function runGuideConversation(action: GuideConversationAction, payload: Record<string, number> = {}): Promise<void> {
    if (busy.value) return;
    const pending = pendingGuideConversation.value ?? { action, payload, requestId: requestId() };
    if (pending.action !== action || JSON.stringify(pending.payload) !== JSON.stringify(payload)) {
        error.value = '前の会話の結果を確認してから、次の操作をしてください。';
        return;
    }
    pendingGuideConversation.value = pending;
    busy.value = true;
    error.value = '';
    guideMode.value = 'conversation';
    try {
        const path = `/api/v1/me/underground/guide-conversation/${action}`;
        const options = { method: 'POST', body: JSON.stringify({ request_id: pending.requestId, ...pending.payload }) };
        if (action === 'start') {
            const topic = await api<GuideConversationStart>(path, options);
            guideConversation.value = topic;
            guideConversationLine.value = topic.initial_line;
            guideConversationPhase.value = 'topic';
        } else if (action === 'reply') {
            const result = await api<GuideConversationReply>(path, options);
            guideConversationLine.value = result.reply_line;
            guideConversationPhase.value = 'reply';
        } else {
            const result = await api<GuideConversationPunch>(path, options);
            guideConversationLine.value = result.punch_line;
            guideConversationPhase.value = 'punch';
        }
        pendingGuideConversation.value = null;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '案内人との会話を更新できませんでした。';
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
    const previous = pendingRespecMutation.value;
    const bypassCooldownWithPd = previous?.growthPathKey === path.key
        ? previous.bypassCooldownWithPd : respecCooldownSeconds.value > 0;
    const fingerprint = JSON.stringify({ growth_path_key: path.key, bypass_cooldown_with_pd: bypassCooldownWithPd });
    const pending = pendingRespecMutation.value?.fingerprint === fingerprint
        ? pendingRespecMutation.value
        : { fingerprint, requestId: requestId(), growthPathKey: path.key, bypassCooldownWithPd };
    pendingRespecMutation.value = pending;
    if (await mutate('/api/v1/me/underground/respec', {
        growth_path_key: path.key, bypass_cooldown_with_pd: pending.bypassCooldownWithPd,
    }, pending.requestId)) {
        pendingRespecMutation.value = null;
        stpDraft.reset();
        pendingStpMutation.value = null;
        pendingSkillAcquire.value = null;
        pendingLoadoutMutation.value = null;
        loadoutDraft.value = state.value?.active_slots.map((slot) => slot?.key ?? null)
            ?? [null, null, null, null, null];
        selectedRespecPathKey.value = null;
        respecConfirmOpen.value = false;
    }
}

async function loadBattles(reset = true): Promise<void> {
    if (battleHistoryLoading.value || (!reset && !battleHistoryHasMore.value)) return;
    battleHistoryLoading.value = true;
    try {
        const cursor = reset ? null : battles.value.at(-1)?.history_cursor;
        const query = cursor === null || cursor === undefined
            ? ''
            : `?before_cursor=${encodeURIComponent(String(cursor))}`;
        const page = await api<Battle[]>(`/api/v1/me/underground/battles${query}`);
        if (reset) {
            battles.value = page;
        } else {
            const known = new Set(battles.value.map((battle) => battle.id));
            battles.value = [...battles.value, ...page.filter((battle) => !known.has(battle.id))];
        }
        battleHistoryHasMore.value = page.length === 20
            && Number.isInteger(page.at(-1)?.history_cursor);
    } finally {
        battleHistoryLoading.value = false;
    }
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
    const pending = pendingPlaytestRequest.value ?? {
        requestId: requestId(), buildKey: selectedBuild.value, enemyKey: selectedEnemy.value,
    };
    pendingPlaytestRequest.value = pending;
    innRested.value = false;
    busy.value = true;
    error.value = '';
    try {
        selectedBattle.value = await api<Battle>('/api/v1/me/underground/playtest', {
            method: 'POST',
            body: JSON.stringify({
                request_id: pending.requestId,
                build_key: pending.buildKey,
                enemy_key: pending.enemyKey,
            }),
        });
        pendingPlaytestRequest.value = null;
        await loadBattles();
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '力試しを開始できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function runExplore(huntingGroundKey: string, intentKey = 'selected-ground', otherworld = false): Promise<void> {
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
        otherworld,
    };
    pendingExplorationRequest.value = pending;
    busy.value = true;
    error.value = '';
    try {
        const battle = await api<Battle>(pending.otherworld ? '/api/v1/me/underground/otherworld/challenge' : '/api/v1/me/underground/explore', {
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
    if (groundKey === 'mad_moon') await runMadMoon();
    else await runExplore(groundKey);
}

async function runMadMoon(): Promise<void> {
    if (busy.value) return;
    const id = pendingMadMoonRequest.value ?? requestId();
    pendingMadMoonRequest.value = id;
    busy.value = true;
    error.value = '';
    try {
        const battle = await api<Battle>('/api/v1/me/underground/mad-moon', { method: 'POST', body: JSON.stringify({ request_id: id }) });
        showDailyQuestCompletion(battle.daily_quest);
        selectedBattle.value = battle;
        await refresh(false);
        pendingMadMoonRequest.value = null;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '狂月賛歌に挑戦できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function completeMadMoonScene(): Promise<void> {
    const victory = state.value?.mad_moon?.victory_pending;
    if (await loungeMutation('events/advance', { event: victory ? 'mad_moon_victory' : 'mad_moon', page: 1 })) {
        equipmentView.value = victory ? 'home' : 'adventure';
    }
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

function maximumHuntingGroundSkipExecutions(ground: HuntingGround): number {
    const keyLimit = ground.entry_key_cost > 0
        ? Math.floor(ground.key_balance / ground.entry_key_cost)
        : maximumBulkSkipExecutions;

    return Math.min(maximumSkipExecutions(ground.skip), keyLimit);
}

function customSkipExecutions(value: string, maximum: number): number | null {
    if (!/^\d+$/.test(value)) return null;
    const count = Number(value);

    return Number.isSafeInteger(count) && count >= 1 && count <= maximum ? count : null;
}

// The counts each button in the skip dialog would run for the selected target.
interface SkipPlan {
    contentType: 'hunting_ground' | 'trial';
    contentKey: string;
    disabled: boolean;
    half: number;
    maximum: number;
    custom: number | null;
}

const huntingGroundSkipPlan = computed<SkipPlan | null>(() => {
    const ground = selectedSkipHuntingGround.value;
    if (!ground) return null;
    const maximum = maximumHuntingGroundSkipExecutions(ground);

    return {
        contentType: 'hunting_ground',
        contentKey: ground.key,
        disabled: ground.disabled || skipDisabled(ground.skip),
        half: Math.floor(maximum * 0.5),
        maximum,
        custom: customSkipExecutions(customHuntingGroundSkipCount.value, maximum),
    };
});

const trialSkipPlan = computed<SkipPlan | null>(() => {
    const trial = selectedSkipTrial.value;
    if (!trial) return null;
    const maximum = maximumSkipExecutions(trial.skip);

    return {
        contentType: 'trial',
        contentKey: trial.key,
        disabled: skipDisabled(trial.skip),
        half: Math.floor(maximum * 0.5),
        maximum,
        custom: customSkipExecutions(customTrialSkipCount.value, maximum),
    };
});

function skipCountUnavailable(plan: SkipPlan, count: number | null): boolean {
    return plan.disabled || count === null || count < 1 || skipIntentBlocked(plan.contentType, plan.contentKey, count);
}

async function runPlannedSkip(plan: SkipPlan, count: number | null): Promise<void> {
    if (count === null) return;
    await runSkip(plan.contentType, plan.contentKey, count);
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

async function confirmStp(): Promise<void> {
    const allocations = stpDraft.allocations();
    if (Object.keys(allocations).length === 0) return;
    const fingerprint = JSON.stringify(allocations);
    const pending = pendingStpMutation.value?.fingerprint === fingerprint
        ? pendingStpMutation.value
        : { fingerprint, requestId: requestId() };
    pendingStpMutation.value = pending;
    if (await mutate('/api/v1/me/underground/status/stp', { allocations }, pending.requestId)) {
        stpDraft.reset();
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
    applyStateWithLatestStoneDay(result as UndergroundState);
    cooldownNowMs.value = Date.now();
}

onMounted(() => {
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
            <UndergroundBattleLog :battle="currentBattle" :fallback-player-name="state.secretary_name ?? '秘書'" />

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
                    v-if="repeatableExplorationGroundKey && !activeMadMoonStory"
                    class="button primary underground-exploration-repeat"
                    type="button"
                    :disabled="busy || exploreCooldownSeconds > 0"
                    @click="repeatCurrentExploration"
                >
                    もう一度ここを探索する<small v-if="exploreCooldownSeconds > 0">あと{{ exploreCooldownSeconds }}秒</small>
                </button>
                <button class="button secondary underground-battle-back" type="button" @click="closeBattle">{{ activeMadMoonStory ? '物語へ進む' : '地下メインへ戻る' }}</button>
            </div>
        </template>

        <template v-else-if="state && activeMadMoonStory">
            <UndergroundScene :scene="state.visuals?.scenes[state.mad_moon?.victory_pending ? 'yunagi-harbor-mad-moon-victory' : 'yunagi-harbor-mad-moon']" :show-ai="state.visuals?.show_ai ?? false" />
            <section class="ug-page-content ug-event-story" aria-label="狂月賛歌の物語">
                <h2>{{ activeMadMoonStory.title }}</h2>
                <p v-for="(line, index) in activeMadMoonStory.body" :key="index" v-html="renderUndergroundStory(line)"></p>
                <button v-if="state.mad_moon?.intro_pending" class="ug-primary" type="button" :disabled="busy || !state.mad_moon.can_fight" @click="runMadMoon">戦う</button>
                <button class="button secondary" type="button" :disabled="busy" @click="completeMadMoonScene">{{ state.mad_moon?.victory_pending ? 'ホームへ' : '撤退する' }}</button>
            </section>
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
                <nav v-if="pageTabs.length && !activeLoungeEvent && !(selectedRecollection && (equipmentView === 'recollections' || guideMode === 'recollections' && equipmentView === 'guide'))" class="ug-tabs" aria-label="画面内の切り替え">
                    <button v-for="tab in pageTabs" :key="tab.key" type="button" :class="{ 'notification-anchor': tabHasDot(tab.key) }" :aria-current="equipmentView === tab.key ? 'page' : undefined" @click="selectTab(tab.key)">{{ tab.label }}<span v-if="tabHasDot(tab.key)" class="notification-dot" aria-hidden="true" /></button>
                </nav>
                <template v-if="activeLoungeEvent">
                    <UndergroundScene :scene="state.visuals?.scenes[activeLoungeEvent.scene]" :show-ai="state.visuals?.show_ai ?? false" />
                    <section class="ug-page-content ug-event-story" aria-label="物語">
                        <h2>{{ activeLoungeEvent.title }}</h2>
                        <p v-for="(line, index) in activeLoungeEvent.body" :key="index">{{ line }}</p>
                        <button class="ug-primary" type="button" :disabled="busy" @click="advanceLounge">{{ loungeReplay ? '回想に戻る' : activeLoungeEvent.choice }}</button>
                    </section>
                </template>
                <template v-else-if="selectedRecollection && (equipmentView === 'recollections' || guideMode === 'recollections' && equipmentView === 'guide')">
                    <UndergroundScene :scene="state.visuals?.scenes[selectedRecollection.scene ?? 'villa']" :show-ai="state.visuals?.show_ai ?? false" />
                    <section class="ug-page-content ug-event-story underground-recollection-detail" aria-live="polite" aria-label="回想">
                        <h2>{{ selectedRecollection.title }}</h2>
                        <p v-for="(line, index) in selectedRecollection.body ?? []" :key="`${selectedRecollection.key}-${index}`" v-html="renderUndergroundStory(line)"></p>
                        <button
                            v-if="selectedRecollection.kind === 'past' && !selectedRecollection.completed"
                            class="ug-primary button primary" type="button"
                            :disabled="busy || selectedRecollection.locked"
                            @click="completeRecollection(selectedRecollection)"
                        >
                            読み終えた
                        </button>
                        <button type="button" :disabled="busy" @click="selectedRecollectionKey = null">回想に戻る</button>
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
                        :inn-cost="state.inn_cost"
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
                                <button class="ug-primary" type="button" :disabled="busy || innResting || Boolean(state.trial?.active_run)" @click="restAtInn">{{ innResting ? '休憩中…' : `宿で休む（${state.inn_cost.toLocaleString('ja-JP')}G）` }}</button>
                                <p v-if="innRested" role="status">HPが全回復しました。</p>
                                <p v-if="state.trial?.active_run" class="ug-muted">封印の地から帰還後に利用できます。</p>
                            </div>
                            <p v-if="equipmentView === 'party'" class="ug-exchange-greeting">{{ exchangeGreeting }}</p>
                            <section v-if="equipmentView === 'merchant'" class="ug-merchant-conversation" aria-label="行商人と話す">
                                <p class="ug-exchange-greeting">{{ loungeStories.merchant_conversation.greeting }}</p>
                                <p v-if="merchantLoading" role="status">話題を読み込んでいます…</p>
                                <div v-else-if="merchantError" role="alert">
                                    <p>{{ merchantError }}</p>
                                    <button type="button" @click="loadMerchantTopics">もう一度読み込む</button>
                                </div>
                                <p v-else-if="merchantTopics.length === 0">話題がまだ登録されていません。</p>
                                <div v-else class="ug-merchant-topics">
                                    <details v-for="topic in merchantTopics" :key="topic.id" class="ug-merchant-topic">
                                        <summary>{{ topic.question }}</summary>
                                        <div class="ug-merchant-answer">
                                            <p v-for="(line, index) in topic.answer.split('\n')" :key="index">{{ line }}</p>
                                        </div>
                                    </details>
                                </div>
                            </section>
                            <section v-if="equipmentView === 'polishing' && state.distorted_stone_shop?.unlocked" class="ug-property-item" aria-label="歪んだ輝石">
                                <header><h2>歪んだ輝石</h2><span>所持 {{ state.distorted_stone_shop.balance.toLocaleString('ja-JP') }} 個</span></header>
                                <p>異世界の勝利1回につき1個使います。敗北・時間切れでは消費しません。試練2クリア後に案内人から受け取れます。</p>
                                <p>本日 {{ state.distorted_stone_shop.purchased_today }} / {{ state.distorted_stone_shop.daily_limit }} 個受取・購入（日本時間0時更新）</p>
                                <button
                                    v-if="state.distorted_stone_shop.next_price !== null" type="button" class="ug-primary"
                                    :disabled="busy || Boolean(pendingLoungeMutation) || state.shard_balance < state.distorted_stone_shop.next_price"
                                    @click="loungeMutation('shop/distorted-stone', { price: state.distorted_stone_shop.next_price })"
                                >
                                    {{ state.distorted_stone_shop.next_price === 0 ? '1個受け取る（無料）' : `1個購入する（${state.distorted_stone_shop.next_price.toLocaleString('ja-JP')} G）` }}
                                </button>
                                <strong v-else class="ug-sold-out">本日売り切れ</strong>
                            </section>
                            <UndergroundEquipmentShop v-if="equipmentView === 'shop'" @updated="applyEquipmentMutation" />
                            <UndergroundMarket v-if="equipmentView === 'market'" :attention="state.market_attention" :initial-pane="marketInitialPane" @balance="applyMarketBalance" @attention="refreshMarketAttention" />
                            <UndergroundEquipmentPolishing v-if="equipmentView === 'polishing'" :balance="state.shard_balance" @updated="applyEquipmentMutation" />
            <section v-if="equipmentView === 'guide' || equipmentView === 'recollections' && state.residence?.villa_owned" class="underground-guide-room" aria-label="案内人">
                <div v-if="equipmentView === 'guide'" class="underground-guide-actions">
                    <button
                        type="button"
                        :aria-pressed="guideMode === 'conversation'"
                        :disabled="busy || pendingGuideConversation !== null"
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
                <p v-if="pendingGuideConversation && !busy" role="status">
                    会話の結果を確認できていません。
                    <button type="button" @click="runGuideConversation(pendingGuideConversation.action, pendingGuideConversation.payload)">前の会話の結果を確認する</button>
                </p>
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
                            :disabled="busy || pendingGuideConversation !== null"
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
                        <template v-if="equipmentView === 'recollections'">
                            <li v-if="(state.residence?.exchange_intro_page ?? 0) >= 1"><button type="button" @click="loungeReplay = 'exchange-1'">{{ loungeStories.exchange[0]!.title }}</button></li>
                            <li v-if="(state.residence?.exchange_intro_page ?? 0) >= 2"><button type="button" @click="loungeReplay = 'exchange-2'">{{ loungeStories.exchange[1]!.title }}</button></li>
                            <li v-if="state.residence?.mirror_event_completed"><button type="button" @click="loungeReplay = 'mirror'">{{ loungeStories.mirror.title }}</button></li>
                            <li v-if="state.otherworld_unlocked"><button type="button" @click="loungeReplay = 'otherworld'">{{ otherworldStory.title }}</button></li>
                        </template>
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
                    <p class="underground-guide-conversation">読める記録を選んでください。</p>
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
                        <p v-if="state.respec">手持ち {{ state.shard_balance }} G・{{ state.respec.paradox_balance }} Pd</p>
                    </header>
                    <div v-if="state.respec" class="underground-respec-explanations">
                        <p>SP・STP・成長方針を再設定します。</p>
                        <p>輝石のかけらが Lv × 10 G 必要です。</p>
                        <p>通常は一度行うと24時間待機します。待機中も10Pdを追加して再振りできます。</p>
                    </div>
                    <template v-if="state.respec">
                        <dl class="underground-respec-summary">
                            <div><dt>今回の費用</dt><dd>{{ state.respec.cost }} G</dd></div>
                            <div><dt>次回利用</dt><dd>{{ respecCooldownSeconds > 0 ? `あと${respecCooldownSeconds}秒` : '現在利用可能' }}</dd></div>
                            <div v-if="respecCooldownSeconds > 0"><dt>待ち時間解除</dt><dd>{{ state.respec.cooldown_bypass_cost_pd }} Pd</dd></div>
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
            <UndergroundEquipmentVault v-if="equipmentView === 'vault'" @updated="applyEquipmentMutation" @market="openMarketSell" />


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
                        <dl><div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ state.combat_stats?.[key] ?? state.growth_path.stats[key] }}</dd></div></dl>
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
                            <label for="underground-bank-amount">{{ state.bank_transfer_unit.toLocaleString('ja-JP') }}G単位の金額</label>
                            <input id="underground-bank-amount" v-model.number="bankAmount" type="number" :min="state.bank_transfer_unit" :step="state.bank_transfer_unit" :disabled="busy">
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
                                <button type="button" :disabled="busy" @click="openSkipModal">スキップ使用</button>
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
                                <button class="button primary underground-explore-button" type="button" :disabled="busy || exploreCooldownSeconds > 0 || Boolean(state.trial?.active_run) || !selectedHuntingGround || selectedHuntingGround.disabled" @click="runSelectedExploration">探索する</button>
                                <small v-if="selectedHuntingGround && selectedHuntingGround.key !== 'mad_moon'">装備 Item Lv {{ selectedHuntingGround.item_level_min }}～{{ selectedHuntingGround.item_level_max }}</small>
                                <small v-if="exploreCooldownSeconds > 0">次の出発まであと{{ exploreCooldownSeconds }}秒</small>
                                <small v-else-if="state.trial?.active_run">進行中の試練から帰還すると探索できます。</small>
                                <small v-else-if="selectedHuntingGround?.key !== 'mad_moon'">現在のPT {{ 1 + confirmedPartyIds.length }} / 4で出発します。</small>
                            </section>
                            <section v-if="equipmentView === 'trials'" class="underground-adventure-block" aria-labelledby="underground-trial-title">
                                <h3 id="underground-trial-title">試練</h3>
                                <p v-if="state.trial?.upcoming_story" class="underground-trial-intro" v-html="renderUndergroundStory(state.trial.upcoming_story)"></p>
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
                                <div v-for="vault in unlockedVaults" :key="vault.key" class="underground-vault-entry">
                                    <strong>{{ vault.name }}</strong>
                                    <button class="button primary" type="button" :disabled="busy || exploreCooldownSeconds > 0 || Boolean(state.trial?.active_run) || vault.disabled" @click="runExplore(vault.key, 'vault:' + vault.key)">挑戦する</button>
                                    <small>装備 Item Lv {{ vault.item_level_min }}～{{ vault.item_level_max }}</small>
                                    <small>{{ vault.key_label }}：所持 {{ vault.key_balance }}個・1回につき{{ vault.entry_key_cost }}個消費</small>
                                    <small v-if="vault.disabled">{{ vault.unavailable_reason }}</small>
                                    <small v-if="exploreCooldownSeconds > 0">次の出発まであと{{ exploreCooldownSeconds }}秒</small>
                                </div>
                                <small v-if="unlockedVaults.length === 0">試練2を初回clearすると解禁されます。</small>
                            </section>
                        </div>
                        <button v-if="state.trial?.active_run" class="button secondary" type="button" :disabled="busy" @click="withdrawTrial">封印の地から帰還する</button>
                    </section>

                    <section v-if="equipmentView === 'otherworld' && state.otherworld" class="underground-playtest" aria-labelledby="underground-otherworld-title">
                        <h2 id="underground-otherworld-title">異世界の戦い</h2>
                        <p>黒竜バハムルが、輝きの王国を脅かしています。</p>
                        <p>現在のHPと覚醒ゲージを引き継いで挑みます。同行者はパーティー画面で確定してください。</p>
                        <label for="underground-otherworld-stage">挑戦する段階</label>
                        <select id="underground-otherworld-stage" v-model="selectedOtherworldStage" :disabled="busy || Boolean(pendingExplorationRequest)">
                            <option v-for="stage in state.otherworld.stages" :key="stage.key" :value="stage.key" :disabled="stage.locked">{{ stage.name }}{{ stage.cleared ? '（クリア済み）' : '' }}</option>
                        </select>
                        <p v-if="otherworldStage">4人推奨・Lv {{ otherworldStage.recommended_level }} 前後／報酬 Item Lv {{ otherworldStage.item_level }}</p>
                        <p>勝利で黒竜晶を1個獲得。まれに黒竜の武器も獲得します。歪んだ輝石が1個あれば挑戦でき、勝利時に1個消費します（所持 {{ state.otherworld.distorted_stone_balance }}個）。</p>
                        <p v-if="pendingExplorationRequest" role="status">前回の戦闘結果が未確認です。同じ段階・同行者で結果を確認します。</p>
                        <button class="button primary" type="button" :disabled="busy || (!pendingExplorationRequest && (!otherworldStage || otherworldStage.locked || Boolean(state.trial?.active_run) || state.otherworld.distorted_stone_balance < 1))" @click="runExplore(selectedOtherworldStage, 'otherworld', true)">{{ pendingExplorationRequest ? '戦闘結果を確認する' : '黒竜バハムルに挑む' }}</button>
                        <p v-if="state.trial?.active_run">試練から帰還してから挑戦してください。</p>
                    </section>

                    <section v-if="equipmentView === 'playtest' && state.playtest" class="underground-playtest" aria-labelledby="underground-playtest-title">
                        <h2 id="underground-playtest-title">力試し（α）</h2>
                        <p>{{ state.playtest.notice }}</p>
                        <label for="underground-build">完成形ビルド</label>
                        <select id="underground-build" v-model="selectedBuild" :disabled="busy || pendingPlaytestRequest !== null"><option v-for="build in state.playtest.builds" :key="build.key" :value="build.key">{{ build.label }} — {{ build.description }}</option></select>
                        <label for="underground-enemy">対戦相手</label>
                        <select id="underground-enemy" v-model="selectedEnemy" :disabled="busy || pendingPlaytestRequest !== null"><option v-for="enemy in state.playtest.enemies" :key="enemy.key" :value="enemy.key">{{ enemy.label }} — {{ enemy.description }}</option></select>
                        <button class="button primary" type="button" :disabled="busy || !selectedBuild || !selectedEnemy" @click="runPlaytest">{{ pendingPlaytestRequest ? '力試しの結果を確認する' : '戦闘開始' }}</button>
                        <p>報酬なし: XP 0・輝石の欠片 0G・ドロップなし。敗北ペナルティもありません。</p>
                    </section>

                    <section v-if="equipmentView === 'history'" class="underground-history" aria-labelledby="underground-history-title">
                        <h2 id="underground-history-title">戦闘履歴</h2>
                        <ul><li v-for="battle in battles" :key="battle.id"><button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battleRoundCount(battle) }}ラウンド</button></li></ul>
                        <p v-if="battles.length === 0 && !battleHistoryLoading" class="ug-muted">表示できる戦闘履歴はありません。</p>
                        <button v-if="battleHistoryHasMore" type="button" :disabled="battleHistoryLoading" @click="loadBattles(false)">{{ battleHistoryLoading ? '読み込み中…' : '過去の履歴を読み込む' }}</button>
                    </section>

                            <UndergroundResidence v-if="(equipmentView === 'property' || equipmentView === 'villa' || equipmentView === 'trophies') && state.residence" :mode="equipmentView" :residence="state.residence" :busy="busy" :shards="state.shard_balance" @purchase="loungeMutation('residence/purchase', { item: $event })" @property="equipmentView = 'property'" />
                            <p v-if="equipmentView === 'recollections' && !state.residence?.villa_owned">別荘を購入すると回想を読めます。</p>
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
                        <template v-if="selectedSkipHuntingGround && huntingGroundSkipPlan">
                            <p>1回 = {{ selectedSkipHuntingGround.skip.ticket_cost }}枚・実戦 {{ selectedSkipHuntingGround.skip.actual_clear_count }} / {{ selectedSkipHuntingGround.skip.actual_clears_required }}勝</p>
                            <p v-if="selectedSkipHuntingGround.entry_key_cost > 0">さらに鍵{{ selectedSkipHuntingGround.entry_key_cost }}個 / 回（所持 {{ selectedSkipHuntingGround.key_balance }}個）</p>
                            <p v-if="!selectedSkipHuntingGround.skip.unlocked" class="field-hint">実戦clearがあと{{ selectedSkipHuntingGround.skip.actual_clears_required - selectedSkipHuntingGround.skip.actual_clear_count }}回必要です。</p>
                            <div class="underground-skip-shortcuts">
                                <button type="button" :disabled="skipCountUnavailable(huntingGroundSkipPlan, huntingGroundSkipPlan.half)" @click="runPlannedSkip(huntingGroundSkipPlan, huntingGroundSkipPlan.half)">50%使用（{{ huntingGroundSkipPlan.half }}回）</button>
                                <button type="button" :disabled="skipCountUnavailable(huntingGroundSkipPlan, huntingGroundSkipPlan.maximum)" @click="runPlannedSkip(huntingGroundSkipPlan, huntingGroundSkipPlan.maximum)">100%使用（{{ huntingGroundSkipPlan.maximum }}回）</button>
                            </div>
                            <div class="underground-skip-custom">
                                <label>任意回数
                                    <input v-model="customHuntingGroundSkipCount" inputmode="numeric" type="number" min="1" :max="huntingGroundSkipPlan.maximum" step="1" :disabled="huntingGroundSkipPlan.disabled">
                                </label>
                                <button type="button" :disabled="skipCountUnavailable(huntingGroundSkipPlan, huntingGroundSkipPlan.custom)" @click="runPlannedSkip(huntingGroundSkipPlan, huntingGroundSkipPlan.custom)">指定回数を使用</button>
                            </div>
                            <p v-if="huntingGroundSkipPlan.custom !== null" class="underground-skip-plan">予定消費：🎫 {{ huntingGroundSkipPlan.custom * selectedSkipHuntingGround.skip.ticket_cost }}枚<span v-if="selectedSkipHuntingGround.entry_key_cost > 0">・鍵 {{ huntingGroundSkipPlan.custom * selectedSkipHuntingGround.entry_key_cost }}個</span></p>
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
                        <template v-if="selectedSkipTrial && trialSkipPlan">
                            <p>1周（{{ selectedSkipTrial.total_battles }}連戦）= {{ selectedSkipTrial.skip.ticket_cost }}枚・実戦 {{ selectedSkipTrial.skip.actual_clear_count }} / {{ selectedSkipTrial.skip.actual_clears_required }}周</p>
                            <p v-if="!selectedSkipTrial.skip.unlocked" class="field-hint">実戦clearがあと{{ selectedSkipTrial.skip.actual_clears_required - selectedSkipTrial.skip.actual_clear_count }}周必要です。</p>
                            <div class="underground-skip-shortcuts">
                                <button type="button" :disabled="skipCountUnavailable(trialSkipPlan, trialSkipPlan.half)" @click="runPlannedSkip(trialSkipPlan, trialSkipPlan.half)">50%使用（{{ trialSkipPlan.half }}周）</button>
                                <button type="button" :disabled="skipCountUnavailable(trialSkipPlan, trialSkipPlan.maximum)" @click="runPlannedSkip(trialSkipPlan, trialSkipPlan.maximum)">100%使用（{{ trialSkipPlan.maximum }}周）</button>
                            </div>
                            <div class="underground-skip-custom">
                                <label>任意周回数
                                    <input v-model="customTrialSkipCount" inputmode="numeric" type="number" min="1" :max="trialSkipPlan.maximum" step="1" :disabled="trialSkipPlan.disabled">
                                </label>
                                <button type="button" :disabled="skipCountUnavailable(trialSkipPlan, trialSkipPlan.custom)" @click="runPlannedSkip(trialSkipPlan, trialSkipPlan.custom)">指定周回数を使用</button>
                            </div>
                            <p v-if="trialSkipPlan.custom !== null" class="underground-skip-plan">予定消費：🎫 {{ trialSkipPlan.custom * selectedSkipTrial.skip.ticket_cost }}枚</p>
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
                            <div v-if="(lastSkipResult.rewards?.distorted_stones ?? 0) > 0"><dt>歪んだ輝石</dt><dd>+{{ lastSkipResult.rewards?.distorted_stones }}個</dd></div>
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

            <UndergroundStatusAllocation
                v-if="equipmentView === 'status' && state.status_breakdown"
                :breakdown="state.status_breakdown"
                :unspent-stp="state.unspent_stp"
                :draft="stpDraft"
                :busy="busy"
                @confirm="confirmStp"
            />

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
                    <p v-if="respecCooldownSeconds > 0" class="underground-respec-destructive">待ち時間を解除するため、さらに{{ state.respec.cooldown_bypass_cost_pd }} Pdを消費します。</p>
                    <p class="underground-respec-destructive">この操作は取り消せません。実行後の通常の再振りには24時間の待ち時間が付きます。</p>
                    <footer>
                        <button class="button secondary" type="button" :disabled="busy" @click="respecConfirmOpen = false">キャンセル</button>
                        <button class="button primary" type="button" :disabled="busy || respecUnavailable" @click="confirmRespec">{{ respecCooldownSeconds > 0 ? '10Pdで待ち時間を解除して再振り' : '再振りを実行する' }}</button>
                    </footer>
                </section>
            </div>
                <UndergroundNavigation :current="currentDestination" :exchange-discovered="(state.residence?.exchange_intro_page ?? 0) >= 2" :distorted-stone-reminder="state.distorted_stone_reminder" :market-attention="marketAttentionVisible" @navigate="navigate" />
            </div>
        </template>
    </section>
</template>
