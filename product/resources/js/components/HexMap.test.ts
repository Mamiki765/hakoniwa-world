import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { MapCell } from '../types';
import HexMap from './HexMap.vue';

function mapCell(overrides: Partial<MapCell> = {}): MapCell {
    return {
        x: 0, y: 0, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
        display_name: '平地', owner_nation_id: 1, owner_name: '地図国', details: [],
        asset: { key: 'tile.plain', url: '/tiles/plain.gif?v=1-1', available: true, fallback_label: '平地', fallback_style: 'tile-plain' },
        overlays: [{ key: 'overlay.selection', url: '/tiles/selection.png?v=1-1', available: true, fallback_label: '', fallback_style: 'overlay-selection' }],
        aria_label: 'x 0 y 0 平地 所有 地図国', version: 1, updated_at: null,
        ...overrides,
    };
}

describe('staggered square-image map', () => {
    it('renders completed and optional overlay images, fallback, selection and six-way keyboard input', async () => {
        const selected = mapCell({
            details: [{ key: 'population', label: '人口', value: 1000, unit: '人', formatted: '1,000人', visibility: 'public' }],
        });
        const fallback = mapCell({
            x: 1, y: 0, terrain: 'forest', terrain_name: '森', display_name: '森', owner_nation_id: null, owner_name: null,
            asset: { key: 'tile.forest', url: null, available: false, fallback_label: '森', fallback_style: 'tile-forest' },
            overlays: [], aria_label: 'x 1 y 0 森 所有 中立',
        });
        const wrapper = mount(HexMap, { props: {
            cells: [selected, fallback], selected, capital: { x: 0, y: 0 }, ownNationId: 1,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const tiles = wrapper.findAll('.map-cell');
        expect(tiles).toHaveLength(2);
        expect(tiles[0]!.classes()).toContain('selected');
        expect(tiles[0]!.find('img:not(.tile-overlay)').attributes('src')).toContain('/tiles/plain.gif');
        expect(tiles[0]!.find('.tile-overlay').attributes('src')).toContain('/tiles/selection.png');
        expect(tiles[1]!.find('img').exists()).toBe(false);
        expect(tiles[1]!.find('.tile-label').text()).toBe('森');
        await tiles[0]!.trigger('mouseenter');
        expect(wrapper.find('.cell-tooltip').text()).toContain('座標 x=0, y=0');
        expect(wrapper.find('.cell-tooltip').text()).toContain('人口');

        await wrapper.find('.map-viewport').trigger('keydown', { key: 'PageUp' });
        expect(wrapper.emitted('move')).toEqual([[1]]);
        await tiles[1]!.trigger('click');
        expect(wrapper.emitted('select')?.[0]).toEqual([fallback]);
    });

    it('does not leak secret facility data when given the public forest representation', async () => {
        const publicForest = mapCell({
            terrain: 'forest', terrain_name: '森', facility: null, facility_name: null, display_name: '森',
            details: [], asset: { key: 'tile.forest', url: null, available: false, fallback_label: '森', fallback_style: 'tile-forest' },
            overlays: [], aria_label: 'x 0 y 0 森 所有 他国', owner_name: '他国',
        });
        const wrapper = mount(HexMap, { props: {
            cells: [publicForest], selected: publicForest, capital: { x: 0, y: 0 },
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        expect(wrapper.html()).not.toContain('missile_base');
        expect(wrapper.html()).not.toContain('ミサイル基地');
        expect(wrapper.find('.map-cell').attributes('aria-label')).toBe('x 0 y 0 森 所有 他国');
        expect(wrapper.find('.map-cell').classes()).toContain('terrain-forest');
    });

    it('uses absolute world y parity before panning around an odd-row capital', async () => {
        const evenRow = mapCell({ x: 0, y: 0 });
        const oddRow = mapCell({ x: 0, y: 1 });
        const wrapper = mount(HexMap, { props: {
            cells: [evenRow, oddRow], selected: evenRow, capital: { x: 5, y: 1 },
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const tiles = wrapper.findAll('.map-cell');
        expect(tiles[0]!.attributes('style')).toContain('left: -144px');
        expect(tiles[0]!.attributes('style')).toContain('top: -32px');
        expect(tiles[1]!.attributes('style')).toContain('left: -160px');
        expect(tiles[1]!.attributes('style')).toContain('top: 0px');
    });
});
