import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MapCell, MapChunk, MapSpace } from '../types';
import { useMapState } from './mapState';

const mapSpace: MapSpace = {
    id: 2,
    world_id: 1,
    key: 'surface',
    name: '地上',
    bounds_revision: 'bounds-0-59',
    bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 },
};

const response = (data: unknown) => new Response(JSON.stringify({ data }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
});

function chunkFromPath(path: string, state: MapChunk['state'] = 'generated'): MapChunk {
    const match = path.match(/\/chunks\/(-?\d+)\/(-?\d+)$/);
    if (match === null) throw new Error(`unexpected chunk path: ${path}`);
    const chunkX = Number(match[1]);
    const chunkY = Number(match[2]);
    const cell: MapCell = {
        x: chunkX * 16,
        y: chunkY * 16,
        terrain: 'plain',
        terrain_name: '平地',
        facility: null,
        facility_name: null,
        display_name: '平地',
        owner_nation_id: null,
        owner_nation_number: null,
        owner_name: null,
        within_viewer_visibility: false,
        buried_treasure_visible: false,
        details: [],
        monster: null,
        asset: { key: 'tile.plain', url: null, available: false, fallback_label: '平', fallback_style: 'tile-plain' },
        overlays: [],
        aria_label: `x ${chunkX * 16} y ${chunkY * 16} 平地`,
        version: 1,
        updated_at: null,
    };

    return {
        world_id: 1,
        map_space_id: 2,
        chunk_x: chunkX,
        chunk_y: chunkY,
        chunk_size: 16,
        version: state,
        state,
        cells: state === 'empty' ? [] : [cell],
    };
}

afterEach(() => vi.unstubAllGlobals());

describe('lazy map chunk loading', () => {
    it('loads only the bounded 3 by 3 chunks around the capital initially', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => response(chunkFromPath(String(input))));
        vi.stubGlobal('fetch', fetchMock);

        await useMapState().loadAround(mapSpace, 30, 30);

        expect(fetchMock).toHaveBeenCalledTimes(9);
        expect(fetchMock.mock.calls.map(([path]) => String(path)).sort()).toEqual([
            '/api/v1/map-spaces/2/chunks/0/0', '/api/v1/map-spaces/2/chunks/0/1', '/api/v1/map-spaces/2/chunks/0/2',
            '/api/v1/map-spaces/2/chunks/1/0', '/api/v1/map-spaces/2/chunks/1/1', '/api/v1/map-spaces/2/chunks/1/2',
            '/api/v1/map-spaces/2/chunks/2/0', '/api/v1/map-spaces/2/chunks/2/1', '/api/v1/map-spaces/2/chunks/2/2',
        ]);
    });

    it('loads only the newly visible adjacent chunk and never refetches it', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => response(chunkFromPath(String(input))));
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 30, 30);

        await map.loadVisibleRange({ minX: 48, maxX: 59, minY: 16, maxY: 31 });
        await map.loadVisibleRange({ minX: 48, maxX: 59, minY: 16, maxY: 31 });

        expect(fetchMock).toHaveBeenCalledTimes(10);
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/3/1'))).toHaveLength(1);
    });

    it('shares an in-flight request and remembers an empty chunk separately from failures', async () => {
        let releaseChunk: (value: Response) => void = () => undefined;
        const pendingChunk = new Promise<Response>((resolve) => { releaseChunk = resolve; });
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            return path.endsWith('/chunks/3/1') ? pendingChunk : Promise.resolve(response(chunkFromPath(path)));
        });
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 30, 30);

        const first = map.loadVisibleRange({ minX: 48, maxX: 59, minY: 16, maxY: 31 });
        const second = map.loadVisibleRange({ minX: 48, maxX: 59, minY: 16, maxY: 31 });
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/3/1'))).toHaveLength(1);
        releaseChunk(response(chunkFromPath('/chunks/3/1', 'empty')));
        await Promise.all([first, second]);
        await map.loadVisibleRange({ minX: 48, maxX: 59, minY: 16, maxY: 31 });

        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/3/1'))).toHaveLength(1);
        expect(map.emptyChunks.value).toContain('3:1');
        expect(map.error.value).toBeNull();
    });

    it('never requests chunks outside the world bounds and loads all 16 chunks only on demand', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => response(chunkFromPath(String(input))));
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 5, 5);

        expect(fetchMock).toHaveBeenCalledTimes(4);
        await map.loadVisibleRange({ minX: -500, maxX: -1, minY: -500, maxY: -1 });
        await map.loadVisibleRange({ minX: 60, maxX: 500, minY: 60, maxY: 500 });
        expect(fetchMock).toHaveBeenCalledTimes(4);

        await map.loadAllChunks();
        expect(fetchMock).toHaveBeenCalledTimes(16);
        for (const [path] of fetchMock.mock.calls) {
            const match = String(path).match(/\/chunks\/(-?\d+)\/(-?\d+)$/);
            expect(Number(match?.[1])).toBeGreaterThanOrEqual(0);
            expect(Number(match?.[1])).toBeLessThanOrEqual(3);
            expect(Number(match?.[2])).toBeGreaterThanOrEqual(0);
            expect(Number(match?.[2])).toBeLessThanOrEqual(3);
        }
    });

    it('invalidates loaded and confirmed-empty chunks when bounds revision changes for the same MapSpace id', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => response(chunkFromPath(String(input), 'empty')));
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 5, 5);

        expect(map.emptyChunks.value).toContain('0:0');
        expect(map.synchronizeMapSpace({
            ...mapSpace,
            bounds_revision: 'bounds-negative-16-59',
            bounds: { min_x: -16, max_x: 59, min_y: -16, max_y: 59 },
        })).toBe(true);
        expect(map.emptyChunks.value).toEqual([]);

        await map.loadVisibleRange({ minX: -16, maxX: -1, minY: -16, maxY: -1 });

        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/-1/-1'))).toHaveLength(1);
    });

    it('clears cells, selection, loaded state, empty state, and ignores stale in-flight responses on revision change', async () => {
        let releaseStale: (value: Response) => void = () => undefined;
        const staleResponse = new Promise<Response>((resolve) => { releaseStale = resolve; });
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/chunks/2/0')) return staleResponse;
            return Promise.resolve(response(chunkFromPath(path, path.endsWith('/chunks/0/0') ? 'generated' : 'empty')));
        });
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 5, 5);

        expect(map.visibleCells.value).toHaveLength(1);
        expect(map.selected.value).not.toBeNull();
        expect(map.emptyChunks.value.length).toBeGreaterThan(0);

        const stale = map.loadVisibleRange({ minX: 32, maxX: 47, minY: 0, maxY: 15 });
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/2/0'))).toHaveLength(1);

        expect(map.synchronizeMapSpace({
            ...mapSpace,
            bounds_revision: 'bounds-negative-16-63',
            bounds: { min_x: -16, max_x: 63, min_y: 0, max_y: 63 },
        })).toBe(true);
        expect(map.visibleCells.value).toEqual([]);
        expect(map.selected.value).toBeNull();
        expect(map.emptyChunks.value).toEqual([]);

        releaseStale(response(chunkFromPath('/chunks/2/0')));
        await stale;
        expect(map.visibleCells.value).toEqual([]);

        await map.loadVisibleRange({ minX: 0, maxX: 15, minY: 0, maxY: 15 });
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/0/0'))).toHaveLength(2);
        expect(map.visibleCells.value).toHaveLength(1);
    });

    it('clears the cached island immediately after a destructive lifecycle mutation', async () => {
        let releaseStale: (value: Response) => void = () => undefined;
        const staleResponse = new Promise<Response>((resolve) => { releaseStale = resolve; });
        const fetchMock = vi.fn((input: RequestInfo | URL) => {
            const path = String(input);
            if (path.endsWith('/chunks/2/0')) return staleResponse;

            return Promise.resolve(response(chunkFromPath(path, path.endsWith('/chunks/0/0') ? 'generated' : 'empty')));
        });
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 5, 5);
        const stale = map.loadVisibleRange({ minX: 32, maxX: 47, minY: 0, maxY: 15 });

        expect(map.visibleCells.value).toHaveLength(1);
        map.clear();
        expect(map.visibleCells.value).toEqual([]);
        expect(map.selected.value).toBeNull();
        expect(map.emptyChunks.value).toEqual([]);

        releaseStale(response(chunkFromPath('/chunks/2/0')));
        await stale;
        expect(map.visibleCells.value).toEqual([]);
    });

    it('keeps the selected cell on the new island when an older loadAround finishes last', async () => {
        let releaseOldChunks!: () => void;
        const oldChunks = new Promise<void>((resolve) => { releaseOldChunks = resolve; });
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path.startsWith('/api/v1/public/nations/7/')) await oldChunks;
            return response(chunkFromPath(path));
        }));
        const map = useMapState();
        const oldLoad = map.loadAround(mapSpace, 16, 16, { kind: 'public', nationId: 7 });

        await map.loadAround(mapSpace, 32, 32, { kind: 'public', nationId: 8 });
        const chosenCell = map.visibleCells.value.find((cell) => cell.x === 48 && cell.y === 48)!;
        map.select(chosenCell);
        expect(map.selected.value).toEqual(chosenCell);
        expect(map.loading.value).toBe(false);

        releaseOldChunks();
        await oldLoad;

        expect(map.selected.value).toEqual(chosenCell);
        expect(map.visibleCells.value).not.toContainEqual(expect.objectContaining({ x: 0, y: 0 }));
        expect(map.loading.value).toBe(false);
    });

    it('applies a ship course change to the loaded cell carrying that own ship even when another cell is selected', async () => {
        const ship = {
            id: 9, key: 'fishing' as const, name: '漁船', asset_key: 'ship.fishing', current_hp: 1, max_hp: 1,
            public_state: 'active' as const, owner_nation: { nation_number: 1, name: '操作国' }, is_owner: true,
            heading: 2, destination: null, version: 3,
        };
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const chunk = chunkFromPath(String(input));
            if (chunk.chunk_x === 0 && chunk.chunk_y === 0) chunk.cells = [{ ...chunk.cells[0]!, terrain: 'sea', ship }];
            return response(chunk);
        }));
        const map = useMapState();
        await map.loadAround(mapSpace, 16, 16);
        expect(map.selected.value?.ship ?? null).toBeNull();

        map.updateShipCourse({ id: 9, heading: null, destination: { x: 20, y: 18 }, version: 4 });

        const carrier = map.visibleCells.value.find((cell) => cell.ship?.id === 9);
        expect(carrier?.ship).toMatchObject({ heading: null, destination: { x: 20, y: 18 }, version: 4 });
        expect(map.selected.value?.ship ?? null).toBeNull();

    });

    it('keeps a newer ship course when the renamed accessible label arrives late', async () => {
        const ship = {
            id: 9, key: 'fishing' as const, name: '漁船', custom_name: null, asset_key: 'ship.fishing',
            current_hp: 5, max_hp: 5, public_state: 'active' as const, is_owner: true,
            owner_nation: { nation_number: 1, name: '操作国' }, heading: 2, destination: null, version: 3,
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const chunk = chunkFromPath(String(input));
            if (chunk.chunk_x === 0 && chunk.chunk_y === 0) chunk.cells[0]!.ship = ship;
            return response(chunk);
        });
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 16, 16);
        map.select(map.visibleCells.value.find((cell) => cell.ship?.id === 9)!);

        let release!: (value: Response) => void;
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { release = resolve; }));
        const refresh = map.renameShip({ id: 9, custom_name: '新しい名前' });
        await Promise.resolve();
        expect(fetchMock).toHaveBeenCalledTimes(10);
        map.updateShipCourse({ id: 9, heading: null, destination: { x: 20, y: 18 }, version: 4 });

        const oldChunk = chunkFromPath('/chunks/0/0');
        oldChunk.cells = [{ ...oldChunk.cells[0]!, ship: { ...ship, custom_name: '新しい名前' }, aria_label: '船 新しい名前 漁船 HP 5/5' }];
        release(response(oldChunk));
        await refresh;
        const expected = { custom_name: '新しい名前', heading: null, destination: { x: 20, y: 18 }, version: 4 };
        expect(map.selected.value?.ship).toMatchObject(expected);
        expect(map.visibleCells.value.find((cell) => cell.ship?.id === 9)?.ship).toMatchObject(expected);
        expect(map.selected.value?.aria_label).toBe(oldChunk.cells[0]!.aria_label);
    });

    it.each(['ship', 'capital'] as const)('keeps the latest %s name and label when rename responses arrive out of order', async (kind) => {
        const namedChunk = (name: string) => {
            const chunk = chunkFromPath('/chunks/0/0');
            chunk.cells = [{ ...chunk.cells[0]!, aria_label: `${kind} ${name}`,
                facility: kind === 'capital' ? 'capital' : null,
                capital_name: kind === 'capital' ? name : null,
                ship: kind === 'ship' ? {
                    id: 9, key: 'fishing', name: '漁船', custom_name: name, asset_key: 'ship.fishing',
                    current_hp: 5, max_hp: 5, public_state: 'active', is_owner: true,
                    owner_nation: { nation_number: 1, name: '操作国' }, heading: null, destination: null, version: 4,
                } : null,
            }];
            return chunk;
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => response(
            String(input).endsWith('/chunks/0/0') ? namedChunk('最初') : chunkFromPath(String(input)),
        ));
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 0, 0);
        const rename = (name: string) => kind === 'ship'
            ? map.renameShip({ id: 9, custom_name: name })
            : map.renameCapital({ x: 0, y: 0, capital_name: name });

        let release!: (value: Response) => void;
        fetchMock.mockImplementationOnce(() => new Promise<Response>((resolve) => { release = resolve; }));
        const olderRefresh = rename('一回目');
        await Promise.resolve();
        fetchMock.mockResolvedValueOnce(response(namedChunk('二回目')));
        await rename('二回目');
        release(response(namedChunk('一回目')));
        await olderRefresh;

        expect(kind === 'ship' ? map.selected.value?.ship?.custom_name : map.selected.value?.capital_name).toBe('二回目');
        expect(map.selected.value?.aria_label).toBe(`${kind} 二回目`);
        expect(map.visibleCells.value.find((cell) => cell.x === 0 && cell.y === 0)?.aria_label).toBe(`${kind} 二回目`);
    });

    it('refreshes the renamed ship and capital with their authoritative accessible labels without clearing the map', async () => {
        let shipName: string | null = null;
        let capitalName: string | null = '旧都';
        let shipLabel = '名前なしの船、HP 5/5';
        let capitalLabel = '旧都 首都、所有 操作国';
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const chunk = chunkFromPath(String(input));
            if (chunk.chunk_x === 0 && chunk.chunk_y === 0) {
                chunk.cells = [{ ...chunk.cells[0]!, aria_label: shipLabel, ship: {
                    id: 9, key: 'fishing', name: '漁船', custom_name: shipName, asset_key: 'ship.fishing',
                    current_hp: 5, max_hp: 5, public_state: 'active', is_owner: true,
                    owner_nation: { nation_number: 1, name: '操作国' }, heading: null, destination: null, version: 4,
                } }];
            }
            if (chunk.chunk_x === 1 && chunk.chunk_y === 1) {
                chunk.cells = [{ ...chunk.cells[0]!, facility: 'capital', capital_name: capitalName, aria_label: capitalLabel }];
            }
            return response(chunk);
        });
        vi.stubGlobal('fetch', fetchMock);
        const map = useMapState();
        await map.loadAround(mapSpace, 16, 16);
        map.select(map.visibleCells.value.find((cell) => cell.ship?.id === 9)!);

        shipName = 'エスポワール';
        shipLabel = '船 エスポワール 漁船、HP 5/5';
        await map.renameShip({ id: 9, custom_name: shipName });
        expect(map.selected.value?.ship).toMatchObject({ custom_name: shipName, version: 4 });
        expect(map.selected.value?.aria_label).toBe(shipLabel);
        expect(map.visibleCells.value.find((cell) => cell.ship?.id === 9)?.aria_label).toBe(shipLabel);

        map.select(map.visibleCells.value.find((cell) => cell.x === 16 && cell.y === 16)!);
        capitalName = null;
        capitalLabel = '首都、所有 操作国';
        await map.renameCapital({ x: 16, y: 16, capital_name: capitalName });
        expect(map.selected.value?.capital_name).toBeNull();
        expect(map.selected.value?.aria_label).toBe(capitalLabel);
        expect(map.visibleCells.value.find((cell) => cell.x === 16 && cell.y === 16)?.aria_label).toBe(capitalLabel);
        expect(map.visibleCells.value).toHaveLength(9);
        expect(fetchMock).toHaveBeenCalledTimes(11);
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/0/0'))).toHaveLength(2);
        expect(fetchMock.mock.calls.filter(([path]) => String(path).endsWith('/chunks/1/1'))).toHaveLength(2);
    });
});
