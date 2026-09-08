import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UndergroundPartyBattleCards from './UndergroundPartyBattleCards.vue';

describe('Underground party battle cards', () => {
    it('uses icon for normal actors and portrait only for selected large-art events', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: { actors: [
            { team: 'player', combatant_id: 'secretary:1', display_name: '自分', icon_url: '/icon', portrait_url: '/portrait', state: { hp: 5, max_hp: 10, mp: 2, awakening_gauge: 10000, awakening_gauge_max: 10000 }, awakening_state: 'ready' },
            { team: 'enemy', combatant_id: 'enemy:1', display_name: '敵', icon_url: '/enemy-icon', portrait_url: '/enemy-portrait', state: { hp: 10, max_hp: 10, mp: 0 } },
        ], portraitEvents: [{ type: 'start', round: 1, combatant_id: 'secretary:1' }], portraitEventType: 'start' } });
        expect(wrapper.findAll('.underground-party-large-art')).toHaveLength(1);
        expect(wrapper.findAll('.underground-party-icon')).toHaveLength(2);
        expect(wrapper.findAll('.underground-party-team')).toHaveLength(2);
        expect(wrapper.findAll('.underground-party-team').at(0)!.text()).toContain('PARTY');
        expect(wrapper.findAll('.underground-party-team').at(1)!.text()).toContain('ENEMY');
        expect(wrapper.text()).toContain('Ready');
        expect(wrapper.text()).toContain('5/10');
        expect(wrapper.text()).not.toContain('2/10000');
        expect(wrapper.text()).not.toContain('10000/10000');
        expect(wrapper.text()).not.toContain('secretary:1');
    });

    it('labels an active awakening as Awaken without exposing the raw gauge value', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: { actors: [
            { team: 'player', combatant_id: 'secretary:1', display_name: '自分', state: { hp: 10, max_hp: 10, mp: 10000, awakening_gauge: 8500, awakening_gauge_max: 10000, awakened: true }, awakening_state: 'awakened' },
        ] } });
        expect(wrapper.text()).toContain('Awaken!');
        expect(wrapper.text()).not.toContain('8500/10000');
    });

    it('renders each server portrait event once by event identity', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: {
            actors: [{ team: 'player', combatant_id: 'secretary:1', display_name: '自分', portrait_url: '/portrait' }],
            portraitEvents: [
                { type: 'start', round: 1, combatant_id: 'secretary:1', event_id: 'start-1' },
                { type: 'start', round: 1, combatant_id: 'secretary:1', event_id: 'start-1' },
                { type: 'final', round: 2, combatant_id: 'secretary:1', event_id: 'final-1' },
            ],
        } });
        expect(wrapper.findAll('.underground-party-portrait-event')).toHaveLength(2);
        expect(wrapper.findAll('.underground-party-large-art')).toHaveLength(2);
    });

    it('projects awakening art only into its recorded round and keeps credit visible', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: {
            actors: [{ team: 'player', combatant_id: 'secretary:1', display_name: '自分' }],
            showCards: false,
            portraitEventType: 'awakening',
            portraitRound: 3,
            portraitEvents: [
                { type: 'awakening', round: 2, combatant_id: 'secretary:1', image_ref: { url: '/old' } },
                { type: 'awakening', round: 3, combatant_id: 'secretary:1', image_ref: { url: '/current', credit: 'Owner credit' } },
            ],
        } });
        expect(wrapper.find('.underground-party-teams').exists()).toBe(false);
        expect(wrapper.findAll('.underground-party-large-art')).toHaveLength(1);
        expect(wrapper.text()).toContain('Owner credit');
    });
});
