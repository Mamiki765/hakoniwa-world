import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { useNavOverflow } from './navOverflow';

afterEach(() => {
    vi.unstubAllGlobals();
});

// jsdom は幅を計算しないので、帯の幅と各項目の幅を与えて測らせる。
function mountNav(available: number, widths: Record<string, number>) {
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) => {
        callback(0);
        return 1;
    });
    vi.stubGlobal('cancelAnimationFrame', () => undefined);
    let api: ReturnType<typeof useNavOverflow> | null = null;
    const Nav = defineComponent(() => {
        const nav = ref<HTMLElement | null>(null);
        api = useNavOverflow(nav, ['manual', 'options', 'trading']);
        return () => h('nav', { ref: nav }, Object.keys(widths).map((key) => h('button', {
            'data-nav-key': key,
            style: { display: api!.overflowed.value.includes(key) ? 'none' : undefined },
        }, key)));
    });
    const wrapper = mount(Nav, { attachTo: document.body });
    const nav = wrapper.element as HTMLElement;
    Object.defineProperty(nav, 'clientWidth', { configurable: true, get: () => available });
    Object.defineProperty(nav, 'scrollWidth', {
        configurable: true,
        get: () => [...nav.querySelectorAll<HTMLElement>('[data-nav-key]')]
            .filter((item) => item.style.display !== 'none')
            .reduce((sum, item) => sum + widths[item.dataset.navKey!]!, 0),
    });
    for (const item of nav.querySelectorAll<HTMLElement>('[data-nav-key]')) {
        Object.defineProperty(item, 'offsetWidth', { configurable: true, get: () => widths[item.dataset.navKey!]! });
    }
    return { wrapper, api: () => api! };
}

it('moves only as many items as needed, from the right, and brings them back when there is room', async () => {
    const widths = { top: 40, island: 50, trading: 50, options: 60, manual: 60 };
    const { wrapper, api } = mountNav(200, widths);
    api().schedule();
    await flushPromises();
    // 260 → 200: 右端のマニュアルだけで足りる。
    expect(api().overflowed.value).toEqual(['manual']);

    const nav = wrapper.element as HTMLElement;
    Object.defineProperty(nav, 'clientWidth', { configurable: true, get: () => 120 });
    api().schedule();
    await flushPromises();
    // 回してよい物は全部回し、残りは帯に残す（TOP・自島へは回さない）。
    expect(api().overflowed.value).toEqual(['manual', 'options', 'trading']);

    Object.defineProperty(nav, 'clientWidth', { configurable: true, get: () => 400 });
    api().schedule();
    await flushPromises();
    expect(api().overflowed.value).toEqual([]);
    wrapper.unmount();
});
