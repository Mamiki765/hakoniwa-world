import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UndergroundPanel from './UndergroundPanel.vue';
import partyPresentation from './__fixtures__/party-presentation.json';

const response = (data: unknown) => new Response(JSON.stringify({ data }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
});

const openState = (overrides: Record<string, unknown> = {}): Record<string, unknown> => ({
    stage: 'underground_open',
    secretary_name: 'Leader',
    combat_level: 20,
    combat_xp: 0,
    next_level_xp: 100,
    next_level_requirement: 100,
    xp_to_next_level: 100,
    shard_balance: 0,
    banked_shard_balance: 0,
    next_battle_at: null,
    current_hp: null,
    unspent_stp: 0,
    allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 },
    current_stats: null,
    combat_stats: null,
    status_breakdown: null,
    equipment_summary: null,
    skill_points_total: 0,
    skill_points_unspent: 0,
    skill_points_spent: 0,
    skill_tree_identity: null,
    skill_trees: null,
    active_slots: [],
    passive_modifiers: {},
    shopkeeper_name: '案内人',
    true_name_branch: false,
    tutorial_projection: { stats: {}, weapon: '' },
    contract_completed: true,
    growth_paths: null,
    growth_path: null,
    respec: null,
    playtest: null,
    hunting_grounds: [{
        key: 'shallow_caves',
        name: '浅い洞窟',
        locked: false,
        unlock_condition: null,
        item_level_min: 1,
        item_level_max: 10,
        skip: {
            actual_clear_count: 50,
            total_clear_count: 50,
            actual_clears_required: 50,
            unlocked: true,
            ticket_cost: 1,
        },
    }],
    default_hunting_ground_key: 'shallow_caves',
    trial: null,
    awakening: null,
    ai: null,
    battle: null,
    party_candidates: [],
    party_member_ids: [],
    lending: {
        settings: { is_public: false, is_available: true },
        candidates: [],
        ticket_balance: 0,
    },
    ...overrides,
});

const smallBattle = (id: string): Record<string, unknown> => ({
    id,
    context: 'exploration',
    encounter_name: '浅い洞窟の敵',
    result: 'victory',
    rounds: [],
    rounds_count: 0,
    xp_awarded: 0,
    shard_delta: 0,
    detail_available: false,
    party: { members: [], enemies: [] },
    hunting_ground: {
        key: 'shallow_caves',
        name: '浅い洞窟',
        content_identity: 'fixture-hunting-ground-v1',
        item_level_min: 1,
        item_level_max: 10,
    },
});

describe('Underground party presentation controls', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
        window.localStorage.clear();
    });

    it('hides only round detail and scrolls once for the newly displayed battle', async () => {
        vi.useFakeTimers();
        const scrollIntoView = vi.fn();
        Object.defineProperty(Element.prototype, 'scrollIntoView', {
            configurable: true,
            value: scrollIntoView,
        });
        const battle = {
            id: 'party-battle-1',
            context: 'exploration',
            encounter_name: '地底鼠 ×2',
            result: 'victory',
            rounds: [{
                round: 1,
                actions: [
                    { type: 'damage', side: 'player', label: '攻撃', amount: 10 },
                    { type: 'awakening', side: 'player', label: '覚醒', amount: 0, important: true },
                ],
                end_state: null,
            }],
            xp_awarded: 36,
            shard_delta: 10,
            detail_available: true,
            rewards: { xp: 36, shards: 10 },
            party: { members: [], enemies: [] },
        };
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => (
            String(input).endsWith('/api/v1/me/underground/battles')
                ? Promise.resolve(response([]))
                : Promise.resolve(response({ stage: 'underground_open', battle, secretary_name: 'Leader' }))
        )));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        expect(scrollIntoView).toHaveBeenCalledTimes(1);

        await wrapper.get('.underground-battle-detail-toggle').trigger('click');
        expect(wrapper.get('[data-action-type="damage"]').attributes('style')).toContain('display: none');
        expect(wrapper.get('[data-action-type="awakening"]').attributes('style') ?? '').not.toContain('display: none');
        expect(wrapper.get('.underground-battle-result').text()).toContain('勝利');
        expect(window.localStorage.getItem('hakoniwa.underground.battle-detail-visible')).toBe('false');

        vi.advanceTimersByTime(3_000);
        await wrapper.vm.$nextTick();
        expect(scrollIntoView).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('renders the generated engine presentation fixture through the party projection', async () => {
        const state = openState({ battle: partyPresentation });
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => (
            String(input).endsWith('/api/v1/me/underground/battles')
                ? Promise.resolve(response([]))
                : Promise.resolve(response(state))
        )));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();

        const partySections = wrapper.findAll('.underground-party-teams');
        expect(partySections).toHaveLength(3);
        expect(partySections[0]!.find('[data-combatant-id="borrowed:2"]').text()).toContain('100/1148');
        expect(partySections[0]!.find('[data-combatant-id="borrowed:2"]').text()).toContain('Ready');
        expect(partySections[0]!.find('img[src="/fixtures/healer.webp"]').exists()).toBe(true);
        expect(partySections[2]!.find('img[src="/fixtures/awakening-healer.webp"]').exists()).toBe(true);
        expect(wrapper.findAll('.underground-party-portrait-event')).toHaveLength(9);
        expect(wrapper.findAll('img[src="/fixtures/awakening-healer-bust.webp"]')).toHaveLength(2);
        expect(wrapper.text()).toContain('生命讃歌');
        expect(wrapper.text()).toContain('AttackerのHP');
        wrapper.unmount();
    });

    it('keeps a selected unavailable secretary visible and removable when the candidate page is empty', async () => {
        const state = openState({
            party_member_ids: [42],
            lending: { settings: { is_public: false, is_available: true }, candidates: [], ticket_balance: 0 },
        });
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path.startsWith('/api/v1/me/underground/lending/candidates')) {
                return Promise.resolve(response({ candidates: [], next_after_id: null }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-party-browser button').trigger('click');
        await flushPromises();

        const selected = wrapper.get('.underground-party-selected button');
        expect(selected.text()).toContain('貸出不可');
        expect(selected.attributes('disabled')).toBeUndefined();
        await selected.trigger('click');
        expect(wrapper.get('.underground-party-count').text()).toBe('1 / 4人');
        wrapper.unmount();
    });

    it('shows every skip drop outcome, including an item lost to a full vault', async () => {
        const state = openState({
            lending: { settings: { is_public: false, is_available: true }, candidates: [], ticket_balance: 5 },
        });
        const skipResult = {
            id: 'skip-1',
            duplicate: false,
            content_type: 'hunting_ground',
            content_key: 'shallow_caves',
            ticket_cost: 1,
            xp_awarded: 12,
            shards_awarded: 4,
            combat_level_before: 20,
            combat_level_after: 20,
            rewards: {
                drops: [
                    { status: 'granted', quantity: 1, item: { name: '銀の短剣', item_level: 20, rarity_label: 'Rare', affixes: [] } },
                    { status: 'vault_full', quantity: 1, item: { name: '黒晶の盾', item_level: 20, rarity_label: 'Epic', affixes: [] } },
                ],
            },
            ticket_balance: 4,
            settled_at: '2026-09-09T00:00:00Z',
        };
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/hunting-ground') return Promise.resolve(response(skipResult));
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-panel li button').trigger('click');
        await flushPromises();

        const result = wrapper.get('.underground-skip-result');
        expect(result.text()).toContain('獲得: 銀の短剣 ×1');
        expect(result.text()).toContain('取り逃し: 黒晶の盾 ×1（宝物庫が満杯）');
        expect(result.text()).toContain('残り 4枚');
        wrapper.unmount();
    });

    it('recovers a lost A response with A payload, then starts a new B request', async () => {
        const candidateA = { secretary_id: 2, source: 'borrowed_secretary' as const, display_name: 'A秘書', combat_level: 30, available: true };
        const candidateB = { secretary_id: 3, source: 'borrowed_secretary' as const, display_name: 'B秘書', combat_level: 30, available: true };
        const state = openState({ lending: { settings: { is_public: false, is_available: true }, candidates: [], ticket_balance: 0 } });
        const requests: Array<Record<string, unknown>> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/explore') {
                const body = JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>;
                requests.push(body);
                if (requests.length === 1) return Promise.reject(new Error('response lost'));

                return Promise.resolve(response(smallBattle(requests.length === 2 ? 'battle-a' : 'battle-b')));
            }
            if (path.startsWith('/api/v1/me/underground/lending/candidates')) {
                return Promise.resolve(response({ candidates: [candidateA, candidateB], next_after_id: null }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-party-browser button').trigger('click');
        await flushPromises();
        await wrapper.get('button[aria-label="A秘書をPTに追加"]').trigger('click');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-pending-request').text()).toContain('同じ同行者');

        await wrapper.get('button[aria-label="A秘書をPTから解除"]').trigger('click');
        await wrapper.get('button[aria-label="B秘書をPTに追加"]').trigger('click');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        expect(wrapper.find('.underground-pending-request').exists()).toBe(false);
        await wrapper.get('.underground-exploration-repeat').trigger('click');
        await flushPromises();

        expect(requests).toHaveLength(3);
        expect(requests.map((request) => request.borrowed_secretary_ids)).toEqual([[2], [2], [3]]);
        expect(requests[0]?.request_id).toBe(requests[1]?.request_id);
        expect(requests[2]?.request_id).not.toBe(requests[1]?.request_id);
        wrapper.unmount();
    });
});
