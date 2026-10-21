import { computed, onUnmounted, ref, watch, type Ref } from 'vue';
import { api } from './api/client';

// お詫びブースト（時間加速・狩場ブースト）の画面側の型と取得口。
// サーバー側のAPIは別作業で実装中のため、ここが仮の形。API仕様が決まったら
// このファイルの型と fetchBoostSchedules だけを差し替える。

export type BoostKind = 'turn_acceleration' | 'hunting_ground';

/** サーバーから届く予約1件（仮）。時間加速と狩場ブーストは別々に予約される。 */
export interface BoostSchedule {
    id: number | string;
    kind: BoostKind;
    starts_at: string;
    ends_at: string;
    title?: string | null;
    description?: string | null;
    /** 時間加速: 開催中のターン更新間隔（分）。 */
    turn_interval_minutes?: number | null;
    /** 狩場ブースト: 倍率。 */
    multiplier?: number | null;
}

export type BoostPhase = 'upcoming' | 'active';

export interface BoostView extends BoostSchedule {
    phase: BoostPhase;
    /** upcoming なら開始まで、active なら終了までのミリ秒。 */
    remainingMs: number;
}

export const boostKindLabels: Record<BoostKind, string> = {
    turn_acceleration: '時間加速',
    hunting_ground: '狩場ブースト',
};

const boostKinds = new Set<string>(Object.keys(boostKindLabels));

export async function fetchBoostSchedules(worldId: number): Promise<BoostSchedule[]> {
    try {
        const rows = await api<BoostSchedule[]>(`/api/v1/public/worlds/${worldId}/boosts`);
        return Array.isArray(rows) ? rows.filter((row) => boostKinds.has(row.kind)) : [];
    } catch {
        // APIが未実装・停止中でも画面は普段どおりに出す。
        return [];
    }
}

export function boostPhase(schedule: BoostSchedule, now: number): BoostView | null {
    const startsAt = Date.parse(schedule.starts_at);
    const endsAt = Date.parse(schedule.ends_at);
    if (!Number.isFinite(startsAt) || !Number.isFinite(endsAt) || now >= endsAt) return null;
    if (now < startsAt) return { ...schedule, phase: 'upcoming', remainingMs: startsAt - now };

    return { ...schedule, phase: 'active', remainingMs: endsAt - now };
}

export function formatBoostRemaining(ms: number): string {
    const totalSeconds = Math.max(0, Math.floor(ms / 1000));
    const days = Math.floor(totalSeconds / 86400);
    const hours = Math.floor((totalSeconds % 86400) / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    if (days > 0) return `${days}日${hours}時間`;

    return [hours, minutes, seconds].map((part) => String(part).padStart(2, '0')).join(':');
}

const refreshIntervalMs = 5 * 60_000;

/** 予約を定期的に取り直し、時計に合わせて予告→開催中→終了を切り替える。 */
export function useBoosts(worldId: Ref<number | null>, now: Ref<number>) {
    const schedules = ref<BoostSchedule[]>([]);
    let timer: ReturnType<typeof setInterval> | null = null;

    async function refresh(): Promise<void> {
        const id = worldId.value;
        if (id === null) {
            schedules.value = [];
            return;
        }
        const rows = await fetchBoostSchedules(id);
        if (worldId.value === id) schedules.value = rows;
    }

    watch(worldId, () => {
        void refresh();
        if (timer !== null) clearInterval(timer);
        timer = worldId.value === null ? null : setInterval(() => { void refresh(); }, refreshIntervalMs);
    }, { immediate: true });
    onUnmounted(() => {
        if (timer !== null) clearInterval(timer);
    });

    const boosts = computed<BoostView[]>(() => schedules.value
        .map((schedule) => boostPhase(schedule, now.value))
        .filter((view): view is BoostView => view !== null)
        .sort((a, b) => (a.phase === b.phase ? Date.parse(a.starts_at) - Date.parse(b.starts_at) : a.phase === 'active' ? -1 : 1)));

    return { boosts, refresh };
}
