import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { MapCell } from '../types';
import HexMap from './HexMap.vue';

const worldBounds = { min_x: 0, max_x: 59, min_y: 0, max_y: 59 };

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

function dispatchPointer(
    element: Element,
    type: string,
    init: { pointerId: number; pointerType: string; clientX: number; clientY: number; button?: number },
): void {
    const event = new MouseEvent(type, {
        bubbles: true,
        cancelable: true,
        button: init.button ?? 0,
        clientX: init.clientX,
        clientY: init.clientY,
    });
    Object.defineProperties(event, {
        pointerId: { value: init.pointerId },
        pointerType: { value: init.pointerType },
        isPrimary: { value: true },
    });
    element.dispatchEvent(event);
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
            cells: [selected, fallback], selected, capital: { x: 0, y: 0 }, bounds: worldBounds, ownNationId: 1,
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
            cells: [publicForest], selected: publicForest, capital: { x: 0, y: 0 }, bounds: worldBounds,
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
            cells: [evenRow, oddRow], selected: evenRow, capital: { x: 5, y: 1 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const tiles = wrapper.findAll('.map-cell');
        expect(tiles[0]!.attributes('style')).toContain('left: -144px');
        expect(tiles[0]!.attributes('style')).toContain('top: -32px');
        expect(tiles[1]!.attributes('style')).toContain('left: -160px');
        expect(tiles[1]!.attributes('style')).toContain('top: 0px');
    });

    it('selects a cell after a short pointer gesture below the pan threshold', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const tile = wrapper.find('.map-cell');
        dispatchPointer(tile.element, 'pointerdown', { pointerId: 1, pointerType: 'touch', button: 0, clientX: 20, clientY: 20 });
        dispatchPointer(wrapper.find('.map-viewport').element, 'pointermove', { pointerId: 1, pointerType: 'touch', clientX: 23, clientY: 22 });
        dispatchPointer(wrapper.find('.map-viewport').element, 'pointerup', { pointerId: 1, pointerType: 'touch', button: 0, clientX: 23, clientY: 22 });
        await tile.trigger('click');

        expect(wrapper.emitted('select')).toEqual([[cell]]);
        expect(wrapper.find('.map-viewport').classes()).not.toContain('is-dragging');
    });

    it('pans from a cell after crossing the threshold and captures the pointer', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const viewport = wrapper.find('.map-viewport');
        const viewportElement = viewport.element as HTMLElement;
        const captured: number[] = [];
        viewportElement.setPointerCapture = (pointerId: number): void => { captured.push(pointerId); };
        const before = wrapper.find('.map-plane').attributes('style');
        expect(wrapper.emitted('requestRange')).toBeUndefined();

        dispatchPointer(wrapper.find('.map-cell').element, 'pointerdown', { pointerId: 7, pointerType: 'pen', button: 0, clientX: 30, clientY: 30 });
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 7, pointerType: 'pen', clientX: 38, clientY: 35 });
        await flushPromises();

        expect(wrapper.find('.map-plane').attributes('style')).not.toBe(before);
        expect(viewport.classes()).toContain('is-dragging');
        expect(captured).toEqual([7]);
        expect(wrapper.emitted('requestRange')).toHaveLength(1);
    });

    it('does not select the originating cell when a drag ends', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const viewport = wrapper.find('.map-viewport');
        const tile = wrapper.find('.map-cell');
        dispatchPointer(tile.element, 'pointerdown', { pointerId: 2, pointerType: 'mouse', button: 0, clientX: 10, clientY: 10 });
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 2, pointerType: 'mouse', clientX: 18, clientY: 10 });
        dispatchPointer(viewport.element, 'pointerup', { pointerId: 2, pointerType: 'mouse', button: 0, clientX: 18, clientY: 10 });
        tile.element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
        await flushPromises();

        expect(wrapper.emitted('select')).toBeUndefined();
        expect(viewport.classes()).not.toContain('is-dragging');
    });

    it('does not start map panning from toolbar controls', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const toolbarButton = wrapper.find('.map-toolbar button');
        const before = wrapper.find('.map-plane').attributes('style');
        dispatchPointer(toolbarButton.element, 'pointerdown', { pointerId: 3, pointerType: 'mouse', button: 0, clientX: 5, clientY: 5 });
        dispatchPointer(toolbarButton.element, 'pointermove', { pointerId: 3, pointerType: 'mouse', clientX: 30, clientY: 30 });
        dispatchPointer(toolbarButton.element, 'pointerup', { pointerId: 3, pointerType: 'mouse', button: 0, clientX: 30, clientY: 30 });
        await flushPromises();

        expect(wrapper.find('.map-plane').attributes('style')).toBe(before);
        expect(wrapper.find('.map-viewport').classes()).not.toContain('is-dragging');
        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('fits distant cells into the viewport and requests every chunk only for the whole-world action', async () => {
        const nearCapital = mapCell({ x: 5, y: 5, aria_label: 'x 5 y 5 首都' });
        const distantCapital = mapCell({ x: 54, y: 54, owner_nation_id: 2, aria_label: 'x 54 y 54 首都' });
        const wrapper = mount(HexMap, { props: {
            cells: [nearCapital, distantCapital], selected: nearCapital, capital: { x: 5, y: 5 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        expect(wrapper.findAll('.map-cell')).toHaveLength(1);
        await wrapper.find('button[aria-label="世界全体を表示"]').trigger('click');

        expect(wrapper.findAll('.map-cell')).toHaveLength(2);
        expect(wrapper.findAll('.map-cell').map((tile) => tile.attributes('aria-label'))).toEqual([
            'x 5 y 5 首都',
            'x 54 y 54 首都',
        ]);
        expect(wrapper.emitted('requestAll')).toHaveLength(1);
    });
});
