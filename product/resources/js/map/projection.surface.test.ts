import { describe, expect, it } from 'vitest';
import {
    floorDiv,
    floorMod,
    gridToPixel,
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

    it('keeps all 60 rows equally wide without cumulative drift', () => {
        const rows = Array.from({ length: 60 }, (_, y) => {
            const first = gridToPixel({ x: 0, y });
            const last = gridToPixel({ x: 59, y });
            return { left: first.x, width: last.x - first.x + 32 };
        });

        expect(new Set(rows.map((row) => row.width))).toEqual(new Set([1920]));
        expect(new Set(rows.map((row) => row.left))).toEqual(new Set([0, 16]));
        expect(gridToPixel({ x: 59, y: 59 })).toEqual({ x: 1888, y: 1888 });
        expect(gridToPixel({ x: 59, y: 58 })).toEqual({ x: 1904, y: 1856 });
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
});
