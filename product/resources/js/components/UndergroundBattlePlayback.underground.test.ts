import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UndergroundBattlePlayback, { type PlaybackRound, type PlaybackStep } from './UndergroundBattlePlayback.vue';

const hit = (amount: number): PlaybackStep => ({
    text: `敵に${amount}`, tone: '', highlight: null, updates: [],
    hits: [{ targetId: 'enemy', kind: 'damage', amount, critical: false }],
});
const vitals = (hp: number) => ({ player: { hp: 100, max_hp: 100 }, enemy: { hp, max_hp: 1000 } });
const rounds: PlaybackRound[] = [
    { round: 1, start: vitals(1000), end: vitals(940), steps: [hit(10), hit(20), hit(30)] },
    { round: 2, start: vitals(940), end: vitals(790), steps: [hit(40), hit(50), hit(60)] },
];

const mountPlayback = () => mount(UndergroundBattlePlayback, {
    props: { rounds, combatants: [{ id: 'player', name: '秘書', side: 'player' }, { id: 'enemy', name: '敵', side: 'enemy' }], resultLabel: '勝利' },
});
const button = (wrapper: VueWrapper, text: string) => wrapper.findAll('button').find((candidate) => candidate.text().includes(text))!;
const advance = async (wrapper: VueWrapper, ms: number) => {
    vi.advanceTimersByTime(ms);
    await wrapper.vm.$nextTick();
};
const shown = (wrapper: VueWrapper) => {
    const label = wrapper.get('.ug-playback-round').text();
    const round = Number(/第(\d+)/.exec(label)?.[1]);
    const steps = Number(/(\d+) \//.exec(label)?.[1] ?? rounds[round - 1]!.steps.length);
    return { round, steps };
};
// 合計は、画面に出した行動だけを足した値と一致するはず。
const expectedTotal = (wrapper: VueWrapper) => {
    const { round, steps } = shown(wrapper);
    return rounds[round - 1]!.steps.slice(0, steps).reduce((sum, step) => sum + step.hits[0]!.amount, 0);
};
const total = (wrapper: VueWrapper) => {
    const element = wrapper.find('.ug-playback-total .is-damage');
    return element.exists() ? Number(element.text().replace(/\D/g, '')) : null;
};

describe('UndergroundBattlePlayback totals', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('keeps going into the next round without showing totals during continuous play, and shows them at the end', async () => {
        const wrapper = mountPlayback();
        await button(wrapper, '再生').trigger('click');
        let sawSecondRound = false;
        for (let elapsed = 0; elapsed < 5_000 && wrapper.get('.ug-playback-play').attributes('aria-pressed') === 'true'; elapsed += 10) {
            await advance(wrapper, 10);
            if (wrapper.get('.ug-playback-play').attributes('aria-pressed') !== 'true') break;
            expect(total(wrapper)).toBeNull();
            sawSecondRound ||= shown(wrapper).round === 2;
        }
        expect(sawSecondRound).toBe(true);
        expect(wrapper.text()).toContain('勝利');
        expect(total(wrapper)).toBe(150);
        expect(total(wrapper)).toBe(expectedTotal(wrapper));
    });

    it('shows the round total after playing a single round', async () => {
        const wrapper = mountPlayback();
        await button(wrapper, '1ラウンド送る').trigger('click');
        await advance(wrapper, 2_000);
        expect(shown(wrapper).round).toBe(1);
        expect(total(wrapper)).toBe(60);

        await button(wrapper, '1ラウンド送る').trigger('click');
        expect(total(wrapper)).toBeNull();
        await advance(wrapper, 2_000);
        expect(total(wrapper)).toBe(150);
    });

    it.each([0, 1, 2, 3, 4])('matches the displayed steps when continuous play is stopped mid-way (after %i ticks)', async (ticks) => {
        const wrapper = mountPlayback();
        await button(wrapper, '再生').trigger('click');
        // 1行動 170ms、ラウンドの最後の行動のあと 500ms で次のラウンドへ続く。
        const schedule = [170, 170, 500, 170, 170];
        for (const ms of schedule.slice(0, ticks)) await advance(wrapper, ms);
        await advance(wrapper, 50);
        await button(wrapper, '止める').trigger('click');
        expect(total(wrapper)).toBe(expectedTotal(wrapper));

        // 再開すると合計は消え、続きの行動から進む。
        const before = shown(wrapper);
        await button(wrapper, '再生').trigger('click');
        expect(total(wrapper)).toBeNull();
        const after = shown(wrapper);
        expect(after.round > before.round || after.steps > before.steps).toBe(true);
        await advance(wrapper, 5_000);
        expect(total(wrapper)).toBe(150);
    });

    it('settles the last round when stopped just after its final action', async () => {
        const wrapper = mountPlayback();
        await button(wrapper, '再生').trigger('click');
        await advance(wrapper, 170 * 2 + 500 + 170 * 2 + 50);
        expect(shown(wrapper)).toEqual({ round: 2, steps: 3 });
        await button(wrapper, '止める').trigger('click');
        expect(total(wrapper)).toBe(150);
        expect(wrapper.get('.ug-playback-team.is-enemy .ug-playback-hp').text()).toContain('790');
        expect(wrapper.text()).toContain('勝利');
    });
});
