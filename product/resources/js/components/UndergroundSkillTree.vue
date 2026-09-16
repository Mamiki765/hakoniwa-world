<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import type { UndergroundSkillNode, UndergroundSkillTree } from './undergroundSkills';

const props = defineProps<{ trees: UndergroundSkillTree[]; busy: boolean; userId?: number; growthPath?: string }>();
const emit = defineEmits<{ acquire: [nodeKey: string]; equip: [] }>();
const storageKey = computed(() => `hakoniwa.underground.skill-tree.${props.userId ?? 'guest'}`);
function initialTree(): string {
    try {
        const saved = localStorage.getItem(storageKey.value);
        if (props.trees.some((tree) => tree.key === saved)) return saved!;
    } catch { /* Storage may be unavailable; the growth path still provides a default. */ }
    const preferred = props.growthPath === 'guardianship_blue' ? 'guardianship'
        : props.growthPath === 'blessing_green' ? 'miracle' : 'martial';
    return props.trees.some((tree) => tree.key === preferred) ? preferred : props.trees[0]?.key ?? 'martial';
}
const treeKey = ref(initialTree());
watch(storageKey, () => { treeKey.value = initialTree(); });
function selectTree(key: string): void {
    treeKey.value = key;
    try { localStorage.setItem(storageKey.value, key); } catch { /* Selection still works without persistence. */ }
}
const selectedKey = ref<string | null>(null);
const detail = ref<HTMLElement | null>(null);
const activeTree = computed(() => props.trees.find((tree) => tree.key === treeKey.value) ?? props.trees[0]);
watch(activeTree, (tree) => {
    if (!tree?.nodes.some((node) => node.key === selectedKey.value)) selectedKey.value = tree?.nodes[0]?.key ?? null;
}, { immediate: true });
const selected = computed(() => activeTree.value?.nodes.find((node) => node.key === selectedKey.value));
const prerequisite = computed(() => activeTree.value?.nodes.find((node) => node.key === selected.value?.prerequisite));
const statNames: Record<string, string> = { vitality: '体力', might: '武力', finesse: '技巧', spirit: '精神', agility: '敏捷' };

// Place each prerequisite layer before its descendants, wrapping wide branches
// to three readable columns. Edges always use the actual prerequisite IDs.
const graph = computed(() => {
    const nodes = activeTree.value?.nodes ?? [];
    const remaining = [...nodes];
    const placed = new Map<string, { node: UndergroundSkillNode; x: number; y: number }>();
    let row = 0;
    while (remaining.length > 0) {
        let layer = remaining.filter((node) => !node.prerequisite || placed.has(node.prerequisite));
        if (layer.length === 0) layer = [...remaining];
        layer.sort((left, right) => (placed.get(left.prerequisite ?? '')?.x ?? 0) - (placed.get(right.prerequisite ?? '')?.x ?? 0));
        for (let offset = 0; offset < layer.length; offset += 3) {
            const group = layer.slice(offset, offset + 3);
            group.forEach((node, index) => {
                const columns = group.length === 1 ? [300] : group.length === 2 ? [100, 500] : [100, 300, 500];
                placed.set(node.key, { node, x: columns[index]!, y: row * 116 + 40 });
                remaining.splice(remaining.indexOf(node), 1);
            });
            row++;
        }
    }
    const branch = new Set<string>();
    let ancestor = selected.value;
    while (ancestor && !branch.has(ancestor.key)) {
        branch.add(ancestor.key);
        ancestor = nodes.find((node) => node.key === ancestor?.prerequisite);
    }
    const edges = [...placed.values()].flatMap((item) => {
        const parent = placed.get(item.node.prerequisite ?? '');
        if (!parent) return [];
        const start = parent.y + 36;
        const end = item.y - 36;
        // Long edges run in the gaps between columns, never underneath another node.
        const lane = item.x > parent.x ? parent.x + 100 : item.x < parent.x ? parent.x - 100 : parent.x === 500 ? 400 : parent.x + 100;
        const upper = start + 18;
        const lower = end - 18;
        return [{ key: item.node.key, path: `M ${parent.x} ${start} V ${upper} H ${lane} V ${lower} H ${item.x} V ${end}`, acquired: item.node.rank > 0, selected: branch.has(item.node.key) }];
    });
    return { nodes: [...placed.values()], edges, height: Math.max(80, row * 116 - 32) };
});

async function selectNode(node: UndergroundSkillNode): Promise<void> {
    selectedKey.value = node.key;
    await nextTick();
    const bounds = detail.value?.getBoundingClientRect();
    if (bounds && (bounds.bottom > window.innerHeight || bounds.top < 0)) {
        detail.value?.scrollIntoView?.({ behavior: 'smooth', block: 'nearest' });
    }
}

function moveTab(event: KeyboardEvent, index: number): void {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const next = (index + (event.key === 'ArrowRight' ? 1 : -1) + props.trees.length) % props.trees.length;
    selectTree(props.trees[next]!.key);
    document.getElementById(`skill-tab-${treeKey.value}`)?.focus();
}
</script>

<template>
    <div class="skill-branches">
        <div class="skill-tabs" role="tablist" aria-label="Skill Tree系統">
            <button
v-for="(tree, index) in trees" :id="`skill-tab-${tree.key}`" :key="tree.key" type="button" role="tab"
                :aria-selected="activeTree?.key === tree.key" :tabindex="activeTree?.key === tree.key ? 0 : -1"
                :aria-controls="`skill-panel-${tree.key}`" @click="selectTree(tree.key)" @keydown="moveTab($event, index)"
>
                {{ tree.label }}
            </button>
        </div>
        <div v-if="activeTree" :id="`skill-panel-${activeTree.key}`" role="tabpanel" :aria-labelledby="`skill-tab-${activeTree.key}`">
            <p class="skill-instructions">技を選ぶと、効果と前提を確認できます。線は取得に必要な技のつながりです。</p>
            <div class="skill-layout">
                <div class="skill-graph" :style="{ height: `${graph.height}px` }">
                    <svg :viewBox="`0 0 600 ${graph.height}`" preserveAspectRatio="none" aria-hidden="true">
                        <path v-for="edge in graph.edges" :key="edge.key" :d="edge.path" :class="{ acquired: edge.acquired, selected: edge.selected }" />
                    </svg>
                    <button
v-for="item in graph.nodes" :id="`skill-node-${item.node.key}`" :key="item.node.key" type="button" class="skill-node"
                        :style="{ left: `${item.x / 6}%`, top: `${item.y}px` }" :aria-pressed="selectedKey === item.node.key"
                        :data-acquired="item.node.rank > 0" @click="selectNode(item.node)"
>
                        <strong>{{ item.node.label }}</strong>
                        <span>{{ item.node.rank >= item.node.max_rank ? '✓ 取得済み' : item.node.can_acquire ? '取得可能' : '未取得' }}</span>
                        <small>{{ item.node.point_cost }} SP</small>
                    </button>
                </div>
                <section v-if="selected" ref="detail" class="skill-detail" aria-label="選んだ技の説明" aria-live="polite">
                    <h3>{{ selected.label }}</h3>
                    <p>{{ selected.summary }}</p>
                    <dl>
                        <div><dt>取得費</dt><dd>{{ selected.point_cost }} SP</dd></div>
                        <div><dt>前提</dt><dd>{{ prerequisite?.label ?? 'なし' }}<span v-if="prerequisite">（{{ prerequisite.rank > 0 ? '取得済み' : '未取得' }}）</span></dd></div>
                        <div v-if="selected.type === 'active'"><dt>MP / CT</dt><dd>{{ selected.mp_cost }} / {{ selected.cooldown }}ターン</dd></div>
                        <div v-if="selected.recommended_stats?.length"><dt>依存</dt><dd>{{ selected.recommended_stats.map((key) => statNames[key] ?? key).join('・') }}</dd></div>
                    </dl>
                    <p v-if="selected.consumes_action === false">使用後、即座に次の行動が可能。技枠を1つ使います。</p>
                    <p v-if="selected.rank >= selected.max_rank">取得済み{{ selected.active_slot ? `・装備枠 ${selected.active_slot}` : '・未装備' }}</p>
                    <p v-else-if="!selected.can_acquire">{{ selected.unavailable_reason }}</p>
                    <button v-if="selected.rank < selected.max_rank" type="button" :disabled="busy || !selected.can_acquire" @click="emit('acquire', selected.key)">{{ selected.point_cost }} SPで取得する</button>
                    <button v-else type="button" @click="emit('equip')">アクティブスキル設定へ</button>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.skill-tabs { display: flex; gap: 8px; }
.skill-tabs button { flex: 1; padding: 12px 6px; border: 1px solid var(--line); border-radius: 8px; background: var(--paper-deep); color: var(--ink); }
.skill-tabs button[aria-selected="true"] { background: var(--navy-solid); color: var(--on-solid); border-color: var(--navy); font-weight: 700; }
.skill-instructions { font-size: 13px; color: var(--muted); margin: 14px 0; }
.skill-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(200px, 0.65fr); gap: 16px; align-items: start; }
.skill-graph { position: relative; min-width: 0; }
.skill-graph svg { position: absolute; width: 100%; height: 100%; overflow: visible; }
.skill-graph path { fill: none; stroke: var(--muted); stroke-width: 2; vector-effect: non-scaling-stroke; }
.skill-graph path.acquired { stroke: var(--success); stroke-width: 3; }
.skill-graph path.selected { stroke: var(--info); stroke-width: 3; }
.skill-node { position: absolute; transform: translate(-50%, -50%); width: 30%; height: 72px; padding: 5px 2px; display: flex; flex-direction: column; justify-content: center; gap: 2px; background: var(--paper); border: 1px solid var(--input-border); border-radius: 8px; color: var(--ink); text-align: center; overflow-wrap: anywhere; cursor: pointer; }
.skill-node strong { font-size: 13px; line-height: 1.25; }
.skill-node span, .skill-node small { font-size: 11px; }
.skill-node[data-acquired="true"] { background: var(--success-surface); border-color: var(--success); }
.skill-node[aria-pressed="true"] { outline: 3px solid var(--info); outline-offset: 2px; }
.skill-detail { color: var(--ink); background: var(--paper-deep); border: 1px solid var(--line); padding: 16px; border-radius: 10px; scroll-margin: 80px; }
.skill-detail h3 { margin: 0 0 12px; font-size: 18px; overflow-wrap: anywhere; }
.skill-detail p { line-height: 1.65; }
.skill-detail dl { display: grid; gap: 8px; font-size: 13px; }
.skill-detail dl > div { display: grid; grid-template-columns: 65px 1fr; gap: 8px; }
.skill-detail dd { margin: 0; }
.skill-detail button { padding: 12px; width: 100%; color: var(--on-solid); background: var(--navy-solid); border: 1px solid var(--navy); border-radius: 6px; }
.skill-detail button:disabled { opacity: .55; }
@media (max-width: 700px) { .skill-layout { grid-template-columns: minmax(0, 1fr); } }
</style>
