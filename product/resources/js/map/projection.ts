export interface AxialCoordinate { q: number; r: number }
export interface StaggeredCoordinate { column: number; row: number }

export const TILE_SIZE = 32;
export const HALF_TILE = TILE_SIZE / 2;
export const VERTICAL_STEP = 32;

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

/** Axial coordinates remain canonical; this is the legacy-compatible display projection. */
export function axialToStaggered({ q, r }: AxialCoordinate): StaggeredCoordinate {
    return { column: q + floorDiv(r + 1, 2), row: r };
}

export function staggeredToAxial({ column, row }: StaggeredCoordinate): AxialCoordinate {
    return { q: column - floorDiv(row + 1, 2), r: row };
}

export function axialToStaggeredPixel(coordinate: AxialCoordinate): { x: number; y: number } {
    const offset = axialToStaggered(coordinate);

    return {
        x: offset.column * TILE_SIZE + (floorMod(offset.row, 2) === 0 ? HALF_TILE : 0),
        y: offset.row * VERTICAL_STEP,
    };
}

export function neighbor(coordinate: AxialCoordinate, direction: number): AxialCoordinate {
    const vector = DIRECTIONS[direction];
    if (vector === undefined) throw new RangeError('direction must be between 0 and 5');
    return { q: coordinate.q + vector.q, r: coordinate.r + vector.r };
}
