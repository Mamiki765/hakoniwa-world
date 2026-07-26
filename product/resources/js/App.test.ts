import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import type { MapChunk, Nation, PublicNationDetail } from './types';

const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data, message: status === 401 ? 'Unauthenticated.' : undefined }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

const emptyChunk: MapChunk = {
    world_id: 1, map_space_id: 2, chunk_x: 0, chunk_y: 0, chunk_size: 16,
    version: 'empty', state: 'empty', cells: [],
};

function publicResponse(path: string): Response | null {
    if (path === '/api/v1/public/worlds') return response([{ id: 1, key: 'shared-world', name: '共有世界', turn: 0 }]);
    if (path.endsWith('/summary')) return response({ id: 1, key: 'shared-world', name: '共有世界', current_turn: 0, nation_count: 1, total_population: 1000 });
    if (path.endsWith('/rankings')) return response([{
        rank: 1, id: 7, world_id: 1, name: '公開島', state: 'active', total_population: 1000,
        territory_cell_count: 19, money_display: '約500億円', money_bucket: '500',
        last_updated_turn: 0, comment: null,
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
        expect(wrapper.text()).toContain('公開できる出来事はまだありません');
        expect(wrapper.text()).not.toContain('初期データを取得できません');
    });

    it('opens a guest preview through public-only endpoints', async () => {
        const detail: PublicNationDetail = {
            id: 7, world_id: 1, name: '公開島', state: 'active', total_population: 1000,
            territory_cell_count: 19, money_display: '約500億円', money_bucket: '500',
            last_updated_turn: 0, comment: null, world: { id: 1, name: '共有世界', current_turn: 0 },
            capital: { x: 12, y: 8 }, map_space: { id: 2, world_id: 1, key: 'surface', name: '地上' },
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/nations/7') return response(detail);
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
        expect(wrapper.find('.command-workspace').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toBe(true);
    });

    it('shows exact owner HUD data without refetching resources per selected cell', async () => {
        const nation: Nation = {
            id: 3, world_id: 1, name: '自島', money: 62728, money_display: '62,728億円',
            state: 'active', current_turn: 0, total_population: 1000, territory_cell_count: 19,
            capital: { x: 12, y: 8 },
            resources: [
                { key: 'wheat', name: '小麦', category: 'food', unit: 'unit', nutrition_per_unit: 1, storable: true, tradable: true, amount: 100 },
                { key: 'fish', name: '魚', category: 'food', unit: 'unit', nutrition_per_unit: 1, storable: true, tradable: true, amount: 5 },
            ],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
            if (path === '/api/v1/me/nation') return response(nation);
            if (path === '/api/v1/worlds/1/map-spaces') return response([{ id: 2, world_id: 1, key: 'surface', name: '地上' }]);
            if (path.includes('/api/v1/map-spaces/2/chunks/')) return response(emptyChunk);
            if (path.includes('command-definitions')) return response([]);
            if (path.includes('command-queue')) return response({
                version: 1, limit: 20, explicit_count: 0, items: [],
                plan: Array.from({ length: 20 }, (_, index) => ({
                    position: index + 1, kind: 'automatic_finance', editable: false, command_name: '資金繰り',
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
        expect(wrapper.find('.nation-hud').text()).toContain('小麦');
        expect(wrapper.find('.nation-hud').text()).toContain('100');
        expect(wrapper.find('.island-grid').exists()).toBe(true);
        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(1);
    });
});
