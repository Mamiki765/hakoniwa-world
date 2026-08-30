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
    action: string;
    amount: number;
}

interface RoundAction {
    type: string;
    side: string;
    label: string;
    reason?: string;
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
const emit = defineEmits<{ returnToSecretary: [] }>();
const state = ref<UndergroundState | null>(null);
const busy = ref(false);
const error = ref('');
const shopkeeperName = ref('');
const battles = ref<Battle[]>([]);
const selectedBattle = ref<Battle | null>(null);
const selectedBuild = ref('');
const selectedEnemy = ref('');
const shopOpen = ref(false);
const selectedRoundIndex = ref(0);
const currentBattle = computed(() => selectedBattle.value ?? state.value?.battle ?? null);
const currentStructuredRounds = computed(() => currentBattle.value ? structuredRounds(currentBattle.value) : []);
const selectedRound = computed(() => currentStructuredRounds.value[selectedRoundIndex.value] ?? null);
const growthEnding = computed(() => state.value?.growth_path?.key === 'free_black'
    ? '「全部？　まぁ、別にあなたにしか必要のないものです。ええ、あげますよ、欲張りさん？」'
    : '「ふふ、とってもお似合いですよ、その能力」');
const shopGreeting = computed(() => state.value?.true_name_branch
    ? '「いらっしゃいませ！　『雨宿り』箱庭ダンジョン支店です！」'
    : '「いらっしゃいませ！　あなたのコンビニ、箱庭ダンジョン店です！」');
const summaryLabels: Record<string, string> = {
    result: '結果',
    rounds: 'round数',
    player_remaining_hp: '秘書の残HP',
    enemy_remaining_hp: '対戦相手の残HP',
    final_mp: '最終MP',
    damage_dealt: '与damage',
    damage_received: '被damage',
    effective_healing: '有効回復',
    damage_prevented: '防いだdamage',
    mp_spent: 'MP消費',
    mp_natural_recovery: '自然回復MP',
    mp_skill_recovery: 'skill回復MP',
    skill_unavailable_due_to_mp: 'MP不足回数',
};
const actionTypeLabels: Record<string, string> = {
    decision: 'AI判断',
    damage: 'damage',
    recovery: '回復',
    barrier: 'barrier',
    state: '状態変化',
    status_applied: 'status付与',
    status_expired: 'status消滅',
    status_resisted: 'status抵抗',
    status_removed: 'status解除',
    counter: 'counter',
    guard: 'guard',
};

watch(() => state.value?.playtest, (playtest) => {
    if (!playtest) return;
    selectedBuild.value = playtest.default_build_key;
    selectedEnemy.value ||= playtest.enemies[0]?.key ?? '';
}, { immediate: true });

watch(() => currentBattle.value?.id, () => { selectedRoundIndex.value = 0; });

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

function moveRound(offset: number): void {
    selectedRoundIndex.value = Math.min(
        Math.max(selectedRoundIndex.value + offset, 0),
        Math.max(currentStructuredRounds.value.length - 1, 0),
    );
}

function summaryLabel(key: string): string {
    return summaryLabels[key] ?? key;
}

function summaryValue(key: string, value: number | string): number | string {
    return key === 'result' ? battleResultLabel(String(value) as Battle['result']) : value;
}

function actionTypeLabel(type: string): string {
    return actionTypeLabels[type] ?? '戦闘効果';
}

function statusSummary(state: RoundState): string {
    return state.statuses.length
        ? state.statuses.map((status) => `${status.label} 残${status.remaining} / stack ${status.stacks}`).join('、')
        : 'なし';
}

function simpleActions(battle: Battle): SimpleAction[] {
    if (!Array.isArray(battle.actions) || (battle.actions[0] && 'actions' in battle.actions[0])) return [];
    return battle.actions as SimpleAction[];
}

onMounted(() => { void enter(); });
</script>

<template>
    <section class="panel underground-panel" aria-live="polite">
        <p v-if="busy && state === null" class="status">地下の状態を確認しています。</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>

        <template v-if="state?.stage === 'initial_descent'">
            <div class="underground-story">
                <p v-for="line in initialDescent" :key="line">{{ line }}</p>
            </div>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'initial_story_complete' })">OK</button>
        </template>

        <template v-else-if="state?.stage === 'tutorial_ready'">
            <p class="underground-story">鼻を突く臭い。秘書が身構えたのも束の間、闇の中から巨大なネズミの様な怪物が現れ、襲い掛かってきた――！</p>
            <h1>ジャイアントラット</h1>
            <dl class="underground-tutorial-stats">
                <div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ state.tutorial_projection.stats[key] }}</dd></div>
                <div><dt>武器</dt><dd>護身用ナイフ</dd></div>
            </dl>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/tutorial')">戦闘開始</button>
        </template>

        <template v-else-if="state?.stage === 'escape_pending'">
            <div class="underground-story">
                <p v-for="line in tutorialAftermath" :key="line">{{ line }}</p>
            </div>
            <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                <h2>戦闘ログ</h2>
                <p>{{ currentBattle.encounter_name }} / {{ battleRoundCount(currentBattle) }} round</p>
                <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}</p>
                <ol v-if="simpleActions(currentBattle).length">
                    <li v-for="(action, index) in simpleActions(currentBattle)" :key="`${action.round}-${index}`">{{ action.round }} / {{ action.side }} / {{ action.action }} / {{ action.amount }}</li>
                </ol>
            </section>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'escape_complete' })">OK</button>
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
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/scripted-loss')">戦闘開始</button>
        </template>

        <template v-else-if="state?.stage === 'special_loss_complete'">
            <div class="underground-story"><p v-for="line in trueNameAfter" :key="line">{{ line }}</p></div>
            <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                <h2>戦闘ログ</h2>
                <p>{{ currentBattle.encounter_name }} / {{ battleRoundCount(currentBattle) }} round</p>
                <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}</p>
                <article v-if="selectedRound" class="underground-round-viewer">
                    <nav aria-label="戦闘round操作">
                        <button type="button" :disabled="selectedRoundIndex === 0" @click="moveRound(-1)">前のround</button>
                        <strong>Round {{ selectedRound.round }} / {{ currentStructuredRounds.length }}</strong>
                        <button type="button" :disabled="selectedRoundIndex >= currentStructuredRounds.length - 1" @click="moveRound(1)">次のround</button>
                    </nav>
                    <ul><li v-for="(action, index) in selectedRound.actions" :key="index">{{ action.side }} / {{ actionTypeLabel(action.type) }} / {{ action.label }}<span v-if="action.reason"> — {{ action.reason }}</span><span v-if="action.amount"> / {{ action.amount }}</span></li></ul>
                </article>
            </section>
            <button class="button primary" type="button" :disabled="busy" @click="mutate('/api/v1/me/underground/story/advance', { action: 'special_loss_aftermath_complete' })">OK</button>
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
                    <h2>{{ path.label }}</h2>
                    <p v-for="line in path.description" :key="line">{{ line }}</p>
                    <dl>
                        <div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ path.stats[key] }}</dd></div>
                        <div><dt>HP</dt><dd>{{ path.max_hp }}</dd></div><div><dt>MP</dt><dd>{{ path.max_mp }}</dd></div>
                    </dl>
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
            <h1>{{ state.secretary_name }}</h1>
            <dl class="underground-summary">
                <div><dt>戦闘Lv</dt><dd>{{ state.combat_level }}</dd></div>
                <div><dt>経験値</dt><dd>{{ state.combat_xp }} / {{ state.next_level_xp }}</dd></div>
                <div><dt>輝石の欠片</dt><dd>{{ state.shard_balance }} G <small>1 G = 輝石の欠片1グラム</small></dd></div>
                <div><dt>ショップ</dt><dd>{{ state.shopkeeper_name }}</dd></div>
                <div v-if="state.growth_path"><dt>成長方針</dt><dd>{{ state.growth_path.label }}</dd></div>
            </dl>
            <section v-if="state.growth_path" class="underground-growth-summary">
                <h2>Lv1能力</h2>
                <dl><div v-for="(label, key) in statLabels" :key="key"><dt>{{ label }}</dt><dd>{{ state.growth_path.stats[key] }}</dd></div></dl>
                <p>HP {{ state.growth_path.max_hp }} / MP {{ state.growth_path.max_mp }} / 自然回復 {{ state.growth_path.natural_recovery }} / round</p>
                <p>Lv2以降: 未使用STP +{{ state.growth_path.unspent_stp_per_level }} / level（実際の成長・振り分けは今後実装）</p>
            </section>
            <div class="underground-entries">
                <button type="button" disabled>周囲を探索<br><small>準備中</small></button>
                <button type="button" disabled>試練<br><small>準備中</small></button>
                <button type="button" @click="shopOpen = !shopOpen">ショップ</button>
            </div>
            <section v-if="shopOpen" class="underground-shop" aria-labelledby="underground-shop-title">
                <h2 id="underground-shop-title">{{ state.true_name_branch ? '「雨宿り」箱庭ダンジョン支店' : '箱庭ダンジョン店' }}</h2>
                <p>{{ shopGreeting }}</p>
                <div class="underground-shop-entries">
                    <button type="button" disabled>宿で休む<br><small>準備中</small></button>
                    <button type="button" disabled>装備ショップを覗く<br><small>準備中</small></button>
                    <button type="button" disabled>銀行に行く<br><small>準備中</small></button>
                </div>
            </section>
            <section v-if="state.playtest" class="underground-playtest" aria-labelledby="underground-playtest-title">
                <h2 id="underground-playtest-title">力試し（α）</h2>
                <p>{{ state.playtest.notice }}</p>
                <label for="underground-build">完成形ビルド</label>
                <select id="underground-build" v-model="selectedBuild" :disabled="busy"><option v-for="build in state.playtest.builds" :key="build.key" :value="build.key">{{ build.label }} — {{ build.description }}</option></select>
                <label for="underground-enemy">対戦相手</label>
                <select id="underground-enemy" v-model="selectedEnemy" :disabled="busy"><option v-for="enemy in state.playtest.enemies" :key="enemy.key" :value="enemy.key">{{ enemy.label }} — {{ enemy.description }}</option></select>
                <button class="button primary" type="button" :disabled="busy || !selectedBuild || !selectedEnemy" @click="runPlaytest">戦闘開始</button>
                <p>報酬なし: XP 0 / 輝石の欠片 0 / G 0 / drop なし。敗北penaltyもありません。</p>
            </section>
            <section v-if="currentBattle" class="underground-battle-log" aria-label="戦闘ログ">
                <h2>{{ currentBattle.context === 'playtest' ? '力試し結果' : '戦闘ログ' }}</h2>
                <p><span v-if="currentBattle.build_name">{{ currentBattle.build_name }} vs </span>{{ currentBattle.encounter_name }} / {{ battleRoundCount(currentBattle) }} round</p>
                <p>結果: {{ battleResultLabel(currentBattle.result) }}</p>
                <p>経験値 +{{ currentBattle.xp_awarded }} / 輝石の欠片 {{ currentBattle.shard_delta >= 0 ? '+' : '' }}{{ currentBattle.shard_delta }}<span v-if="currentBattle.context === 'playtest'"> / G +0 / drop なし</span></p>
                <dl v-if="currentBattle.summary" class="underground-combat-summary">
                    <div v-for="(value, key) in currentBattle.summary" :key="key"><dt>{{ summaryLabel(key) }}</dt><dd>{{ summaryValue(key, value) }}</dd></div>
                </dl>
                <article v-if="selectedRound" class="underground-round-viewer">
                    <nav aria-label="戦闘round操作">
                        <button type="button" :disabled="selectedRoundIndex === 0" @click="moveRound(-1)">前のround</button>
                        <strong>Round {{ selectedRound.round }} / {{ currentStructuredRounds.length }}</strong>
                        <button type="button" :disabled="selectedRoundIndex >= currentStructuredRounds.length - 1" @click="moveRound(1)">次のround</button>
                    </nav>
                    <ul>
                        <li v-for="(action, index) in selectedRound.actions" :key="index">
                            {{ action.side }} / {{ actionTypeLabel(action.type) }} / {{ action.label }}<span v-if="action.reason"> — {{ action.reason }}</span><span v-if="action.amount"> / {{ action.amount }}</span><span v-if="action.critical"> / critical</span><span v-if="action.evaded"> / evade</span><span v-if="action.guarded"> / guard</span><span v-if="action.parried"> / parry</span><span v-if="action.barrier_absorbed"> / barrier {{ action.barrier_absorbed }}</span>
                        </li>
                    </ul>
                    <template v-if="selectedRound.end_state">
                        <p>秘書 HP {{ selectedRound.end_state.player.hp }}/{{ selectedRound.end_state.player.max_hp }} / MP {{ selectedRound.end_state.player.mp }} / barrier {{ selectedRound.end_state.player.barrier }} / status {{ statusSummary(selectedRound.end_state.player) }} / 闘志 {{ selectedRound.end_state.player.role_stacks.fighting_spirit }} / 恩寵 {{ selectedRound.end_state.player.role_stacks.grace }}</p>
                        <p>対戦相手 HP {{ selectedRound.end_state.enemy.hp }}/{{ selectedRound.end_state.enemy.max_hp }} / MP {{ selectedRound.end_state.enemy.mp }} / barrier {{ selectedRound.end_state.enemy.barrier }} / status {{ statusSummary(selectedRound.end_state.enemy) }} / 闘志 {{ selectedRound.end_state.enemy.role_stacks.fighting_spirit }} / 恩寵 {{ selectedRound.end_state.enemy.role_stacks.grace }}</p>
                    </template>
                </article>
                <ol v-else-if="simpleActions(currentBattle).length"><li v-for="(action, index) in simpleActions(currentBattle)" :key="`${action.round}-${index}`">{{ action.round }} / {{ action.side }} / {{ action.action }} / {{ action.amount }}</li></ol>
            </section>
            <section class="underground-history" aria-labelledby="underground-history-title">
                <h2 id="underground-history-title">戦闘履歴</h2>
                <ul><li v-for="battle in battles" :key="battle.id"><button type="button" @click="showBattle(battle)">{{ battle.encounter_name }} / {{ battleRoundCount(battle) }} round</button></li></ul>
            </section>
        </template>
    </section>
</template>
