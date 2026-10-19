import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api, apiEnvelope } from '../api/client';
import ShipStatusWindow from './ShipStatusWindow.vue';

vi.mock('../api/client', () => ({ api: vi.fn(), apiEnvelope: vi.fn(), validationErrors: () => ({}) }));

function button(root: ParentNode, label: string): HTMLButtonElement {
    const found = [...root.querySelectorAll('button')].find((candidate) => candidate.textContent?.trim() === label || candidate.getAttribute('aria-label') === label);
    if (found === undefined) throw new Error(`button ${label} not found`);
    return found;
}

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('ship status window', () => {
    it('loads the complete fleet on open, shows directions and waiting warships, and refreshes removed ships', async () => {
        const fishing = { id: 1, name: '漁船', x: -2, y: 4, current_hp: 1, max_hp: 1, heading: null, destination: null, movement_mode: 'heading_or_random' };
        const types = [
            { key: 'fishing', name: '漁船', count: 1, capacity: 3 },
            { key: 'tourist', name: '観光船', count: 0, capacity: 3 },
            { key: 'warship', name: '軍艦', count: 1, capacity: 3 },
        ];
        vi.mocked(apiEnvelope).mockResolvedValueOnce({ data: [
            fishing,
            { ...fishing, id: 2, name: '探検船', heading: 1, current_hp: 1, max_hp: 2 },
            { ...fishing, id: 3, name: '軍艦', movement_mode: 'heading_only', max_hp: 5 },
            { ...fishing, id: 4, name: '軍艦', movement_mode: 'heading_only', destination: { x: 3, y: -1 }, max_hp: 5 },
        ], meta: { ship_types: types } }).mockResolvedValueOnce({ data: [], meta: {
            ship_types: types.map(type => ({ ...type, count: 0 })),
        } });
        const wrapper = mount(ShipStatusWindow, { props: { nationId: 7 }, attachTo: document.body });
        const dialog = document.querySelector('dialog')!;
        // jsdom does not implement native dialog methods; browser QA covers their behavior.
        dialog.showModal = () => { dialog.open = true; };
        dialog.close = () => { dialog.open = false; };
        expect(apiEnvelope).not.toHaveBeenCalled();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(apiEnvelope).toHaveBeenCalledWith('/api/v1/nations/7/ships');
        expect(dialog.querySelector('.ship-type-count-values')!.textContent).toContain('1/3');
        const rows = [...dialog.querySelectorAll('li')].map(row => row.textContent ?? '');
        expect(rows).toHaveLength(4);
        // 指示がなければ移動方式で表示が分かれる(おまかせ / 軍艦の待機)。目的地は進路より先に出す。
        expect(rows[0]).toContain('おまかせ');
        expect(rows[1]).toContain('北東');
        expect(rows[2]).toContain('待機');
        expect(rows[3]).toContain('目的地 (3,-1)');
        expect(rows[3]).not.toContain('待機');
        button(dialog, '更新').click();
        await flushPromises();
        expect(dialog.querySelectorAll('li')).toHaveLength(0);
        expect(dialog.querySelector('.ship-type-count-values')!.textContent).not.toContain('1/3');
        button(dialog, '閉じる').click();
        expect(dialog.open).toBe(false);
        wrapper.unmount();
    });

    it('leads with a given name, keeps type and position beside it, and renames or clears one ship', async () => {
        const base = { name: '海底工作船', current_hp: 3, max_hp: 3, heading: null, destination: null, movement_mode: 'heading_or_random' };
        vi.mocked(apiEnvelope).mockResolvedValueOnce({ data: [
            { ...base, id: 5, custom_name: 'エスポワール', x: 2, y: 3 },
            { ...base, id: 6, custom_name: null, x: 4, y: 5 },
        ], meta: { ship_types: [] } });
        const wrapper = mount(ShipStatusWindow, { props: { nationId: 7 }, attachTo: document.body });
        const dialog = document.querySelector('dialog')!;
        dialog.showModal = () => { dialog.open = true; };
        await wrapper.get('button').trigger('click');
        await flushPromises();
        const rows = () => [...dialog.querySelectorAll('li')];
        expect(rows()[0]!.querySelector('.given-name')!.textContent).toBe('エスポワール海底工作船 (2,3)');
        expect(rows()[1]!.querySelector('.given-name')).toBeNull();
        expect(rows()[1]!.textContent).toContain('海底工作船 (4,5)');

        vi.mocked(api).mockResolvedValueOnce({ id: 6, custom_name: 'ルミナ号' });
        button(rows()[1]!, '名前をつける').click();
        await flushPromises();
        const input = rows()[1]!.querySelector('input')!;
        input.value = 'ルミナ号';
        input.dispatchEvent(new Event('input'));
        rows()[1]!.querySelector('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(api).toHaveBeenLastCalledWith('/api/v1/nations/7/ships/6/name', expect.objectContaining({ method: 'PATCH', body: JSON.stringify({ name: 'ルミナ号' }) }));
        expect(rows()[1]!.querySelector('.given-name')!.textContent).toContain('ルミナ号');

        vi.mocked(api).mockResolvedValueOnce({ id: 5, custom_name: null });
        button(rows()[0]!, '名前を変える').click();
        await flushPromises();
        const rename = rows()[0]!.querySelector('input')!;
        expect(rename.value).toBe('エスポワール');
        rename.value = '  ';
        rename.dispatchEvent(new Event('input'));
        rows()[0]!.querySelector('form')!.dispatchEvent(new Event('submit'));
        await flushPromises();
        expect(api).toHaveBeenLastCalledWith('/api/v1/nations/7/ships/5/name', expect.objectContaining({ body: JSON.stringify({ name: null }) }));
        expect(rows()[0]!.querySelector('.given-name')).toBeNull();
        wrapper.unmount();
    });
});
