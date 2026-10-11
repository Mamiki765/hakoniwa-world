import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { newestIncoming, useOwnBoardUnread } from './ownBoardUnread';
import type { MessageBoardTimeline } from '../types';

beforeEach(() => {
    vi.useFakeTimers();
    window.localStorage.clear();
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const board = (entries: unknown[]) => ({ entries, viewer: { author_type: 'owner' } }) as unknown as MessageBoardTimeline;
const received = (key: string, time: string) => ({ key, created_at: time, kind: 'secret', direction: 'incoming' });
const timeline = (createdAt: string[]) => new Response(JSON.stringify({
    data: board(createdAt.map((time, index) => received(`entry-${index}`, time))),
}), { status: 200 });

it('keeps one poller for the own island, keeps unread across a failed fetch, and never carries another island over', async () => {
    const nationId = ref<number | null>(3);
    const boardMounted = ref(false);
    let fail = false;
    const requested: string[] = [];
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
        requested.push(String(input));
        if (fail) throw new TypeError('offline');
        return timeline(String(input).includes('/nations/3/') ? ['2026-10-11T07:00:00+09:00'] : []);
    }));
    let unreadState!: ReturnType<typeof useOwnBoardUnread>;
    const wrapper = mount(defineComponent({
        setup() {
            unreadState = useOwnBoardUnread(nationId, boardMounted);
            return () => h('i');
        },
    }));
    await flushPromises();
    await vi.advanceTimersByTimeAsync(0);
    expect(requested).toEqual(['/api/v1/nations/3/message-board']);
    expect(unreadState.unread.value).toBe(true);

    // 取得に失敗しても、未読の印は消えない。
    fail = true;
    await vi.advanceTimersByTimeAsync(60_000);
    expect(requested).toHaveLength(2);
    expect(unreadState.unread.value).toBe(true);
    fail = false;

    // 開発画面の伝言板が出ているあいだは、ここでは読まない（二重に取らない）。
    boardMounted.value = true;
    await vi.advanceTimersByTimeAsync(180_000);
    expect(requested).toHaveLength(2);

    // 伝言板を画面に出しても、本文を取得して表示できていなければ既読にしない（取得失敗・読み込み中）。
    unreadState.markSeen();
    unreadState.setBodyShown(3, false);
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(true);
    expect(window.localStorage.getItem('hakoniwa.message-board.seen:3')).toBeNull();

    // 取得できて本文を表示したら既読。読み込み直しても既読のまま。
    const first = received('entry-0', '2026-10-11T07:00:00+09:00');
    unreadState.report(3, newestIncoming(board([first])));
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(true);
    unreadState.setBodyShown(3, true);
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(false);
    expect(window.localStorage.getItem('hakoniwa.message-board.seen:3')).toContain('2026-10-11T07:00:00+09:00');

    // 自分が送った伝言（送信した秘密通信、島主としての書き込み）は、新しくても新着にしない。
    const sent = [
        { key: 'sent-secret', created_at: '2026-10-11T08:00:00+09:00', kind: 'secret', direction: 'outgoing' },
        { key: 'sent-public', created_at: '2026-10-11T08:30:00+09:00', kind: 'public', author: { type: 'owner' } },
    ];
    unreadState.setBodyShown(3, false);
    unreadState.report(3, newestIncoming(board([...sent, first])));
    expect(unreadState.unread.value).toBe(false);

    // 見た伝言と同じ秒に、別の伝言が届いたら新着。送信では既読にならず、表示したら既読。
    unreadState.report(3, newestIncoming(board([...sent, first, received('entry-same-second', '2026-10-11T07:00:00+09:00')])));
    expect(unreadState.unread.value).toBe(true);
    unreadState.setBodyShown(3, true);
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(false);

    // ページが別のブラウザタブの裏にあるあいだに届いた伝言は、伝言板を開いたままでも既読にしない。前面へ戻ったら既読にできる。
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    unreadState.report(3, newestIncoming(board([received('while-hidden', '2026-10-11T08:45:00+09:00')])));
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(true);
    visibility.mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(unreadState.pageVisible.value).toBe(true);
    unreadState.markSeen();
    expect(unreadState.unread.value).toBe(false);
    visibility.mockRestore();

    // 別の島へ切り替わったら、前の島の新着や既読を持ち越さない。別の島あての報告も受け取らない。
    boardMounted.value = false;
    nationId.value = 8;
    await flushPromises();
    unreadState.report(3, newestIncoming(board([received('late', '2026-10-11T09:00:00+09:00')])));
    await vi.advanceTimersByTimeAsync(0);
    expect(requested.at(-1)).toBe('/api/v1/nations/8/message-board');
    expect(unreadState.unread.value).toBe(false);

    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(180_000);
    expect(requested.at(-1)).toBe('/api/v1/nations/8/message-board');
    expect(requested.filter((path) => path.includes('/nations/8/'))).toHaveLength(1);
});
