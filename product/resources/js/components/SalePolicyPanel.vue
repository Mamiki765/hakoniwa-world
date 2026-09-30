<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { ApiError, api } from '../api/client';
import type { NationResource, SalePolicy } from '../types';

const props = defineProps<{
    nationId: number;
    resources?: Array<Pick<NationResource, 'key' | 'capacity'>>;
    population?: number;
}>();
const policies = ref<SalePolicy[]>([]);
const sliderLimits = ref<Record<number, number>>({});
const wheatAdjusted = ref(false);
const busyResource = ref<number | null>(null);
const message = ref('');

onMounted(load);

async function load(): Promise<void> {
    try {
        policies.value = await api<SalePolicy[]>(`/api/v1/nations/${props.nationId}/sale-policies`);
        sliderLimits.value = Object.fromEntries(policies.value.map((policy) => [
            policy.resource_id,
            props.resources?.find((resource) => resource.key === policy.resource_key)?.capacity ?? Math.max(1, policy.amount, policy.keep_amount ?? 0),
        ]));
        wheatAdjusted.value = false;
        for (const policy of policies.value) {
            if (wheatLocked(policy) && policy.policy !== 'stockpile') {
                policy.policy = 'stockpile';
                policy.keep_amount = null;
                wheatAdjusted.value = true;
            } else if (policy.resource_key === 'wheat' && policy.policy === 'keep_amount' && (policy.keep_amount ?? 0) < minimumHolding(policy)) {
                policy.keep_amount = minimumHolding(policy);
                wheatAdjusted.value = true;
            }
        }
    } catch (error) {
        message.value = error instanceof Error ? error.message : '売却方針を取得できませんでした。';
    }
}

async function save(policy: SalePolicy): Promise<void> {
    if (policy.policy === 'keep_amount' && (!Number.isSafeInteger(policy.keep_amount) || (policy.keep_amount ?? -1) < minimumHolding(policy))) {
        message.value = `保持量には${minimumHolding(policy).toLocaleString()}以上の整数を入力してください。`;
        return;
    }
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
        if (policy.resource_key === 'wheat') wheatAdjusted.value = false;
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

function policyLabel(policy: SalePolicy): string {
    if (policy.policy === 'sell_all') return 'すべて売却';
    if (policy.policy === 'stockpile') return '上限まで備蓄';
    return `${(policy.keep_amount ?? 0).toLocaleString()}${policy.unit_label ?? ''} 残す`;
}

function minimumHolding(policy: SalePolicy): number {
    return policy.resource_key === 'wheat' ? props.population ?? 0 : 0;
}

function wheatLocked(policy: SalePolicy): boolean {
    return policy.resource_key === 'wheat' && minimumHolding(policy) > (sliderLimits.value[policy.resource_id] ?? 0);
}

function sliderValue(policy: SalePolicy): number {
    if (policy.policy === 'sell_all') return 0;
    if (policy.policy === 'stockpile') return 1000;
    const minimum = minimumHolding(policy);
    const span = Math.max(1, (sliderLimits.value[policy.resource_id] ?? 1) - minimum);
    const position = Math.round(((policy.keep_amount ?? minimum) - minimum) / span * 1000);
    return Math.min(999, Math.max(allows(policy, 'sell_all') ? 1 : 0, position));
}

function moveSlider(policy: SalePolicy, event: Event): void {
    if (wheatLocked(policy)) return;
    const position = Number((event.target as HTMLInputElement).value);
    if (position === 0 && allows(policy, 'sell_all')) {
        policy.policy = 'sell_all';
        policy.keep_amount = null;
    } else if (position === 1000 && allows(policy, 'stockpile')) {
        policy.policy = 'stockpile';
        policy.keep_amount = null;
    } else if (allows(policy, 'keep_amount')) {
        policy.policy = 'keep_amount';
        const minimum = minimumHolding(policy);
        policy.keep_amount = minimum + Math.round(position / 1000 * ((sliderLimits.value[policy.resource_id] ?? minimum) - minimum));
    }
}

function enterKeepAmount(policy: SalePolicy, event: Event): void {
    if (wheatLocked(policy)) return;
    policy.policy = 'keep_amount';
    const value = (event.target as HTMLInputElement).value;
    policy.keep_amount = value === '' ? null : Number(value);
}
</script>

<template>
    <section class="resource-panel" aria-labelledby="resource-policy-heading">
        <div class="resource-panel-actions"><slot name="actions" /></div>
        <details class="resource-policy-details">
            <summary>
                <strong id="resource-policy-heading">資源・売却方針</strong>
            </summary>
        <p v-if="message" class="compact-message" role="status">{{ message }}</p>
        <div class="policy-list">
            <form v-for="policy in policies" :key="policy.resource_id" @submit.prevent="save(policy)">
                <div class="policy-resource"><strong>{{ policy.resource_name }}</strong><span>現在 {{ policy.amount.toLocaleString() }}{{ policy.unit_label ?? '' }}</span></div>
                <div class="policy-direction" aria-hidden="true"><span>{{ minimumHolding(policy).toLocaleString() }}{{ policy.unit_label ?? '' }}<template v-if="policy.resource_key === 'wheat'">（人口）</template></span><span>{{ (sliderLimits[policy.resource_id] ?? 0).toLocaleString() }}{{ policy.unit_label ?? '' }}（上限）</span></div>
                <input
                    type="range" min="0" max="1000" step="1"
                    :value="sliderValue(policy)"
                    :aria-label="`${policy.resource_name}の売却・備蓄方針`"
                    :aria-valuetext="policyLabel(policy)"
                    :disabled="busyResource !== null || wheatLocked(policy)"
                    @input="moveSlider(policy, $event)"
                >
                <output class="policy-current">{{ policyLabel(policy) }}</output>
                <label v-if="allows(policy, 'keep_amount')" class="policy-amount">
                    <span>保持量<span v-if="policy.unit_label">（{{ policy.unit_label }}）</span></span>
                    <input
                        :value="policy.keep_amount ?? ''"
                        type="number" :min="minimumHolding(policy)" step="1" :max="Number.MAX_SAFE_INTEGER"
                        :required="policy.policy === 'keep_amount'"
                        :disabled="busyResource !== null || wheatLocked(policy)"
                        @input="enterKeepAmount(policy, $event)"
                    >
                </label>
                <button type="submit" :disabled="busyResource !== null">保存</button>
                <p v-if="wheatLocked(policy)" class="policy-lock-note">人口が上限を超えているため、上限まで備蓄に固定しています。</p>
            </form>
        </div>
        <details class="resource-policy-help">
            <summary>売却・備蓄について</summary>
            <p>食料消費後に自動売却します。「上限まで備蓄」は個別上限を超えた分だけを売却し、売れない超過分を破棄します。保持量は数値欄で正確に指定できます。</p>
        </details>
        </details>
        <p v-if="wheatAdjusted" class="compact-message" role="status">小麦の方針を安全範囲へ調整しました。「保存」で反映されます。</p>
    </section>
</template>
