<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';

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
}

interface RoundState {
    hp: number;
    max_hp: number;
    mp: number;
    barrier: number;
    statuses: Array<{ label: string; remaining: number; stacks: number }>;
    role_stacks: { fighting_spirit: number; grace: number };
}

interface CombatRound {
    round: number;
    actions: RoundAction[];
    end_state: { player: RoundState; enemy: RoundState } | null;
}

interface Battle {
    id: string;
    context: 'tutorial' | 'scripted_loss' | 'playtest';
    player_display_name?: string;
    encounter_name: string;
    build_name?: string;
    result: 'victory' | 'defeat' | 'withdrawal' | 'stalemate';
    rounds?: number | CombatRound[];
    rounds_count?: number;
    xp_awarded: number;
    shard_delta: number;
    detail_available: boolean;
    actions?: SimpleAction[] | CombatRound[] | null;
    summary?: Record<string, number | string> | null;
    rewards?: { xp: number; shards: number; g: number; drops: unknown[] };
    detail_message?: string | null;
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

interface UndergroundState {
    stage: Stage;
    secretary_name: string;
    combat_level: number;
    combat_xp: number;
    next_level_xp: number;
    shard_balance: number;
    shopkeeper_name: string | null;
    true_name_branch: boolean;
    tutorial_projection: {
        stats: Record<'vitality' | 'might' | 'finesse' | 'spirit' | 'agility', number>;
        weapon: string;
    };
    contract_completed: boolean;
    growth_paths: GrowthPath[] | null;
    growth_path: GrowthPath | null;
    playtest: PlaytestOptions | null;
    battle: Battle | null;
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
const props = defineProps<{ secretaryImageUrl?: string | null }>();
const emit = defineEmits<{ returnToSecretary: [] }>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const error = ref('');
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const selectedBattle = ref<Battle | null>(null);
const selectedBuild = ref('');
const selectedEnemy = ref('');
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);
const currentPlayerDisplayName = computed(() => currentBattle.value
    ? playerDisplayName(currentBattle.value)
    : state.value?.secretary_name ?? '秘書');
const currentStructuredRounds = computed(() => currentBattle.value ? structuredRounds(currentBattle.value) : []);
const growthEnding = computed(() => state.value?.growth_path?.key === 'free_black'
    ? '「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」'
    : '「ふふ、とってもお似合いですよ、その能力」');
const shopGreeting = computed(() => state.value?.true_name_branch
    ? '「いらっしゃいませ！　『雨宿り』箱庭ダンジョン支店です！」'
    : '「いらっしゃいませ！　あなたのコンビニ、箱庭ダンジョン店です！」');
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
const hiddenSummaryKeys = new Set(['rounds', 'player_remaining_hp', 'enemy_remaining_hp', 'final_mp']);

watch(() => state.value?.playtest, (playtest) => {
    if (!playtest) return;
    selectedBuild.value = playtest.default_build_key;
    selectedEnemy.value ||= playtest.enemies[0]?.key ?? '';
}, { immediate: true });

function requestId(): string {
    return crypto.randomUUID();
}

async function refresh(returnIfTutorialAlreadyFinished = true): Promise<void> {
    state.value = await api<UndergroundState>('/api/v1/me/underground');
    if (returnIfTutorialAlreadyFinished && state.value.stage === 'returned_after_tutorial') {
        emit('returnToSecretary');
        return;
    }
    if (state.value.stage === 'underground_open') await loadBattles();
}

async function mutate(path: string, body: Record<string, unknown> = {}): Promise<boolean> {
    if (busy.value) return false;
    busy.value = true;
    error.value = '';
    try {
        state.value = await api<UndergroundState>(path, {
            method: 'POST',
            body: JSON.stringify({ request_id: requestId(), ...body }),
        });
        selectedBattle.value = null;
        if (state.value.stage === 'returned_after_tutorial') {
            emit('returnToSecretary');
            return true;
        }
        if (state.value.stage === 'underground_open') await loadBattles();
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

async function loadBattles(): Promise<void> {
    battles.value = await api<Battle[]>('/api/v1/me/underground/battles');
}

async function showBattle(battle: Battle): Promise<void> {
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

function summaryValue(key: string, value: number | string): number | string {
    return key === 'result' ? battleResultLabel(String(value) as Battle['result']) : value;
}

function visibleSummary(summary: Record<string, number | string>): Record<string, number | string> {
    return Object.fromEntries(Object.entries(summary).filter(([key]) => !hiddenSummaryKeys.has(key)));
}

function statusSummary(state: RoundState): string {
    return state.statuses.length
        ? state.statuses.map((status) => `${status.label} 残${status.remaining}・${status.stacks}段階`).join('、')
        : 'なし';
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
    if (action.type === 'action' || action.type === 'decision') return `${actor}は「${action.label}」を使用した。`;
    if (action.type === 'mp_cost') return `${actor}はMPを${amount}消費した。`;
    if (action.type === 'mp_recovery') return `${actor}はMPを${amount}回復した。`;
    if (action.type === 'counter') return `${actor}の反撃。${target}に${amount}ダメージ。`;
    if (action.type === 'guard') return `${actor}は防御態勢を取った。`;
    if (action.type === 'barrier') return `${actor}は「${action.label}」で障壁を${amount}得た。`;
    if (action.type === 'recovery') return `${actor}は「${action.label}」でHPを${amount}回復した。`;
    if (action.type === 'role_stack_gain' || action.type === 'role_stack_spent') {
        const role = action.label.replace(/^(増加|消費):\s*/, '');
        return `${actor}の${role}が${amount}${action.type === 'role_stack_gain' ? '増加' : '消費'}した。`;
    }
    if (action.type === 'status_applied') return `${target}に${action.label.replace(/^付与:\s*/, '')}が付与された。`;
    if (action.type === 'status_expired') return `${actor}の${action.label.replace(/^消滅:\s*/, '')}が消滅した。`;
    if (action.type === 'status_resisted') return `${target}は${action.label.replace(/^抵抗:\s*/, '')}を防いだ。`;
    if (action.type === 'status_removed') return `${actor}は状態効果を${amount}個解除した。`;
    if (action.type === 'damage') {
        const qualifiers = [action.critical ? '会心' : '', action.guarded ? '防御' : '', action.parried ? '受け流し' : '']
            .filter(Boolean).join('・');
        if (action.evaded) return `${actor}の「${action.label}」。${target}は回避した。`;
        const damage = amount > 0 ? `${target}に${amount}ダメージ。` : `${target}のHPダメージは0。`;
        const barrier = action.barrier_absorbed ? `障壁が${action.barrier_absorbed}吸収。` : '';
        return `${actor}の「${action.label}」。${qualifiers ? `${qualifiers}。` : ''}${damage}${barrier}`;
    }
    return `${actor}に「${action.label}」の効果。`;
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
}

onMounted(() => { void enter(); });
</script>

<template>
    <section class="panel underground-panel" aria-live="polite">
        <p v-if="busy && state === null" class="status">地下の状態を確認しています。</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="state && currentBattle">
            <section id="underground-battle-start" class="underground-battle-log" aria-label="戦闘ログ">
                <header class="underground-battle-opening">
                    <p class="eyebrow">遭遇</p>
                    <h1>{{ currentBattle.encounter_name }}</h1>
                    <p v-if="currentBattle.build_name">{{ currentBattle.build_name }}で戦闘を開始した。</p>
                    <p v-else>{{ currentPlayerDisplayName }}は戦闘を開始した。</p>
                    <a class="underground-log-jump" href="#underground-battle-result">末尾へ</a>
                </header>

                <div class="underground-rounds">
                    <p v-if="currentBattle.detail_message" class="status">{{ currentBattle.detail_message }}</p>
                    <article v-for="round in currentStructuredRounds" :key="round.round" class="underground-round">
                        <h2>Round {{ round.round }}</h2>
                        <ol class="underground-action-log">
                            <li v-for="(action, index) in round.actions" :key="index">{{ actionNarrative(action, currentBattle) }}</li>
                        </ol>
                        <div v-if="round.end_state" class="underground-round-state">
                            <section class="underground-combatant-state">
                                <strong>{{ currentPlayerDisplayName }}</strong>
                                <div class="underground-vitals">
                                    <label><span>HP {{ round.end_state.player.hp }}/{{ round.end_state.player.max_hp }}</span><progress class="hp" :max="round.end_state.player.max_hp" :value="round.end_state.player.hp" /></label>
                                    <label><span>MP {{ round.end_state.player.mp }}/10000</span><progress class="mp" max="10000" :value="round.end_state.player.mp" /></label>
                                </div>
                                <p>障壁 {{ round.end_state.player.barrier }}・状態 {{ statusSummary(round.end_state.player) }}・闘志 {{ round.end_state.player.role_stacks.fighting_spirit }}・恩寵 {{ round.end_state.player.role_stacks.grace }}</p>
                            </section>
                            <section class="underground-combatant-state">
                                <strong>{{ currentBattle.encounter_name }}</strong>
                                <div class="underground-vitals">
                                    <label><span>HP {{ round.end_state.enemy.hp }}/{{ round.end_state.enemy.max_hp }}</span><progress class="hp" :max="round.end_state.enemy.max_hp" :value="round.end_state.enemy.hp" /></label>
                                    <label><span>MP {{ round.end_state.enemy.mp }}/10000</span><progress class="mp" max="10000" :value="round.end_state.enemy.mp" /></label>
                                </div>
                                <p>障壁 {{ round.end_state.enemy.barrier }}・状態 {{ statusSummary(round.end_state.enemy) }}・闘志 {{ round.end_state.enemy.role_stacks.fighting_spirit }}・恩寵 {{ round.end_state.enemy.role_stacks.grace }}</p>
                            </section>
                            <p v-if="round.end_state.player.hp === 0" class="underground-ko">{{ currentPlayerDisplayName }}は戦闘不能になった。</p>
                            <p v-if="round.end_state.enemy.hp === 0" class="underground-ko">{{ currentBattle.encounter_name }}は戦闘不能になった。</p>
                        </div>
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
                    <p>{{ battleRoundCount(currentBattle) }}ラウンドで決着。</p>
                    <p>経験値 +{{ currentBattle.xp_awarded }}・輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}<span v-if="currentBattle.context === 'playtest'">・G +0・ドロップなし</span></p>
                    <dl v-if="currentBattle.summary" class="underground-combat-summary">
                        <div v-for="(value, key) in visibleSummary(currentBattle.summary)" :key="key"><dt>{{ summaryLabel(key) }}</dt><dd>{{ summaryValue(key, value) }}</dd></div>
                    </dl>
                    <a class="underground-log-jump" href="#underground-battle-start">先頭へ</a>
                </footer>
            </section>

            <div v-if="state.stage === 'escape_pending'" class="underground-story underground-after-battle">
                <p v-for="line in tutorialAftermath" :key="line">{{ line }}</p>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'escape_complete' })">OK</button>
            </div>
            <div v-else-if="state.stage === 'special_loss_complete'" class="underground-story underground-after-battle">
                <p v-for="line in trueNameAfter" :key="line">{{ line }}</p>
                <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'special_loss_aftermath_complete' })">OK</button>
            </div>
            <button v-else class="button secondary underground-battle-back" type="button" @click="closeBattle">地下メインへ戻る</button>
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
            <div class="underground-main-layout">
                <section class="underground-character-pane" aria-labelledby="underground-character-title">
                    <div class="underground-character-header">
                        <img v-if="props.secretaryImageUrl" :src="props.secretaryImageUrl" :alt="`${state.secretary_name}の画像`">
                        <div v-else class="underground-portrait-placeholder">No image</div>
                        <div><p class="eyebrow">Underground</p><h1 id="underground-character-title">{{ state.secretary_name }}</h1></div>
                    </div>
                    <dl class="underground-summary">
                        <div><dt>戦闘Lv</dt><dd>{{ state.combat_level }}</dd></div>
                        <div><dt>経験値</dt><dd>{{ state.combat_xp }} / {{ state.next_level_xp }}</dd></div>
                        <div v-if="state.growth_path"><dt>HP</dt><dd>{{ state.growth_path.max_hp }} / {{ state.growth_path.max_hp }}</dd></div>
                        <div v-if="state.growth_path"><dt>MP</dt><dd>{{ state.growth_path.max_mp }} / {{ state.growth_path.max_mp }}</dd></div>
                        <div><dt>輝石の欠片</dt><dd>{{ state.shard_balance }} G</dd></div>
                        <div v-if="state.growth_path"><dt>成長方針</dt><dd>{{ state.growth_path.label }}</dd></div>
                    </dl>
                    <section v-if="state.growth_path" class="underground-growth-summary">
                        <h2>能力</h2>
                        <dl><div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ state.growth_path.stats[key] }}</dd></div></dl>
                        <p>自然回復 {{ state.growth_path.natural_recovery }} MP / ラウンド・Lv2以降 未使用STP +{{ state.growth_path.unspent_stp_per_level }}</p>
                    </section>
                    <section class="underground-equipment" aria-labelledby="underground-equipment-title">
                        <h2 id="underground-equipment-title">装備</h2>
                        <dl><div><dt>武器</dt><dd>未設定</dd></div><div><dt>防具</dt><dd>未設定</dd></div><div><dt>装飾</dt><dd>未設定</dd></div></dl>
                    </section>
                    <div class="underground-character-actions"><button type="button" disabled>スキル<small>準備中</small></button><button type="button" disabled>AI設定<small>準備中</small></button></div>
                </section>

                <section class="underground-action-pane" aria-labelledby="underground-guide-title">
                    <section class="underground-shop">
                        <p class="eyebrow">案内人 / ショップ</p>
                        <h2 id="underground-guide-title">{{ state.shopkeeper_name }}</h2>
                        <p>{{ shopGreeting }}</p>
                        <div class="underground-shop-entries">
                            <button type="button" disabled>宿で休む<small>準備中</small></button>
                            <button type="button" disabled>装備ショップ<small>準備中</small></button>
                            <button type="button" disabled>銀行<small>準備中</small></button>
                            <button type="button" disabled>ショップ<small>準備中</small></button>
                        </div>
                    </section>
                    <section class="underground-adventure" aria-labelledby="underground-adventure-title">
                        <h2 id="underground-adventure-title">冒険</h2>
                        <div class="underground-entries"><button type="button" disabled>周囲を探索<small>準備中</small></button><button type="button" disabled>試練<small>準備中</small></button></div>
                    </section>
                    <section v-if="state.playtest" class="underground-playtest" aria-labelledby="underground-playtest-title">
                        <h2 id="underground-playtest-title">力試し（α）</h2>
                        <p>{{ state.playtest.notice }}</p>
                        <label for="underground-build">完成形ビルド</label>
                        <select id="underground-build" v-model="selectedBuild" :disabled="busy"><option v-for="build in state.playtest.builds" :key="build.key" :value="build.key">{{ build.label }} — {{ build.description }}</option></select>
                        <label for="underground-enemy">対戦相手</label>
                        <select id="underground-enemy" v-model="selectedEnemy" :disabled="busy"><option v-for="enemy in state.playtest.enemies" :key="enemy.key" :value="enemy.key">{{ enemy.label }} — {{ enemy.description }}</option></select>
                        <button class="button primary" type="button" :disabled="busy || !selectedBuild || !selectedEnemy" @click="runPlaytest">戦闘開始</button>
                        <p>報酬なし: XP 0・輝石の欠片 0・G 0・ドロップなし。敗北ペナルティもありません。</p>
                    </section>
                    <section class="underground-history" aria-labelledby="underground-history-title">
                        <h2 id="underground-history-title">戦闘履歴</h2>
                        <ul><li v-for="battle in battles" :key="battle.id"><button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battleRoundCount(battle) }}ラウンド</button></li></ul>
                    </section>
                </section>
            </div>
        </template>
    </section>
</template>
