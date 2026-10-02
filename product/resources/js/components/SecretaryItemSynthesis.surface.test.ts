import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, api } from '../api/client';
import SecretaryItemSynthesis from './SecretaryItemSynthesis.vue';

vi.mock('../api/client', async (original) => ({
    ...await original<typeof import('../api/client')>(), api: vi.fn(),
}));
const mockApi = vi.mocked(api);
const recipe = {
    key: 'succubus_emblem', cost_money: 0,
    ingredients: [
        { key: 'love_emblem', name: '愛の紋章', level: 1, candidate_ids: [11, 21] },
        { key: 'twin_star_emblem', name: '双子星の紋章', level: 1, candidate_ids: [12] },
        { key: 'crescent_emblem', name: '三日月の紋章', level: 1, candidate_ids: [13] },
    ],
    result: { name: '夢魔の紋章', level: 1, rarity_label: 'レリック', effect_text: '人口増加量が100%増える。' },
};

beforeEach(() => {
    vi.clearAllMocks();
    sessionStorage.clear();
    vi.spyOn(window, 'confirm').mockReturnValue(true);
});

describe('SecretaryItemSynthesis', () => {
    it('keeps the exact material IDs and UUID after an ambiguous result and remount, and blocks double submission', async () => {
        let rejectMutation!: (error: Error) => void;
        mockApi.mockResolvedValueOnce({ recipes: [recipe] }).mockImplementationOnce(() => new Promise((_, reject) => {
            rejectMutation = reject;
        }));
        const wrapper = mount(SecretaryItemSynthesis, { props: { secretaryId: 7, busy: false } });
        await flushPromises();
        await wrapper.find('select').setValue(21);
        await wrapper.find('button').trigger('click');
        await wrapper.find('button').trigger('click');
        expect(mockApi).toHaveBeenCalledTimes(2);
        const first = JSON.parse(mockApi.mock.calls[1]![1]!.body as string);
        expect(first.ingredient_ids).toEqual([12, 13, 21]);
        rejectMutation(new Error('Connection interrupted'));
        await flushPromises();
        wrapper.unmount();

        mockApi.mockResolvedValueOnce({ recipes: [] })
            .mockRejectedValueOnce(new ApiError(409, '処理中', {}, 'secretary_item_synthesis_busy'))
            .mockResolvedValueOnce({ item: { name: '夢魔の紋章', level: 1 } })
            .mockResolvedValueOnce({ recipes: [] });
        const retry = mount(SecretaryItemSynthesis, { props: { secretaryId: 7, busy: false } });
        await flushPromises();
        expect(retry.text()).toContain('送信済みの結果を確認');
        await retry.find('button').trigger('click');
        await flushPromises();
        expect(JSON.parse(mockApi.mock.calls[3]![1]!.body as string)).toEqual(first);
        expect(sessionStorage.getItem('hakoniwa:item-synthesis:7')).not.toBeNull();
        await retry.find('button').trigger('click');
        await flushPromises();
        expect(JSON.parse(mockApi.mock.calls[4]![1]!.body as string)).toEqual(first);
        expect(retry.emitted('synthesized')).toHaveLength(1);
        expect(sessionStorage.getItem('hakoniwa:item-synthesis:7')).toBeNull();
        retry.unmount();
    });

    it('does not disclose an absent recipe and disables material consumption when an ingredient is equipped or escrowed', async () => {
        mockApi.mockResolvedValueOnce({ recipes: [] });
        const hidden = mount(SecretaryItemSynthesis, { props: { secretaryId: 8, busy: false } });
        await flushPromises();
        expect(hidden.find('section').exists()).toBe(false);
        hidden.unmount();
        mockApi.mockResolvedValueOnce({ recipes: [{ ...recipe, ingredients: recipe.ingredients.map((item, i) => i === 1 ? { ...item, candidate_ids: [] } : item) }] });
        const unavailable = mount(SecretaryItemSynthesis, { props: { secretaryId: 8, busy: false } });
        await flushPromises();
        expect(unavailable.find('button').attributes('disabled')).toBeDefined();
        expect(unavailable.text()).toContain('装備・出品を解除してください');
        unavailable.unmount();
    });
});
