<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import type { SalePolicy } from '../types';

const props = defineProps<{ nationId: number }>();
const policies = ref<SalePolicy[]>([]);
const busyResource = ref<number | null>(null);
const message = ref('');

onMounted(load);

async function load(): Promise<void> {
    try {
        policies.value = await api<SalePolicy[]>(`/api/v1/nations/${props.nationId}/sale-policies`);
    } catch (error) {
        message.value = error instanceof Error ? error.message : '売却方針を取得できませんでした。';
    }
}

async function save(policy: SalePolicy): Promise<void> {
    busyResource.value = policy.resource_id;
    message.value = '';
    try {
        const updated = await api<SalePolicy>(`/api/v1/nations/${props.nationId}/resources/${policy.resource_id}/sale-policy`, {
            method: 'PUT',
            body: JSON.stringify({
                policy: policy.policy,
                keep_amount: policy.policy === 'keep_amount' ? policy.keep_amount ?? 0 : null,
                expected_version: policy.version,
            }),
        });
        Object.assign(policy, updated);
        message.value = `${policy.resource_name}の売却方針を保存しました。`;
    } catch (error) {
        message.value = error instanceof ApiError && error.status === 409
            ? '他の操作で更新されています。最新状態を再取得しました。'
            : error instanceof Error ? error.message : '売却方針を保存できませんでした。';
        if (error instanceof ApiError && error.status === 409) await load();
    } finally {
        busyResource.value = null;
    }
}

function allows(policy: SalePolicy, value: SalePolicy['policy']): boolean {
    return (policy.allowed_policies ?? ['sell_all', 'stockpile', 'keep_amount']).includes(value);
}
</script>

<template>
    <section class="panel resource-panel">
        <p class="eyebrow">RESOURCE POLICY</p>
        <h1>資源の売却方針</h1>
        <p>食料消費後のターン処理で、資源ごとの方針に従って自動売却します。「上限まで備蓄」は個別上限を超えた分だけを売却し、売れない超過分を破棄します。</p>
        <p v-if="message" class="compact-message" role="status">{{ message }}</p>
        <div class="policy-list">
            <form v-for="policy in policies" :key="policy.resource_id" @submit.prevent="save(policy)">
                <strong>{{ policy.resource_name }}</strong>
                <span>在庫 {{ policy.amount.toLocaleString() }}{{ policy.unit_label ?? '' }}</span>
                <label>
                    方針
                    <select v-model="policy.policy">
                        <option v-if="allows(policy, 'stockpile')" value="stockpile">上限まで備蓄</option>
                        <option v-if="allows(policy, 'sell_all')" value="sell_all">すべて売却</option>
                        <option v-if="allows(policy, 'keep_amount')" value="keep_amount">指定数を残して売却</option>
                    </select>
                </label>
                <label v-if="policy.policy === 'keep_amount'">
                    保持数<span v-if="policy.unit_label">（{{ policy.unit_label }}）</span>
                    <input v-model.number="policy.keep_amount" type="number" min="0" required>
                </label>
                <button type="submit" :disabled="busyResource === policy.resource_id">保存</button>
            </form>
        </div>
    </section>
</template>
