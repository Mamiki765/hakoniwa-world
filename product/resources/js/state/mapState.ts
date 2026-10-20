import { computed, reactive, ref } from 'vue';
import { api } from '../api/client';
import { floorDiv, neighbor } from '../map/projection';
import type { MapCell, MapChunk, MapSpace, ShipCourse } from '../types';

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

    function clear(): void {
        generation++;
        mapSpace = null;
        source = { kind: 'private' };
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
        const requestGeneration = generation;
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
        if (requestGeneration !== generation) return;
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

    function clearSelection(): void {
        selected.value = null;
    }

    function moveSelection(direction: number): void {
        if (selected.value === null) return;
        const next = neighbor(selected.value, direction);
        selected.value = cells.get(key(next.x, next.y)) ?? selected.value;
    }

    // 進路・目的地の変更結果を、その船が載っている読み込み済みのマスへ反映する。選択中のマス以外の船も対象。
    function updateShipCourse(course: ShipCourse): void {
        for (const [cellKey, cell] of cells) {
            if (cell.ship?.id !== course.id || !cell.ship.is_owner) continue;
            const updated = {
                ...cell,
                ship: { ...cell.ship, heading: course.heading, destination: course.destination, version: course.version },
            };
            cells.set(cellKey, updated);
            if (selected.value?.x === cell.x && selected.value?.y === cell.y) selected.value = updated;
        }
    }

    // 名前の変更を、読み込み済みのマスと選択中のマスへ反映する。
    function replaceCells(matches: (cell: MapCell) => boolean, change: (cell: MapCell) => MapCell): void {
        for (const [cellKey, cell] of cells) {
            if (!matches(cell)) continue;
            const updated = change(cell);
            cells.set(cellKey, updated);
            if (selected.value?.x === cell.x && selected.value?.y === cell.y) selected.value = updated;
        }
    }

    // 読み上げラベルも含め、名前を含む表示はサーバーの正規のセル表現から更新する。
    async function refreshNamedCells(matches: (cell: MapCell) => boolean): Promise<void> {
        const activeMapSpace = mapSpace;
        if (activeMapSpace === null) return;
        const chunks = new Map<string, { x: number; y: number }>();
        for (const cell of cells.values()) {
            if (!matches(cell)) continue;
            const x = floorDiv(cell.x, CHUNK_SIZE);
            const y = floorDiv(cell.y, CHUNK_SIZE);
            chunks.set(chunkKey(x, y), { x, y });
        }
        const requestGeneration = generation;
        if (chunks.size > 0) error.value = null;
        await Promise.all([...chunks].map(async ([requestKey, { x, y }]) => {
            await inFlightChunks.get(requestKey);
            if (requestGeneration !== generation) return;
            try {
                const chunk = await api<MapChunk>(chunkPath(activeMapSpace.id, x, y));
                if (requestGeneration !== generation) return;
                for (const cell of chunk.cells) {
                    const cellKey = key(cell.x, cell.y);
                    const current = cells.get(cellKey);
                    if (current === undefined || !matches(current)
                        || current.ship?.id !== cell.ship?.id
                        || (current.ship?.custom_name ?? null) !== (cell.ship?.custom_name ?? null)
                        || (current.capital_name ?? null) !== (cell.capital_name ?? null)) continue;
                    // 取得中に保存した進路・version・別の改名結果を古いセルで上書きしない。
                    cells.set(cellKey, { ...current, aria_label: cell.aria_label });
                }
            } catch {
                if (requestGeneration === generation) error.value = '一部のchunkを取得できませんでした。';
            }
        }));
        if (requestGeneration === generation && selected.value !== null) {
            selected.value = cells.get(key(selected.value.x, selected.value.y)) ?? selected.value;
        }
    }

    async function renameShip(ship: { id: number; custom_name: string | null }): Promise<void> {
        const matches = (cell: MapCell) => cell.ship?.id === ship.id && cell.ship.is_owner;
        replaceCells(
            matches,
            (cell) => ({ ...cell, ship: cell.ship == null ? cell.ship : { ...cell.ship, custom_name: ship.custom_name } }),
        );
        await refreshNamedCells(matches);
    }

    async function renameCapital(capital: { x: number; y: number; capital_name: string | null }): Promise<void> {
        const matches = (cell: MapCell) => cell.x === capital.x && cell.y === capital.y;
        replaceCells(matches, (cell) => ({ ...cell, capital_name: capital.capital_name }));
        await refreshNamedCells(matches);
    }

    return {
        visibleCells,
        selected,
        loading,
        error,
        emptyChunks,
        clear,
        synchronizeMapSpace,
        loadAround,
        loadVisibleRange,
        loadAllChunks,
        select,
        clearSelection,
        moveSelection,
        updateShipCourse,
        renameShip,
        renameCapital,
    };
}
