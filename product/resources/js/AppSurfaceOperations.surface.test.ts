import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import type { Nation } from './types';
import { response, envelopeResponse, ownerNationFixture, publicResponse, installAppTestLifecycle } from './AppTestHarness';

installAppTestLifecycle();

describe('surface inquiries and nation lifecycle', () => {
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
