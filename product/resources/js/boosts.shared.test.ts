import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { boostPhase, fetchBoostSchedules, formatBoostRemaining, type BoostSchedule } from './boosts';
import BoostBanner from './components/BoostBanner.vue';

const turn: BoostSchedule = {
    id: 1,
    kind: 'turn_acceleration',
    starts_at: '2026-10-25T03:00:00Z',
    ends_at: '2026-10-25T09:00:00Z',
    turn_interval_minutes: 60,
    effects: { compensation_only: true },
};
const hunting: BoostSchedule = {
    id: 2,
    kind: 'hunting_ground',
    starts_at: '2026-10-25T00:00:00Z',
    ends_at: '2026-10-26T00:00:00Z',
    multiplier: 2,
};

afterEach(() => {
    vi.unstubAllGlobals();
});

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
        expect(banners[0]!.text()).toContain('レア敵出現率・通常敵の宝物庫の鍵出現率・経験値が各2倍');
        expect(banners[0]!.text()).not.toContain('獲得が ×2');
        expect(banners[1]!.attributes()).toMatchObject({ 'data-kind': 'turn_acceleration', 'data-phase': 'upcoming' });
        expect(banners[1]!.text()).toContain('未補填分のみ');
        expect(banners[1]!.text()).not.toContain('60分');
        expect(banners[1]!.text()).toContain('02:00:00');
    });

    it('renders nothing without boosts', () => {
        expect(mount(BoostBanner, { props: { boosts: [] } }).find('.boost-banners').exists()).toBe(false);
    });

    it('prefers the server description', () => {
        const view = boostPhase({ ...hunting, description: '狩場のレア出現率・通常敵の鍵出現率・獲得XPが2倍（Gや鍵の個数は変わりません）' }, Date.parse('2026-10-25T01:00:00Z'))!;
        const wrapper = mount(BoostBanner, { props: { boosts: [view] } });

        expect(wrapper.text()).toContain('Gや鍵の個数は変わりません');
    });

    it('reads the public list and the server clock offset', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({
            data: [turn, { ...hunting, kind: 'unknown_kind' }],
            meta: { server_now: new Date(Date.now() + 60_000).toISOString(), timezone: 'Asia/Tokyo' },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } })));
        const result = await fetchBoostSchedules(1);

        expect(result.schedules).toEqual([turn]);
        expect(result.clockOffsetMs).toBeGreaterThan(55_000);
        expect(result.clockOffsetMs).toBeLessThan(65_000);
    });

    it('shows nothing when the API is missing', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 404, headers: { 'Content-Type': 'application/json' } })));

        expect(await fetchBoostSchedules(1)).toEqual({ schedules: [], clockOffsetMs: 0 });
    });
});
