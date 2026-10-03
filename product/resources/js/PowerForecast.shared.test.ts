import { flushPromises, mount } from '@vue/test-utils';
import { expect, it, vi } from 'vitest';
import App from './App.vue';
import HexMap from './components/HexMap.vue';
import { emptyChunk, installAppTestLifecycle, ownerNationFixture, publicResponse, response, surfaceCellFixture } from './AppTestHarness';

installAppTestLifecycle();

it('preserves forecast uncertainty and MW units while selecting an unprovided pizzeria image', async () => {
    const nation = structuredClone(ownerNationFixture);
    nation.resource_forecast.rows.push({
        key: 'power', name: '電力', unit_label: 'MW', holding: 1100,
        production: 245, consumption: 29, delta: -10,
        production_range: { minimum: 245, maximum: 335 },
        consumption_range: { minimum: 29, maximum: 30 },
        delta_range: { minimum: -10, maximum: 20 },
    });
    nation.resource_forecast.power_summary = {
        wind_expected_mw: 90, thermal_generated_mw: 200,
        thermal_oil_display: 4, thermal_minerals_display: 0,
        capacity_mw: 1200, stored_after_mw: { minimum: 1090, maximum: 1120 },
        discarded_mw: { minimum: 0, maximum: 0 },
        pizzeria_revenue: { minimum: 15, maximum: 16 }, pizzeria_maintenance: 1, pizzeria_unfunded: 0,
    };
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
        const path = String(input);
        if (path === '/api/v1/application-version') return response({ version: '3.0.0' });
        if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
        if (path === '/api/v1/me/nation') return response(nation);
        if (path === '/api/v1/me/underground/surface-map') return response(null);
        if (path.includes('/secretary')) return response(null);
        if (path.endsWith('/map-spaces')) return response([{
            id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59',
            bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 },
        }]);
        if (path.includes('/chunks/')) return response(emptyChunk);
        if (path.includes('/command-definitions')) return response({ commands: [], quantity_contract: { type: 'integer', minimum: 1, maximum: 99, default: 1, quick_presets: [1, 5, 10, 25, 50, 99] } });
        if (path.includes('/command-queue')) return response({ version: 1, limit: 30, explicit_count: 0, items: [], plan: [] });
        if (path.includes('/events')) return response({ groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 }, turns_per_page: 12, has_newer_page: false, has_older_page: false });
        return publicResponse(path) ?? response([], 200);
    }));
    const wrapper = mount(App);
    await flushPromises();
    await wrapper.findAll('.site-header nav button').find(button => button.text() === '自島へ')!.trigger('click');
    await flushPromises();
    const powerRow = wrapper.findAll('.resource-forecast tbody tr').find(row => row.text().startsWith('電力'))!;
    expect(powerRow.text()).toContain('245〜335MW');
    expect(powerRow.text()).toContain('29〜30MW');
    expect(powerRow.text()).toContain('−10〜+20MW');
    expect(powerRow.text()).toContain('1,100MW');
    wrapper.getComponent(HexMap).vm.$emit('select', {
        ...surfaceCellFixture, facility: 'pizzeria', facility_name: 'ピザ屋', display_name: 'ピザ屋',
        asset: { ...surfaceCellFixture.asset, key: 'tile.pizzeria', available: false },
    });
    await flushPromises();
    expect(wrapper.get('.selected-cell h3').text()).toContain('ピザ屋');
    expect(wrapper.get('.selected-cell').text()).toContain('施設画像は未配備');
    wrapper.unmount();
});
