import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import type { SecretaryProfile } from '../types';
import SecretaryImageSlotsEditor from './SecretaryImageSlotsEditor.vue';

vi.mock('../api/client', () => ({ api: vi.fn() }));

const apiMock = vi.mocked(api);

afterEach(() => {
    apiMock.mockReset();
    vi.restoreAllMocks();
});

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
        expect(wrapper.findAll('input[placeholder="作者名・権利表記"]')).toHaveLength(6);
        expect(wrapper.findAll('[data-slot="full_body"], [data-slot="awakening_full_body"]')).toHaveLength(2);
        expect(wrapper.get<HTMLSelectElement>('label select').element.value).toBe('full_body');
        expect(wrapper.text()).toContain('バストアップと全身は3:4');
        expect(wrapper.text()).toContain('覚醒画像がない場合は通常画像');
        expect(wrapper.text()).toContain('全身画像がない場合も、登録済みのバストアップ');
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
        expect(icon.get<HTMLInputElement>('input[placeholder="作者名・権利表記"]').element.value).toBe('Artist Icon');
        await icon.get('button:not(.secretary-image-delete)').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/images/icon', {
            method: 'PATCH',
            body: JSON.stringify({ creation_method: 'ai_generated', credit: 'Artist Icon' }),
        });
    });

    it('deletes a registered image from its image slot', async () => {
        const profile = {
            id: 12,
            portrait_preference: 'full_body',
            main_image: { display: 'uploaded', url: '/full.webp' },
            images: {
                full_body: { display: 'uploaded', url: '/full.webp', source: 'slot', creation_method: 'self_made', creation_method_label: '自作', credit: 'Artist Full' },
            },
        } as SecretaryProfile;
        apiMock.mockResolvedValue(profile);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const wrapper = mount(SecretaryImageSlotsEditor, { props: { profile } });

        await wrapper.get('[aria-label="通常全身を削除"]').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/images/full_body', { method: 'DELETE' });
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

        expect(icon.text()).toContain('登録済み');
        expect(icon.text()).toContain('表示設定により非表示');
        expect(icon.get<HTMLSelectElement>('select').element.value).toBe('ai_generated');
        expect(icon.get<HTMLInputElement>('input[placeholder="作者名・権利表記"]').element.value).toBe('Hidden credit');
        expect(icon.get('button:not(.secretary-image-delete)').attributes('disabled')).toBeUndefined();
        await icon.get('button:not(.secretary-image-delete)').trigger('click');

        expect(apiMock).toHaveBeenCalledWith('/api/v1/me/secretary/images/icon', {
            method: 'PATCH',
            body: JSON.stringify({ creation_method: 'ai_generated', credit: 'Hidden credit' }),
        });
    });
});
