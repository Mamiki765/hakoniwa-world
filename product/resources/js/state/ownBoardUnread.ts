import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { api } from '../api/client';
import type { MessageBoardTimeline } from '../types';

const POLL_INTERVAL_MS = 60_000;
const seenKey = (nationId: number): string => `hakoniwa.message-board.seen:${nationId}`;

/** いちばん新しい「届いた伝言」の時刻と、その時刻に届いた伝言。同じ秒に何件か届いても数え落とさない。 */
export interface BoardMark {
    at: string;
    keys: string[];
}

/**
 * 通知の対象になる、いちばん新しい伝言。自分が送ったもの（送信した秘密通信、島主としての書き込み）は数えない。
 */
export function newestIncoming(timeline: MessageBoardTimeline | null): BoardMark | null {
    const ownBoard = timeline?.viewer.author_type === 'owner';
    const incoming = (timeline?.entries ?? []).filter((entry) => !(
        (entry.kind === 'secret' && entry.direction === 'outgoing')
        || (ownBoard && entry.kind === 'public' && entry.author.type === 'owner')
    ));
    if (incoming.length === 0) return null;
    const at = incoming.reduce((newest, entry) => entry.created_at > newest ? entry.created_at : newest, incoming[0]!.created_at);
    return { at, keys: incoming.filter((entry) => entry.created_at === at).map((entry) => entry.key) };
}

/** mark に、seen ではまだ見ていない伝言があるか。 */
function hasUnseen(mark: BoardMark | null, seen: BoardMark | null): boolean {
    if (mark === null) return false;
    if (seen === null || mark.at > seen.at) return true;
    return mark.at === seen.at && mark.keys.some((key) => !seen.keys.includes(key));
}

function readSeen(nationId: number): BoardMark | null {
    try {
        const stored: unknown = JSON.parse(window.localStorage.getItem(seenKey(nationId)) ?? 'null');
        if (typeof stored !== 'object' || stored === null) return null;
        const { at, keys } = stored as { at?: unknown; keys?: unknown };
        return typeof at === 'string' && Array.isArray(keys) ? { at, keys: keys.filter((key): key is string => typeof key === 'string') } : null;
    } catch {
        return null;
    }
}

/**
 * 自分の島の伝言板に、まだ見ていない伝言があるか。
 * 「最後に見た伝言の時刻」はこの端末に覚える（端末をまたいだ同期はしない）。
 *
 * 開発画面では伝言板の部品が自分で1分ごとに取り直すので、そこから時刻を受け取る（report）。
 * ほかの画面にいるあいだだけ、ここが同じAPIを1分ごとに読む。タイマーは常に1本だけ。
 */
export function useOwnBoardUnread(nationId: Ref<number | null>, boardMounted: Ref<boolean>) {
    // 通知用: 新着があるかどうかを決める、いちばん新しい伝言の時刻。
    const latest = ref<BoardMark | null>(null);
    // 表示用: 伝言板の部品が実際に取得して、いま本文として出している伝言のうち、いちばん新しい時刻。
    // 既読にするのはここまで。取得に失敗した・読み込み中のあいだは null のまま。
    const displayed = ref<BoardMark | null>(null);
    const bodyShown = ref(false);
    // このページがいま前面に出ているか。別のブラウザタブの裏にあるあいだは、届いた伝言を読んだことにしない。
    const pageVisible = ref(document.visibilityState === 'visible');
    const onVisibilityChange = (): void => {
        pageVisible.value = document.visibilityState === 'visible';
    };
    document.addEventListener('visibilitychange', onVisibilityChange);
    const seen = ref<BoardMark | null>(null);
    const unread = computed(() => hasUnseen(latest.value, seen.value));
    let timer: ReturnType<typeof setTimeout> | null = null;
    let generation = 0;
    let lastUpdatedAt = 0;

    function stop(): void {
        if (timer !== null) clearTimeout(timer);
        timer = null;
    }

    function schedule(delay: number): void {
        stop();
        timer = setTimeout(() => void poll(), delay);
    }

    async function poll(): Promise<void> {
        timer = null;
        const targetNationId = nationId.value;
        if (targetNationId === null || boardMounted.value) return;
        const requestGeneration = generation;
        if (document.visibilityState !== 'hidden') {
            try {
                const timeline = await api<MessageBoardTimeline>(`/api/v1/nations/${targetNationId}/message-board`);
                // 取得中に島や画面が変わっていたら、その結果は使わない。
                if (requestGeneration !== generation) return;
                latest.value = newestIncoming(timeline);
                lastUpdatedAt = Date.now();
            } catch {
                // 取得に失敗しても、いまの未読の印は消さない。次の回でまた確かめる。
                if (requestGeneration !== generation) return;
            }
        }
        if (nationId.value === targetNationId && !boardMounted.value) schedule(POLL_INTERVAL_MS);
    }

    /** 開発画面の伝言板が取り直せた結果を受け取る（取得に失敗したときは呼ばれない）。 */
    function report(targetNationId: number, mark: BoardMark | null): void {
        if (nationId.value !== targetNationId) return;
        latest.value = mark;
        displayed.value = mark;
        lastUpdatedAt = Date.now();
    }

    /** 伝言板の部品が、いま本文を出せているか（読み込み中・取得失敗のあいだは false）。 */
    function setBodyShown(targetNationId: number, shown: boolean): void {
        if (nationId.value !== targetNationId) return;
        bodyShown.value = shown;
    }

    /** 伝言板が画面に出ているときに呼ぶ。本文として表示できている分だけを既読にする。 */
    function markSeen(): void {
        const targetNationId = nationId.value;
        const shownUpTo = bodyShown.value && pageVisible.value ? displayed.value : null;
        if (targetNationId === null || shownUpTo === null || !hasUnseen(shownUpTo, seen.value)) return;
        // 同じ秒のうち、すでに見た伝言も覚えたままにする。
        seen.value = seen.value?.at === shownUpTo.at ? { at: shownUpTo.at, keys: [...new Set([...seen.value.keys, ...shownUpTo.keys])] } : shownUpTo;
        try {
            window.localStorage.setItem(seenKey(targetNationId), JSON.stringify(seen.value));
        } catch {
            // 保存できなくても動く
        }
    }

    // 島が変わったら前の島の状態を持ち越さない。伝言板の部品が出ていないあいだだけ、ここで読む。
    // 直前に読んだばかりなら、残りの時間だけ待つ。
    watch([nationId, boardMounted] as const, ([targetNationId, mounted], previous) => {
        generation++;
        stop();
        // 伝言板の部品が外れたら、表示できている分は無くなる。
        if (!mounted) {
            displayed.value = null;
            bodyShown.value = false;
        }
        if (previous === undefined || previous[0] !== targetNationId) {
            latest.value = null;
            displayed.value = null;
            bodyShown.value = false;
            lastUpdatedAt = 0;
            seen.value = targetNationId === null ? null : readSeen(targetNationId);
        }
        if (targetNationId === null || mounted) return;
        schedule(Math.max(0, POLL_INTERVAL_MS - (Date.now() - lastUpdatedAt)));
    }, { immediate: true });

    onBeforeUnmount(() => {
        document.removeEventListener('visibilitychange', onVisibilityChange);
        generation++;
        stop();
    });

    return { unread, latest, displayed, bodyShown, pageVisible, report, setBodyShown, markSeen };
}
