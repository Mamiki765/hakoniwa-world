import { gridToPixel, TILE_SIZE } from './projection';
import type { MapBounds } from '../types';

/** The outline follows the staggered rows; only the area's perimeter is drawn. */
export function seaAreaOutline(bounds: MapBounds, origin: { x: number; y: number }): string {
    const points: string[] = [];
    const add = (x: number, y: number): void => { points.push(`${x - origin.x},${y - origin.y}`); };
    add(gridToPixel({ x: bounds.min_x, y: bounds.min_y }).x, bounds.min_y * TILE_SIZE);
    for (let y = bounds.min_y; y <= bounds.max_y; y++) {
        const right = gridToPixel({ x: bounds.max_x, y }).x + TILE_SIZE;
        add(right, y * TILE_SIZE);
        add(right, (y + 1) * TILE_SIZE);
    }
    for (let y = bounds.max_y; y >= bounds.min_y; y--) {
        const left = gridToPixel({ x: bounds.min_x, y }).x;
        add(left, (y + 1) * TILE_SIZE);
        add(left, y * TILE_SIZE);
    }

    return points.join(' ');
}
