import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import SecretaryMenu from './SecretaryMenu.vue';

describe('secretary menu access', () => {
    it('keeps synthesis inert and skips it during keyboard navigation', async () => {
        const wrapper = mount(SecretaryMenu, { props: { owner: true, modelValue: 'warehouse' }, attachTo: document.body });
        const synthesis = wrapper.get<HTMLButtonElement>('[aria-label="合成（未実装）"]');
        expect(synthesis.element.disabled).toBe(true);
        synthesis.element.click();
        expect(wrapper.emitted('update:modelValue')).toBeUndefined();
        await wrapper.get('#secretary-tab-warehouse').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['achievements']);
        await wrapper.setProps({ modelValue: 'achievements' });
        await wrapper.get('#secretary-tab-achievements').trigger('keydown', { key: 'End' });
        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['settings']);
        await nextTick();
        expect(document.activeElement?.id).toBe('secretary-tab-settings');
        wrapper.unmount();
    });

    it('only navigates between the public main and equipped views', async () => {
        const wrapper = mount(SecretaryMenu, { props: { owner: false, modelValue: 'main' } });
        expect(wrapper.find('#secretary-tab-settings').exists()).toBe(false);
        expect(wrapper.find('button:disabled').exists()).toBe(false);
        await wrapper.get('#secretary-tab-main').trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['equipment']);
    });
});
