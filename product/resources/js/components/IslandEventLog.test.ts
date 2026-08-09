import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import IslandEventLog from './IslandEventLog.vue';
import type { PlayerIslandEventPage } from '../types';

const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

function deferredResponse(): {
    promise: Promise<Response>;
    resolve: (value: Response) => void;
} {
    let resolve!: (value: Response) => void;
    const promise = new Promise<Response>((next) => { resolve = next; });
    return { promise, resolve };
}

afterEach(() => vi.unstubAllGlobals());

describe('IslandEventLog', () => {
    it('loads only the selected 24-turn page, groups newest first, and blocks duplicate page loads', async () => {
        const firstRequest = deferredResponse();
        const secondRequest = deferredResponse();
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => firstRequest.promise)
            .mockImplementationOnce(() => secondRequest.promise);
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(IslandEventLog, { props: { nationId: 3 } });
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="status"]').text()).toContain('読み込み中');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(String(fetchMock.mock.calls[0]?.[0])).toBe('/api/v1/nations/3/events?page=1');

        const firstPage: PlayerIslandEventPage = {
            groups: [
                {
                    target_turn: 25,
                    events: [{
                        id: 5, type: 'terrain.changed', message: '整地を実行し、荒地を平地へ変更しました。',
                        importance: 'info', target_turn: 25, coordinate: { x: 12, y: 8 },
                        occurred_at: '2026-08-01T10:00:00+09:00', summary: null,
                    }],
                },
                {
                    target_turn: 2,
                    events: [{
                        id: 1, type: 'turn.completed', message: '第2ターンが完了しました。',
                        importance: 'info', target_turn: 2, coordinate: null,
                        occurred_at: '2026-07-01T10:00:00+09:00', summary: null,
                    }],
                },
            ],
            page: 1,
            anchor_turn: 25,
            turn_range: { start: 2, end: 25 },
            turns_per_page: 24,
            has_newer_page: false,
            has_older_page: true,
        };
        firstRequest.resolve(response(firstPage));
        await flushPromises();

        expect(wrapper.findAll('.island-event-group h3').map((heading) => heading.text()))
            .toEqual(['第25ターン', '第2ターン']);
        expect(wrapper.text()).toContain('座標 (12, 8)');
        expect(wrapper.text()).not.toContain('第24ターン');
        expect(fetchMock).toHaveBeenCalledTimes(1);

        const olderButton = wrapper.get('.island-event-pagination button:last-child');
        await olderButton.trigger('click');
        await olderButton.trigger('click');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(String(fetchMock.mock.calls[1]?.[0]))
            .toBe('/api/v1/nations/3/events?page=2&anchor_turn=25');

        secondRequest.resolve(response({
            groups: [], page: 2, anchor_turn: 25, turn_range: { start: 1, end: 1 },
            turns_per_page: 24, has_newer_page: true, has_older_page: false,
        } satisfies PlayerIslandEventPage));
        await flushPromises();

        expect(wrapper.text()).toContain('この24ターンには表示できる出来事がありません');
        expect(wrapper.text()).toContain('2ページ');
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('shows an error state and retries only the current page on demand', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(null, 500))
            .mockResolvedValueOnce(response({
                groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
                turns_per_page: 24, has_newer_page: false, has_older_page: false,
            } satisfies PlayerIslandEventPage));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(IslandEventLog, { props: { nationId: 3 } });
        await flushPromises();

        expect(wrapper.get('[role="alert"]').text()).toContain('取得できませんでした');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await wrapper.get('.island-events-error button').trigger('click');
        await flushPromises();

        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('表示できる出来事がありません');
        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(String(fetchMock.mock.calls[1]?.[0])).toBe('/api/v1/nations/3/events?page=1');
    });
});
