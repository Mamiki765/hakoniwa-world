import { describe, expect, it } from 'vitest';
import {
    axialToStaggered,
    axialToStaggeredPixel,
    floorDiv,
    floorMod,
    neighbor,
    staggeredToAxial,
} from './projection';

describe('staggered square-tile projection', () => {
    it.each([
        [0, 0, 0], [15, 0, 15], [16, 1, 0], [-1, -1, 15], [-16, -1, 0], [-17, -2, 15],
    ])('locates %i in chunk %i local %i', (value, chunk, local) => {
        expect(floorDiv(value, 16)).toBe(chunk);
        expect(floorMod(value, 16)).toBe(local);
    });

    it.each([{ q: 0, r: 0 }, { q: 5, r: -9 }, { q: -7, r: 3 }, { q: -1, r: -1 }])('round trips $q,$r', (coordinate) => {
        expect(staggeredToAxial(axialToStaggered(coordinate))).toEqual(coordinate);
    });

    it('offsets even rows by half a 32px tile', () => {
        expect(axialToStaggeredPixel({ q: 0, r: 0 })).toEqual({ x: 16, y: 0 });
        expect(axialToStaggeredPixel({ q: 0, r: 1 })).toEqual({ x: 32, y: 32 });
        expect(axialToStaggeredPixel({ q: 0, r: -1 })).toEqual({ x: 0, y: -32 });
    });

    it('moves through all six adjacent staggered cells', () => {
        const pixels = Array.from({ length: 6 }, (_, direction) => axialToStaggeredPixel(neighbor({ q: 0, r: 0 }, direction)));
        expect(new Set(pixels.map(({ x, y }) => `${x}:${y}`)).size).toBe(6);
        expect(pixels).toEqual([
            { x: 48, y: 0 }, { x: 32, y: -32 }, { x: 0, y: -32 },
            { x: -16, y: 0 }, { x: 0, y: 32 }, { x: 32, y: 32 },
        ]);
    });
});
