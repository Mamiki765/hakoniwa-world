import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UndergroundPanel from './UndergroundPanel.vue';

const response = (data: unknown) => new Response(JSON.stringify({ data }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
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
});
