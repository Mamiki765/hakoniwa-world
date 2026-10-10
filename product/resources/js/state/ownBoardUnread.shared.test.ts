import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { useOwnBoardUnread } from './ownBoardUnread';

beforeEach(() => {
    vi.useFakeTimers();
    window.localStorage.clear();
});
afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

const timeline = (createdAt: string[]) => new Response(JSON.stringify({ data: {
    entries: createdAt.map((time, index) => ({ key: `entry-${index}`, created_at: time, kind: 'secret_placeholder', text: '--秘密通信あり--' })),
} }), { status: 200 });

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
    let board!: ReturnType<typeof useOwnBoardUnread>;
    const wrapper = mount(defineComponent({
        setup() {
            board = useOwnBoardUnread(nationId, boardMounted);
            return () => h('i');
        },
    }));
    await flushPromises();
    await vi.advanceTimersByTimeAsync(0);
    expect(requested).toEqual(['/api/v1/nations/3/message-board']);
    expect(board.unread.value).toBe(true);

    // 取得に失敗しても、未読の印は消えない。
    fail = true;
    await vi.advanceTimersByTimeAsync(60_000);
    expect(requested).toHaveLength(2);
    expect(board.unread.value).toBe(true);
    fail = false;

    // 開発画面の伝言板が出ているあいだは、ここでは読まない（二重に取らない）。
    boardMounted.value = true;
    await vi.advanceTimersByTimeAsync(180_000);
    expect(requested).toHaveLength(2);

    // 伝言板の本文を表示したら既読。読み込み直しても既読のまま。
    board.report(3, '2026-10-11T07:00:00+09:00');
    board.markSeen();
    expect(board.unread.value).toBe(false);
    expect(window.localStorage.getItem('hakoniwa.message-board.seen:3')).toBe('2026-10-11T07:00:00+09:00');

    // 別の島へ切り替わったら、前の島の新着や既読を持ち越さない。別の島あての報告も受け取らない。
    boardMounted.value = false;
    nationId.value = 8;
    await flushPromises();
    board.report(3, '2026-10-11T09:00:00+09:00');
    await vi.advanceTimersByTimeAsync(0);
    expect(requested.at(-1)).toBe('/api/v1/nations/8/message-board');
    expect(board.unread.value).toBe(false);

    wrapper.unmount();
    await vi.advanceTimersByTimeAsync(180_000);
    expect(requested.at(-1)).toBe('/api/v1/nations/8/message-board');
    expect(requested.filter((path) => path.includes('/nations/8/'))).toHaveLength(1);
});
