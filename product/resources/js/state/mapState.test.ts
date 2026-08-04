import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MapCell, MapChunk, MapSpace } from '../types';
import { useMapState } from './mapState';

const mapSpace: MapSpace = {
    id: 2,
    world_id: 1,
    key: 'surface',
    name: '地上',
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
});
