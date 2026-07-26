export interface AxialCoordinate { q: number; r: number }
export interface OddQCoordinate { column: number; row: number }

export const DIRECTIONS: readonly AxialCoordinate[] = [
    { q: 1, r: 0 }, { q: 1, r: -1 }, { q: 0, r: -1 },
    { q: -1, r: 0 }, { q: -1, r: 1 }, { q: 0, r: 1 },
];

export function floorDiv(value: number, size: number): number {
    return Math.floor(value / size);
}

export function floorMod(value: number, size: number): number {
    return value - floorDiv(value, size) * size;
}

export function axialToOddQ({ q, r }: AxialCoordinate): OddQCoordinate {
    return { column: q, row: r + (q - floorMod(q, 2)) / 2 };
}

export function oddQToAxial({ column, row }: OddQCoordinate): AxialCoordinate {
    return { q: column, r: row - (column - floorMod(column, 2)) / 2 };
}

export function neighbor(coordinate: AxialCoordinate, direction: number): AxialCoordinate {
    const vector = DIRECTIONS[direction];
    if (vector === undefined) throw new RangeError('direction must be between 0 and 5');
    return { q: coordinate.q + vector.q, r: coordinate.r + vector.r };
}

export function axialToPixel(coordinate: AxialCoordinate, size: number): { x: number; y: number } {
    const offset = axialToOddQ(coordinate);
    const parity = floorMod(offset.column, 2);
    return {
        x: offset.column * Math.sqrt(3) * size,
        y: (offset.row + parity * 0.5) * size * 1.5,
    };
}
