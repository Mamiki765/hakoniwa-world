import type { ShipCourse } from '../types';

export const SHIP_HEADING_LABELS = ['東', '北東', '北西', '西', '南西', '南東'] as const;

// 指示がないときの実際の動き。戦艦（heading_only）はその場で待つ。
export function shipIdleLabel(movementMode: string | null | undefined): string {
    if (movementMode === 'heading_only') return '待機';
    if (movementMode === 'sparkle_or_random') return 'おまかせ（宝を探す）';
    return 'おまかせ';
}

export function shipInstructionLabel(
    course: { heading: ShipCourse['heading']; destination?: ShipCourse['destination'] },
    movementMode: string | null | undefined,
): string {
    if (course.destination != null) return `目的地 (${course.destination.x},${course.destination.y})`;
    if (course.heading != null) return SHIP_HEADING_LABELS[course.heading] ?? '';
    return shipIdleLabel(movementMode);
}
