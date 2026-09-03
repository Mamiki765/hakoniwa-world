import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import UndergroundAiEditor from './UndergroundAiEditor.vue';
import type { UndergroundAiConfiguration } from './undergroundAi';

const response = (data: unknown, status = 200): Response => new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

const configuration = (overrides: Partial<UndergroundAiConfiguration> = {}): UndergroundAiConfiguration => ({
    schema_version: 1,
    max_rules: 16,
    max_conditions_per_rule: 2,
    is_custom: false,
    rules: [
        { conditions: [{ type: 'own_hp_lte', percent: 20 }], action: 'awakening' },
        { conditions: [{ type: 'always' }], action: 'normal_attack' },
    ],
    default_rules: [
        { conditions: [{ type: 'own_hp_lte', percent: 20 }], action: 'awakening' },
        { conditions: [{ type: 'always' }], action: 'normal_attack' },
    ],
    hash: 'a'.repeat(64),
    catalog: {
        condition_types: [
            { key: 'always', label: '常に', value_kind: 'none' },
            { key: 'own_hp_lte', label: '自分のHPが指定%以下', value_kind: 'percent' },
            { key: 'self_has_status', label: '自分に指定状態がある', value_kind: 'status' },
            { key: 'status_stacks_gte', label: '自分の指定状態が指定stack以上', value_kind: 'status_stacks' },
            { key: 'role_stacks_gte', label: '自分のrole stackが指定数以上', value_kind: 'role_stacks' },
            { key: 'skill_ready', label: '指定skillが現在使用可能', value_kind: 'skill' },
            { key: 'round_gte', label: '指定round以降', value_kind: 'round' },
            { key: 'round_modulo', label: 'roundの周期条件', value_kind: 'round_modulo' },
        ],
        actions: [
            { key: 'normal_attack', label: '通常攻撃' },
            { key: 'defend', label: '防御' },
            { key: 'awakening', label: '覚醒' },
            { key: 'jump', label: '後ろのruleへ移動' },
        ],
        skills: [
            { key: 'learned_cut', label: '習得済みの斬撃', summary: 'test' },
            { key: 'future_blast', label: '未習得の砲撃', summary: 'test' },
        ],
        statuses: [{ key: 'bleed', label: '出血', max_stacks: 3 }],
        role_stacks: [{ key: 'grace', label: '恩寵', max_stacks: 5 }],
    },
    ...overrides,
});

afterEach(() => {
    vi.unstubAllGlobals();
    document.body.replaceChildren();
});

describe('Underground AI editor', () => {
    it('clones the default, limits AND conditions, and offers only forward jump targets', async () => {
        const wrapper = mount(UndergroundAiEditor, { props: { configuration: configuration() } });

        expect(wrapper.text()).toContain('初期設定を表示しています');
        expect(wrapper.findAll('.underground-ai-rule')).toHaveLength(2);
        expect(wrapper.findAll('.underground-ai-rule')[0]!.find('fieldset').attributes('disabled')).toBeDefined();

        await wrapper.findAll('.underground-ai-mode-actions button')[1]!.trigger('click');
        const firstRule = wrapper.findAll('.underground-ai-rule')[0]!;
        expect(firstRule.find('fieldset').attributes('disabled')).toBeUndefined();

        const addCondition = firstRule.findAll('fieldset')[0]!.find('button');
        await addCondition.trigger('click');
        expect(firstRule.findAll('.underground-ai-condition')).toHaveLength(2);
        expect(addCondition.attributes('disabled')).toBeDefined();

        await firstRule.find('select[aria-label="Rule 1 action"]').setValue('jump');
        const jump = firstRule.get('select[aria-label="Rule 1の移動先"]');
        expect(jump.findAll('option').map((option) => option.text())).toEqual(['Rule 2']);
        expect(wrapper.findAll('.underground-ai-rule')[1]!.find('option[value="jump"]').attributes('disabled')).toBeDefined();
        wrapper.unmount();
    });

    it('saves a canonical unlearned skill action and preserves the UUID when retrying', async () => {
        const payloads: Array<{ request_id: string; rules: unknown }> = [];
        let attempts = 0;
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            attempts += 1;
            payloads.push(JSON.parse(String(init?.body)) as { request_id: string; rules: unknown });
            if (attempts === 1) throw new TypeError('AI response lost');
            return response({ stage: 'underground_open', ai: configuration({ is_custom: true }) });
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundAiEditor, { props: { configuration: configuration() } });

        await wrapper.findAll('.underground-ai-mode-actions button')[1]!.trigger('click');
        await wrapper.get('select[aria-label="Rule 1 action"]').setValue('skill:future_blast');
        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('AI response lost');

        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(payloads).toHaveLength(2);
        expect(payloads[0]).toEqual(payloads[1]);
        expect(payloads[0]!.rules).toEqual([
            { conditions: [{ type: 'own_hp_lte', percent: 20 }], action: 'skill:future_blast' },
            { conditions: [{ type: 'always' }], action: 'normal_attack' },
        ]);
        expect(wrapper.emitted('updated')).toHaveLength(1);
        wrapper.unmount();
    });

    it('keeps default null distinct from an intentionally empty custom rule list', async () => {
        const payloads: Array<{ request_id: string; rules: unknown }> = [];
        const fetchMock = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            payloads.push(JSON.parse(String(init?.body)) as { request_id: string; rules: unknown });
            return response({ stage: 'underground_open' });
        });
        vi.stubGlobal('fetch', fetchMock);

        const custom = configuration({ is_custom: true });
        const wrapper = mount(UndergroundAiEditor, { props: { configuration: custom } });
        await wrapper.findAll('.underground-ai-mode-actions button')[0]!.trigger('click');
        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(payloads[0]!.rules).toBeNull();

        await wrapper.setProps({ configuration: configuration() });
        await wrapper.findAll('.underground-ai-mode-actions button')[2]!.trigger('click');
        expect(wrapper.text()).toContain('deterministic fallbackだけを使用します');
        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(payloads[1]!.rules).toEqual([]);
        wrapper.unmount();
    });

    it('preserves jump targets across reordering and blocks backward or dangling jumps', async () => {
        const payloads: Array<{ rules: unknown }> = [];
        vi.stubGlobal('fetch', vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            payloads.push(JSON.parse(String(init?.body)) as { rules: unknown });
            return response({ stage: 'underground_open' });
        }));
        const rules = [
            { conditions: [{ type: 'always' }], action: 'jump', jump_to: 3 },
            { conditions: [{ type: 'always' }], action: 'defend' },
            { conditions: [{ type: 'always' }], action: 'awakening' },
        ];
        const wrapper = mount(UndergroundAiEditor, {
            props: { configuration: configuration({ is_custom: true, rules }) },
        });

        await wrapper.get('button[aria-label="Rule 3を上へ"]').trigger('click');
        expect(wrapper.findAll('select[aria-label$="action"]').map((select) => (select.element as HTMLSelectElement).value))
            .toEqual(['jump', 'awakening', 'defend']);

        await wrapper.get('button[aria-label="Rule 2を上へ"]').trigger('click');
        expect(wrapper.get('[role="alert"]').text()).toContain('移動先が前方');
        await wrapper.get('button[aria-label="Rule 2を削除"]').trigger('click');
        expect(wrapper.get('[role="alert"]').text()).toContain('1番目のruleがこのruleへ移動');

        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(payloads[0]!.rules).toEqual([
            { conditions: [{ type: 'always' }], action: 'jump', jump_to: 2 },
            { conditions: [{ type: 'always' }], action: 'awakening' },
            { conditions: [{ type: 'always' }], action: 'defend' },
        ]);
        wrapper.unmount();
    });

    it('abandons a conflicting request UUID before retrying the same draft', async () => {
        const requestIds: string[] = [];
        let attempts = 0;
        vi.stubGlobal('fetch', vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
            attempts += 1;
            requestIds.push((JSON.parse(String(init?.body)) as { request_id: string }).request_id);
            if (attempts === 1) {
                return new Response(JSON.stringify({
                    code: 'underground_request_conflict',
                    message: '既に別の操作で使われています。',
                }), { status: 409, headers: { 'Content-Type': 'application/json' } });
            }
            return response({ stage: 'underground_open' });
        }));
        const wrapper = mount(UndergroundAiEditor, { props: { configuration: configuration() } });

        await wrapper.findAll('.underground-ai-mode-actions button')[2]!.trigger('click');
        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('再試行時は新しいIDを使います');
        await wrapper.get('.underground-ai-save-actions .button.primary').trigger('click');
        await flushPromises();

        expect(requestIds).toHaveLength(2);
        expect(requestIds[1]).not.toBe(requestIds[0]);
        wrapper.unmount();
    });
});
