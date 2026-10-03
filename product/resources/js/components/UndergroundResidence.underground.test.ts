import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
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

const label = (text: string) => text.replace(/\s+/g, '');
const numbers = (text: string) => (text.match(/\d[\d,]*/g) ?? []).map((value) => Number(value.replaceAll(',', '')));

function definition(wrapper: VueWrapper, name: string): Element | undefined {
    return wrapper.findAll('dt').find((term) => label(term.text()) === label(name))?.element.nextElementSibling ?? undefined;
}

function cell(wrapper: VueWrapper, rowName: string, columnName: string): Element {
    const row = wrapper.findAll('tr').find((candidate) => {
        const heading = candidate.find('th[scope="row"]');
        return heading.exists() && label(heading.text()) === label(rowName);
    });
    if (!row) throw new Error(`Missing record row: ${rowName}`);
    const columns = [...row.element.closest('table')!.querySelectorAll('th[scope="col"]')];
    const index = columns.findIndex((heading) => label(heading.textContent ?? '') === label(columnName));
    if (index < 0) throw new Error(`Missing record column: ${columnName}`);
    return row.element.children[index]!;
}

// Read the primary quantity separately from its coverage note. A missing value
// must still have a visible nonnumeric placeholder, while a known zero stays 0.
function quantity(element: Element | undefined): number | null {
    expect(element).toBeDefined();
    const primary = element!.cloneNode(true) as Element;
    primary.querySelectorAll('small').forEach((note) => note.remove());
    const text = primary.textContent?.trim() ?? '';
    expect(text).not.toBe('');
    const values = numbers(text);
    expect(values.length).toBeLessThanOrEqual(1);
    return values[0] ?? null;
}

afterEach(() => vi.unstubAllGlobals());

describe('Adventure journal records', () => {
    it('renders known, partial and missing facts with self and PT columns and separate clear counts', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: journal }))));
        const wrapper = mount(UndergroundResidence, { props: { residence, mode: 'villa', busy: false, shards: 0 } });
        await flushPromises();
        const maximum = definition(wrapper, '最大ダメージ');
        expect(quantity(maximum)).toBe(journal.maximum_hit.value);
        expect(numbers(maximum!.querySelector('small')!.textContent ?? '')).toEqual([journal.maximum_hit.known_battles]);
        expect(definition(wrapper, '技名')!.textContent).toContain(journal.maximum_hit.action_name);
        const skill = journal.favorite_skills.entries[0]!;
        const skillRow = wrapper.findAll('ol li').find((entry) => entry.find('span').text() === skill.name);
        expect(quantity(skillRow?.find('strong').element)).toBe(skill.count);
        const selfHealing = cell(wrapper, '実回復HP', '本人');
        expect(quantity(selfHealing)).toBe(journal.combat_support.self.effective_healing.value);
        expect(numbers(selfHealing.querySelector('small')!.textContent ?? '')).toEqual([journal.combat_support.self.effective_healing.unknown_battles]);
        expect(quantity(cell(wrapper, '実回復HP', 'PT合計'))).toBe(journal.combat_support.party.effective_healing.value);
        const selfDefense = cell(wrapper, '防いだダメージ', '本人');
        expect(quantity(selfDefense)).toBeNull();
        expect(numbers(selfDefense.querySelector('small')!.textContent ?? '')).toEqual([journal.combat_support.self.damage_prevented.unknown_battles]);
        expect(quantity(cell(wrapper, '防いだダメージ', 'PT合計'))).toBe(journal.combat_support.party.damage_prevented.value);
        const clear = journal.content_clears[0]!;
        expect(quantity(cell(wrapper, clear.name, '実戦'))).toBe(clear.actual_clear_count);
        expect(quantity(cell(wrapper, clear.name, 'スキップ'))).toBe(clear.skip_clear_count);
        const unknownClear = journal.content_clears[1]!;
        expect(quantity(cell(wrapper, unknownClear.name, '実戦'))).toBeNull();
        expect(quantity(cell(wrapper, unknownClear.name, 'スキップ'))).toBeNull();
        expect(quantity(definition(wrapper, '助っ人参加累計'))).toBe(journal.lending_participation_count);
        wrapper.unmount();
    });

    it('keeps all-unknown maxima and action usage distinct from a known zero support total', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response(JSON.stringify({ data: {
            ...journal, maximum_hit: { ...metric(null, 3), action_name: null },
            favorite_skills: { entries: [], known_battles: 0, unknown_battles: 3 },
        } }))));
        const wrapper = mount(UndergroundResidence, { props: { residence, mode: 'villa', busy: false, shards: 0 } });
        await flushPromises();
        expect(quantity(definition(wrapper, '最大ダメージ'))).toBeNull();
        expect(definition(wrapper, '技名')).toBeUndefined();
        expect(wrapper.findAll('ol li')).toHaveLength(0);
        expect(quantity(cell(wrapper, '蘇生した回数', '本人'))).toBe(journal.combat_support.self.revivals.value);
        expect(quantity(cell(wrapper, '蘇生した回数', 'PT合計'))).toBe(journal.combat_support.party.revivals.value);
        wrapper.unmount();
    });
});
