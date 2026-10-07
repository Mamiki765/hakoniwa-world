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
    it('wires settled amounts and optional counts into the summary, including zero receipts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(response({
            groups: [{ target_turn: 2, events: [{
                id: 1, type: 'turn.summary', message: '資源変化', importance: 'info', target_turn: 2,
                confidential: false, summary: null,
                economic_contributions: [
                    { element_key: 'fishing', name: '試験漁船', count: 3, amount: 0, unit: 'トン' },
                    { element_key: 'pizzeria', name: '試験ピザ', count: null, amount: 110, unit: '億円' },
                    { element_key: 'undersea_fire_station', name: '試験消防', count: 1, amount: -6, unit: '億円' },
                ],
            }] }],
            page: 1, anchor_turn: 2, turn_range: { start: 1, end: 2 }, turns_per_page: 12,
            has_newer_page: false, has_older_page: false,
        } satisfies PlayerIslandEventPage)));
        const wrapper = mount(IslandEventLog, { props: { nationId: 3, audience: 'owner' } });
        await flushPromises();
        const rows = wrapper.findAll('p');
        const fishing = rows.find((row) => row.text().includes('試験漁船'))!;
        expect(fishing.text()).toContain('×3');
        expect(fishing.text()).toContain('0トン');
        const pizza = rows.find((row) => row.text().includes('試験ピザ'))!;
        expect(pizza.text()).toContain('+110億円');
        expect(pizza.text()).not.toContain('×');
        const station = rows.find((row) => row.text().includes('試験消防'))!;
        expect(station.text()).toContain('-6億円');
        // Owner explicitly places breakdowns immediately before the existing total summary.
        const summary = rows.find((row) => row.text() === '資源変化')!;
        expect(station.element.compareDocumentPosition(summary.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });
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
            turn_range: { start: 14, end: 25 },
            turns_per_page: 12,
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
            page: 1, anchor_turn: 25, turn_range: { start: 14, end: 25 }, turns_per_page: 12,
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
            groups: [], page: 2, anchor_turn: 25, turn_range: { start: 2, end: 13 },
            turns_per_page: 12, has_newer_page: true, has_older_page: false,
        } satisfies PublicEventPage));
        await flushPromises();

        expect(wrapper.text()).toContain('この12ターンには表示できるログがありません。');
        expect(wrapper.text()).toContain('2ページ');
    });

    it('shows an error and retries the current owner page', async () => {
        const fetchMock = vi.fn()
            .mockResolvedValueOnce(response(null, 500))
            .mockResolvedValueOnce(response({
                groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
                turns_per_page: 12, has_newer_page: false, has_older_page: false,
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
