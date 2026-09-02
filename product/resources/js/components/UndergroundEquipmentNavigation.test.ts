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
    window.localStorage.removeItem('hakoniwa.underground.vault.bulk-sell-preferences');
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

    it('uses server bulk-sale options, previews filters, and retries a concrete confirmation with the same UUID', async () => {
        const previewPayloads: Array<Record<string, unknown>> = [];
        const bulkPayloads: Array<Record<string, unknown>> = [];
        let bulkAttempts = 0;
        let vaultLoads = 0;
        const vaultPaths: string[] = [];
        const previewItem = item({ name: 'プレビュー対象', rarity_label: 'サーバー希少', sell_price: 180 });
        const bulkSellOptions = {
            rarities: [{ key: 'common', label: 'サーバー通常' }, { key: 'rare', label: 'サーバー希少' }],
            categories: [{ key: 'weapon', label: 'サーバー武器' }, { key: 'armor', label: 'サーバー防具' }, { key: 'accessory', label: 'サーバー装飾' }],
            weapon_styles: [{ key: 'dagger', label: 'サーバー短剣' }, { key: 'rapier', label: 'サーバー細身剣' }],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path.startsWith('/api/v1/me/underground/equipment/vault?page=') && (!init?.method || init.method === 'GET')) {
                vaultLoads += 1;
                vaultPaths.push(path);
                const page = path.endsWith('=2') ? 2 : 1;
                return response({
                    catalog_identity: 'test-catalog', used: 1, capacity: 500, equipped,
                    items: [previewItem], page, per_page: 50, last_page: 2, total: 51,
                    bulk_sell_options: bulkSellOptions,
                });
            }
            if (path === '/api/v1/me/underground/equipment/vault/bulk-sell/preview' && init?.method === 'POST') {
                previewPayloads.push(JSON.parse(String(init.body)) as Record<string, unknown>);
                return response({ catalog_identity: 'preview-catalog', items: [previewItem], count: 1, total_sell_price: 180 });
            }
            if (path === '/api/v1/me/underground/equipment/vault/bulk-sell' && init?.method === 'POST') {
                bulkAttempts += 1;
                bulkPayloads.push(JSON.parse(String(init.body)) as Record<string, unknown>);
                if (bulkAttempts === 1) throw new TypeError('Bulk response lost');
                return response({
                    shard_balance: 280,
                    banked_shard_balance: 200,
                    vault: { used: 0, capacity: 500, equipped },
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(UndergroundEquipmentVault);
        await flushPromises();

        expect(wrapper.get('.underground-bulk-sale-panel').text()).toContain('サーバー希少');
        const optionInputs = wrapper.findAll('fieldset input[type="checkbox"]');
        expect(optionInputs).toHaveLength(7);
        expect(optionInputs.every((input) => (input.element as HTMLInputElement).checked)).toBe(true);

        await wrapper.get('.underground-vault-toolbar button:last-child').trigger('click');
        await flushPromises();
        expect(vaultPaths).toEqual([
            '/api/v1/me/underground/equipment/vault?page=1',
            '/api/v1/me/underground/equipment/vault?page=2',
        ]);

        await wrapper.get('input[type="number"]').setValue('30');
        await wrapper.get('.underground-bulk-preview-button').trigger('click');
        await flushPromises();

        expect(previewPayloads).toEqual([{
            item_level_max: 30,
            rarities: ['common', 'rare'],
            categories: ['weapon', 'armor', 'accessory'],
            weapon_styles: ['dagger', 'rapier'],
        }]);
        expect(wrapper.get('.underground-bulk-sale-preview').text()).toContain('プレビュー対象');
        expect(wrapper.get('.underground-bulk-sale-preview').text()).toContain('180G');
        const stored = JSON.parse(window.localStorage.getItem('hakoniwa.underground.vault.bulk-sell-preferences') ?? '{}') as Record<string, unknown>;
        expect(stored.item_level_max).toBe(30);

        await wrapper.get('.underground-bulk-confirm-trigger').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-bulk-confirm-dialog').text()).toContain('以下の装備をまとめて売却します。');
        expect(wrapper.get('.underground-bulk-confirm-dialog').text()).toContain('対象：1個');
        expect(wrapper.get('.underground-bulk-confirm-dialog').text()).toContain('獲得する輝石のかけら：180G');

        await wrapper.get('.underground-bulk-confirm-dialog .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Bulk response lost');
        expect(wrapper.find('.underground-bulk-confirm-dialog').exists()).toBe(true);
        expect(bulkPayloads).toHaveLength(1);

        await wrapper.get('.underground-bulk-confirm-dialog .button.primary').trigger('click');
        await flushPromises();
        expect(bulkPayloads).toHaveLength(2);
        expect(bulkPayloads[0]).toEqual(bulkPayloads[1]);
        expect(bulkPayloads[0]).toMatchObject({
            catalog_identity: 'preview-catalog',
            items: [{ id: 7, sell_price: 180 }],
        });
        expect(bulkPayloads[0]).toHaveProperty('request_id');
        expect(bulkPayloads[0]).not.toHaveProperty('rarities');
        expect(bulkPayloads[0]).not.toHaveProperty('categories');
        expect(bulkPayloads[0]).not.toHaveProperty('weapon_styles');
        expect(wrapper.find('.underground-bulk-confirm-dialog').exists()).toBe(false);
        expect(wrapper.find('.underground-bulk-sale-preview').exists()).toBe(false);
        expect(vaultLoads).toBe(3);
        expect(vaultPaths.at(-1)).toBe('/api/v1/me/underground/equipment/vault?page=1');
        expect(wrapper.emitted('updated')).toHaveLength(1);
        wrapper.unmount();
    });

    it('restores only valid server options from a stale bulk-sale preference', async () => {
        window.localStorage.setItem('hakoniwa.underground.vault.bulk-sell-preferences', JSON.stringify({
            item_level_max: 'invalid',
            rarities: ['stale', 'rare'],
            categories: ['stale'],
            weapon_styles: ['stale', 'dagger'],
        }));
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            if (String(input) === '/api/v1/me/underground/equipment/vault?page=1') {
                return response({
                    catalog_identity: 'test-catalog', used: 1, capacity: 500, equipped,
                    items: [item()], page: 1, per_page: 50, last_page: 1, total: 1,
                    bulk_sell_options: {
                        rarities: [{ key: 'common', label: '通常' }, { key: 'rare', label: '希少' }],
                        categories: [{ key: 'weapon', label: '武器' }, { key: 'armor', label: '防具' }],
                        weapon_styles: [{ key: 'dagger', label: '短剣' }, { key: 'rapier', label: '細身剣' }],
                    },
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(UndergroundEquipmentVault);
        await flushPromises();

        expect((wrapper.get('input[type="number"]').element as HTMLInputElement).value).toBe('');
        expect((wrapper.get('input[type="checkbox"][value="rare"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('input[type="checkbox"][value="common"]').element as HTMLInputElement).checked).toBe(false);
        expect((wrapper.get('input[type="checkbox"][value="weapon"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('input[type="checkbox"][value="armor"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('input[type="checkbox"][value="dagger"]').element as HTMLInputElement).checked).toBe(true);
        expect((wrapper.get('input[type="checkbox"][value="rapier"]').element as HTMLInputElement).checked).toBe(false);
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
