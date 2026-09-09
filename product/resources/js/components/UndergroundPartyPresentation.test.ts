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
        const lendingRequests: Array<Record<string, unknown>> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path.startsWith('/api/v1/me/underground/lending/candidates')) {
                return Promise.resolve(response({ candidates: [], next_after_id: null }));
            }
            if (path === '/api/v1/me/underground/lending' && init?.method === 'PUT') {
                lendingRequests.push(JSON.parse(String(init.body)) as Record<string, unknown>);
                return Promise.resolve(response(state));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-main-navigation button:nth-child(4)').trigger('click');
        await wrapper.get('.underground-party-browser button').trigger('click');
        await flushPromises();

        const selected = wrapper.get('.underground-party-selected button');
        expect(selected.text()).toContain('貸出不可');
        expect(selected.attributes('disabled')).toBeUndefined();
        await selected.trigger('click');
        expect(wrapper.get('.underground-party-count').text()).toBe('1 / 4人');
        expect(wrapper.get<HTMLInputElement>('.underground-lending-settings input').element.checked).toBe(false);
        await wrapper.get('.underground-lending-settings input').setValue(true);
        await wrapper.get('.underground-lending-settings button').trigger('click');
        await flushPromises();
        expect(lendingRequests).toHaveLength(1);
        expect(lendingRequests[0]?.is_lendable).toBe(true);
        expect(lendingRequests[0]).not.toHaveProperty('is_public');
        expect(lendingRequests[0]).not.toHaveProperty('is_available');
        wrapper.unmount();
    });

    it('keeps the selected party across underground page remounts for the same user', async () => {
        const candidate = {
            secretary_id: 42,
            source: 'borrowed_secretary' as const,
            display_name: '保存する秘書',
            combat_level: 18,
            available: true,
        };
        const state = openState({
            party_member_ids: undefined,
            lending: { settings: { is_public: false, is_available: true }, candidates: [], ticket_balance: 0 },
        });
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path.startsWith('/api/v1/me/underground/lending/candidates')) {
                return Promise.resolve(response({ candidates: [candidate], next_after_id: null }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body, props: { userId: 7 } });
        await flushPromises();
        await wrapper.get('.underground-main-navigation button:nth-child(4)').trigger('click');
        await wrapper.get('.underground-party-browser button').trigger('click');
        await flushPromises();
        await wrapper.get('button[aria-label="保存する秘書をPTに追加"]').trigger('click');
        expect(window.localStorage.getItem('hakoniwa.underground.party-member-ids.7')).toBe('[42]');
        wrapper.unmount();

        const restored = mount(UndergroundPanel, { attachTo: document.body, props: { userId: 7 } });
        await flushPromises();
        await restored.get('.underground-main-navigation button:nth-child(4)').trigger('click');
        expect(restored.get('.underground-party-count').text()).toBe('2 / 4人');
        expect(restored.get('button[aria-label="選択中の秘書をPTから解除"]')).toBeTruthy();
        restored.unmount();
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
            execution_count: 2,
            ticket_cost: 2,
            xp_awarded: 24,
            shards_awarded: 8,
            combat_level_before: 20,
            combat_level_after: 20,
            rewards: {
                drops: [
                    { status: 'granted', quantity: 1, item: { name: '銀の短剣', item_level: 20, rarity_label: 'Rare', affixes: [] } },
                    { status: 'vault_full', quantity: 1, item: { name: '黒晶の盾', item_level: 20, rarity_label: 'Epic', affixes: [] } },
                ],
            },
            ticket_balance: 3,
            settled_at: '2026-09-09T00:00:00Z',
        };
        const skipRequests: Array<Record<string, unknown>> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/hunting-ground') {
                skipRequests.push(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);
                return Promise.resolve(response(skipResult));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-entry button').trigger('click');
        await wrapper.get('.underground-skip-category .underground-skip-shortcuts button').trigger('click');
        await flushPromises();

        const result = wrapper.get('.underground-skip-result');
        expect(skipRequests).toHaveLength(1);
        expect(skipRequests[0]?.execution_count).toBe(2);
        expect(result.text()).toContain('浅い洞窟を2回スキップしました');
        expect(result.text()).toContain('獲得: 銀の短剣 ×1');
        expect(result.text()).toContain('取り逃し: 黒晶の盾 ×1（宝物庫が満杯）');
        expect(result.text()).toContain('残りticket3枚');
        wrapper.unmount();
    });

    it('keeps hunting grounds and trials separate and calculates 50 and 100 percent bulk shortcuts', async () => {
        const state = openState({
            trial: {
                key: 'trial_01',
                label: '黒曜石の魔窟',
                total_battles: 10,
                first_cleared: true,
                active_run: null,
                trials: [{
                    key: 'trial_01',
                    label: '黒曜石の魔窟',
                    total_battles: 10,
                    locked: false,
                    unlock_condition: null,
                    first_cleared: true,
                    skip: { actual_clear_count: 5, total_clear_count: 5, actual_clears_required: 5, unlocked: true, ticket_cost: 10 },
                }],
            },
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 20_000 },
        });
        const requests: Array<Record<string, unknown>> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/trial') {
                requests.push(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);
                return Promise.resolve(response({
                    id: 'trial-skip', duplicate: false, content_type: 'trial', content_key: 'trial_01', execution_count: 1000,
                    ticket_cost: 10_000, xp_awarded: 100_000, shards_awarded: 50_000, combat_level_before: 20, combat_level_after: 21,
                    rewards: { equipment_granted_count: 0, vault_full_count: 0, drops: [], ticket_balance_after: 10_000 },
                    settled_at: '2026-09-09T00:00:00Z',
                }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        const adventureSections = wrapper.findAll('.underground-adventure-block');
        expect(adventureSections).toHaveLength(2);
        expect(adventureSections[0]!.get('h3').text()).toBe('狩場');
        expect(adventureSections[1]!.get('h3').text()).toBe('試練');

        await wrapper.get('.underground-skip-entry button').trigger('click');
        const categories = wrapper.findAll('.underground-skip-category');
        expect(categories).toHaveLength(2);
        expect(categories[0]!.text()).toContain('50%使用（500回）');
        expect(categories[0]!.text()).toContain('100%使用（1000回）');
        expect(categories[1]!.text()).toContain('50%使用（500周）');
        expect(categories[1]!.text()).toContain('100%使用（1000周）');
        await categories[1]!.findAll('.underground-skip-shortcuts button')[1]!.trigger('click');
        await flushPromises();

        expect(requests).toHaveLength(1);
        expect(requests[0]?.execution_count).toBe(1000);
        expect(requests[0]?.trial_key).toBe('trial_01');
        expect(wrapper.get('.underground-skip-result').text()).toContain('黒曜石の魔窟を1000周スキップしました');
        wrapper.unmount();
    });

    it('keeps an active trial target independent from the skip-modal trial selector', async () => {
        window.localStorage.setItem('hakoniwa.underground.party-member-ids.7', '[42]');
        const activeRun = {
            key: 'trial_02', label: '二つ目の封印の地', run_key: 'active-trial-02',
            status: 'active', next_battle_index: 4, total_battles: 10,
        };
        const state = openState({
            party_member_ids: undefined,
            trial: {
                key: 'trial_02', label: '二つ目の封印の地', total_battles: 10, first_cleared: false,
                active_run: activeRun,
                trials: [
                    { key: 'trial_01', label: '一つ目の封印の地', total_battles: 10, locked: false, unlock_condition: null, first_cleared: true, skip: { actual_clear_count: 5, total_clear_count: 5, actual_clears_required: 5, unlocked: true, ticket_cost: 10 } },
                    { key: 'trial_02', label: '二つ目の封印の地', total_battles: 10, locked: false, unlock_condition: null, first_cleared: false, skip: { actual_clear_count: 0, total_clear_count: 0, actual_clears_required: 5, unlocked: false, ticket_cost: 10 } },
                ],
            },
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 100 },
        });
        const requests: Array<{ path: string; body: Record<string, unknown> }> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/trial/fight' && init?.method === 'POST') {
                requests.push({ path, body: JSON.parse(String(init.body)) as Record<string, unknown> });
                return Promise.resolve(response({
                    ...smallBattle('trial-02-battle-4'), context: 'trial', trial_run_key: activeRun.run_key,
                    trial_battle_index: 4, trial_total_battles: 10, trial_status: 'active', trial_next_battle_index: 5,
                }));
            }
            if (path === '/api/v1/me/underground/trial/start' && init?.method === 'POST') {
                requests.push({ path, body: JSON.parse(String(init.body)) as Record<string, unknown> });
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body, props: { userId: 7 } });
        await flushPromises();
        const activeTrialSelect = wrapper.get<HTMLSelectElement>('select[aria-label="試練を選択"]');
        expect(activeTrialSelect.element.value).toBe('trial_02');
        expect(wrapper.get('.underground-trial-entry').attributes('disabled')).toBeUndefined();

        await wrapper.get('.underground-skip-entry button').trigger('click');
        await wrapper.get<HTMLSelectElement>('select[aria-label="スキップする試練を選択"]').setValue('trial_01');
        await wrapper.get('.underground-skip-dialog button[aria-label="閉じる"]').trigger('click');

        expect(activeTrialSelect.element.value).toBe('trial_02');
        expect(requests).toHaveLength(0);
        expect(wrapper.get('.underground-skip-entry').text()).toContain('所持 100枚');
        await wrapper.get('.underground-trial-entry').trigger('click');
        await flushPromises();

        expect(requests).toHaveLength(1);
        expect(requests[0]?.path).toBe('/api/v1/me/underground/trial/fight');
        expect(requests[0]?.body.run_key).toBe('active-trial-02');
        expect(requests[0]?.body).not.toHaveProperty('borrowed_secretary_ids');
        expect(window.localStorage.getItem('hakoniwa.underground.party-member-ids.7')).toBe('[42]');
        wrapper.unmount();
    });

    it('keeps a network skip failure inside the modal and retries with the same request id', async () => {
        const state = openState({
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 4 },
        });
        const requests: Array<Record<string, unknown>> = [];
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/hunting-ground') {
                requests.push(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);
                if (requests.length === 1) return Promise.reject(new Error('スキップ通信に失敗しました。'));

                return Promise.resolve(response({
                    id: 'retry-safe-skip', duplicate: true, content_type: 'hunting_ground', content_key: 'shallow_caves', execution_count: 2,
                    ticket_cost: 2, xp_awarded: 20, shards_awarded: 4, combat_level_before: 20, combat_level_after: 20,
                    rewards: { equipment_granted_count: 0, vault_full_count: 0, drops: [], ticket_balance_after: 2 },
                    settled_at: '2026-09-09T00:00:00Z',
                }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-entry button').trigger('click');
        const shortcut = wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button')[0]!;
        await shortcut.trigger('click');
        await flushPromises();

        expect(wrapper.get('.underground-skip-dialog').isVisible()).toBe(true);
        expect(wrapper.get('.underground-skip-error').text()).toBe('スキップ通信に失敗しました。');
        expect(wrapper.get('.underground-skip-pending').text()).toContain('同じ対象・回数で再試行');
        const otherShortcut = wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button')[1]!;
        expect(shortcut.attributes('disabled')).toBeUndefined();
        expect(otherShortcut.attributes('disabled')).toBeDefined();
        await shortcut.trigger('click');
        await flushPromises();

        expect(requests).toHaveLength(2);
        expect(requests[1]?.request_id).toBe(requests[0]?.request_id);
        expect(wrapper.find('.underground-skip-error').exists()).toBe(false);
        expect(wrapper.get('.underground-skip-result').text()).toContain('浅い洞窟を2回スキップしました');
        wrapper.unmount();
    });

    it('clears a stale confirmed result before reporting a new skip failure', async () => {
        const state = openState({
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 6 },
        });
        let skipRequests = 0;
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/hunting-ground') {
                skipRequests += 1;
                if (skipRequests > 1) return Promise.reject(new Error('新しいskipの通信に失敗しました。'));

                return Promise.resolve(response({
                    id: 'previous-skip', duplicate: false, content_type: 'hunting_ground', content_key: 'shallow_caves', execution_count: 3,
                    ticket_cost: 3, xp_awarded: 30, shards_awarded: 6, combat_level_before: 20, combat_level_after: 20,
                    rewards: { equipment_granted_count: 0, vault_full_count: 0, drops: [], ticket_balance_after: 3 },
                    settled_at: '2026-09-09T00:00:00Z',
                }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-entry button').trigger('click');
        let shortcuts = wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button');
        await shortcuts[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.find('.underground-skip-result').exists()).toBe(true);

        shortcuts = wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button');
        await shortcuts[1]!.trigger('click');
        await flushPromises();

        expect(skipRequests).toBe(2);
        expect(wrapper.find('.underground-skip-result').exists()).toBe(false);
        expect(wrapper.get('.underground-skip-error').text()).toBe('新しいskipの通信に失敗しました。');
        wrapper.unmount();
    });

    it('keeps a confirmed skip result when refreshing the latest state fails', async () => {
        const state = openState({
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 2 },
        });
        const skipRequests: Array<Record<string, unknown>> = [];
        let stateRequests = 0;
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/skip/hunting-ground') {
                skipRequests.push(JSON.parse(String(init?.body ?? '{}')) as Record<string, unknown>);

                return Promise.resolve(response({
                    id: 'confirmed-skip', duplicate: false, content_type: 'hunting_ground', content_key: 'shallow_caves', execution_count: 1,
                    ticket_cost: 1, xp_awarded: 10, shards_awarded: 2, combat_level_before: 20, combat_level_after: 20,
                    rewards: { equipment_granted_count: 0, vault_full_count: 0, drops: [], ticket_balance_after: 0 },
                    settled_at: '2026-09-09T00:00:00Z',
                }));
            }
            if (path === '/api/v1/me/underground') {
                stateRequests += 1;
                if (stateRequests > 1) return Promise.reject(new Error('最新状態の取得に失敗しました。'));

                return Promise.resolve(response(state));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-entry button').trigger('click');
        const shortcut = wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button')[0]!;
        await shortcut.trigger('click');
        await flushPromises();

        expect(skipRequests).toHaveLength(1);
        expect(wrapper.get('.underground-skip-result').text()).toContain('浅い洞窟を1回スキップしました');
        expect(wrapper.get('.underground-skip-error').text()).toContain('skipは完了しました');
        expect(wrapper.get('.underground-skip-dialog').text()).toContain('🎫 0枚');
        expect(shortcut.attributes('disabled')).toBeDefined();
        await shortcut.trigger('click');
        await flushPromises();
        expect(skipRequests).toHaveLength(1);
        wrapper.unmount();
    });

    it('preserves the original 409 skip reason when the recovery refresh also fails', async () => {
        const state = openState({
            lending: { settings: { is_lendable: false, is_public: false, is_available: false }, candidates: [], ticket_balance: 4 },
        });
        let stateReads = 0;
        vi.stubGlobal('fetch', vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/me/underground') {
                stateReads++;
                return stateReads === 1
                    ? Promise.resolve(response(state))
                    : Promise.reject(new Error('状態の再読込にも失敗しました。'));
            }
            if (path === '/api/v1/me/underground/skip/hunting-ground') {
                return Promise.resolve(new Response(JSON.stringify({
                    message: '同じrequest idの確定内容と一致しません。',
                    code: 'underground_request_conflict',
                }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
            }
            if (path.endsWith('/api/v1/me/underground/battles')) return Promise.resolve(response([]));

            return Promise.resolve(response(state));
        }));

        const wrapper = mount(UndergroundPanel, { attachTo: document.body });
        await flushPromises();
        await wrapper.get('.underground-skip-entry button').trigger('click');
        await wrapper.findAll('.underground-skip-category')[0]!.findAll('.underground-skip-shortcuts button')[0]!.trigger('click');
        await flushPromises();

        expect(wrapper.get('.underground-skip-dialog').isVisible()).toBe(true);
        expect(wrapper.get('.underground-skip-error').text()).toBe('同じrequest idの確定内容と一致しません。');
        expect(wrapper.get('.underground-skip-error').text()).not.toContain('再読込');
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
        await wrapper.get('.underground-main-navigation button:nth-child(4)').trigger('click');
        await wrapper.get('.underground-party-browser button').trigger('click');
        await flushPromises();
        await wrapper.get('button[aria-label="A秘書をPTに追加"]').trigger('click');
        await wrapper.get('.underground-main-navigation button:first-child').trigger('click');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-pending-request').text()).toContain('同じ同行者');

        await wrapper.get('.underground-main-navigation button:nth-child(4)').trigger('click');
        await wrapper.get('button[aria-label="A秘書をPTから解除"]').trigger('click');
        await wrapper.get('button[aria-label="B秘書をPTに追加"]').trigger('click');
        await wrapper.get('.underground-main-navigation button:first-child').trigger('click');
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
