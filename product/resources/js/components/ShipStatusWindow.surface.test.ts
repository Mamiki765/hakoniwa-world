import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import ShipStatusWindow from './ShipStatusWindow.vue';

vi.mock('../api/client', () => ({ api: vi.fn() }));

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('ship status window', () => {
    it('loads the complete fleet on open, shows directions and waiting warships, and refreshes removed ships', async () => {
        const fishing = { id: 1, name: '漁船', x: -2, y: 4, current_hp: 1, max_hp: 1, heading: null, movement_mode: 'heading_or_random' };
        vi.mocked(api).mockResolvedValueOnce([
            fishing,
            { ...fishing, id: 2, name: '探検船', heading: 1, current_hp: 1, max_hp: 2 },
            { ...fishing, id: 3, name: '軍艦', movement_mode: 'heading_only', max_hp: 5 },
        ]).mockResolvedValueOnce([]);
        const wrapper = mount(ShipStatusWindow, { props: { nationId: 7 }, attachTo: document.body });
        const dialog = document.querySelector('dialog')!;
        // jsdom does not implement native dialog methods; browser QA covers their behavior.
        dialog.showModal = () => { dialog.open = true; };
        dialog.close = () => { dialog.open = false; };
        expect(api).not.toHaveBeenCalled();
        await wrapper.get('button').trigger('click');
        await flushPromises();
        expect(api).toHaveBeenCalledWith('/api/v1/nations/7/ships');
        expect([...dialog.querySelectorAll('li')].map(row => row.textContent)).toEqual([
            '漁船 (-2,4) HP1/1 ランダム', '探検船 (-2,4) HP1/2 北東', '軍艦 (-2,4) HP1/5 待機中',
        ]);
        dialog.querySelectorAll('button')[1]!.click();
        await flushPromises();
        expect(dialog.textContent).toContain('生存している船はありません。');
        dialog.querySelector('button')!.click();
        expect(dialog.open).toBe(false);
        wrapper.unmount();
    });
});
