import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import AdminTurnControl from './AdminTurnControl.vue';

afterEach(() => { vi.unstubAllGlobals(); });

it('executes only the turn that was confirmed and restarts confirmation when a reload shows another turn', async () => {
    let currentTurn = 10;
    const attempted: number[] = [];
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        if (String(input).endsWith('/turn-attempt')) {
            const target = (JSON.parse(String(init?.body)) as { target_turn: number }).target_turn;
            attempted.push(target);
            currentTurn = target;
            return new Response(JSON.stringify({ data: { target_turn: target, status: 'completed' } }), { status: 200 });
        }
        return new Response(JSON.stringify({ data: {
            current_turn: currentTurn, next_scheduled_turn_at: '2026-10-10T00:00:00+09:00',
            status: 'delayed', can_attempt: true, calendar_initialized: true,
        } }), { status: 200 });
    }));
    const wrapper = mount(AdminTurnControl, { props: { worldId: 1 } });
    await flushPromises();
    const button = (label: string) => wrapper.findAll('button').find((candidate) => candidate.text() === label)!;

    await button('Turn進行を試みる').trigger('click');
    expect(wrapper.get('[role="alertdialog"]').text()).toContain('T11への進行を試みます');
    expect(attempted).toEqual([]);

    // 確認中に別の処理が1ターン進めた。再読込したら、確認はやり直しになる。
    currentTurn = 11;
    await button('状態を再読込').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alertdialog"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('内容を確かめ直してください');
    expect(attempted).toEqual([]);

    await button('Turn進行を試みる').trigger('click');
    expect(wrapper.get('[role="alertdialog"]').text()).toContain('T12への進行を試みます');
    await button('この内容で実行する').trigger('click');
    await flushPromises();
    expect(attempted).toEqual([12]);
});
