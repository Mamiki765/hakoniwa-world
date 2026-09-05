<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import UndergroundAiEditor from './UndergroundAiEditor.vue';
import UndergroundCombatantCard from './UndergroundCombatantCard.vue';
import UndergroundEquipmentShop from './UndergroundEquipmentShop.vue';
import UndergroundEquipmentVault from './UndergroundEquipmentVault.vue';
import type { EquipmentItem, EquipmentSlot } from './EquipmentItemCard.vue';
import type { UndergroundAiConfiguration } from './undergroundAi';

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
    actor_name?: string;
    target_name?: string | null;
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
}

interface CombatRound {
    round: number;
    actions: RoundAction[];
    end_state: { player: RoundState; enemy: RoundState } | null;
}

interface Battle {
    id: string;
    context: 'tutorial' | 'scripted_loss' | 'playtest' | 'exploration' | 'trial';
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
    summary?: Record<string, boolean | number | string> | null;
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
            rarity: 'common' | 'uncommon' | 'rare' | 'epic';
            rarity_label: string;
            affixes: Array<{ key: string; label: string; target: string; value: number }>;
        };
    } | null;
}

interface HuntingGround {
    key: string;
    name: string;
    locked: boolean;
    unlock_condition: string | null;
    item_level_min: number;
    item_level_max: number;
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
}

interface AwakeningState {
    identity: string;
    unlocked: boolean;
    current: number;
    maximum: number;
    custom_message: string | null;
    default_message: string;
    technique: {
        key: string;
        name: string;
        summary: string;
        consumes_action: boolean;
    } | null;
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

interface SkillNode {
    key: string;
    label: string;
    summary: string;
    type: 'active' | 'passive';
    rank: number;
    max_rank: number;
    point_cost: number;
    invested_points_required: number;
    prerequisite: string | null;
    can_acquire: boolean;
    unavailable_reason: string | null;
    skill_key: string | null;
    mp_cost: number | null;
    cooldown: number | null;
    required_weapon_styles: string[];
    recommended_stats: StatKey[] | null;
    active_slot: number | null;
}

interface SkillTree {
    key: string;
    label: string;
    invested_points: number;
    full_points: number;
    nodes: SkillNode[];
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

interface PendingExplorationRequest {
    requestId: string;
    huntingGroundKey: string;
    intentKey: string;
}

interface UndergroundState {
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
    ai?: UndergroundAiConfiguration | null;
    battle: Battle | null;
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

const initialDescent = [
    'あなたの秘書は、暗く狭い場所で目を覚ました。',
    '身体が痛むが、幸い重症ではないようだ。ゆっくりと身体を起こし、何があったかを思い出す。',
    '秘書はあなたと、首都に核シェルターを作るための工事をしていた。',
    '有事の際に身を守るために、ある程度の住居や農場を地下深くに作る計画。',
    '輝石と呼ばれる、不思議な性質を持つことが最近判明した宝石の鉱脈が首都直下に見つかったことも、その理由の一つだった。',
    '何故かは自分でもわからないが、秘書はその宝石から不思議な力を解放することができた。',
    '大量の輝石と数週間ほどの休憩が必要だが、その力を弓矢に込めれば怪獣すら穿つほどに。',
    'だが、計画は難航していた。',
    '地下には巨大な空洞も輝石の鉱脈もあった。まるで世界全体で繋がっているかと思うほどの。',
    'しかし同時にあらゆる機械や人を通さない、輝く膜のようなものがその入り口に広がっていたのだ。',
    '穴の周囲に集まり対処に頭を悩ませていたその時、災害による衝撃波があなた達を襲う。',
    '何人かがバランスを崩し、その穴に落ちて——',
    '秘書だけが、光の膜の向こう側へと突き抜けてしまったのだ。',
    '秘書は改めて状況を頭の中で整理する。',
    '酸素は十分にある。視界も良好、壁や床全体が弱く光を放っており、夜目があればネズミ一匹見逃す心配もない。',
    '地盤は非常に硬く、崩落は心配なさそうだ。',
    'ただ、何かが確実にいる気配がする。',
    '怪獣ほどではないが、危険な怪物が。進めば交戦は避けられない。',
    '怪物がどこにいるかわからない空間では大量の輝石と狙いを定める時間を必要とする弓は使えない。',
    '護身用に身につけていたナイフ一つで、何とかここを脱出しなければならない。',
    'まずは、あの膜のところへ戻ろう。どちらに進めばそれがあるかはわかる。片道通行でなければ、あそこから外に出られるはずだ。',
    'そこにさえいけば、地上に戻る方法はいくらでもある、あの人達が自分を待っているはずだ、とも。',
    '冷静に判断し、秘書は怪物と遭遇する覚悟を決めた。',
    'ナイフを構えた瞬間、秘書は今までに感じたことのない大量の輝石の気配を感じた。',
    'そして、自分の心臓がドクンと、強く脈打つのを感じた。',
];

const tutorialAftermath = [
    'ジャイアントラットは甲高い断末魔を放つと横に倒れ、動かなくなった。',
    '息を整えた秘書は、偶然ラットの身体に巨大な輝石が埋め込まれていることに気がついた。',
    '少し躊躇こそしたが、地底の不思議な環境を解析する有力な情報になるということで、採取することにした。',
    '・　・　・　・　・　・',
    '見事な輝石だ。これを使えば、いったいどのような魔法を使えるようになるだろうか。',
    '少しばかり興味心が疼いたが、この狭い空間で放つには身の安全がどうあがいても保証できそうにない……',
    '結界の向こう側の危険な敵、敵が持っていた輝石。この非常事態には、それだけの情報があれば十分だろう。',
    '見上げてみれば、秘書が落ちたであろう光の膜は頭上に広がって見える。秘書の名前を何度も呼び掛ける、島民たちの声も聞こえる。',
    'これ以上長居は不要だろう。秘書はバリアが一方通行でないことを祈りながら、土壁に杭代わりのナイフを突き立てるのであった――',
    '「へぇ……あれはまた使えそうですね……でもこんな危険な場所に二度と戻ってきたらダメですよー？」',
];

const encounterStory = [
    '「また来ましたね」',
    '再び地下に降り立ったあなたを待っていたのは、一人の女性であった。',
    'その顔には皺一つなく、薄暗い地底でも二十歳を超えない若い女性にも見える、銀髪の女性。',
    '彼女は目を細めながら、くすくすと笑っていた。',
    '「自殺願望があるわけじゃあないでしょうに……ここに来るなんてさぞ嬉しかったんですね、あの宝石がこんなにあるなんて。見てましたよー、穴の上で必死に工事して、地下の探索基地を作る人々の姿も。」',
    '『あの宝石』とは間違いなく、輝石のことだろう。',
    '「おお、既に着目していたとは流石お目が高いです！　それは嬉しいばかりで……いえいえ、アレの本質にたどり着いても、魔力を実際に引き出せる人はほとんどいませんでしたから」',
    'そういうと女性はすっと、ポケットから輝石を3つ取り出してみせた。',
    '「これは時間？　空間……まぁ、世界そのものとでも言いますか。とにかく凄いものが込められた石(メアリー・スー)なのです。ささ、まずはお一つ好きなものをとってください」',
    '「私は……まぁ、案内人とでも呼んでください。しがない店員。あなたのいう、輝石を求めて地下に降りてきたあなただけを相手にする、店員とでも言いましょうか」',
    '「好きなように呼んでください、私はその名で呼ばれましょう」',
];

const trueNameBefore = [
    '「……リカ？　あなた、私にその名前をつけたの？」',
    '「単なる偶然？　読心術？　それとも何か地上でヘマした……？」',
    '「なんでよ……なんで……」',
    '「……理由を吐いてもらうわよ、なんであんた、私の名前を知ってんのよ？」',
];

const trueNameAfter = [
    '「……」',
    '「…………はぁ……」',
    '「命までは取らないであげる」',
    '「どうして本名を知っているのか拷問の一つや二つやって問いただしてあげたいけれど。あなたがいなきゃ、私が永遠に退屈なのは真実ですもの」',
    '「好きに呼びなさい？　長命種同士、仲良く、ね？」',
];

const shopStory = [
    '「……さて、私の役目を話しましょうか」',
    '「私は退屈しているのです。この空間はだだっ広い暗黒の中ですが、上は上で海しか広がってませんしね」',
    '「だから、私を楽しませてください。この魔物だらけの空間で、お宝をいっぱい見つけてください！」',
    '「あなたが使えないほど小さな輝石のかけらをいっぱい持ってきたら、私が練り固めて大きな輝石にしてあげましょう」',
    '「あるいは、あなたが地上から持ち込んできた武器に輝石を施して、地下の魔物に対抗できるものに加工してあげます」',
    '「もちろんやることに応じた加工料……つまり、端数程度のかけらは頂きますけどね」',
    '「輝石クズを地上に持って行っても使い道はないでしょう？　あなたにとっても悪い話じゃないと思いますが」',
    '「私と契約、しませんか？　拒否権はありませんけどね」',
];

const crystalOffer = [
    '「そうでしょう、そうでしょう？　そう来なくちゃです」',
    '「ではでは、初めてのお客様にサービスです。今お見せした輝石、そのどれかをあなたに一つプレゼントいたします」',
    '「これは私が特別に調整した一級品中の一級品です！　それぞれの輝石はあなたに特別な才を授けてくれる事でしょう」',
    '「ですがよーく考えてくださいね。交換したいなんて言われたら一杯欠片を請求するかもしれませんから……」',
];

const commonEnding = [
    '「では、後はご自由に過ごしてください」',
    '「地下の浅い層を探索するもよし、より深くを目指して潜ってくるのもよし」',
    '「手に入れた新しい力で大暴れしてストレス発散をするもよし、お宝を掘ってみるのも良し！」',
    '「もっとも、地下で得られる輝石は魔力の性質が違います。地上で役に立たないものがほとんどでしょう」',
    '「何よりいくら効果的とはいえ怪獣相手にナイフと技術で立ち向かうなんて、愚かにもほどがありますからねえ……弓の方が楽だしお似合いでしょう？　あなたには」',
    '「とはいえ、地下では逆に超超頼りになる戦略になってくれるはずです！」',
    '「ですが気をつけてくださいね。輝石は強大な力を与えます。それが何の見返りも要求しないとはとても思えません」',
    '「力と欲望に溺れてしまわないようにだけは、気を付けてくださいね……そうなれば、あなたの精神や肉体にもきっと何らかの変質が起こりかねませんから」',
];

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
const props = defineProps<{ secretaryImageUrl?: string | null }>();
const emit = defineEmits<{ returnToSecretary: [] }>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const error = ref('');
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const recentBattles = computed(() => battles.value.slice(0, 5));
const selectedBattle = ref<Battle | null>(null);
const selectedBuild = ref('');
const selectedEnemy = ref('');
const bankOpen = ref(false);
const bankAmount = ref<number | null>(1000);
const selectedHuntingGroundKey = ref('shallow_caves');
const pendingExplorationRequest = ref<PendingExplorationRequest | null>(null);
const pendingTrialRequest = ref<PendingTrialRequest | null>(null);
const pendingInnRequestId = ref<string | null>(null);
const pendingBankMutation = ref<PendingBankMutation | null>(null);
const statusOpen = ref(false);
const skillsOpen = ref(false);
const activeSkillTreeKey = ref('martial');
const innResting = ref(false);
const innRested = ref(false);
const stpDraft = ref<Record<StatKey, number>>({ vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 });
const loadoutDraft = ref<Array<string | null>>([null, null, null, null, null]);
const pendingStpMutation = ref<PendingMutation | null>(null);
const pendingSkillAcquire = ref<PendingMutation | null>(null);
const pendingLoadoutMutation = ref<PendingMutation | null>(null);
const pendingAwakeningMutation = ref<PendingMutation | null>(null);
const awakeningMessageDraft = ref('');
const equipmentView = ref<'main' | 'shop' | 'guide' | 'ai' | 'vault'>('main');
const guideMode = ref<'basic' | 'conversation' | 'respec'>('basic');
const selectedRespecPathKey = ref<string | null>(null);
const respecConfirmOpen = ref(false);
const pendingRespecMutation = ref<PendingMutation | null>(null);
const cooldownNowMs = ref(Date.now());
let cooldownTimer: ReturnType<typeof window.setInterval> | null = null;
let huntingGroundPreferenceHydrated = false;
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);
const unlockedHuntingGrounds = computed(() => (state.value?.hunting_grounds ?? [])
    .filter((ground) => !ground.locked));
const selectedHuntingGround = computed(() => unlockedHuntingGrounds.value
    .find((ground) => ground.key === selectedHuntingGroundKey.value) ?? null);
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
const finalBattleState = computed(() => [...currentStructuredRounds.value]
    .reverse()
    .find((round) => round.end_state !== null)?.end_state ?? null);
const growthEnding = computed(() => state.value?.growth_path?.key === 'free_black'
    ? '「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」'
    : '「ふふ、とってもお似合いですよ、その能力」');
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

watch(() => state.value?.active_slots, (slots) => {
    if (!slots || pendingLoadoutMutation.value) return;
    loadoutDraft.value = slots.map((slot) => slot?.key ?? null);
}, { deep: true, immediate: true });

watch(() => state.value?.skill_trees, (trees) => {
    if (!trees || trees.some((tree) => tree.key === activeSkillTreeKey.value)) return;
    activeSkillTreeKey.value = trees[0]?.key ?? 'martial';
}, { deep: true, immediate: true });

watch(() => state.value?.awakening, (awakening) => {
    if (!awakening || pendingAwakeningMutation.value) return;
    awakeningMessageDraft.value = awakening.custom_message ?? awakening.default_message;
}, { deep: true, immediate: true });

watch(() => state.value?.hunting_grounds, (grounds) => {
    if (!grounds) return;
    const unlocked = grounds.filter((ground) => !ground.locked);
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
    if (pendingExplorationRequest.value?.huntingGroundKey !== nextKey) {
        pendingExplorationRequest.value = null;
    }
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
    if (!unlockedHuntingGrounds.value.some((ground) => ground.key === key)) return;
    if (selectedHuntingGroundKey.value !== key) pendingExplorationRequest.value = null;
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
        if (caught instanceof ApiError && caught.status === 409) await refresh();
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

function openGuide(mode: 'basic' | 'conversation' | 'respec' = 'basic'): void {
    equipmentView.value = 'guide';
    guideMode.value = mode;
    if (mode !== 'respec') {
        selectedRespecPathKey.value = null;
        respecConfirmOpen.value = false;
    }
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
    const pending = currentPending?.huntingGroundKey === huntingGroundKey
        && currentPending.intentKey === intentKey
        ? currentPending
        : {
        requestId: requestId(),
        huntingGroundKey,
        intentKey,
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
            }),
        });
        await refresh(false);
        selectedBattle.value = battle;
        pendingExplorationRequest.value = null;
    } catch (caught) {
        if (caught instanceof ApiError && caught.status === 409) await refresh(false);
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

async function repeatCurrentExploration(): Promise<void> {
    const battle = currentBattle.value;
    const groundKey = repeatableExplorationGroundKey.value;
    if (!battle || !groundKey) return;
    selectHuntingGround(groundKey);
    await runExplore(groundKey, `repeat-battle:${battle.id}`);
}

async function runTrial(): Promise<void> {
    if (busy.value || !state.value?.trial) return;
    innRested.value = false;
    busy.value = true;
    error.value = '';
    try {
        let pending = pendingTrialRequest.value;
        if (!pending) {
            let run = state.value.trial.active_run;
            if (!run) {
                run = await api<TrialRun>('/api/v1/me/underground/trial/start', { method: 'POST' });
            }
            pending = { requestId: requestId(), runKey: run.run_key };
            pendingTrialRequest.value = pending;
        }
        const battle = await api<Battle>('/api/v1/me/underground/trial/fight', {
            method: 'POST',
            body: JSON.stringify({ run_key: pending.runKey, request_id: pending.requestId }),
        });
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
    const pending = pendingAwakeningMutation.value?.fingerprint === fingerprint
        ? pendingAwakeningMutation.value
        : { fingerprint, requestId: requestId() };
    pendingAwakeningMutation.value = pending;
    if (await mutate(
        '/api/v1/me/underground/awakening/message',
        { message: awakeningMessageDraft.value },
        pending.requestId,
        'PUT',
    )) {
        pendingAwakeningMutation.value = null;
        const awakening = state.value?.awakening;
        if (awakening) {
            awakeningMessageDraft.value = awakening.custom_message ?? awakening.default_message;
        }
    }
}

function nodeLabel(nodeKey: string | null): string {
    if (!nodeKey) return 'なし';
    return (state.value?.skill_trees ?? [])
        .flatMap((tree) => tree.nodes)
        .find((node) => node.key === nodeKey)?.label ?? '前提skill';
}

function orderedSkillNodes(nodes: SkillNode[]): SkillNode[] {
    return nodes
        .map((node, index) => ({ node, index }))
        .sort((left, right) => left.node.invested_points_required - right.node.invested_points_required
            || left.index - right.index)
        .map(({ node }) => node);
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

function recommendedStatsText(stats: StatKey[]): string {
    return stats.length === 0 ? 'ー' : stats.map((stat) => statLabels[stat]).join(' / ');
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

function visibleSummary(summary: Record<string, boolean | number | string>): Record<string, boolean | number | string> {
    return Object.fromEntries(Object.entries(summary).filter(([key]) => !hiddenSummaryKeys.has(key)));
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

function actionNarrative(action: RoundAction, battle: Battle): string {
    const actor = action.actor_name ?? actorName(action.side, battle);
    const target = action.target_name ?? targetName(action.side, battle);
    const amount = action.amount ?? 0;
    if (action.lines && action.lines.length > 0) return action.lines.join('\n');
    if (action.type === 'warning' || action.type === 'phase_transition') return action.label;
    if (action.type === 'action' || action.type === 'decision') return `${actor}は「${action.label}」を使用した。`;
    if (action.type === 'mp_cost') return `${actor}はMPを${amount}消費した。`;
    if (action.type === 'mp_recovery') return `${actor}はMPを${amount}回復した。`;
    if (action.type === 'counter') return `${actor}の反撃。${target}に${amount}ダメージ。`;
    if (action.type === 'guard') return `${actor}は防御態勢を取った。`;
    if (action.type === 'barrier') return `${actor}は「${action.label}」で障壁を${amount}得た。`;
    if (action.type === 'recovery') return `${actor}は「${action.label}」でHPを${amount}回復した。`;
    if (action.type === 'role_stack_gain' || action.type === 'role_stack_spent') {
        const role = withoutActionPrefix(action.label, action.type === 'role_stack_gain' ? '増加:' : '消費:');
        return `${actor}の${role}が${amount}${action.type === 'role_stack_gain' ? '増加' : '消費'}した。`;
    }
    if (action.type === 'status_applied') return `${target}に${withoutActionPrefix(action.label, '付与:')}が付与された。`;
    if (action.type === 'status_expired') return `${actor}の${withoutActionPrefix(action.label, '消滅:')}が消滅した。`;
    if (action.type === 'status_resisted') return `${target}は${withoutActionPrefix(action.label, '抵抗:')}を防いだ。`;
    if (action.type === 'status_removed') return `${actor}は状態効果を${amount}個解除した。`;
    if (action.type === 'damage') {
        const qualifiers = [action.critical ? '会心' : '', action.guarded ? '防御' : '', action.parried ? '受け流し' : '']
            .filter(Boolean).join('・');
        if (action.complete_guarded) return `${actor}の「${action.label}」。${target}は完全防御し、HPダメージは0。`;
        if (action.evaded) return `${actor}の「${action.label}」。${target}は回避した。`;
        const damage = amount > 0 ? `${target}に${amount}ダメージ。` : `${target}のHPダメージは0。`;
        const barrier = action.barrier_absorbed ? `障壁が${action.barrier_absorbed}吸収。` : '';
        return `${actor}の「${action.label}」。${qualifiers ? `${qualifiers}。` : ''}${damage}${barrier}`;
    }
    return `${actor}に「${action.label}」の効果。`;
}

function withoutActionPrefix(label: string, prefix: string): string {
    return label.startsWith(prefix) ? label.slice(prefix.length).trimStart() : label;
}

function actionTone(action: RoundAction): string {
    if (action.type === 'warning' || action.type === 'phase_transition') return 'is-warning';
    if (action.type === 'awakening' || action.type === 'awakening_technique') return 'is-awakening';
    if (action.type === 'recovery' || action.type === 'mp_recovery' || action.type === 'barrier') return 'is-recovery';
    if (action.critical) return 'is-critical';
    if (action.evaded || action.parried || action.complete_guarded || action.type === 'guard') return 'is-defense';
    if (action.type === 'damage' || action.type === 'counter') return 'is-impact';
    return 'is-neutral';
}

function actionHighlight(action: RoundAction): string | null {
    if (action.type === 'awakening' || action.type === 'awakening_technique') return '覚醒';
    if (action.critical) return '会心';
    if (action.type === 'recovery' || action.type === 'mp_recovery') return '回復';
    if (action.complete_guarded) return '完全防御';
    if (action.evaded) return '回避';
    if (action.parried) return '受け流し';
    if (action.type === 'warning' || action.type === 'phase_transition') return '警戒';
    return null;
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
    cooldownTimer = window.setInterval(() => { cooldownNowMs.value = Date.now(); }, 1_000);
    void enter();
});
onUnmounted(() => {
    if (cooldownTimer !== null) window.clearInterval(cooldownTimer);
});
</script>

<template>
    <section class="panel underground-panel" aria-live="polite">
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
                    <a class="underground-log-jump" href="#underground-battle-result">末尾へ</a>
                </header>

                <section v-if="finalBattleState" class="underground-matchup" aria-labelledby="underground-matchup-title">
                    <div class="underground-matchup-heading">
                        <p class="eyebrow">MATCHUP</p>
                        <h2 id="underground-matchup-title">最終ラウンド終了時</h2>
                    </div>
                    <div class="underground-matchup-grid">
                        <UndergroundCombatantCard
                            :name="currentPlayerDisplayName"
                            side="player"
                            :state="finalBattleState.player"
                            :image-url="secretaryImageUrl"
                        />
                        <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                        <UndergroundCombatantCard
                            :name="currentBattle.encounter_name"
                            side="enemy"
                            :state="finalBattleState.enemy"
                        />
                    </div>
                </section>

                <div class="underground-rounds">
                    <p v-if="currentBattle.detail_message" class="status">{{ currentBattle.detail_message }}</p>
                    <article v-for="round in currentStructuredRounds" :key="round.round" class="underground-round">
                        <h2>Round {{ round.round }}</h2>
                        <ul class="underground-action-log">
                            <li
                                v-for="(action, index) in round.actions"
                                :key="index"
                                :class="actionTone(action)"
                                :data-action-type="action.type"
                            >
                                <span class="underground-action-marker" aria-hidden="true" />
                                <div>
                                    <span v-if="actionHighlight(action)" class="underground-event-badge">{{ actionHighlight(action) }}</span>
                                    <small v-if="action.agility_combo_hits" class="underground-agility-combo">{{ action.agility_combo_hits }}連続ヒット！</small>
                                    <p>{{ actionNarrative(action, currentBattle) }}</p>
                                </div>
                            </li>
                        </ul>
                    </article>

                    <article v-for="roundNumber in simpleRoundNumbers(currentBattle)" :key="`simple-${roundNumber}`" class="underground-round">
                        <h2>Round {{ roundNumber }}</h2>
                        <ol class="underground-action-log">
                            <li v-for="(action, index) in simpleActions(currentBattle).filter((item) => item.round === roundNumber)" :key="index">{{ simpleActionNarrative(action, currentBattle) }}</li>
                        </ol>
                    </article>
                </div>

                <footer id="underground-battle-result" class="underground-battle-result">
                    <p class="eyebrow">戦闘終了</p>
                    <h2>{{ battleResultLabel(currentBattle.result) }}</h2>
                    <p v-if="currentBattle.hunting_ground">狩場: {{ currentBattle.hunting_ground.name }}</p>
                    <p>{{ battleRoundCount(currentBattle) }}ラウンドで決着。</p>
                    <p>経験値 +{{ currentBattle.xp_awarded }}・輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}G<span v-if="currentBattle.context === 'playtest'">・ドロップなし</span></p>
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
                    <dl v-if="currentBattle.summary" class="underground-combat-summary">
                        <div v-for="(value, key) in visibleSummary(currentBattle.summary)" :key="key"><dt>{{ summaryLabel(key) }}</dt><dd>{{ summaryValue(key, value) }}</dd></div>
                    </dl>
                    <a class="underground-log-jump" href="#underground-battle-start">先頭へ</a>
                </footer>
                <section v-if="currentBattle.first_clear_story" class="underground-first-clear-story" aria-labelledby="underground-first-clear-title">
                    <h2 id="underground-first-clear-title">{{ currentBattle.first_clear_story.title }}</h2>
                    <p class="underground-first-clear-body">{{ currentBattle.first_clear_story.body }}</p>
                    <div class="underground-first-clear-results" role="status">
                        <p v-for="message in currentBattle.first_clear_story.system_messages" :key="message">{{ message }}</p>
                    </div>
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
                    @click="runTrial"
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
            <nav class="underground-main-navigation" aria-label="地下メニュー">
                <button type="button" :aria-current="equipmentView === 'main' ? 'page' : undefined" @click="equipmentView = 'main'">地下メイン</button>
                <button type="button" :aria-current="equipmentView === 'shop' ? 'page' : undefined" @click="equipmentView = 'shop'">装備ショップ</button>
                <button type="button" :aria-current="equipmentView === 'guide' ? 'page' : undefined" @click="openGuide()">案内人の部屋</button>
                <button type="button" :aria-current="equipmentView === 'ai' ? 'page' : undefined" :disabled="!state.ai" @click="equipmentView = 'ai'">作戦設定</button>
                <button type="button" :aria-current="equipmentView === 'vault' ? 'page' : undefined" @click="equipmentView = 'vault'">宝物庫</button>
            </nav>

            <UndergroundEquipmentShop v-if="equipmentView === 'shop'" @updated="applyEquipmentMutation" />
            <section v-else-if="equipmentView === 'guide'" class="underground-guide-room" aria-labelledby="underground-guide-room-title">
                <header class="underground-guide-room-heading">
                    <div>
                        <p class="eyebrow">Underground Guide Room</p>
                        <h1 id="underground-guide-room-title">案内人の部屋</h1>
                    </div>
                    <p class="underground-guide-room-greeting">{{ state.shopkeeper_name ?? '案内人' }}「あら、どうしたんですか？」</p>
                </header>
                <div class="underground-guide-actions">
                    <button
                        type="button"
                        :aria-pressed="guideMode === 'conversation'"
                        @click="guideMode = 'conversation'"
                    >
                        少しお話がしたい
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
                <p v-if="guideMode === 'conversation'" class="underground-guide-conversation">「あ、あー……話題が思い浮かんだらまた来てちょうだいな？」</p>
                <section v-else-if="guideMode === 'respec'" class="underground-respec-panel" aria-labelledby="underground-respec-title">
                    <header>
                        <div>
                            <p class="eyebrow">Growth Reconfiguration</p>
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
            <UndergroundAiEditor
                v-else-if="equipmentView === 'ai' && state.ai"
                :configuration="state.ai"
                @updated="applyAiMutation"
            />
            <UndergroundEquipmentVault v-else-if="equipmentView === 'vault'" @updated="applyEquipmentMutation" />

            <div v-else class="underground-main-layout">
                <section class="underground-character-pane" aria-labelledby="underground-character-title">
                    <div class="underground-character-header">
                        <img v-if="props.secretaryImageUrl" :src="props.secretaryImageUrl" :alt="`${state.secretary_name}の画像`">
                        <div v-else class="underground-portrait-placeholder">No image</div>
                        <div><p class="eyebrow">Underground</p><h1 id="underground-character-title">{{ state.secretary_name }}</h1></div>
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
                        <p v-if="state.equipment_summary">宝物庫 {{ state.equipment_summary.used }} / {{ state.equipment_summary.capacity }}</p>
                    </section>
                    <div class="underground-character-actions">
                        <button type="button" :aria-expanded="statusOpen" @click="statusOpen = !statusOpen">ステータス<small>STP配分</small></button>
                        <button type="button" :aria-expanded="skillsOpen" @click="skillsOpen = !skillsOpen">Skill Tree<small>SP・active設定</small></button>
                        <button type="button" :disabled="busy || !state.ai" @click="equipmentView = 'ai'">AI設定<small>作戦を編集</small></button>
                    </div>
                </section>

                <section class="underground-action-pane" aria-labelledby="underground-guide-title">
                    <section class="underground-shop">
                        <p class="eyebrow">案内人 / ショップ</p>
                        <h2 id="underground-guide-title">{{ state.shopkeeper_name }}</h2>
                        <p>{{ shopGreeting }}</p>
                        <p v-if="innRested" class="underground-inn-result" role="status">（HPが全回復しました）</p>
                        <div class="underground-shop-entries">
                            <button type="button" :disabled="busy || innResting || Boolean(state.trial?.active_run)" @click="restAtInn">{{ innResting ? '休憩中…' : '宿で休む（10G）' }}<small>{{ state.trial?.active_run ? '封印の地から帰還後に利用できます' : innResting ? '案内人が準備しています' : 'HPを全回復' }}</small></button>
                            <button type="button" :disabled="busy" @click="equipmentView = 'shop'">装備ショップ<small>武器・防具・アクセサリー</small></button>
                            <button type="button" :disabled="busy" @click="bankOpen = !bankOpen">銀行<small>預入・引出</small></button>
                            <button type="button" :disabled="busy" @click="equipmentView = 'vault'">宝物庫<small>所持品・装備変更</small></button>
                        </div>
                        <form v-if="bankOpen" class="underground-bank" @submit.prevent>
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
                    </section>
                    <section class="underground-adventure" aria-labelledby="underground-adventure-title">
                        <h2 id="underground-adventure-title">冒険</h2>
                        <div class="underground-entries">
                            <div class="underground-explore-picker">
                                <button
                                    class="underground-explore-button"
                                    type="button"
                                    :disabled="busy || exploreCooldownSeconds > 0 || Boolean(state.trial?.active_run) || !selectedHuntingGround"
                                    @click="runSelectedExploration"
                                >
                                    周囲を探索
                                    <small>{{ selectedHuntingGround?.name ?? '浅い洞窟' }}</small>
                                    <small v-if="exploreCooldownSeconds > 0">あと{{ exploreCooldownSeconds }}秒</small>
                                </button>
                                <template v-if="unlockedHuntingGrounds.length > 1">
                                    <select
                                        class="underground-ground-selector"
                                        aria-label="狩場を選択"
                                        :value="selectedHuntingGroundKey"
                                        :disabled="busy || Boolean(state.trial?.active_run)"
                                        @change="changeHuntingGround"
                                    >
                                        <option v-for="ground in unlockedHuntingGrounds" :key="ground.key" :value="ground.key">{{ ground.name }}</option>
                                    </select>
                                    <span class="underground-ground-chevron" aria-hidden="true">▼</span>
                                </template>
                            </div>
                            <button class="underground-trial-entry" type="button" :disabled="busy || exploreCooldownSeconds > 0 || !state.trial" @click="runTrial">封印の地<small>{{ exploreCooldownSeconds > 0 ? `あと${exploreCooldownSeconds}秒` : state.trial?.active_run ? `${state.trial.active_run.next_battle_index}/${state.trial.active_run.total_battles}戦目` : state.trial?.label }}</small></button>
                        </div>
                        <button v-if="state.trial?.active_run" class="button secondary" type="button" :disabled="busy" @click="withdrawTrial">封印の地から帰還する</button>
                    </section>
                    <section v-if="state.playtest" class="underground-playtest" aria-labelledby="underground-playtest-title">
                        <h2 id="underground-playtest-title">力試し（α）</h2>
                        <p>{{ state.playtest.notice }}</p>
                        <label for="underground-build">完成形ビルド</label>
                        <select id="underground-build" v-model="selectedBuild" :disabled="busy"><option v-for="build in state.playtest.builds" :key="build.key" :value="build.key">{{ build.label }} — {{ build.description }}</option></select>
                        <label for="underground-enemy">対戦相手</label>
                        <select id="underground-enemy" v-model="selectedEnemy" :disabled="busy"><option v-for="enemy in state.playtest.enemies" :key="enemy.key" :value="enemy.key">{{ enemy.label }} — {{ enemy.description }}</option></select>
                        <button class="button primary" type="button" :disabled="busy || !selectedBuild || !selectedEnemy" @click="runPlaytest">戦闘開始</button>
                        <p>報酬なし: XP 0・輝石の欠片 0G・ドロップなし。敗北ペナルティもありません。</p>
                    </section>
                    <section class="underground-history" aria-labelledby="underground-history-title">
                        <h2 id="underground-history-title">戦闘履歴</h2>
                        <ul><li v-for="battle in recentBattles" :key="battle.id"><button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battleRoundCount(battle) }}ラウンド</button></li></ul>
                    </section>
                </section>
            </div>

            <section v-if="statusOpen && state.status_breakdown" class="underground-progression-panel" aria-labelledby="underground-status-title">
                <header>
                    <div><p class="eyebrow">Character Growth</p><h2 id="underground-status-title">ステータス</h2></div>
                    <p>未使用STP {{ state.unspent_stp }} / 仮配分後 {{ stpDraftRemaining }}</p>
                </header>
                <div class="underground-table-scroll">
                    <table class="underground-status-table">
                        <thead><tr><th scope="col">能力</th><th scope="col">初期値</th><th scope="col">自然成長</th><th scope="col">確定STP</th><th scope="col">装備</th><th scope="col">最終値</th><th scope="col">今回の配分</th></tr></thead>
                        <tbody>
                            <tr v-for="(label, key) in statLabels" :key="key">
                                <th scope="row">{{ label }}</th>
                                <td>{{ state.status_breakdown[key].baseline }}</td>
                                <td>+{{ state.status_breakdown[key].natural_growth }}</td>
                                <td>+{{ state.status_breakdown[key].allocated_stp }}</td>
                                <td>+{{ state.status_breakdown[key].equipment }}</td>
                                <td>{{ state.status_breakdown[key].final }}</td>
                                <td class="underground-stp-control"><input type="number" min="0" :max="maximumStpDraft(key)" step="1" inputmode="numeric" :value="stpDraft[key]" :disabled="busy" :aria-label="`${label}の今回の配分`" @input="setStpDraft(key, $event)"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="underground-progression-note">確定すると元に戻せません。装備補正とSTPは別々に計算されます。</p>
                <button class="button primary" type="button" :disabled="busy || stpDraftTotal === 0" @click="confirmStp">{{ stpDraftTotal }} STPを一括確定</button>
            </section>

            <section v-if="skillsOpen && state.skill_trees" class="underground-progression-panel" aria-labelledby="underground-skills-title">
                <header>
                    <div><p class="eyebrow">Finite Skill Points</p><h2 id="underground-skills-title">Skill Tree</h2></div>
                    <div class="underground-skill-header-actions">
                        <p>SP {{ state.skill_points_unspent }} / {{ state.skill_points_total }}（使用済み {{ state.skill_points_spent }}）</p>
                        <button class="underground-skill-jump" type="button" @click="focusActiveLoadout">アクティブスキル設定へ</button>
                    </div>
                </header>
                <p class="underground-progression-note">SPを消費することでスキルを習得できます。</p>
                <div class="underground-tree-tabs" role="tablist" aria-label="Skill Tree系統">
                    <button
                        v-for="tree in state.skill_trees"
                        :id="`underground-tree-tab-${tree.key}`"
                        :key="tree.key"
                        type="button"
                        role="tab"
                        :aria-controls="`underground-tree-panel-${tree.key}`"
                        :aria-selected="activeSkillTreeKey === tree.key"
                        @click="activeSkillTreeKey = tree.key"
                    >
                        {{ tree.label }}
                    </button>
                </div>
                <div class="underground-tree-grid">
                    <article
                        v-for="tree in state.skill_trees"
                        :id="`underground-tree-panel-${tree.key}`"
                        :key="tree.key"
                        class="underground-skill-tree"
                        :aria-labelledby="`underground-tree-tab-${tree.key}`"
                        :data-mobile-active="activeSkillTreeKey === tree.key"
                    >
                        <header><h3>{{ tree.label }}</h3><span>{{ tree.invested_points }} / {{ tree.full_points }} SP</span></header>
                        <ol>
                            <li v-for="node in orderedSkillNodes(tree.nodes)" :key="node.key" class="underground-skill-node" :data-acquired="node.rank > 0">
                                <div class="underground-skill-node-heading"><strong>{{ node.label }}</strong><span>{{ node.type === 'active' ? 'active' : 'passive' }} {{ node.rank }} / {{ node.max_rank }}</span></div>
                                <p>{{ node.summary }}</p>
                                <dl>
                                    <div><dt>SP cost</dt><dd>{{ node.point_cost }}</dd></div>
                                    <div><dt>前提</dt><dd>{{ nodeLabel(node.prerequisite) }}</dd></div>
                                    <div><dt>tree投資</dt><dd>{{ node.invested_points_required }} SP</dd></div>
                                    <div v-if="node.type === 'active'"><dt>MP / CD</dt><dd>{{ node.mp_cost }} / {{ node.cooldown }}R</dd></div>
                                    <div v-if="node.required_weapon_styles.length > 0"><dt>武器条件</dt><dd>{{ node.required_weapon_styles.join('・') }}</dd></div>
                                </dl>
                                <p v-if="node.type === 'active' && node.recommended_stats !== null" class="underground-skill-dependency">依存： {{ recommendedStatsText(node.recommended_stats) }}</p>
                                <p v-if="!node.can_acquire && node.rank < node.max_rank" class="underground-node-unavailable">{{ node.unavailable_reason }}</p>
                                <p v-else-if="node.rank >= node.max_rank" class="underground-node-complete">取得済み</p>
                                <button v-else type="button" :disabled="busy" @click="acquireSkill(node.key)">取得する</button>
                            </li>
                        </ol>
                    </article>
                </div>

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
                        <div><p class="eyebrow">Awakening</p><h3 id="underground-awakening-settings-title">{{ state.awakening.technique.name }}</h3></div>
                        <strong>覚醒中に1度だけ使用可能</strong>
                    </header>
                    <p>{{ state.awakening.technique.summary }}</p>
                    <p>{{ state.awakening.technique.consumes_action ? '通常actionを消費します。' : '通常actionを消費せず、そのままAI行動を続けます。' }}</p>
                    <label for="underground-awakening-message">覚醒時の最初の演出文</label>
                    <textarea id="underground-awakening-message" v-model="awakeningMessageDraft" maxlength="100" rows="3" :disabled="busy"></textarea>
                    <p class="underground-progression-note"><code>{secretary_name}</code> はbattle開始時の秘書名へ置換されます。空欄でdefaultへ戻ります。{{ awakeningMessageDraft.length }} / 100</p>
                    <button class="button primary" type="button" :disabled="busy" @click="saveAwakeningMessage">覚醒演出文を保存</button>
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
        </template>
    </section>
</template>
