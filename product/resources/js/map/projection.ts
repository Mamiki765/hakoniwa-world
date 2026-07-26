export interface GridCoordinate { x: number; y: number }

export const TILE_SIZE = 32;
export const HALF_TILE = TILE_SIZE / 2;
export const VERTICAL_STEP = 32;

export const DIRECTIONS = {
    EAST: 0,
    NORTH_EAST: 1,
    NORTH_WEST: 2,
    WEST: 3,
    SOUTH_WEST: 4,
    SOUTH_EAST: 5,
} as const;

export function floorDiv(value: number, size: number): number {
    return Math.floor(value / size);
}

export function floorMod(value: number, size: number): number {
    return value - floorDiv(value, size) * size;
}

export function gridToPixel({ x, y }: GridCoordinate): GridCoordinate {
    return {
        x: x * TILE_SIZE + (floorMod(y, 2) === 0 ? HALF_TILE : 0),
        y: y * VERTICAL_STEP,
    };
}

export function neighbor(coordinate: GridCoordinate, direction: number): GridCoordinate {
    const evenRow = floorMod(coordinate.y, 2) === 0;
    const vector = {
        [DIRECTIONS.EAST]: { x: 1, y: 0 },
        [DIRECTIONS.NORTH_EAST]: { x: evenRow ? 1 : 0, y: -1 },
        [DIRECTIONS.NORTH_WEST]: { x: evenRow ? 0 : -1, y: -1 },
        [DIRECTIONS.WEST]: { x: -1, y: 0 },
        [DIRECTIONS.SOUTH_WEST]: { x: evenRow ? 0 : -1, y: 1 },
        [DIRECTIONS.SOUTH_EAST]: { x: evenRow ? 1 : 0, y: 1 },
    }[direction];
    if (vector === undefined) throw new RangeError('direction must be between 0 and 5');

    return { x: coordinate.x + vector.x, y: coordinate.y + vector.y };
}
