import { computed, reactive, ref } from 'vue';
import { api } from '../api/client';
import { floorDiv, neighbor } from '../map/projection';
import type { MapCell, MapChunk } from '../types';

export function useMapState() {
    const cells = reactive(new Map<string, MapCell>());
    const selected = ref<MapCell | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const emptyChunks = ref<string[]>([]);

    const visibleCells = computed(() => [...cells.values()]);
    const key = (q: number, r: number) => `${q}:${r}`;

    async function loadAround(mapSpaceId: number, q: number, r: number): Promise<void> {
        loading.value = true;
        error.value = null;
        cells.clear();
        emptyChunks.value = [];
        const centerQ = floorDiv(q, 16);
        const centerR = floorDiv(r, 16);
        const requests: Promise<MapChunk>[] = [];

        for (let dq = -1; dq <= 1; dq++) {
            for (let dr = -1; dr <= 1; dr++) {
                requests.push(api<MapChunk>(`/api/v1/map-spaces/${mapSpaceId}/chunks/${centerQ + dq}/${centerR + dr}`));
            }
        }

        const results = await Promise.allSettled(requests);
        for (const result of results) {
            if (result.status === 'rejected') {
                error.value = '一部のchunkを取得できませんでした。';
                continue;
            }
            if (result.value.state === 'empty') {
                emptyChunks.value.push(`${result.value.chunk_q}:${result.value.chunk_r}`);
            }
            for (const cell of result.value.cells) cells.set(key(cell.q, cell.r), cell);
        }
        selected.value = cells.get(key(q, r)) ?? visibleCells.value[0] ?? null;
        loading.value = false;
    }

    function select(cell: MapCell): void {
        selected.value = cell;
    }

    function moveSelection(direction: number): void {
        if (selected.value === null) return;
        const next = neighbor(selected.value, direction);
        selected.value = cells.get(key(next.q, next.r)) ?? selected.value;
    }

    return { visibleCells, selected, loading, error, emptyChunks, loadAround, select, moveSelection };
}
