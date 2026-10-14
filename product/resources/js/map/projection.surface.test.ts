import { describe, expect, it } from 'vitest';
import {
    floorDiv,
    floorMod,
    gridToPixel,
    hexDistance,
    neighbor,
} from './projection';

describe('staggered square-tile x/y grid', () => {
    it.each([
        [0, 0, 0], [15, 0, 15], [16, 1, 0], [-1, -1, 15], [-16, -1, 0], [-17, -2, 15],
    ])('locates %i in chunk %i local %i', (value, chunk, local) => {
        expect(floorDiv(value, 16)).toBe(chunk);
        expect(floorMod(value, 16)).toBe(local);
    });

    it('projects absolute rows with a fixed alternating half-tile offset', () => {
        expect(gridToPixel({ x: 0, y: 0 })).toEqual({ x: 16, y: 0 });
        expect(gridToPixel({ x: 1, y: 0 })).toEqual({ x: 48, y: 0 });
        expect(gridToPixel({ x: 0, y: 1 })).toEqual({ x: 0, y: 32 });
        expect(gridToPixel({ x: 1, y: 1 })).toEqual({ x: 32, y: 32 });
        expect(gridToPixel({ x: 0, y: 2 })).toEqual({ x: 16, y: 64 });
        expect(gridToPixel({ x: 0, y: 58 }).x).toBe(16);
        expect(gridToPixel({ x: 0, y: 59 }).x).toBe(0);
    });

    it('keeps every row equally wide without cumulative drift', () => {
        const tileWidth = gridToPixel({ x: 1, y: 0 }).x - gridToPixel({ x: 0, y: 0 }).x;
        const lastX = 99;
        const rows = Array.from({ length: 101 }, (_, index) => {
            const y = index - 50;
            const first = gridToPixel({ x: 0, y });
            const last = gridToPixel({ x: lastX, y });
            return { left: first.x, width: last.x - first.x };
        });

        expect(new Set(rows.map((row) => row.width))).toEqual(new Set([lastX * tileWidth]));
        expect(new Set(rows.map((row) => row.left)).size).toBe(2);
    });

    it('moves through the specified six neighbors on even and odd rows', () => {
        expect(Array.from({ length: 6 }, (_, direction) => neighbor({ x: 10, y: 8 }, direction))).toEqual([
            { x: 11, y: 8 }, { x: 11, y: 7 }, { x: 10, y: 7 },
            { x: 9, y: 8 }, { x: 10, y: 9 }, { x: 11, y: 9 },
        ]);
        expect(Array.from({ length: 6 }, (_, direction) => neighbor({ x: 10, y: 9 }, direction))).toEqual([
            { x: 11, y: 9 }, { x: 10, y: 8 }, { x: 9, y: 8 },
            { x: 9, y: 9 }, { x: 9, y: 10 }, { x: 10, y: 10 },
        ]);
        expect(Array.from({ length: 6 }, (_, direction) => neighbor({ x: 10, y: -2 }, direction))).toEqual([
            { x: 11, y: -2 }, { x: 11, y: -3 }, { x: 10, y: -3 },
            { x: 9, y: -2 }, { x: 10, y: -1 }, { x: 11, y: -1 },
        ]);
        expect(Array.from({ length: 6 }, (_, direction) => neighbor({ x: 10, y: -1 }, direction))).toEqual([
            { x: 11, y: -1 }, { x: 10, y: -2 }, { x: 9, y: -2 },
            { x: 9, y: -1 }, { x: 9, y: 0 }, { x: 10, y: 0 },
        ]);
        expect(gridToPixel({ x: 10, y: -2 })).toEqual({ x: 336, y: -64 });
        expect(gridToPixel({ x: 10, y: -1 })).toEqual({ x: 320, y: -32 });
    });

    it('counts straight-line hex distance the same way as the server, including odd and negative rows', () => {
        for (const origin of [{ x: 0, y: 0 }, { x: 10, y: -1 }]) {
            expect(hexDistance(origin, origin)).toBe(0);
            for (let direction = 0; direction < 6; direction++) expect(hexDistance(origin, neighbor(origin, direction))).toBe(1);
        }
        expect(hexDistance({ x: 0, y: 0 }, { x: 4, y: 4 })).toBe(6);
        expect(hexDistance({ x: 4, y: 4 }, { x: 0, y: 0 })).toBe(6);
        expect(hexDistance({ x: -20, y: -16 }, { x: -16, y: -12 })).toBe(6);
    });
});
