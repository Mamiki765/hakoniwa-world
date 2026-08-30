<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import EquipmentItemCard, { type EquipmentItem } from './EquipmentItemCard.vue';

interface VaultResponse {
    catalog_identity: string;
    used: number;
    capacity: number;
    equipped: Record<'weapon' | 'armor' | 'accessory', EquipmentItem | null>;
    items: EquipmentItem[];
    page: number;
    per_page: number;
    last_page: number;
    total: number;
}
interface MutationResponse {
    shard_balance: number;
    banked_shard_balance: number;
    vault: Pick<VaultResponse, 'used' | 'capacity' | 'equipped'>;
}

const emit = defineEmits<{ updated: [value: MutationResponse] }>();
const vault = ref<VaultResponse | null>(null);
const busy = ref(false);
const loading = ref(true);
const error = ref('');
const pending = ref<{ fingerprint: string; requestId: string } | null>(null);

function requestId(): string { return crypto.randomUUID(); }
function slotLabel(slot: string): string { return slot === 'weapon' ? '武器' : slot === 'armor' ? '防具' : 'アクセサリー'; }

async function loadVault(page = vault.value?.page ?? 1): Promise<void> {
    loading.value = true;
    error.value = '';
    try {
        vault.value = await api<VaultResponse>(`/api/v1/me/underground/equipment/vault?page=${page}`);
    } catch (caught) {
        error.value = caught instanceof Error ? caught.message : '宝物庫を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function mutationError(caught: unknown): string {
    if (caught instanceof ApiError && caught.code === 'underground_request_conflict') return `request IDの競合です。${caught.message}`;
    return caught instanceof Error ? caught.message : '装備の変更に失敗しました。再試行してください。';
}

async function mutate(action: 'equip' | 'unequip', item?: EquipmentItem): Promise<void> {
    const slot = action === 'equip' ? item?.category : item?.equipped_slot;
    if (!slot || busy.value || (action === 'unequip' && slot === 'weapon')) return;
    const fingerprint = `${action}:${action === 'equip' ? item?.id : slot}`;
    const request = pending.value?.fingerprint === fingerprint ? pending.value : { fingerprint, requestId: requestId() };
    pending.value = request;
    busy.value = true;
    error.value = '';
    try {
        const path = action === 'equip' ? '/api/v1/me/underground/equipment/equipped' : `/api/v1/me/underground/equipment/equipped/${slot}`;
        const result = await api<MutationResponse>(path, { method: action === 'equip' ? 'PUT' : 'DELETE', body: JSON.stringify({ request_id: request.requestId, ...(action === 'equip' ? { item_id: item?.id } : {}) }) });
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
            <div><p class="eyebrow">Underground Equipment</p><h1 id="underground-equipment-vault-title">宝物庫</h1><p>所有アイテムを確認し、武器・防具・アクセサリーを各1つまで装備できます。</p></div>
            <div v-if="vault" class="underground-vault-capacity"><strong>{{ vault.used }} / {{ vault.capacity }}</strong><span>使用中 / 容量</span></div>
        </header>
        <p v-if="loading" class="status" role="status">宝物庫を読み込んでいます…</p>
        <p v-if="error" class="status error" role="alert">{{ error }}</p>
        <template v-if="vault">
            <section class="underground-equipped-slots" aria-labelledby="underground-equipped-title">
                <h2 id="underground-equipped-title">現在の装備</h2>
                <div class="underground-equipped-slot-grid">
                    <article v-for="slot in (['weapon', 'armor', 'accessory'] as const)" :key="slot" class="underground-equipped-slot">
                        <h3>{{ slotLabel(slot) }}</h3>
                        <p v-if="vault.equipped[slot]"><strong>{{ vault.equipped[slot]!.name }}</strong></p><p v-else class="underground-equipment-empty">空き</p>
                        <button v-if="vault.equipped[slot] && slot !== 'weapon'" class="button secondary" type="button" :disabled="busy" @click="mutate('unequip', vault.equipped[slot]!)">外す</button>
                        <small v-if="slot === 'weapon' && vault.equipped[slot]">武器は常に1つ必要です</small>
                    </article>
                </div>
            </section>
            <div class="underground-vault-toolbar"><p>全{{ vault.total }}件・1ページ{{ vault.per_page }}件</p><div><button v-if="vault.page > 1" class="button secondary" type="button" :disabled="loading" @click="loadVault(vault.page - 1)">前へ</button><span> {{ vault.page }} / {{ vault.last_page }} </span><button v-if="vault.page < vault.last_page" class="button secondary" type="button" :disabled="loading" @click="loadVault(vault.page + 1)">次へ</button></div></div>
            <div class="underground-equipment-card-grid underground-vault-items">
                <EquipmentItemCard v-for="item in vault.items" :key="item.id" :item="item" mode="vault" :disabled="busy || item.equipped_slot !== null" @action="mutate('equip', item)">
                    <template #status><span v-if="item.equipped_slot" class="underground-equipment-equipped">{{ slotLabel(item.equipped_slot) }}として装備中</span><span v-else>未装備</span></template>
                    <template #action>{{ item.equipped_slot ? '装備中' : '装備する' }}</template>
                </EquipmentItemCard>
            </div>
        </template>
    </section>
</template>
