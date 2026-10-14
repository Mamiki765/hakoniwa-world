import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { apiEnvelope } from '../api/client';
import ShipStatusWindow from './ShipStatusWindow.vue';

vi.mock('../api/client', () => ({ apiEnvelope: vi.fn() }));

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
        dialog.querySelectorAll('button')[1]!.click();
        await flushPromises();
        expect(dialog.querySelectorAll('li')).toHaveLength(0);
        expect(dialog.querySelector('.ship-type-count-values')!.textContent).not.toContain('1/3');
        dialog.querySelector('button')!.click();
        expect(dialog.open).toBe(false);
        wrapper.unmount();
    });
});
