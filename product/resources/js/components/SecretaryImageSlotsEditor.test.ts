import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import type { SecretaryProfile } from '../types';
import SecretaryImageSlotsEditor from './SecretaryImageSlotsEditor.vue';

vi.mock('../api/client', () => ({ api: vi.fn() }));

const apiMock = vi.mocked(api);

afterEach(() => apiMock.mockReset());

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

    it('prefills slot metadata and updates it without uploading another image', async () => {
        const profile = {
            id: 11,
            portrait_preference: 'full_body',
            main_image: { display: 'none', url: null },
            images: {
                icon: { display: 'uploaded', url: '/icon.webp', source: 'slot', creation_method: 'ai_generated', creation_method_label: 'AI生成', credit: 'Artist Icon' },
            },
        } as SecretaryProfile;
        apiMock.mockResolvedValue(profile);
        const wrapper = mount(SecretaryImageSlotsEditor, { props: { profile } });
        const icon = wrapper.get('[data-slot="icon"]');

        expect(icon.get<HTMLSelectElement>('select').element.value).toBe('ai_generated');
        expect(icon.get<HTMLInputElement>('input[placeholder="画像ごとのcredit"]').element.value).toBe('Artist Icon');
        await icon.get('button').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/images/icon', {
            method: 'PATCH',
            body: JSON.stringify({ creation_method: 'ai_generated', credit: 'Artist Icon' }),
        });
    });

    it('keeps legacy main metadata on its fallback endpoint', async () => {
        const profile = {
            id: 12,
            portrait_preference: 'full_body',
            main_image: { display: 'uploaded', url: '/legacy.webp' },
            images: {
                full_body: { display: 'uploaded', url: '/legacy.webp', source: 'legacy_main', creation_method: 'self_made', creation_method_label: '自作', credit: 'Legacy credit' },
            },
        } as SecretaryProfile;
        apiMock.mockResolvedValue(profile);
        const wrapper = mount(SecretaryImageSlotsEditor, { props: { profile } });

        await wrapper.get('[data-slot="full_body"] button').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/main-image', {
            method: 'PATCH',
            body: JSON.stringify({ creation_method: 'self_made', credit: 'Legacy credit' }),
        });
    });

    it('keeps metadata controls when the owner hides an AI image', async () => {
        const profile = {
            id: 13,
            portrait_preference: 'full_body',
            main_image: { display: 'none', url: null },
            images: {
                icon: {
                    display: 'none',
                    url: null,
                    source: 'slot',
                    editable_metadata: { creation_method: 'ai_generated', creation_method_label: 'AI生成', credit: 'Hidden credit' },
                    creation_method: null,
                    credit: null,
                },
            },
        } as SecretaryProfile;
        apiMock.mockResolvedValue(profile);
        const wrapper = mount(SecretaryImageSlotsEditor, { props: { profile } });
        const icon = wrapper.get('[data-slot="icon"]');

        expect(icon.text()).toContain('画像非表示・編集可');
        expect(icon.get<HTMLSelectElement>('select').element.value).toBe('ai_generated');
        expect(icon.get<HTMLInputElement>('input[placeholder="画像ごとのcredit"]').element.value).toBe('Hidden credit');
        expect(icon.get('button').attributes('disabled')).toBeUndefined();
        await icon.get('button').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/images/icon', {
            method: 'PATCH',
            body: JSON.stringify({ creation_method: 'ai_generated', credit: 'Hidden credit' }),
        });
    });
});
