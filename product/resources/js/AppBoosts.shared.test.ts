import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import { installAppTestLifecycle, publicResponse, response } from './AppTestHarness';

installAppTestLifecycle();

describe('boost display on the top page', () => {
    it('shows both boosts and does not promise hourly turns during compensation', async () => {
        const now = Date.now();
        const boosts = [
            {
                id: 1, kind: 'turn_acceleration', status: 'active',
                starts_at: new Date(now - 3_600_000).toISOString(), ends_at: new Date(now + 3_600_000).toISOString(),
                title: '停止のお詫び', description: '通常の日本時間偶数時00分に加え、期間内の奇数時00分に未補填分の追加ターンを実行します',
                turn_interval_minutes: 60, multiplier: null, effects: { compensation_only: true },
            },
            {
                id: 2, kind: 'hunting_ground', status: 'scheduled',
                starts_at: new Date(now + 86_400_000).toISOString(), ends_at: new Date(now + 2 * 86_400_000).toISOString(),
                title: '狩場のお祭り', description: '狩場のレア出現率・通常敵の鍵出現率・獲得XPが2倍（Gや鍵の個数は変わりません）',
                turn_interval_minutes: null, multiplier: 2, effects: { scope: 'hunting_ground' },
            },
        ];
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            if (path === '/api/v1/public/worlds/1/boosts') return new Response(JSON.stringify({
                data: boosts, meta: { server_now: new Date(now).toISOString(), timezone: 'Asia/Tokyo' },
            }), { status: 200, headers: { 'Content-Type': 'application/json' } });
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response(null, 401);

            return response(null, 404);
        }));
        const wrapper = mount(App);
        await flushPromises();

        const banners = wrapper.findAll('.tp-boost .boost-banner');
        expect(banners.map((banner) => banner.attributes('data-phase'))).toEqual(['active', 'upcoming']);
        expect(banners[0]!.text()).toContain('停止のお詫び');
        expect(banners[1]!.text()).toContain('Gや鍵の個数は変わりません');
        const turnHeader = wrapper.get('.turn-status-card header').text();
        expect(turnHeader).toContain('停止補填中：奇数時にも追加（未補填分のみ）');
        expect(turnHeader).not.toContain('60分');
        wrapper.unmount();
    });
});
