import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { SecretaryProfile } from '../types';
import SecretaryImageSlotsEditor from './SecretaryImageSlotsEditor.vue';

describe('Secretary image slots editor', () => {
    it('renders six independent image and credit inputs with the portrait preference', () => {
        const profile = {
            portrait_preference: 'full_body',
            images: {
                full_body: { display: 'uploaded', url: '/full.webp', creation_method: 'commissioned_or_permitted', creation_method_label: '依頼・使用許諾済み', credit: 'Artist A' },
            },
        } as SecretaryProfile;
        const wrapper = mount(SecretaryImageSlotsEditor, { props: { profile } });

        expect(wrapper.findAll('.secretary-image-slot')).toHaveLength(6);
        expect(wrapper.findAll('input[type="file"]')).toHaveLength(6);
        expect(wrapper.findAll('input[placeholder="画像ごとのcredit"]')).toHaveLength(6);
        expect(wrapper.findAll('[data-slot="full_body"], [data-slot="awakening_full_body"]')).toHaveLength(2);
        expect(wrapper.get<HTMLSelectElement>('label select').element.value).toBe('full_body');
        expect(wrapper.text()).toContain('bust/full bodyはどちらも3:4');
        expect(wrapper.text()).toContain('覚醒画像が未登録なら同じ種類の通常画像');
        expect(wrapper.text()).toContain('画像ごとに保存');
        expect(wrapper.text()).toContain('Artist A');
    });
});
