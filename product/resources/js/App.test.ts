import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import HexMap from './components/HexMap.vue';
import TradingPostPanel from './components/TradingPostPanel.vue';
import UndergroundPanel from './components/UndergroundPanel.vue';
import type { MapChunk, Nation, PublicNationDetail, Secretary, TradingPostData, TradingPostListing } from './types';

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
    id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', state_label: '', recovery_remaining_turns: null,
    karma: 0, karma_badge: null, total_population: 1000,
    owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
    farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
    registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
    last_updated_turn: 1, comment: '公開コメント', world: { id: 1, name: '箱庭諸島２S＋', current_turn: 1 },
    capital: { x: 12, y: 8 }, secretary_id: 11,
    monster_final_blow_count: 1,
    monster_kill_stats: [{
        key: 'inora', name: 'いのら', kill_count: 1, first_killed_turn: 12, last_killed_turn: 12,
    }],
    map_space: { id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 } },
};

const resourceForecastFixture: Nation['resource_forecast'] = {
    rows: [
        { key: 'food', name: '食料', production: 55_000, consumption: 60_000, delta: -5_000, holding: 12_000 },
        { key: 'industrial_goods', name: '工業品', production: 15_000, consumption: 0, delta: 15_000, holding: 1_200 },
        { key: 'minerals', name: '鉱物', production: 46_154, consumption: 0, delta: 46_154, holding: 0 },
        { key: 'oil', name: '石油', production: 500, consumption: 0, delta: 500, holding: 123 },
    ],
    food_holding_note: '食料の所持は小麦換算です。',
    workforce: { status: 'unemployment', label: '失業率', percentage_tenths: 160, population: 60_000, demand: 50_400 },
};

const ownerNationFixture: Nation = {
    id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '',
    money: 100, money_display: '100億円', money_capacity: 9999, money_remaining_capacity: 9899,
    money_is_at_capacity: false, total_food_tons: 10000, food_total_tons: 10000,
    food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
    farm_capacity_people: 10000, factory_capacity_people: 0, mine_capacity_people: 0,
    resource_forecast: resourceForecastFixture,
    food_resources: [], resources: [], state: 'active', state_label: '', karma: 0, karma_positive: false,
    recovery_remaining_turns: null, state_reason: null,
    state_started_turn: null, resume_at_turn: null, manual_dormancy_days: null,
    dormancy_remaining_turns: null, dormancy_remaining_days: null, abandonment_remaining_turns: 2060,
    can_request_dormancy: true, winter_theme_active: false, current_turn: 1, registered_turn: 1,
    survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only', total_population: 1000,
    territory_cell_count: 19, owned_land_cells: 17, capital: { x: 12, y: 8 },
};

const unnamedSecretaryFixture: Secretary = {
    id: 11,
    name: null,
    named_at: null,
    header_label: '？？？',
    profile: {
        id: 11,
        name: null,
        is_owner: true,
        domestic_level: 1,
        secretary_level: 1,
        passive_level_total: 1,
        capacity_bonus_percent: 1,
        monster_experience: 0,
        biography: '',
        main_image: {
            display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null,
        },
        editable_image_metadata: null,
        viewer_preferences: {
            configured: false, show_ai_generated_images: null,
            own_secretary_fallback: null, fallback: null, can_update: true,
        },
        equipment: {
            slot_count: 5,
            category_limits: [
                { category: 'bow', label: '弓', maximum_equipped: 1 },
                { category: 'clothing', label: '衣服', maximum_equipped: 1 },
            ],
            slots: [
                { slot: 1, item: null },
                { slot: 2, item: null },
                { slot: 3, item: null },
                { slot: 4, item: null },
                { slot: 5, item: null },
            ],
        },
    },
    effect_context: {
        source: 'owned_world', world_id: 1, ruleset_version_id: 11,
        ruleset_key: 'test-hakoniwa-2s-plus-v11-secretary-items', ruleset_version: 11,
    },
    equipment_version: 1,
    skills: [
        { key: 'agricultural_policy', name: '農業政策', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '小麦生産＋0.0%' },
        { key: 'specialty_development', name: '特産品開発', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '工場生産＋0.0%' },
        { key: 'gold_vein_survey', name: '金鉱脈調査', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '採掘場生産＋0.0%' },
        { key: 'forest_management', name: '森林管理', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '伐採資金・森林増加＋0%' },
        { key: 'final_defense_line', name: '最終防衛ライン', level: 1, experience: 0, required_experience: 100, remaining_experience: 100, effect: '防衛されなかったミサイルを1ターンにつき1発まで迎撃' },
        { key: 'declining_birthrate_policy', name: '少子化対策', level: 10, experience: 460000, required_experience: 560000, remaining_experience: 100000, effect: '自然人口上限 +500人 / 誘致人口上限 +1,000人' },
        { key: 'indomitable', name: '不屈', level: 10, experience: 0, required_experience: 560000, remaining_experience: 560000, effect: '自然人口増加 +2.50%' },
    ],
    inventory: {
        capacity: 50,
        used: 2,
        items: [{
            id: 21, key: 'old_bow', name: '古びた弓', level: 1, category: 'bow', category_label: '弓',
            equipped_slot: 1, is_equipped: true, is_escrowed: false, rarity: 'novice', rarity_label: 'ノービス',
            fixed_sale_price_money: 100, fixed_sale_label: '売却（100億円）',
            effect_text: '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            flavor_text: '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
            obtained_at: '2026-08-17T00:00:00Z',
        }, {
            id: 22, key: 'ring', name: '指輪', level: 3, category: 'accessory', category_label: 'アクセサリー',
            equipped_slot: null, is_equipped: false, is_escrowed: false, rarity: 'novice', rarity_label: 'ノービス',
            fixed_sale_price_money: 100, fixed_sale_label: '売却（100億円）',
            effect_text: '資金繰りの際、追加で3億円を得る。',
            flavor_text: '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
            obtained_at: '2026-08-18T00:00:00Z',
        }],
    },
    equipment: {
        slot_count: 5,
        category_limits: [
            { category: 'bow', label: '弓', maximum_equipped: 1 },
            { category: 'clothing', label: '衣服', maximum_equipped: 1 },
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
unnamedSecretaryFixture.profile.equipment = unnamedSecretaryFixture.equipment;

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
        rank: 1, id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', state_label: '',
        recovery_remaining_turns: null, karma: 0, karma_badge: null, total_population: 1000,
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
        turns_per_page: 12, has_newer_page: false, has_older_page: false,
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
    document.documentElement.dataset.theme = 'system';
    document.cookie = 'hakoniwa_theme=; Path=/; Max-Age=0; SameSite=Lax';
    const meta = document.createElement('meta');
    meta.name = 'hakoniwa-application-version';
    meta.content = '3.0.0-alpha.1';
    document.head.append(meta);
});

afterEach(() => {
    document.documentElement.dataset.theme = 'system';
    document.cookie = 'hakoniwa_theme=; Path=/; Max-Age=0; SameSite=Lax';
    document.querySelector('meta[name="hakoniwa-application-version"]')?.remove();
    vi.unstubAllGlobals();
    vi.useRealTimers();
    window.history.replaceState({}, '', '/');
});

describe('application lobby and island entry', () => {
    it('opens theme options before authentication and persists the selected document mode', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);

            return publicResponse(path) ?? response(null, 401);
        });
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(App);
        const optionsButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'オプション')!;
        expect(optionsButton.exists()).toBe(true);
        expect(wrapper.find('.site-header nav').text()).not.toContain('プロフィール編集');
        const requestCountBeforeOptions = fetchMock.mock.calls.length;
        await optionsButton.trigger('click');
        expect(fetchMock).toHaveBeenCalledTimes(requestCountBeforeOptions);
        expect(wrapper.get('.options-panel h1').text()).toBe('オプション');
        expect(wrapper.get('.theme-options legend').text()).toBe('表示テーマ');
        expect(wrapper.find('.profile-settings').exists()).toBe(false);
        expect(wrapper.get<HTMLInputElement>('input[value="system"]').element.checked).toBe(true);

        await flushPromises();
        const requestCount = fetchMock.mock.calls.length;
        await wrapper.get<HTMLInputElement>('input[value="dark"]').setValue();
        expect(document.documentElement.dataset.theme).toBe('dark');
        expect(document.cookie).toContain('hakoniwa_theme=dark');
        await wrapper.get<HTMLInputElement>('input[value="light"]').setValue();
        expect(document.documentElement.dataset.theme).toBe('light');
        expect(document.cookie).toContain('hakoniwa_theme=light');
        await wrapper.get<HTMLInputElement>('input[value="system"]').setValue();
        expect(document.documentElement.dataset.theme).toBe('system');
        expect(document.cookie).toContain('hakoniwa_theme=system');
        expect(fetchMock).toHaveBeenCalledTimes(requestCount);
        wrapper.unmount();

        document.documentElement.dataset.theme = 'dark';
        const darkWrapper = mount(App);
        await darkWrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'オプション')!.trigger('click');
        expect(darkWrapper.get<HTMLInputElement>('input[value="dark"]').element.checked).toBe(true);
        await flushPromises();
        darkWrapper.unmount();

        let resolveNation!: (value: Response) => void;
        const pendingNation = new Promise<Response>((resolve) => {
            resolveNation = resolve;
        });
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
            if (path === '/api/v1/me/nation') return pendingNation;
            if (path === '/api/v1/me/secretary?world_id=1') return response(null);

            return publicResponse(path) ?? response(null, 401);
        }));
        const pendingWrapper = mount(App);
        await pendingWrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'オプション')!.trigger('click');
        await flushPromises();
        expect(pendingWrapper.find('.profile-settings').exists()).toBe(false);

        resolveNation(response({ ...ownerNationFixture, comment: '既存コメント' }));
        await flushPromises();
        expect(pendingWrapper.get<HTMLInputElement>('.profile-form input').element.value).toBe('自島主');
        expect(pendingWrapper.get<HTMLTextAreaElement>('.profile-form textarea').element.value).toBe('既存コメント');
        pendingWrapper.unmount();
    });

    it('continues rendering the public lobby after the normal guest /me 401', async () => {
        window.history.replaceState({}, '', '/underground');
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        expect(window.location.pathname).toBe('/');
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
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
        expect(wrapper.find('.app-version').text()).toBe('ver 3.0.0-alpha.1');
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
                rank: 1, id: 7, world_id: 1, nation_number: 1, name: '休止島', state: 'dormant', state_label: '休眠',
                recovery_remaining_turns: null, karma: 0, karma_badge: null,
                total_population: 1000, owner_name: '休止島主', territory_cell_count: 19, owned_land_cells: 17,
                money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
                farm_capacity_people: 0, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
                registered_turn: 1, survival_turns: 10, finance_only_turns: 7, activity_status: 'dormant',
                last_updated_turn: 11, comment: '',
            }]);
            return publicResponse(path) ?? response(null, 401);
        }));
        const wrapper = mount(App);
        await flushPromises();

        const name = wrapper.find('.ranking-card tbody button');
        expect(name.text()).toBe('休止島');
        expect(wrapper.find('.ranking-island .state-badge').text()).toBe('休眠');
        expect(name.classes()).toContain('is-dormant');
        expect(wrapper.find('.ranking-owner-row').text()).toBe('休止島主');
        expect(wrapper.find('.ranking-card').text()).not.toContain('活動状態');
        expect(wrapper.find('.ranking-card tbody').text()).toContain('保有せず');
    });

    it('renders recovery and KARMA in the authored badge order while keeping zero and negative values unaccented', async () => {
        const baseRanking = {
            world_id: 1, total_population: 1000, territory_cell_count: 19, owned_land_cells: 17,
            money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
            farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
            registered_turn: 1, survival_turns: 10, finance_only_turns: 0, last_updated_turn: 11, comment: '',
            achievements: { awards: [], monster_kills: null },
        };
        const negativeDetail: PublicNationDetail = {
            ...publicDetail,
            id: 9,
            nation_number: 3,
            name: '更生島',
            owner_name: '更生島主',
            karma: -10,
            karma_badge: null,
        };
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/rankings')) return response([{
                ...baseRanking,
                rank: 1, id: 7, nation_number: 1, name: '休戦島', owner_name: '休戦島主',
                state: 'recovery', state_label: '休戦中：残り42ターン', recovery_remaining_turns: 42,
                karma: 84, karma_badge: 'KARMA:84', activity_status: 'recovery',
                achievements: {
                    awards: [{
                        key: 'recovery-order', name: '表示順賞', recurring: false, count: 1,
                        asset: { key: 'test.award', url: null, available: false, fallback_label: '賞', fallback_style: 'text' },
                    }],
                    monster_kills: null,
                },
            }, {
                ...baseRanking,
                rank: 2, id: 8, nation_number: 2, name: '通常島', owner_name: '通常島主',
                state: 'active', state_label: '', recovery_remaining_turns: null,
                karma: 0, karma_badge: null, activity_status: 'active',
            }, {
                ...baseRanking,
                rank: 3, id: 9, nation_number: 3, name: '更生島', owner_name: '更生島主',
                state: 'active', state_label: '', recovery_remaining_turns: null,
                karma: -10, karma_badge: null, activity_status: 'active',
            }]);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);
            if (path === '/api/v1/public/nations/9') return response(negativeDetail);
            if (path.includes('/api/v1/public/nations/9/map-spaces/2/chunks/')) return response(emptyChunk);
            return response(null, 404);
        }));
        const wrapper = mount(App);
        await flushPromises();

        const islands = wrapper.findAll('.ranking-island');
        expect(islands).toHaveLength(3);
        expect(islands[0]!.find('button').classes()).toContain('is-karma-positive');
        expect(islands[0]!.find('.state-badge').text()).toBe('休戦中：残り42ターン');
        expect(islands[0]!.find('.karma-badge').text()).toBe('KARMA:84');
        expect(islands[0]!.findAll(':scope > *').map((child) => (
            child.element.tagName === 'BUTTON' ? 'button' : child.classes()[0]
        ))).toEqual(['button', 'ranking-achievements', 'state-badge', 'karma-badge']);
        expect(islands[1]!.findAll('.state-badge, .karma-badge')).toHaveLength(0);
        expect(islands[1]!.find('button').classes()).not.toContain('is-karma-positive');
        expect(islands[2]!.findAll('.state-badge, .karma-badge')).toHaveLength(0);
        expect(islands[2]!.find('button').classes()).not.toContain('is-karma-positive');

        await islands[2]!.find('button').trigger('click');
        await flushPromises();
        const karmaRow = wrapper.findAll('.preview-heading dl > div')
            .find((row) => row.find('dt').text() === 'KARMA');
        expect(karmaRow?.find('dd').text()).toBe('-10');
        expect(karmaRow?.find('dd').classes()).not.toContain('karma-text');
        expect(wrapper.find('.preview-heading h1').classes()).not.toContain('karma-name');
        expect(wrapper.find('.preview-heading .karma-emphasis').exists()).toBe(false);
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
            resource_forecast: resourceForecastFixture,
            food_resources: [], resources: [], state: 'active', state_label: '', karma: 0, karma_positive: false,
            recovery_remaining_turns: null, state_reason: null,
            state_started_turn: null, resume_at_turn: null, manual_dormancy_days: null,
            dormancy_remaining_turns: null, dormancy_remaining_days: null,
            abandonment_remaining_turns: 2160, can_request_dormancy: true,
            winter_theme_active: false, current_turn: 1, registered_turn: 1,
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
                    turns_per_page: 12, has_newer_page: false, has_older_page: false,
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
            if (path === '/api/v1/secretaries/11?world_id=1') return response({
                ...unnamedSecretaryFixture.profile,
                name: '公開秘書',
                is_owner: false,
                biography: "公開経歴1行目\n公開経歴2行目",
                viewer_preferences: {
                    configured: false, show_ai_generated_images: null,
                    own_secretary_fallback: null, fallback: null, can_update: false,
                },
            });
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

        await wrapper.get('.preview-secretary-link').trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-name').text()).toBe('公開秘書');
        expect(wrapper.findAll('[role="tab"]').map((tab) => tab.text())).toEqual(['メイン']);
        expect(wrapper.get('.secretary-biography-text').text()).toContain('公開経歴2行目');
        expect(wrapper.findAll('.secretary-profile-equipment li')).toHaveLength(5);
        expect(wrapper.find('.secretary-portrait-column > button').exists()).toBe(false);
        expect(wrapper.get('.secretary-image-preference-notice').text()).toContain('ログインすると設定できます');
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

    it('keeps the trading post browsable, disables new mutations, and allows zero-bid cancellation while dormant', async () => {
        const dormantOwnListing: TradingPostListing = {
            id: 82,
            seller: { type: 'nation', nation_id: 3, name: '休眠島' },
            product: {
                type: 'resource', name: '石油', resource_key: 'oil', unit_label: '万バレル',
                quantity: 50, item_key: null, item_level: null, rarity: null, rarity_label: null, effect_text: null,
            },
            start_price: 100, current_price: null, minimum_bid: 100, bid_count: 0,
            highest_bidder_nation_id: null, highest_bidder: null, viewer_bid_status: 'seller',
            started_turn: 8, ends_turn: 14, remaining_turns: 4,
            duration_turns: 6, auto_relist: true, relist_count: 1, is_mine: true,
            can_bid: false, can_cancel: true,
        };
        const dormantMarket: TradingPostData = {
            world: { id: 1, current_turn: 10 },
            nation: { id: 3, name: '休眠島', money: 500, state: 'dormant' },
            permissions: { can_mutate: false },
            listings: [{
                id: 81,
                seller: { type: 'hakoniwa_federation', nation_id: null, name: '箱庭連合' },
                product: {
                    type: 'resource', name: '石油', resource_key: 'oil', unit_label: '万バレル',
                    quantity: 100, item_key: null, item_level: null, rarity: null, rarity_label: null, effect_text: null,
                },
                start_price: 200, current_price: null, minimum_bid: 200, bid_count: 0,
                highest_bidder_nation_id: null, highest_bidder: null, viewer_bid_status: 'none',
                started_turn: 10, ends_turn: 16, remaining_turns: 6,
                duration_turns: 6, auto_relist: false, relist_count: 0, is_mine: false,
                can_bid: false, can_cancel: false,
            }, dormantOwnListing],
            my_listings: [dormantOwnListing],
            sellable_resources: [{ id: 6, key: 'oil', name: '石油', unit_label: '万バレル', amount: 123 }],
            sellable_items: [],
            contract: {
                active_listing_limit: 3, minimum_duration_turns: 3, maximum_duration_turns: 84,
                minimum_increment_money: 1, money_unit_label: '億円', npc_seller_name: '箱庭連合',
            },
        };
        const fetchMock = vi.fn(async () => response(dormantMarket));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(TradingPostPanel, { props: { nationId: 3, worldId: 1 } });
        await flushPromises();

        expect(wrapper.get('.trading-post-table').text()).toContain('石油 100万バレル');
        expect(wrapper.get('.trading-post-table').text()).toContain('現在は入札不可');
        expect(wrapper.get('.trading-post-panel').text()).toContain('休眠中は新規出品できません。');
        expect(wrapper.get('.trading-post-my-listings button').text()).toBe('キャンセル');
        const listingForm = wrapper.get('.trading-post-listing-form');
        expect(listingForm.findAll('input').every((control) => control.attributes('disabled') !== undefined)).toBe(true);
        expect(listingForm.findAll('select').every((control) => control.attributes('disabled') !== undefined)).toBe(true);
        expect(listingForm.get('button').attributes('disabled')).toBeDefined();
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('shows exact owner HUD data without refetching resources per selected cell', async () => {
        vi.useFakeTimers();
        const nation: Nation = {
            id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '自島コメント', money: 62728, money_display: '62,728億円',
            money_capacity: 9999, money_remaining_capacity: 0, money_is_at_capacity: true,
            total_food_tons: 10000, food_total_tons: 10000,
            food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
            farm_capacity_people: 10000, factory_capacity_people: 20000, mine_capacity_people: 30000,
            resource_forecast: resourceForecastFixture,
            food_resources: [
                { key: 'wheat', name: '小麦', balance: 10000, unit: 'ton', unit_label: 'トン' },
                { key: 'fish', name: '魚', balance: 0, unit: 'ton', unit_label: 'トン' },
                { key: 'monster_meat', name: '怪獣肉', balance: 0, unit: 'ton', unit_label: 'トン' },
            ],
            state: 'active', state_label: '', karma: 0, karma_positive: false, recovery_remaining_turns: null,
            state_reason: null, state_started_turn: null,
            resume_at_turn: null, manual_dormancy_days: null, dormancy_remaining_turns: null,
            dormancy_remaining_days: null, abandonment_remaining_turns: 2160,
            can_request_dormancy: true, winter_theme_active: false,
            current_turn: 1, registered_turn: 1, survival_turns: 0,
            finance_only_turns: 0, activity_status: 'active', total_population: 1000, territory_cell_count: 19,
            owned_land_cells: 17,
            capital: { x: 12, y: 8 },
            resources: [
                { key: 'wheat', name: '小麦', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 1, storable: true, tradable: true, amount: 10000, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'fish', name: '魚', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 1, storable: true, tradable: true, amount: 0, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'monster_meat', name: '怪獣肉', category: 'food', unit: 'ton', unit_label: 'トン', nutrition_per_unit: 2, storable: true, tradable: true, amount: 0, capacity: 999900, remaining_capacity: 989900, is_at_capacity: false },
                { key: 'industrial_goods', name: '工業品', category: 'industry', unit: 'unit', unit_label: 'ユニット', nutrition_per_unit: null, storable: true, tradable: true, amount: 1200, capacity: 9999000, remaining_capacity: 9997800, is_at_capacity: false },
                { key: 'minerals', name: '鉱物', category: 'material', unit: 'ton', unit_label: 'トン', nutrition_per_unit: null, storable: true, tradable: true, amount: 0, capacity: 9999000, remaining_capacity: 9999000, is_at_capacity: false },
                { key: 'oil', name: '石油', category: 'energy', unit: 'ten_thousand_barrels', unit_label: '万バレル', nutrition_per_unit: null, storable: true, tradable: true, amount: 123, capacity: 5000, remaining_capacity: 4877, is_at_capacity: false },
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
            if (path === '/api/v1/worlds/1/trading-post') return response({
                world: { id: 1, current_turn: 1 },
                nation: { id: 3, name: '自島', money: 62728, state: 'active' },
                permissions: { can_mutate: true },
                listings: [{
                    id: 81,
                    seller: { type: 'hakoniwa_federation', nation_id: null, name: '箱庭連合' },
                    product: {
                        type: 'item', name: '指輪', resource_key: null, unit_label: null, quantity: null,
                        item_key: 'ring', item_level: 3, rarity: 'novice', rarity_label: 'ノービス',
                        effect_text: '資金繰りの際、追加で3億円を得る。',
                    },
                    start_price: 300, current_price: 300, minimum_bid: 301, bid_count: 1,
                    highest_bidder_nation_id: 3, highest_bidder: { nation_id: 3, name: '自島' },
                    viewer_bid_status: 'highest', started_turn: 1, ends_turn: 7, remaining_turns: 6,
                    duration_turns: 6, auto_relist: false, relist_count: 0, is_mine: false,
                    can_bid: true, can_cancel: false,
                }, {
                    id: 82,
                    seller: { type: 'nation', nation_id: 7, name: '第二島' },
                    product: {
                        type: 'resource', name: '石油', resource_key: 'oil', unit_label: '万バレル',
                        quantity: 100, item_key: null, item_level: null, rarity: null, rarity_label: null, effect_text: null,
                    },
                    start_price: 100, current_price: 200, minimum_bid: 201, bid_count: 2,
                    highest_bidder_nation_id: 9, highest_bidder: { nation_id: 9, name: '第三島' },
                    viewer_bid_status: 'outbid', started_turn: 1, ends_turn: 7, remaining_turns: 6,
                    duration_turns: 6, auto_relist: false, relist_count: 0, is_mine: false,
                    can_bid: true, can_cancel: false,
                }],
                my_listings: [],
                sellable_resources: [{ id: 6, key: 'oil', name: '石油', unit_label: '万バレル', amount: 123 }],
                sellable_items: [{
                    id: 22, key: 'ring', name: '指輪', level: 3, rarity: 'novice', rarity_label: 'ノービス',
                    effect_text: '資金繰りの際、追加で3億円を得る。',
                }],
                contract: {
                    active_listing_limit: 3, minimum_duration_turns: 3, maximum_duration_turns: 84,
                    minimum_increment_money: 1, money_unit_label: '億円', npc_seller_name: '箱庭連合',
                },
            });
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
                turns_per_page: 12, has_newer_page: false, has_older_page: false,
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
        expect(headerNavigation).toContain('交易場');
        expect(headerNavigation).toContain('オプション');
        expect(headerNavigation).toContain('マニュアル');
        expect(headerNavigation).not.toContain('クレジット');
        expect(headerNavigation).not.toContain('利用ルール');
        expect(wrapper.find('.session-actions').text()).toContain('Owner');
        expect(wrapper.find('.session-actions').text()).toContain('アカウント');
        expect(wrapper.find('.session-actions').text()).not.toContain('自島');

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '交易場')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.trading-post-heading h1').text()).toBe('交易場');
        expect(wrapper.get('.trading-post-table').text()).toContain('指輪 Lv3（ノービス）');
        expect(wrapper.get('.trading-post-table').text()).toContain('箱庭連合');
        expect(wrapper.get('.trading-post-table').text()).toContain('最高額入札者：自島');
        expect(wrapper.get('.trading-post-table').text()).toContain('あなたが最高額入札中');
        expect(wrapper.get('.trading-post-table').text()).toContain('入札済み・現在は他国が最高額');
        expect(wrapper.get('.trading-post-table').text()).toContain('最高額入札者：第三島');
        expect(wrapper.get('.trading-post-table').text()).not.toContain('資金繰りの際、追加で3億円を得る。');
        expect(wrapper.get('.trading-post-table').findAll('.item-effect-info-button')).toHaveLength(1);
        const listingForm = wrapper.get('.trading-post-listing-form');
        expect(listingForm.findAll('.item-effect-info-button')).toHaveLength(0);
        await listingForm.findAll('select')[0]!.setValue('item');
        expect(listingForm.findAll('.item-effect-info-button')).toHaveLength(1);
        const effectButton = wrapper.get('.trading-post-table .item-effect-info-button');
        expect(effectButton.attributes('aria-expanded')).toBe('false');
        await wrapper.get('.trading-post-table .item-effect-info').trigger('mouseenter');
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(1);
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(0);
        expect(document.activeElement).not.toBe(effectButton.element);
        await effectButton.trigger('focus');
        expect(effectButton.attributes('aria-expanded')).toBe('true');
        expect(wrapper.get('[role="tooltip"]').text()).toBe('資金繰りの際、追加で3億円を得る。');
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await flushPromises();
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(0);
        await effectButton.trigger('click');
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(1);
        await effectButton.trigger('click');
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(0);
        await effectButton.trigger('click');
        document.body.dispatchEvent(new MouseEvent('pointerdown', { bubbles: true }));
        await flushPromises();
        expect(wrapper.findAll('[role="tooltip"]')).toHaveLength(0);
        expect(wrapper.get('.trading-post-table').text()).toContain('残り6ターン');
        expect(wrapper.get('.trading-post-capacity-note').text()).toContain('預託資金は資金上限の使用量に含まれ');
        expect(wrapper.get('.trading-post-capacity-note').text()).toContain('出品中の資源も保管容量に含まれます');
        expect(wrapper.get('.trading-post-capacity-note a').attributes('href')).toBe('/manual/trading-post');
        expect(wrapper.get('.trading-post-panel').text()).not.toContain('オークション');

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
        expect(wrapper.findAll('.hud-more-grid > section').map((section) => section.classes()[0])).toEqual([
            'resource-forecast', 'hud-support',
        ]);
        expect(wrapper.findAll('.resource-forecast thead th').map((heading) => heading.text())).toEqual([
            '資源', '生産', '消費', '予測', '所持',
        ]);
        expect(wrapper.findAll('.resource-forecast tbody tr')).toHaveLength(4);
        expect(wrapper.findAll('.resource-forecast tbody tr')[0]!.text()).toContain('食料55,00060,000−5,00012,000');
        expect(wrapper.find('.resource-forecast .forecast-positive').text()).toBe('+15,000');
        expect(wrapper.find('.resource-forecast .forecast-negative').text()).toBe('−5,000');
        expect(wrapper.find('.resource-forecast-note').text()).toBe('食料の所持は小麦換算です。');
        expect(wrapper.find('.workforce-forecast').text()).toContain('失業率 16.0%');
        expect(wrapper.find('.hud-details').text()).toContain('資金上限9,999億円');
        expect(wrapper.find('.hud-details').text()).toContain('食材上限999,900トン');
        expect(wrapper.find('.hud-details').text()).toContain('小麦10,000トン');
        expect(wrapper.find('.hud-details').text()).toContain('魚0トン');
        expect(wrapper.find('.hud-details').text()).toContain('怪獣肉0トン');
        expect(wrapper.find('.hud-details').text()).toContain('工業品上限9,999,000ユニット');
        expect(wrapper.find('.hud-details').text()).toContain('鉱物上限9,999,000トン');
        expect(wrapper.find('.hud-details').text()).toContain('石油上限5,000万バレル');
        expect(wrapper.find('.hud-more').text()).not.toContain('出来事は24ターンごとに');
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
        expect(developmentLogs).toHaveLength(1);
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
        expect(wrapper.findAll('.island-events-panel')).toHaveLength(1);
        expect(wrapper.findAll('.island-events-panel').map((panel) => panel.get('h2').text()))
            .toEqual(['島ログ']);
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

        const profileButton = wrapper.findAll('.site-header nav button').find((button) => button.text() === 'オプション')!;
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
        window.history.replaceState({}, '', '/underground');
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
            if (path === '/api/v1/me/secretary/profile' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { biography: string };
                secretary = {
                    ...secretary,
                    profile: { ...secretary.profile, biography: body.biography },
                };

                return response({ ...secretary.profile, name: secretary.name, is_owner: true });
            }
            if (path === '/api/v1/me/secretary/image-preferences' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as {
                    show_ai_generated_images: boolean;
                    own_secretary_fallback: 'silhouette' | 'peridot';
                };
                secretary = {
                    ...secretary,
                    profile: {
                        ...secretary.profile,
                        viewer_preferences: {
                            configured: true,
                            show_ai_generated_images: body.show_ai_generated_images,
                            own_secretary_fallback: body.own_secretary_fallback,
                            fallback: body.own_secretary_fallback,
                            can_update: true,
                        },
                    },
                };

                return response(secretary.profile.viewer_preferences);
            }
            if (path === '/api/v1/secretaries/11?world_id=1') {
                return response({ ...secretary.profile, name: secretary.name, is_owner: true });
            }
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);
            if (path === '/api/v1/me/underground') {
                return response({
                    stage: 'not_started', secretary_name: 'エメラルド', combat_level: 1,
                    combat_xp: 0, next_level_xp: 100, shard_balance: 0,
                    shopkeeper_name: null, battle: null,
                });
            }
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') {
                return response({
                    stage: 'initial_descent', secretary_name: secretary.name,
                    combat_level: 1, combat_xp: 0, next_level_xp: 100,
                    shard_balance: 0, shopkeeper_name: null, battle: null,
                });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(window.location.pathname).toBe('/');
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(true);
        const secretaryButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === '？？？')!;
        expect(secretaryButton.exists()).toBe(true);

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
        expect(wrapper.get('.secretary-main-profile').text()).toContain('内政Lv1');
        expect(wrapper.get('.secretary-main-profile').text()).toContain('資金・食糧最大+1%');
        expect(wrapper.get('.secretary-main-profile').text()).toContain('討伐経験値0');
        expect(wrapper.get('.secretary-no-image').text()).toBe('No image');
        expect(wrapper.get('.secretary-image-preference-notice').text()).toContain('秘書画像設定が未設定です');
        await wrapper.get('.secretary-image-preference-notice button').trigger('click');
        expect(wrapper.get('.secretary-profile-modal').text()).toContain('閲覧するAI生成画像');
        expect(wrapper.get('.secretary-profile-modal').text()).toContain('自分の秘書が画像未設定のとき');
        await wrapper.get('.secretary-profile-modal form').trigger('submit');
        await flushPromises();
        const imagePreferenceRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/image-preferences' && init?.method === 'PATCH'
        ));
        expect(JSON.parse(String(imagePreferenceRequest?.[1]?.body))).toEqual({
            show_ai_generated_images: true,
            own_secretary_fallback: 'silhouette',
        });
        await wrapper.get('.secretary-biography textarea').setValue('更新した経歴');
        await wrapper.get('.secretary-biography form').trigger('submit');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/secretaries/11?world_id=1')).toBe(true);
        expect(wrapper.get<HTMLTextAreaElement>('.secretary-biography textarea').element.value).toBe('更新した経歴');
        const initialTabs = wrapper.findAll('[role="tab"]');
        expect(initialTabs.map((tab) => tab.text())).toEqual(['メイン', '熟練度', '装備', '倉庫']);
        await initialTabs[1]!.trigger('click');
        expect(wrapper.get('.secretary-section-title').text()).toBe('パッシブスキル');
        const skillRows = wrapper.findAll('.secretary-skill');
        expect(skillRows).toHaveLength(7);
        const agriculturalSkill = skillRows[0]!;
        const defenseSkill = skillRows[4]!;
        expect(agriculturalSkill.get('.secretary-skill-name').text()).toBe('農業政策');
        expect(agriculturalSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv0', 'XP 0 / 1']);
        expect(agriculturalSkill.get('.secretary-skill-effect').text()).toBe('小麦生産＋0.0%');
        expect(defenseSkill.get('.secretary-skill-name').text()).toBe('最終防衛ライン');
        expect(defenseSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv1', 'XP 0 / 100']);
        expect(defenseSkill.get('.secretary-skill-effect').text()).toBe('防衛されなかったミサイルを1ターンにつき1発まで迎撃');
        expect(skillRows[5]!.get('.secretary-skill-effect').text()).toBe('自然人口上限 +500人 / 誘致人口上限 +1,000人');
        expect(skillRows[6]!.get('.secretary-skill-effect').text()).toBe('自然人口増加 +2.50%');
        expect(wrapper.get('.secretary-skills').text()).not.toContain('次のlevelまで');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'ペリドット')).toBe(true);

        const secretaryGetCount = () => fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/secretary?world_id=1').length;
        const beforeTabSwitch = secretaryGetCount();
        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.text())).toEqual(['メイン', '熟練度', '装備', '倉庫']);
        expect(tabs[1]!.attributes('aria-selected')).toBe('true');
        await tabs[1]!.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.findAll('[role="tab"]')[2]!.attributes('aria-selected')).toBe('true');
        await wrapper.findAll('[role="tab"]')[2]!.trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true');
        await tabs[2]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment li')).toHaveLength(5);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('古びた弓');
        expect(wrapper.findAll('.secretary-equipment li').slice(1).every((slot) => slot.text().includes('空き'))).toBe(true);
        await wrapper.findAll('[role="tab"]')[3]!.trigger('click');
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
            .find((button) => button.text() === 'オプション')!.trigger('click');
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
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'エメラルド')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-underground-entry button').text()).toBe('地下へ');
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('あなたの秘書は、暗く狭い場所で目を覚ました。');
        const undergroundRequest = fetchMock.mock.calls.find(([path]) => (
            String(path) === '/api/v1/me/underground/entry'
        ));
        expect(JSON.parse(String(undergroundRequest?.[1]?.body))).toEqual({
            request_id: expect.any(String),
        });
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'TOP')!.trigger('click');
        await flushPromises();
        expect(window.location.pathname).toBe('/');
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'エメラルド')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        window.history.pushState({ page: 'secretary' }, '', '/');
        window.dispatchEvent(new PopStateEvent('popstate', { state: { page: 'secretary' } }));
        await flushPromises();
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(true);
    });

    it('replaces the Underground history entry when escape completion redirects to Secretary', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        const returnedState = {
            stage: 'returned_after_tutorial', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 0,
            shopkeeper_name: null, battle: null,
        };
        const escapeState = {
            ...returnedState,
            stage: 'escape_pending',
            battle: {
                id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
                encounter_name: 'ジャイアントラット', result: 'victory', rounds: 1,
                xp_awarded: 5, shard_delta: 0, detail_available: true,
                actions: [{ round: 1, side: 'player', action_label: '連続斬り', amount: 90 }],
            },
        };
        const replaceSpy = vi.spyOn(window.history, 'replaceState');
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/underground') return response(escapeState);
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') return response(returnedState);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/');
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(true);
        expect(replaceSpy).toHaveBeenCalledWith({ page: 'secretary' }, '', '/');
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/underground/story/advance')).toHaveLength(1);
    });

    it('shows the unlocked Underground projection with disabled future entries and escaped battle history', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        serverSecretary.underground = {
            available: true, stage: 'underground_open', combat_level: 1,
            combat_xp: 5, next_level_xp: 100,
        };
        const growthPath = {
            key: 'guardianship_blue', label: '護身', color: 'blue',
            description: ['粘り強い戦いを求める成長方針。'], default_build_key: 'pure_tank',
            stats: { vitality: 40, might: 20, finesse: 15, spirit: 15, agility: 10 },
            max_hp: 660, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 2, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: 5, points_per_level: 10,
        };
        const playtest = {
            notice: 'α版の戦闘検証用完成形ビルドです。正式な育成・装備状態ではありません。',
            default_build_key: 'pure_tank',
            builds: [
                { key: 'pure_attacker', label: '攻撃特化', description: '短期戦' },
                { key: 'pure_tank', label: '護身特化', description: '防御判断' },
                { key: 'pure_healer', label: '奇跡特化', description: '回復' },
                { key: 'balanced', label: '混成', description: '混成' },
            ],
            enemies: [
                { key: 'depth_stalker', label: '深層追跡者', description: '通常の適正戦闘' },
                { key: 'pressure_construct', label: '予兆を放つ圧力試験体', description: '防御判断' },
                { key: 'crystal_warden', label: '輝晶守護者', description: 'boss' },
            ],
        };
        let openState = {
            stage: 'underground_open', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, next_level_requirement: 100, xp_to_next_level: 95,
            shard_balance: 2350, banked_shard_balance: 5000, current_hp: 321, unspent_stp: 0,
            allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 },
            current_stats: growthPath.stats,
            combat_stats: { vitality: 41, might: 21, finesse: 16, spirit: 16, agility: 11 },
            starter_weapon: { key: 'starter_knife', label: '護身用ナイフ', item_level: 1, rarity: 'common' },
            shopkeeper_name: '<b>店員</b>', true_name_branch: false,
            tutorial_projection: { stats: { vitality: 10, might: 10, finesse: 10, spirit: 10, agility: 10 }, weapon: 'starter knife' },
            contract_completed: true, growth_paths: null, growth_path: growthPath, playtest, battle: null,
        };
        const summary = {
            id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
            player_display_name: '過去のペリドット',
            encounter_name: '<b>ジャイアントラット</b>', result: 'victory', rounds: 2,
            xp_awarded: 5, shard_delta: 0, detail_available: true, actions: null,
        };
        const playtestBattle = {
            id: '44444444-4444-4444-8444-444444444444', context: 'playtest',
            player_display_name: '過去のペリドット',
            build_name: '護身特化', encounter_name: '深層追跡者', result: 'victory', rounds_count: 2,
            xp_awarded: 0, shard_delta: 0, detail_available: true,
            summary: { damage_prevented: 120, final_mp: 9700 },
            rounds: [
                {
                    round: 1,
                    actions: [{ type: 'decision', side: '秘書', label: '防御', reason: 'priority_rule_0' }],
                    end_state: {
                        player: { hp: 650, max_hp: 660, mp: 9700, barrier: 40, statuses: [], role_stacks: { fighting_spirit: 1, grace: 0 } },
                        enemy: { hp: 100, max_hp: 200, mp: 0, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                },
                {
                    round: 2,
                    actions: [
                        {
                            type: 'status_applied', side: '秘書', actor_name: '過去のペリドット',
                            target_name: '深層追跡者', label: '付与: 出血', amount: 0,
                        },
                        {
                            type: 'status_resisted', side: '対戦相手', actor_name: '深層追跡者',
                            target_name: '過去のペリドット', label: '抵抗: 鈍足', amount: 0,
                        },
                    ],
                    end_state: {
                        player: { hp: 640, max_hp: 660, mp: 9700, barrier: 20, statuses: [], role_stacks: { fighting_spirit: 2, grace: 1 } },
                        enemy: { hp: 0, max_hp: 200, mp: 0, barrier: 0, statuses: [{ label: '出血', remaining: 1, stacks: 1 }], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                },
            ],
            rewards: { xp: 0, shards: 0, g: 0, drops: [] },
        };
        const explorationBattle = {
            id: '55555555-5555-4555-8555-555555555555', context: 'exploration',
            player_display_name: 'ペリドット', encounter_name: '輝石虫', result: 'victory',
            rounds_count: 1, xp_awarded: 1150, shard_delta: 0, detail_available: true,
            combat_level_before: 1, combat_level_after: 6, stp_awarded: 25, unspent_stp_after: 25,
            summary: { damage_dealt: 1, damage_received: 0 },
            rounds: [{
                round: 1,
                actions: [
                    { type: 'damage', side: '秘書', actor_name: 'ペリドット', target_name: '輝石虫', label: '通常攻撃', amount: 0, complete_guarded: true },
                    { type: 'damage', side: '秘書', actor_name: 'ペリドット', target_name: '輝石虫', label: '通常攻撃', amount: 1, complete_guarded: false },
                ],
                end_state: {
                    player: { hp: 660, max_hp: 660, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    enemy: { hp: 0, max_hp: 1, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                },
            }],
            rewards: { xp: 1150, shards: 0 },
        };
        let battleDetailGets = 0;
        let bankTransferAttempts = 0;
        const bankTransferResults = new Map<string, typeof openState>();
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/underground') return response(openState);
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') return response(openState);
            if (path === '/api/v1/me/underground/explore' && init?.method === 'POST') return response(explorationBattle);
            if (path === '/api/v1/me/underground/inn/rest' && init?.method === 'POST') {
                openState = { ...openState, shard_balance: openState.shard_balance - 10, current_hp: growthPath.max_hp };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/bank/transfer' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as {
                    request_id: string;
                    action: string;
                    amount?: number;
                };
                const duplicate = bankTransferResults.get(payload.request_id);
                if (duplicate) {
                    openState = duplicate;
                    return response(openState);
                }
                const amount = payload.action === 'deposit_all'
                    ? openState.shard_balance
                    : payload.action === 'withdraw_all'
                        ? openState.banked_shard_balance
                        : payload.amount ?? 0;
                const deposit = payload.action.startsWith('deposit');
                openState = {
                    ...openState,
                    shard_balance: openState.shard_balance + (deposit ? -amount : amount),
                    banked_shard_balance: openState.banked_shard_balance + (deposit ? amount : -amount),
                };
                bankTransferResults.set(payload.request_id, openState);
                bankTransferAttempts++;
                if (bankTransferAttempts === 1) throw new TypeError('Failed to fetch');
                return response(openState);
            }
            if (path === '/api/v1/me/underground/playtest' && init?.method === 'POST') return response(playtestBattle);
            if (path === '/api/v1/me/underground/battles') return response([summary]);
            if (path === `/api/v1/me/underground/battles/${summary.id}`) {
                battleDetailGets++;
                if (battleDetailGets > 1) {
                    return new Response(JSON.stringify({ message: '戦闘ログを読み込めませんでした。' }), {
                        status: 500,
                        headers: { 'Content-Type': 'application/json' },
                    });
                }
                return response({
                    ...summary,
                    actions: [{ round: 1, side: 'player', action: 'quick_slash', action_label: '連続斬り', amount: 90 }],
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('内政Lv');
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('戦闘Lv1');
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();

        expect(wrapper.find('.underground-main-layout').exists()).toBe(true);
        expect(wrapper.find('.underground-character-pane').exists()).toBe(true);
        expect(wrapper.find('.underground-action-pane').exists()).toBe(true);
        expect(wrapper.get('.underground-summary').text()).toContain('戦闘Lv1');
        expect(wrapper.get('.underground-summary').text()).toContain('経験値5 / 100');
        expect(wrapper.get('.underground-summary').text()).toContain('HP321 / 660');
        expect(wrapper.get('.underground-summary').text()).toContain('戦闘開始MP10000 / 10000');
        expect(wrapper.get('.underground-summary').text()).toContain('未使用STP0');
        expect(wrapper.get('.underground-equipment').text()).toContain('武器護身用ナイフ');
        expect(wrapper.get('#underground-guide-title').text()).toContain('<b>店員</b>');
        expect(wrapper.get('#underground-guide-title').find('b').exists()).toBe(false);
        expect(wrapper.findAll('.underground-entries button')).toHaveLength(2);
        expect(wrapper.findAll('.underground-entries button')[0]!.attributes('disabled')).toBeUndefined();
        expect(wrapper.findAll('.underground-entries button')[1]!.attributes('disabled')).toBeDefined();
        await wrapper.findAll('.underground-entries button')[0]!.trigger('click');
        await flushPromises();
        const explorationRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/underground/explore' && init?.method === 'POST'
        ));
        expect(JSON.parse(String(explorationRequest?.[1]?.body))).toEqual({ request_id: expect.any(String) });
        const explorationLog = wrapper.get('.underground-battle-log').text();
        expect(explorationLog).toContain('輝石虫は完全防御し、HPダメージは0。');
        expect(explorationLog.indexOf('Round 1')).toBeLessThan(explorationLog.indexOf('戦闘終了'));
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +1150・輝石の欠片 +0G');
        expect(wrapper.get('.underground-battle-result').text()).toContain('戦闘Lv 1 → 6・未使用STP +25（合計 25）');
        await wrapper.get('.underground-battle-back').trigger('click');
        expect(wrapper.get('.underground-shop').text()).toContain('あなたのコンビニ、箱庭ダンジョン店です！');
        expect(wrapper.findAll('.underground-shop-entries button')).toHaveLength(4);
        expect(wrapper.findAll('.underground-shop-entries button').map((button) => button.attributes('disabled') !== undefined))
            .toEqual([false, true, false, true]);
        await wrapper.findAll('.underground-shop-entries button')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-summary').text()).toContain('HP660 / 660');
        const innRequest = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/me/underground/inn/rest');
        expect(JSON.parse(String(innRequest?.[1]?.body))).toEqual({ request_id: expect.any(String) });
        await wrapper.findAll('.underground-shop > .underground-shop-entries button')[2]!.trigger('click');
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 2340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 5000 G');
        await wrapper.get('#underground-bank-amount').setValue('2000');
        await wrapper.findAll('.underground-bank .underground-shop-entries button')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Failed to fetch');
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 2340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 5000 G');
        const failedBankRequest = fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/underground/bank/transfer'
        ));
        expect(failedBankRequest).toHaveLength(1);
        const failedBankPayload = JSON.parse(String(failedBankRequest[0]?.[1]?.body)) as {
            request_id: string;
            action: string;
            amount: number;
        };
        await wrapper.findAll('.underground-bank .underground-shop-entries button')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 7000 G');
        const bankRequests = fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/underground/bank/transfer'
        ));
        expect(bankRequests).toHaveLength(2);
        expect(JSON.parse(String(bankRequests[1]?.[1]?.body))).toEqual({
            request_id: failedBankPayload.request_id, action: 'deposit', amount: 2000,
        });
        const historyButton = wrapper.get('.underground-history li button');
        expect(historyButton.text()).toContain('<b>ジャイアントラット</b>');
        expect(historyButton.find('b').exists()).toBe(false);
        await historyButton.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-opening').text()).toContain('<b>ジャイアントラット</b>');
        expect(wrapper.get('.underground-battle-opening').text()).toContain('過去のペリドットは戦闘を開始した。');
        expect(wrapper.get('.underground-battle-opening').text()).not.toContain('勝利');
        expect(wrapper.get('.underground-battle-log').text()).toContain('連続斬り');
        expect(wrapper.get('.underground-battle-log').text()).not.toContain('quick_slash');
        expect(wrapper.get('.underground-battle-result').text()).toContain('勝利');
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +5・輝石の欠片 +0');
        expect(wrapper.get('.underground-log-jump').text()).toBe('末尾へ');
        await wrapper.get('.underground-battle-back').trigger('click');
        await wrapper.get('.underground-history li button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('戦闘ログを読み込めませんでした。');
        expect(wrapper.get('.underground-battle-log').text()).toContain('<b>ジャイアントラット</b>');
        await wrapper.get('.underground-battle-back').trigger('click');
        expect(wrapper.get('.underground-playtest').text()).toContain('正式な育成・装備状態ではありません');
        expect(wrapper.get<HTMLSelectElement>('#underground-build').element.value).toBe('pure_tank');
        await wrapper.get('.underground-playtest .button').trigger('click');
        await flushPromises();
        const playtestRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/underground/playtest' && init?.method === 'POST'
        ));
        expect(JSON.parse(String(playtestRequest?.[1]?.body))).toEqual({
            request_id: expect.any(String), build_key: 'pure_tank', enemy_key: 'depth_stalker',
        });
        expect(wrapper.get('.underground-battle-log').text()).toContain('過去のペリドットは「防御」を使用した。');
        expect(wrapper.get('.underground-battle-log').text()).not.toContain('priority_rule_0');
        expect(wrapper.get('.underground-combat-summary').text()).toContain('防いだダメージ120');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('ラウンド数');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('残HP');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('最終MP');
        expect(wrapper.findAll('.underground-vitals progress.hp')).toHaveLength(4);
        expect(wrapper.findAll('.underground-vitals progress.mp')).toHaveLength(4);
        expect(wrapper.findAll('.underground-round')).toHaveLength(2);
        expect(wrapper.findAll('.underground-round')[0]!.text()).toContain('Round 1');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('Round 2');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('深層追跡者に出血が付与された。');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('過去のペリドットは鈍足を防いだ。');
        expect(wrapper.findAll('.underground-combatant-state')[0]!.get('strong').text()).toBe('過去のペリドット');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('出血 残1・1段階');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('闘志 2・恩寵 1');
        expect(wrapper.find('.underground-round-viewer').exists()).toBe(false);
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +0・輝石の欠片 +0G・ドロップなし');
    });

    it('returns to the Secretary when a concurrent escape already advanced the persisted stage', async () => {
        const battle = {
            id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
            encounter_name: 'ジャイアントラット', result: 'victory', rounds: 2,
            xp_awarded: 5, shard_delta: 0, detail_available: true,
            actions: [{ round: 1, side: 'player', action: 'quick_slash', amount: 90 }],
        };
        const escapeState = {
            stage: 'escape_pending', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 0,
            shopkeeper_name: null, battle,
        };
        const returnedState = { ...escapeState, stage: 'returned_after_tutorial', battle: null };
        let stateGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground') {
                stateGets++;
                return response(stateGets === 1 ? escapeState : returnedState);
            }
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') return response(null, 409);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('returnToSecretary')).toHaveLength(1);
    });

    it('renders the story battle outcome as a penalty-free player defeat', async () => {
        const lossState = {
            stage: 'special_loss_complete', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 9,
            shopkeeper_name: 'ダミー',
            battle: {
                id: '22222222-2222-4222-8222-222222222222', context: 'scripted_loss',
                encounter_name: '（ダミー）', result: 'defeat', rounds: 1,
                xp_awarded: 0, shard_delta: 0, detail_available: true,
                actions: [{ round: 1, side: 'enemy', action: 'heavy_strike', amount: 999 }],
            },
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            if (String(input) === '/api/v1/me/underground') return response(lossState);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        expect(wrapper.get('.underground-battle-result').text()).toContain('敗北');
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +0・輝石の欠片 +0');
    });

    it('completes the normal first-player flow from tutorial through shop explanation and main unlock', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let stage = 'not_started';
        let combatXp = 0;
        let shopkeeperName: string | null = null;
        let battle: Record<string, unknown> | null = null;
        let contractCompleted = false;
        let growthPath: Record<string, unknown> | null = null;
        const pathFixture = (key: string, label: string, color: string, build: string) => ({
            key, label, color, description: [`${label}の説明`], default_build_key: build,
            stats: { vitality: 40, might: 20, finesse: 15, spirit: 15, agility: 10 },
            max_hp: 660, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 2, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: 5, points_per_level: 10,
        });
        const growthPaths = [
            pathFixture('martial_red', '戦技', 'red', 'pure_attacker'),
            pathFixture('guardianship_blue', '護身', 'blue', 'pure_tank'),
            pathFixture('blessing_green', '祝福', 'green', 'pure_healer'),
            pathFixture('free_black', '自由', 'black', 'balanced'),
        ];
        const projection = () => ({
            stage,
            secretary_name: 'ペリドット',
            combat_level: 1,
            combat_xp: combatXp,
            next_level_xp: 100,
            shard_balance: 0,
            shopkeeper_name: shopkeeperName,
            true_name_branch: false,
            tutorial_projection: { stats: { vitality: 10, might: 10, finesse: 10, spirit: 10, agility: 10 }, weapon: 'starter knife' },
            contract_completed: contractCompleted,
            growth_paths: stage === 'crystal_selection' ? growthPaths : null,
            growth_path: growthPath,
            playtest: stage === 'underground_open' ? {
                notice: 'α版の戦闘検証用完成形ビルドです。正式な育成・装備状態ではありません。',
                default_build_key: 'pure_tank', builds: [], enemies: [],
            } : null,
            battle,
        });
        const tutorialBattle = {
            id: '33333333-3333-4333-8333-333333333333', context: 'tutorial',
            encounter_name: 'ジャイアントラット', result: 'victory', rounds: 1,
            xp_awarded: 5, shard_delta: 0, detail_available: true,
            actions: [{ round: 1, side: 'player', action: 'quick_slash', amount: 90 }],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                serverSecretary.underground = {
                    available: true, stage, combat_level: 1,
                    combat_xp: combatXp, next_level_xp: 100,
                };
                return response(serverSecretary);
            }
            if (path === '/api/v1/me/underground' && init?.method === undefined) return response(projection());
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') {
                stage = stage === 'not_started' ? 'initial_descent' : 'shopkeeper_encounter';
                battle = null;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/tutorial' && init?.method === 'POST') {
                stage = 'escape_pending';
                combatXp = 5;
                battle = tutorialBattle;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') {
                const action = String(JSON.parse(String(init.body)).action);
                const transitions: Record<string, string> = {
                    initial_story_complete: 'tutorial_ready',
                    escape_complete: 'returned_after_tutorial',
                    shopkeeper_encounter_complete: 'shopkeeper_naming',
                    shop_explanation_complete: 'contract_ready',
                    growth_path_story_complete: 'underground_open',
                };
                stage = transitions[action] ?? stage;
                if (stage !== 'escape_pending') battle = null;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/shopkeeper/name' && init?.method === 'POST') {
                shopkeeperName = String(JSON.parse(String(init.body)).name).trim();
                stage = 'shop_explanation';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/contract' && init?.method === 'POST') {
                contractCompleted = true;
                stage = 'crystal_selection';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/growth-path' && init?.method === 'POST') {
                const key = String(JSON.parse(String(init.body)).growth_path_key);
                growthPath = growthPaths.find((candidate) => candidate.key === key) ?? null;
                stage = 'growth_path_selected';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/battles') return response([tutorialBattle]);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.underground-story').text()).toContain('首都に核シェルターを作るための工事');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-panel h1').text()).toBe('ジャイアントラット');
        expect(wrapper.find('.underground-tutorial-stats').exists()).toBe(false);
        await wrapper.get('.underground-battle-preview .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-result').text()).toContain('勝利');
        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('戦闘Lv1');

        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('また来ましたね');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        await wrapper.get('#underground-shopkeeper-name').setValue('通常店員');
        await wrapper.get('.underground-name-form').trigger('submit');
        await flushPromises();
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-contract').text()).toBe('契約する');
        await wrapper.get('.underground-contract').trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.underground-growth-card')).toHaveLength(4);
        expect(wrapper.get('.underground-growth-grid').text()).toContain('Lv2以降: 自然成長 5 / 未使用STP +5');
        await wrapper.findAll('.underground-growth-card .button')[1]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('ふふ、とってもお似合いですよ、その能力');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();

        expect(wrapper.get('#underground-guide-title').text()).toContain('通常店員');
        expect(wrapper.get('.underground-summary').text()).toContain('経験値5 / 100');
        expect(wrapper.find('.underground-currency-note').exists()).toBe(false);
        expect(wrapper.get('.underground-summary').text()).toContain('HP660 / 660');
        expect(wrapper.get('.underground-summary').text()).toContain('MP10000 / 10000');
        expect(wrapper.get('.underground-growth-summary').text()).toContain('自然回復 300 MP / ラウンド');
        const adventureButtons = wrapper.findAll('.underground-entries button');
        expect(adventureButtons[0]?.attributes('disabled')).toBeUndefined();
        expect(adventureButtons[1]?.attributes('disabled')).toBeDefined();
        expect(stage).toBe('underground_open');
    });

    it('keeps a Secretary load failure visible instead of treating it as an absent Secretary', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                return new Response(JSON.stringify({ message: 'Secretaryを読み込めませんでした。' }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.text()).toContain('Secretaryを読み込めませんでした。');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === '？？？')).toBe(false);
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
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
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
            .find((button) => button.text() === 'オプション')!.trigger('click');
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
        await wrapper.findAll('[role="tab"]')[3]!.trigger('click');

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
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment button')).toHaveLength(5);
        expect(wrapper.get('.equipment-category-limits').text()).toContain('弓・1個まで');
        expect(wrapper.get('.equipment-category-limits').text()).toContain('衣服・1個まで');
        expect(wrapper.get('.equipment-category-limits').text()).not.toContain('アクセサリー');
        expect(wrapper.get('.equipment-category-limits').text()).not.toContain('99');
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

    it('places neutral manual dormancy above the unchanged red abandonment operation and shows the active term', async () => {
        const dormantNation: Nation = {
            ...ownerNationFixture,
            state: 'dormant', state_label: '放置', state_reason: 'manual', state_started_turn: 1,
            resume_at_turn: 86, manual_dormancy_days: 7, dormancy_remaining_turns: 84,
            dormancy_remaining_days: 7, abandonment_remaining_turns: 160,
            can_request_dormancy: false, winter_theme_active: true, activity_status: 'dormant',
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/nations/3/dormancy' && init?.method === 'POST') return response(dormantNation);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        const profileButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'オプション')!;
        await profileButton.trigger('click');
        expect(wrapper.findAll('.danger-zone h3').map((heading) => heading.text())).toEqual([
            '島を休止する', '島を破棄する',
        ]);
        const dormancyButton = wrapper.get<HTMLButtonElement>('.dormancy-block button');
        expect(dormancyButton.classes()).toContain('secondary');
        expect(dormancyButton.classes()).not.toContain('danger');
        expect(wrapper.get('.abandonment-block button').classes()).toContain('danger');
        await wrapper.get('#dormancy-days').setValue('7');
        await wrapper.get('.dormancy-form').trigger('submit');
        await flushPromises();

        const request = fetchMock.mock.calls.find(([path]) => String(path) === '/api/v1/nations/3/dormancy');
        expect(JSON.parse(String(request?.[1]?.body))).toEqual({ days: 7 });
        const status = wrapper.get('.dormancy-block');
        expect(status.text()).toContain('現在休止中');
        expect(status.text()).toContain('指定期間7日');
        expect(status.text()).toContain('再開予定turnTurn 86');
        expect(status.text()).toContain('残りturn / 日数84 turn / 約7日');
        expect(status.text()).toContain('指定期間が終わるまで解除できません');
        expect(wrapper.get<HTMLButtonElement>('.abandonment-block button').element.disabled).toBe(true);

        wrapper.unmount();
        const automaticDormantNation: Nation = {
            ...dormantNation,
            state_reason: 'idle', resume_at_turn: null, manual_dormancy_days: null,
            dormancy_remaining_turns: null, dormancy_remaining_days: null,
        };
        const automaticFetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [] });
            if (path === '/api/v1/me/nation') return response(automaticDormantNation);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', automaticFetchMock);
        const automaticWrapper = mount(App);
        await flushPromises();
        const automaticProfileButton = automaticWrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'オプション')!;
        await automaticProfileButton.trigger('click');
        expect(automaticWrapper.get('.dormancy-status').text())
            .toContain('再開予定turn通常command登録後の次official Turn');
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
            .find((button) => button.text() === 'オプション')!;
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
            .find((button) => button.text() === 'オプション')!;
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
            .find((button) => button.text() === 'オプション')!;
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
            .find((button) => button.text() === 'オプション')!;
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
