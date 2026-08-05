import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { MapCell } from '../types';
import HexMap from './HexMap.vue';

const worldBounds = { min_x: 0, max_x: 59, min_y: 0, max_y: 59 };

afterEach(() => vi.unstubAllGlobals());

function mapCell(overrides: Partial<MapCell> = {}): MapCell {
    return {
        x: 0, y: 0, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
        display_name: '平地', owner_nation_id: 18, owner_nation_number: 1, owner_name: '地図国', details: [],
        monster: null,
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

function trackPointerCapture(element: Element): { captured: number[]; released: number[] } {
    const captured: number[] = [];
    const released: number[] = [];
    const active = new Set<number>();
    const captureOwner = element as HTMLElement;
    captureOwner.setPointerCapture = (pointerId: number): void => {
        active.add(pointerId);
        captured.push(pointerId);
    };
    captureOwner.hasPointerCapture = (pointerId: number): boolean => active.has(pointerId);
    captureOwner.releasePointerCapture = (pointerId: number): void => {
        active.delete(pointerId);
        released.push(pointerId);
    };

    return { captured, released };
}

describe('staggered square-image map', () => {
    it('renders completed and optional overlay images, fallback, selection and six-way keyboard input', async () => {
        const selected = mapCell({
            details: [{ key: 'population', label: '人口', value: 1000, unit: '人', formatted: '1,000人', visibility: 'public' }],
        });
        const fallback = mapCell({
            x: 1, y: 0, terrain: 'forest', terrain_name: '森', display_name: '森', owner_nation_id: null, owner_nation_number: null, owner_name: null,
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

    it('renders a non-interactive monster overlay with current HP, current host label and safe fallback', async () => {
        const monsterCell = mapCell({
            owner_nation_id: 18,
            owner_nation_number: 3,
            monster: {
                id: 9, key: 'sanjira', name: 'サンジラ',
                asset_key: 'hakoniwa_original.monster.hardened', asset_url: null,
                asset: { key: 'hakoniwa_original.monster.hardened', url: null, available: false, fallback_label: 'サンジラ', fallback_style: 'hakoniwa-original-monster-hardened' },
                current_hp: 1, spawned_max_hp: 2, hp_range: { min: 1, max: 2 },
                skill_description: '奇数ターンは硬化する。', hardened_now: true, public_state: 'alive',
                coordinate: { x: 0, y: 0 }, host_nation: { nation_number: 3, name: '第三国' }, host_label: 'N3',
            },
            aria_label: 'x 0 y 0 平地 怪獣 サンジラ HP 1 N3 硬化中',
        });
        const wrapper = mount(HexMap, { props: {
            cells: [monsterCell], selected: monsterCell, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const overlay = wrapper.find('.monster-overlay');
        expect(overlay.exists()).toBe(true);
        expect(overlay.text()).toContain('HP 1');
        expect(overlay.text()).toContain('N3');
        expect(overlay.text()).toContain('硬');
        expect(overlay.find('.monster-image').exists()).toBe(false);
        expect(overlay.find('.monster-fallback').text()).toBe('サ');
        expect(wrapper.find('.map-cell').attributes('aria-label')).toContain('サンジラ HP 1 N3');
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

        const viewport = wrapper.find('.map-viewport');
        const tile = wrapper.find('.map-cell');
        const capture = trackPointerCapture(viewport.element);
        dispatchPointer(tile.element, 'pointerdown', { pointerId: 1, pointerType: 'touch', button: 0, clientX: 20, clientY: 20 });
        expect(capture.captured).toEqual([1]);
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 1, pointerType: 'touch', clientX: 23, clientY: 22 });
        dispatchPointer(viewport.element, 'pointerup', { pointerId: 1, pointerType: 'touch', button: 0, clientX: 23, clientY: 22 });
        tile.element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
        await flushPromises();

        expect(wrapper.emitted('select')).toEqual([[cell]]);
        expect(capture.released).toEqual([1]);
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
        const tile = wrapper.find('.map-cell');
        const capture = trackPointerCapture(viewport.element);
        const before = wrapper.find('.map-plane').attributes('style');
        const initialRequestCount = wrapper.emitted('requestRange')?.length ?? 0;

        dispatchPointer(tile.element, 'pointerdown', { pointerId: 7, pointerType: 'pen', button: 0, clientX: 30, clientY: 30 });
        expect(capture.captured).toEqual([7]);
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 7, pointerType: 'pen', clientX: 38, clientY: 35 });
        await flushPromises();

        expect(wrapper.find('.map-plane').attributes('style')).not.toBe(before);
        expect(viewport.classes()).toContain('is-dragging');
        expect(wrapper.emitted('requestRange')).toHaveLength(initialRequestCount + 1);

        dispatchPointer(viewport.element, 'pointerup', { pointerId: 7, pointerType: 'pen', clientX: 38, clientY: 35 });
        expect(capture.released).toEqual([7]);
    });

    it('keeps capture on the persistent viewport after the origin cell is culled', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const viewport = wrapper.find('.map-viewport');
        const tile = wrapper.find('.map-cell');
        const capture = trackPointerCapture(viewport.element);
        const beforeFirstPan = wrapper.find('.map-plane').attributes('style');

        dispatchPointer(tile.element, 'pointerdown', { pointerId: 8, pointerType: 'mouse', button: 0, clientX: 2, clientY: 20 });
        expect(capture.captured).toEqual([8]);
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 8, pointerType: 'mouse', clientX: -1000, clientY: 20 });
        await flushPromises();

        expect(wrapper.find('.map-plane').attributes('style')).not.toBe(beforeFirstPan);
        expect(wrapper.find('.map-cell').exists()).toBe(false);
        dispatchPointer(viewport.element, 'pointerup', { pointerId: 8, pointerType: 'mouse', clientX: -1000, clientY: 20 });
        await flushPromises();
        expect(capture.released).toEqual([8]);
        expect(viewport.classes()).not.toContain('is-dragging');
        expect(wrapper.emitted('select')).toBeUndefined();

        const beforeSecondPan = wrapper.find('.map-plane').attributes('style');
        dispatchPointer(viewport.element, 'pointerdown', { pointerId: 9, pointerType: 'mouse', button: 0, clientX: 20, clientY: 20 });
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 9, pointerType: 'mouse', clientX: 28, clientY: 20 });
        dispatchPointer(viewport.element, 'pointerup', { pointerId: 9, pointerType: 'mouse', clientX: 28, clientY: 20 });
        await flushPromises();

        expect(capture.captured).toEqual([8, 9]);
        expect(capture.released).toEqual([8, 9]);
        expect(wrapper.find('.map-plane').attributes('style')).not.toBe(beforeSecondPan);
        expect(wrapper.emitted('select')).toBeUndefined();
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

    it('selects the next normally clicked cell after a threshold drag is cancelled', async () => {
        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: null, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const viewport = wrapper.find('.map-viewport');
        const tile = wrapper.find('.map-cell');
        const capture = trackPointerCapture(viewport.element);
        dispatchPointer(tile.element, 'pointerdown', { pointerId: 4, pointerType: 'touch', button: 0, clientX: 10, clientY: 10 });
        dispatchPointer(viewport.element, 'pointermove', { pointerId: 4, pointerType: 'touch', clientX: 18, clientY: 10 });
        dispatchPointer(viewport.element, 'pointercancel', { pointerId: 4, pointerType: 'touch', clientX: 18, clientY: 10 });
        tile.element.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, detail: 1 }));
        await flushPromises();

        expect(wrapper.emitted('select')).toEqual([[cell]]);
        expect(capture.released).toEqual([4]);
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

    it('requests newly visible chunks after mounting and resizing in normal mode', async () => {
        let resizeCallback: ResizeObserverCallback | null = null;
        class TestResizeObserver {
            constructor(callback: ResizeObserverCallback) {
                resizeCallback = callback;
            }

            observe(): void {}
            disconnect(): void {}
        }
        vi.stubGlobal('ResizeObserver', TestResizeObserver);

        const cell = mapCell();
        const wrapper = mount(HexMap, { props: {
            cells: [cell], selected: cell, capital: { x: 0, y: 0 }, bounds: worldBounds,
            loading: false, error: null, emptyChunks: [],
        } });
        await flushPromises();

        const initialRanges = wrapper.emitted('requestRange');
        expect(initialRanges).toHaveLength(1);
        const initialRange = initialRanges![0]![0] as { minX: number; maxX: number; minY: number; maxY: number };
        const viewport = wrapper.find('.map-viewport').element;
        Object.defineProperties(viewport, {
            clientWidth: { configurable: true, value: 1400 },
            clientHeight: { configurable: true, value: 800 },
        });

        const callback = resizeCallback as ResizeObserverCallback | null;
        expect(callback).not.toBeNull();
        callback!([], {} as ResizeObserver);
        await flushPromises();

        const resizedRanges = wrapper.emitted('requestRange');
        expect(resizedRanges).toHaveLength(2);
        const resizedRange = resizedRanges![1]![0] as { minX: number; maxX: number; minY: number; maxY: number };
        expect(resizedRange.minX).toBeLessThan(initialRange.minX);
        expect(resizedRange.maxX).toBeGreaterThan(initialRange.maxX);
    });

    it('fits distant cells into the viewport and requests every chunk only for the whole-world action', async () => {
        const nearCapital = mapCell({ x: 5, y: 5, aria_label: 'x 5 y 5 首都' });
        const distantCapital = mapCell({ x: 54, y: 54, owner_nation_id: 2, owner_nation_number: 2, aria_label: 'x 54 y 54 首都' });
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
