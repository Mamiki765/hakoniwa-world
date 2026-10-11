import { computed, onBeforeUnmount, ref, watch, type Ref } from 'vue';
import { api } from '../api/client';
import type { MessageBoardTimeline } from '../types';

const POLL_INTERVAL_MS = 60_000;
const seenKey = (nationId: number): string => `hakoniwa.message-board.seen:${nationId}`;

export function newestCreatedAt(timeline: MessageBoardTimeline | null): string | null {
    const times = (timeline?.entries ?? []).map((entry) => entry.created_at);
    return times.length > 0 ? times.reduce((newest, time) => time > newest ? time : newest) : null;
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
    const latest = ref<string | null>(null);
    // 表示用: 伝言板の部品が実際に取得して、いま本文として出している伝言のうち、いちばん新しい時刻。
    // 既読にするのはここまで。取得に失敗した・読み込み中のあいだは null のまま。
    const displayed = ref<string | null>(null);
    const bodyShown = ref(false);
    const seen = ref<string | null>(null);
    const unread = computed(() => latest.value !== null && (seen.value === null || latest.value > seen.value));
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
                latest.value = newestCreatedAt(timeline);
                lastUpdatedAt = Date.now();
            } catch {
                // 取得に失敗しても、いまの未読の印は消さない。次の回でまた確かめる。
                if (requestGeneration !== generation) return;
            }
        }
        if (nationId.value === targetNationId && !boardMounted.value) schedule(POLL_INTERVAL_MS);
    }

    /** 開発画面の伝言板が取り直せた結果を受け取る（取得に失敗したときは呼ばれない）。 */
    function report(targetNationId: number, createdAt: string | null): void {
        if (nationId.value !== targetNationId) return;
        latest.value = createdAt;
        displayed.value = createdAt;
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
        const shownUpTo = bodyShown.value ? displayed.value : null;
        if (targetNationId === null || shownUpTo === null) return;
        if (seen.value !== null && seen.value >= shownUpTo) return;
        seen.value = shownUpTo;
        try {
            window.localStorage.setItem(seenKey(targetNationId), shownUpTo);
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
            try {
                seen.value = targetNationId === null ? null : window.localStorage.getItem(seenKey(targetNationId));
            } catch {
                seen.value = null;
            }
        }
        if (targetNationId === null || mounted) return;
        schedule(Math.max(0, POLL_INTERVAL_MS - (Date.now() - lastUpdatedAt)));
    }, { immediate: true });

    onBeforeUnmount(() => {
        generation++;
        stop();
    });

    return { unread, latest, displayed, bodyShown, report, setBodyShown, markSeen };
}
