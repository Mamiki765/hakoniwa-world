import { computed, reactive, ref } from 'vue';
import { api } from '../api/client';
import { floorDiv, neighbor } from '../map/projection';
import type { MapCell, MapChunk, MapSpace } from '../types';

const CHUNK_SIZE = 16;

export interface MapViewRange {
    minX: number;
    maxX: number;
    minY: number;
    maxY: number;
}

type MapSource = { kind: 'private' } | { kind: 'public'; nationId: number };

export function useMapState() {
    const cells = reactive(new Map<string, MapCell>());
    const selected = ref<MapCell | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const emptyChunks = ref<string[]>([]);
    let mapSpace: MapSpace | null = null;
    let source: MapSource = { kind: 'private' };
    let generation = 0;
    let loadedChunks = new Set<string>();
    let confirmedEmptyChunks = new Set<string>();
    let inFlightChunks = new Map<string, Promise<void>>();

    const visibleCells = computed(() => [...cells.values()]);
    const key = (x: number, y: number) => `${x}:${y}`;
    const chunkKey = (chunkX: number, chunkY: number) => `${chunkX}:${chunkY}`;

    function isChunkInWorld(chunkX: number, chunkY: number): boolean {
        if (mapSpace === null) return false;

        return chunkX >= floorDiv(mapSpace.bounds.min_x, CHUNK_SIZE)
            && chunkX <= floorDiv(mapSpace.bounds.max_x, CHUNK_SIZE)
            && chunkY >= floorDiv(mapSpace.bounds.min_y, CHUNK_SIZE)
            && chunkY <= floorDiv(mapSpace.bounds.max_y, CHUNK_SIZE);
    }

    function syncEmptyChunks(): void {
        emptyChunks.value = [...confirmedEmptyChunks].sort((left, right) => left.localeCompare(right, undefined, { numeric: true }));
    }

    function resetForMapSpace(nextMapSpace: MapSpace): void {
        generation++;
        mapSpace = nextMapSpace;
        loading.value = false;
        error.value = null;
        cells.clear();
        selected.value = null;
        emptyChunks.value = [];
        loadedChunks = new Set<string>();
        confirmedEmptyChunks = new Set<string>();
        inFlightChunks = new Map<string, Promise<void>>();
    }

    function synchronizeMapSpace(nextMapSpace: MapSpace): boolean {
        const revisionChanged = mapSpace === null
            || mapSpace.id !== nextMapSpace.id
            || mapSpace.bounds_revision !== nextMapSpace.bounds_revision;

        if (revisionChanged) {
            resetForMapSpace(nextMapSpace);
        } else {
            mapSpace = nextMapSpace;
        }

        return revisionChanged;
    }

    function chunkPath(mapSpaceId: number, chunkX: number, chunkY: number): string {
        return source.kind === 'public'
            ? `/api/v1/public/nations/${source.nationId}/map-spaces/${mapSpaceId}/chunks/${chunkX}/${chunkY}`
            : `/api/v1/map-spaces/${mapSpaceId}/chunks/${chunkX}/${chunkY}`;
    }

    function requestChunk(chunkX: number, chunkY: number): Promise<void> {
        const activeMapSpace = mapSpace;
        const requestKey = chunkKey(chunkX, chunkY);
        if (activeMapSpace === null || !isChunkInWorld(chunkX, chunkY)
            || loadedChunks.has(requestKey) || confirmedEmptyChunks.has(requestKey)) {
            return Promise.resolve();
        }

        const existing = inFlightChunks.get(requestKey);
        if (existing !== undefined) return existing;

        const requestGeneration = generation;
        loading.value = true;
        const request = api<MapChunk>(chunkPath(activeMapSpace.id, chunkX, chunkY))
            .then((chunk) => {
                if (requestGeneration !== generation) return;
                if (chunk.state === 'empty') {
                    confirmedEmptyChunks.add(requestKey);
                } else {
                    loadedChunks.add(requestKey);
                    for (const cell of chunk.cells) cells.set(key(cell.x, cell.y), cell);
                }
                syncEmptyChunks();
            })
            .catch(() => {
                if (requestGeneration === generation) {
                    error.value = '一部のchunkを取得できませんでした。';
                }
            })
            .finally(() => {
                if (requestGeneration !== generation) return;
                inFlightChunks.delete(requestKey);
                loading.value = inFlightChunks.size > 0;
            });

        inFlightChunks.set(requestKey, request);

        return request;
    }

    async function loadChunks(coordinates: Array<{ x: number; y: number }>): Promise<void> {
        const unique = new Map<string, { x: number; y: number }>();
        for (const coordinate of coordinates) {
            if (isChunkInWorld(coordinate.x, coordinate.y)) {
                unique.set(chunkKey(coordinate.x, coordinate.y), coordinate);
            }
        }
        const requests = [...unique.values()].filter((coordinate) => {
            const requestKey = chunkKey(coordinate.x, coordinate.y);
            return !loadedChunks.has(requestKey)
                && !confirmedEmptyChunks.has(requestKey);
        });
        if (requests.length === 0) return;

        if (requests.some((coordinate) => !inFlightChunks.has(chunkKey(coordinate.x, coordinate.y)))) {
            error.value = null;
        }
        await Promise.all(requests.map((coordinate) => requestChunk(coordinate.x, coordinate.y)));
    }

    async function loadAround(
        nextMapSpace: MapSpace,
        x: number,
        y: number,
        nextSource: MapSource = { kind: 'private' },
    ): Promise<void> {
        resetForMapSpace(nextMapSpace);
        source = nextSource;
        loading.value = true;
        const centerX = floorDiv(x, CHUNK_SIZE);
        const centerY = floorDiv(y, CHUNK_SIZE);
        const coordinates: Array<{ x: number; y: number }> = [];

        for (let offsetX = -1; offsetX <= 1; offsetX++) {
            for (let offsetY = -1; offsetY <= 1; offsetY++) {
                coordinates.push({ x: centerX + offsetX, y: centerY + offsetY });
            }
        }

        await loadChunks(coordinates);
        selected.value = cells.get(key(x, y)) ?? visibleCells.value[0] ?? null;
        loading.value = inFlightChunks.size > 0;
    }

    async function loadVisibleRange(range: MapViewRange): Promise<void> {
        if (mapSpace === null) return;

        const minX = Math.max(mapSpace.bounds.min_x, Math.floor(range.minX));
        const maxX = Math.min(mapSpace.bounds.max_x, Math.ceil(range.maxX));
        const minY = Math.max(mapSpace.bounds.min_y, Math.floor(range.minY));
        const maxY = Math.min(mapSpace.bounds.max_y, Math.ceil(range.maxY));
        if (minX > maxX || minY > maxY) return;

        const coordinates: Array<{ x: number; y: number }> = [];
        for (let chunkX = floorDiv(minX, CHUNK_SIZE); chunkX <= floorDiv(maxX, CHUNK_SIZE); chunkX++) {
            for (let chunkY = floorDiv(minY, CHUNK_SIZE); chunkY <= floorDiv(maxY, CHUNK_SIZE); chunkY++) {
                coordinates.push({ x: chunkX, y: chunkY });
            }
        }
        await loadChunks(coordinates);
    }

    async function loadAllChunks(): Promise<void> {
        if (mapSpace === null) return;
        await loadVisibleRange({
            minX: mapSpace.bounds.min_x,
            maxX: mapSpace.bounds.max_x,
            minY: mapSpace.bounds.min_y,
            maxY: mapSpace.bounds.max_y,
        });
    }

    function select(cell: MapCell): void {
        selected.value = cell;
    }

    function moveSelection(direction: number): void {
        if (selected.value === null) return;
        const next = neighbor(selected.value, direction);
        selected.value = cells.get(key(next.x, next.y)) ?? selected.value;
    }

    return {
        visibleCells,
        selected,
        loading,
        error,
        emptyChunks,
        synchronizeMapSpace,
        loadAround,
        loadVisibleRange,
        loadAllChunks,
        select,
        moveSelection,
    };
}
