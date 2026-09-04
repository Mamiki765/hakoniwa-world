import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { MapCell, MapCellDetail } from '../types';
import CellDetails from './CellDetails.vue';

const detail = (key: string, label: string, value: number | string, formatted: string): MapCellDetail => ({
    key, label, value, formatted, unit: null, visibility: 'public',
});

function cell(overrides: Partial<MapCell> = {}): MapCell {
    return {
        x: 3, y: -2, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
        display_name: '平地', owner_nation_id: 18, owner_nation_number: 1, owner_name: '試験国',
        within_viewer_visibility: false, details: [],
        monster: null,
        asset: { key: 'tile.plain', url: null, available: false, fallback_label: '平地', fallback_style: 'tile-plain' },
        overlays: [], aria_label: 'x 3 y -2 平地 所有 試験国', version: 1, updated_at: null,
        ...overrides,
    };
}

describe('viewer-safe cell details', () => {
    it('shows the per-World nation number instead of the internal database id', () => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            display_name: '観光船',
            ship: {
                id: 7, key: 'tourist', name: '観光船', asset_key: 'ship.tourist',
                current_hp: 2, max_hp: 2, public_state: 'active',
                owner_nation: { nation_number: 1, name: '試験国' },
                is_owner: true, heading: null, version: 1,
            },
        }) } });

        expect(wrapper.text()).toContain('N1');
        expect(wrapper.text()).not.toContain('N18');
        expect(wrapper.text()).toContain('船HP2/2');
        expect(wrapper.text()).toContain('船舶所有試験国（N1）');
    });

    it.each([
        ['村', 'village'], ['首都', 'capital'],
    ])('shows settlement population for %s', (name, facility) => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            facility, facility_name: name, display_name: name,
            details: [detail('population', '人口', 1000, '1,000人')],
        }) } });
        expect(wrapper.text()).toContain(name);
        expect(wrapper.text()).toContain('人口');
        expect(wrapper.text()).toContain('1,000人');
    });

    it('shows owner forest quantity without a meaningless zero population', () => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            terrain: 'forest', terrain_name: '森', display_name: '森',
            details: [detail('terrain_quantity', '木', 500, '500本')],
        }) } });
        expect(wrapper.text()).toContain('木');
        expect(wrapper.text()).toContain('500本');
        expect(wrapper.text()).not.toContain('人口');
        expect(wrapper.text()).not.toContain('人口0');
    });

    it.each([
        ['farm', '農場', '10,000人規模'],
        ['factory', '工場', '30,000人規模'],
        ['mine', '採掘場', '5,000人規模'],
    ])('shows backend-formatted capacity for %s', (facility, name, formatted) => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            facility, facility_name: name, display_name: name,
            details: [detail('facility_capacity', '規模', 10000, formatted)],
        }) } });
        expect(wrapper.text()).toContain(formatted);
        expect(wrapper.text()).not.toContain('生産予定');
        expect(wrapper.text()).toContain('労働者割当');
        expect(wrapper.text()).toContain('ターン処理未実装');
        expect(wrapper.text()).not.toContain('人口0');
    });

    it('shows owner missile experience, level and launch capacity without population', () => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            facility: 'missile_base', facility_name: 'ミサイル基地', display_name: 'ミサイル基地',
            details: [
                detail('facility_experience', '経験値', 20, '20'),
                detail('facility_level', 'LV', 2, '2'),
                detail('launch_capacity', '発射可能数', 2, '2発'),
            ],
        }) } });
        expect(wrapper.text()).toContain('経験値');
        expect(wrapper.text()).toContain('LV');
        expect(wrapper.text()).toContain('発射可能数');
        expect(wrapper.text()).not.toContain('人口');
        expect(wrapper.text()).not.toContain('人規模');
    });

    it('shows public monster HP, skill, hardening and the current host Nation number', () => {
        const wrapper = mount(CellDetails, { props: { cell: cell({
            monster: {
                id: 8, key: 'whale', name: 'クジラ', asset_key: 'hakoniwa_original.monster.kujira', asset_url: null,
                asset: { key: 'hakoniwa_original.monster.kujira', url: null, available: false, fallback_label: 'クジラ', fallback_style: 'monster-kujira' },
                current_hp: 4, spawned_max_hp: 5, hp_range: { min: 4, max: 5 },
                skill_description: '偶数ターンは硬化する。', hardened_now: true, public_state: 'alive',
                coordinate: { x: 3, y: -2 }, host_nation: { nation_number: 1, name: '試験島' }, host_label: 'N1',
            },
        }) } });

        expect(wrapper.text()).toContain('クジラ');
        expect(wrapper.text()).toContain('現在HP4');
        expect(wrapper.text()).toContain('4～5');
        expect(wrapper.text()).toContain('偶数ターンは硬化する。');
        expect(wrapper.text()).toContain('硬化中');
        expect(wrapper.text()).toContain('試験島（N1）');
        expect(wrapper.text()).not.toContain('N18');
    });

    it('renders a public disguised representation exactly as an ordinary forest detail view', () => {
        const ordinary = mount(CellDetails, { props: { cell: cell({
            terrain: 'forest', terrain_name: '森', display_name: '森', details: [],
        }) } });
        const disguised = mount(CellDetails, { props: { cell: cell({
            terrain: 'forest', terrain_name: '森', facility: null, facility_name: null, display_name: '森', details: [],
        }) } });
        expect(disguised.html()).toBe(ordinary.html());
        expect(disguised.html()).not.toContain('missile_base');
        expect(disguised.text()).not.toContain('ミサイル基地');
    });
});
