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
    const key = (x: number, y: number) => `${x}:${y}`;

    async function loadAround(mapSpaceId: number, x: number, y: number): Promise<void> {
        loading.value = true;
        error.value = null;
        cells.clear();
        emptyChunks.value = [];
        const centerX = floorDiv(x, 16);
        const centerY = floorDiv(y, 16);
        const requests: Promise<MapChunk>[] = [];

        for (let offsetX = -1; offsetX <= 1; offsetX++) {
            for (let offsetY = -1; offsetY <= 1; offsetY++) {
                requests.push(api<MapChunk>(`/api/v1/map-spaces/${mapSpaceId}/chunks/${centerX + offsetX}/${centerY + offsetY}`));
            }
        }

        const results = await Promise.allSettled(requests);
        for (const result of results) {
            if (result.status === 'rejected') {
                error.value = '一部のchunkを取得できませんでした。';
                continue;
            }
            if (result.value.state === 'empty') {
                emptyChunks.value.push(`${result.value.chunk_x}:${result.value.chunk_y}`);
            }
            for (const cell of result.value.cells) cells.set(key(cell.x, cell.y), cell);
        }
        selected.value = cells.get(key(x, y)) ?? visibleCells.value[0] ?? null;
        loading.value = false;
    }

    function select(cell: MapCell): void {
        selected.value = cell;
    }

    function moveSelection(direction: number): void {
        if (selected.value === null) return;
        const next = neighbor(selected.value, direction);
        selected.value = cells.get(key(next.x, next.y)) ?? selected.value;
    }

    return { visibleCells, selected, loading, error, emptyChunks, loadAround, select, moveSelection };
}
