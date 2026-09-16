import { flushPromises, type VueWrapper } from '@vue/test-utils';

export function withUndergroundDefaults(data: unknown): unknown {
    if (!data || typeof data !== 'object' || !('stage' in data) || data.stage !== 'underground_open') return data;
    return {
        shard_balance: 0, banked_shard_balance: 0, xp_to_next_level: 0,
        residence: {
            villa_owned: true, mirror_owned: false, trophy_shelf_owned: false, exchange_intro_page: 2, mirror_event_completed: false,
            items: { villa: { name: '別荘', price: 100000 }, mirror: { name: '透明な鏡', price: 1000000 }, trophy_shelf: { name: 'トロフィー棚', price: 500000 } },
        },
        ...data,
    };
}

/** Follow the same page and tab controls as the player; no incidental button indices. */
export async function openUndergroundView(wrapper: VueWrapper, page: string, tab?: string): Promise<void> {
    const navigation = wrapper.findAll('.ug-navigation button');
    const pageButton = navigation.find(button => button.text().includes(page)
        || page === '交流場' && button.text().includes('？？？'));
    if (!pageButton) throw new Error('地底の行き先が見つかりません: ' + page);
    if (pageButton.attributes('aria-current') !== 'page') {
        await pageButton.trigger('click');
        await flushPromises();
    }
    if (tab) {
        const tabButton = wrapper.findAll('.ug-tabs button').find(button => button.text() === tab);
        if (!tabButton) throw new Error('画面内の切り替えが見つかりません: ' + tab);
        if (tabButton.attributes('aria-current') !== 'page') {
            await tabButton.trigger('click');
            await flushPromises();
        }
    }
}
