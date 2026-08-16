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

const errorResponse = (
    message: string,
    status: number,
    extra: { errors?: Record<string, string[]>; code?: string } = {},
) => new Response(JSON.stringify({ message, ...extra }), {
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
        expect(wrapper.get('.command-status').text()).toBe('未送信');
        expect(wrapper.get('.command-status').classes()).toContain('command-status--idle');
        expect(wrapper.get('.command-status').attributes('role')).toBe('status');
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
        expect(wrapper.get('.command-status').text()).toBe('送信完了');
        expect(wrapper.get('.command-status').classes()).toContain('command-status--success');
        expect(wrapper.get('.command-status').attributes('aria-live')).toBe('polite');
        expect(wrapper.text()).not.toContain('実行はターン更新時');
        expect(wrapper.text()).not.toContain('登録されました');
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
    });

    it('shows a concise player-facing validation error with semantic alert styling', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return errorResponse('The given data was invalid.', 422, {
                errors: { target_x: ['首都の上には建設できません'] },
            });
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        const status = wrapper.get('.command-status');
        expect(status.text()).toBe('送信エラー：首都の上には建設できません');
        expect(status.classes()).toContain('command-status--error');
        expect(status.attributes('role')).toBe('alert');
        expect(status.attributes('aria-live')).toBe('assertive');
    });

    it('does not expose an untyped domain exception from a 422 response', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return errorResponse('Command definition no longer matches the locked World ruleset.', 422);
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.command-status').text()).toBe('送信エラー：入力内容を確認してください');
        expect(wrapper.text()).not.toContain('locked World ruleset');
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
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);

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
        expect(wrapper.find('.mobile-panel-toggle').exists()).toBe(false);
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
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);

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
        expect(wrapper.find('.parameter-popover input[type="number"]').exists()).toBe(true);
        await wrapper.find('.parameter-popover').trigger('submit');
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
            definition({ key: 'missile', name: 'ミサイル', quantity_semantics: 'ordinary', quantity_default: 99 }),
            definition({ key: 'pp_missile', name: 'PPミサイル', quantity_semantics: 'ordinary', quantity_default: 99 }),
            definition({ key: 'land_destruction_missile', name: '陸地破壊弾', quantity_semantics: 'ordinary', quantity_default: 99 }),
            definition({ key: 'spp_missile', name: 'SPPミサイル', quantity_semantics: 'ordinary', quantity_default: 1 }),
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
            expect(wrapper.find('.parameter-popover input[type="number"]').exists()).toBe(true);
            await wrapper.find('.parameter-popover').trigger('submit');
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
                    input_semantics: 'nation_selector',
                    options: [{ value: 42, label: '援助対象島', nation_number: 7 }],
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
        expect(inputs).toHaveLength(0);
        const targetSelector = wrapper.find<HTMLSelectElement>('.nation-target-select');
        expect(targetSelector.exists()).toBe(true);
        expect(targetSelector.text()).toContain('援助対象島 (7)');
        await targetSelector.setValue('42');
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

    it('keeps a nation target selector separate from ordinary aid quantity', async () => {
        const moneyAid = definition({
            key: 'money_aid',
            name: '資金援助',
            target_type: 'nation',
            quantity_semantics: 'ordinary',
            parameters: {
                target_nation_id: {
                    label: '対象島', type: 'integer', minimum: 1, maximum: 2_147_483_647, required: true,
                    input_semantics: 'nation_selector',
                    options: [{ value: 42, label: '援助対象島', nation_number: 7 }],
                },
            },
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({ queue: commandQueue(2), message: '登録しました。' }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([moneyAid])
                : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected: null } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        const popover = wrapper.find('.parameter-popover');
        expect(popover.findAll('input[type="number"]')).toHaveLength(1);
        expect(popover.findAll('select')).toHaveLength(1);
        await popover.find('.nation-target-select').setValue('42');
        await popover.find('input[type="number"]').setValue('5');
        await popover.trigger('submit');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            command_key: 'money_aid',
            quantity: 5,
            parameters: { target_nation_id: 42 },
        });
    });

    it('closes a prior parameter form when switching to an immediate unused command', async () => {
        const monument = definition({
            key: 'build_monument',
            quantity_semantics: 'selector',
            quantity_default: null,
            quantity_options: [{ value: 1, key: 'peace', label: '平和記念碑' }],
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({ queue: commandQueue(2), message: '登録しました。' }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([monument, definition()])
                : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const buttons = wrapper.findAll('.command-grid button');
        await buttons[0]!.trigger('click');
        expect(wrapper.find('.parameter-popover').exists()).toBe(true);
        await buttons[1]!.trigger('click');
        await flushPromises();

        expect(wrapper.find('.parameter-popover').exists()).toBe(false);
        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({ command_key: 'land_clear' });
    });

    it('opens quantity editing from a single plan-row tap through landscape touch width', async () => {
        const matchMedia = vi.fn((query: string) => ({ matches: query === '(max-width: 900px)' }));
        vi.stubGlobal('matchMedia', matchMedia);
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions')
                ? catalog([definition({ key: 'excavate', quantity_semantics: 'ordinary' })])
                : commandQueue(1, [item(1, 1, { command_key: 'excavate', quantity_semantics: 'ordinary' })]),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('click');
        expect(wrapper.find('.plan-parameter-popover').exists()).toBe(true);
        expect(matchMedia).toHaveBeenCalledWith('(max-width: 900px)');
        expect(wrapper.findAll('.plan-row-actions button').map((button) => button.attributes('aria-label')))
            .toEqual(['前へ移動', '後へ移動', undefined, undefined]);
    });

    it('keeps pending-command and plan-editor quantities independent', async () => {
        const excavate = definition({ key: 'excavate', quantity_semantics: 'ordinary' });
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', quantity: 2, quantity_semantics: 'ordinary',
        });
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions') ? catalog([excavate]) : commandQueue(1, [queued]),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        const pendingInput = wrapper.find<HTMLInputElement>('.available-commands .parameter-popover input');
        await pendingInput.setValue('5');
        await wrapper.find('.plan-row').trigger('dblclick');
        const editingInput = wrapper.find<HTMLInputElement>('.plan-parameter-popover input');
        expect(editingInput.element.value).toBe('2');
        await editingInput.setValue('9');

        expect(pendingInput.element.value).toBe('5');
        expect(editingInput.element.value).toBe('9');
    });

    it('defers refresh during a committed add and finishes with the authoritative queue', async () => {
        let resolvePost!: (response: Response) => void;
        const pendingPost = new Promise<Response>((resolve) => { resolvePost = resolve; });
        let queueReads = 0;
        let serverQueue = commandQueue();
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            if (init?.method === 'POST') return pendingPost;
            if (String(input).includes('command-definitions')) return Promise.resolve(jsonResponse(catalog([definition()])));
            queueReads++;
            return Promise.resolve(jsonResponse(serverQueue));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await vi.waitFor(() => expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true));
        await wrapper.setProps({ selected: { ...selected, x: 9 } });
        await wrapper.findAll('.plan-row')[9]!.trigger('click');
        await flushPromises();
        expect(queueReads).toBe(1);

        serverQueue = commandQueue(2, [item(1, 1)]);
        resolvePost(jsonResponse({ queue: serverQueue }, 201));
        await vi.waitFor(() => expect(wrapper.get('.command-status').text()).toBe('送信完了'));
        await vi.waitFor(() => expect(queueReads).toBe(2));
        expect(wrapper.find('.plan-row').text()).toContain('整地');
        expect(wrapper.findAll('.plan-row')[9]!.classes()).toContain('selected');
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
    });

    it('does not let an old pending form abort an active cell refresh', async () => {
        let resolveDefinitions!: (response: Response) => void;
        const pendingDefinitions = new Promise<Response>((resolve) => { resolveDefinitions = resolve; });
        let definitionReads = 0;
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            if (init?.method === 'POST') return Promise.resolve(jsonResponse({ queue: commandQueue(2) }, 201));
            if (String(input).includes('command-definitions')) {
                definitionReads++;
                return definitionReads === 1
                    ? Promise.resolve(jsonResponse(catalog([definition({ quantity_semantics: 'ordinary' })])))
                    : pendingDefinitions;
            }
            return Promise.resolve(jsonResponse(commandQueue()));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        const pendingForm = wrapper.get('.available-commands .parameter-popover');
        await wrapper.setProps({ selected: { ...selected, x: 9 } });
        await vi.waitFor(() => expect(definitionReads).toBe(2));
        expect(pendingForm.get('button[type="submit"]').attributes('disabled')).toBeDefined();
        await pendingForm.trigger('submit');
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);

        resolveDefinitions(jsonResponse(catalog([definition({ name: '更新後コマンド', quantity_semantics: 'ordinary' })])));
        await vi.waitFor(() => expect(wrapper.text()).toContain('更新後コマンド'));
        expect(wrapper.find('.available-commands .parameter-popover').exists()).toBe(false);
        await pendingForm.trigger('submit');
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
        expect(wrapper.get('.plan-panel').attributes('aria-busy')).toBe('false');
    });

    it('reconciles the authoritative queue after a committed add loses its response', async () => {
        let serverQueue = commandQueue();
        let queueReads = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                serverQueue = commandQueue(2, [item(1, 1)]);
                throw new TypeError('network response lost');
            }
            if (String(input).includes('command-definitions')) return jsonResponse(catalog([definition()]));
            queueReads++;
            return jsonResponse(serverQueue);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await vi.waitFor(() => expect(queueReads).toBe(2));

        expect(wrapper.find('.plan-row').text()).toContain('整地');
        expect(wrapper.get('.command-status').text()).toBe('送信エラー：通信に失敗しました');
        expect(wrapper.text()).not.toContain('network response lost');
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

    it('synchronizes an open quantity editor on a normal authoritative refresh', async () => {
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', quantity: 1, quantity_semantics: 'ordinary',
        });
        let queueReads = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            if (String(input).includes('command-definitions')) {
                return jsonResponse(catalog([definition({ key: 'excavate', quantity_semantics: 'ordinary' })]));
            }
            queueReads++;
            return jsonResponse(commandQueue(queueReads, [{ ...queued, quantity: queueReads === 1 ? 1 : 5 }]));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.plan-row').trigger('dblclick');
        await wrapper.find('.plan-parameter-popover input').setValue('99');
        await wrapper.setProps({ selected: { ...selected, x: 9 } });
        await vi.waitFor(() => expect(queueReads).toBe(2));

        await vi.waitFor(() => expect(wrapper.find<HTMLInputElement>('.plan-parameter-popover input').element.value).toBe('5'));
        expect(wrapper.find('.plan-row').text()).toContain('×5');
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
        expect(wrapper.get('.command-status').text()).toBe('送信エラー：開発計画が更新されたため再読み込みしました');
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
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
        expect(wrapper.get('.command-status').text()).toBe('送信完了');
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
    });

    it('keeps the authoritative plan and all panels after cancellation fails', async () => {
        const initial = commandQueue(1, [item(1, 1)]);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'DELETE') return errorResponse('internal queue invariant', 422);
            return jsonResponse(String(input).includes('command-definitions') ? catalog([definition()]) : initial);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const cancel = wrapper.findAll('.plan-row-actions button').find((button) => button.text() === '取消')!;
        await cancel.trigger('click');
        await flushPromises();

        expect(wrapper.find('.plan-row').text()).toContain('整地');
        expect(wrapper.get('.command-status').text()).toBe('送信エラー：入力内容を確認してください');
        expect(wrapper.text()).not.toContain('internal queue invariant');
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
    });

    it('does not treat reset_required as an optimistic queue conflict', async () => {
        let queueReads = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return errorResponse('reset_required: internal World details', 409, { code: 'reset_required' });
            }
            if (String(input).includes('command-definitions')) return jsonResponse(catalog([definition()]));
            queueReads++;
            return jsonResponse(commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.command-status').text()).toBe('送信エラー：この島は現在のルールでは変更できません');
        expect(wrapper.text()).not.toContain('internal World details');
        expect(queueReads).toBe(1);
    });

    it('keeps command and plan panels permanently mounted without mobile drawer controls', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions') ? catalog([]) : commandQueue(),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.find('.command-workspace').exists()).toBe(true);
        expect(wrapper.find('.command-panel').exists()).toBe(true);
        expect(wrapper.find('.plan-panel').exists()).toBe(true);
        expect(wrapper.find('.mobile-panel-toggle').exists()).toBe(false);
        expect(wrapper.find('.command-panel').classes()).not.toContain('expanded');
        expect(wrapper.find('.command-panel').classes()).not.toContain('mobile-peer-expanded');
        expect(wrapper.find('.plan-panel').classes()).not.toContain('expanded');
        expect(wrapper.find('.plan-panel').classes()).not.toContain('mobile-peer-expanded');
    });

    it('starts bulk insertion and delete-from-here at the selected position with explicit feedback', async () => {
        let serverQueue = commandQueue(7, [], 30);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path.endsWith('/command-queue/bulk') && init?.method === 'POST') {
                serverQueue = commandQueue(8, [item(101, 5), item(102, 6)], 30);
                return jsonResponse({
                    queue: serverQueue,
                    inserted_count: 2,
                    truncated_count: 3,
                    candidate_count: 5,
                });
            }
            if (path.endsWith('/command-queue/from') && init?.method === 'DELETE') {
                serverQueue = commandQueue(9, [], 30);
                return jsonResponse({ queue: serverQueue, deleted_count: 2 });
            }

            return jsonResponse(path.includes('command-definitions') ? catalog([definition()]) : serverQueue);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.findAll('.plan-row')[4]!.trigger('click');
        await flushPromises();
        await wrapper.findAll('.bulk-actions button').find((button) => button.text() === '全て整地')!.trigger('click');
        await flushPromises();

        const bulk = fetchMock.mock.calls.find(([path, init]) => String(path).endsWith('/command-queue/bulk') && init?.method === 'POST');
        expect(JSON.parse(String(bulk?.[1]?.body))).toMatchObject({
            action: 'clear_all',
            position: 5,
            expected_version: 7,
        });
        expect(wrapper.get('.command-status').text()).toContain('31件目以降の3件を末尾から切り捨てました');

        await wrapper.findAll('.bulk-actions button').find((button) => button.text() === 'ここから下を削除')!.trigger('click');
        expect(wrapper.find('.command-modal').text()).toContain('5番以降をすべて削除');
        expect(fetchMock.mock.calls.some(([path, init]) => String(path).endsWith('/command-queue/from') && init?.method === 'DELETE')).toBe(false);
        await wrapper.find('.command-modal .danger-action').trigger('click');
        await flushPromises();

        const deletion = fetchMock.mock.calls.find(([path, init]) => String(path).endsWith('/command-queue/from') && init?.method === 'DELETE');
        expect(JSON.parse(String(deletion?.[1]?.body))).toEqual({ position: 5, expected_version: 8 });
        expect(wrapper.get('.command-status').text()).toBe('2件を削除しました');
    });

    it('warns for the hidden defense variant and keeps the monument target optional', async () => {
        const defense = definition({
            key: 'build_defense_facility',
            name: '防衛施設建設',
            command_suffix: '（自爆）',
            command_suffix_tone: 'danger',
            confirmation_message: 'この位置に防衛施設を建設すると自爆します。',
        });
        const monument = definition({
            key: 'build_monument',
            name: '記念碑建設',
            quantity_semantics: 'selector',
            quantity_default: null,
            quantity_options: [{ value: 1, key: 'peace', label: '平和記念碑' }],
            parameters: {
                target_nation_id: {
                    label: '対象島',
                    type: 'integer',
                    input_semantics: 'nation_selector',
                    options: [{ value: 42, label: '目標島', nation_number: 7 }],
                    minimum: 1,
                    maximum: 2_147_483_647,
                    required: false,
                    nullable: true,
                },
            },
        });
        const queued = item(1, 1, {
            command_key: 'build_defense_facility',
            command_name: '防衛施設建設',
            command_suffix: '（自爆）',
            command_suffix_tone: 'danger',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({ queue: commandQueue(2, [queued], 30), message: '登録しました。' }, 201);
            }

            return jsonResponse(String(input).includes('command-definitions')
                ? catalog([defense, monument])
                : commandQueue(1, [queued], 30));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        const dangerLabels = wrapper.findAll('.danger-suffix');
        expect(dangerLabels).toHaveLength(2);
        expect(dangerLabels.every((label) => label.text() === '（自爆）')).toBe(true);
        await wrapper.findAll('.command-grid button')[0]!.trigger('click');
        expect(wrapper.find('.command-modal').text()).toContain('自爆');
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(false);
        await wrapper.find('.command-modal .danger-action').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([, init]) => init?.method === 'POST')).toBe(true);

        await wrapper.findAll('.command-grid button')[1]!.trigger('click');
        expect(wrapper.find('.parameter-popover').exists()).toBe(true);
        expect(wrapper.find('.nation-target-select').text()).toContain('対象島なし');
        expect(wrapper.find('.nation-target-select').attributes('required')).toBeUndefined();
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
