import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import SecretaryEquipmentModal from './SecretaryEquipmentModal.vue';
import type { SecretaryEquipmentOptions } from '../types';

const options: SecretaryEquipmentOptions = {
    slot: 2,
    equipment_version: 4,
    effect_context: {
        source: 'owned_world', world_id: 1, ruleset_version_id: 10,
        ruleset_key: 'hakoniwa-2s-plus-v10', ruleset_version: 10,
    },
    current_item: {
        id: 21, key: 'old_bow', name: '古びた弓', level: 1,
        category: 'bow', category_label: '弓', equipped_slot: 2, effect_text: null,
    },
    items: [
        {
            id: 21, key: 'old_bow', name: '古びた弓', level: 1,
            category: 'bow', category_label: '弓', equipped_slot: 2, effect_text: null,
        },
        {
            id: 31, key: 'test_ring', name: '指輪', level: 3,
            category: 'accessory', category_label: 'アクセサリー', equipped_slot: null,
            effect_text: '装備効果の説明',
        },
    ],
    category_limits: [
        { category: 'bow', label: '弓', maximum_equipped: 1 },
        { category: 'clothing', label: '衣服', maximum_equipped: 1 },
    ],
};

const props = {
    targetSlot: 2,
    options,
    loading: false,
    submitting: false,
    error: '',
    requireFreshChoice: false,
};

afterEach(() => document.body.replaceChildren());

describe('SecretaryEquipmentModal', () => {
    it('renders the accessible native-scroll selection list in authoritative order without flavor text', async () => {
        const trigger = document.createElement('button');
        document.body.append(trigger);
        trigger.focus();
        const wrapper = mount(SecretaryEquipmentModal, { attachTo: document.body, props });
        await wrapper.vm.$nextTick();

        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.attributes('aria-modal')).toBe('true');
        expect(dialog.attributes('aria-labelledby')).toBe('equipment-modal-title-2');
        expect(document.activeElement).toBe(wrapper.get('.equipment-modal-close').element);
        expect(wrapper.get('.equipment-options-scroll').attributes('data-native-scroll')).toBe('true');
        const rows = wrapper.findAll('.equipment-option-row');
        expect(rows.map((row) => row.text())).toEqual(['外す', '古びた弓Lv1', '指輪Lv3装備効果の説明']);
        expect(wrapper.text()).not.toContain('貴金属');
        expect(wrapper.findAll<HTMLInputElement>('input[type="radio"]')[1]!.element.checked).toBe(true);

        await wrapper.findAll<HTMLInputElement>('input[type="radio"]')[2]!.setValue(true);
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        expect(wrapper.emitted('selectionChange')).toHaveLength(1);
        expect(wrapper.emitted('submit')).toEqual([[31]]);

        wrapper.unmount();
        expect(document.activeElement).toBe(trigger);
    });

    it('closes through the x button backdrop or Escape without submitting', async () => {
        for (const action of ['button', 'backdrop', 'escape'] as const) {
            const wrapper = mount(SecretaryEquipmentModal, { attachTo: document.body, props });
            if (action === 'button') await wrapper.get('.equipment-modal-close').trigger('click');
            if (action === 'backdrop') await wrapper.get('.equipment-modal-backdrop').trigger('click');
            if (action === 'escape') await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
            expect(wrapper.emitted('close')).toHaveLength(1);
            expect(wrapper.emitted('submit')).toBeUndefined();
            wrapper.unmount();
        }
    });

    it('keeps the footer outside the scroll region and handles loading errors and fresh-choice state', async () => {
        const wrapper = mount(SecretaryEquipmentModal, {
            props: { ...props, requireFreshChoice: true, error: '更新競合です。' },
        });
        expect(wrapper.get('.equipment-options-scroll').element.contains(wrapper.get('.equipment-modal-footer').element)).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toBe('更新競合です。');
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(true);

        await wrapper.findAll<HTMLInputElement>('input[type="radio"]')[0]!.setValue(true);
        expect(wrapper.emitted('selectionChange')).toHaveLength(1);
        await wrapper.setProps({ requireFreshChoice: false, submitting: true });
        expect(wrapper.get('.equipment-modal-footer button').text()).toBe('変更中…');
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(true);

        await wrapper.setProps({ loading: true, submitting: false, options: null, error: '' });
        expect(wrapper.get('[role="status"]').text()).toContain('読み込んでいます');
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(true);
    });

    it('wraps keyboard focus within the dialog', async () => {
        const wrapper = mount(SecretaryEquipmentModal, { attachTo: document.body, props });
        await wrapper.vm.$nextTick();
        const close = wrapper.get<HTMLButtonElement>('.equipment-modal-close').element;
        const submit = wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element;

        close.focus();
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Tab', shiftKey: true });
        expect(document.activeElement).toBe(submit);
        submit.focus();
        await wrapper.get('[role="dialog"]').trigger('keydown', { key: 'Tab' });
        expect(document.activeElement).toBe(close);
    });
});
