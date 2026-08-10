import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import MessageBoard from './MessageBoard.vue';
import type { MessageBoardTimeline } from '../types';

const guestTimeline: MessageBoardTimeline = {
    board: { nation_number: 2, name: '受信島' },
    entries: [{
        key: 'placeholder-1', kind: 'secret_placeholder', text: '--秘密通信あり--',
        created_at: '2026-08-11T12:00:00+09:00',
    }],
    viewer: { authenticated: false, can_post: false, author_type: null, can_send_secret: false },
    contract: {
        latest_limit: 16, body_max_characters: 140, cooldown_seconds: 10,
    },
};

const ownerTimeline: MessageBoardTimeline = {
    ...guestTimeline,
    entries: [
        {
            key: 'secret-1', kind: 'secret', label: '秘密通信', direction: 'incoming',
            body: '<b>秘密もplain text</b>', counterpart: { nation_number: 1, name: '送信島' },
            created_at: '2026-08-11T12:00:00+09:00',
        },
        {
            key: 'owner-1', kind: 'public', body: '<script>not html</script>',
            author: { type: 'owner', label: '島主', nation: { nation_number: 2, name: '受信島' } },
            created_at: '2026-08-11T11:00:00+09:00',
        },
        {
            key: 'other-1', kind: 'public', body: '他島投稿',
            author: { type: 'other_nation', label: '他島', nation: { nation_number: 3, name: '他島' } },
            created_at: '2026-08-11T10:00:00+09:00',
        },
        {
            key: 'visitor-1', kind: 'public', body: '観光客投稿',
            author: { type: 'visitor', label: '観光客', display_name: '観光客(ID:A8K2Q7XZ)', visitor_code: 'A8K2Q7XZ' },
            created_at: '2026-08-11T09:00:00+09:00',
        },
    ],
    viewer: { authenticated: true, can_post: true, author_type: 'other_nation', can_send_secret: true },
    contract: {
        ...guestTimeline.contract,
        secret_cost_money: 100,
        secret_cost_display: '100億円',
    },
};

const response = (data: unknown, status = 200, message?: string) => new Response(JSON.stringify({ data, message }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

function deferred<T>(): { promise: Promise<T>; resolve: (value: T) => void } {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((resolver) => { resolve = resolver; });

    return { promise, resolve };
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.useRealTimers();
    localStorage.clear();
});

describe('MessageBoard', () => {
    it('renders logged-out read-only placeholder with exact viewer-safe text', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => response(guestTimeline)));
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();

        expect(wrapper.find('.message-board-body').text()).toBe('--秘密通信あり--');
        expect(wrapper.text()).toContain('閲覧のみです。投稿にはログインが必要です。');
        expect(wrapper.findAll('form')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('送信島');
        expect(wrapper.find('.message-board-toggle').attributes('aria-expanded')).toBe('true');
        wrapper.unmount();
    });

    it('uses text labels plus owner/other/tourist/secret classes and never renders body HTML', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => response(ownerTimeline)));
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();

        expect(wrapper.find('.message-secret').text()).toContain('[N1 送信島からの秘密通信]');
        expect(wrapper.find('.message-secret').text()).toContain('<b>秘密もplain text</b>');
        expect(wrapper.find('.message-secret b').exists()).toBe(false);
        expect(wrapper.find('.message-author-owner').text()).toContain('島主・N2 受信島');
        expect(wrapper.find('.message-author-owner script').exists()).toBe(false);
        expect(wrapper.find('.message-author-other_nation').text()).toContain('他島・N3 他島');
        expect(wrapper.find('.message-author-visitor').text()).toContain('観光客(ID:A8K2Q7XZ)');
        expect(wrapper.find('.secret-form').text()).toContain('費用 100億円');
        wrapper.unmount();
    });

    it('counts Japanese ASCII and emoji as Unicode code points and disables 141 characters', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => response(ownerTimeline)));
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        const normal = wrapper.find<HTMLTextAreaElement>('#message-board-body');
        const button = wrapper.find<HTMLButtonElement>('.message-board-form:not(.secret-form) button');

        await normal.setValue(`${'あ'.repeat(100)}${'A'.repeat(39)}😀`);
        expect(wrapper.find('#message-board-counter').text()).toBe('140 / 140');
        expect(button.attributes('disabled')).toBeUndefined();

        await normal.setValue(`${'あ'.repeat(100)}${'A'.repeat(40)}😀`);
        expect(wrapper.find('#message-board-counter').text()).toBe('141 / 140');
        expect(wrapper.find('#message-board-counter').classes()).toContain('over');
        expect(normal.attributes('aria-invalid')).toBe('true');
        expect(button.attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('keeps failed posts out of the timeline and shows cooldown guidance', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return response(null, 429, '次の投稿まで7秒お待ちください。');
            return response({ ...ownerTimeline, entries: [] });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();

        await wrapper.find('#message-board-body').setValue('frontend-only-message');
        await wrapper.find('.message-board-form:not(.secret-form)').trigger('submit');
        await flushPromises();

        expect(wrapper.text()).toContain('次の投稿まで7秒お待ちください。');
        expect(wrapper.find('.message-board-timeline').exists()).toBe(false);
        expect(wrapper.find('#message-board-body').element).toHaveProperty('value', 'frontend-only-message');
        wrapper.unmount();
    });

    it('replaces the timeline only with the successful authoritative response', async () => {
        const saved: MessageBoardTimeline = {
            ...ownerTimeline,
            entries: [{
                key: 'server-key', kind: 'public', body: 'server-normalized',
                author: { type: 'other_nation', label: '他島', nation: { nation_number: 3, name: '他島' } },
                created_at: '2026-08-11T12:00:00+09:00',
            }],
        };
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => response(
            init?.method === 'POST' ? saved : { ...ownerTimeline, entries: [] },
            init?.method === 'POST' ? 201 : 200,
        ));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        await wrapper.find('#message-board-body').setValue('client draft');
        await wrapper.find('.message-board-form:not(.secret-form)').trigger('submit');
        await flushPromises();

        expect(wrapper.find('.message-board-body').text()).toBe('server-normalized');
        expect(wrapper.text()).not.toContain('client draft');
        expect(wrapper.find<HTMLTextAreaElement>('#message-board-body').element.value).toBe('');
        wrapper.unmount();
    });

    it('does not let an older polling response overwrite a successful post response', async () => {
        vi.useFakeTimers();
        const latePoll = deferred<Response>();
        const saved: MessageBoardTimeline = {
            ...ownerTimeline,
            entries: [{
                key: 'posted', kind: 'public', body: '投稿成功',
                author: { type: 'owner', label: '島主', nation: { nation_number: 2, name: '受信島' } },
                created_at: '2026-08-11T12:00:00+09:00',
            }],
        };
        let getCount = 0;
        vi.stubGlobal('fetch', vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return response(saved, 201);
            getCount++;
            if (getCount === 1) return response({ ...ownerTimeline, entries: [] });

            return latePoll.promise;
        }));
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        await vi.advanceTimersByTimeAsync(60_000);
        await wrapper.find('#message-board-body').setValue('投稿成功');
        await wrapper.find('.message-board-form:not(.secret-form)').trigger('submit');
        await flushPromises();
        expect(wrapper.find('.message-board-body').text()).toBe('投稿成功');

        latePoll.resolve(response({ ...ownerTimeline, entries: [] }));
        await flushPromises();
        expect(wrapper.find('.message-board-body').text()).toBe('投稿成功');
        wrapper.unmount();
    });

    it('posts secret text to the secret endpoint and renders the recipient-board authoritative response', async () => {
        const saved: MessageBoardTimeline = {
            ...ownerTimeline,
            entries: [{
                key: 'incoming', kind: 'secret', label: '秘密通信', direction: 'incoming', body: '秘密本文',
                counterpart: { nation_number: 1, name: '送信島' },
                created_at: '2026-08-11T12:00:00+09:00',
            }],
        };
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => response(
            init?.method === 'POST' ? saved : { ...ownerTimeline, entries: [] },
            init?.method === 'POST' ? 201 : 200,
        ));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        const secret = wrapper.find<HTMLTextAreaElement>('#message-board-secret-body');
        const button = wrapper.find<HTMLButtonElement>('.secret-form button');

        await secret.setValue(`${'秘'.repeat(139)}😀`);
        expect(wrapper.find('#message-board-secret-counter').text()).toBe('140 / 140');
        expect(button.attributes('disabled')).toBeUndefined();
        await secret.setValue(`${'秘'.repeat(140)}😀`);
        expect(wrapper.find('#message-board-secret-counter').text()).toBe('141 / 140');
        expect(button.attributes('disabled')).toBeDefined();
        await secret.setValue('秘密本文');
        await wrapper.find('.secret-form').trigger('submit');
        await flushPromises();

        const request = fetchMock.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(String(request?.[0])).toBe('/api/v1/nations/2/message-board/secret');
        expect(JSON.parse(String(request?.[1]?.body))).toEqual({ body: '秘密本文' });
        expect(wrapper.find('.message-secret').text()).toContain('[N1 送信島からの秘密通信]');
        expect(wrapper.find('.message-board-body').text()).toBe('秘密本文');
        expect(secret.element.value).toBe('');
        wrapper.unmount();
    });

    it('renders an outgoing secret only in the sender owner development context', async () => {
        const developmentTimeline: MessageBoardTimeline = {
            ...ownerTimeline,
            board: { nation_number: 1, name: '送信島' },
            entries: [{
                key: 'outgoing', kind: 'secret', label: '秘密通信', direction: 'outgoing', body: '送信済み本文',
                counterpart: { nation_number: 2, name: '受信島' },
                created_at: '2026-08-11T12:00:00+09:00',
            }],
            viewer: { authenticated: true, can_post: true, author_type: 'owner', can_send_secret: false },
            contract: guestTimeline.contract,
        };
        vi.stubGlobal('fetch', vi.fn(async () => response(developmentTimeline)));

        const wrapper = mount(MessageBoard, { props: { nationId: 1, context: 'development' } });
        await flushPromises();

        expect(wrapper.find('.message-secret').text()).toContain('[N2 受信島への秘密通信]');
        expect(wrapper.find('.message-board-body').text()).toBe('送信済み本文');
        expect(wrapper.find('.secret-form').exists()).toBe(false);
        wrapper.unmount();
    });

    it('keeps the secret draft and existing timeline when the server rejects the send', async () => {
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.method === 'POST') return response(null, 500, '送信に失敗しました。');
            return response(ownerTimeline);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        const originalCount = wrapper.findAll('.message-board-timeline li').length;

        await wrapper.find('#message-board-secret-body').setValue('再送する秘密');
        await wrapper.find('.secret-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find<HTMLTextAreaElement>('#message-board-secret-body').element.value).toBe('再送する秘密');
        expect(wrapper.findAll('.message-board-timeline li')).toHaveLength(originalCount);
        expect(wrapper.text()).toContain('送信に失敗しました。');
        wrapper.unmount();
    });

    it('persists expanded/collapsed preference separately for public and development contexts', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => response(guestTimeline)));
        const first = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();
        const toggle = first.find('.message-board-toggle');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(localStorage.getItem('hakoniwa.message-board.collapsed:public')).toBe('1');
        expect(first.find('.message-board-content').isVisible()).toBe(false);
        first.unmount();

        const publicReload = mount(MessageBoard, { props: { nationId: 99, context: 'public' } });
        await flushPromises();
        expect(publicReload.find('.message-board-toggle').attributes('aria-expanded')).toBe('false');
        publicReload.unmount();

        const development = mount(MessageBoard, { props: { nationId: 2, context: 'development' } });
        await flushPromises();
        expect(development.find('.message-board-toggle').attributes('aria-expanded')).toBe('true');
        development.unmount();
    });

    it('keeps toggle, timeline and both forms usable in a mobile viewport', async () => {
        Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 });
        vi.stubGlobal('fetch', vi.fn(async () => response(ownerTimeline)));
        const wrapper = mount(MessageBoard, { props: { nationId: 2, context: 'public' } });
        await flushPromises();

        expect(wrapper.find('.message-board-toggle').element.tagName).toBe('BUTTON');
        expect(wrapper.findAll('textarea')).toHaveLength(2);
        expect(wrapper.findAll('.message-board-timeline li')).toHaveLength(4);
        expect(wrapper.find('.message-board-form-footer button').attributes('type')).toBe('submit');
        wrapper.unmount();
    });
});
