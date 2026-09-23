<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { ApiError, api } from '../api/client';
import type { CompensationAssetKey } from '../types';

interface Overview {
    world: { id: number; name: string; current_turn: number };
    user_count: number;
    assets: Record<CompensationAssetKey, { label: string; unit: string }>;
    nations: Array<{ id: number; nation_number: number; name: string; state: string }>;
    recent_secretaries: Array<{ id: number; user_id: number; name: string | null }>;
}
interface PurgeRow {
    profile_id: number; stream: string; candidates: number; old_receipts_in_scan: number;
    oldest_scanned_at: string | null; stop_reason: string; scan_limit: number;
}
interface Preview {
    token: string;
    recipients?: Array<{ user_id: number; name: string; nation_name: string | null; nation_number: number | null }>;
    assets?: Partial<Record<CompensationAssetKey, number>>;
    reason?: string; nation_name?: string; nation_number?: number; public_reason?: string;
    operator_note?: string; auctions?: Array<{ id: number; current_price: number; bid_count: number }>;
    preview?: PurgeRow;
}
type Operation = 'distribution' | 'abandonment' | 'purge';
const props = defineProps<{ worldId: number }>();
const emit = defineEmits<{ close: []; announcements: []; guide: []; inquiries: [] }>();
const overview = ref<Overview | null>(null);
const purgeRows = ref<PurgeRow[]>([]);
const busy = ref(false);
const error = ref('');
const resultMessage = ref('');
const section = ref<'distribution' | 'abandonment' | 'purge' | null>(null);
const preview = ref<Preview | null>(null);
const operation = ref<Operation | null>(null);
const submitted = ref(false);
const targetKind = ref<'all' | 'nation' | 'secretary' | 'user'>('all');
const selectedIds = ref<number[]>([]);
const manualIds = ref('');
const reason = ref('');
const assets = reactive<Partial<Record<CompensationAssetKey, number>>>({});
const nationId = ref<number | null>(null);
const publicReason = ref('');
const operatorNote = ref('');
const confirmationName = ref('');
const locked = computed(() => busy.value || submitted.value);
const endpoint = computed(() => `/api/v1/admin/worlds/${props.worldId}`);

onMounted(refresh);

async function refresh(): Promise<void> {
    busy.value = true;
    error.value = '';
    const results = await Promise.allSettled([
        api<Overview>(`${endpoint.value}/operations`).then((value) => { overview.value = value; }),
        api<PurgeRow[]>('/api/v1/admin/receipt-purge/status').then((value) => { purgeRows.value = value; }),
    ]);
    if (results.some((result) => result.status === 'rejected')) error.value = '管理情報の一部を取得できませんでした。再読込してください。';
    busy.value = false;
}

function selectSection(value: typeof section.value): void {
    if (locked.value) return;
    section.value = value;
    preview.value = null;
    error.value = '';
    resultMessage.value = '';
}

async function requestPreview(value: Operation, input: object): Promise<void> {
    if (locked.value) return;
    busy.value = true;
    preview.value = null;
    error.value = '';
    resultMessage.value = '';
    try {
        const body = value === 'distribution' ? { ...input, assets: Object.fromEntries(Object.entries(assets).filter(([, amount]) => typeof amount === 'number')) } : input;
        preview.value = await api<Preview>(`${endpoint.value}/operations/${value}/preview`, { method: 'POST', body: JSON.stringify(body) });
        operation.value = value;
        confirmationName.value = '';
    } catch (failure) {
        error.value = failure instanceof Error ? failure.message : '内容を確認できませんでした。';
    } finally {
        busy.value = false;
    }
}

async function apply(): Promise<void> {
    if (busy.value || preview.value === null || operation.value === null) return;
    busy.value = true;
    submitted.value = true;
    error.value = '';
    const currentOperation = operation.value;
    try {
        const result = await api<{ recipient_count?: number; candidates?: number }>(`${endpoint.value}/operations/${currentOperation}/apply`, {
            method: 'POST', body: JSON.stringify({ token: preview.value.token }),
        });
        // A refresh failure must never turn a confirmed mutation into a new operation.
        preview.value = null;
        submitted.value = false;
        resultMessage.value = currentOperation === 'distribution' ? `${result.recipient_count}人へ配布しました。`
            : currentOperation === 'abandonment' ? '島の整理と重大ニュースへの掲載が完了しました。'
                : `${result.candidates}件の古いログを削除しました。`;
    } catch (failure) {
        if (failure instanceof ApiError && failure.status >= 400 && failure.status < 500) submitted.value = false;
        error.value = `${failure instanceof Error ? failure.message : '結果を取得できませんでした。'}${submitted.value ? ' 同じ操作の「結果を確認する」で再確認できます。' : ''}`;
    } finally {
        busy.value = false;
    }
    if (preview.value === null) await refresh();
}

const stopLabels: Record<string, string> = {
    unverified_receipt: '恒久集計・検証が未完了', batch_limit: '今回の確認件数の上限',
    no_more_receipts: '確認範囲の末尾', unfinished_receipt: '未完了の記録', too_recent: '保持期間内',
};
</script>

<template>
    <section class="admin-operations panel" aria-labelledby="admin-heading">
        <header class="section-heading">
            <div><p class="eyebrow">ADMINISTRATION</p><h1 id="admin-heading">管理ページ</h1></div>
            <div class="admin-actions"><button :disabled="busy" @click="refresh">状態を再読込</button><button :disabled="locked" @click="emit('close')">TOPへ戻る</button></div>
        </header>
        <p v-if="error" class="field-error" role="alert">{{ error }}</p>
        <p v-if="resultMessage" role="status">{{ resultMessage }}</p>
        <p v-if="busy" role="status">確認しています…</p>
        <aside v-if="purgeRows.length" class="admin-notice">
            <strong>古いログが溜まっています</strong>
            <p>30日を過ぎた記録があります。削除可能な範囲と保留理由を確認できます。</p>
            <button :disabled="locked" @click="selectSection('purge')">古いログを整理</button>
        </aside>
        <nav class="admin-menu" aria-label="管理項目">
            <button :disabled="locked" @click="selectSection('distribution')">配布</button>
            <button :disabled="locked" @click="selectSection('abandonment')">島整理</button>
            <button :disabled="locked" @click="emit('announcements')">お知らせ管理</button>
            <button :disabled="locked" @click="emit('guide')">案内人の会話</button>
            <button :disabled="locked" @click="emit('inquiries')">お問い合わせ一覧</button>
            <button :disabled="locked" @click="selectSection('purge')">ログ整理</button>
        </nav>

        <form v-if="overview && section === 'distribution' && !preview" @submit.prevent="requestPreview('distribution', { target_kind: targetKind, selected_ids: selectedIds, manual_ids: manualIds, reason, assets })">
            <h2>配布</h2>
            <p>1人につき下記の量を配布します。受取期限は365日です。島なしの地上資産は受取可能になるまで倉庫に保留します。</p>
            <fieldset :disabled="locked">
                <label>対象の種類<select v-model="targetKind" @change="selectedIds = []; manualIds = ''">
                    <option value="all">全User（島なし・休眠含む、現在{{ overview.user_count }}人）</option>
                    <option value="nation">島番号で選択</option><option value="secretary">秘書IDで選択</option><option value="user">User IDで指定</option>
                </select></label>
                <div v-if="targetKind === 'nation'" class="admin-selection">
                    <label v-for="target in overview.nations" :key="target.id"><input v-model="selectedIds" type="checkbox" :value="target.nation_number">#{{ target.nation_number }} {{ target.name }}（{{ target.state }}）</label>
                </div>
                <div v-if="targetKind === 'secretary'" class="admin-selection">
                    <p>直近7日以内に戦闘・スキップした秘書</p>
                    <label v-for="target in overview.recent_secretaries" :key="target.id"><input v-model="selectedIds" type="checkbox" :value="target.id">ID {{ target.id }} {{ target.name ?? '未命名' }}（User {{ target.user_id }}）</label>
                </div>
                <label v-if="targetKind !== 'all'">番号を追加（選択中の種類の番号）<input v-model="manualIds" placeholder="1-4,10,14,15"></label>
                <label>配布理由<textarea v-model="reason" required maxlength="2000" /></label>
                <div class="admin-assets"><label v-for="(asset, key) in overview.assets" :key="key">{{ asset.label }}（{{ asset.unit }}）<input v-model.number="assets[key]" type="number" min="0" step="1" placeholder="0"></label></div>
                <button class="button primary" type="submit">配布内容を確認する</button>
            </fieldset>
        </form>

        <form v-if="overview && section === 'abandonment' && !preview" @submit.prevent="requestPreview('abandonment', { nation_id: nationId, public_reason: publicReason, operator_note: operatorNote })">
            <h2>島整理</h2>
            <p>対象島に関係する競売を即決し、島・地上資産・船を既存の破棄処理で整理します。User・秘書・地底進行は残ります。</p>
            <fieldset :disabled="locked">
                <label>対象島<select v-model="nationId" required><option :value="null" disabled>島を選択</option><option v-for="target in overview.nations" :key="target.id" :value="target.id">#{{ target.nation_number }} {{ target.name }}</option></select></label>
                <label>公開理由（重大ニュースに掲載）<textarea v-model="publicReason" required maxlength="2000" /></label>
                <label>運営備考（非公開・任意）<textarea v-model="operatorNote" maxlength="4000" /></label>
                <button type="submit">整理内容を確認する</button>
            </fieldset>
        </form>

        <section v-if="section === 'purge' && !preview">
            <h2>古いログの整理</h2>
            <p>状態の確認だけでは削除しません。各区分の先頭500件を調べ、恒久集計と検証が完了した連続範囲だけを手動で削除します。</p>
            <p v-if="!purgeRows.length">今回の確認範囲に古いログはありません。</p>
            <article v-for="row in purgeRows" :key="`${row.profile_id}:${row.stream}`" class="admin-log-row">
                <strong>プロフィール {{ row.profile_id }} / {{ row.stream }}</strong>
                <p>確認範囲の古い記録 {{ row.old_receipts_in_scan }}件 ／ 削除候補 {{ row.candidates }}件</p>
                <p>最古：{{ row.oldest_scanned_at ?? '不明' }} ／ 停止理由：{{ stopLabels[row.stop_reason] ?? row.stop_reason }}</p>
                <button :disabled="locked || row.candidates === 0" @click="requestPreview('purge', { profile_id: row.profile_id, stream: row.stream })">削除範囲を確認する</button>
            </article>
        </section>

        <section v-if="preview" class="admin-confirm" aria-label="実行内容の確認">
            <h2>実行内容の確認</h2>
            <template v-if="operation === 'distribution'">
                <p>{{ preview.reason }}</p><p>受取User {{ preview.recipients?.length }}人（同じUserは1回）／受取期限：発行から365日</p>
                <ul><li v-for="(amount, key) in preview.assets" :key="key">1人あたり {{ overview?.assets[key].label }} {{ amount }}{{ overview?.assets[key].unit }}</li></ul>
                <ul class="admin-selection"><li v-for="recipient in preview.recipients" :key="recipient.user_id">User {{ recipient.user_id }} {{ recipient.name }} ／ {{ recipient.nation_name ?? '島なし（地上資産は倉庫に保留）' }}</li></ul>
            </template>
            <template v-else-if="operation === 'abandonment'">
                <p>#{{ preview.nation_number }} {{ preview.nation_name }}</p>
                <p>{{ preview.nation_name }}は運営により存在を消され、忘れ去られる。理由：{{ preview.public_reason }}</p>
                <p>即決する競売：{{ preview.auctions?.length }}件</p>
                <ul><li v-for="auction in preview.auctions" :key="auction.id">競売 #{{ auction.id }}：{{ auction.bid_count > 0 ? `${auction.current_price}億円で決算` : '入札なし・返却' }}</li></ul>
                <p v-if="preview.operator_note">非公開備考：{{ preview.operator_note }}</p>
                <label>確認のため島名を入力<input v-model="confirmationName" :disabled="locked"></label>
            </template>
            <p v-else>プロフィール {{ preview.preview?.profile_id }} / {{ preview.preview?.stream }} の検証済み記録 {{ preview.preview?.candidates }}件を削除します。</p>
            <div class="admin-actions">
                <button :disabled="busy || (!submitted && operation === 'abandonment' && confirmationName !== preview.nation_name)" class="button primary" @click="apply">{{ submitted ? '結果を確認する' : 'この内容で実行する' }}</button>
                <button v-if="!submitted" :disabled="busy" @click="preview = null">内容を変更する</button>
            </div>
        </section>
    </section>
</template>

<style scoped>
.admin-operations { max-width: 1050px; margin: auto; }
.admin-menu, .admin-actions { display: flex; gap: .65rem; flex-wrap: wrap; }
.admin-menu { margin: 1.5rem 0; }
.admin-notice, .admin-confirm { border: 1px solid #aa8736; background: #fff9e9; color: #3d3526; padding: 1rem; margin: 1rem 0; border-radius: .6rem; }
fieldset { border: 0; padding: 0; display: grid; gap: 1rem; }
fieldset > label, .admin-assets label { display: grid; gap: .4rem; }
input, select, textarea { font: inherit; padding: .5rem; max-width: 100%; }
textarea { min-height: 5rem; }
.admin-assets { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; }
.admin-selection { max-height: 20rem; overflow: auto; }
.admin-selection label { display: block; padding: .3rem; }
.admin-log-row { border-bottom: 1px solid #bbb; padding: 1rem 0; }
</style>
