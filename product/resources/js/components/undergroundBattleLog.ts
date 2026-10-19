import type { DailyQuestProgress } from '../types';

// 地底の戦闘記録の型と、記録を文章・表示用の値に直す関数。画面の状態に触らない。

export interface SimpleAction {
    round: number;
    side: string;
    actor_name?: string;
    target_name?: string;
    action_label?: string;
    amount: number;
}

export interface RoundAction {
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
    countdown?: number;
    // MPが増減した行にだけ付く。古い記録には無い（無いものを推測しない）。
    mp_source?: string;
    actor_mp?: number;
    mp_recovered?: number;
    major_telegraph?: boolean;
    state?: RoundState | null;
}

export interface RoundState {
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

export interface RoundStatePair {
    player: RoundState;
    enemy: RoundState;
}

export type StateEnvelope = RoundStatePair | Record<string, RoundState>;

export interface ImageReference {
    url?: string | null;
    credit?: string | null;
    creation_method_label?: string | null;
}

export interface ImageReferences {
    compact?: ImageReference | null;
    awakening_compact?: ImageReference | null;
    normal?: ImageReference | null;
    awakening?: ImageReference | null;
}

export interface CombatRound {
    round: number;
    actions: RoundAction[];
    start_state?: StateEnvelope | null;
    end_state: StateEnvelope | null;
    state_timing?: 'battle_start' | 'previous_round_end' | string;
}

export interface Battle {
    id: string;
    history_cursor?: number;
    context: 'tutorial' | 'scripted_loss' | 'playtest' | 'exploration' | 'trial' | 'guide_duel' | 'event_battle';
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
    milestone_story?: string | null;
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
            category: 'weapon' | 'armor' | 'accessory' | 'resonance';
            item_level: number;
            rarity: 'common' | 'uncommon' | 'rare' | 'epic' | 'unique';
            rarity_label: string;
            affixes: Array<{ key: string; label: string; target: string; value: number }>;
        };
    } | null;
    drops?: Array<NonNullable<Battle['drop']>> | null;
    treasure?: { found: boolean; base_g: number; multiplier: number; total_g: number } | null;
    shining_kingdom_key?: { label?: string; balance_before: number; entry_cost?: number; awarded: number; balance_after: number } | null;
    distorted_stones?: number;
    daily_quest?: DailyQuestProgress;
}

export interface PartyBattleMember {
    team: 'player' | 'enemy';
    combatant_id: string;
    display_name: string;
    icon_url?: string | null;
    portrait_url?: string | null;
    image_references?: ImageReferences | null;
    state?: RoundState;
    awakening_state?: 'ready' | 'awakened' | null;
}

export function isStatePair(value: StateEnvelope | null | undefined): value is RoundStatePair {
    return value !== null
        && value !== undefined
        && typeof value === 'object'
        && 'player' in value
        && 'enemy' in value;
}
export function hasStateEnvelope(value: unknown): value is StateEnvelope {
    return value !== null
        && value !== undefined
        && typeof value === 'object'
        && Object.keys(value).length > 0;
}
export function partyStateMap(value: StateEnvelope | null | undefined): Record<string, RoundState> | null {
    return hasStateEnvelope(value) && !isStatePair(value) ? value : null;
}
export function soloState(value: StateEnvelope | null | undefined, side: 'player' | 'enemy'): RoundState | null {
    return isStatePair(value) ? value[side] : null;
}

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

export function battleResultLabel(result: Battle['result']): string {
    return result === 'victory' ? '勝利' : result === 'defeat' ? '敗北' : result === 'stalemate' ? '決着なし' : '撤退';
}

export function battleRoundCount(battle: Battle): number {
    return battle.rounds_count ?? (typeof battle.rounds === 'number' ? battle.rounds : battle.rounds?.length ?? 0);
}

export function structuredRounds(battle: Battle): CombatRound[] {
    if (Array.isArray(battle.rounds)) return battle.rounds;
    if (Array.isArray(battle.actions) && battle.actions[0] && 'actions' in battle.actions[0]) {
        return battle.actions as CombatRound[];
    }
    return [];
}

export function summaryLabel(key: string): string {
    return summaryLabels[key] ?? key;
}

export function summaryValue(key: string, value: boolean | number | string): boolean | number | string {
    return key === 'result' ? battleResultLabel(String(value) as Battle['result']) : value;
}

export function visibleSummary(summary: Record<string, unknown>): Record<string, boolean | number | string> {
    const entries = Object.entries(summary).filter(([key, value]) => !hiddenSummaryKeys.has(key)
        && (typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string'));

    return Object.fromEntries(entries) as Record<string, boolean | number | string>;
}

export function simpleActions(battle: Battle): SimpleAction[] {
    if (!Array.isArray(battle.actions) || (battle.actions[0] && 'actions' in battle.actions[0])) return [];
    return battle.actions as SimpleAction[];
}

export function simpleRoundNumbers(battle: Battle): number[] {
    return [...new Set(simpleActions(battle).map((action) => action.round))];
}

export function playerDisplayName(battle: Battle, fallback: string): string {
    return battle.player_display_name ?? fallback;
}

export function imageUrl(reference: ImageReference | string | null | undefined): string | null {
    if (typeof reference === 'string') return reference;

    return reference?.url ?? null;
}

export function battlePlayerImageUrl(battle: Battle, awakened = false): string | null {
    const references = battle.player_image_references;
    if (!references) return null;

    return awakened
        ? imageUrl(references.awakening) ?? imageUrl(references.normal)
        : imageUrl(references.normal);
}

export function roundHasAwakening(round: CombatRound): boolean {
    return round.actions.some((action) => action.type === 'awakening'
        || action.type === 'awakening_technique'
        || action.kind === 'awakening'
        || action.kind === 'awakening_technique');
}

export function roundStartLabel(round: CombatRound): string {
    return round.round === 1 || round.state_timing === 'battle_start'
        ? `第${round.round}ラウンド 開始時`
        : `第${round.round}ラウンド（前ラウンド終了時）`;
}

export function actorName(side: string, battle: Battle, player: string): string {
    return side === '秘書' || side === 'player'
        ? player
        : side === '対戦相手' || side === 'enemy'
            ? battle.encounter_name
            : '戦闘';
}

export function targetName(side: string, battle: Battle, player: string): string {
    return side === '秘書' || side === 'player'
        ? battle.encounter_name
        : player;
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

export function actionKey(action: RoundAction): string | null {
    const key = action.action_key ?? action.action;

    return typeof key === 'string' && key !== '' ? key : null;
}

export function actionLabel(action: RoundAction): string {
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

export function battlePartyMembers(battle: Battle): PartyBattleMember[] {
    const party = battle.party;

    return party ? [...party.members, ...(party.enemies ?? [])] : [];
}

export function battleActorNameById(battle: Battle, combatantId: string): string | null {
    const actor = battlePartyMembers(battle).find((member) => member.combatant_id === combatantId);

    return actor?.display_name ?? null;
}

export function actionActorName(action: RoundAction, battle: Battle, player: string): string {
    return (action.actor_id ? battleActorNameById(battle, action.actor_id) : null)
        ?? action.actor_name
        ?? actorName(action.side, battle, player);
}

export function actionTargetNames(action: RoundAction, battle: Battle): string[] {
    const ids = action.target_ids?.filter((id) => id !== '')
        ?? (action.target_id ? [action.target_id] : []);
    const byId = ids.map((id) => battleActorNameById(battle, id) ?? id);
    const explicit = action.target_names?.filter((name) => name !== '') ?? [];
    const fallback = action.target_name ? [action.target_name] : [];
    const names = byId.length > 0 ? byId : explicit.length > 0 ? explicit : fallback;

    return [...new Set(names)];
}

export function actionTargetsText(action: RoundAction, battle: Battle, player: string): string {
    const names = actionTargetNames(action, battle);

    return names.length > 0 ? names.join('、') : targetName(action.side, battle, player);
}

export function actionNarrative(action: RoundAction, battle: Battle, player: string): string {
    const actor = actionActorName(action, battle, player);
    const target = actionTargetsText(action, battle, player);
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

export function withoutActionPrefix(label: string, prefix: string): string {
    return label.startsWith(prefix) ? label.slice(prefix.length).trimStart() : label;
}

export function actionTone(action: RoundAction): string {
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
export function actionGroups(actions: RoundAction[]): Array<{ action: RoundAction; source: RoundAction[]; cost: number | null }> {
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

export function actionHighlight(action: RoundAction): string | null {
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

export function isImportantAction(action: RoundAction): boolean {
    return action.important === true || action.type === 'awakening' || action.type === 'awakening_technique'
        || action.kind === 'revival' || action.type === 'revival' || action.revived === true
        || action.type === 'warning' || action.type === 'phase_transition'
        || action.type === 'damage' && Boolean(action.critical)
        || action.type === 'victory' || action.type === 'defeat';
}

export function simpleActionNarrative(action: SimpleAction, battle: Battle, player: string): string {
    const actor = action.actor_name ?? actorName(action.side, battle, player);
    const target = action.target_name ?? targetName(action.side, battle, player);
    const label = action.action_label ?? '戦闘行動';
    return action.amount > 0
        ? `${actor}の「${label}」。${target}に${action.amount}ダメージ。`
        : `${actor}は「${label}」を行った。`;
}
