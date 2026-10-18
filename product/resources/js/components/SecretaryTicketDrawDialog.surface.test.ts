import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import SecretaryTicketDrawDialog from './SecretaryTicketDrawDialog.vue';

describe('ticket draw count', () => {
    it('starts at the largest draw the warehouse allows and emits the chosen count', async () => {
        const wrapper = mount(SecretaryTicketDrawDialog, { props: { name: 'わくわくチケット Lv9,999', level: 9999, maxDrawCount: 52, busy: false } });
        expect((wrapper.get('input').element as HTMLInputElement).value).toBe('52');
        await wrapper.findAll('button').find(button => button.text() === '10回')!.trigger('click');
        await wrapper.get('form').trigger('submit');
        expect(wrapper.emitted('draw')?.[0]).toEqual([10]);
        expect(wrapper.text()).toContain('残り Lv9,989');
    });

    it('explains why a full warehouse cannot draw', () => {
        const wrapper = mount(SecretaryTicketDrawDialog, { props: { name: 'わくわくチケット Lv3', level: 3, maxDrawCount: 0, busy: false } });
        expect(wrapper.text()).toContain('倉庫に空きがない');
        expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    });
});
