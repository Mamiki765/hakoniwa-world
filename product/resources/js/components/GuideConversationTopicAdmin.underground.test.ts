import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '../api/client';
import type { GuideConversationTopic, GuideConversationTopicIndex } from '../types';
import GuideConversationTopicAdmin from './GuideConversationTopicAdmin.vue';

vi.mock('../api/client', () => ({
    api: vi.fn(),
    ApiError: class ApiError extends Error {
        constructor(
            public readonly status: number,
            message: string,
            public readonly errors: Record<string, string[]> = {},
        ) {
            super(message);
        }
    },
}));

const apiMock = vi.mocked(api);
const unlockOptions = [
    { key: 'always', label: '常時' },
    { key: 'trial_01_first_clear', label: '試練1初回クリア' },
];
const topic: GuideConversationTopic = {
    id: 7,
    initial_line: '既存の話題',
    choice_1: '聞く',
    reply_1: '返答',
    choice_2: null,
    reply_2: null,
    choice_3: null,
    reply_3: null,
    unlock_key: 'trial_01_first_clear',
    enabled: true,
};

afterEach(() => {
    apiMock.mockReset();
    vi.restoreAllMocks();
});

describe('Guide conversation topic admin', () => {
    it('loads the code-defined unlocks and creates one fixed-shape topic', async () => {
        const emptyIndex: GuideConversationTopicIndex = { topics: [], unlock_options: unlockOptions };
        apiMock
            .mockResolvedValueOnce(emptyIndex)
            .mockResolvedValueOnce(topic)
            .mockResolvedValueOnce({ topics: [topic], unlock_options: unlockOptions });

        const wrapper = mount(GuideConversationTopicAdmin);
        await flushPromises();

        expect(wrapper.findAll<HTMLOptionElement>('select option').map((option) => option.element.value))
            .toEqual(expect.arrayContaining(unlockOptions.map((option) => option.key)));

        const textareas = wrapper.findAll('textarea');
        await textareas[0]!.setValue('新しい話題');
        await textareas[1]!.setValue('返答する');
        await textareas[2]!.setValue('新しい返答');
        await wrapper.get('select').setValue('trial_01_first_clear');
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(apiMock).toHaveBeenNthCalledWith(2, '/api/v1/admin/guide-conversation-topics', {
            method: 'POST',
            body: JSON.stringify({
                initial_line: '新しい話題',
                choice_1: '返答する',
                reply_1: '新しい返答',
                choice_2: null,
                reply_2: null,
                choice_3: null,
                reply_3: null,
                unlock_key: 'trial_01_first_clear',
                enabled: true,
            }),
        });
        expect(apiMock).toHaveBeenNthCalledWith(3, '/api/v1/admin/guide-conversation-topics');
        expect(wrapper.text()).toContain(topic.initial_line);
    });

    it('prefills, updates, and deletes an existing topic', async () => {
        const firstIndex: GuideConversationTopicIndex = { topics: [topic], unlock_options: unlockOptions };
        const updated = { ...topic, initial_line: '更新後の話題', enabled: false };
        apiMock
            .mockResolvedValueOnce(firstIndex)
            .mockResolvedValueOnce(updated)
            .mockResolvedValueOnce({ topics: [updated], unlock_options: unlockOptions })
            .mockResolvedValueOnce(null)
            .mockResolvedValueOnce({ topics: [], unlock_options: unlockOptions });
        vi.spyOn(window, 'scrollTo').mockImplementation(() => undefined);
        vi.spyOn(window, 'confirm').mockReturnValue(true);

        const wrapper = mount(GuideConversationTopicAdmin);
        await flushPromises();
        await wrapper.findAll('button').find((button) => button.text() === '編集')!.trigger('click');

        expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe('既存の話題');
        expect(wrapper.get<HTMLSelectElement>('select').element.value).toBe('trial_01_first_clear');
        await wrapper.get('textarea').setValue('更新後の話題');
        await wrapper.get<HTMLInputElement>('input[type="checkbox"]').setValue(false);
        await wrapper.get('form').trigger('submit');
        await flushPromises();

        expect(apiMock).toHaveBeenNthCalledWith(2, `/api/v1/admin/guide-conversation-topics/${topic.id}`, expect.objectContaining({ method: 'PATCH' }));
        expect(apiMock).toHaveBeenNthCalledWith(3, '/api/v1/admin/guide-conversation-topics');
        expect(wrapper.text()).toContain(updated.initial_line);
        await wrapper.findAll('button').find((button) => button.text() === '削除')!.trigger('click');
        await flushPromises();

        expect(apiMock).toHaveBeenNthCalledWith(4, `/api/v1/admin/guide-conversation-topics/${topic.id}`, { method: 'DELETE' });
        expect(apiMock).toHaveBeenNthCalledWith(5, '/api/v1/admin/guide-conversation-topics');
        expect(wrapper.text()).not.toContain(updated.initial_line);
    });
});
