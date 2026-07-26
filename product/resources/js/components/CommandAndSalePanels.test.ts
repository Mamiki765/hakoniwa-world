import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type {
    CommandDefinition,
    CommandParameterSchema,
    CommandQueue,
    CommandQueueItem,
    EffectivePlanSlot,
    MapCell,
} from '../types';
import CommandQueuePanel from './CommandQueuePanel.vue';
import SalePolicyPanel from './SalePolicyPanel.vue';

const selected: MapCell = {
    x: 8, y: 7, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
    display_name: '平地', owner_nation_id: 1, owner_name: '操作国', details: [],
    asset: { key: 'tile.plain', url: null, available: false, fallback_label: '平地', fallback_style: 'tile-plain' },
    overlays: [], aria_label: 'x 8 y 7 平地 所有 操作国', version: 1, updated_at: null,
};

const quantitySchema: CommandParameterSchema = {
    label: '数量', type: 'integer', minimum: 1, maximum: 99, default: 1,
    quick_presets: [1, 5, 10, 25, 50, 99], required: true, meaning: '予約数量',
};

const definition = (overrides: Partial<CommandDefinition> = {}): CommandDefinition => ({
    key: 'land_clear', name: '整地', description: '平地にします。', cost_money: 5,
    execution_phase: 'terrain', parameter_schema: {}, initial_facility_capacity: null,
    applicable: true, available: true, shortfall_money: 0, unavailable_reason: null,
    ...overrides,
});

const item = (id: number, position: number, overrides: Partial<CommandQueueItem> = {}): CommandQueueItem => ({
    id, command_key: 'land_clear', command_name: '整地', queue_position: position,
    target_x: 8, target_y: 7, parameters: {}, status: 'queued', queued_at: null,
    ...overrides,
});

function commandQueue(version = 1, items: CommandQueueItem[] = []): CommandQueue {
    const byPosition = new Map(items.map((entry) => [entry.queue_position, entry]));
    const plan: EffectivePlanSlot[] = Array.from({ length: 20 }, (_, index) => {
        const position = index + 1;
        const explicit = byPosition.get(position);
        return explicit === undefined
            ? { position, kind: 'automatic_finance', editable: false, command_name: '資金繰り' }
            : { ...explicit, position, kind: 'explicit', editable: true };
    });
    return { version, limit: 20, explicit_count: items.length, items, plan };
}

const jsonResponse = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

afterEach(() => vi.unstubAllGlobals());

describe('command plan workspace', () => {
    it('shows twenty slots and inserts a command at the selected row', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') {
                return jsonResponse({
                    queue: commandQueue(2, [item(1, 5)]),
                    message: '開発計画に登録されました。実行はターン更新時に行われます。',
                }, 201);
            }
            return jsonResponse(String(input).includes('command-definitions') ? [definition()] : commandQueue());
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(wrapper.findAll('.plan-row.automatic')).toHaveLength(20);
        await wrapper.findAll('.plan-row')[4]!.trigger('click');
        expect(wrapper.findAll('.plan-row')[4]!.classes()).toContain('selected');
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({
            command_key: 'land_clear', target_x: 8, target_y: 7, position: 5, expected_version: 1,
        });
        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(wrapper.findAll('.plan-row')[4]!.text()).toContain('整地');
        expect(wrapper.text()).toContain('実行はターン更新時');
    });

    it('supports drag and keyboard reorder without permanent button clutter', async () => {
        const initial = commandQueue(3, [item(1, 1), item(2, 2, { command_name: '掘削', command_key: 'excavate' })]);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PUT') return jsonResponse(commandQueue(4, [item(1, 2), item(2, 1)]));
            return jsonResponse(String(input).includes('command-definitions') ? [definition()] : initial);
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

    it('opens quantity presets by double click and by a visible selected-row action', async () => {
        const excavate = definition({
            key: 'excavate', name: '掘削', cost_money: 200, parameter_schema: { quantity: quantitySchema },
        });
        const queued = item(9, 1, {
            command_key: 'excavate', command_name: '掘削', parameters: { quantity: 1 },
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'PATCH') return jsonResponse(commandQueue(2, [{ ...queued, parameters: { quantity: 99 } }]));
            return jsonResponse(String(input).includes('command-definitions') ? [excavate] : commandQueue(1, [queued]));
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
            parameters: { quantity: 99 }, expected_version: 1,
        });

        await row.trigger('click');
        expect(row.classes()).toContain('selected');
        expect(row.find('.plan-row-actions').text()).toContain('数量');
    });

    it('mutually collapses the mobile command sheet and plan drawer', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => jsonResponse(
            String(input).includes('command-definitions') ? [] : commandQueue(),
        )));
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();
        const toggles = wrapper.findAll('.mobile-panel-toggle');

        await toggles[0]!.trigger('click');
        expect(wrapper.find('.command-panel').classes()).toContain('expanded');
        await toggles[1]!.trigger('click');
        expect(wrapper.find('.command-panel').classes()).not.toContain('expanded');
        expect(wrapper.find('.plan-panel').classes()).toContain('expanded');
    });

    it('ignores a successful stale refresh', async () => {
        let resolveOld!: (response: Response) => void;
        const old = new Promise<Response>((resolve) => { resolveOld = resolve; });
        let definitionCalls = 0;
        const fetchMock = vi.fn((input: RequestInfo | URL): Promise<Response> => {
            const path = String(input);
            if (path.includes('command-definitions')) {
                definitionCalls++;
                return definitionCalls === 1 ? old : Promise.resolve(jsonResponse([definition({ name: '最新コマンド' })]));
            }
            return Promise.resolve(jsonResponse(commandQueue(7)));
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        await wrapper.setProps({ selected: { ...selected, x: 9 } });
        await flushPromises();
        expect(wrapper.text()).toContain('最新コマンド');

        resolveOld(jsonResponse([definition({ name: '古いコマンド' })]));
        await flushPromises();
        expect(wrapper.text()).toContain('最新コマンド');
        expect(wrapper.text()).not.toContain('古いコマンド');
    });
});

describe('sale policy panel', () => {
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
