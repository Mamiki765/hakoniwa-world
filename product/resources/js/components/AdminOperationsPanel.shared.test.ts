import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import AdminOperationsPanel from './AdminOperationsPanel.vue';

afterEach(() => { vi.unstubAllGlobals(); });

it('requires an explicit apply, retries its fixed preview after response loss, and separates refresh failure from distribution', async () => {
    let attempts = 0;
    const overview = {
        world: { id: 1, name: 'World', current_turn: 1 }, user_count: 2,
        assets: { paradox: { label: '輝石', unit: 'Pd' } }, nations: [], recent_secretaries: [],
    };
    const preview = { token: 'server-issued-preview', reason: '配布理由', assets: { paradox: 2 }, recipients: [
        { user_id: 1, name: '管理人', nation_name: null, nation_number: null },
        { user_id: 2, name: '島なし利用者', nation_name: null, nation_number: null },
    ] };
    const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
        const path = String(input);
        let data: unknown;
        if (path.endsWith('/operations/distribution/apply')) {
            attempts++;
            if (attempts === 1) throw new TypeError('配布応答を受信できませんでした');
            data = { recipient_count: 2, duplicate: true };
        } else if (path.endsWith('/operations/distribution/preview')) {
            data = preview;
        } else if (path.endsWith('/operations')) {
            if (attempts === 2) throw new TypeError('表示更新だけ失敗');
            data = overview;
        } else {
            data = [];
        }
        return new Response(JSON.stringify({ data }), { status: 200 });
    });
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(AdminOperationsPanel, { props: { worldId: 1 } });
    await flushPromises();
    await wrapper.findAll('.admin-menu button').find((button) => button.text() === '配布')!.trigger('click');
    await wrapper.get('textarea').setValue('配布理由');
    await wrapper.get('input[type=number]').setValue(2);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(attempts).toBe(0);
    expect(wrapper.get('.admin-confirm').text()).toContain('島なし利用者');
    expect(wrapper.get('.admin-confirm').text()).toContain('365日');
    await wrapper.get('.admin-confirm .primary').trigger('click');
    await flushPromises();
    expect(wrapper.get('.admin-confirm .primary').text()).toBe('結果を確認する');
    expect(wrapper.findAll('.admin-menu button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    await wrapper.get('.admin-confirm .primary').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('2人へ配布しました');
    expect(wrapper.text()).toContain('管理情報の一部を取得できませんでした');
    expect(wrapper.find('.admin-confirm').exists()).toBe(false);
    const calls = fetchMock.mock.calls as unknown as Array<[string, RequestInit]>;
    const apply = calls.filter(([path]) => path.endsWith('/distribution/apply'));
    expect(apply.map(([, init]) => JSON.parse(String(init.body)))).toEqual([
        { token: 'server-issued-preview' }, { token: 'server-issued-preview' },
    ]);
    expect(calls.filter(([path]) => path.endsWith('/distribution/preview'))).toHaveLength(1);
    wrapper.unmount();
});
