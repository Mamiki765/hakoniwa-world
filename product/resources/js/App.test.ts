import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import type { MapChunk, Nation, PublicNationDetail } from './types';

const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data, message: status === 401 ? 'Unauthenticated.' : undefined }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

const validationResponse = (errors: Record<string, string[]>) => new Response(JSON.stringify({
    message: '入力内容を確認してください。',
    errors,
}), { status: 422, headers: { 'Content-Type': 'application/json' } });

const emptyChunk: MapChunk = {
    world_id: 1, map_space_id: 2, chunk_x: 0, chunk_y: 0, chunk_size: 16,
    version: 'empty', state: 'empty', cells: [],
};

const publicDetail: PublicNationDetail = {
    id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', total_population: 1000,
    owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500',
    last_updated_turn: 1, comment: '公開コメント', world: { id: 1, name: '共有世界', current_turn: 1 },
    capital: { x: 12, y: 8 },
    monster_final_blow_count: 1,
    monster_kill_stats: [{
        key: 'inora', name: 'いのら', kill_count: 1, first_killed_turn: 12, last_killed_turn: 12,
    }],
    map_space: { id: 2, world_id: 1, key: 'surface', name: '地上', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 } },
};

function publicResponse(path: string): Response | null {
    if (path === '/api/v1/public/worlds') return response([{ id: 1, key: 'shared-world', name: '共有世界', turn: 1 }]);
    if (path.endsWith('/summary')) return response({ id: 1, key: 'shared-world', name: '共有世界', current_turn: 1, nation_count: 1, total_population: 1000 });
    if (path.endsWith('/rankings')) return response([{
        rank: 1, id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', total_population: 1000,
        owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500',
        last_updated_turn: 1, comment: '公開コメント',
    }]);
    if (path.endsWith('/events')) return response([]);
    return null;
}

afterEach(() => vi.unstubAllGlobals());

describe('application lobby and island entry', () => {
    it('continues rendering the public lobby after the normal guest /me 401', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.text()).toContain('PUBLIC WORLD LOBBY');
        expect(wrapper.text()).toContain('現在turn');
        expect(wrapper.text()).toContain('公開島');
        expect(wrapper.text()).toContain('約500億円');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('島主：公開島主');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('公開コメント');
        expect(wrapper.text()).toContain('公開できる出来事はまだありません');
        expect(wrapper.text()).not.toContain('初期データを取得できません');
    });

    it('registers the island profile explicitly and shows field-level validation', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'OAuth名', providers: [] });
            if (path === '/api/v1/me/nation') return response(null);
            if (path === '/api/v1/nations' && init?.method === 'POST') return validationResponse({
                owner_name: ['島主名は必須です。'],
                comment: ['一言コメントに改行は使用できません。'],
            });
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const inputs = wrapper.findAll('.nation-form input');
        await inputs[0]!.setValue('登録島');
        await inputs[1]!.setValue('登録島主');
        await wrapper.find('.nation-form textarea').setValue('登録コメント');
        await wrapper.find('.nation-form').trigger('submit');
        await flushPromises();

        const request = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/nations');
        expect(JSON.parse(String(request?.[1]?.body))).toEqual({
            world_id: 1, name: '登録島', owner_name: '登録島主', comment: '登録コメント',
        });
        expect(wrapper.text()).toContain('島主名は必須です。');
        expect(wrapper.text()).toContain('一言コメントに改行は使用できません。');
    });

    it('opens a guest preview through public-only endpoints', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/nations/7') return response(publicDetail);
            if (path.includes('/api/v1/public/nations/7/map-spaces/2/chunks/')) return response(emptyChunk);
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.ranking-card tbody button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('PUBLIC ISLAND PREVIEW');
        expect(wrapper.text()).toContain('公開情報だけを表示しています');
        expect(wrapper.text()).toContain('島主：公開島主');
        expect(wrapper.text()).toContain('公開コメント');
        expect(wrapper.find('.command-workspace').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toBe(true);
    });

    it('shows exact owner HUD data without refetching resources per selected cell', async () => {
        const nation: Nation = {
            id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '自島コメント', money: 62728, money_display: '62,728億円',
            money_capacity: 9999, money_remaining_capacity: 0, money_is_at_capacity: true,
            total_food_tons: 10000, food_total_tons: 10000,
            food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
            food_resources: [
                { key: 'wheat', name: '小麦', balance: 10000, unit: 'ton', unit_label: 'トン' },
                { key: 'fish', name: '魚', balance: 0, unit: 'ton', unit_label: 'トン' },
                { key: 'monster_meat', name: '怪獣肉', balance: 0, unit: 'ton', unit_label: 'トン' },
            ],
            state: 'active', current_turn: 1, total_population: 1000, territory_cell_count: 19,
            owned_land_cells: 17,
            capital: { x: 12, y: 8 },
            resources: [
                { key: 'wheat', name: '小麦', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 1, storable: true, tradable: true, amount: 10000, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'fish', name: '魚', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 1, storable: true, tradable: true, amount: 0, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'monster_meat', name: '怪獣肉', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 2, storable: true, tradable: true, amount: 0, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'industrial_goods', name: '工業品', category: 'industry', unit: 'unit', unit_label: 'ユニット', nutrition_per_unit: null, storable: true, tradable: true, amount: 1200, capacity: 9999000, remaining_capacity: 9997800, is_at_capacity: false },
                { key: 'minerals', name: '鉱物', category: 'material', unit: 'ton', unit_label: 'トン', nutrition_per_unit: null, storable: true, tradable: true, amount: 0, capacity: 9999000, remaining_capacity: 9999000, is_at_capacity: false },
            ],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
            if (path === '/api/v1/me/nation') return response(nation);
            if (path === '/api/v1/public/nations/7') return response(publicDetail);
            if (path.includes('/api/v1/public/nations/7/map-spaces/2/chunks/')) return response(emptyChunk);
            if (path === '/api/v1/nations/3/profile' && init?.method === 'PATCH') return response({
                ...nation, owner_name: '更新島主', comment: '<b>更新コメント</b>',
            });
            if (path === '/api/v1/worlds/1/map-spaces') return response([{
                id: 2, world_id: 1, key: 'surface', name: '地上', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 },
            }]);
            if (path.includes('/api/v1/map-spaces/2/chunks/')) return response(emptyChunk);
            if (path === '/api/v1/nations/3/events?page=1') return response({
                groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
                turns_per_page: 24, has_newer_page: false, has_older_page: false,
            });
            if (path.includes('command-definitions')) return response({
                commands: [],
                quantity_contract: { type: 'integer', minimum: 1, maximum: 99, default: 1, quick_presets: [1, 5, 10, 25, 50, 99] },
            });
            if (path.includes('command-queue')) return response({
                version: 1, limit: 20, explicit_count: 0, items: [],
                plan: Array.from({ length: 20 }, (_, index) => ({
                    position: index + 1, kind: 'automatic_finance', editable: false, command_name: '資金繰り', quantity: null,
                })),
            });
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.session-actions button').trigger('click');
        await flushPromises();
        expect(wrapper.find('.nation-hud').text()).toContain('62,728億円');
        expect(wrapper.find('.nation-hud').text()).toContain('N1 自島');
        expect(wrapper.find('.nation-hud').text()).toContain('島主：自島主');
        expect(wrapper.find('.nation-hud').text()).toContain('自島コメント');
        expect(wrapper.find('.nation-hud').text()).toContain('保有陸地：17セル');
        expect(wrapper.find('.nation-hud').text()).toContain('食料');
        expect(wrapper.find('.nation-hud').text()).toContain('10,000トン');
        expect(wrapper.find('.hud-money .hud-current-value').text()).toBe('62,728億円');
        expect(wrapper.find('.hud-money .hud-capacity-limit').text()).toBe('上限 9,999億円');
        expect(wrapper.find('.hud-food .hud-capacity-limit').text()).toBe('上限 999,900トン');
        expect(wrapper.find('.hud-money').text()).not.toContain('/');
        const industrialHud = wrapper.findAll('.hud-primary > div').find((item) => item.text().includes('工業品'))!;
        const mineralHud = wrapper.findAll('.hud-primary > div').find((item) => item.text().includes('鉱物'))!;
        expect(industrialHud.find('.hud-current-value').text()).toBe('1,200ユニット');
        expect(industrialHud.find('.hud-capacity-limit').text()).toBe('上限 9,999,000ユニット');
        expect(mineralHud.find('.hud-capacity-limit').text()).toBe('上限 9,999,000トン');
        expect(wrapper.find('.hud-food-detail').exists()).toBe(false);
        await wrapper.find('.food-detail-toggle').trigger('click');
        expect(wrapper.find('.hud-food-detail').text()).toContain('小麦');
        expect(wrapper.find('.hud-food-detail').text()).toContain('魚');
        expect(wrapper.find('.hud-food-detail').text()).toContain('怪獣肉');
        expect(wrapper.find('.hud-food-detail').text()).toContain('0トン');
        await wrapper.find('.hud-food-detail > button').trigger('click');
        expect(wrapper.find('.hud-food-detail').exists()).toBe(false);
        expect(wrapper.find('.island-grid').exists()).toBe(true);
        expect(wrapper.find('.island-events-panel').text()).toContain('島の出来事');
        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(1);

        const lobbyButton = wrapper.findAll('.site-header nav button').find((button) => button.text() === '公開ロビー')!;
        await lobbyButton.trigger('click');
        await wrapper.find('.ranking-card tbody button').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('PUBLIC ISLAND PREVIEW');

        const profileButton = wrapper.findAll('.site-header nav button').find((button) => button.text() === 'プロフィール編集')!;
        await profileButton.trigger('click');
        await wrapper.find('.profile-form input').setValue('更新島主');
        await wrapper.find('.profile-form textarea').setValue('<b>更新コメント</b>');
        await wrapper.find('.profile-form').trigger('submit');
        await flushPromises();
        expect(wrapper.find('.nation-hud').text()).toContain('島主：更新島主');
        expect(wrapper.find('.nation-hud').text()).toContain('<b>更新コメント</b>');
        expect(wrapper.find('.nation-hud b').exists()).toBe(false);
        const patchRequest = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/nations/3/profile');
        expect(JSON.parse(String(patchRequest?.[1]?.body))).toEqual({ owner_name: '更新島主', comment: '<b>更新コメント</b>' });
        const patchIndex = fetchMock.mock.calls.findIndex(([path]) => String(path) === '/api/v1/nations/3/profile');
        expect(fetchMock.mock.calls.slice(patchIndex + 1).some(([path]) => String(path).includes('/api/v1/map-spaces/2/chunks/'))).toBe(true);
    });
});
