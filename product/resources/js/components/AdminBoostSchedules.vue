<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';

type Kind = 'turn_acceleration' | 'hunting_ground';
type Repeat = 'none' | 'daily' | 'weekly';
interface Occurrence {
    id: number; series_id: number; occurrence_number: number; kind: Kind; title: string;
    starts_at: string; ends_at: string; status: 'scheduled' | 'active' | 'ended' | 'cancelled';
}
interface Overview {
    current_turn: number; uncompensated_turns: number; occurrences: Occurrence[];
}
interface Preview {
    token: string; kind: Kind; title: string; repeat: Repeat; repeat_count: number;
    uncompensated_turns: number; duration_hours: number;
    additional_turns?: number; interval_minutes?: number; effective_additional_turns?: number;
    windows: Array<{ starts_at: string; ends_at: string; additional_slots: number; additional_scheduled_ats?: string[] }>;
}
const props = defineProps<{ worldId: number; initialKind: Kind }>();
const emit = defineEmits<{ close: []; updated: []; locked: [value: boolean] }>();
const endpoint = computed(() => `/api/v1/admin/worlds/${props.worldId}/boosts`);
const kind = ref(props.initialKind);
const titles: Record<Kind, string> = { turn_acceleration: '停止分のターン補填', hunting_ground: '狩場ブースト' };
const title = ref(titles[kind.value]);
// datetime-local は端末の timezone に依存させず、常に日本時間の壁時計として扱う。
const startsAt = ref(props.initialKind === 'turn_acceleration'
    ? nextOddHour(new Date(Date.now() + 9 * 3_600_000).toISOString().slice(0, 16), true)
    : new Date(Date.now() + 5 * 60_000 + 9 * 3_600_000).toISOString().slice(0, 16));
const endsAt = ref('');
const additionalTurns = ref(7);
let initializedTime = false;
const repeat = ref<Repeat>('none');
const repeatCount = ref(2);
const overview = ref<Overview | null>(null);
const preview = ref<Preview | null>(null);
const cancellation = ref<{ row: Occurrence; futureSeries: boolean } | null>(null);
const busy = ref(false);
// 応答不明時は対象・tokenを保持し、同じ操作の結果確認だけを許可する。
const submitted = ref(false);
const locked = computed(() => busy.value || submitted.value);
const error = ref('');
const resultMessage = ref('');
const labels = { scheduled: '予約中', active: '開催中', ended: '終了', cancelled: '取消済み' };
watch(locked, (value) => emit('locked', value), { immediate: true });
onMounted(refresh);

function formatDate(value: string): string {
    return new Date(value).toLocaleString('ja-JP', { timeZone: 'Asia/Tokyo', hour12: false });
}

function jstTimestamp(value: string): string {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) throw new Error('日時を日本時間で入力してください。');
    return `${value}:00+09:00`;
}

function nextOddHour(value: string, strictlyAfter = false): string {
    // UTCフィールドでJSTの壁時計を操作し、端末のtimezoneを参照しない。
    const date = new Date(`${value}:00Z`);
    const minutes = date.getUTCMinutes();
    date.setUTCMinutes(0, 0, 0);
    if (minutes !== 0 || strictlyAfter) date.setUTCHours(date.getUTCHours() + 1);
    if (date.getUTCHours() % 2 === 0) date.setUTCHours(date.getUTCHours() + 1);
    return date.toISOString().slice(0, 16);
}

function fillFromDelay(): void {
    if (locked.value || overview.value === null) return;
    const turns = overview.value.uncompensated_turns;
    if (turns < 1) {
        error.value = '未補填遅延がないため、遅延ターン分は入力できません。';
        return;
    }
    if (turns > 500 || turns * 2 > 31 * 24) {
        error.value = '遅延ターン分を一括入力できません。長期停止の全量補填は自動適用せず、追加回数と開催期間を個別に指定してください。';
        return;
    }
    try {
        const start = nextOddHour(startsAt.value);
        startsAt.value = start;
        additionalTurns.value = turns;
        endsAt.value = new Date(Date.parse(`${start}:00Z`) + turns * 2 * 3_600_000).toISOString().slice(0, 16);
        error.value = '';
        resultMessage.value = '指定開始時刻以降の奇数時00分から、遅延ターン分を入力しました。予約前に全追加枠を確認してください。';
    } catch {
        error.value = '開始日時を日本時間で入力してから、遅延ターン分を入力してください。';
    }
}

async function refresh(): Promise<void> {
    if (locked.value) return;
    busy.value = true;
    error.value = '';
    try {
        overview.value = await api<Overview>(endpoint.value);
    } catch {
        error.value = '予約一覧を取得できませんでした。状態を再読込してください。';
    } finally {
        busy.value = false;
    }
    if (!initializedTime && kind.value === 'turn_acceleration' && overview.value !== null) {
        initializedTime = true;
        fillFromDelay();
    }
}

function changeKind(): void {
    title.value = titles[kind.value];
    if (kind.value === 'turn_acceleration' && !initializedTime) {
        initializedTime = true;
        fillFromDelay();
    }
}

async function requestPreview(): Promise<void> {
    if (locked.value || overview.value === null) return;
    busy.value = true;
    error.value = '';
    resultMessage.value = '';
    try {
        const input = { kind: kind.value, title: title.value, starts_at: jstTimestamp(startsAt.value), ends_at: jstTimestamp(endsAt.value),
            ...(kind.value === 'hunting_ground' ? { repeat: repeat.value,
                repeat_count: repeat.value === 'none' ? 1 : repeatCount.value }
                : { additional_turns: additionalTurns.value }) };
        preview.value = await api<Preview>(`${endpoint.value}/preview`, { method: 'POST', body: JSON.stringify(input) });
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : '予約内容を確認できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function reserve(): Promise<void> {
    if (busy.value || preview.value === null) return;
    const wasUncertain = submitted.value;
    busy.value = true;
    submitted.value = true;
    error.value = '';
    try {
        const result = await api<{ series_id: number; duplicate: boolean }>(`${endpoint.value}/reserve`, {
            method: 'POST', body: JSON.stringify({ token: preview.value.token }),
        });
        // mutation成功を確定してから表示を更新する。再読込の失敗で新しい予約にはしない。
        preview.value = null;
        submitted.value = false;
        resultMessage.value = `予約系列 #${result.series_id} の登録を確認しました。`;
        emit('updated');
    } catch (failure) {
        if (failure instanceof ApiError && failure.status >= 400 && failure.status < 500
            && (!wasUncertain || failure.code === 'boost_reservation_rejected')) {
            submitted.value = false;
            preview.value = null;
        }
        error.value = `${failure instanceof Error ? failure.message : '予約結果を取得できませんでした。'}${submitted.value
            ? ' 同じ予約の「結果を確認する」で再確認してください。' : ' 内容を再確認してから予約してください。'}`;
    } finally {
        busy.value = false;
    }
    if (preview.value === null && !error.value) await refresh();
}

function requestCancellation(row: Occurrence): void {
    if (locked.value) return;
    cancellation.value = { row, futureSeries: false };
    error.value = '';
    resultMessage.value = '';
}

async function cancel(): Promise<void> {
    if (busy.value || cancellation.value === null) return;
    const wasUncertain = submitted.value;
    busy.value = true;
    submitted.value = true;
    error.value = '';
    const target = cancellation.value;
    try {
        await api(`${endpoint.value}/${target.row.id}/cancel`, {
            method: 'POST', body: JSON.stringify({ future_series: target.futureSeries }),
        });
        cancellation.value = null;
        submitted.value = false;
        resultMessage.value = `開催回 #${target.row.id}${target.futureSeries ? ' と以後の同系列' : ''}の取消を確認しました。`;
        emit('updated');
    } catch (failure) {
        if (!wasUncertain && failure instanceof ApiError && failure.status >= 400 && failure.status < 500) submitted.value = false;
        error.value = `${failure instanceof Error ? failure.message : '取消結果を取得できませんでした。'}${submitted.value
            ? ' 同じ取消の「結果を確認する」で再確認してください。' : ''}`;
    } finally {
        busy.value = false;
    }
    if (cancellation.value === null && !error.value) await refresh();
}
</script>

<template>
    <section class="admin-boosts" aria-label="ブースト予約の管理">
        <header class="admin-actions"><h2>ブースト予約</h2><button :disabled="locked" @click="refresh">予約一覧を再読込</button><button :disabled="locked" @click="emit('close')">予約画面を閉じる</button></header>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p v-if="resultMessage" role="status">{{ resultMessage }}</p>
        <p v-if="busy" role="status">確認しています…</p>
        <p v-if="overview">現在 T{{ overview.current_turn }} ／ 未補填遅延 {{ overview.uncompensated_turns }}ターン</p>
        <form v-if="!preview && !cancellation" aria-label="ブーストの予約入力" @submit.prevent="requestPreview">
            <fieldset :disabled="locked || !overview">
                <label>ブーストの種類<select v-model="kind" @change="changeKind"><option value="turn_acceleration">時間加速（遅延ターン分）</option><option value="hunting_ground">狩場ブースト</option></select></label>
                <label>公開タイトル<input v-model="title" required maxlength="120"></label>
                <label>開始日時（日本時間 JST）<input v-model="startsAt" type="datetime-local" required step="60"></label>
                <label>終了日時（日本時間 JST）<input v-model="endsAt" type="datetime-local" required step="60"></label>
                <template v-if="kind === 'hunting_ground'">
                    <label>繰り返し<select v-model="repeat"><option value="none">1回だけ</option><option value="daily">毎日</option><option value="weekly">毎週</option></select></label>
                    <label v-if="repeat !== 'none'">開催回数（初回を含む）<input v-model.number="repeatCount" type="number" required min="2" max="52" step="1"></label>
                    <p>狩場のレア出現率・通常敵の鍵出現率・XPが2倍。確率は100%上限で、G・鍵の個数・宝物庫のXPは変わりません。</p>
                    <p>毎日・毎週は日本時間の同時刻、最大52回、各回31日以内。同種の重複は予約できません。変更は取消後の新規予約で行います。</p>
                </template>
                <template v-else>
                    <label>追加回数の上限<input v-model.number="additionalTurns" type="number" required min="1" max="500" step="1"></label>
                    <button type="button" @click="fillFromDelay">遅延ターン分を入力</button>
                    <p>通常のJST偶数時00分は維持し、補填専用の更新で奇数時00分に追加します。追加だけでは120分間隔で、期間中の追加枠がある間は通常と合わせて1時間ごとの予定枠になります。指定回数が期間に収まるか、全追加時刻を確認画面で確認してください。</p>
                    <p>通常は箱庭歴1年が実時間1日です。時間加速は大規模障害の補填に使う例外で、通常のお祭り用途ではありません。追加は回数上限と実行時の未補填数までで、停止中は実行せず、取りこぼした枠や長期停止分を遡って自動実行しません。</p>
                    <p>遅延ターン分の入力は開始を奇数時00分へ揃え、未補填Nターンに対して2N時間を入力する補助で、自動予約ではありません。</p>
                </template>
                <p>開始を含み、終了は含みません。初回開始は現在以降1年以内です。確認画面へ進むだけでは予約されません。</p>
                <button class="button primary" type="submit">予約内容を確認する</button>
            </fieldset>
        </form>

        <section v-if="preview" class="admin-confirm" aria-label="ブースト予約の確認">
            <h3>{{ preview.title }}</h3>
            <p>{{ titles[preview.kind] }} ／ {{ preview.repeat_count }}回 ／ 1回 {{ preview.duration_hours }}時間</p>
            <p v-if="preview.kind === 'turn_acceleration'">未補填遅延 {{ preview.uncompensated_turns }}ターン ／ 追加上限 {{ preview.additional_turns }}回 ／ 追加間隔 {{ preview.interval_minutes }}分 ／ 確認時点の実行上限 {{ preview.effective_additional_turns }}回。確認後に手動補填などで状態が変わった場合は再確認が必要です。停止中や未補填解消後は追加枠を実行しません。取りこぼした枠を自動で繰り越しません。</p>
            <div class="admin-periods"><table><caption>開催日時（日本時間 JST、終了を含まない）</caption><thead><tr><th>回</th><th>開始</th><th>終了</th><th v-if="preview.kind === 'turn_acceleration'">追加枠</th></tr></thead><tbody><tr v-for="(window, index) in preview.windows" :key="window.starts_at"><td>{{ index + 1 }}</td><td>{{ formatDate(window.starts_at) }}</td><td>{{ formatDate(window.ends_at) }}</td><td v-if="preview.kind === 'turn_acceleration'">{{ window.additional_slots }}回</td></tr></tbody></table></div>
            <div v-if="preview.kind === 'turn_acceleration'" class="admin-periods" aria-label="追加予定時刻">
                <h4>追加予定時刻（日本時間 JST）</h4>
                <ol v-for="window in preview.windows" :key="window.starts_at"><li v-for="scheduledAt in window.additional_scheduled_ats" :key="scheduledAt"><time :datetime="scheduledAt">{{ formatDate(scheduledAt) }}</time></li></ol>
            </div>
            <div class="admin-actions"><button class="button primary" :disabled="busy" @click="reserve">{{ submitted ? '結果を確認する' : 'この内容で予約する' }}</button><button v-if="!submitted" :disabled="busy" @click="preview = null">入力に戻る</button></div>
        </section>

        <section v-if="cancellation" class="admin-confirm" aria-label="ブースト取消の確認">
            <h3>{{ cancellation.row.title }} — 開催回 #{{ cancellation.row.id }} の取消</h3>
            <p>系列 #{{ cancellation.row.series_id }} の {{ cancellation.row.occurrence_number }}回目 ／ {{ formatDate(cancellation.row.starts_at) }} ～ {{ formatDate(cancellation.row.ends_at) }} JST</p>
            <label>取消範囲<select v-model="cancellation.futureSeries" :disabled="locked"><option :value="false">この開催回だけ</option><option :value="true">この回と以後の同系列の未終了回</option></select></label>
            <p>開催中なら新たな受付から効果を停止します。既に受け付けた戦闘や完了ターンは巻き戻しません。</p>
            <div class="admin-actions"><button class="button primary" :disabled="busy" @click="cancel">{{ submitted ? '結果を確認する' : 'この範囲を取り消す' }}</button><button v-if="!submitted" :disabled="busy" @click="cancellation = null">取消をやめる</button></div>
        </section>

        <section v-if="overview && !preview && !cancellation" aria-label="ブーストの開催一覧">
            <h3>開催一覧（最新500回）</h3>
            <p v-if="!overview.occurrences.length">開催予約はありません。</p>
            <article v-for="row in overview.occurrences" :key="row.id" class="admin-boost-row" :aria-label="`開催回 #${row.id}`">
                <h4>{{ row.title }} ／ {{ labels[row.status] }}</h4>
                <p>{{ titles[row.kind] }} ／ 系列 #{{ row.series_id }}・{{ row.occurrence_number }}回目 ／ 開催回 #{{ row.id }}</p>
                <p>{{ formatDate(row.starts_at) }} ～ {{ formatDate(row.ends_at) }} JST</p>
                <button v-if="row.status === 'scheduled' || row.status === 'active'" :disabled="locked" @click="requestCancellation(row)">この回の取消を確認</button>
            </article>
        </section>
    </section>
</template>

<style scoped>
.admin-boosts { min-width: 0; overflow-wrap: anywhere; margin-top: 1rem; padding: 1rem; border: 1px solid var(--sl-line); border-radius: var(--sl-radius, 4px); }
.admin-actions { display: flex; align-items: center; gap: .65rem; flex-wrap: wrap; }
h2, h3, h4 { margin: .5rem 0; }
fieldset { border: 0; padding: 0; display: grid; gap: .75rem; }
label { display: grid; gap: .4rem; }
input, select { font: inherit; padding: .5rem; width: 100%; max-width: 32rem; box-sizing: border-box; }
.admin-confirm { padding: 1rem; margin: 1rem 0; border: 1px solid var(--sl-turn); border-radius: var(--sl-radius, 4px); background: var(--warning-surface, #fff9e9); color: var(--ink, #3d3526); }
.admin-periods { max-height: 20rem; overflow: auto; margin: .75rem 0; }
table { border-collapse: collapse; width: 100%; }
th, td { padding: .4rem; text-align: left; border-bottom: 1px solid var(--sl-line); }
.admin-boost-row { padding: .75rem 0; border-bottom: 1px solid var(--sl-line); }
</style>
