import { describe, expect, it } from 'vitest';
import { axialToOddQ, floorDiv, floorMod, neighbor, oddQToAxial } from './projection';

describe('hex projection', () => {
    it.each([
        [0, 0, 0], [15, 0, 15], [16, 1, 0], [-1, -1, 15], [-16, -1, 0], [-17, -2, 15],
    ])('locates %i in chunk %i local %i', (value, chunk, local) => {
        expect(floorDiv(value, 16)).toBe(chunk);
        expect(floorMod(value, 16)).toBe(local);
    });

    it.each([{ q: 0, r: 0 }, { q: 5, r: -9 }, { q: -7, r: 3 }, { q: -1, r: -1 }])('round trips $q,$r', (coordinate) => {
        expect(oddQToAxial(axialToOddQ(coordinate))).toEqual(coordinate);
    });

    it('moves through all six neighbors', () => {
        const values = Array.from({ length: 6 }, (_, direction) => neighbor({ q: 0, r: 0 }, direction));
        expect(new Set(values.map(({ q, r }) => `${q}:${r}`)).size).toBe(6);
    });
});
