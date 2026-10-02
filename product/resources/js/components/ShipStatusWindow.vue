<script setup lang="ts">
import { ref } from 'vue';
import { apiEnvelope } from '../api/client';

const props = defineProps<{ nationId: number }>();
interface ShipStatus {
    id: number;
    name: string;
    x: number;
    y: number;
    current_hp: number;
    max_hp: number;
    heading: number | null;
    movement_mode: string;
}
const dialog = ref<HTMLDialogElement | null>(null);
const ships = ref<ShipStatus[]>([]);
interface ShipTypeCount {
    key: string;
    name: string;
    count: number;
    capacity: number;
}
const shipTypes = ref<ShipTypeCount[]>([]);
const loading = ref(false);
const error = ref('');

function headingLabel(ship: ShipStatus): string {
    if (ship.heading === null) return ship.movement_mode === 'heading_only' ? '待機中' : 'ランダム';
    return ['東', '北東', '北西', '西', '南西', '南東'][ship.heading] ?? '';
}

async function load(): Promise<void> {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    ships.value = [];
    shipTypes.value = [];
    try {
        const result = await apiEnvelope<ShipStatus[]>(`/api/v1/nations/${props.nationId}/ships`);
        ships.value = result.data;
        shipTypes.value = (result.meta?.ship_types ?? []) as ShipTypeCount[];
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '船一覧を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function open(): void {
    dialog.value?.showModal();
    void load();
}

function closeOnBackdrop(event: MouseEvent): void {
    const element = dialog.value;
    if (element === null || event.target !== element) return;
    const bounds = element.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right
        || event.clientY < bounds.top || event.clientY > bounds.bottom) element.close();
}
</script>

<template>
    <button class="daily-quest-trigger" type="button" @click="open">船一覧</button>
    <Teleport to="body">
        <dialog ref="dialog" class="daily-quest-modal ship-status-modal" aria-labelledby="ship-status-title" @click="closeOnBackdrop">
            <header>
                <div><h2 id="ship-status-title">船一覧</h2><p>自国の生存船 {{ loading ? '…' : ships.length }}隻</p></div>
                <button type="button" aria-label="閉じる" autofocus @click="dialog?.close()">×</button>
            </header>
            <section v-if="!loading && !error" class="ship-type-counts" aria-label="船種ごとの保有数と建造上限">
                <p>保有数／建造上限</p>
                <p class="ship-type-count-values"><span v-for="type in shipTypes" :key="type.key">{{ type.name }} {{ type.count }}/{{ type.capacity }}</span></p>
            </section>
            <p v-if="loading" role="status">読み込み中…</p>
            <p v-else-if="error" class="status error" role="alert">{{ error }}</p>
            <p v-else-if="ships.length === 0">生存している船はありません。</p>
            <ul v-else class="ship-status-list">
                <li v-for="ship in ships" :key="ship.id">{{ ship.name }} ({{ ship.x }},{{ ship.y }}) HP{{ ship.current_hp }}/{{ ship.max_hp }} {{ headingLabel(ship) }}</li>
            </ul>
            <button type="button" :disabled="loading" @click="load">更新</button>
        </dialog>
    </Teleport>
</template>
