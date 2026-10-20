import type { MapCell } from '../types';

export const GIVEN_NAME_MAX_LENGTH = 16;

// 名前は船種の前に同じ文字で添えるだけ。名前がない船は今まで通り「船種 (x,y)」。
export function shipListLabel(ship: { name: string; custom_name?: string | null; x: number; y: number }): string {
    return `${ship.custom_name ? `${ship.custom_name} ` : ''}${ship.name} (${ship.x},${ship.y})`;
}

// マスの見出しに出す、プレイヤーがつけた名前。船が上にいれば船の名前が優先。
export function cellGivenName(cell: MapCell): string | null {
    if (cell.ship != null) return cell.ship.custom_name ?? null;
    return cell.capital_name ?? null;
}
