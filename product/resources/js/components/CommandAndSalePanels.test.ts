import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    CommandCatalog,
    CommandDefinition,
    CommandQueue,
    CommandQueueItem,
    EffectivePlanSlot,
    MapCell,
} from '../types';
import CommandQueuePanel from './CommandQueuePanel.vue';
import SalePolicyPanel from './SalePolicyPanel.vue';

const selected: MapCell = {
    x: 8, y: 7, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
    display_name: '平地', owner_nation_id: 1, owner_nation_number: 1, owner_name: '操作国', details: [],
    monster: null,
    asset: { key: 'tile.plain', url: null, available: false, fallback_label: '平地', fallback_style: 'tile-plain' },
    overlays: [], aria_label: 'x 8 y 7 平地 所有 操作国', version: 1, updated_at: null,
};

const definition = (overrides: Partial<CommandDefinition> = {}): CommandDefinition => ({
    key: 'land_clear', name: '整地', description: '平地にします。', cost_money: 5,
    target_type: 'cell', quantity_semantics: 'unused', quantity_default: 1, quantity_options: [], parameters: {},
    execution_phase: 'terrain', initial_facility_capacity: null,
    applicable: true, available: true, shortfall_money: 0, unavailable_reason: null,
    execution_preview_status: 'currently_executable', execution_warnings: [],
    ...overrides,
});

const item = (id: number, position: number, overrides: Partial<CommandQueueItem> = {}): CommandQueueItem => ({
    id, command_key: 'land_clear', command_name: '整地', queue_position: position,
    target_x: 8, target_y: 7, quantity: 1, quantity_semantics: 'unused', quantity_label: null,
    parameters: {}, status: 'queued', queued_at: null,
    ...overrides,
});

function commandQueue(version = 1, items: CommandQueueItem[] = [], limit = 20): CommandQueue {
    const byPosition = new Map(items.map((entry) => [entry.queue_position, entry]));
    const plan: EffectivePlanSlot[] = Array.from({ length: limit }, (_, index) => {
        const position = index + 1;
        const explicit = byPosition.get(position);
        return explicit === undefined
            ? { position, kind: 'automatic_finance', editable: false, command_name: '資金繰り', quantity: null }
            : { ...explicit, position, kind: 'explicit', editable: true };
    });
    return { version, limit, explicit_count: items.length, items, plan };
}

const catalog = (commands: CommandDefinition[]): CommandCatalog => ({
    commands,
    quantity_contract: {
        type: 'integer',
        minimum: 1,
        maximum: 99,
        default: 1,
        quick_presets: [1, 5, 10, 25, 50, 99],
    },
});

const jsonResponse = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

afterEach(() => vi.unstubAllGlobals());

describe('command plan workspace', () => {
    it('shows twenty slots and inserts a command at the selected row', async () => {
        let serverQueue = commandQueue();
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                serverQueue = commandQueue(2, [item(1, 5)]);
                return jsonResponse({
                    queue: serverQueue,
                    message: '開発計画に登録されました。実行はターン更新時に行われます。',
                }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : serverQueue);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(wrapper.findAll('.plan-row.automatic')).toHaveLength(20);
        await wrapper.findAll('.plan-row')[4]!.trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[4]!.classes()).toContain('selected');
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            command_key: 'land_clear', target_x: 8, target_y: 7, position: 5,
            expected_version: 1, quantity: 1, parameters: {},
        });
        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(wrapper.findAll('.plan-row')[4]!.text()).toContain('整地');
        expect(wrapper.findAll('.plan-row')[5]!.classes()).toContain('selected');
        expect(wrapper.text()).toContain('実行はターン更新時');
    });

    it('supports drag and keyboard reorder without permanent button clutter', async () => {
        const initial = commandQueue(3, [item(1, 1), item(2, 2, { command_name: '掘削', command_key: 'excavate' })]);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PUT') return jsonResponse(commandQueue(4, [item(1, 2), item(2, 1)]));
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : initial);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const rows = wrapper.findAll('.plan-row');
        expect(rows[0]!.attributes('draggable')).toBe('true');
        await rows[0]!.trigger('dragstart');
        await rows[1]!.trigger('drop');
        await flushPromises();
        const reorder = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT');
        expect(JSON.parse(String(reorder?.[1]?.body))).toEqual({
            placements: [{ id: 1, position: 2 }, { id: 2, position: 1 }],
            expected_version: 3,
        });
        expect(wrapper.find('.plan-row-actions').classes()).not.toContain('always-visible');

        await wrapper.findAll('.plan-row')[0]!.trigger('keydown', { key: 'ArrowDown', altKey: true });
        await flushPromises();
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'PUT').length).toBe(2);
    });

    it('renders and keyboard-reorders all thirty active-ruleset positions in the scrollable plan region', async () => {
        const items = Array.from({ length: 30 }, (_, index) => item(index + 1, index + 1));
        const initial = commandQueue(3, items, 30);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PUT') return jsonResponse(commandQueue(4, items, 30));
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : initial);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.findAll('.plan-row')).toHaveLength(30);
        expect(wrapper.find('.plan-list').exists()).toBe(true);
        expect(wrapper.find('.plan-panel .mobile-panel-toggle').text()).toContain('30');
        expect(wrapper.findAll('.plan-row')[29]!.attributes('draggable')).toBe('true');

        await wrapper.findAll('.plan-row')[29]!.trigger('keydown', { key: 'ArrowUp', altKey: true });
        await flushPromises();
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'PUT')).toBe(true);
    });

    it('opens the universal quantity editor by double click and keyboard without command schemas', async () => {
        const excavate = definition({
            key: 'excavate', name: '掘削', cost_money: 200, quantity_semantics: 'ordinary',
        });
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', quantity: 1, quantity_semantics: 'ordinary',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PATCH') return jsonResponse(commandQueue(2, [{ ...queued, quantity: 99 }]));
            return jsonResponse(String(input).includes('command-definitions') ? catalog([excavate]) : commandQueue(1, [queued]));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const row = wrapper.find('.plan-row');
        await row.trigger('dblclick');
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(true);
        await wrapper.find('.plan-parameter-popover input').setValue('99');
        await wrapper.find('.plan-parameter-popover').trigger('submit');
        await flushPromises();
        const patch = fetchMock.mock.calls.find(([, init]) => init?.method === 'PATCH');
        expect(JSON.parse(String(patch?.[1]?.body))).toEqual({
            quantity: 99, expected_version: 1,
        });

        await row.trigger('click');
        expect(row.classes()).toContain('selected');
        expect(row.find('.plan-row-actions').text()).toContain('数量');
        await row.trigger('keydown', { key: 'q' });
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(true);
    });

    it('adds deterministic seabed oil search with the ordinary presentation default', async () => {
        const seaTarget: MapCell = {
            ...selected,
            terrain: 'sea',
            terrain_name: '海',
            display_name: '海',
            owner_nation_id: null,
            owner_nation_number: null,
            owner_name: null,
        };
        const excavate = definition({
            key: 'excavate',
            name: '掘削',
            description: '海ではquantityに応じて海底油田を探索します。',
            cost_money: 200, quantity_semantics: 'ordinary',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({
                    queue: commandQueue(2, [item(10, 1, {
                        command_key: 'excavate', command_name: '掘削', quantity: 1, quantity_semantics: 'ordinary',
                    })]),
                    message: '開発計画に登録されました。',
                }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([excavate])
                : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, {
            props: { nationId: 1, mapSpaceId: 2, selected: seaTarget },
        });
        await flushPromises();

        const button = wrapper.find('.command-grid button');
        expect(button.text()).toContain('掘削');
        expect(button.attributes('title')).toContain('海底油田');
        await button.trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            command_key: 'excavate', target_x: 8, target_y: 7, quantity: 1,
        });
    });

    it('does not offer seabed oil search when backend target validation marks it inapplicable', async () => {
        const unavailable = definition({
            key: 'excavate',
            name: '掘削',
            description: '海底油田を探索します。',
            applicable: false,
            available: false,
            unavailable_reason: '施設のある海では油田探索できません。',
        });
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions') ? catalog([unavailable]) : commandQueue(),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.find('.command-grid button').exists()).toBe(false);
    });

    it('uses command-specific defaults and requires an explicit selector choice', async () => {
        const commands = [
            definition({ key: 'missile', name: 'ミサイル', quantity_default: 99 }),
            definition({ key: 'pp_missile', name: 'PPミサイル', quantity_default: 99 }),
            definition({ key: 'land_destruction_missile', name: '陸地破壊弾', quantity_default: 99 }),
            definition({ key: 'spp_missile', name: 'SPPミサイル', quantity_default: 1 }),
            definition({
                key: 'build_monument', name: '記念碑建設', quantity_semantics: 'selector', quantity_default: null,
                quantity_options: [{ value: 1, key: 'peace', label: '平和記念碑' }],
            }),
        ];
        const posted: number[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                const body = JSON.parse(String(init.body)) as { quantity: number };
                posted.push(body.quantity);
                return jsonResponse({ queue: commandQueue(posted.length + 1), message: '登録しました。' }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions') ? catalog(commands) : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const buttons = wrapper.findAll('.command-grid button');
        for (const button of buttons.slice(0, 4)) {
            await button.trigger('click');
            await flushPromises();
        }
        expect(posted).toEqual([99, 99, 99, 1]);
        await buttons[4]!.trigger('click');
        expect(wrapper.find('.parameter-popover select').exists()).toBe(true);
        expect(wrapper.find('.parameter-popover button[type="submit"]').attributes('disabled')).toBeDefined();
        expect(posted).toHaveLength(4);
        await wrapper.find('.parameter-popover select').setValue('1');
        await wrapper.find('.parameter-popover').trigger('submit');
        await flushPromises();
        expect(posted).toEqual([99, 99, 99, 1, 1]);
    });

    it('registers a nation-target command without a selected cell and submits its parameter schema', async () => {
        const monsterDispatch = definition({
            key: 'monster_dispatch',
            name: '怪獣派遣',
            target_type: 'nation',
            parameters: {
                target_nation_id: {
                    label: '対象Nation ID', type: 'integer', minimum: 1, maximum: 2_147_483_647, required: true,
                },
            },
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({ queue: commandQueue(2, [item(1, 1, {
                    command_key: 'monster_dispatch', command_name: '怪獣派遣', parameters: { target_nation_id: 42 },
                })]), message: '登録しました。' }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([monsterDispatch])
                : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected: null } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        const inputs = wrapper.findAll('.parameter-popover input');
        expect(inputs).toHaveLength(1);
        await inputs[0]!.setValue('42');
        await wrapper.find('.parameter-popover').trigger('submit');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            command_key: 'monster_dispatch',
            target_x: null,
            target_y: null,
            parameters: { target_nation_id: 42 },
        });
    });

    it('opens quantity editing from a single mobile plan-row tap', async () => {
        vi.stubGlobal('matchMedia', vi.fn(() => ({ matches: true })));
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions')
                ? catalog([definition({ key: 'excavate', quantity_semantics: 'ordinary' })])
                : commandQueue(1, [item(1, 1, { command_key: 'excavate', quantity_semantics: 'ordinary' })]),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('click');
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(true);
    });

    it('keeps the insertion cursor on a failed add and advances only after its successful retry', async () => {
        let fail = true;
        let serverQueue = commandQueue(1, [], 30);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                if (fail) return jsonResponse(null, 422);
                serverQueue = commandQueue(2, [item(1, 2)], 30);
                return jsonResponse({
                    queue: serverQueue,
                    message: '登録しました。',
                }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([definition()])
                : serverQueue);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.findAll('.plan-row')[1]!.trigger('click');
        await flushPromises();
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[1]!.classes()).toContain('selected');

        fail = false;
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[1]!.text()).toContain('整地');
        expect(wrapper.findAll('.plan-row')[2]!.classes()).toContain('selected');
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST').map(([, init]) => (
            JSON.parse(String(init?.body)) as { position: number }
        ).position)).toEqual([2, 2]);
    });

    it('clamps the insertion cursor after a successful add fills the queue limit', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return jsonResponse({
                queue: commandQueue(2, [item(1, 1)], 1), message: '登録しました。',
            }, 201);
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([definition()])
                : commandQueue(1, [], 1));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();
        expect(wrapper.find('.plan-row').classes()).toContain('selected');
        expect(wrapper.find('.plan-row').text()).toContain('整地');
    });

    it('advances from position one to two and then three only after successful posts', async () => {
        let posts = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                posts++;
                return jsonResponse({
                    queue: commandQueue(posts + 1, Array.from({ length: posts }, (_, index) => item(index + 1, index + 1))),
                    message: '登録しました。',
                }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[1]!.classes()).toContain('selected');
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[2]!.classes()).toContain('selected');
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST').map(([, init]) => (
            JSON.parse(String(init?.body)) as { position: number }
        ).position)).toEqual([1, 2]);
    });

    it('restores an ordinary quantity editor from the authoritative queue after patch failure', async () => {
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', quantity: 1, quantity_semantics: 'ordinary',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PATCH') return jsonResponse(null, 422);
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([definition({ key: 'excavate', quantity_semantics: 'ordinary' })])
                : commandQueue(1, [queued]));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('dblclick');
        const input = wrapper.find<HTMLInputElement>('.plan-parameter-popover input');
        await input.setValue('99');
        await wrapper.find('.plan-parameter-popover').trigger('submit');
        await flushPromises();
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(true);
        expect(wrapper.find<HTMLInputElement>('.plan-parameter-popover input').element.value).toBe('1');
        expect(wrapper.find('.plan-row').text()).toContain('×1');
    });

    it('closes a stale quantity editor when a 409 refresh shows the item was cancelled elsewhere', async () => {
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', quantity: 1, quantity_semantics: 'ordinary',
        });
        let queueReads = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PATCH') return jsonResponse(null, 409);
            if (String(input).includes('command-definitions')) {
                return jsonResponse(catalog([definition({ key: 'excavate', quantity_semantics: 'ordinary' })]));
            }
            queueReads++;
            return jsonResponse(queueReads === 1 ? commandQueue(1, [queued]) : commandQueue(2));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('dblclick');
        await wrapper.find('.plan-parameter-popover input').setValue('99');
        await wrapper.find('.plan-parameter-popover').trigger('submit');
        await flushPromises();
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(false);
        expect(wrapper.find('.plan-row').classes()).toContain('automatic');
    });

    it('redraws cancellation from the authoritative server queue and Escape never clears the cursor', async () => {
        const initial = commandQueue(1, [item(1, 1), item(2, 2, { command_name: '掘削' })]);
        const afterCancel = commandQueue(2, [item(2, 1, { command_name: '掘削' })]);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'DELETE') return jsonResponse(afterCancel);
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : initial);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('.plan-row').classes()).toContain('selected');
        const cancel = wrapper.findAll('.plan-row-actions button').find((button) => button.text() === '取消')!;
        await cancel.trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.plan-row')[0]!.text()).toContain('掘削');
        expect(wrapper.findAll('.plan-row')[1]!.classes()).toContain('automatic');
    });

    it('mutually collapses the mobile command sheet and plan drawer', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions') ? catalog([]) : commandQueue(),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();
        const toggles = wrapper.findAll('.mobile-panel-toggle');

        await toggles[0]!.trigger('click');
        expect(wrapper.find('.command-panel').classes()).toContain('expanded');
        expect(wrapper.find('.plan-panel').classes()).toContain('mobile-peer-expanded');
        await toggles[1]!.trigger('click');
        expect(wrapper.find('.command-panel').classes()).not.toContain('expanded');
        expect(wrapper.find('.command-panel').classes()).toContain('mobile-peer-expanded');
        expect(wrapper.find('.plan-panel').classes()).toContain('expanded');
        expect(wrapper.find('.plan-panel').classes()).not.toContain('mobile-peer-expanded');
    });

    it('ignores a successful stale refresh', async () => {
        let resolveOld!: (response: Response) => void;
        const old = new Promise<Response>((resolve) => { resolveOld = resolve; });
        let definitionCalls = 0;
        const fetchMock = vi.fn((input: RequestInfo | URL): Promise<Response> => {
            const path = String(input);
            if (path.includes('command-definitions')) {
                definitionCalls++;
                return definitionCalls === 1 ? old : Promise.resolve(jsonResponse(catalog([definition({ name: '最新コマンド' })])));
            }
            return Promise.resolve(jsonResponse(commandQueue(7)));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await wrapper.setProps({ selected: { ...selected, x: 9 } });
        await flushPromises();
        expect(wrapper.text()).toContain('最新コマンド');

        resolveOld(jsonResponse(catalog([definition({ name: '古いコマンド' })])));
        await flushPromises();
        expect(wrapper.text()).toContain('最新コマンド');
        expect(wrapper.text()).not.toContain('古いコマンド');
    });
});

describe('sale policy panel', () => {
    it('does not offer sell_all when wheat capabilities forbid it', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse([{
            resource_id: 10,
            resource_key: 'wheat',
            resource_name: '小麦',
            amount: 100,
            policy: 'stockpile',
            keep_amount: null,
            version: 1,
            allowed_policies: ['stockpile', 'keep_amount'],
        }])));
        const wrapper = mount(SalePolicyPanel, { props: { nationId: 1 } });
        await flushPromises();

        expect(wrapper.find('option[value="sell_all"]').exists()).toBe(false);
        expect(wrapper.find('option[value="stockpile"]').exists()).toBe(true);
        expect(wrapper.find('option[value="stockpile"]').text()).toBe('上限まで備蓄');
        expect(wrapper.find('option[value="keep_amount"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('個別上限を超えた分だけを売却');
    });

    it('updates keep_amount with the row version and exposes non-negative validation', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            const data = init?.method === 'PUT'
                ? { resource_id: 10, resource_key: 'wheat', resource_name: '小麦', amount: 100, policy: 'keep_amount', keep_amount: 25, version: 2 }
                : [{ resource_id: 10, resource_key: 'wheat', resource_name: '小麦', amount: 100, policy: 'stockpile', keep_amount: null, version: 1 }];
            return jsonResponse(data);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(SalePolicyPanel, { props: { nationId: 1 } });
        await flushPromises();

        await wrapper.find('select').setValue('keep_amount');
        const input = wrapper.find('input[type="number"]');
        expect(input.attributes('min')).toBe('0');
        await input.setValue('25');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const put = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT');
        expect(JSON.parse(String(put?.[1]?.body))).toEqual({ policy: 'keep_amount', keep_amount: 25, expected_version: 1 });
    });
});
