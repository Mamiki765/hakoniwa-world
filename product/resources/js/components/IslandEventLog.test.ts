import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import IslandEventLog from './IslandEventLog.vue';
import type { PlayerIslandEventPage, PublicEventPage } from '../types';

const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

function deferredResponse(): { promise: Promise<Response>; resolve: (value: Response) => void } {
    let resolve!: (value: Response) => void;
    const promise = new Promise<Response>((next) => { resolve = next; });
    return { promise, resolve };
}

afterEach(() => vi.unstubAllGlobals());

describe('IslandEventLog', () => {
    it('renders owner events as one-line messages and keeps confidential styling separate from text', async () => {
        const fetchMock = vi.fn().mockResolvedValue(response({
            groups: [{
                target_turn: 25,
                events: [{
                    id: 5,
                    type: 'command.logging_private',
                    message: '試験島(12,8)で伐採し、500億円を得ました。',
                    importance: 'info',
                    target_turn: 25,
                    confidential: true,
                    summary: null,
                }],
            }],
            page: 1,
            anchor_turn: 25,
            turn_range: { start: 2, end: 25 },
            turns_per_page: 24,
            has_newer_page: false,
            has_older_page: false,
        } satisfies PlayerIslandEventPage));
        vi.stubGlobal('fetch', fetchMock);

        const wrapper = mount(IslandEventLog, { props: { nationId: 3, audience: 'owner' } });
        await flushPromises();

        expect(String(fetchMock.mock.calls[0]?.[0])).toBe('/api/v1/nations/3/events?page=1');
        expect(wrapper.get('h2').text()).toBe('島ログ');
        expect(wrapper.get('.event-confidential-label').text()).toBe('秘密');
        expect(wrapper.get('.island-event-group li').text()).toContain('試験島(12,8)で伐採し、500億円を得ました。');
        expect(wrapper.text()).not.toContain('座標');
        expect(wrapper.find('time').exists()).toBe(false);
    });

    it('loads the public island endpoint and blocks duplicate pagination requests', async () => {
        const firstRequest = deferredResponse();
        const secondRequest = deferredResponse();
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => firstRequest.promise)
            .mockImplementationOnce(() => secondRequest.promise);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(IslandEventLog, { props: { nationId: 3, audience: 'public' } });
        await wrapper.vm.$nextTick();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0]?.[0])).toBe('/api/v1/public/nations/3/events?page=1');

        firstRequest.resolve(response({
            groups: [{
                target_turn: 25,
                events: [{
                    id: 5, type: 'command.facility_built_public',
                    message: '試験島(12,8)で農場が建設されました。',
                    importance: 'info', target_turn: 25,
                }],
            }],
            page: 1, anchor_turn: 25, turn_range: { start: 2, end: 25 }, turns_per_page: 24,
            has_newer_page: false, has_older_page: true,
        } satisfies PublicEventPage));
        await flushPromises();

        expect(wrapper.get('h2').text()).toBe('公開島ログ');
        const olderButton = wrapper.get('.island-event-pagination button:last-child');
        await olderButton.trigger('click');
        await olderButton.trigger('click');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(String(fetchMock.mock.calls[1]?.[0]))
            .toBe('/api/v1/public/nations/3/events?page=2&anchor_turn=25');

        secondRequest.resolve(response({
            groups: [], page: 2, anchor_turn: 25, turn_range: { start: 1, end: 1 },
            turns_per_page: 24, has_newer_page: true, has_older_page: false,
        } satisfies PublicEventPage));
        await flushPromises();

        expect(wrapper.text()).toContain('この24ターンには表示できるログがありません。');
        expect(wrapper.text()).toContain('2ページ');
    });

    it('shows an error and retries the current owner page', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(null, 500))
            .mockResolvedValueOnce(response({
                groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
                turns_per_page: 24, has_newer_page: false, has_older_page: false,
            } satisfies PlayerIslandEventPage));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(IslandEventLog, { props: { nationId: 3, audience: 'owner' } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('島ログを取得できませんでした');
        await wrapper.get('.island-events-error button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(String(fetchMock.mock.calls[1]?.[0])).toBe('/api/v1/nations/3/events?page=1');
    });
});
