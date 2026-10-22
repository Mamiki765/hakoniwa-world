import { computed, onUnmounted, ref, watch, type Ref } from 'vue';
import { apiEnvelope } from './api/client';

// 予約ブースト（時間加速・狩場ブースト）の画面側の型と取得口。
// API契約の正本は docs/architecture/boost-scheduling-api.md（#334）。

export type BoostKind = 'turn_acceleration' | 'hunting_ground';

/** 公開APIの開催回1件。時間加速と狩場ブーストは別々に予約され、種別ごとに開催中か次回の1件が届く。 */
export interface BoostSchedule {
    id: number | string;
    series_id?: number | null;
    occurrence_number?: number | null;
    kind: BoostKind;
    status?: 'scheduled' | 'active';
    starts_at: string;
    ends_at: string;
    title?: string | null;
    description?: string | null;
    /** 時間加速: 追加ターン同士の間隔（奇数時00分のみなので120）。通常の偶数時と合わせると追加枠のある間は1時間ごと。実行の保証ではないので次回時刻の計算に使わない。 */
    turn_interval_minutes?: number | null;
    /** 狩場ブースト: 倍率（2）。 */
    multiplier?: number | null;
    effects?: {
        /** 時間加速: 未補填分だけ追加する。 */
        compensation_only?: boolean;
        additional_turn_hours_jst?: number[];
        [key: string]: unknown;
    } | null;
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

export interface BoostFetchResult {
    schedules: BoostSchedule[];
    /** サーバー時計 − 端末時計（ミリ秒）。予告→開催中の切り替えをサーバー時計に合わせる。 */
    clockOffsetMs: number;
}

export async function fetchBoostSchedules(worldId: number): Promise<BoostFetchResult> {
    try {
        const requestedAt = Date.now();
        const envelope = await apiEnvelope<BoostSchedule[]>(`/api/v1/public/worlds/${worldId}/boosts`);
        const serverNow = typeof envelope.meta?.server_now === 'string' ? Date.parse(envelope.meta.server_now) : Number.NaN;
        const rows = envelope.data;

        return {
            schedules: Array.isArray(rows) ? rows.filter((row) => boostKinds.has(row.kind)) : [],
            clockOffsetMs: Number.isFinite(serverNow) ? serverNow - Math.round((requestedAt + Date.now()) / 2) : 0,
        };
    } catch {
        // APIが未実装・停止中でも画面は普段どおりに出す。
        return { schedules: [], clockOffsetMs: 0 };
    }
}

export function boostPhase(schedule: BoostSchedule, now: number): BoostView | null {
    const startsAt = Date.parse(schedule.starts_at);
    const endsAt = Date.parse(schedule.ends_at);
    if (!Number.isFinite(startsAt) || !Number.isFinite(endsAt) || now >= endsAt) return null;
    if (now < startsAt) return { ...schedule, phase: 'upcoming', remainingMs: startsAt - now };

    return { ...schedule, phase: 'active', remainingMs: endsAt - now };
}

/** 時間加速は未補填分だけの追加（今回の契約では常にそう扱う）。 */
export function isCompensationOnly(boost: BoostSchedule): boolean {
    return boost.kind === 'turn_acceleration' && boost.effects?.compensation_only !== false;
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
    const clockOffsetMs = ref(0);
    let timer: ReturnType<typeof setInterval> | null = null;

    async function refresh(): Promise<void> {
        const id = worldId.value;
        if (id === null) {
            schedules.value = [];
            return;
        }
        const result = await fetchBoostSchedules(id);
        if (worldId.value !== id) return;
        schedules.value = result.schedules;
        clockOffsetMs.value = result.clockOffsetMs;
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
        .map((schedule) => boostPhase(schedule, now.value + clockOffsetMs.value))
        .filter((view): view is BoostView => view !== null)
        .sort((a, b) => (a.phase === b.phase ? Date.parse(a.starts_at) - Date.parse(b.starts_at) : a.phase === 'active' ? -1 : 1)));

    return { boosts, refresh };
}
