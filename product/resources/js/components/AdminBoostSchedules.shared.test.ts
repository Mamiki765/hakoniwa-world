import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import AdminBoostSchedules from './AdminBoostSchedules.vue';
import AdminTurnControl from './AdminTurnControl.vue';

afterEach(() => { vi.unstubAllGlobals(); });
const response = (data: unknown) => new Response(JSON.stringify({ data }), { status: 200 });
const overview = { current_turn: 11, uncompensated_turns: 10, occurrences: [] };
const timePreview = {
    token: 'fixed-time-preview', kind: 'turn_acceleration', title: '停止分のターン補填', repeat: 'none', repeat_count: 1,
    uncompensated_turns: 10, duration_hours: 20,
    windows: [{ starts_at: '2026-11-01T00:30:00+00:00', ends_at: '2026-11-01T20:30:00+00:00', additional_slots: 10 }],
};
const button = (wrapper: VueWrapper, name: string) => wrapper.findAll('button').find((candidate) => candidate.text() === name)!;
const field = (wrapper: VueWrapper, name: string) => wrapper.findAll('label').find((candidate) => candidate.text().startsWith(name))!.get('input, select');

it('opens compensation from the turn controls, sends JST explicitly, and reserves only after the returned preview is confirmed', async () => {
    const requests: Array<{ path: string; body: unknown }> = [];
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const path = String(input);
        if (init?.method === 'POST') requests.push({ path, body: JSON.parse(String(init.body)) });
        if (path.endsWith('/turn-status')) return response({ current_turn: 11, status: 'delayed', can_attempt: true, calendar_initialized: true, next_scheduled_turn_at: '2026-11-01T01:00:00Z' });
        if (path.endsWith('/preview')) return response(timePreview);
        if (path.endsWith('/reserve')) return response({ series_id: 87, duplicate: false });
        return response(overview);
    }));
    const wrapper = mount(AdminTurnControl, { props: { worldId: 7 } });
    await flushPromises();
    await button(wrapper, '遅延ターン分').trigger('click');
    await flushPromises();
    expect(field(wrapper, 'ブーストの種類').element).toHaveProperty('value', 'turn_acceleration');
    await field(wrapper, '開始日時').setValue('2026-11-01T09:30');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(requests).toEqual([{ path: '/api/v1/admin/worlds/7/boosts/preview', body: {
        kind: 'turn_acceleration', title: timePreview.title, starts_at: '2026-11-01T09:30:00+09:00',
    } }]);
    const confirmation = wrapper.get('[aria-label="ブースト予約の確認"]');
    expect(confirmation.text()).toContain('20時間');
    expect(confirmation.text()).toContain('追加枠 合計 10回');
    expect(confirmation.get('tbody').text()).toContain('2026/11/2 5:30:00');
    await button(wrapper, 'この内容で予約する').trigger('click');
    await flushPromises();
    expect(requests[1]).toEqual({ path: '/api/v1/admin/worlds/7/boosts/reserve', body: { token: timePreview.token } });
    expect(wrapper.text()).toContain('系列 #87');
    expect(wrapper.find('[aria-label="ブースト予約の確認"]').exists()).toBe(false);
    wrapper.unmount();
});

it('sends finite weekly dates and keeps the same token through response loss, without making a refresh failure a new reservation', async () => {
    const requests: Array<{ path: string; body: unknown }> = [];
    let attempts = 0;
    const preview = { ...timePreview, token: 'fixed-festival-preview', kind: 'hunting_ground', title: '週末のお祭り', repeat: 'weekly', repeat_count: 2, duration_hours: 4,
        windows: [
            { starts_at: '2026-11-07T01:00:00Z', ends_at: '2026-11-07T05:00:00Z', additional_slots: 0 },
            { starts_at: '2026-11-14T01:00:00Z', ends_at: '2026-11-14T05:00:00Z', additional_slots: 0 },
        ] };
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const path = String(input);
        if (init?.method === 'POST') requests.push({ path, body: JSON.parse(String(init.body)) });
        if (path.endsWith('/preview')) return response(preview);
        if (path.endsWith('/reserve')) {
            attempts++;
            if (attempts === 1) throw new TypeError('応答を受信できませんでした');
            return response({ series_id: 88, duplicate: true });
        }
        if (attempts === 2) throw new TypeError('一覧だけ取得失敗');
        return response(overview);
    }));
    const wrapper = mount(AdminBoostSchedules, { props: { worldId: 7, initialKind: 'hunting_ground' } });
    await flushPromises();
    await field(wrapper, '公開タイトル').setValue(preview.title);
    await field(wrapper, '開始日時').setValue('2026-11-07T10:00');
    await field(wrapper, '終了日時').setValue('2026-11-07T14:00');
    await field(wrapper, '繰り返し').setValue('weekly');
    await field(wrapper, '開催回数').setValue(2);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(requests[0]?.body).toEqual({ kind: 'hunting_ground', title: preview.title,
        starts_at: '2026-11-07T10:00:00+09:00', ends_at: '2026-11-07T14:00:00+09:00', repeat: 'weekly', repeat_count: 2 });
    expect(wrapper.findAll('tbody tr')).toHaveLength(preview.windows.length);
    await button(wrapper, 'この内容で予約する').trigger('click');
    await flushPromises();
    expect(button(wrapper, '予約画面を閉じる').attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('button').some((candidate) => candidate.text() === '入力に戻る')).toBe(false);
    await button(wrapper, '結果を確認する').trigger('click');
    await flushPromises();
    expect(requests.filter((request) => request.path.endsWith('/reserve')).map((request) => request.body)).toEqual([
        { token: preview.token }, { token: preview.token },
    ]);
    expect(requests.filter((request) => request.path.endsWith('/preview'))).toHaveLength(1);
    expect(wrapper.text()).toContain('系列 #88');
    expect(wrapper.get('[role="alert"]').text()).toContain('予約一覧を取得できませんでした');
    expect(wrapper.find('[aria-label="ブースト予約の確認"]').exists()).toBe(false);
    wrapper.unmount();
});

it('requires a fresh preview after the server rejects stale compensation', async () => {
    let previews = 0;
    let reserves = 0;
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
        const path = String(input);
        if (path.endsWith('/preview')) { previews++; return response(timePreview); }
        if (path.endsWith('/reserve')) { reserves++; return new Response(JSON.stringify({ message: '未補填遅延が変わりました。再確認してください。' }), { status: 409 }); }
        return response(overview);
    }));
    const wrapper = mount(AdminBoostSchedules, { props: { worldId: 7, initialKind: 'turn_acceleration' } });
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await button(wrapper, 'この内容で予約する').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('未補填遅延が変わりました');
    expect(wrapper.find('[aria-label="ブースト予約の確認"]').exists()).toBe(false);
    expect(previews).toBe(1);
    expect(reserves).toBe(1);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(previews).toBe(2);
    expect(reserves).toBe(1);
    wrapper.unmount();
});

it('confirms occurrence identity and cancellation scope, then retries only that scope after response loss', async () => {
    const row = { id: 321, series_id: 88, occurrence_number: 2, kind: 'hunting_ground', title: '週末のお祭り', starts_at: '2026-11-14T01:00:00Z', ends_at: '2026-11-14T05:00:00Z', status: 'active' };
    const cancellations: unknown[] = [];
    let confirmed = false;
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        if (String(input).endsWith('/321/cancel')) {
            cancellations.push(JSON.parse(String(init?.body)));
            if (cancellations.length === 1) throw new TypeError('取消応答を受信できませんでした');
            confirmed = true;
            return response({ cancelled: true });
        }
        return response({ ...overview, occurrences: [{ ...row, status: confirmed ? 'cancelled' : row.status }] });
    }));
    const wrapper = mount(AdminBoostSchedules, { props: { worldId: 7, initialKind: 'hunting_ground' } });
    await flushPromises();
    await wrapper.get('[aria-label="開催回 #321"] button').trigger('click');
    expect(cancellations).toEqual([]);
    expect(wrapper.get('[aria-label="ブースト取消の確認"]').text()).toContain('系列 #88 の 2回目');
    await field(wrapper, '取消範囲').setValue('true');
    await button(wrapper, 'この範囲を取り消す').trigger('click');
    await flushPromises();
    expect(field(wrapper, '取消範囲').attributes('disabled')).toBeDefined();
    expect(wrapper.findAll('button').some((candidate) => candidate.text() === '取消をやめる')).toBe(false);
    await button(wrapper, '結果を確認する').trigger('click');
    await flushPromises();
    expect(cancellations).toEqual([{ future_series: true }, { future_series: true }]);
    expect(wrapper.get('[aria-label="開催回 #321"]').text()).toContain('取消済み');
    expect(wrapper.get('[aria-label="開催回 #321"]').find('button').exists()).toBe(false);
    wrapper.unmount();
});
