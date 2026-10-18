<script setup lang="ts">
import { ref } from 'vue';
import { ApiError, api } from '../api/client';

export interface WarehouseCapacity {
    capacity: number;
    used: number;
    expansions?: number;
    max_expansions?: number;
    next_cost_paradox?: number | null;
    paradox_balance?: number;
}

const props = defineProps<{ warehouse: WarehouseCapacity }>();
const emit = defineEmits<{ close: []; expanded: [value: WarehouseCapacity] }>();
const busy = ref(false);
const error = ref('');
let requestId: string | null = null;

const step = 50;
const cost = (): number | null => props.warehouse.next_cost_paradox ?? null;
const affordable = (): boolean => cost() !== null && (props.warehouse.paradox_balance ?? 0) >= (cost() ?? 0);

async function expand(): Promise<void> {
    if (busy.value || cost() === null) return;
    requestId ??= crypto.randomUUID();
    busy.value = true;
    error.value = '';
    try {
        const result = await api<WarehouseCapacity>('/api/v1/me/secretary/warehouse/expand', {
            method: 'POST', body: JSON.stringify({ request_id: requestId, expected_expansions: props.warehouse.expansions ?? 0 }),
        });
        requestId = null;
        emit('expanded', result);
    } catch (caught) {
        if (caught instanceof ApiError && caught.code !== 'request_conflict') requestId = null;
        error.value = caught instanceof Error ? caught.message : '倉庫を広げられませんでした。';
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="modal-backdrop" @click.self="!busy && emit('close')">
        <section class="secretary-synthesis-dialog" role="dialog" aria-modal="true" aria-labelledby="warehouse-expand-title">
            <h2 id="warehouse-expand-title">倉庫を広げますか？</h2>
            <template v-if="cost() !== null">
                <dl>
                    <div><dt>支払う輝石</dt><dd>{{ cost() }}Pd</dd></div>
                    <div><dt>広げた後の容量</dt><dd>{{ warehouse.capacity }} → {{ warehouse.capacity + step }}</dd></div>
                    <div><dt>手持ちの輝石</dt><dd>{{ warehouse.paradox_balance ?? '—' }}Pd</dd></div>
                    <div><dt>拡張</dt><dd>{{ warehouse.expansions ?? 0 }} / {{ warehouse.max_expansions ?? 4 }}回</dd></div>
                </dl>
                <p v-if="!affordable()" class="status error">輝石が足りません。</p>
            </template>
            <p v-else>倉庫はもう最大（{{ warehouse.capacity }}）です。</p>
            <p v-if="error" class="status error" role="alert">{{ error }}</p>
            <footer>
                <button class="button secondary" type="button" :disabled="busy" @click="emit('close')">やめる</button>
                <button v-if="cost() !== null" class="button primary" type="button" :disabled="busy || !affordable()" @click="expand">{{ busy ? '処理中…' : '広げる' }}</button>
            </footer>
        </section>
    </div>
</template>
