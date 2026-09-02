<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { ApiError, api } from '../api/client';
import EquipmentItemCard, { type AccessorySlot, type EquipmentItem, type EquipmentSlot } from './EquipmentItemCard.vue';
import UndergroundBulkSaleConfirmDialog from './UndergroundBulkSaleConfirmDialog.vue';

const equipmentSlots: EquipmentSlot[] = ['weapon', 'armor', 'accessory_1', 'accessory_2', 'accessory_3'];
const accessorySlots: AccessorySlot[] = ['accessory_1', 'accessory_2', 'accessory_3'];

interface VaultResponse {
    catalog_identity: string;
    used: number;
    capacity: number;
    equipped: Record<EquipmentSlot, EquipmentItem | null>;
    items: EquipmentItem[];
    page: number;
    per_page: number;
    last_page: number;
    total: number;
    bulk_sell_options?: BulkSellOptions;
}
interface MutationResponse {
    shard_balance: number;
    banked_shard_balance: number;
    vault: Pick<VaultResponse, 'used' | 'capacity' | 'equipped'>;
}
interface BulkSellOption {
    key: string;
    label: string;
}
interface BulkSellOptions {
    rarities: BulkSellOption[];
    categories: BulkSellOption[];
    weapon_styles: BulkSellOption[];
}
interface BulkSellPreviewResponse {
    catalog_identity: string;
    items: EquipmentItem[];
    count: number;
    total_sell_price: number;
}
interface BulkSellItem {
    id: number;
    sell_price: number;
}
interface BulkSellPreferences {
    item_level_max?: unknown;
    rarities?: unknown;
    categories?: unknown;
    weapon_styles?: unknown;
}

const bulkSellPreferenceKey = 'hakoniwa.underground.vault.bulk-sell-preferences';

const emit = defineEmits<{ updated: [value: MutationResponse] }>();
const vault = ref<VaultResponse | null>(null);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const pending = ref<{ fingerprint: string; requestId: string } | null>(null);
const accessoryTargetSlot = ref<AccessorySlot>('accessory_1');
const bulkSellOptions = ref<BulkSellOptions | null>(null);
const selectedRarityKeys = ref<string[]>([]);
const selectedCategoryKeys = ref<string[]>([]);
const selectedWeaponStyleKeys = ref<string[]>([]);
const itemLevelMaxDraft = ref<string | number>('');
const bulkPreview = ref<BulkSellPreviewResponse | null>(null);
const bulkPreviewLoading = ref(false);
const bulkConfirmOpen = ref(false);
const bulkPending = ref<{ fingerprint: string; requestId: string } | null>(null);
const bulkOptionsInitialized = ref(false);
let bulkOptionsSignature = '';

const bulkFilterDisabled = computed(() => busy.value || loading.value || bulkPreviewLoading.value);
const bulkPreviewCanConfirm = computed(() => {
    const preview = bulkPreview.value;
    if (!preview || preview.count < 1 || preview.items.length !== preview.count) return false;

    return preview.items.every((item) => typeof item.id === 'number'
        && Number.isInteger(item.id)
        && item.id >= 1
        && Number.isFinite(item.sell_price)
        && item.sell_price >= 1);
});

function requestId(): string { return crypto.randomUUID(); }
function isAccessorySlot(slot: EquipmentSlot | null | undefined): slot is AccessorySlot {
    return typeof slot === 'string' && slot.startsWith('accessory_');
}
function slotLabel(slot: EquipmentSlot | null | undefined): string {
    if (slot === 'weapon') return '武器';
    if (slot === 'armor') return '防具';
    if (isAccessorySlot(slot)) return `アクセサリー${accessorySlots.indexOf(slot) + 1}`;
    return 'アクセサリー';
}
function itemKey(item: EquipmentItem, index: number): string {
    return String(item.id ?? item.instance_identity ?? `${item.key}-${index}`);
}
function selectedSlotFor(item: EquipmentItem): EquipmentSlot | undefined {
    return item.category === 'accessory' ? accessoryTargetSlot.value : undefined;
}

function normalizeBulkOptionList(value: unknown): BulkSellOption[] {
    if (!Array.isArray(value)) return [];
    const seen = new Set<string>();

    return value.flatMap((candidate) => {
        if (typeof candidate !== 'object' || candidate === null || Array.isArray(candidate)) return [];
        const record = candidate as Record<string, unknown>;
        const key = record.key;
        const label = record.label;
        if (typeof key !== 'string' || key === '' || typeof label !== 'string' || label === '' || seen.has(key)) return [];
        seen.add(key);
        return [{ key, label }];
    });
}

function normalizeBulkOptions(options: BulkSellOptions | undefined): BulkSellOptions | null {
    if (!options) return null;

    return {
        rarities: normalizeBulkOptionList(options.rarities),
        categories: normalizeBulkOptionList(options.categories),
        weapon_styles: normalizeBulkOptionList(options.weapon_styles),
    };
}

function readBulkPreferences(): BulkSellPreferences | null {
    if (typeof window === 'undefined') return null;
    try {
        const stored = window.localStorage.getItem(bulkSellPreferenceKey);
        if (!stored) return null;
        const parsed: unknown = JSON.parse(stored);
        if (typeof parsed !== 'object' || parsed === null || Array.isArray(parsed)) return null;
        return parsed as BulkSellPreferences;
    } catch {
        return null;
    }
}

function optionKeys(options: BulkSellOption[]): string[] {
    return options.map((option) => option.key);
}

function restoreSelectedKeys(value: unknown, options: BulkSellOption[]): string[] {
    const allKeys = optionKeys(options);
    if (!Array.isArray(value)) return allKeys;
    const validKeys = new Set(allKeys);
    const selected = [...new Set(value.filter((key): key is string => typeof key === 'string' && validKeys.has(key)))];
    if (value.length > 0 && selected.length === 0) return allKeys;
    return selected;
}

function restoreItemLevelMax(value: unknown): string {
    if (typeof value === 'number' && Number.isInteger(value) && value >= 1) return String(value);
    if (typeof value !== 'string' || !/^\d+$/.test(value.trim())) return '';
    const parsed = Number(value);
    return Number.isInteger(parsed) && parsed >= 1 ? String(parsed) : '';
}

function persistBulkPreferences(): void {
    if (!bulkOptionsInitialized.value || typeof window === 'undefined') return;
    try {
        const raw = String(itemLevelMaxDraft.value).trim();
        window.localStorage.setItem(bulkSellPreferenceKey, JSON.stringify({
            item_level_max: raw === '' ? null : Number(raw),
            rarities: selectedRarityKeys.value,
            categories: selectedCategoryKeys.value,
            weapon_styles: selectedWeaponStyleKeys.value,
        }));
    } catch {
        // Private browsing or a disabled storage area should not block the vault.
    }
}

function invalidateBulkPreview(): void {
    bulkPreview.value = null;
    bulkConfirmOpen.value = false;
    bulkPending.value = null;
}

function syncBulkSellOptions(options: BulkSellOptions | undefined): void {
    const normalized = normalizeBulkOptions(options);
    if (!normalized) return;
    const signature = JSON.stringify(normalized);
    if (bulkOptionsInitialized.value && bulkOptionsSignature === signature) return;
    const changed = bulkOptionsInitialized.value && bulkOptionsSignature !== signature;
    bulkOptionsSignature = signature;
    bulkSellOptions.value = normalized;
    const stored = readBulkPreferences();
    itemLevelMaxDraft.value = restoreItemLevelMax(stored?.item_level_max);
    selectedRarityKeys.value = restoreSelectedKeys(stored?.rarities, normalized.rarities);
    selectedCategoryKeys.value = restoreSelectedKeys(stored?.categories, normalized.categories);
    selectedWeaponStyleKeys.value = restoreSelectedKeys(stored?.weapon_styles, normalized.weapon_styles);
    bulkOptionsInitialized.value = true;
    if (changed) invalidateBulkPreview();
}

function parseItemLevelMax(): number | null {
    const raw = String(itemLevelMaxDraft.value).trim();
    if (raw === '') return null;
    const parsed = Number(raw);
    return Number.isInteger(parsed) && parsed >= 1 ? parsed : null;
}

async function loadVault(page = vault.value?.page ?? 1): Promise<void> {
    invalidateBulkPreview();
    loading.value = true;
    error.value = '';
    try {
        const next = await api<VaultResponse>(`/api/v1/me/underground/equipment/vault?page=${page}`);
        vault.value = next;
        syncBulkSellOptions(next.bulk_sell_options);
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '宝物庫を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function isBulkConflict(caught: unknown): boolean {
    return caught instanceof ApiError && caught.status === 409;
}

function bulkMutationError(caught: unknown): string {
    if (isBulkConflict(caught) || (caught instanceof ApiError && caught.code === 'underground_request_conflict')) {
        return `売却状態が変わりました。プレビューをやり直してください。${caught instanceof Error ? caught.message : ''}`;
    }
    return caught instanceof Error ? caught.message : 'まとめ売却に失敗しました。再試行してください。';
}

function onBulkFilterChanged(): void {
    invalidateBulkPreview();
}

function openBulkConfirmation(): void {
    if (!bulkPreviewCanConfirm.value || bulkFilterDisabled.value) return;
    bulkConfirmOpen.value = true;
}

function concreteBulkItems(items: EquipmentItem[]): BulkSellItem[] | null {
    const concrete: BulkSellItem[] = [];
    for (const item of items) {
        if (typeof item.id !== 'number' || !Number.isInteger(item.id) || item.id < 1
            || !Number.isFinite(item.sell_price) || item.sell_price < 1) return null;
        concrete.push({ id: item.id, sell_price: item.sell_price });
    }
    return concrete;
}

function bulkFingerprint(preview: BulkSellPreviewResponse): string {
    return JSON.stringify({
        catalog_identity: preview.catalog_identity,
        count: preview.count,
        total_sell_price: preview.total_sell_price,
        items: preview.items.map((item) => [item.id, item.sell_price]),
    });
}

async function previewBulkSale(): Promise<void> {
    if (bulkFilterDisabled.value || !bulkSellOptions.value) return;
    invalidateBulkPreview();
    const itemLevelMax = parseItemLevelMax();
    if (itemLevelMax === null && String(itemLevelMaxDraft.value).trim() !== '') {
        error.value = 'Item Lvを確認してください。';
        return;
    }

    bulkPreviewLoading.value = true;
    error.value = '';
    try {
        bulkPreview.value = await api<BulkSellPreviewResponse>('/api/v1/me/underground/equipment/vault/bulk-sell/preview', {
            method: 'POST',
            body: JSON.stringify({
                item_level_max: itemLevelMax,
                rarities: [...selectedRarityKeys.value],
                categories: [...selectedCategoryKeys.value],
                weapon_styles: [...selectedWeaponStyleKeys.value],
            }),
        });
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '売却候補を読み込めませんでした。';
    } finally {
        bulkPreviewLoading.value = false;
    }
}

async function confirmBulkSale(): Promise<void> {
    const preview = bulkPreview.value;
    if (!preview || !bulkPreviewCanConfirm.value || busy.value) return;
    const items = concreteBulkItems(preview.items);
    if (!items || items.length !== preview.count) {
        error.value = '売却候補を確認できません。プレビューをやり直してください。';
        return;
    }

    const fingerprint = bulkFingerprint(preview);
    const request = bulkPending.value?.fingerprint === fingerprint
        ? bulkPending.value
        : { fingerprint, requestId: requestId() };
    bulkPending.value = request;
    busy.value = true;
    error.value = '';
    try {
        const result = await api<MutationResponse>('/api/v1/me/underground/equipment/vault/bulk-sell', {
            method: 'POST',
            body: JSON.stringify({
                request_id: request.requestId,
                catalog_identity: preview.catalog_identity,
                items,
            }),
        });
        emit('updated', result);
        bulkPending.value = null;
        bulkConfirmOpen.value = false;
        bulkPreview.value = null;
        await loadVault(1);
    } catch (caught) {
        error.value = bulkMutationError(caught);
        if (isBulkConflict(caught)) {
            bulkPending.value = null;
            bulkConfirmOpen.value = false;
            bulkPreview.value = null;
        }
    } finally {
        busy.value = false;
    }
}

watch(
    [selectedRarityKeys, selectedCategoryKeys, selectedWeaponStyleKeys, itemLevelMaxDraft],
    () => persistBulkPreferences(),
    { deep: true },
);

function mutationError(caught: unknown): string {
    if (caught instanceof ApiError && caught.code === 'underground_request_conflict') return `request IDの競合です。${caught.message}`;
    return caught instanceof Error ? caught.message : '装備の変更に失敗しました。再試行してください。';
}

async function mutate(action: 'equip' | 'unequip', item?: EquipmentItem, selectedSlot?: EquipmentSlot): Promise<void> {
    if (busy.value || (action === 'equip' && item?.id === undefined)) return;
    const slot = action === 'equip'
        ? item?.category === 'accessory' ? (selectedSlot ?? 'accessory_1') : item?.category
        : item?.equipped_slot;
    if (!slot || (action === 'unequip' && slot === 'weapon')) return;
    const fingerprint = `${action}:${action === 'equip' ? `${item?.id}:${slot}` : slot}`;
    const request = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: requestId() };
    pending.value = request;
    busy.value = true;
    error.value = '';
    try {
        const path = action === 'equip' ? '/api/v1/me/underground/equipment/equipped' : `/api/v1/me/underground/equipment/equipped/${slot}`;
        const body = action === 'equip'
            ? { request_id: request.requestId, item_id: item!.id, target_slot: slot }
            : { request_id: request.requestId };
        const result = await api<MutationResponse>(path, { method: action === 'equip' ? 'PUT' : 'DELETE', body: JSON.stringify(body) });
        emit('updated', result);
        pending.value = null;
        await loadVault();
    } catch (caught) {
        error.value = mutationError(caught);
    } finally {
        busy.value = false;
    }
}

onMounted(() => { void loadVault(); });
</script>

<template>
    <section class="underground-equipment-screen" aria-labelledby="underground-equipment-vault-title">
        <header class="underground-equipment-screen-heading">
            <div><p class="eyebrow">Underground Equipment</p><h1 id="underground-equipment-vault-title">宝物庫</h1><p>所有アイテムを確認し、武器1つ・防具1つ・アクセサリー3枠まで装備できます。</p></div>
            <div v-if="vault" class="underground-vault-capacity"><strong>{{ vault.used }} / {{ vault.capacity }}</strong><span>使用中 / 容量</span></div>
        </header>
        <p v-if="loading" class="status" role="status">宝物庫を読み込んでいます…</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>
        <template v-if="vault">
            <section class="underground-equipped-slots" aria-labelledby="underground-equipped-title">
                <h2 id="underground-equipped-title">現在の装備</h2>
                <div class="underground-equipped-slot-grid">
                    <article v-for="slot in equipmentSlots" :key="slot" class="underground-equipped-slot">
                        <h3>{{ slotLabel(slot) }}</h3>
                        <p v-if="vault.equipped[slot]"><strong>{{ vault.equipped[slot]!.name }}</strong></p><p v-else class="underground-equipment-empty">空き</p>
                        <button v-if="vault.equipped[slot] && slot !== 'weapon'" class="button secondary" type="button" :disabled="busy" @click="mutate('unequip', vault.equipped[slot]!)">外す</button>
                        <small v-if="slot === 'weapon' && vault.equipped[slot]">武器は常に1つ必要です</small>
                    </article>
                </div>
            </section>
            <section v-if="bulkSellOptions" class="underground-bulk-sale-panel" aria-labelledby="underground-bulk-sale-title">
                <header>
                    <div>
                        <h2 id="underground-bulk-sale-title">装備のまとめ売り</h2>
                        <p>条件に一致する未装備の装備をサーバーで確認します。</p>
                    </div>
                </header>
                <div class="underground-bulk-sale-options">
                    <label>
                        <span>Item Lv以下</span>
                        <input v-model="itemLevelMaxDraft" type="number" min="1" inputmode="numeric" placeholder="未入力" :disabled="bulkFilterDisabled" @input="onBulkFilterChanged">
                    </label>
                    <fieldset>
                        <legend>レアリティ</legend>
                        <label v-for="option in bulkSellOptions.rarities" :key="`rarity-${option.key}`">
                            <input v-model="selectedRarityKeys" type="checkbox" :value="option.key" :disabled="bulkFilterDisabled" @change="onBulkFilterChanged">
                            <span>{{ option.label }}</span>
                        </label>
                    </fieldset>
                    <fieldset>
                        <legend>カテゴリ</legend>
                        <label v-for="option in bulkSellOptions.categories" :key="`category-${option.key}`">
                            <input v-model="selectedCategoryKeys" type="checkbox" :value="option.key" :disabled="bulkFilterDisabled" @change="onBulkFilterChanged">
                            <span>{{ option.label }}</span>
                        </label>
                    </fieldset>
                    <fieldset>
                        <legend>武器スタイル</legend>
                        <label v-for="option in bulkSellOptions.weapon_styles" :key="`weapon-style-${option.key}`">
                            <input v-model="selectedWeaponStyleKeys" type="checkbox" :value="option.key" :disabled="bulkFilterDisabled" @change="onBulkFilterChanged">
                            <span>{{ option.label }}</span>
                        </label>
                    </fieldset>
                </div>
                <button class="button primary underground-bulk-preview-button" type="button" :disabled="bulkFilterDisabled" @click="previewBulkSale">
                    {{ bulkPreviewLoading ? '候補を確認中…' : '売却候補をプレビュー' }}
                </button>
            </section>
            <section v-if="bulkPreview" class="underground-bulk-sale-preview" aria-labelledby="underground-bulk-preview-title">
                <header>
                    <h2 id="underground-bulk-preview-title">売却候補</h2>
                </header>
                <dl>
                    <div><dt>対象</dt><dd>{{ bulkPreview.count.toLocaleString('ja-JP') }}個</dd></div>
                    <div><dt>合計売却額</dt><dd>{{ bulkPreview.total_sell_price.toLocaleString('ja-JP') }}G</dd></div>
                </dl>
                <ul class="underground-bulk-preview-list" aria-label="売却候補の装備">
                    <li v-for="item in bulkPreview.items" :key="item.id ?? item.instance_identity ?? item.key">
                        <span><strong>{{ item.name }}</strong><small>Item Lv {{ item.item_level }}・{{ item.rarity_label ?? item.rarity }}</small></span>
                        <strong>{{ item.sell_price.toLocaleString('ja-JP') }}G</strong>
                    </li>
                </ul>
                <p v-if="bulkPreview.count === 0" class="status" role="status">条件に一致する装備はありません。</p>
                <p v-else-if="!bulkPreviewCanConfirm" class="status error" role="alert">売却候補を確認できません。プレビューをやり直してください。</p>
                <button class="button primary underground-bulk-confirm-trigger" type="button" :disabled="!bulkPreviewCanConfirm || bulkFilterDisabled" @click="openBulkConfirmation">売却内容を確認する</button>
            </section>
            <div class="underground-vault-toolbar"><p>全{{ vault.total }}件・1ページ{{ vault.per_page }}件</p><div><button v-if="vault.page > 1" class="button secondary" type="button" :disabled="loading" @click="loadVault(vault.page - 1)">前へ</button><span> {{ vault.page }} / {{ vault.last_page }} </span><button v-if="vault.page < vault.last_page" class="button secondary" type="button" :disabled="loading" @click="loadVault(vault.page + 1)">次へ</button></div></div>
            <div class="underground-equipment-card-grid underground-vault-items">
                <EquipmentItemCard v-for="(item, index) in vault.items" :key="itemKey(item, index)" :item="item" mode="vault" :disabled="busy || item.equipped_slot !== null" @action="mutate('equip', item, selectedSlotFor(item))">
                    <template #status>
                        <span v-if="item.equipped_slot" class="underground-equipment-equipped">{{ slotLabel(item.equipped_slot) }}として装備中</span><span v-else>未装備</span>
                        <label v-if="item.category === 'accessory' && !item.equipped_slot" class="underground-accessory-target">
                            <span>装備先</span>
                            <select v-model="accessoryTargetSlot" :disabled="busy" aria-label="アクセサリー装備先">
                                <option v-for="slot in accessorySlots" :key="slot" :value="slot">{{ slotLabel(slot) }}</option>
                            </select>
                        </label>
                    </template>
                    <template #action>{{ item.equipped_slot ? '装備中' : '装備する' }}</template>
                </EquipmentItemCard>
            </div>
            <UndergroundBulkSaleConfirmDialog
                v-if="bulkConfirmOpen && bulkPreview"
                :items="bulkPreview.items"
                :count="bulkPreview.count"
                :total-sell-price="bulkPreview.total_sell_price"
                :submitting="busy"
                @cancel="bulkConfirmOpen = false"
                @confirm="confirmBulkSale"
            />
        </template>
    </section>
</template>
