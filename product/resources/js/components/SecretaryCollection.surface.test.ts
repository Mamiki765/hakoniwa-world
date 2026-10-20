import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SecretaryCollection from './SecretaryCollection.vue';

const ring = {
    registered: true, key: 'ring', name: '指輪', category: 'accessory', category_label: 'アクセサリー', rarity: 'regular', rarity_label: 'レギュラー',
    max_level: 10, synthesis_level_cap: null, effect_text: '最大Lvの効果', flavor_text: '小さな指輪。', fixed_sale_price_money: 500, tradable: true,
    registered_at: '2026-10-21T00:00:00+09:00', claimed: false,
};
const collection = (claimed: boolean, balance: number) => ({
    summary: { registered: 2, total: 4, unclaimed: claimed ? 0 : 1, next_bonus_remaining: 8, entry_paradox: 5, bonus_paradox: 20 },
    monsters: [
        { registered: true, key: 'inora', name: 'いのら', hp: '1〜2', experience: '5', appearance: '人口1000人以上', note: '', first_turn: 3, claimed: true },
        { registered: false, hint: '人口3000人以上' },
    ],
    items: [{ ...ring, claimed }, { registered: false, category: 'bow', category_label: '弓', rarity: 'relic', rarity_label: 'レリック' }],
    bonuses: [{ milestone: 10, reached: false, claimed: false }],
    paradox_balance: balance,
});

function json(data: unknown): Response {
    return new Response(JSON.stringify({ data }), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

afterEach(() => { vi.unstubAllGlobals(); });

describe('secretary collection', () => {
    it('keeps undiscovered entries hidden and pays the present for an opened entry', async () => {
        const posted: unknown[] = [];
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) => {
            if (init?.method === 'POST') {
                posted.push(JSON.parse(String(init.body)));
                return json(collection(true, 105));
            }
            return json(collection(false, 100));
        }));
        const wrapper = mount(SecretaryCollection);
        await flushPromises();
        expect(wrapper.text()).toContain('次のボーナスまであと8個');
        await wrapper.findAll('nav button').find(button => button.text() === '地上アイテム')!.trigger('click');
        const hidden = wrapper.findAll('.secretary-collection-cell.hidden');
        expect(hidden).toHaveLength(1);
        expect(hidden[0]!.text()).toBe('？？？弓');
        expect(hidden[0]!.classes()).toContain('rarity-relic');

        await wrapper.findAll('.secretary-collection-cell').find(cell => cell.text().includes('指輪'))!.trigger('click');
        expect(wrapper.text()).toContain('効果（最大Lv）: 最大Lvの効果');
        await wrapper.findAll('button').find(button => button.text() === 'プレゼント 5Pd')!.trigger('click');
        await flushPromises();
        expect(posted).toEqual([{ kind: 'item', key: 'ring' }]);
        expect(wrapper.text()).toContain('5Pdを受け取りました。');
        expect(wrapper.text()).toContain('✓ 受け取り済み');
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });
});
