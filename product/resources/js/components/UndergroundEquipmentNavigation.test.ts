import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { EquipmentItem } from './EquipmentItemCard.vue';
import UndergroundEquipmentShop from './UndergroundEquipmentShop.vue';
import UndergroundEquipmentVault from './UndergroundEquipmentVault.vue';

const response = (data: unknown, status = 200): Response => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

const item = (overrides: Partial<EquipmentItem> = {}): EquipmentItem => ({
    id: 7,
    key: 'generated:shallow_caves:accessory',
    name: '浅層の護符',
    category: 'accessory',
    weapon_style: null,
    rank: 0,
    item_level: 18,
    rarity: 'rare',
    rarity_label: 'アーティファクト',
    buy_price: null,
    sell_price: 180,
    owned: true,
    equipped_slot: null,
    weapon_power: 0,
    physical_defense: 0,
    magical_defense: 0,
    max_hp: 0,
    stats: { vitality: 0, might: 0, finesse: 4, spirit: 0, agility: 0 },
    affixes: [{ key: 'finesse', label: '技巧アップ', value: 4 }],
    instance_kind: 'generated',
    instance_identity: 'generated-instance-identity',
    ...overrides,
});

const equipped = {
    weapon: null,
    armor: null,
    accessory_1: null,
    accessory_2: null,
    accessory_3: null,
};

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

describe('Underground equipment navigation', () => {
    it('shows five slots and sends the selected accessory target slot', async () => {
        const equipPayloads: Array<Record<string, unknown>> = [];
        const accessory = item();
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground/equipment/equipped' && init?.method === 'PUT') {
                equipPayloads.push(JSON.parse(String(init.body)) as Record<string, unknown>);
                return response({
                    shard_balance: 100,
                    banked_shard_balance: 200,
                    vault: { used: 2, capacity: 500, equipped: { ...equipped, accessory_3: { ...accessory, equipped_slot: 'accessory_3' } } },
                });
            }
            if (path.startsWith('/api/v1/me/underground/equipment/vault')) {
                return response({
                    catalog_identity: 'test-catalog', used: 2, capacity: 500, equipped,
                    items: [accessory], page: 1, per_page: 50, last_page: 1, total: 1,
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(UndergroundEquipmentVault);
        await flushPromises();

        expect(wrapper.findAll('.underground-equipped-slot')).toHaveLength(5);
        expect(wrapper.text()).toContain('アクセサリー1');
        expect(wrapper.text()).toContain('アクセサリー3');
        expect(wrapper.get('.underground-equipment-card').text()).toContain('アーティファクト');
        expect(wrapper.get('.underground-equipment-card').text()).toContain('技巧アップ +4');
        expect(wrapper.get('.underground-equipment-card').text()).toContain('生成装備');

        await wrapper.get('select[aria-label="アクセサリー装備先"]').setValue('accessory_3');
        await wrapper.get('.underground-equipment-card .button').trigger('click');
        await flushPromises();

        expect(equipPayloads).toHaveLength(1);
        expect(equipPayloads[0]).toMatchObject({ item_id: 7, target_slot: 'accessory_3' });
        expect(typeof equipPayloads[0]?.request_id).toBe('string');
        wrapper.unmount();
    });

    it('renders a locked shop item with its unlock requirement and disables purchase', async () => {
        const lockedWeapon = item({
            id: undefined,
            key: 'black_crystal_dagger',
            name: '黒晶の短剣',
            category: 'weapon',
            weapon_style: 'dagger',
            item_level: 40,
            rarity: 'common',
            rarity_label: 'ノービス',
            buy_price: 3000,
            sell_price: 1500,
            owned: false,
            instance_kind: 'fixed',
            instance_identity: null,
            locked: true,
            unlock_requirement: '試練1を初回clear',
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, _init?: RequestInit) => {
            if (String(input) === '/api/v1/me/underground/equipment/shop') {
                return response({
                    catalog_identity: 'test-catalog', currency_label: '輝石の欠片 G', shard_balance: 5000,
                    banked_shard_balance: 0, bank_auto_withdraw: false,
                    items: [lockedWeapon], owned_items: [],
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(UndergroundEquipmentShop);
        await flushPromises();

        const card = wrapper.get('.underground-equipment-card');
        expect(card.attributes('data-locked')).toBe('true');
        expect(card.text()).toContain('ノービス');
        expect(card.text()).toContain('試練1を初回clear');
        expect(card.get('button').text()).toBe('ロック中');
        expect((card.get('button').element as HTMLButtonElement).disabled).toBe(true);
        expect(fetchMock.mock.calls.filter(([, init]) => init?.method === 'POST')).toHaveLength(0);
        wrapper.unmount();
    });
});
