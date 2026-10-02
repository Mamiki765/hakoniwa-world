import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UndergroundResidence from './UndergroundResidence.vue';
import type { ResidenceState } from './undergroundScenes';

const residence: ResidenceState = {
    villa_owned: true, mirror_owned: false, trophy_shelf_owned: false,
    vault_expansion_owned: false, resonance_expansion_owned: false,
    exchange_intro_page: 2, mirror_event_completed: false,
    items: Object.fromEntries(['villa', 'mirror', 'trophy_shelf', 'vault_expansion', 'resonance_expansion']
        .map((key) => [key, { name: key, price: 100 }])) as ResidenceState['items'],
};
const metric = (value: number | null, unknown = 0) => ({ value, known_battles: value === null ? 0 : 2, unknown_battles: unknown });
const journal = {
    cleared_trials: [], trophies: [], battle_count: 3, victory_count: 2,
    damage_dealt: 20, damage_received: null, damage_dealt_unknown_battles: 1, damage_received_unknown_battles: 3,
    skip_tickets_used: 8, guide_punch_count: 1,
    maximum_hit: { ...metric(90, 1), action_name: '天断一閃' },
    favorite_skills: { entries: [{ key: 'mending_prayer', name: 'ヒール', count: 8 }], known_battles: 2, unknown_battles: 1 },
    combat_support: {
        self: { effective_healing: metric(7, 1), damage_prevented: metric(null, 3), revivals: metric(0) },
        party: { effective_healing: metric(50), damage_prevented: metric(24), revivals: metric(3) },
    },
    content_clears: [
        { type: 'hunting_ground', key: 'shallow_caves', name: '浅い洞窟', actual_clear_count: 2, skip_clear_count: 3 },
        { type: 'trial', key: 'trial_01', name: '試練1', actual_clear_count: null, skip_clear_count: null },
    ],
    lending_participation_count: 42,
};

afterEach(() => vi.unstubAllGlobals());

describe('Adventure journal records', () => {
    it('renders known, partial and missing facts with self and PT columns and separate clear counts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: journal }))));
        const wrapper = mount(UndergroundResidence, { props: { residence, mode: 'villa', busy: false, shards: 0 } });
        await flushPromises();
        expect(wrapper.text()).toContain('天断一閃');
        expect(wrapper.text()).toContain('記録のある 2 戦での最大');
        expect(wrapper.get('ol').text()).toContain('ヒール8 回');
        const tables = wrapper.findAll('table');
        expect(tables[0]!.findAll('th').map((cell) => cell.text())).toContain('本人');
        expect(tables[0]!.findAll('tr')[1]!.findAll('td').map((cell) => cell.text())).toEqual(['7HP記録のある分・1 戦は記録なし', '50HP']);
        expect(tables[0]!.findAll('tr')[2]!.findAll('td').map((cell) => cell.text())).toEqual(['記録なし3 戦は記録なし', '24']);
        expect(tables[1]!.findAll('tr')[1]!.findAll('td').map((cell) => cell.text().replaceAll(' ', ''))).toEqual(['2回', '3回']);
        expect(tables[1]!.findAll('tr')[2]!.findAll('td').map((cell) => cell.text())).toEqual(['記録なし', '記録なし']);
        expect(wrapper.text()).toContain('42 回');
        wrapper.unmount();
    });

    it('keeps all-unknown maxima and action usage distinct from a known zero support total', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {
            ...journal, maximum_hit: { ...metric(null, 3), action_name: null },
            favorite_skills: { entries: [], known_battles: 0, unknown_battles: 3 },
        } }))));
        const wrapper = mount(UndergroundResidence, { props: { residence, mode: 'villa', busy: false, shards: 0 } });
        await flushPromises();
        expect(wrapper.text()).toContain('最大ダメージ記録なし');
        expect(wrapper.text()).toContain('使用技の記録なし');
        expect(wrapper.text()).not.toContain('技名の記録なし');
        expect(wrapper.findAll('table')[0]!.findAll('tr')[3]!.findAll('td')[0]!.text()).toBe('0回');
        wrapper.unmount();
    });
});
