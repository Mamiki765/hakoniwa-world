<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { api, apiEnvelope, validationErrors } from '../api/client';
import { GIVEN_NAME_MAX_LENGTH } from '../formatters/givenName';
import { shipInstructionLabel } from '../formatters/shipInstruction';
import type { OwnShipStatus } from '../types';

const props = defineProps<{ nationId: number }>();
const dialog = ref<HTMLDialogElement | null>(null);
const ships = ref<OwnShipStatus[]>([]);
interface ShipTypeCount {
    key: string;
    name: string;
    count: number;
    capacity: number;
}
const shipTypes = ref<ShipTypeCount[]>([]);
const loading = ref(false);
const error = ref('');
const namingId = ref<number | null>(null);
const nameDraft = ref('');
const nameSaving = ref(false);
const nameError = ref('');

async function load(): Promise<void> {
    if (loading.value) return;
    loading.value = true;
    error.value = '';
    namingId.value = null;
    ships.value = [];
    shipTypes.value = [];
    try {
        const result = await apiEnvelope<OwnShipStatus[]>(`/api/v1/nations/${props.nationId}/ships`);
        ships.value = result.data;
        shipTypes.value = (result.meta?.ship_types ?? []) as ShipTypeCount[];
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : '船一覧を読み込めませんでした。';
    } finally {
        loading.value = false;
    }
}

function startNaming(ship: OwnShipStatus): void {
    namingId.value = ship.id;
    nameDraft.value = ship.custom_name ?? '';
    nameError.value = '';
    void nextTick(() => document.getElementById(`ship-name-${ship.id}`)?.focus());
}

// 名前だけを書き換える。空なら名前を外して、今まで通り船種と座標で表示する。
async function saveName(ship: OwnShipStatus): Promise<void> {
    if (nameSaving.value) return;
    nameSaving.value = true;
    nameError.value = '';
    try {
        const updated = await api<{ id: number; custom_name: string | null }>(`/api/v1/nations/${props.nationId}/ships/${ship.id}/name`, {
            method: 'PATCH',
            body: JSON.stringify({ name: nameDraft.value.trim() === '' ? null : nameDraft.value }),
        });
        ships.value = ships.value.map((row) => row.id === updated.id ? { ...row, custom_name: updated.custom_name } : row);
        namingId.value = null;
    } catch (cause) {
        nameError.value = validationErrors(cause).name ?? (cause instanceof Error ? cause.message : '名前を変更できませんでした。');
    } finally {
        nameSaving.value = false;
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
                <li v-for="ship in ships" :key="ship.id">
                    <span class="ship-status-row">
                        <strong v-if="ship.custom_name" class="given-name">{{ ship.custom_name }}<small>{{ ship.name }} ({{ ship.x }},{{ ship.y }})</small></strong>
                        <span v-else>{{ ship.name }} ({{ ship.x }},{{ ship.y }})</span>
                        <span>HP{{ ship.current_hp }}/{{ ship.max_hp }} {{ shipInstructionLabel(ship, ship.movement_mode) }}</span>
                        <button v-if="namingId !== ship.id" type="button" class="ship-name-button" @click="startNaming(ship)">{{ ship.custom_name ? '名前を変える' : '名前をつける' }}</button>
                    </span>
                    <form v-if="namingId === ship.id" class="ship-name-form" @submit.prevent="saveName(ship)">
                        <input :id="`ship-name-${ship.id}`" v-model="nameDraft" :maxlength="GIVEN_NAME_MAX_LENGTH" :aria-label="`${ship.name} (${ship.x},${ship.y})の名前`" placeholder="名前なし" :aria-describedby="`ship-name-help-${ship.id}`" @keydown.esc.stop.prevent="namingId = null">
                        <button type="submit" :disabled="nameSaving">保存</button>
                        <button type="button" :disabled="nameSaving" @click="namingId = null">やめる</button>
                        <small :id="`ship-name-help-${ship.id}`">{{ GIVEN_NAME_MAX_LENGTH }}文字まで。空にすると名前を外します。地図やログで他の島からも見えます。</small>
                        <span v-if="nameError" class="field-error" role="alert">{{ nameError }}</span>
                    </form>
                </li>
            </ul>
            <button type="button" :disabled="loading" @click="load">更新</button>
        </dialog>
    </Teleport>
</template>
