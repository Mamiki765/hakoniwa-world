import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UndergroundMarket from './UndergroundMarket.vue';

const item = (id: number, level: number) => ({
    id, key: `generated-${id}`, name: `黒竜の輝石杖${id}`, category: 'weapon', weapon_style: 'crystal_staff', rank: 0,
    item_level: level, quality_percent: 92, rarity: 'rare', rarity_label: 'アーティファクト', sell_price: 1200,
    weapon_power: 10, physical_defense: 0, magical_defense: 0, max_hp: 0, stats: {}, instance_kind: 'generated',
});
const market = {
    purchase_il_cap: 140, carried_shards: 52000, fee: { numerator: 1, denominator: 10 },
    listings: [
        { id: 812, item: item(1, 120), seller: { secretary_name: 'ルリ', island_name: '瑠璃島' }, price: 12000, expires_at: '2099-01-01T00:00:00Z', is_own: false, purchasable: true, blocked_reasons: [] },
        { id: 813, item: item(2, 160), seller: { secretary_name: 'アキ', island_name: null }, price: 60000, expires_at: '2099-01-01T00:00:00Z', is_own: false, purchasable: false, blocked_reasons: ['il_cap_exceeded', 'insufficient_carried_shards'] },
    ],
    page: 1, has_more: false,
};

function json(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(status < 400 ? { data } : data), { status, headers: { 'Content-Type': 'application/json' } });
}

afterEach(() => { vi.unstubAllGlobals(); });

describe('underground market stalls', () => {
    it('shows the IL cap and keeps blocked listings unbuyable with their reasons', async () => {
        const fetch = vi.fn(async (_url: string) => json(market));
        vi.stubGlobal('fetch', fetch);
        const wrapper = mount(UndergroundMarket);
        await flushPromises();
        expect(String(fetch.mock.calls[0]?.[0])).toContain('purchasable_only=1');
        expect(wrapper.text()).toContain('IL 140');
        expect(wrapper.text()).toContain('IL上限を超えています');
        expect(wrapper.text()).toContain('手持ちのGが足りません');
        const buttons = wrapper.findAll('.underground-equipment-card-grid button').filter(button => ['買う', '買えません'].includes(button.text()));
        expect(buttons.map(button => [button.text(), button.attributes('disabled') !== undefined])).toEqual([['買う', false], ['買えません', true]]);
    });

    it('confirms a purchase with the expected price and refreshes when someone else bought it first', async () => {
        const calls: Array<{ url: string; body: unknown }> = [];
        vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
            calls.push({ url, body: init?.body ? JSON.parse(String(init.body)) : null });
            if (url === '/api/v1/me/underground/requests') return json({ request_id: 'issued-1', token: 't', expires_at: 0, format: 1 });
            if (url.includes('/purchase')) return json({ message: '売り切れです。', code: 'listing_unavailable' }, 409);
            return json(market);
        }));
        const wrapper = mount(UndergroundMarket);
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === '買う')!.trigger('click');
        expect(wrapper.text()).toContain('買った後の手持ち');
        expect(wrapper.text()).toContain('40,000G');
        await wrapper.get('[role="dialog"] .button.primary').trigger('click');
        await flushPromises();
        const purchase = calls.find(call => call.url.includes('/purchase'));
        expect(purchase?.body).toMatchObject({ expected_price: 12000, request_id: 'issued-1' });
        expect(wrapper.text()).toContain('ほかの人が先に買ったか');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(calls.filter(call => call.url.startsWith('/api/v1/me/underground/market?')).length).toBe(2);
    });
});
