import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { boostPhase, formatBoostRemaining, type BoostSchedule } from './boosts';
import BoostBanner from './components/BoostBanner.vue';

const turn: BoostSchedule = {
    id: 1,
    kind: 'turn_acceleration',
    starts_at: '2026-10-25T03:00:00Z',
    ends_at: '2026-10-25T09:00:00Z',
    turn_interval_minutes: 30,
};
const hunting: BoostSchedule = {
    id: 2,
    kind: 'hunting_ground',
    starts_at: '2026-10-25T00:00:00Z',
    ends_at: '2026-10-26T00:00:00Z',
    multiplier: 2,
};

describe('boost schedules', () => {
    it('switches from upcoming to active and disappears after the end', () => {
        expect(boostPhase(turn, Date.parse('2026-10-25T02:00:00Z'))).toMatchObject({ phase: 'upcoming', remainingMs: 3_600_000 });
        expect(boostPhase(turn, Date.parse('2026-10-25T03:00:00Z'))).toMatchObject({ phase: 'active', remainingMs: 6 * 3_600_000 });
        expect(boostPhase(turn, Date.parse('2026-10-25T09:00:00Z'))).toBeNull();
        expect(boostPhase({ ...turn, starts_at: 'broken' }, 0)).toBeNull();
    });

    it('formats the remaining time', () => {
        expect(formatBoostRemaining(3_723_000)).toBe('01:02:03');
        expect(formatBoostRemaining(2 * 86_400_000 + 5 * 3_600_000)).toBe('2日5時間');
        expect(formatBoostRemaining(-1)).toBe('00:00:00');
    });

    it('shows each boost independently', () => {
        const now = Date.parse('2026-10-25T01:00:00Z');
        const views = [boostPhase(hunting, now)!, boostPhase(turn, now)!];
        const wrapper = mount(BoostBanner, { props: { boosts: views } });
        const banners = wrapper.findAll('.boost-banner');

        expect(banners).toHaveLength(2);
        expect(banners[0]!.attributes()).toMatchObject({ 'data-kind': 'hunting_ground', 'data-phase': 'active' });
        expect(banners[0]!.text()).toContain('開催中');
        expect(banners[0]!.text()).toContain('狩場の獲得が ×2');
        expect(banners[1]!.attributes()).toMatchObject({ 'data-kind': 'turn_acceleration', 'data-phase': 'upcoming' });
        expect(banners[1]!.text()).toContain('ターン更新が30分ごとになります');
        expect(banners[1]!.text()).toContain('02:00:00');
    });

    it('renders nothing without boosts', () => {
        expect(mount(BoostBanner, { props: { boosts: [] } }).find('.boost-banners').exists()).toBe(false);
    });
});
