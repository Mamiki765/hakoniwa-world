import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import HexMap from './components/HexMap.vue';
import TradingPostPanel from './components/TradingPostPanel.vue';
import type { Nation, PublicNationDetail, TradingPostData, TradingPostListing } from './types';
import { response, envelopeResponse, validationResponse, emptyChunk, publicDetail, resourceForecastFixture, ownerNationFixture, surfaceCellFixture, undergroundSurfaceMapFixture, unnamedSecretaryFixture, publicResponse, installAppTestLifecycle } from './AppTestHarness';

installAppTestLifecycle();

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
        expect(wrapper.find('.app-version').text()).toBe('ver 3.0.0');
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

    it('renders recovery and KARMA while keeping zero and negative values unaccented', async () => {
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
        let undergroundMapCalls = 0;
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
            if (path === '/api/v1/me/underground/surface-map') {
                undergroundMapCalls++;
                const nextMap = structuredClone(undergroundSurfaceMapFixture);
                if (undergroundMapCalls > 1) {
                    nextMap.layers[0]!.slots[0]!.facility_key = 'underground_city';
                    nextMap.layers[0]!.slots[0]!.asset_key = 'underground.city';
                }

                return response(nextMap);
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
        await wrapper.findAll('.underground-slot')[0]!.trigger('click');
        await flushPromises();
        const initialChunkCalls = privateChunkCalls;

        await vi.advanceTimersByTimeAsync(1_000);
        await flushPromises();

        expect(summaryCalls).toBe(2);
        expect(nationCalls).toBe(2);
        expect(mapSpaceCalls).toBe(2);
        expect(privateChunkCalls).toBeGreaterThan(initialChunkCalls);
        expect(ownerEventCalls).toBe(2);
        expect(undergroundMapCalls).toBe(2);
        expect(wrapper.findAll('.underground-slot')[0]!.attributes('aria-label')).toContain('地底都市');
        expect(wrapper.findAll('.underground-slot')[0]!.attributes('aria-pressed')).toBe('true');
        expect(wrapper.find('.underground-target-summary').text()).toContain('建築済み施設枠');
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
            body_format: 'plain_text', body_html: null as string | null,
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
            if (path === '/api/v1/admin/announcements/preview') {
                return response({ body_html: '<p><strong>一行目</strong><br />二行目</p>' });
            }
            if (path === '/api/v1/admin/announcements' && init?.method === 'POST') {
                article = { ...article, ...JSON.parse(String(init.body)) as { title: string; body: string } };
                article.body_html = '<p><strong>一行目</strong><br />二行目</p>';
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
        expect(wrapper.get<HTMLSelectElement>('.announcement-form select').element.value).toBe('markdown');
        await wrapper.find('.announcement-form textarea').setValue('**一行目**\n二行目');
        await wrapper.findAll('.announcement-actions button').find(button => button.text() === 'プレビュー')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.announcement-preview strong').text()).toBe('一行目');
        expect(fetchMock.mock.calls.some(([path, init]) => String(path) === '/api/v1/admin/announcements' && init?.method === 'POST')).toBe(false);
        await wrapper.find('.announcement-form textarea').setValue('修正中');
        expect(wrapper.find('.announcement-preview').exists()).toBe(false);
        await wrapper.find('.announcement-form textarea').setValue('**一行目**\n二行目');
        await wrapper.find('.announcement-form').trigger('submit');
        await flushPromises();
        const post = fetchMock.mock.calls.find(([path, init]) => String(path) === '/api/v1/admin/announcements' && init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ title: '作成した記事', body: '**一行目**\n二行目', body_format: 'markdown' });
        expect(wrapper.get('.announcement-article strong').text()).toBe('一行目');

        const edit = wrapper.findAll('.announcement-actions button').find((button) => button.text() === '編集')!;
        await edit.trigger('click');
        expect(wrapper.get<HTMLTextAreaElement>('.announcement-form textarea').element.value).toBe('**一行目**\n二行目');
        expect(wrapper.get<HTMLSelectElement>('.announcement-form select').element.value).toBe('markdown');
        await wrapper.find('.announcement-form input').setValue('編集した記事');
        await wrapper.find('.announcement-form select').setValue('plain_text');
        await wrapper.find('.announcement-form').trigger('submit');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([path, init]) => String(path).endsWith('/admin/announcements/8') && init?.method === 'PATCH')).toBe(true);
        expect(wrapper.get('.announcement-body').text()).toContain('**一行目**');
        expect(wrapper.find('.announcement-body strong').exists()).toBe(false);

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
        const scrollTo = vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
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
            underground_surface_map: undergroundSurfaceMapFixture,
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
                battle_display_name: '公開秘書',
                is_owner: false,
                combat_level: 7,
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
        expect(scrollTo).toHaveBeenCalledWith({ top: 0, left: 0, behavior: 'auto' });
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
        expect(wrapper.find('.command-workspace').exists()).toBe(false);
        const publicUndergroundMap = wrapper.get('.preview-page > .underground-map-card');
        expect(publicUndergroundMap.findAll('.underground-layer-row')).toHaveLength(2);
        expect(publicUndergroundMap.text()).not.toContain('(X-2, Y, -2)');
        await publicUndergroundMap.find('.underground-slot').trigger('click');
        expect(publicUndergroundMap.get('.underground-map-detail').text()).toContain('座標(10, 8, -2)');
        expect(wrapper.find('.preview-page > .message-board').exists()).toBe(true);
        const previewMap = wrapper.get('.preview-page > .preview-grid').element;
        const previewUnderground = publicUndergroundMap.element;
        const previewBoard = wrapper.get('.preview-page > .message-board').element;
        const previewLog = wrapper.get('.preview-page > .island-events-panel').element;
        expect(previewMap.compareDocumentPosition(previewUnderground) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(previewUnderground.compareDocumentPosition(previewBoard) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(previewBoard.compareDocumentPosition(previewLog) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(fetchMock.mock.calls.some(([path]) => String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toBe(true);

        await wrapper.get('.preview-secretary-link').trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-name').text()).toBe('公開秘書');
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('戦闘Lv7');
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

    it('retries an ambiguous compensation claim with the same request id and keeps refresh failure separate from settlement', async () => {
        vi.useFakeTimers();
        let claimed = false;
        let claimAttempts = 0;
        const grant = {
            id: 41,
            grant_key: 'incident-test-owner-1',
            expires_at: '2027-09-09T12:00:00+09:00',
            remaining_days: 365,
            reason: '今回のお詫びです。',
            status: 'pending' as const,
            claimed_at: null,
            items: [
                { asset_key: 'money' as const, label: '資金', unit: '億円', amount: 1234, claimed_amount: 0, remaining_amount: 1234 },
                { asset_key: 'skip_ticket' as const, label: 'スキップチケット', unit: '枚', amount: 1000, claimed_amount: 0, remaining_amount: 1000 },
            ],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') {
                if (claimAttempts >= 2) throw new TypeError('account refresh failed');
                return response({
                id: 1,
                display_name: 'Owner',
                paradox: { name: '輝石', unit: 'Pd', description: '説明', balance: 0 },
                can_manage_announcements: false,
                can_manage_inquiries: false,
                providers: [],
                });
            }
            if (path === '/api/v1/me/daily-login') return response({
                awarded_now: false, canonical_day: '2026-09-09', paradox_awarded: 0, skip_tickets_awarded: 0,
                paradox: { name: '輝石', unit: 'Pd', description: '説明', balance: 0 }, skip_ticket_balance: 0,
            });
            if (path === '/api/v1/me/nation') {
                if (claimAttempts >= 2) throw new TypeError('nation refresh failed');
                return response(ownerNationFixture);
            }
            if (path === '/api/v1/me/secretary?world_id=1') return response(null);
            if (path === '/api/v1/worlds/1/map-spaces') return response([{
                id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59',
                bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 },
            }]);
            if (path === '/api/v1/me/underground/surface-map') return response(null);
            if (path.includes('/api/v1/map-spaces/2/chunks/')) return response(emptyChunk);
            if (path === '/api/v1/me/compensation-grants') {
                if (claimAttempts >= 2) throw new TypeError('warehouse refresh failed');
                return response(claimed ? [] : [grant]);
            }
            if (path === '/api/v1/me/compensation-grants/41/claim' && init?.method === 'POST') {
                claimAttempts++;
                claimed = true;
                if (claimAttempts === 1) throw new TypeError('claim response lost');
                return response({
                    grant: { ...grant, status: 'claimed', claimed_at: '2026-09-09T12:00:00+09:00' },
                    applied_now: [
                        { asset_key: 'money', applied: 1234, remaining: 0 },
                        { asset_key: 'skip_ticket', applied: 1000, remaining: 0 },
                    ],
                    already_claimed: false,
                    duplicate: true,
                });
            }
            if (path === '/api/v1/me/daily-quests/development-opened') return response({
                key: 'development_opened', label: '開発画面を開く', canonical_day: '2026-09-09', progress: 1,
                target: 1, paradox_awarded: 0, completed: true, completed_now: false, paradox_balance: 0,
            });
            if (path.includes('command-definitions')) return response({
                commands: [], paradox: { name: '輝石', unit: 'Pd', description: '説明', balance: 0 },
                quantity_contract: { type: 'integer', minimum: 1, maximum: 99, default: 1, quick_presets: [1, 5, 10, 25, 50, 99] },
            });
            if (path.includes('command-queue')) return response({
                version: 1, limit: 20, explicit_count: 0, items: [],
                plan: Array.from({ length: 20 }, (_, index) => ({
                    position: index + 1, kind: 'automatic_finance', editable: false, command_name: '資金繰り', quantity: null,
                })),
            });
            if (path === '/api/v1/nations/3/events?page=1') return response({
                groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
                turns_per_page: 12, has_newer_page: false, has_older_page: false,
            });

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '自島へ')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.compensation-banner').text()).toContain('1件の配布内容を確認する');
        await wrapper.get('.compensation-banner').trigger('click');
        expect(wrapper.get('.compensation-modal').attributes('aria-modal')).toBe('true');
        expect(wrapper.get('.compensation-modal').text()).toContain('今回のお詫びです。');
        expect(wrapper.get('.compensation-modal').text()).toContain('資金1,234億円');
        expect(wrapper.get('.compensation-modal').text()).toContain('スキップチケット1,000枚');

        await wrapper.get('.compensation-grant .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('.compensation-modal').text()).toContain('claim response lost');
        expect(wrapper.get('.compensation-grant .button.primary').text()).toBe('受取結果を再確認する');
        await wrapper.get('.compensation-modal button[aria-label="閉じる"]').trigger('click');
        await wrapper.findAll('.session-account-actions button').find((button) => button.text() === '配布倉庫')!.trigger('click');
        await flushPromises();
        // The refreshed pending list no longer includes the committed grant, but its unknown intent must remain retryable.
        expect(wrapper.get('.compensation-grant .button.primary').text()).toBe('受取結果を再確認する');
        await wrapper.get('.compensation-grant .button.primary').trigger('click');
        await flushPromises();

        const claimBodies = fetchMock.mock.calls
            .filter(([path]) => String(path).endsWith('/41/claim'))
            .map(([, init]) => JSON.parse(String(init?.body)) as { request_id: string });
        expect(claimBodies).toHaveLength(2);
        expect(claimBodies[0]?.request_id).toEqual(expect.any(String));
        expect(claimBodies[1]?.request_id).toBe(claimBodies[0]?.request_id);
        expect(wrapper.get('.reward-toast').text()).toContain('資金1,234億円');
        expect(wrapper.get('.reward-toast').text()).toContain('スキップチケット1,000枚');
        expect(wrapper.find('.compensation-banner').exists()).toBe(false);
        expect(wrapper.get('.compensation-modal').text()).toContain('該当する配布はありません');
        await vi.advanceTimersByTimeAsync(5_250);
        await flushPromises();
        expect(wrapper.get('.reward-toast').text()).toContain('配布は受取済みですが、最新表示を更新できませんでした。');
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
        let previewDetailCalls = 0;
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
            if (path === '/api/v1/me/underground/surface-map') return response(undergroundSurfaceMapFixture);
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
            if (path === '/api/v1/public/nations/7') {
                previewDetailCalls++;
                return response(previewDetailCalls === 1 ? publicDetail : {
                    ...publicDetail,
                    map_space: {
                        ...publicDetail.map_space,
                        bounds_revision: 'bounds-0-63',
                        bounds: { min_x: 0, max_x: 63, min_y: 0, max_y: 63 },
                    },
                });
            }
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
        const undergroundMap = wrapper.get('.underground-map-card');
        expect(undergroundMap.text()).toContain('首都地下');
        expect(undergroundMap.text()).not.toContain('UNDERGROUND');
        expect(undergroundMap.text()).not.toContain('地底マップ');
        expect(undergroundMap.text()).not.toContain('2層・8施設枠');
        expect(undergroundMap.text()).not.toContain('梯子と入口は施設枠に含まれません。');
        expect(wrapper.findAll('.underground-layer')).toHaveLength(2);
        expect(wrapper.findAll('.underground-slot')).toHaveLength(8);
        expect(wrapper.findAll('.underground-ladder')).toHaveLength(2);
        expect(wrapper.findAll('.underground-entrance')).toHaveLength(1);
        expect(undergroundMap.text()).not.toContain('地底農場');
        expect(wrapper.findAll('.underground-slot-label')).toHaveLength(0);
        expect(wrapper.findAll('.underground-slot').every((slot) => !slot.text().includes('(') && !slot.text().includes('X'))).toBe(true);
        expect(wrapper.find('.underground-map-detail').exists()).toBe(false);
        expect(wrapper.findAll('.underground-entrance button')).toHaveLength(0);
        expect(wrapper.findAll('.underground-ladder button')).toHaveLength(0);
        const workspaceElement = wrapper.get('.island-workspace-region').element;
        const undergroundMapElement = undergroundMap.element;
        const messageBoardElement = wrapper.get('.message-board').element;
        expect(Boolean(workspaceElement.compareDocumentPosition(undergroundMapElement) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true);
        expect(Boolean(undergroundMapElement.compareDocumentPosition(messageBoardElement) & Node.DOCUMENT_POSITION_FOLLOWING)).toBe(true);
        await wrapper.findAll('.underground-slot')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.findAll('.underground-slot')[0]!.attributes('aria-pressed')).toBe('true');
        expect(wrapper.findAll('.underground-slot')[0]!.classes()).toContain('selected');
        expect(wrapper.get('.underground-map-detail').text()).toContain('地底農場');
        expect(wrapper.get('.underground-map-detail').text()).toContain('座標(10, 8, -2)');
        expect(wrapper.get('.underground-map-detail').text()).not.toContain('(X-2, Y, -2)');
        expect(wrapper.get('.underground-target-summary').text()).toContain('地下1層・slot 0');
        const undergroundCommandRequest = fetchMock.mock.calls
            .map(([input]) => String(input))
            .find((path) => path.includes('command-definitions') && path.includes('target_layer=1'));
        expect(undergroundCommandRequest).toContain('target_slot_index=0');
        expect(undergroundCommandRequest).not.toContain('target_x');
        wrapper.getComponent(HexMap).vm.$emit('select', surfaceCellFixture);
        await flushPromises();
        expect(wrapper.findAll('.underground-slot').every((slot) => slot.attributes('aria-pressed') === 'false')).toBe(true);
        expect(wrapper.find('.underground-target-summary').exists()).toBe(false);
        const surfaceCommandRequest = fetchMock.mock.calls
            .map(([input]) => String(input))
            .filter((path) => path.includes('command-definitions')).at(-1);
        expect(surfaceCommandRequest).toContain('target_x=12');
        expect(surfaceCommandRequest).toContain('target_y=8');
        expect(surfaceCommandRequest).not.toContain('target_layer');
        expect(wrapper.find('.hud-primary').text()).toContain('人口1,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('面積17セル');
        expect(wrapper.find('.hud-primary').text()).toContain('食料10,000トン');
        expect(wrapper.find('.hud-primary').text()).toContain('農場規模10,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('工場規模20,000人');
        expect(wrapper.find('.hud-primary').text()).toContain('採掘場規模30,000人');
        expect(wrapper.find('.hud-money .hud-current-value').text()).toBe('62,728億円');
        expect(wrapper.find('.hud-money').text()).not.toContain('/');
        expect(wrapper.find('.hud-primary').text()).not.toContain('工業品');
        expect(wrapper.find('.hud-primary').text()).not.toContain('上限');
        expect(wrapper.find('.hud-more').text()).toContain('詳細情報');
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
        expect(workspaceJumpButtons.every((button) => button.attributes('aria-controls') === workspaceScroll.attributes('id'))).toBe(true);
        const scrollTo = vi.fn();
        Object.defineProperty(workspaceScroll.element, 'scrollTo', { configurable: true, value: scrollTo });
        await workspaceJumpButtons.find((button) => button.text() === '開発計画')!.trigger('click');
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
        const publicPreviewChunkCallsBefore = fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/')).length;
        const privateChunkCallsBefore = fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/map-spaces/2/chunks/')).length;
        const publicRankingButton = wrapper.findAll('.ranking-card tbody button').find((button) => button.text().includes('公開島'))!;
        await publicRankingButton.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('PUBLIC ISLAND PREVIEW');
        expect(fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toHaveLength(publicPreviewChunkCallsBefore);
        expect(fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/map-spaces/2/chunks/')).length).toBeGreaterThan(privateChunkCallsBefore);

        const privatePreviewRefreshCalls = fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/map-spaces/2/chunks/')).length;
        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();
        expect(previewDetailCalls).toBe(2);
        expect(fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/public/nations/7/map-spaces/2/chunks/'))).toHaveLength(publicPreviewChunkCallsBefore);
        expect(fetchMock.mock.calls.filter(([path]) =>
            String(path).includes('/api/v1/map-spaces/2/chunks/')).length).toBeGreaterThan(privatePreviewRefreshCalls);

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
        expect(summaryCallCount()).toBe(3);
        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();
        expect(summaryCallCount()).toBe(4);
        wrapper.unmount();
        await vi.advanceTimersByTimeAsync(60_000);
        await flushPromises();
        expect(summaryCallCount()).toBe(4);
    });
});
