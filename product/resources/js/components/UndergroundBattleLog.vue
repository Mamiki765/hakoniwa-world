<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import UndergroundBattlePlayback, { type PlaybackCombatant, type PlaybackHit, type PlaybackMpChange, type PlaybackRound, type PlaybackStep, type PlaybackVitals } from './UndergroundBattlePlayback.vue';
import UndergroundCombatantCard from './UndergroundCombatantCard.vue';
import UndergroundPartyBattleCards from './UndergroundPartyBattleCards.vue';
import { renderUndergroundStory } from '../undergroundStoryMarkup';
import {
    actionGroups, actionHighlight, actionNarrative, actionTone, battlePlayerImageUrl, battleResultLabel, battleRoundCount,
    hasStateEnvelope, isImportantAction, partyStateMap, playerDisplayName, roundHasAwakening, roundStartLabel,
    simpleActionNarrative, simpleActions, simpleRoundNumbers, soloState, structuredRounds, summaryLabel, summaryValue, visibleSummary,
    type Battle, type RoundAction, type RoundState, type StateEnvelope,
} from './undergroundBattleLog';

const props = defineProps<{ battle: Battle; fallbackPlayerName: string }>();

const detailPreferenceKey = 'hakoniwa.underground.battle-detail-visible';
function savedDetailVisible(): boolean {
    try {
        const saved = window.localStorage.getItem(detailPreferenceKey);
        if (saved === 'true' || saved === 'false') return saved === 'true';
    } catch { /* optional */ }
    return true;
}
const detailVisible = ref(savedDetailVisible());
function setDetailVisible(visible: boolean): void {
    detailVisible.value = visible;
    try { window.localStorage.setItem(detailPreferenceKey, String(detailVisible.value)); } catch { /* optional */ }
}

const playerName = computed(() => playerDisplayName(props.battle, props.fallbackPlayerName));
const rounds = computed(() => structuredRounds(props.battle));
const drops = computed(() => props.battle.drops ?? (props.battle.drop ? [props.battle.drop] : []));
const currentPartyActors = computed(() => {
    const party = props.battle?.party;
    return party ? [...party.members, ...(party.enemies ?? [])] : [];
});
// ---- 再生: 1行動ずつ進めて、カードの上にダメージや回復の数字を出す ----
const battleMode = ref<'read' | 'play'>('read');
const playbackCombatants = computed<PlaybackCombatant[]>(() => {
    const battle = props.battle;
    if (!battle) return [];
    if (currentPartyActors.value.length > 0) {
        return currentPartyActors.value.map((actor) => ({ id: actor.combatant_id, name: actor.display_name, side: actor.team }));
    }
    return [
        { id: 'player', name: playerName.value, side: 'player' },
        { id: 'enemy', name: battle.encounter_name, side: 'enemy' },
    ];
});

function playbackVitals(value: StateEnvelope | null | undefined): Record<string, PlaybackVitals> | null {
    if (!hasStateEnvelope(value)) return null;
    const entries = Object.entries(value as Record<string, RoundState>);
    return Object.fromEntries(entries.map(([id, entry]) => [id, Number.isSafeInteger(entry.mp)
        ? { hp: entry.hp, max_hp: entry.max_hp, mp: entry.mp }
        : { hp: entry.hp, max_hp: entry.max_hp }]));
}

// 数字を出す相手は、対象が1人に決まる行動だけ。複数が対象の行動は、合計か1人ぶんかをログから決められないので出さない。
function playbackHits(action: RoundAction): PlaybackHit[] {
    const kind = action.type === 'damage' || action.type === 'counter' ? 'damage'
        : action.type === 'recovery' || action.type === 'revival' ? 'heal' : null;
    if (kind === null) return [];
    const targetId = playbackTargetId(action, kind);
    if (targetId === null) return [];
    if (action.evaded || action.complete_guarded) return [{ targetId, kind: 'miss', amount: 0, critical: false }];
    const amount = action.type === 'revival' ? Math.abs(action.amount ?? 0) : action.amount ?? 0;
    return amount > 0 ? [{ targetId, kind, amount, critical: action.critical === true }] : [];
}

function playbackTargetId(action: RoundAction, kind: 'damage' | 'heal'): string | null {
    const party = currentPartyActors.value.length > 0;
    const ids = action.target_ids?.filter((id) => id !== '') ?? (action.target_id ? [action.target_id] : []);
    return party ? ids.length === 1 ? ids[0]! : kind === 'heal' && ids.length === 0 ? action.actor_id ?? null : null
        : soloPlaybackTarget(action, kind);
}

// 1対1のログは、陣営が「秘書」「対戦相手」という表示名で入っている。名前が一致すればそれを使い、無ければ陣営から決める。
function soloPlaybackTarget(action: RoundAction, kind: 'damage' | 'heal'): 'player' | 'enemy' | null {
    const battle = props.battle;
    if (!battle) return null;
    if (action.target_id === 'player' || action.target_id === 'enemy') return action.target_id;
    const sideOf = (name: string | null | undefined): 'player' | 'enemy' | null => {
        const player = name === playerName.value;
        const enemy = name === battle.encounter_name;
        return player === enemy ? null : player ? 'player' : 'enemy';
    };
    if (action.target_name === playerName.value && action.target_name === battle.encounter_name) return null;
    const named = sideOf(action.target_name);
    if (named !== null) return named;
    const actor = action.side === 'player' || action.side === '秘書'
        ? 'player'
        : action.side === 'enemy' || action.side === '対戦相手' ? 'enemy' : sideOf(action.actor_name);
    if (actor === null) return null;
    return kind === 'heal' ? actor : actor === 'player' ? 'enemy' : 'player';
}

// 数字を出すMPの増減。技消費・自然回復と、出どころの分からない古い記録は数字を出さない。
const mpPopupSources = new Set(['skill_recovery', 'awakening_technique']);

function playbackMpOwner(row: RoundAction): string | null {
    if (currentPartyActors.value.length > 0) {
        return (row.type === 'mp_recovery' ? row.target_id || row.actor_id : row.actor_id) || null;
    }
    // 1対1では、MPが動いた本人はログの陣営で決まる。名前は秘書と敵で同じことがあるので、陣営が無いときだけ使う。
    if (row.side === 'player' || row.side === '秘書') return 'player';
    if (row.side === 'enemy' || row.side === '対戦相手') return 'enemy';
    return row.type === 'mp_recovery' ? soloPlaybackTarget(row, 'heal') : null;
}

// ひとまとめにした行動の中のMPの行も読む（技の消費は、まとめた先の行動からは見えなくなるため）。
function playbackMp(rows: RoundAction[]): PlaybackMpChange[] {
    return rows.flatMap((row): PlaybackMpChange[] => {
        const value = Number.isSafeInteger(row.actor_mp) ? row.actor_mp! : null;
        let delta = 0;
        if (row.type === 'awakening') {
            if (row.mp_source !== 'awakening') return [];
            delta = Number.isSafeInteger(row.mp_recovered) ? Math.max(0, row.mp_recovered!) : 0;
        } else if (row.type === 'mp_cost' || row.type === 'mp_recovery') {
            const amount = Number.isSafeInteger(row.amount) ? Math.max(0, row.amount!) : 0;
            if (row.mp_source !== undefined && mpPopupSources.has(row.mp_source)) delta = row.type === 'mp_cost' ? -amount : amount;
        } else {
            return [];
        }
        const targetId = playbackMpOwner(row);
        return targetId === null || (delta === 0 && value === null) ? [] : [{ targetId, delta, value }];
    });
}

function playbackUpdates(action: RoundAction, end: StateEnvelope | null | undefined): PlaybackStep['updates'] {
    if (action.type === 'awakening') {
        const party = currentPartyActors.value.length > 0;
        const actor = party ? action.actor_id : action.side === 'player' || action.side === '秘書' ? 'player' : null;
        if (!actor) return [];
        const snapshot = action.state;
        if (snapshot && Number.isSafeInteger(snapshot.hp) && Number.isSafeInteger(snapshot.max_hp) && snapshot.max_hp > 0) {
            return [{ targetId: actor, hp: snapshot.hp, max_hp: snapshot.max_hp }];
        }
        // Solo AlphaV1 logs omit the awakening snapshot. Awakening is the only
        // in-battle max-HP change and fills HP once, so the awakened round-end
        // maximum also describes that event. Never calculate it from display text.
        const boundary = party ? null : soloState(end, 'player');
        if (boundary?.awakened === true && Number.isSafeInteger(boundary.max_hp) && boundary.max_hp > 0) {
            return [{ targetId: actor, hp: boundary.max_hp, max_hp: boundary.max_hp }];
        }
        return [{ targetId: actor, hp: null }];
    }
    if (action.type === 'revival' || action.revived === true) {
        const targetId = playbackTargetId(action, 'heal');
        const hp = Math.abs(action.amount ?? 0);
        return targetId !== null && hp > 0 ? [{ targetId, hp }] : [];
    }
    const battle = props.battle;
    if (currentPartyActors.value.length === 0 && battle
        && ['damage', 'counter', 'recovery'].includes(action.type)
        && action.target_name === playerName.value && action.target_name === battle.encounter_name
        && playbackTargetId(action, action.type === 'recovery' ? 'heal' : 'damage') === null) {
        return [{ targetId: 'player', hp: null }, { targetId: 'enemy', hp: null }];
    }
    return [];
}

const playbackRounds = computed<PlaybackRound[]>(() => {
    const battle = props.battle;
    if (!battle) return [];
    return rounds.value.map((round, index) => ({
        round: round.round,
        start: playbackVitals(round.start_state) ?? (index === 0 ? playbackVitals(battle.initial_state) : null),
        end: playbackVitals(round.end_state),
        steps: actionGroups(round.actions).map((group) => ({
            text: group.action.countdown ? `${group.action.countdown}` : actionNarrative(group.action, battle, playerName.value),
            tone: actionTone(group.action),
            highlight: actionHighlight(group.action),
            hits: playbackHits(group.action),
            updates: playbackUpdates(group.action, round.end_state),
            mp: playbackMp(group.source),
        })),
    }));
});
const playbackAvailable = computed(() => playbackRounds.value.length > 0 && playbackRounds.value[0]!.start !== null);
watch(() => props.battle?.id, () => { battleMode.value = 'read'; });

const finalBattleState = computed<StateEnvelope | null>(() => {
    const summaryFinalState = props.battle?.summary?.final_state;
    if (hasStateEnvelope(summaryFinalState)) return summaryFinalState;
    const roundState = [...rounds.value]
        .reverse()
        .find((round) => hasStateEnvelope(round.end_state))?.end_state;
    if (hasStateEnvelope(roundState)) return roundState;
    return null;
});
</script>

<template>
    <section id="underground-battle-start" class="underground-battle-log" aria-label="戦闘ログ">
        <p v-if="battle.challenge_intro" class="underground-trial-intro" v-html="renderUndergroundStory(battle.challenge_intro)"></p>
        <p v-if="battle.milestone_story" class="underground-trial-intro" v-html="renderUndergroundStory(battle.milestone_story)"></p>
        <header class="underground-battle-opening">
            <p class="eyebrow">遭遇</p>
            <h1>{{ battle.encounter_name }}</h1>
            <p v-if="battle.build_name">{{ battle.build_name }}で戦闘を開始した。</p>
            <p v-else>{{ playerName }}は戦闘を開始した。</p>
            <div class="underground-battle-filter" role="group" aria-label="戦闘ログに出す行動">
                <button type="button" class="underground-battle-detail-toggle" :aria-pressed="!detailVisible" title="会心・覚醒・予告・撃破などの要点だけを出します" @click="setDetailVisible(false)">要点</button>
                <button type="button" :aria-pressed="detailVisible" @click="setDetailVisible(true)">すべて</button>
            </div>
            <div v-if="playbackAvailable" class="underground-battle-filter underground-battle-mode" role="group" aria-label="戦闘ログの見方">
                <button type="button" :aria-pressed="battleMode === 'read'" @click="battleMode = 'read'">読む</button>
                <button type="button" :aria-pressed="battleMode === 'play'" @click="battleMode = 'play'">再生</button>
            </div>
            <a class="underground-log-jump" href="#underground-battle-result">末尾へ</a>
        </header>

        <UndergroundBattlePlayback
            v-if="battleMode === 'play' && playbackAvailable"
            :rounds="playbackRounds"
            :combatants="playbackCombatants"
            :result-label="battleResultLabel(battle.result)"
        />

        <UndergroundPartyBattleCards
            v-if="currentPartyActors.length > 0"
            :actors="currentPartyActors"
            :state-by-id="partyStateMap(battle.initial_state)"
            :portrait-events="battle.portrait_events"
            portrait-event-type="start"
        />

        <section
            v-if="currentPartyActors.length === 0 && soloState(battle.initial_state, 'player') && soloState(battle.initial_state, 'enemy')"
            class="underground-round-start underground-solo-battle-start"
            aria-label="戦闘開始時の状態"
        >
            <div class="underground-matchup-grid">
                <UndergroundCombatantCard
                    :name="playerName"
                    side="player"
                    :state="soloState(battle.initial_state, 'player')!"
                    :image-url="battlePlayerImageUrl(battle)"
                />
                <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                <UndergroundCombatantCard
                    :name="battle.encounter_name"
                    side="enemy"
                    :state="soloState(battle.initial_state, 'enemy')!"
                />
            </div>
        </section>

        <div v-show="battleMode === 'read'" class="underground-rounds">
            <p v-if="battle.detail_message" class="status">{{ battle.detail_message }}</p>
            <article v-for="round in rounds" :key="round.round" class="underground-round">
                <h2>{{ roundStartLabel(round) }}</h2>
                <UndergroundPartyBattleCards
                    v-if="currentPartyActors.length > 0"
                    :actors="currentPartyActors"
                    :portrait-events="battle.portrait_events"
                    :state-by-id="partyStateMap(round.start_state)"
                    portrait-event-type="awakening"
                    :portrait-round="round.round"
                />
                <section v-if="soloState(round.start_state, 'player') && soloState(round.start_state, 'enemy') && currentPartyActors.length === 0" class="underground-round-start" :aria-label="`第${round.round}ラウンド開始時の状態`">
                    <div class="underground-matchup-grid">
                        <UndergroundCombatantCard
                            :name="playerName"
                            side="player"
                            :state="soloState(round.start_state, 'player')!"
                            compact
                        />
                        <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                        <UndergroundCombatantCard :name="battle.encounter_name" side="enemy" :state="soloState(round.start_state, 'enemy')!" compact />
                    </div>
                </section>
                <section
                    v-if="currentPartyActors.length === 0 && roundHasAwakening(round) && (soloState(round.end_state, 'player') ?? soloState(round.start_state, 'player'))"
                    class="underground-solo-awakening-art"
                    aria-label="覚醒時の状態"
                >
                    <UndergroundCombatantCard
                        :name="playerName"
                        side="player"
                        :state="(soloState(round.end_state, 'player') ?? soloState(round.start_state, 'player'))!"
                        :image-url="battlePlayerImageUrl(battle, true)"
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
                            <p v-for="(supplement, supplementIndex) in group.source.slice(2, -1)" :key="supplementIndex" class="underground-action-supplement">{{ actionNarrative(supplement, battle, playerName) }}</p>
                            <template v-if="group.action.countdown">
                                <strong class="ug-boss-countdown" :class="{ 'is-major': group.action.major_telegraph }">{{ group.action.countdown }}</strong>
                                <p v-if="group.action.major_telegraph">大予告が付与された。</p>
                            </template>
                            <p v-else>{{ actionNarrative(group.action, battle, playerName) }}</p>
                            <details v-if="group.source.length > 1" class="underground-action-details">
                                <summary>行動の全詳細</summary>
                                <p v-for="(source, sourceIndex) in group.source" :key="sourceIndex">{{ actionNarrative(source, battle, playerName) }}</p>
                            </details>
                        </div>
                    </li>
                </ul>
                <details v-if="!round.start_state && soloState(round.end_state, 'player') && soloState(round.end_state, 'enemy') && currentPartyActors.length === 0" class="underground-round-state">
                    <summary>ラウンド{{ round.round }}終了時の状態</summary>
                    <div class="underground-matchup-grid">
                        <UndergroundCombatantCard :name="playerName" side="player" :state="soloState(round.end_state, 'player')!" compact />
                        <UndergroundCombatantCard :name="battle.encounter_name" side="enemy" :state="soloState(round.end_state, 'enemy')!" compact />
                    </div>
                </details>
            </article>

            <article v-for="roundNumber in simpleRoundNumbers(battle)" :key="`simple-${roundNumber}`" class="underground-round">
                <h2>Round {{ roundNumber }}</h2>
                <ol class="underground-action-log">
                    <li v-for="(action, index) in simpleActions(battle).filter((item) => item.round === roundNumber)" :key="index">
                        <span class="underground-action-marker" aria-hidden="true" />
                        <div><p>{{ simpleActionNarrative(action, battle, playerName) }}</p></div>
                    </li>
                </ol>
            </article>
        </div>

        <footer id="underground-battle-result" class="underground-battle-result">
            <p class="eyebrow">戦闘終了</p>
            <h2>{{ battleResultLabel(battle.result) }}</h2>
            <UndergroundPartyBattleCards
                v-if="currentPartyActors.length > 0"
                :actors="currentPartyActors"
                :state-by-id="partyStateMap(finalBattleState)"
                :portrait-events="battle.portrait_events"
                portrait-event-type="final"
            />
            <section v-if="soloState(finalBattleState, 'player') && soloState(finalBattleState, 'enemy') && currentPartyActors.length === 0" class="underground-matchup underground-final-state" aria-labelledby="underground-final-state-title">
                <div class="underground-matchup-heading">
                    <h3 id="underground-final-state-title">戦闘中の最終状態</h3>
                </div>
                <div class="underground-matchup-grid">
                    <UndergroundCombatantCard
                        :name="playerName"
                        side="player"
                        :state="soloState(finalBattleState, 'player')!"
                        :image-url="battlePlayerImageUrl(battle, soloState(finalBattleState, 'player')?.awakened === true)"
                    />
                    <span class="underground-matchup-versus" aria-hidden="true">VS</span>
                    <UndergroundCombatantCard :name="battle.encounter_name" side="enemy" :state="soloState(finalBattleState, 'enemy')!" />
                </div>
            </section>
            <p v-if="battle.hunting_ground">狩場: {{ battle.hunting_ground.name }}</p>
            <p>{{ battleRoundCount(battle) }}ラウンドで決着。</p>
            <p>経験値 +{{ battle.xp_awarded }}・輝石の欠片 {{ battle.shard_delta >= 0 ? '+' : '' }}{{ battle.shard_delta }}G<span v-if="battle.context === 'playtest'">・ドロップなし</span></p>
            <p v-if="battle.treasure?.found" class="underground-equipment-drop" role="status">財宝を見つけた！ ×{{ battle.treasure.multiplier }}</p>
            <p v-if="(battle.shining_kingdom_key?.awarded ?? 0) > 0" class="underground-equipment-drop" role="status">{{ battle.shining_kingdom_key?.label ?? '輝きの王国の鍵' }} +{{ battle.shining_kingdom_key?.awarded }}</p>
            <p v-if="(battle.distorted_stones ?? 0) > 0" class="underground-equipment-drop" role="status">歪んだ輝石 +{{ battle.distorted_stones }}</p>
            <template v-for="(drop, dropIndex) in drops" :key="dropIndex">
            <p v-if="drop.status === 'granted' && drop.item" class="underground-equipment-drop" role="status">
                装備drop: {{ drop.item.rarity_label }}・Item Lv {{ drop.item.item_level }}・{{ drop.item.name }}
                <span v-if="drop.item.affixes.length > 0">（{{ drop.item.affixes.map((affix) => affix.label).join('、') }}）</span>
            </p>
            <p v-if="drop.status === 'vault_full' && drop.item" class="underground-equipment-drop lost" role="alert">
                保管庫が満杯のため、{{ drop.item.rarity_label }}・Item Lv {{ drop.item.item_level }}・{{ drop.item.name }}を持ち帰れませんでした。
            </p>
            </template>
            <p v-if="(battle.interbattle_heal_amount ?? 0) > 0" class="underground-interbattle-heal">体力が少し回復した</p>
            <div
                v-if="battle.combat_level_after !== undefined && battle.combat_level_after !== battle.combat_level_before"
                class="underground-level-up"
                role="status"
                aria-label="レベルアップ"
            >
                <strong>LEVEL UP！</strong>
                <span>戦闘Lv {{ battle.combat_level_before }} → {{ battle.combat_level_after }}</span>
                <span>未使用STP +{{ battle.stp_awarded ?? 0 }}（合計 {{ battle.unspent_stp_after ?? 0 }}）</span>
            </div>
            <details v-if="battle.summary && detailVisible" class="underground-combat-details">
                <summary>戦闘詳細</summary>
                <dl class="underground-combat-summary">
                    <div v-for="(value, key) in visibleSummary(battle.summary)" :key="key"><dt>{{ summaryLabel(key) }}</dt><dd>{{ summaryValue(key, value) }}</dd></div>
                </dl>
            </details>
            <a class="underground-log-jump" href="#underground-battle-start">先頭へ</a>
        </footer>
        <section v-if="battle.first_clear_story" class="underground-first-clear-story" aria-labelledby="underground-first-clear-title">
            <h2 id="underground-first-clear-title">{{ battle.first_clear_story.title }}</h2>
            <p class="underground-first-clear-body" v-html="renderUndergroundStory(battle.first_clear_story.body)"></p>
            <div class="underground-first-clear-results" role="status">
                <p v-for="message in battle.first_clear_story.system_messages" :key="message">{{ message }}</p>
            </div>
        </section>
        <section v-if="battle.context === 'guide_duel'" class="underground-first-clear-story" aria-label="決闘の結末">
            <p v-for="(line, index) in battle.duel_dialogue ?? []" :key="index">{{ line }}</p>
            <p>決闘前のHP・MP・覚醒状態に戻りました。経験値・Gの増減はありません。</p>
        </section>
    </section>
</template>
