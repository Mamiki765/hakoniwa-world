import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import HexMap from './components/HexMap.vue';
import type { MapChunk, Nation, PublicNationDetail, Secretary } from './types';

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
    map_space: { id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 } },
};

const ownerNationFixture: Nation = {
    id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '',
    money: 100, money_display: '100億円', money_capacity: 9999, money_remaining_capacity: 9899,
    money_is_at_capacity: false, total_food_tons: 10000, food_total_tons: 10000,
    food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
    farm_capacity_people: 10000, factory_capacity_people: 0, mine_capacity_people: 0,
    food_resources: [], resources: [], state: 'active', current_turn: 1, registered_turn: 1,
    survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only', total_population: 1000,
    territory_cell_count: 19, owned_land_cells: 17, capital: { x: 12, y: 8 },
};

const unnamedSecretaryFixture: Secretary = {
    id: 11,
    name: null,
    named_at: null,
    header_label: '？？？',
    effect_context: {
        source: 'owned_world', world_id: 1, ruleset_version_id: 11,
        ruleset_key: 'test-hakoniwa-2s-plus-v11-secretary-items', ruleset_version: 11,
    },
    equipment_version: 1,
    skills: [
        { key: 'agricultural_policy', name: '農業政策', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '小麦生産＋0.0%' },
        { key: 'specialty_development', name: '特産品開発', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '工場生産＋0.0%' },
        { key: 'gold_vein_survey', name: '金鉱脈調査', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '採掘場生産＋0.0%' },
        { key: 'final_defense_line', name: '最終防衛ライン', level: 1, experience: 0, required_experience: 100, remaining_experience: 100, effect: '防衛されなかったミサイルを1ターンにつき1発まで迎撃' },
    ],
    inventory: {
        capacity: 50,
        used: 2,
        items: [{
            id: 21, key: 'old_bow', name: '古びた弓', level: 1, category: 'bow', category_label: '弓',
            equipped_slot: 1, is_equipped: true,
            effect_text: '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            flavor_text: '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
            obtained_at: '2026-08-17T00:00:00Z',
        }, {
            id: 22, key: 'ring', name: '指輪', level: 3, category: 'ring', category_label: '指輪',
            equipped_slot: null, is_equipped: false,
            effect_text: '資金繰りの際、追加で3億円を得る。',
            flavor_text: '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
            obtained_at: '2026-08-18T00:00:00Z',
        }],
    },
    equipment: {
        slot_count: 5,
        category_limits: [
            { category: 'bow', label: '弓', maximum_equipped: 1 },
            { category: 'ring', label: '指輪', maximum_equipped: 5 },
        ],
        slots: [
            { slot: 1, item: null },
            { slot: 2, item: null },
            { slot: 3, item: null },
            { slot: 4, item: null },
            { slot: 5, item: null },
        ],
    },
};
unnamedSecretaryFixture.equipment.slots[0]!.item = unnamedSecretaryFixture.inventory.items[0]!;

function publicResponse(path: string): Response | null {
    if (path === '/api/v1/public/announcements/latest') return response([
        { id: 2, title: 'ver 1.0.2のお知らせ', body: 'queue fix', created_at: '2026-08-02T03:00:00+09:00', updated_at: '2026-08-02T03:00:00+09:00' },
        { id: 1, title: 'ver 1.0.1のお知らせ', body: 'resource fix', created_at: '2026-08-01T03:00:00+09:00', updated_at: '2026-08-01T03:00:00+09:00' },
    ]);
    if (path === '/api/v1/public/worlds') return response([{ id: 1, key: 'shared-world', name: '箱庭諸島２S＋', turn: 1 }]);
    if (path.endsWith('/summary')) return response({
        id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 1, nation_count: 1, total_population: 1000, contact_url: null,
        hakoniwa_calendar: { year: 1, month: 1, label: '箱庭歴 1年1月' },
        turn_status: 'normal', last_successful_turn_at: '2026-08-09T13:00:00Z',
        next_scheduled_turn_at: '2099-08-09T15:00:00Z', turn_schedule_timezone: 'Asia/Tokyo',
    });
    if (path.endsWith('/rankings')) return response([{
        rank: 1, id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', total_population: 1000,
        owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
        farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
        registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
        last_updated_turn: 1, comment: '公開コメント',
        achievements: { awards: [], monster_kills: null },
    }]);
    if (path.endsWith('/major-news')) return response({ groups: [], limit: 15 });
    if (/^\/api\/v1\/public\/worlds\/\d+\/events/.test(path)) return response({
        groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
        turns_per_page: 2, has_newer_page: false, has_older_page: false,
    });
    if (/^\/api\/v1\/public\/nations\/\d+\/events/.test(path)) return response({
        groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
        turns_per_page: 24, has_newer_page: false, has_older_page: false,
    });
    if (/^\/api\/v1\/nations\/\d+\/message-board$/.test(path)) return response({
        board: { nation_number: 1, name: '公開島' }, entries: [],
        viewer: { authenticated: false, can_post: false, author_type: null, can_send_secret: false },
        contract: {
            latest_limit: 16, body_max_characters: 140, cooldown_seconds: 10,
            secret_cost_money: 100, secret_cost_display: '100億円',
        },
    });
    return null;
}

beforeEach(() => {
    const meta = document.createElement('meta');
    meta.name = 'hakoniwa-application-version';
    meta.content = '2.2.1';
    document.head.append(meta);
});

afterEach(() => {
    document.querySelector('meta[name="hakoniwa-application-version"]')?.remove();
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
        expect(wrapper.find('.ranking-card thead').text()).toBe('順位島名＋賞/討伐人口面積資金食料農場規模工場規模採掘場規模生存ターン');
        expect(wrapper.findAll('.ranking-card tbody')).toHaveLength(1);
        expect(wrapper.findAll('.ranking-card tbody tr')).toHaveLength(2);
        expect(wrapper.find('.ranking-card tbody').text()).toContain('17セル');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('10,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('30,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('5,000人');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('10,000トン');
        expect(wrapper.find('.ranking-card').text()).not.toContain('活動状態');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('公開島主');
        expect(wrapper.find('.ranking-owner-row').text()).toBe('公開島主：公開コメント');
        expect(wrapper.find('.ranking-card tbody button').text()).toContain('公開島 (100)');
        expect(wrapper.text()).toContain('重大ニュースはまだありません');
        expect(wrapper.text()).toContain('このターン範囲には公開島ログがありません');
        expect(wrapper.text()).not.toContain('初期データを取得できません');
        expect(wrapper.find('.app-version').text()).toBe('ver 2.2.1');
        expect(wrapper.find('.hakoniwa-calendar').text()).toBe('箱庭歴 1年1月');
        expect(wrapper.find('.site-header nav').text()).toContain('TOP');
        expect(wrapper.find('.site-header nav').text()).toContain('マニュアル');
        expect(wrapper.find('.site-header nav').text()).not.toContain('クレジット');
        expect(wrapper.find('.site-header nav').text()).not.toContain('利用ルール');
        expect(wrapper.find('.announcement-window').text()).toContain('ver 1.0.2のお知らせ');
        expect(wrapper.findAll('.announcement-window li')).toHaveLength(2);
        expect(wrapper.find('.turn-status-card').text()).toContain('最終ターン更新');
        expect(wrapper.find('.turn-status-card').text()).toContain('次回更新まで');
        expect(wrapper.find('.turn-countdown').exists()).toBe(true);
    });

    it('renders a 100-character Latin comment in the wrapping ranking owner row', async () => {
        const comment = `https://example.com/${'a'.repeat(80)}`;
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/rankings')) return response([{
                rank: 1, id: 7, world_id: 1, nation_number: 1, name: '長文島', state: 'active',
                total_population: 1000, owner_name: '長文島主', territory_cell_count: 19, owned_land_cells: 17,
                money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
                registered_turn: 1, survival_turns: 0, finance_only_turns: 0, activity_status: 'active',
                last_updated_turn: 1, comment,
                achievements: { awards: [], monster_kills: null },
            }]);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        const ownerCell = wrapper.find('.ranking-owner-row td');
        expect(comment).toHaveLength(100);
        expect(ownerCell.text()).toBe(`長文島主：${comment}`);
    });

    it('marks dormant islands beside the name without an activity-status column', async () => {
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/rankings')) return response([{
                rank: 1, id: 7, world_id: 1, nation_number: 1, name: '休止島', state: 'dormant_frozen',
                total_population: 1000, owner_name: '休止島主', territory_cell_count: 19, owned_land_cells: 17,
                money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                farm_capacity_people: 0, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
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
        expect(wrapper.find('.ranking-owner-row').text()).toBe('休止島主');
        expect(wrapper.find('.ranking-card').text()).not.toContain('活動状態');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('保有せず');
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

    it('refreshes the owner Nation, MapSpace bounds revision, and loaded private map when the turn advances', async () => {
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
        let mapSpaceCalls = 0;
        let privateChunkCalls = 0;
        let ownerEventCalls = 0;
        let failTurnRefreshChunk = true;
        let failExpansionRefreshChunk = true;
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
            if (path === '/api/v1/worlds/1/map-spaces') {
                mapSpaceCalls++;
                return response([mapSpaceCalls < 4 ? publicDetail.map_space : {
                    ...publicDetail.map_space,
                    bounds_revision: 'bounds-0-63',
                    bounds: { min_x: 0, max_x: 63, min_y: 0, max_y: 63 },
                }]);
            }
            if (path.includes('/api/v1/map-spaces/2/chunks/')) {
                privateChunkCalls++;
                if (summaryCalls >= 2 && failTurnRefreshChunk) {
                    failTurnRefreshChunk = false;
                    return response(null, 500);
                }
                if (mapSpaceCalls >= 4 && failExpansionRefreshChunk) {
                    failExpansionRefreshChunk = false;
                    return response(null, 500);
                }
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
        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '自島へ')!.trigger('click');
        await flushPromises();
        const initialChunkCalls = privateChunkCalls;

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();

        expect(summaryCalls).toBe(2);
        expect(nationCalls).toBe(2);
        expect(mapSpaceCalls).toBe(2);
        expect(privateChunkCalls).toBeGreaterThan(initialChunkCalls);
        expect(ownerEventCalls).toBe(2);
        expect(wrapper.findComponent(HexMap).props('bounds')).toEqual(publicDetail.map_space.bounds);
        expect(wrapper.find('.hud-primary').text()).toContain('人口1,500人');
        const failedRefreshChunkCalls = privateChunkCalls;

        await vi.advanceTimersByTimeAsync(2_000);
        await flushPromises();

        expect(summaryCalls).toBe(3);
        expect(nationCalls).toBe(3);
        expect(mapSpaceCalls).toBe(3);
        expect(privateChunkCalls).toBeGreaterThan(failedRefreshChunkCalls);
        expect(ownerEventCalls).toBe(2);

        await vi.advanceTimersByTimeAsync(57_000);
        await flushPromises();

        expect(summaryCalls).toBe(4);
        expect(mapSpaceCalls).toBe(4);
        expect(wrapper.findComponent(HexMap).props('bounds')).toEqual({ min_x: 0, max_x: 63, min_y: 0, max_y: 63 });
        const failedExpansionChunkCalls = privateChunkCalls;

        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();

        expect(summaryCalls).toBe(5);
        expect(mapSpaceCalls).toBe(5);
        expect(privateChunkCalls).toBeGreaterThan(failedExpansionChunkCalls);
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
        expect(JSON.parse(String(request?.[1]?.body))).toEqual(expect.objectContaining({
            request_key: expect.any(String),
            world_id: 1, name: '登録島', owner_name: '登録島主', comment: '登録コメント',
        }));
        expect(wrapper.text()).toContain('島主名は必須です。');
        expect(wrapper.text()).toContain('一言コメントに改行は使用できません。');
    });

    it('opens a guest preview through public-only endpoints', async () => {
        const monsterKillStats = Array.from({ length: 11 }, (_, index) => ({
            key: `monster_${index}`,
            name: `怪獣${index}`,
            kill_count: index + 1,
            first_killed_turn: index + 2,
            last_killed_turn: index + 3,
        }));
        const detailWithManySpecies: PublicNationDetail = {
            ...publicDetail,
            monster_final_blow_count: 66,
            monster_kill_stats: monsterKillStats,
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/nations/7') return response(detailWithManySpecies);
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
        expect(wrapper.find('.monster-kill-marks').text()).toContain('怪獣10 × 11');
        expect(wrapper.findAll('.monster-kill-marks > span')).toHaveLength(11);
        expect(wrapper.find('.command-workspace').exists()).toBe(false);
        expect(wrapper.find('.preview-page > .message-board').exists()).toBe(true);
        const previewBoard = wrapper.get('.preview-page > .message-board').element;
        const previewLog = wrapper.get('.preview-page > .island-events-panel').element;
        expect(previewBoard.compareDocumentPosition(previewLog) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toBe(true);
    });

    it('refreshes an open public preview when bounds change without a turn advance', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-09T12:00:00Z'));
        let detailCalls = 0;
        let publicChunkCalls = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/nations/7') {
                detailCalls++;
                return response(detailCalls === 1 ? publicDetail : {
                    ...publicDetail,
                    map_space: {
                        ...publicDetail.map_space,
                        bounds_revision: 'bounds-0-63',
                        bounds: { min_x: 0, max_x: 63, min_y: 0, max_y: 63 },
                    },
                });
            }
            if (path.includes('/api/v1/public/nations/7/map-spaces/2/chunks/')) {
                publicChunkCalls++;
                return response(emptyChunk);
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.find('.ranking-card tbody button').trigger('click');
        await flushPromises();
        const initialChunkCalls = publicChunkCalls;

        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();

        expect(detailCalls).toBe(2);
        expect(publicChunkCalls).toBeGreaterThan(initialChunkCalls);
        expect(wrapper.findComponent(HexMap).props('bounds')).toEqual({ min_x: 0, max_x: 63, min_y: 0, max_y: 63 });
        wrapper.unmount();
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
            if (path.endsWith('/rankings')) return response([
                {
                    rank: 1, id: 3, world_id: 1, nation_number: 1, name: '自島', state: 'active',
                    total_population: 1000, owner_name: '自島主', territory_cell_count: 19, owned_land_cells: 17,
                    money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                    farm_capacity_people: 10_000, factory_capacity_people: 20_000, mine_capacity_people: 30_000,
                    registered_turn: 1, survival_turns: 0, finance_only_turns: 0, activity_status: 'active',
                    last_updated_turn: 1, comment: '自島コメント', achievements: { awards: [], monster_kills: null },
                },
                {
                    rank: 2, id: 7, world_id: 1, nation_number: 2, name: '公開島', state: 'active',
                    total_population: 1000, owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17,
                    money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                    farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
                    registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
                    last_updated_turn: 1, comment: '公開コメント', achievements: { awards: [], monster_kills: null },
                },
            ]);
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
                id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 },
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

        const headerNavigation = wrapper.find('.site-header nav').text();
        expect(headerNavigation).toContain('TOP');
        expect(headerNavigation).toContain('自島へ');
        expect(headerNavigation).toContain('資源売却');
        expect(headerNavigation).toContain('プロフィール編集');
        expect(headerNavigation).toContain('マニュアル');
        expect(headerNavigation).not.toContain('クレジット');
        expect(headerNavigation).not.toContain('利用ルール');
        expect(wrapper.find('.session-actions').text()).toContain('Owner');
        expect(wrapper.find('.session-actions').text()).toContain('アカウント');
        expect(wrapper.find('.session-actions').text()).not.toContain('自島');

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '自島へ')!.trigger('click');
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
        const workspaceScroll = wrapper.get('.island-workspace-scroll');
        expect(workspaceScroll.attributes('role')).toBe('region');
        expect(workspaceScroll.attributes('tabindex')).toBe('0');
        expect(workspaceScroll.find('.island-grid').exists()).toBe(true);
        expect(workspaceScroll.find('.command-panel').exists()).toBe(true);
        expect(workspaceScroll.find('.map-column').exists()).toBe(true);
        expect(workspaceScroll.find('.plan-panel').exists()).toBe(true);
        expect(workspaceScroll.find('.island-events-panel').exists()).toBe(false);
        const developmentBoard = wrapper.get('.island-page > .message-board').element;
        const developmentLogs = wrapper.findAll('.island-page > .island-events-panel');
        expect(developmentLogs).toHaveLength(2);
        for (const log of developmentLogs) {
            expect(developmentBoard.compareDocumentPosition(log.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        }
        const workspaceJumpButtons = wrapper.findAll('.workspace-jump button');
        expect(workspaceJumpButtons).toHaveLength(3);
        expect(workspaceJumpButtons.every((button) => button.attributes('aria-controls') === workspaceScroll.attributes('id'))).toBe(true);
        const scrollTo = vi.fn();
        Object.defineProperty(workspaceScroll.element, 'scrollTo', { configurable: true, value: scrollTo });
        await workspaceJumpButtons[2]!.trigger('click');
        expect(scrollTo).toHaveBeenCalledWith({ left: expect.any(Number), behavior: 'smooth' });
        expect(wrapper.findAll('.island-events-panel')).toHaveLength(2);
        expect(wrapper.findAll('.island-events-panel').map((panel) => panel.get('h2').text()))
            .toEqual(['公開島ログ', 'owner-onlyログ']);
        expect(wrapper.find('.island-page > .message-board').exists()).toBe(true);
        expect(wrapper.findAll('.plan-row')).toHaveLength(20);
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(1);

        const lobbyButton = wrapper.findAll('.site-header nav button').find((button) => button.text() === 'TOP')!;
        await lobbyButton.trigger('click');
        const ownRankingButton = wrapper.findAll('.ranking-card tbody button').find((button) => button.text().includes('自島'))!;
        await ownRankingButton.trigger('click');
        await flushPromises();
        expect(wrapper.find('.island-page').exists()).toBe(true);
        expect(wrapper.find('.preview-page').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/public/nations/3')).toBe(false);

        await lobbyButton.trigger('click');
        const publicRankingButton = wrapper.findAll('.ranking-card tbody button').find((button) => button.text().includes('公開島'))!;
        await publicRankingButton.trigger('click');
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

    it('shows the unnamed Secretary story with the default name and switches permanently to the skill view after naming', async () => {
        let secretary = unnamedSecretaryFixture;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') {
                return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            }
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary/name' && init?.method === 'POST') {
                const body = JSON.parse(String(init.body)) as { name: string };
                secretary = {
                    ...secretary,
                    name: body.name,
                    named_at: '2026-08-16T15:00:00+09:00',
                    header_label: body.name,
                };

                return response(secretary);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { name: string };
                secretary = { ...secretary, name: body.name, header_label: body.name };

                return response(secretary);
            }
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const secretaryButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === '？？？')!;
        expect(secretaryButton.exists()).toBe(true);
        await secretaryButton.trigger('click');
        await flushPromises();

        expect(wrapper.get('.secretary-story').text()).toContain('怪獣に踏み荒らされた地から妙な施設が見つかった');
        expect(wrapper.get('.secretary-page-title').text()).toBe('秘書');
        expect(wrapper.get('.secretary-name').text()).toBe('？？？');
        expect(wrapper.get<HTMLInputElement>('#secretary-name').element.value).toBe('ペリドット');
        await wrapper.get('.secretary-naming-form').trigger('submit');
        await flushPromises();

        const namingRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/name' && init?.method === 'POST'
        ));
        expect(JSON.parse(String(namingRequest?.[1]?.body))).toEqual({ name: 'ペリドット' });
        expect(wrapper.find('.secretary-story').exists()).toBe(false);
        expect(wrapper.get('.secretary-page-title').text()).toBe('秘書');
        expect(wrapper.get('.secretary-name').text()).toBe('ペリドット');
        expect(wrapper.get('.secretary-section-title').text()).toBe('パッシブスキル');
        const skillRows = wrapper.findAll('.secretary-skill');
        expect(skillRows).toHaveLength(4);
        const agriculturalSkill = skillRows[0]!;
        const defenseSkill = skillRows[3]!;
        expect(agriculturalSkill.get('.secretary-skill-name').text()).toBe('農業政策');
        expect(agriculturalSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv0', 'XP 0 / 1']);
        expect(agriculturalSkill.get('.secretary-skill-effect').text()).toBe('小麦生産＋0.0%');
        expect(defenseSkill.get('.secretary-skill-name').text()).toBe('最終防衛ライン');
        expect(defenseSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv1', 'XP 0 / 100']);
        expect(defenseSkill.get('.secretary-skill-effect').text()).toBe('防衛されなかったミサイルを1ターンにつき1発まで迎撃');
        expect(wrapper.get('.secretary-skills').text()).not.toContain('次のlevelまで');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'ペリドット')).toBe(true);

        const secretaryGetCount = () => fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/secretary?world_id=1').length;
        const beforeTabSwitch = secretaryGetCount();
        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.text())).toEqual(['熟練度', '装備', '倉庫']);
        expect(tabs[0]!.attributes('aria-selected')).toBe('true');
        await tabs[0]!.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true');
        await wrapper.findAll('[role="tab"]')[1]!.trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.findAll('[role="tab"]')[0]!.attributes('aria-selected')).toBe('true');
        await tabs[1]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment li')).toHaveLength(5);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('古びた弓');
        expect(wrapper.findAll('.secretary-equipment li').slice(1).every((slot) => slot.text().includes('空き'))).toBe(true);
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
        expect(wrapper.get('.secretary-section-title').text()).toBe('倉庫 2 / 50');
        expect(wrapper.findAll('.item-effect').map((effect) => effect.text())).toEqual([
            '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            '資金繰りの際、追加で3億円を得る。',
        ]);
        expect(wrapper.get('.secretary-warehouse').text()).toContain('施設の最奥');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('貴金属が使われた豪華な指輪');
        expect(wrapper.get('.item-flavor').classes()).toContain('item-flavor');
        expect(secretaryGetCount()).toBe(beforeTabSwitch);

        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!.trigger('click');
        expect(wrapper.get<HTMLInputElement>('.secretary-rename-form input').element.value).toBe('ペリドット');
        await wrapper.get('.secretary-rename-form input').setValue('エメラルド');
        await wrapper.get('.secretary-rename-form').trigger('submit');
        await flushPromises();
        const renameRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/name' && init?.method === 'PATCH'
        ));
        expect(JSON.parse(String(renameRequest?.[1]?.body))).toEqual({ name: 'エメラルド' });
        expect(wrapper.text()).toContain('秘書の名前を「エメラルド」に変更しました。');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'エメラルド')).toBe(true);
    });

    it('keeps a committed equipment mutation when the scoped projection refresh fails', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let scopedSecretaryGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                scopedSecretaryGets++;
                return scopedSecretaryGets <= 2 ? response(serverSecretary) : response(null, 500);
            }
            if (path === '/api/v1/me/secretary/equipment/1/options?world_id=1') {
                const current = serverSecretary.equipment.slots[0]!.item;
                return response({
                    slot: 1,
                    equipment_version: serverSecretary.equipment_version,
                    effect_context: serverSecretary.effect_context,
                    current_item: current,
                    items: serverSecretary.inventory.items,
                    category_limits: serverSecretary.equipment.category_limits,
                });
            }
            if (path === '/api/v1/me/secretary/equipment/1' && init?.method === 'PUT') {
                serverSecretary.equipment_version = 2;
                serverSecretary.inventory.items[0]!.equipped_slot = null;
                serverSecretary.inventory.items[0]!.is_equipped = false;
                serverSecretary.equipment.slots[0]!.item = null;

                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
        await wrapper.findAll('.secretary-equipment button')[0]!.trigger('click');
        await flushPromises();
        await wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[0]!.setValue(true);
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();

        const put = fetchMock.mock.calls.find(([path, request]) => (
            String(path) === '/api/v1/me/secretary/equipment/1' && request?.method === 'PUT'
        ));
        expect(JSON.parse(String(put?.[1]?.body))).toEqual({ item_id: null, expected_version: 1 });
        expect(wrapper.find('.equipment-modal').exists()).toBe(false);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('空き');
        expect(wrapper.text()).toContain('装備は変更されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('装備を変更できませんでした。');
    });

    it('keeps committed naming and rename results when scoped projection refreshes fail', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        let scopedSecretaryGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                scopedSecretaryGets++;
                return scopedSecretaryGets <= 2 ? response(serverSecretary) : response(null, 500);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'POST') {
                const body = JSON.parse(String(init.body)) as { name: string };
                serverSecretary.name = body.name;
                serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
                serverSecretary.header_label = body.name;

                return response(serverSecretary);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { name: string };
                serverSecretary.name = body.name;
                serverSecretary.header_label = body.name;

                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '？？？')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-naming-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('.secretary-story').exists()).toBe(false);
        expect(wrapper.get('.secretary-name').text()).toBe('ペリドット');
        expect(wrapper.text()).toContain('秘書は「ペリドット」と命名されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('Secretaryを命名できませんでした。');

        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!.trigger('click');
        await wrapper.get('.secretary-rename-form input').setValue('エメラルド');
        await wrapper.get('.secretary-rename-form').trigger('submit');
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('.secretary-rename-form input').element.value).toBe('エメラルド');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'エメラルド')).toBe(true);
        expect(wrapper.text()).toContain('秘書の名前は「エメラルド」に変更されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('秘書名を変更できませんでした。');
    });

    it('keeps a v10 warehouse ruleset-neutral without substituting category text as an effect', async () => {
        const secretary = structuredClone(unnamedSecretaryFixture);
        secretary.name = 'ペリドット';
        secretary.named_at = '2026-08-16T15:00:00+09:00';
        secretary.header_label = 'ペリドット';
        secretary.effect_context = {
            source: 'owned_world', world_id: 1, ruleset_version_id: 10,
            ruleset_key: 'hakoniwa-2s-plus-v10', ruleset_version: 10,
        };
        secretary.inventory.items.forEach((item) => { item.effect_text = null; });
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');

        expect(wrapper.findAll('.item-effect')).toHaveLength(0);
        expect(wrapper.get('.secretary-warehouse').text()).toContain('弓');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('指輪');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('貴金属が使われた豪華な指輪');
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/me/secretary')).toBe(false);
    });

    it('loads authoritative equipment options and handles duplicate submit, stale refresh, validation, and success', async () => {
        let serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let optionsCalls = 0;
        let putCalls = 0;
        let resolveFirstPut: ((response: Response) => void) | undefined;
        const firstPut = new Promise<Response>((resolve) => { resolveFirstPut = resolve; });

        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') {
                return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [] });
            }
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/secretary/equipment/1/options?world_id=1') {
                optionsCalls++;
                const current = serverSecretary.equipment.slots[0]!.item;
                return response({
                    slot: 1,
                    equipment_version: serverSecretary.equipment_version,
                    effect_context: {
                        source: 'owned_world', world_id: 1, ruleset_version_id: 10,
                        ruleset_key: 'hakoniwa-2s-plus-v10', ruleset_version: 10,
                    },
                    current_item: current === null ? null : {
                        id: current.id, key: current.key, name: current.name, level: current.level,
                        category: current.category, category_label: current.category_label,
                        equipped_slot: current.equipped_slot, effect_text: current.effect_text,
                    },
                    items: serverSecretary.inventory.items.map((item) => ({
                        id: item.id, key: item.key, name: item.name, level: item.level,
                        category: item.category, category_label: item.category_label,
                        equipped_slot: item.equipped_slot, effect_text: item.effect_text,
                    })),
                    category_limits: serverSecretary.equipment.category_limits,
                });
            }
            if (path === '/api/v1/me/secretary/equipment/1' && init?.method === 'PUT') {
                putCalls++;
                if (putCalls === 1) {
                    serverSecretary.equipment_version = 2;
                    return firstPut;
                }
                if (putCalls === 2) {
                    return new Response(JSON.stringify({
                        code: 'secretary_equipment_invalid',
                        message: 'この装備は選択できません。',
                    }), { status: 422, headers: { 'Content-Type': 'application/json' } });
                }

                serverSecretary.equipment_version = 3;
                serverSecretary.inventory.items[0]!.equipped_slot = null;
                serverSecretary.inventory.items[0]!.is_equipped = false;
                serverSecretary.equipment.slots[0]!.item = null;
                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        const scopedSecretaryGets = () => fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/secretary?world_id=1'
        )).length;
        await wrapper.findAll('[role="tab"]')[1]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment button')).toHaveLength(5);
        expect(wrapper.get('.equipment-category-limits').text()).toContain('弓・1個まで');
        await wrapper.findAll('.secretary-equipment button')[0]!.trigger('click');
        await flushPromises();

        expect(wrapper.get('.equipment-modal').attributes('aria-modal')).toBe('true');
        expect(wrapper.findAll('.equipment-option-row').map((row) => row.text())).toEqual([
            '外す',
            '古びた弓Lv110%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            '指輪Lv3資金繰りの際、追加で3億円を得る。',
        ]);
        expect(wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[1]!.element.checked).toBe(true);
        const submit = wrapper.get('.equipment-modal-footer button');
        const beforeStaleRefresh = scopedSecretaryGets();
        await submit.trigger('click');
        await submit.trigger('click');
        expect(putCalls).toBe(1);

        resolveFirstPut?.(new Response(JSON.stringify({
            code: 'secretary_equipment_version_conflict',
            message: '装備状態が更新されています。',
        }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
        await flushPromises();
        expect(scopedSecretaryGets()).toBe(beforeStaleRefresh + 1);
        expect(optionsCalls).toBe(2);
        expect(wrapper.get('.equipment-modal-notice').text()).toContain('最新の候補から選び直してください');
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(true);

        await wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[0]!.setValue(true);
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(false);
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.equipment-modal-error').text()).toBe('この装備は選択できません。');
        expect(wrapper.find('.equipment-modal').exists()).toBe(true);

        const beforeSuccessfulReload = scopedSecretaryGets();
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();
        expect(putCalls).toBe(3);
        expect(scopedSecretaryGets()).toBe(beforeSuccessfulReload + 1);
        expect(wrapper.find('.equipment-modal').exists()).toBe(false);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('空き');
        const successfulBody = JSON.parse(String(fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/secretary/equipment/1'
        )).at(-1)?.[1]?.body));
        expect(successfulBody).toEqual({ item_id: null, expected_version: 2 });
    });

    it('shows only a compact header inquiry shortcut to non-admin users', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Player', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(null);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.find('.inquiry-window').exists()).toBe(false);
        expect(wrapper.get('.session-account-actions').text()).toContain('アカウント');
        const shortcut = wrapper.get('.inquiry-shortcut');
        expect(shortcut.text()).toBe('お問い合わせ');
        expect(shortcut.element.previousElementSibling).toBe(wrapper.get('.session-account-actions').element);
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/admin/inquiries/latest')).toBe(false);

        await shortcut.trigger('click');
        expect(wrapper.find('.inquiry-form').exists()).toBe(true);
    });

    it('submits an in-game inquiry as multipart and shows latest inquiries only to admins on TOP', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Admin', can_manage_announcements: true, can_manage_inquiries: true, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(null);
            if (path === '/api/v1/admin/inquiries/latest') return response([{
                management_id: 'INQ-000123', category: 'bug', category_label: 'バグ報告', subject: '表示がおかしい',
                created_at: '2026-08-17T12:00:00Z', user: { id: 3, display_name: 'Reporter' }, nation: null,
            }]);
            if (path === '/api/v1/inquiries' && init?.method === 'POST') return response({
                management_id: 'INQ-000124', category: 'idea', category_label: 'アイデア', subject: '新しい案',
                created_at: '2026-08-17T12:01:00Z',
            }, 201);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.get('.inquiry-window').text()).toContain('INQ-000123 [バグ報告] 表示がおかしい');
        expect(wrapper.find('.inquiry-shortcut').exists()).toBe(false);
        const sendButton = wrapper.findAll('.inquiry-window button').find((button) => button.text() === 'お問い合わせを送る')!;
        await sendButton.trigger('click');
        await wrapper.get<HTMLSelectElement>('.inquiry-form select').setValue('idea');
        await wrapper.get<HTMLInputElement>('.inquiry-form input:not([type="file"])').setValue('新しい案');
        await wrapper.get<HTMLTextAreaElement>('.inquiry-form textarea').setValue('本文です。');
        await wrapper.get('.inquiry-form').trigger('submit');
        await flushPromises();

        expect(wrapper.get('.inquiry-confirmation').text()).toContain('INQ-000124');
        const request = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/inquiries');
        expect(request?.[1]?.body).toBeInstanceOf(FormData);
        const body = request?.[1]?.body as FormData;
        expect(body.get('category')).toBe('idea');
        expect(body.get('subject')).toBe('新しい案');
        expect(body.get('body')).toBe('本文です。');
        expect(request?.[1]?.headers).toBeInstanceOf(Headers);
        expect((request?.[1]?.headers as Headers).has('Content-Type')).toBe(false);
    });

    it('loads the inquiry index when an admin returns from a TOP-linked detail', async () => {
        const summary = {
            management_id: 'INQ-000123', category: 'bug', category_label: 'バグ報告', subject: '表示がおかしい',
            created_at: '2026-08-17T12:00:00Z', user: { id: 3, display_name: 'Reporter' }, nation: null,
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Admin', can_manage_announcements: true, can_manage_inquiries: true, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(null);
            if (path === '/api/v1/admin/inquiries/latest') return response([summary]);
            if (path === '/api/v1/admin/inquiries/123') return response({
                ...summary,
                body: '詳細本文', world: { id: 1, submitted_turn: 9 }, application_version: '2.2.0', attachment_url: null,
            });
            if (path === '/api/v1/admin/inquiries?page=1') return envelopeResponse([
                { ...summary, management_id: 'INQ-000122', subject: '一覧の件名' },
            ], { current_page: 1, last_page: 1, total: 1 });

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const latestButton = wrapper.findAll('.inquiry-window button')
            .find((button) => button.text().includes('INQ-000123'))!;
        await latestButton.trigger('click');
        await flushPromises();
        expect(wrapper.get('.inquiry-detail').text()).toContain('詳細本文');

        const backButton = wrapper.findAll('.inquiry-detail button')
            .find((button) => button.text() === '一覧へ戻る')!;
        await backButton.trigger('click');
        await flushPromises();

        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/admin/inquiries?page=1')).toBe(true);
        expect(wrapper.find('.inquiry-detail').exists()).toBe(false);
        expect(wrapper.get('.inquiry-list.full').text()).toContain('INQ-000122 [バグ報告] 一覧の件名');
    });

    it('requires the danger button, modal, and exact island name before abandonment and returns to registration', async () => {
        let abandoned = false;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') return response(abandoned ? null : ownerNationFixture);
            if (path === '/api/v1/nations/3/abandon' && init?.method === 'POST') {
                abandoned = true;

                return response({ nation_id: 3, state: 'abandoned' });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const profileButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!;
        await profileButton.trigger('click');
        expect(wrapper.find('.danger-zone').text()).toContain('危険な操作');
        await wrapper.get('.danger-zone .danger').trigger('click');
        expect(wrapper.get('.abandonment-modal').attributes('aria-modal')).toBe('true');

        const confirmation = wrapper.get<HTMLInputElement>('#abandonment-confirmation');
        const confirmButton = wrapper.get<HTMLButtonElement>('.abandonment-modal button[type="submit"]');
        expect(confirmButton.element.disabled).toBe(true);
        await confirmation.setValue('自島 ');
        expect(confirmButton.element.disabled).toBe(true);
        await confirmation.setValue('自島');
        expect(confirmButton.element.disabled).toBe(false);

        await wrapper.get('.modal-actions button[type="button"]').trigger('click');
        expect(wrapper.find('.abandonment-modal').exists()).toBe(false);
        expect(fetchMock.mock.calls.some(([path]) => String(path).endsWith('/abandon'))).toBe(false);

        await wrapper.get('.danger-zone .danger').trigger('click');
        await wrapper.get('#abandonment-confirmation').setValue('自島');
        await wrapper.get('.abandonment-modal form').trigger('submit');
        await flushPromises();

        const request = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/nations/3/abandon');
        expect(JSON.parse(String(request?.[1]?.body))).toEqual({ confirmation_name: '自島' });
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(2);
        expect(wrapper.find('.abandonment-modal').exists()).toBe(false);
        expect(wrapper.find('.nation-form').exists()).toBe(true);
    });

    it('reconciles the authoritative Nation state when the abandonment response is lost', async () => {
        let abandoned = false;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') return response(abandoned ? null : ownerNationFixture);
            if (path === '/api/v1/nations/3/abandon' && init?.method === 'POST') {
                abandoned = true;
                throw new TypeError('Failed to fetch');
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const profileButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!;
        await profileButton.trigger('click');
        await wrapper.get('.danger-zone .danger').trigger('click');
        await wrapper.get('#abandonment-confirmation').setValue('自島');
        await wrapper.get('.abandonment-modal form').trigger('submit');
        await flushPromises();

        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(2);
        expect(wrapper.find('.abandonment-modal').exists()).toBe(false);
        expect(wrapper.find('.nation-form').exists()).toBe(true);
        expect(wrapper.text()).toContain('島の破棄を確認しました。新しい島を登録できます。');
    });

    it('ignores an old turn refresh that completes after successful abandonment', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-08-09T12:00:00Z'));
        let summaryCalls = 0;
        let nationCalls = 0;
        let resolveStaleNation!: (value: Response) => void;
        const staleNationResponse = new Promise<Response>((resolve) => {
            resolveStaleNation = resolve;
        });
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path.endsWith('/summary')) {
                summaryCalls++;
                return response({
                    id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: summaryCalls === 1 ? 1 : 2,
                    nation_count: summaryCalls < 3 ? 1 : 0, total_population: summaryCalls < 3 ? 1000 : 0, contact_url: null,
                    turn_status: 'normal', last_successful_turn_at: summaryCalls === 1 ? '2026-08-09T10:00:00Z' : '2026-08-09T12:00:01Z',
                    next_scheduled_turn_at: summaryCalls === 1 ? '2026-08-09T12:00:01Z' : '2026-08-09T14:00:00Z',
                    turn_schedule_timezone: 'Asia/Tokyo',
                });
            }
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') {
                nationCalls++;
                if (nationCalls === 1) return response(ownerNationFixture);
                if (nationCalls === 2) return staleNationResponse;

                return response(null);
            }
            if (path === '/api/v1/nations/3/abandon' && init?.method === 'POST') {
                return response({ nation_id: 3, state: 'abandoned' });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();
        expect(nationCalls).toBe(2);

        const profileButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!;
        await profileButton.trigger('click');
        await wrapper.get('.danger-zone .danger').trigger('click');
        await wrapper.get('#abandonment-confirmation').setValue('自島');
        await wrapper.get('.abandonment-modal form').trigger('submit');
        await flushPromises();

        expect(nationCalls).toBe(3);
        expect(wrapper.find('.nation-form').exists()).toBe(true);
        resolveStaleNation(response(ownerNationFixture));
        await flushPromises();
        expect(wrapper.find('.nation-form').exists()).toBe(true);
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === '自島へ')).toBe(false);
        wrapper.unmount();
    });

    it('does not clear the active Nation when the abandonment API fails', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/nations/3/abandon' && init?.method === 'POST') {
                return new Response(JSON.stringify({ code: 'world_updating', message: 'このWorldは現在更新中です。' }), {
                    status: 409,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const profileButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'プロフィール編集')!;
        await profileButton.trigger('click');
        await wrapper.get('.danger-zone .danger').trigger('click');
        await wrapper.get('#abandonment-confirmation').setValue('自島');
        await wrapper.get('.abandonment-modal form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('.abandonment-modal').exists()).toBe(true);
        expect(wrapper.find('.nation-form').exists()).toBe(false);
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/nation')).toHaveLength(1);
        expect(wrapper.get('.abandonment-modal [role="alert"]').text()).toContain('このWorldは現在更新中です。');
    });
});
