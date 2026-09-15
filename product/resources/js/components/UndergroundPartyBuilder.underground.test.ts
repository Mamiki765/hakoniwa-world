import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import UndergroundPartyBuilder, { type PartyCandidate } from './UndergroundPartyBuilder.vue';

const candidate = (id: number, source: PartyCandidate['source']): PartyCandidate => ({
    secretary_id: id, source, display_name: `秘書${id}`, combat_level: 20, available: true,
});

describe('Underground party builder', () => {
    it('disables a fourth borrowed member while retaining the implicit self member and emits selection', async () => {
        const candidates = [candidate(2, 'borrowed_secretary'), candidate(3, 'borrowed_secretary'), candidate(4, 'borrowed_secretary'), candidate(5, 'borrowed_secretary')];
        const wrapper = mount(UndergroundPartyBuilder, { props: { candidates, selectedIds: [2, 3, 4] } });
        expect(wrapper.get('.underground-party-count').text()).toBe('4 / 4人');
        expect(wrapper.findAll('button')[3]!.attributes('disabled')).toBeDefined();
        await wrapper.findAll('button')[0]!.trigger('click');
        expect(wrapper.emitted('toggle')?.[0]?.[0]).toEqual(candidates[0]);
    });

    it('does not allow unavailable borrowed members', async () => {
        const unavailable = { ...candidate(2, 'borrowed_secretary'), available: false };
        const wrapper = mount(UndergroundPartyBuilder, { props: { candidates: [unavailable], selectedIds: [] } });
        expect(wrapper.findAll('button')[0]!.attributes('disabled')).toBeDefined();
        await wrapper.findAll('button')[0]!.trigger('click');
        expect(wrapper.emitted('toggle')).toBeUndefined();
    });
});
