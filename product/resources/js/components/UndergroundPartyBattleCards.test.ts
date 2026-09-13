import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UndergroundPartyBattleCards from './UndergroundPartyBattleCards.vue';

describe('Underground party battle cards', () => {
    it('shows each boundary barrier separately from HP in cards and portrait events', async () => {
        const state = { hp: 6220, max_hp: 8000, mp: 10000, barrier: 754 };
        const wrapper = mount(UndergroundPartyBattleCards, { props: {
            actors: [{ team: 'player', combatant_id: 'secretary:1', display_name: '秘書', state: { ...state, barrier: 9999 } }],
            stateById: { 'secretary:1': state },
            portraitEvents: [{ type: 'start', combatant_id: 'secretary:1', state: { ...state, barrier: 922 } }],
        } });
        const hp = wrapper.get('.underground-party-meter.is-hp');
        expect(hp.text()).toBe('6220 +754/8000');
        expect(hp.get('progress').attributes('value')).toBe('6220');
        expect(hp.get('progress').attributes('max')).toBe('8000');
        expect(wrapper.get('.underground-party-portrait-state').text()).toContain('HP 6220 +922/8000');
        await wrapper.setProps({ stateById: { 'secretary:1': { ...state, barrier: 0 } } });
        expect(hp.text()).toBe('6220/8000');
        expect(wrapper.text()).not.toContain('+9999');
    });

    it('uses icon for normal actors and portrait only for selected large-art events', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: { actors: [
            { team: 'player', combatant_id: 'secretary:1', display_name: '自分', icon_url: '/icon', portrait_url: '/portrait', state: { hp: 5, max_hp: 10, mp: 2, awakening_unlocked: true, awakening_gauge: 1000, awakening_gauge_max: 1000 }, awakening_state: 'ready' },
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
        expect(wrapper.get('.underground-party-portrait-awakening').text()).toContain('覚醒Ready');
        expect(wrapper.get('.underground-party-portrait-awakening progress').attributes('aria-label')).toBe('覚醒ゲージ 100%、Ready');
        expect(wrapper.text()).not.toContain('1000/1000');
        expect(wrapper.text()).not.toContain('secretary:1');
    });

    it('labels an active awakening as Awaken without exposing the raw gauge value', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: { actors: [
            { team: 'player', combatant_id: 'secretary:1', display_name: '自分', state: { hp: 10, max_hp: 10, mp: 10000, awakening_gauge: 8500, awakening_gauge_max: 10000, awakened: true }, awakening_state: 'awakened' },
        ] } });
        expect(wrapper.text()).toContain('Awaken!');
        expect(wrapper.text()).not.toContain('8500/10000');
    });

    it('renders the saved portrait-event gauge instead of current actor state and hides a locked gauge', () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: {
            actors: [
                { team: 'player', combatant_id: 'secretary:1', display_name: '自分', state: { hp: 10, max_hp: 10, mp: 2, awakening_unlocked: true, awakening_gauge: 0, awakening_gauge_max: 1000, awakened: true }, awakening_state: 'awakened' },
                { team: 'player', combatant_id: 'secretary:2', display_name: '同行者', state: { hp: 10, max_hp: 10, mp: 2, awakening_unlocked: true, awakening_gauge: 1000, awakening_gauge_max: 1000 }, awakening_state: 'ready' },
            ],
            portraitEvents: [
                { type: 'start', round: 1, combatant_id: 'secretary:1', state: { hp: 8, max_hp: 10, mp: 1, awakening_unlocked: true, awakening_gauge: 400, awakening_gauge_max: 1000, awakened: false } },
                { type: 'start', round: 1, combatant_id: 'secretary:2', state: { hp: 9, max_hp: 10, mp: 2, awakening_unlocked: false, awakening_gauge: 0, awakening_gauge_max: 1000, awakened: false } },
            ],
            portraitEventType: 'start',
        } });

        const eventGauges = wrapper.findAll('.underground-party-portrait-awakening');
        expect(eventGauges).toHaveLength(1);
        expect(eventGauges[0]!.text()).toContain('覚醒蓄積中');
        expect(eventGauges[0]!.get<HTMLProgressElement>('progress').element.value).toBe(400);
        expect(eventGauges[0]!.get('progress').attributes('max')).toBe('1000');
        expect(eventGauges[0]!.get('progress').attributes('aria-label')).toBe('覚醒ゲージ 40%、蓄積中');
        expect(eventGauges[0]!.text()).not.toContain('400/1000');
        expect(eventGauges[0]!.text()).not.toContain('Awaken!');
        expect(eventGauges[0]!.text()).not.toContain('Ready');
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

    it('projects awakening art only into its recorded round and reveals saved credit from the info control', async () => {
        const wrapper = mount(UndergroundPartyBattleCards, { props: {
            actors: [{ team: 'player', combatant_id: 'secretary:1', display_name: '自分' }],
            showCards: false,
            portraitEventType: 'awakening',
            portraitRound: 3,
            portraitEvents: [
                { type: 'awakening', round: 2, combatant_id: 'secretary:1', image_ref: { url: '/old' } },
                { type: 'awakening', round: 3, combatant_id: 'secretary:1', image_ref: { url: '/current', creation_method_label: '自作', credit: 'Owner credit' } },
            ],
        } });
        expect(wrapper.find('.underground-party-teams').exists()).toBe(false);
        expect(wrapper.findAll('.underground-party-large-art')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('画像：©');
        const info = wrapper.get('.underground-party-image-info');
        expect(info.attributes('open')).toBeUndefined();
        expect(info.get('summary').text()).toBe('ⓘ');
        await info.get('summary').trigger('click');
        expect(info.attributes('open')).toBeDefined();
        expect(info.text()).toContain('制作方法：自作');
        expect(wrapper.text()).toContain('Owner credit');
    });
});
