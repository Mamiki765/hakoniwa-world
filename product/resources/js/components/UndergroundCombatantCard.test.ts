import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UndergroundCombatantCard from './UndergroundCombatantCard.vue';

describe('Underground combatant card', () => {
    it('shows the current barrier beside HP without changing the HP meter', async () => {
        const state = { hp: 6220, max_hp: 8000, mp: 10000, barrier: 754, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } };
        const wrapper = mount(UndergroundCombatantCard, { props: { name: '秘書', side: 'player', state } });
        const hp = wrapper.get('.underground-matchup-vitals label');
        expect(hp.text()).toBe('HP 6,220 +754/ 8,000');
        expect(hp.get('progress').attributes('value')).toBe('6220');
        expect(hp.get('progress').attributes('max')).toBe('8000');
        await wrapper.setProps({ state: { ...state, barrier: 0 } });
        expect(hp.text()).toBe('HP 6,220/ 8,000');
    });

    it('keeps awakening progress visual while exposing only its percentage to assistive technology', () => {
        const wrapper = mount(UndergroundCombatantCard, {
            props: {
                name: '覚醒秘書',
                side: 'player',
                state: {
                    hp: 500,
                    max_hp: 500,
                    mp: 10_000,
                    barrier: 0,
                    statuses: [],
                    role_stacks: { fighting_spirit: 0, grace: 0 },
                    awakening_unlocked: true,
                    awakening_gauge: 980,
                    awakening_gauge_max: 1_000,
                },
            },
        });

        const gauge = wrapper.get('.underground-combatant-awakening');
        const progress = gauge.get('progress');
        expect(gauge.text()).toBe('覚醒ゲージ');
        expect(progress.attributes('aria-label')).toBe('覚醒ゲージ');
        expect(progress.attributes('aria-valuetext')).toBe('98%');
        expect(progress.attributes('value')).toBe('980');
        expect(progress.attributes('max')).toBe('1000');
    });
});
