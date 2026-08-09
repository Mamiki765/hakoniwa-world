import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import type { MapChunk, Nation, PublicNationDetail } from './types';

const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data, message: status === 401 ? 'Unauthenticated.' : undefined }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

const envelopeResponse = (data: unknown, meta: Record<string, unknown>) => new Response(JSON.stringify({ data, meta }), {
    status: 200,
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
    owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
    farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
    registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
    last_updated_turn: 1, comment: '公開コメント', world: { id: 1, name: '箱庭諸島２S＋', current_turn: 1 },
    capital: { x: 12, y: 8 },
    monster_final_blow_count: 1,
    monster_kill_stats: [{
        key: 'inora', name: 'いのら', kill_count: 1, first_killed_turn: 12, last_killed_turn: 12,
    }],
    map_space: { id: 2, world_id: 1, key: 'surface', name: '地上', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 } },
};

function publicResponse(path: string): Response | null {
    if (path === '/api/v1/public/announcements/latest') return response([
        { id: 2, title: 'ver 1.0.2のお知らせ', body: 'queue fix', created_at: '2026-08-02T03:00:00+09:00', updated_at: '2026-08-02T03:00:00+09:00' },
        { id: 1, title: 'ver 1.0.1のお知らせ', body: 'resource fix', created_at: '2026-08-01T03:00:00+09:00', updated_at: '2026-08-01T03:00:00+09:00' },
    ]);
    if (path === '/api/v1/public/worlds') return response([{ id: 1, key: 'shared-world', name: '箱庭諸島２S＋', turn: 1 }]);
    if (path.endsWith('/summary')) return response({
        id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 1, nation_count: 1, total_population: 1000, contact_url: null,
        turn_status: 'normal', last_successful_turn_at: '2026-08-09T13:00:00Z',
        next_scheduled_turn_at: '2099-08-09T15:00:00Z', turn_schedule_timezone: 'Asia/Tokyo',
    });
    if (path.endsWith('/rankings')) return response([{
        rank: 1, id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', total_population: 1000,
        owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
        farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
        registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
        last_updated_turn: 1, comment: '公開コメント',
    }]);
    if (path.endsWith('/events')) return response({
        groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
        turns_per_page: 24, has_newer_page: false, has_older_page: false,
    });
    return null;
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

describe('application lobby and island entry', () => {
    it('continues rendering the public lobby after the normal guest /me 401', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.text()).toContain('HAKONIWA ISLANDS');
        expect(wrapper.text()).toContain('ターン更新（2時間ごと）');
        expect(wrapper.text()).toContain('公開島');
        expect(wrapper.text()).toContain('約500億円');
        expect(wrapper.find('.ranking-card thead').text()).toBe('島名島主生存ターン面積農場規模工場規模採掘場規模人口資金食料');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('17セル');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('10,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('30,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('5,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('10,000トン');
        expect(wrapper.find('.ranking-card').text()).not.toContain('活動状態');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('公開島主');
        expect(wrapper.find('.ranking-card tbody button').text()).toContain('公開島 (100)');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('公開コメント');
        expect(wrapper.text()).toContain('公開できる出来事はまだありません');
        expect(wrapper.text()).not.toContain('初期データを取得できません');
        expect(wrapper.find('.app-version').text()).toBe('ver 1.2.0');
        expect(wrapper.find('.announcement-window').text()).toContain('ver 1.0.2のお知らせ');
        expect(wrapper.findAll('.announcement-window li')).toHaveLength(2);
        expect(wrapper.find('.turn-status-card').text()).toContain('最終ターン更新');
        expect(wrapper.find('.turn-status-card').text()).toContain('次回更新まで');
        expect(wrapper.find('.turn-countdown').exists()).toBe(true);
    });

    it('marks dormant islands beside the name without an activity-status column', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/rankings')) return response([{
                rank: 1, id: 7, world_id: 1, nation_number: 1, name: '休止島', state: 'dormant_frozen',
                total_population: 1000, owner_name: '休止島主', territory_cell_count: 19, owned_land_cells: 17,
                money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
                registered_turn: 1, survival_turns: 10, finance_only_turns: 7, activity_status: 'finance_only',
                last_updated_turn: 11, comment: '',
            }]);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        const name = wrapper.find('.ranking-card tbody button');
        expect(name.text()).toBe('休止島（休止中）');
        expect(name.classes()).toContain('is-dormant');
        expect(wrapper.find('.ranking-card').text()).not.toContain('活動状態');
    });

    it('suppresses the normal countdown for a failed turn', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/summary')) return response({
                id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 7, nation_count: 1, total_population: 1000, contact_url: null,
                turn_status: 'failed', last_successful_turn_at: '2026-08-09T13:00:00Z',
                next_scheduled_turn_at: '2026-08-09T15:00:00Z', turn_schedule_timezone: 'Asia/Tokyo',
            });
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.find('.turn-status-card').text()).toContain('ターン更新が停止しています。');
        expect(wrapper.find('.turn-countdown').exists()).toBe(false);
    });

    it('shows delayed status without a countdown after the grace boundary', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/summary')) return response({
                id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 7, nation_count: 1,
                total_population: 1000, contact_url: null, turn_status: 'delayed',
                last_successful_turn_at: '2026-08-09T13:00:00Z', next_scheduled_turn_at: '2026-08-09T15:00:00Z',
                turn_schedule_timezone: 'Asia/Tokyo',
            });
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.find('.turn-status-card').text()).toContain('ターン更新が遅延しています。');
        expect(wrapper.find('.turn-countdown').exists()).toBe(false);
    });

    it('shows paged plain-text announcements and never renders article HTML', async () => {
        const article = {
            id: 5, title: '運営からのお知らせ', body: '<b>タグではありません</b>\n二行目',
            created_at: '2026-08-09T10:30:00+09:00', updated_at: '2026-08-09T10:30:00+09:00',
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/announcements?page=1') return response([article]);
            if (path === '/api/v1/public/announcements/5') return response(article);
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.announcement-window .section-heading button').trigger('click');
        await flushPromises();
        expect(wrapper.find('.announcement-page').text()).toContain('運営からのお知らせ');
        expect(wrapper.find('.announcement-pager').text()).toContain('1ページ');
        await wrapper.find('.announcement-list.full button').trigger('click');
        await flushPromises();
        expect(wrapper.find('.announcement-body').text()).toContain('<b>タグではありません</b>\n二行目');
        expect(wrapper.find('.announcement-body b').exists()).toBe(false);
    });

    it('uses paginator metadata even when an announcement page is not full', async () => {
        const article = {
            id: 5, title: '1ページ目', body: '本文',
            created_at: '2026-08-09T10:30:00+09:00', updated_at: '2026-08-09T10:30:00+09:00',
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/public/announcements?page=1') {
                return envelopeResponse([article], { current_page: 1, last_page: 2 });
            }
            if (path === '/api/v1/public/announcements?page=2') {
                return envelopeResponse([{ ...article, id: 6, title: '2ページ目' }], { current_page: 2, last_page: 2 });
            }
            return publicResponse(path) ?? response(null, 401);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.announcement-window .section-heading button').trigger('click');
        await flushPromises();
        const pager = wrapper.findAll('.announcement-pager button');
        expect(pager[1]!.attributes('disabled')).toBeUndefined();
        await pager[1]!.trigger('click');
        await flushPromises();
        expect(wrapper.find('.announcement-page').text()).toContain('2ページ目');
        expect(wrapper.findAll('.announcement-pager button')[1]!.attributes('disabled')).toBeDefined();
    });

    it.each([
        { total: 10, page: 1, lastPage: 1 },
        { total: 20, page: 2, lastPage: 2 },
    ])('disables Next on the full final page for exactly $total announcements', async ({ total, page, lastPage }) => {
        const articles = Array.from({ length: 10 }, (_, index) => ({
            id: index + 1, title: `記事${index + 1}`, body: '本文',
            created_at: '2026-08-09T10:30:00+09:00', updated_at: '2026-08-09T10:30:00+09:00',
        }));
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/public/announcements?page=1') {
                return envelopeResponse(articles, { current_page: 1, last_page: lastPage, total });
            }
            if (path === `/api/v1/public/announcements?page=${page}`) {
                return envelopeResponse(articles, { current_page: page, last_page: lastPage, total });
            }
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        if (page === 1) {
            await wrapper.find('.announcement-window .section-heading button').trigger('click');
        } else {
            await wrapper.find('.announcement-window .section-heading button').trigger('click');
            await flushPromises();
            await wrapper.findAll('.announcement-pager button')[1]!.trigger('click');
        }
        await flushPromises();
        expect(wrapper.findAll('.announcement-list.full li')).toHaveLength(10);
        expect(wrapper.find('.announcement-pager').text()).toContain(`${page}ページ`);
        expect(wrapper.findAll('.announcement-pager button')[1]!.attributes('disabled')).toBeDefined();
    });

    it('retries the summary then refreshes turn dependent public views when the turn advances', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-09T12:00:00Z'));
        let summaryCalls = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/summary')) {
                summaryCalls++;
                return response(summaryCalls < 3 ? {
                    id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 1, nation_count: 1,
                    total_population: 1000, contact_url: null, turn_status: 'normal',
                    last_successful_turn_at: '2026-08-09T10:00:00Z', next_scheduled_turn_at: '2026-08-09T12:00:01Z',
                    turn_schedule_timezone: 'Asia/Tokyo',
                } : {
                    id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 2, nation_count: 1,
                    total_population: 1000, contact_url: null, turn_status: 'normal',
                    last_successful_turn_at: '2026-08-09T12:00:02Z', next_scheduled_turn_at: '2026-08-09T14:00:00Z',
                    turn_schedule_timezone: 'Asia/Tokyo',
                });
            }
            return publicResponse(path) ?? response(null, 401);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();
        expect(summaryCalls).toBe(2);
        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();
        expect(summaryCalls).toBe(3);
        expect(wrapper.find('.world-stats dd').text()).toBe('2');
        expect(fetchMock.mock.calls.filter(([path]) => String(path).includes('/announcements/latest'))).toHaveLength(1);
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/rankings'))).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/events'))).toHaveLength(2);
        wrapper.unmount();
    });

    it('refreshes the owner Nation and loaded private map when the turn advances', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-09T12:00:00Z'));
        const ownerNation = {
            id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '',
            money: 500, money_display: '500億円', money_capacity: 9999, money_remaining_capacity: 9499,
            money_is_at_capacity: false, total_food_tons: 10000, food_total_tons: 10000,
            food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
            farm_capacity_people: 10000, factory_capacity_people: 20000, mine_capacity_people: 30000,
            food_resources: [], resources: [], state: 'active', current_turn: 1, registered_turn: 1,
            survival_turns: 0, finance_only_turns: 0, activity_status: 'active', total_population: 1000,
            territory_cell_count: 19, owned_land_cells: 17, capital: { x: 12, y: 8 },
        } as Nation;
        let summaryCalls = 0;
        let nationCalls = 0;
        let privateChunkCalls = 0;
        let ownerEventCalls = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/summary')) {
                summaryCalls++;
                return response({
                    id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: summaryCalls === 1 ? 1 : 2,
                    nation_count: 1, total_population: summaryCalls === 1 ? 1000 : 1500, contact_url: null,
                    turn_status: 'normal', last_successful_turn_at: summaryCalls === 1 ? '2026-08-09T10:00:00Z' : '2026-08-09T12:00:02Z',
                    next_scheduled_turn_at: summaryCalls === 1 ? '2026-08-09T12:00:01Z' : '2026-08-09T14:00:00Z',
                    turn_schedule_timezone: 'Asia/Tokyo',
                });
            }
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
            if (path === '/api/v1/me/nation') {
                nationCalls++;
                return response({
                    ...ownerNation,
                    current_turn: nationCalls === 1 ? 1 : 2,
                    total_population: nationCalls === 1 ? 1000 : 1500,
                });
            }
            if (path === '/api/v1/worlds/1/map-spaces') return response([publicDetail.map_space]);
            if (path.includes('/api/v1/map-spaces/2/chunks/')) {
                privateChunkCalls++;
                return response(emptyChunk);
            }
            if (path === '/api/v1/nations/3/events?page=1') {
                ownerEventCalls++;
                return response({
                    groups: [], page: 1, anchor_turn: ownerEventCalls,
                    turn_range: { start: 1, end: ownerEventCalls },
                    turns_per_page: 24, has_newer_page: false, has_older_page: false,
                });
            }
            if (path.includes('command-definitions')) return response({
                commands: [],
                quantity_contract: { type: 'integer', minimum: 1, maximum: 99, default: 1, quick_presets: [1, 5, 10, 25, 50, 99] },
            });
            if (path.includes('command-queue')) return response({
                version: 1, limit: 20, explicit_count: 0, items: [], plan: [],
            });

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.find('.session-actions button').trigger('click');
        await flushPromises();
        const initialChunkCalls = privateChunkCalls;

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();

        expect(summaryCalls).toBe(2);
        expect(nationCalls).toBe(2);
        expect(privateChunkCalls).toBeGreaterThan(initialChunkCalls);
        expect(ownerEventCalls).toBe(2);
        expect(wrapper.find('.hud-primary').text()).toContain('人口1,500人');
        wrapper.unmount();
    });

    it('stops deadline retries when the refreshed summary reports a failed turn', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-09T12:00:00Z'));
        let summaryCalls = 0;
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/summary')) {
                summaryCalls++;
                return response({
                    id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 1, nation_count: 1,
                    total_population: 1000, contact_url: null,
                    turn_status: summaryCalls === 1 ? 'normal' : 'failed',
                    last_successful_turn_at: '2026-08-09T10:00:00Z',
                    next_scheduled_turn_at: '2026-08-09T12:00:01Z', turn_schedule_timezone: 'Asia/Tokyo',
                });
            }
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();
        expect(summaryCalls).toBe(2);
        expect(wrapper.find('.turn-status-card').text()).toContain('ターン更新が停止しています。');
        expect(wrapper.find('.turn-countdown').exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(30_000);
        expect(summaryCalls).toBe(2);
        wrapper.unmount();
    });

    it('allows only a capability-bearing user to create edit and delete announcements', async () => {
        let article = {
            id: 8, title: '新規記事', body: '本文',
            created_at: '2026-08-09T10:30:00+09:00', updated_at: '2026-08-09T10:30:00+09:00',
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/public/announcements/latest') return response([article]);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Admin', can_manage_announcements: true, providers: [] });
            if (path === '/api/v1/me/nation') return response(null);
            if (path === '/api/v1/public/announcements?page=1') return response([article]);
            if (path === '/api/v1/admin/announcements' && init?.method === 'POST') {
                article = { ...article, ...JSON.parse(String(init.body)) as { title: string; body: string } };
                return response(article, 201);
            }
            if (path === '/api/v1/admin/announcements/8' && init?.method === 'PATCH') {
                article = { ...article, ...JSON.parse(String(init.body)) as { title: string; body: string } };
                return response(article);
            }
            if (path === '/api/v1/admin/announcements/8' && init?.method === 'DELETE') return response(null);
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.announcement-window .section-heading button').trigger('click');
        await flushPromises();
        const create = wrapper.findAll('.announcement-actions button').find((button) => button.text() === '新規作成')!;
        await create.trigger('click');
        await wrapper.find('.announcement-form input').setValue('作成した記事');
        await wrapper.find('.announcement-form textarea').setValue('一行目\n二行目');
        await wrapper.find('.announcement-form').trigger('submit');
        await flushPromises();
        const post = fetchMock.mock.calls.find(([path, init]) => String(path) === '/api/v1/admin/announcements' && init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ title: '作成した記事', body: '一行目\n二行目' });

        const edit = wrapper.findAll('.announcement-actions button').find((button) => button.text() === '編集')!;
        await edit.trigger('click');
        await wrapper.find('.announcement-form input').setValue('編集した記事');
        await wrapper.find('.announcement-form').trigger('submit');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([path, init]) => String(path).endsWith('/admin/announcements/8') && init?.method === 'PATCH')).toBe(true);

        const remove = wrapper.findAll('.announcement-actions button').find((button) => button.text() === '削除')!;
        await remove.trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([path, init]) => String(path).endsWith('/admin/announcements/8') && init?.method === 'DELETE')).toBe(true);
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
        expect(wrapper.text()).toContain('人口・面積・推定資金・食料合計・施設規模');
        expect(wrapper.find('.preview-heading').text()).toContain('人口1,000人');
        expect(wrapper.find('.preview-heading').text()).toContain('面積17セル');
        expect(wrapper.find('.preview-heading').text()).toContain('推定資金約500億円');
        expect(wrapper.find('.preview-heading').text()).toContain('食料10,000トン');
        expect(wrapper.find('.preview-heading').text()).toContain('農場規模10,000人');
        expect(wrapper.find('.preview-heading').text()).toContain('工場規模30,000人');
        expect(wrapper.find('.preview-heading').text()).toContain('採掘場規模5,000人');
        expect(wrapper.text()).toContain('島主：公開島主');
        expect(wrapper.text()).toContain('公開コメント');
        expect(wrapper.find('.command-workspace').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toBe(true);
    });

    it('shows exact owner HUD data without refetching resources per selected cell', async () => {
        vi.useFakeTimers();
        const nation: Nation = {
            id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '自島コメント', money: 62728, money_display: '62,728億円',
            money_capacity: 9999, money_remaining_capacity: 0, money_is_at_capacity: true,
            total_food_tons: 10000, food_total_tons: 10000,
            food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
            farm_capacity_people: 10000, factory_capacity_people: 20000, mine_capacity_people: 30000,
            food_resources: [
                { key: 'wheat', name: '小麦', balance: 10000, unit: 'ton', unit_label: 'トン' },
                { key: 'fish', name: '魚', balance: 0, unit: 'ton', unit_label: 'トン' },
                { key: 'monster_meat', name: '怪獣肉', balance: 0, unit: 'ton', unit_label: 'トン' },
            ],
            state: 'active', current_turn: 1, registered_turn: 1, survival_turns: 0,
            finance_only_turns: 0, activity_status: 'active', total_population: 1000, territory_cell_count: 19,
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
        expect(wrapper.find('.hud-primary').text()).toContain('人口1,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('面積17セル');
        expect(wrapper.find('.hud-primary').text()).toContain('食料10,000トン');
        expect(wrapper.find('.hud-primary').text()).toContain('農場規模10,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('工場規模20,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('採掘場規模30,000人');
        expect(wrapper.findAll('.hud-primary > div')).toHaveLength(7);
        expect(wrapper.find('.hud-money .hud-current-value').text()).toBe('62,728億円');
        expect(wrapper.find('.hud-money').text()).not.toContain('/');
        expect(wrapper.find('.hud-primary').text()).not.toContain('工業品');
        expect(wrapper.find('.hud-primary').text()).not.toContain('上限');
        expect(wrapper.find('.hud-more').text()).toContain('詳細情報');
        expect(wrapper.find('.hud-details').text()).toContain('資金上限9,999億円');
        expect(wrapper.find('.hud-details').text()).toContain('食料上限999,900トン');
        expect(wrapper.find('.hud-details').text()).toContain('小麦10,000トン');
        expect(wrapper.find('.hud-details').text()).toContain('魚0トン');
        expect(wrapper.find('.hud-details').text()).toContain('怪獣肉0トン');
        expect(wrapper.find('.hud-details').text()).toContain('工業品1,200ユニット');
        expect(wrapper.find('.hud-details').text()).toContain('上限 9,999,000ユニット');
        expect(wrapper.find('.hud-details').text()).toContain('鉱物0トン');
        expect(wrapper.find('.hud-details').text()).toContain('上限 9,999,000トン');
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

        const summaryCallCount = () => fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/summary')).length;
        expect(summaryCallCount()).toBe(2);
        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();
        expect(summaryCallCount()).toBe(3);
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();
        expect(summaryCallCount()).toBe(3);
    });
});
