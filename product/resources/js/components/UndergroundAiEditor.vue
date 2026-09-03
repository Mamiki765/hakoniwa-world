<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import type {
    UndergroundAiCondition,
    UndergroundAiConfiguration,
    UndergroundAiRule,
} from './undergroundAi';

interface DraftRule {
    id: string;
    conditions: UndergroundAiCondition[];
    action: string;
    jumpTargetId: string | null;
}

interface PendingMutation {
    fingerprint: string;
    requestId: string;
}

const props = defineProps<{ configuration: UndergroundAiConfiguration }>();
const emit = defineEmits<{ updated: [state: unknown] }>();

const mode = ref<'default' | 'custom'>('default');
const draftRules = ref<DraftRule[]>([]);
const busy = ref(false);
const error = ref('');
const notice = ref('');
const pending = ref<PendingMutation | null>(null);
let nextDraftId = 1;

function copyCondition(condition: UndergroundAiCondition): UndergroundAiCondition {
    return { ...condition };
}

function makeDrafts(rules: UndergroundAiRule[]): DraftRule[] {
    const drafts: DraftRule[] = rules.map((rule) => ({
        id: `ai-rule-${nextDraftId++}`,
        conditions: rule.conditions.map(copyCondition),
        action: rule.action,
        jumpTargetId: null,
    }));
    rules.forEach((rule, index) => {
        if (rule.action === 'jump' && rule.jump_to !== undefined) {
            drafts[index]!.jumpTargetId = drafts[rule.jump_to - 1]?.id ?? null;
        }
    });
    return drafts;
}

function syncFromConfiguration(): void {
    mode.value = props.configuration.is_custom ? 'custom' : 'default';
    draftRules.value = makeDrafts(
        props.configuration.is_custom ? props.configuration.rules : props.configuration.default_rules,
    );
    error.value = '';
}

watch(() => props.configuration, syncFromConfiguration, { deep: true, immediate: true });

function payloadRules(): UndergroundAiRule[] {
    return draftRules.value.map((rule, index) => {
        const payload: UndergroundAiRule = {
            conditions: rule.conditions.map(copyCondition),
            action: rule.action,
        };
        if (rule.action === 'jump') {
            const targetIndex = draftRules.value.findIndex((candidate) => candidate.id === rule.jumpTargetId);
            if (targetIndex <= index) throw new Error(`${index + 1}番目の移動先は後ろのruleを選んでください。`);
            payload.jump_to = targetIndex + 1;
        }
        return payload;
    });
}

const desiredRules = computed<UndergroundAiRule[] | null>(() => (
    mode.value === 'default' ? null : payloadRules()
));
const savedRules = computed<UndergroundAiRule[] | null>(() => (
    props.configuration.is_custom ? props.configuration.rules : null
));
const dirty = computed(() => JSON.stringify(desiredRules.value) !== JSON.stringify(savedRules.value));

function edited(): void {
    error.value = '';
    notice.value = '';
}

function useDefault(): void {
    mode.value = 'default';
    draftRules.value = makeDrafts(props.configuration.default_rules);
    edited();
}

function cloneDefault(): void {
    mode.value = 'custom';
    draftRules.value = makeDrafts(props.configuration.default_rules);
    edited();
}

function clearRules(): void {
    mode.value = 'custom';
    draftRules.value = [];
    edited();
}

function addRule(): void {
    if (mode.value !== 'custom' || draftRules.value.length >= props.configuration.max_rules) return;
    draftRules.value.push({
        id: `ai-rule-${nextDraftId++}`,
        conditions: [{ type: 'always' }],
        action: 'normal_attack',
        jumpTargetId: null,
    });
    edited();
}

function jumpTargetedBy(ruleId: string): number | null {
    const index = draftRules.value.findIndex((rule) => rule.action === 'jump' && rule.jumpTargetId === ruleId);
    return index < 0 ? null : index + 1;
}

function removeRule(index: number): void {
    const rule = draftRules.value[index];
    if (!rule || mode.value !== 'custom') return;
    const source = jumpTargetedBy(rule.id);
    if (source !== null) {
        error.value = `${source}番目のruleがこのruleへ移動します。先に移動先を変更してください。`;
        return;
    }
    draftRules.value.splice(index, 1);
    edited();
}

function jumpsRemainForward(rules: DraftRule[]): boolean {
    return rules.every((rule, index) => {
        if (rule.action !== 'jump') return true;
        return rules.findIndex((candidate) => candidate.id === rule.jumpTargetId) > index;
    });
}

function moveRule(index: number, offset: -1 | 1): void {
    const target = index + offset;
    if (mode.value !== 'custom' || target < 0 || target >= draftRules.value.length) return;
    const reordered = [...draftRules.value];
    const [rule] = reordered.splice(index, 1);
    if (!rule) return;
    reordered.splice(target, 0, rule);
    if (!jumpsRemainForward(reordered)) {
        error.value = 'この並べ替えでは移動先が前方になるため実行できません。先にjumpを変更してください。';
        return;
    }
    draftRules.value = reordered;
    edited();
}

function conditionDefinition(type: string): { key: string; label: string; value_kind: string } | undefined {
    return props.configuration.catalog.condition_types.find((condition) => condition.key === type);
}

function conditionFor(type: string): UndergroundAiCondition {
    const kind = conditionDefinition(type)?.value_kind;
    if (kind === 'percent') return { type, percent: 50 };
    if (kind === 'status') return { type, status: props.configuration.catalog.statuses[0]?.key ?? '' };
    if (kind === 'status_stacks') {
        return { type, status: props.configuration.catalog.statuses[0]?.key ?? '', stacks: 1 };
    }
    if (kind === 'role_stacks') {
        return { type, status: props.configuration.catalog.role_stacks[0]?.key ?? '', stacks: 1 };
    }
    if (kind === 'skill') return { type, skill: props.configuration.catalog.skills[0]?.key ?? '' };
    if (kind === 'round') return { type, round: 1 };
    if (kind === 'round_modulo') return { type, modulo: 2, equals: 0 };
    return { type };
}

function changeConditionType(rule: DraftRule, conditionIndex: number, event: Event): void {
    if (!(event.target instanceof HTMLSelectElement)) return;
    const condition = conditionFor(event.target.value);
    if (condition.type === 'always') {
        rule.conditions = [condition];
    } else {
        rule.conditions.splice(conditionIndex, 1, condition);
    }
    edited();
}

function addCondition(rule: DraftRule): void {
    if (rule.conditions.length >= props.configuration.max_conditions_per_rule) return;
    if (rule.conditions.some((condition) => condition.type === 'always')) return;
    rule.conditions.push(conditionFor('own_hp_lte'));
    edited();
}

function removeCondition(rule: DraftRule, index: number): void {
    rule.conditions.splice(index, 1);
    if (rule.conditions.length === 0) rule.conditions = [{ type: 'always' }];
    edited();
}

function changeAction(rule: DraftRule, index: number): void {
    if (rule.action === 'jump') {
        rule.jumpTargetId = draftRules.value[index + 1]?.id ?? null;
        if (rule.jumpTargetId === null) {
            rule.action = 'normal_attack';
            error.value = '最後のruleからは後ろへ移動できません。';
            return;
        }
    } else {
        rule.jumpTargetId = null;
    }
    edited();
}

function statusMaximum(key: string | undefined): number {
    return props.configuration.catalog.statuses.find((status) => status.key === key)?.max_stacks ?? 1;
}

function roleStackMaximum(key: string | undefined): number {
    return props.configuration.catalog.role_stacks.find((status) => status.key === key)?.max_stacks ?? 1;
}

function requestId(): string {
    return crypto.randomUUID();
}

async function save(): Promise<void> {
    if (busy.value || !dirty.value) return;
    let rules: UndergroundAiRule[] | null;
    try {
        rules = desiredRules.value;
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : 'AI ruleの設定を確認してください。';
        return;
    }
    const fingerprint = JSON.stringify({ rules });
    const mutation = pending.value?.fingerprint === fingerprint
        ? pending.value
        : { fingerprint, requestId: requestId() };
    pending.value = mutation;
    busy.value = true;
    error.value = '';
    notice.value = '';
    try {
        const nextState = await api<unknown>('/api/v1/me/underground/ai', {
            method: 'PUT',
            body: JSON.stringify({ request_id: mutation.requestId, rules }),
        });
        pending.value = null;
        notice.value = rules === null ? '初期設定へ戻しました。' : '作戦を保存しました。';
        emit('updated', nextState);
    } catch (caught) {
        if (caught instanceof ApiError && caught.code === 'underground_request_conflict') {
            pending.value = null;
            error.value = `request IDの競合です。この内容は確定していません。再試行時は新しいIDを使います。${caught.message}`;
        } else {
            error.value = caught instanceof Error ? caught.message : '作戦を保存できませんでした。再試行してください。';
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="underground-ai-editor" aria-labelledby="underground-ai-title">
        <header class="underground-ai-heading">
            <div>
                <p class="eyebrow">Combat Strategy</p>
                <h1 id="underground-ai-title">作戦設定</h1>
                <p>上から順に条件を確認し、最初に実行できるactionを使います。</p>
            </div>
            <dl>
                <div><dt>現在</dt><dd>{{ configuration.is_custom ? 'カスタム作戦' : '初期設定' }}</dd></div>
                <div><dt>上限</dt><dd>{{ configuration.max_rules }} rules・AND条件{{ configuration.max_conditions_per_rule }}個</dd></div>
                <div><dt>適用中hash</dt><dd><code>{{ configuration.hash }}</code></dd></div>
            </dl>
        </header>

        <div class="underground-ai-notes">
            <p>成立したruleのactionがMP・cooldown・装備・習得状態などで使えない場合は、次のruleへ進みます。</p>
            <p>最後まで実行できない場合は、使用可能な習得済み攻撃skill、なければ通常攻撃を必ず使います。</p>
            <p>作戦の変更は次に始まる戦闘から有効です。進行中・保存済みの戦闘内容は変わりません。</p>
        </div>

        <div class="underground-ai-mode-actions">
            <button type="button" :aria-pressed="mode === 'default'" :disabled="busy" @click="useDefault">初期設定を使用</button>
            <button type="button" :aria-pressed="mode === 'custom' && draftRules.length > 0" :disabled="busy" @click="cloneDefault">初期設定を複製して編集</button>
            <button type="button" :aria-pressed="mode === 'custom' && draftRules.length === 0" :disabled="busy" @click="clearRules">ruleなしにする</button>
        </div>

        <p v-if="mode === 'default'" class="status">初期設定を表示しています。編集するには「初期設定を複製して編集」を選んでください。</p>
        <p v-else-if="draftRules.length === 0" class="status warning">カスタムruleは0件です。戦闘ではdeterministic fallbackだけを使用します。</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>
        <p v-if="notice" class="status" role="status">{{ notice }}</p>

        <ol v-if="draftRules.length > 0" class="underground-ai-rules">
            <li v-for="(rule, ruleIndex) in draftRules" :key="rule.id" class="underground-ai-rule">
                <header>
                    <h2>Rule {{ ruleIndex + 1 }}</h2>
                    <div class="underground-ai-order-actions">
                        <button type="button" :disabled="busy || mode !== 'custom' || ruleIndex === 0" :aria-label="`Rule ${ruleIndex + 1}を上へ`" @click="moveRule(ruleIndex, -1)">↑</button>
                        <button type="button" :disabled="busy || mode !== 'custom' || ruleIndex === draftRules.length - 1" :aria-label="`Rule ${ruleIndex + 1}を下へ`" @click="moveRule(ruleIndex, 1)">↓</button>
                        <button type="button" :disabled="busy || mode !== 'custom'" :aria-label="`Rule ${ruleIndex + 1}を削除`" @click="removeRule(ruleIndex)">削除</button>
                    </div>
                </header>

                <fieldset :disabled="busy || mode !== 'custom'">
                    <legend>条件（複数はAND）</legend>
                    <div v-for="(condition, conditionIndex) in rule.conditions" :key="`${rule.id}-condition-${conditionIndex}`" class="underground-ai-condition">
                        <select :value="condition.type" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}`" @change="changeConditionType(rule, conditionIndex, $event)">
                            <option v-for="definition in configuration.catalog.condition_types" :key="definition.key" :value="definition.key">{{ definition.label }}</option>
                        </select>
                        <template v-if="conditionDefinition(condition.type)?.value_kind === 'percent'">
                            <input v-model.number="condition.percent" type="number" min="0" max="100" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}の割合`" @input="edited">
                            <span>%</span>
                        </template>
                        <select v-else-if="conditionDefinition(condition.type)?.value_kind === 'status'" v-model="condition.status" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}の状態`" @change="edited">
                            <option v-for="status in configuration.catalog.statuses" :key="status.key" :value="status.key">{{ status.label }}</option>
                        </select>
                        <template v-else-if="conditionDefinition(condition.type)?.value_kind === 'status_stacks'">
                            <select v-model="condition.status" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}の状態`" @change="edited">
                                <option v-for="status in configuration.catalog.statuses" :key="status.key" :value="status.key">{{ status.label }}</option>
                            </select>
                            <input v-model.number="condition.stacks" type="number" min="1" :max="statusMaximum(condition.status)" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}のstack数`" @input="edited">
                        </template>
                        <template v-else-if="conditionDefinition(condition.type)?.value_kind === 'role_stacks'">
                            <select v-model="condition.status" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}のrole stack`" @change="edited">
                                <option v-for="status in configuration.catalog.role_stacks" :key="status.key" :value="status.key">{{ status.label }}</option>
                            </select>
                            <input v-model.number="condition.stacks" type="number" min="1" :max="roleStackMaximum(condition.status)" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}のstack数`" @input="edited">
                        </template>
                        <select v-else-if="conditionDefinition(condition.type)?.value_kind === 'skill'" v-model="condition.skill" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}のskill`" @change="edited">
                            <option v-for="skill in configuration.catalog.skills" :key="skill.key" :value="skill.key">{{ skill.label }}</option>
                        </select>
                        <input v-else-if="conditionDefinition(condition.type)?.value_kind === 'round'" v-model.number="condition.round" type="number" min="1" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}のround`" @input="edited">
                        <template v-else-if="conditionDefinition(condition.type)?.value_kind === 'round_modulo'">
                            <span>周期</span>
                            <input v-model.number="condition.modulo" type="number" min="1" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}の周期`" @input="edited">
                            <span>余り</span>
                            <input v-model.number="condition.equals" type="number" min="0" :max="Math.max(0, (condition.modulo ?? 1) - 1)" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}の余り`" @input="edited">
                        </template>
                        <button v-if="rule.conditions.length > 1" type="button" :aria-label="`Rule ${ruleIndex + 1} 条件${conditionIndex + 1}を削除`" @click="removeCondition(rule, conditionIndex)">条件を削除</button>
                    </div>
                    <button type="button" :disabled="rule.conditions.length >= configuration.max_conditions_per_rule || rule.conditions.some((condition) => condition.type === 'always')" @click="addCondition(rule)">AND条件を追加</button>
                </fieldset>

                <fieldset :disabled="busy || mode !== 'custom'">
                    <legend>Action</legend>
                    <select v-model="rule.action" :aria-label="`Rule ${ruleIndex + 1} action`" @change="changeAction(rule, ruleIndex)">
                        <optgroup label="基本action">
                            <option v-for="action in configuration.catalog.actions" :key="action.key" :value="action.key" :disabled="action.key === 'jump' && ruleIndex === draftRules.length - 1">{{ action.label }}</option>
                        </optgroup>
                        <optgroup label="Skill（未習得でも保存できます）">
                            <option v-for="skill in configuration.catalog.skills" :key="skill.key" :value="`skill:${skill.key}`">{{ skill.label }}</option>
                        </optgroup>
                    </select>
                    <select v-if="rule.action === 'jump'" v-model="rule.jumpTargetId" :aria-label="`Rule ${ruleIndex + 1}の移動先`" @change="edited">
                        <option v-for="target in draftRules.slice(ruleIndex + 1)" :key="target.id" :value="target.id">Rule {{ draftRules.indexOf(target) + 1 }}</option>
                    </select>
                </fieldset>
            </li>
        </ol>

        <button class="button secondary underground-ai-add" type="button" :disabled="busy || mode !== 'custom' || draftRules.length >= configuration.max_rules" @click="addRule">ruleを追加</button>
        <footer class="underground-ai-save-actions">
            <button class="button secondary" type="button" :disabled="busy || !dirty" @click="syncFromConfiguration">変更を破棄</button>
            <button class="button primary" type="button" :disabled="busy || !dirty" @click="save">{{ busy ? '保存中…' : '作戦を保存' }}</button>
        </footer>
    </section>
</template>

<style scoped>
.underground-ai-editor { display: grid; gap: 1rem; }
.underground-ai-heading { display: flex; justify-content: space-between; gap: 1rem; align-items: start; }
.underground-ai-heading h1, .underground-ai-rule h2 { margin: 0; }
.underground-ai-heading dl { display: grid; gap: .4rem; margin: 0; min-width: min(24rem, 100%); }
.underground-ai-heading dl div { display: grid; grid-template-columns: 7rem minmax(0, 1fr); gap: .5rem; }
.underground-ai-heading dt { color: var(--muted, #64748b); }
.underground-ai-heading dd { margin: 0; overflow-wrap: anywhere; }
.underground-ai-heading code { font-size: .75rem; }
.underground-ai-notes { padding: .8rem 1rem; border: 1px solid rgba(148, 163, 184, .35); border-radius: .75rem; }
.underground-ai-notes p { margin: .25rem 0; }
.underground-ai-mode-actions, .underground-ai-order-actions, .underground-ai-save-actions { display: flex; flex-wrap: wrap; gap: .5rem; }
.underground-ai-mode-actions button[aria-pressed="true"] { border-color: #38bdf8; box-shadow: 0 0 0 1px #38bdf8 inset; }
.underground-ai-rules { display: grid; gap: .8rem; margin: 0; padding: 0; list-style: none; }
.underground-ai-rule { display: grid; gap: .75rem; padding: 1rem; border: 1px solid rgba(148, 163, 184, .35); border-radius: .75rem; }
.underground-ai-rule > header { display: flex; justify-content: space-between; gap: .75rem; align-items: center; }
.underground-ai-rule fieldset { display: grid; gap: .5rem; min-width: 0; }
.underground-ai-condition { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
.underground-ai-condition select, .underground-ai-condition input, .underground-ai-rule fieldset > select { min-height: 2.25rem; }
.underground-ai-condition input { width: 7rem; }
.underground-ai-add { justify-self: start; }
.underground-ai-save-actions { justify-content: flex-end; }
.warning { color: #b45309; }
@media (max-width: 720px) {
    .underground-ai-heading { display: grid; }
    .underground-ai-heading dl { min-width: 0; }
    .underground-ai-rule > header { align-items: start; }
}
</style>
