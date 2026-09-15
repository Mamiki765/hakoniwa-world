import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import RankingAchievements from './RankingAchievements.vue';
import type { AssetDescriptor, PublicRankingAchievements } from '../types';

const asset = (key: string, available = true): AssetDescriptor => ({
    key,
    url: available ? `/assets/${key}.gif` : null,
    available,
    fallback_label: key === 'award.prosperity' ? '繁栄賞' : '賞',
    fallback_style: key.replaceAll('.', '-'),
});

describe('RankingAchievements', () => {
    it('renders recurring counts at 1, 9, and 10 and lists every awarded turn', async () => {
        const achievements: PublicRankingAchievements = {
            awards: [
                { key: 'award.turn', name: 'ターン賞', recurring: true, count: 1, awarded_turns: [100], asset: asset('award.turn') },
                { key: 'award.test_nine', name: '九回賞', recurring: true, count: 9, awarded_turns: [100, 200, 300, 400, 500, 600, 700, 800, 900], asset: asset('award.test_nine') },
                { key: 'award.test_ten', name: '十回賞', recurring: true, count: 10, awarded_turns: [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000], asset: asset('award.test_ten') },
            ],
            monster_kills: null,
        };
        const wrapper = mount(RankingAchievements, { props: { achievements } });

        expect(wrapper.findAll('.achievement-count').map((node) => node.text())).toEqual(['×1', '×9', '10']);
        const first = wrapper.findAll('button')[0]!;
        await first.trigger('mouseenter');
        expect(wrapper.find('[role="tooltip"]').text()).toContain('100ターン');
        expect(first.attributes('aria-describedby')).toBe(wrapper.find('[role="tooltip"]').attributes('id'));

        const tenth = wrapper.findAll('button')[2]!;
        await tenth.trigger('focus');
        const turns = wrapper.findAll('[role="tooltip"] .achievement-turns span').map((node) => node.text());
        expect(turns).toEqual(['100ターン', '200ターン', '300ターン', '400ターン', '500ターン', '600ターン', '700ターン', '800ターン', '900ターン', '1,000ターン']);
    });

    it('supports hover, focus, tap pinning, Escape, condition fallback, and species details', async () => {
        const achievements: PublicRankingAchievements = {
            awards: [{
                key: 'award.prosperity',
                name: '繁栄賞',
                recurring: false,
                count: 1,
                asset: asset('award.prosperity', false),
            }],
            monster_kills: {
                total_count: 13,
                asset: asset('hakoniwa_original.monster.king_inora'),
                species: [
                    { key: 'inora', name: 'いのら', kill_count: 11 },
                    { key: 'king_inora', name: 'キングいのら', kill_count: 2 },
                ],
            },
        };
        const wrapper = mount(RankingAchievements, { props: { achievements } });
        const buttons = wrapper.findAll('button');
        expect(buttons).toHaveLength(2);
        expect(wrapper.find('.achievement-fallback').text()).toBe('繁');
        expect(wrapper.findAll('img')).toHaveLength(1);
        expect(wrapper.find('img').attributes('alt')).toBe('');

        await buttons[0]!.trigger('mouseenter');
        expect(wrapper.find('[role="tooltip"]').text()).toBe('繁栄賞');
        await buttons[0]!.trigger('mouseleave');
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);

        await buttons[1]!.trigger('click');
        await buttons[1]!.trigger('mouseleave');
        expect(wrapper.find('[role="tooltip"]').text()).toContain('いのら ×11');
        expect(wrapper.find('[role="tooltip"]').text()).toContain('キングいのら ×2');
        expect(buttons[1]!.attributes('aria-expanded')).toBe('true');

        await buttons[0]!.trigger('mouseenter');
        expect(wrapper.find('[role="tooltip"]').text()).toBe('繁栄賞');
        await buttons[0]!.trigger('mouseleave');
        expect(wrapper.find('[role="tooltip"]').text()).toContain('キングいのら ×2');

        await wrapper.find('img').trigger('error');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('.monster-kill-badge .achievement-fallback').text()).toBe('怪');

        await wrapper.setProps({
            achievements: {
                ...achievements,
                monster_kills: {
                    ...achievements.monster_kills!,
                    asset: { ...achievements.monster_kills!.asset, url: '/assets/recovered-monster.gif' },
                },
            },
        });
        expect(wrapper.find('img').attributes('src')).toBe('/assets/recovered-monster.gif');
        await buttons[1]!.trigger('keydown', { key: 'Escape' });
        expect(wrapper.find('[role="tooltip"]').exists()).toBe(false);
        expect(buttons[1]!.attributes('aria-expanded')).toBe('false');
    });

    it('renders no controls when a Nation has no achievement projection', () => {
        const wrapper = mount(RankingAchievements, {
            props: { achievements: { awards: [], monster_kills: null } },
        });

        expect(wrapper.find('.ranking-achievements').exists()).toBe(false);
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('renders eleven ordered species with an accessible missing-asset fallback', async () => {
        const species = Array.from({ length: 11 }, (_, index) => ({
            key: `monster_${index}`,
            name: `怪獣${index}`,
            kill_count: index + 1,
        }));
        const wrapper = mount(RankingAchievements, {
            props: {
                achievements: {
                    awards: [],
                    monster_kills: {
                        total_count: 66,
                        asset: asset('hakoniwa_custom.monster.missing', false),
                        species,
                    },
                },
            },
        });

        const trigger = wrapper.get('button');
        expect(trigger.attributes('aria-label')).toBe('怪獣討伐 66体');
        expect(wrapper.get('.achievement-fallback').text()).toBe('怪');
        await trigger.trigger('focus');
        expect(wrapper.findAll('.achievement-turns > span')).toHaveLength(11);
        expect(wrapper.findAll('.achievement-turns > span').map((node) => node.text()))
            .toEqual(species.map((row) => `${row.name} ×${row.kill_count}`));
        expect(trigger.attributes('aria-describedby')).toBe(wrapper.get('[role="tooltip"]').attributes('id'));
    });
});
