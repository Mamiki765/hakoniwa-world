import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { CommandDefinition, CommandQueue, MapCell } from '../types';
import CommandQueuePanel from './CommandQueuePanel.vue';
import SalePolicyPanel from './SalePolicyPanel.vue';

const selected: MapCell = {
    x: -4, y: 7, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
    display_name: '平地', owner_nation_id: 1, owner_name: '操作国', details: [],
    asset: { key: 'tile.plain', url: null, available: false, fallback_label: '平地', fallback_style: 'tile-plain' },
    overlays: [], aria_label: 'x -4 y 7 平地 所有 操作国', version: 1, updated_at: null,
};

const item = (id: number, position: number) => ({
    id, command_key: id === 1 ? 'land_clear' : 'build_farm', command_name: id === 1 ? '整地' : '農場建設',
    queue_position: position, target_x: -4, target_y: 7, parameters: {}, status: 'queued', queued_at: null,
});

const commandDefinition = (key: string, name: string): CommandDefinition => ({
    key, name, description: `${name}の説明`, cost_money: 5, execution_phase: 'terrain',
    initial_facility_capacity: null, available: true, unavailable_reason: null,
});

const jsonResponse = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void; reject: (reason: unknown) => void } {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

afterEach(() => vi.unstubAllGlobals());

describe('command queue panel', () => {
    it('adds the selected x/y and clearly reports that execution is deferred', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            let data: unknown;
            if (init?.method === 'POST') {
                data = { queue: { version: 2, limit: 20, items: [item(1, 1)] }, message: 'commandはqueueへ登録されただけで、まだ実行されていません。' };
            } else if (path.includes('command-definitions')) {
                data = [{
                    key: 'land_clear', name: '整地', description: '平地にします。', cost_money: 5,
                    execution_phase: 'terrain', initial_facility_capacity: null, available: true, unavailable_reason: null,
                }];
            } else {
                data = { version: 1, limit: 20, items: [] };
            }
            return new Response(JSON.stringify({ data }), { status: init?.method === 'POST' ? 201 : 200, headers: { 'Content-Type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        expect(wrapper.text()).toContain('commandはqueueへ登録されただけで、まだ実行されていません');
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(post).toBeDefined();
        const body = JSON.parse(String(post?.[1]?.body)) as Record<string, unknown>;
        expect(body.target_x).toBe(-4);
        expect(body.target_y).toBe(7);
        expect(body.command_key).toBe('land_clear');
        expect(wrapper.text()).toContain('整地（-4, 7）');
    });

    it('reorders and cancels queued items with the current optimistic version', async () => {
        const initialQueue: CommandQueue = { version: 3, limit: 20, items: [item(1, 1), item(2, 2)] };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            let data: unknown;
            if (init?.method === 'PUT') data = { version: 4, limit: 20, items: [item(2, 1), item(1, 2)] };
            else if (init?.method === 'DELETE') data = { version: 5, limit: 20, items: [item(1, 1)] };
            else data = path.includes('command-definitions') ? [] : initialQueue;
            return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await flushPromises();

        await wrapper.findAll('.queue-actions')[0]!.findAll('button')[1]!.trigger('click');
        await flushPromises();
        const reorder = fetchMock.mock.calls.find(([, init]) => init?.method === 'PUT');
        const reorderBody = JSON.parse(String(reorder?.[1]?.body)) as { ordered_ids: number[]; expected_version: number };
        expect(reorderBody).toEqual({ ordered_ids: [2, 1], expected_version: 3 });

        await wrapper.findAll('.queue-actions')[0]!.findAll('button')[2]!.trigger('click');
        await flushPromises();
        const cancellation = fetchMock.mock.calls.find(([, init]) => init?.method === 'DELETE');
        expect(String(cancellation?.[0])).toContain('/command-queue/2');
        expect(JSON.parse(String(cancellation?.[1]?.body))).toEqual({ expected_version: 4 });
    });

    it('ignores a successful stale refresh and mutates with the latest cell and queue version', async () => {
        const definitionA = deferred<Response>();
        const queueA = deferred<Response>();
        const definitionB = deferred<Response>();
        const queueB = deferred<Response>();
        let queueGets = 0;
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            const path = String(input);
            if (init?.method === 'POST') {
                return Promise.resolve(jsonResponse({
                    queue: { version: 10, limit: 20, items: [item(20, 1)] },
                    message: 'Bを予約しました。',
                }, 201));
            }
            if (path.includes('command-definitions')) {
                return path.includes('target_x=-4') ? definitionA.promise : definitionB.promise;
            }

            queueGets++;
            return queueGets === 1 ? queueA.promise : queueB.promise;
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        const selectedB = { ...selected, x: 8, y: 9, aria_label: 'x 8 y 9 平地 所有 操作国' };
        await wrapper.setProps({ selected: selectedB });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
        definitionB.resolve(jsonResponse([commandDefinition('build_factory', 'Bの工場建設')]));
        queueB.resolve(jsonResponse({ version: 9, limit: 20, items: [item(20, 1)] }));
        await flushPromises();

        expect(wrapper.text()).toContain('Bの工場建設');
        expect(wrapper.text()).not.toContain('Aの整地');
        expect(wrapper.text()).toContain('予約 1/20');

        definitionA.resolve(jsonResponse([commandDefinition('land_clear', 'Aの整地')]));
        queueA.resolve(jsonResponse({ version: 2, limit: 20, items: [] }));
        await flushPromises();

        expect(wrapper.text()).toContain('Bの工場建設');
        expect(wrapper.text()).not.toContain('Aの整地');
        expect(wrapper.text()).toContain('予約 1/20');
        await wrapper.find('.command-grid button').trigger('click');
        await flushPromises();

        const post = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        const body = JSON.parse(String(post?.[1]?.body)) as Record<string, unknown>;
        expect(body).toMatchObject({ command_key: 'build_factory', target_x: 8, target_y: 9, expected_version: 9 });
        expect(wrapper.text()).not.toContain('A stale');
    });

    it('keeps the latest refresh busy and hides AbortError from the superseded refresh', async () => {
        const definitionB = deferred<Response>();
        const queueB = deferred<Response>();
        let queueGets = 0;
        const abortingResponse = (signal: AbortSignal | null | undefined) => new Promise<Response>((_resolve, reject) => {
            signal?.addEventListener('abort', () => reject(new DOMException('A request aborted', 'AbortError')), { once: true });
        });
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            if (String(input).includes('command-definitions')) {
                return String(input).includes('target_x=-4') ? abortingResponse(init?.signal) : definitionB.promise;
            }
            queueGets++;
            return queueGets === 1 ? abortingResponse(init?.signal) : queueB.promise;
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        await wrapper.setProps({ selected: { ...selected, x: 8, y: 9 } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(4));
        await flushPromises();

        expect(wrapper.find('.command-panel').attributes('aria-busy')).toBe('true');
        expect(wrapper.text()).not.toContain('A request aborted');
        definitionB.resolve(jsonResponse([commandDefinition('build_mine', 'Bの採掘場建設')]));
        queueB.resolve(jsonResponse({ version: 11, limit: 20, items: [] }));
        await flushPromises();

        expect(wrapper.find('.command-panel').attributes('aria-busy')).toBe('false');
        expect(wrapper.text()).toContain('Bの採掘場建設');
        expect(wrapper.text()).not.toContain('A request aborted');
    });

    it('does not show AbortError and aborts the active refresh when unmounted', async () => {
        const activeSignals: AbortSignal[] = [];
        const fetchMock = vi.fn((_input: RequestInfo | URL, init?: RequestInit): Promise<Response> => {
            const signal = init?.signal;
            if (signal !== null && signal !== undefined) activeSignals.push(signal);

            return new Promise<Response>((_resolve, reject) => {
                signal?.addEventListener('abort', () => reject(new DOMException('request aborted', 'AbortError')), { once: true });
            });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(CommandQueuePanel, { props: { nationId: 1, mapSpaceId: 2, selected } });
        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        wrapper.unmount();
        await flushPromises();

        expect(activeSignals).toHaveLength(2);
        expect(activeSignals.every((signal) => signal.aborted)).toBe(true);
        expect(document.body.textContent).not.toContain('request aborted');
    });
});

describe('sale policy panel', () => {
    it('updates keep_amount with the row version and exposes non-negative input validation', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            const data = init?.method === 'PUT'
                ? { resource_id: 10, resource_key: 'wheat', resource_name: '小麦', amount: 100, policy: 'keep_amount', keep_amount: 25, version: 2 }
                : [{ resource_id: 10, resource_key: 'wheat', resource_name: '小麦', amount: 100, policy: 'stockpile', keep_amount: null, version: 1 }];
            return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
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
        expect(wrapper.text()).toContain('保存しました');
    });
});
