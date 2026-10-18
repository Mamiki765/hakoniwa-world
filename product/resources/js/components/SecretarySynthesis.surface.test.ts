import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import SecretarySynthesis from './SecretarySynthesis.vue';

const synthesis = {
    recipes: [
        {
            key: 'succubus_emblem', kind: 'fixed', name: '夢魔の紋章', rarity: 'relic', rarity_label: 'レリック', crafted: true,
            inputs: [{ item_key: 'love_emblem', name: '愛の紋章', owned_count: 1 }, { item_key: 'twin_star_emblem', name: '双子星の紋章', owned_count: 0 }, { item_key: 'crescent_emblem', name: '三日月の紋章', owned_count: 1 }],
            output: { item_key: 'succubus_emblem', name: '夢魔の紋章', effect_text: '自然回復と誘致活動での人口増加が2倍', flavor_text: '人間よ、快楽だけを貪る獣に帰れ。' },
        },
        { key: 'stack:fire_charm', kind: 'stack', name: '防火のお守り', rarity: 'regular', rarity_label: 'レギュラー', level_cap: 9999, crafted: false },
    ],
    available: [{
        recipe_key: 'stack:fire_charm', material_ids: [101, 102, 103], base_id: 101, left_over_count: 2,
        materials: [{ id: 101, name: '防火のお守り', level: 9990, is_equipped: true }, { id: 102, name: '防火のお守り', level: 3, is_equipped: false }, { id: 103, name: '防火のお守り', level: 2, is_equipped: false }],
        preview: { result_name: '防火のお守り', result_level: 9995, consumed_count: 2, warehouse_delta: -2 },
    }],
    warehouse: { used: 48, capacity: 100 },
};

function json(data: unknown, status = 200): Response {
    return new Response(JSON.stringify(status < 400 ? { data } : data), { status, headers: { 'Content-Type': 'application/json' } });
}

afterEach(() => { vi.unstubAllGlobals(); });

describe('secretary synthesis', () => {
    it('lists what can be merged now and links to the recipe book with every recipe visible', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => json(synthesis)));
        const wrapper = mount(SecretarySynthesis, { attachTo: document.body });
        await flushPromises();
        expect(wrapper.text()).toContain('Lv9,995 ×1個・倉庫が2つ空きます');
        expect(wrapper.text()).toContain('残り2個はそのまま');
        expect(wrapper.text()).toContain('装備中の1個に足します');
        await wrapper.findAll('button').find(button => button.text() === 'レシピ ›')!.trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('夢魔の紋章');
        expect(wrapper.text()).toContain('✓ 作成済み');
        expect(wrapper.get('#synthesis-recipe-stack\\:fire_charm').classes()).toContain('highlighted');
        await wrapper.findAll('button').find(button => button.text() === '作ったことがある')!.trigger('click');
        expect(wrapper.find('#synthesis-recipe-stack\\:fire_charm').exists()).toBe(false);
        wrapper.unmount();
    });

    it('reloads instead of substituting materials when the warehouse changed', async () => {
        const posted: unknown[] = [];
        vi.stubGlobal('fetch', vi.fn(async (_url: string, init?: RequestInit) => {
            if (init?.method === 'POST') {
                posted.push(JSON.parse(String(init.body)));
                return json({ message: '素材が変わりました。', code: 'materials_invalid' }, 422);
            }
            return json(synthesis);
        }));
        const wrapper = mount(SecretarySynthesis);
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'まとめる')!.trigger('click');
        expect(wrapper.text()).toContain('（これが残ります）');
        await wrapper.get('[role="dialog"] .button.primary').trigger('click');
        await flushPromises();
        expect(posted[0]).toMatchObject({ recipe_key: 'stack:fire_charm', material_ids: [101, 102, 103], base_id: 101 });
        expect(wrapper.text()).toContain('出し直しました');
        expect(wrapper.emitted('changed')).toBeUndefined();
    });
});
