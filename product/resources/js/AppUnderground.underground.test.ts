import { openUndergroundView, withUndergroundDefaults } from './UndergroundTestNavigation';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import App from './App.vue';
import UndergroundPanel from './components/UndergroundPanel.vue';
import { response as baseResponse, ownerNationFixture, unnamedSecretaryFixture, publicResponse, installAppTestLifecycle } from './AppTestHarness';

const response = (data: unknown, status = 200) => baseResponse(withUndergroundDefaults(data), status);

installAppTestLifecycle();

describe('Underground application operations', () => {
    it('sells only the unequipped instance of two equal-level bracelets', async () => {
        const secretary = structuredClone(unnamedSecretaryFixture);
        Object.assign(secretary, { name: '秘書', named_at: '2026-09-09T00:00:00Z', header_label: '秘書' });
        for (const item of secretary.inventory.items) {
            Object.assign(item, { key: 'inora_bracelet', name: 'いのらの腕輪', level: 1, category: 'accessory' });
        }
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({ id: 1, display_name: 'Owner', providers: [] });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);
            if (path === '/api/v1/me/secretary/items/22/sell' && init?.method === 'POST') {
                secretary.inventory.items = secretary.inventory.items.filter((item) => item.id !== 22);
                secretary.inventory.used = 1;
                return response({ secretary, nation: ownerNationFixture });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '秘書')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === '倉庫')!.trigger('click');
        const rows = wrapper.findAll('.secretary-warehouse > li');
        expect(rows).toHaveLength(2);
        await rows[1]!.get('.secretary-item-sell').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.filter(([path, init]) => String(path).endsWith('/sell') && init?.method === 'POST')
            .map(([path]) => path)).toEqual(['/api/v1/me/secretary/items/22/sell']);
        expect(wrapper.findAll('.secretary-warehouse > li')).toHaveLength(1);
        expect(wrapper.get('.secretary-warehouse > li').text()).toContain('slot 1 に装備中');
        wrapper.unmount();
    });

    it('shows the unnamed Secretary story with the default name and switches permanently to the skill view after naming', async () => {
        window.history.replaceState({}, '', '/underground');
        let secretary = structuredClone(unnamedSecretaryFixture);
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') {
                return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, providers: [], viewer_preferences: secretary.profile.viewer_preferences });
            }
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary/name' && init?.method === 'POST') {
                const body = JSON.parse(String(init.body)) as { name: string };
                secretary = {
                    ...secretary,
                    name: body.name,
                    named_at: '2026-08-16T15:00:00+09:00',
                    header_label: body.name,
                    profile: { ...secretary.profile, name: body.name, battle_display_name: body.name },
                };

                return response(secretary);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { name: string };
                secretary = {
                    ...secretary,
                    name: body.name,
                    header_label: body.name,
                    profile: { ...secretary.profile, name: body.name, battle_display_name: body.name },
                };

                return response(secretary);
            }
            if (path === '/api/v1/me/secretary/profile' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { biography?: string; nickname?: string | null };
                const nickname = Object.hasOwn(body, 'nickname') ? (body.nickname || null) : secretary.profile.nickname;
                secretary = {
                    ...secretary,
                    profile: {
                        ...secretary.profile,
                        biography: body.biography ?? secretary.profile.biography,
                        nickname,
                        battle_display_name: nickname ?? secretary.name ?? '？？？',
                    },
                };

                return response({ ...secretary.profile, name: secretary.name, is_owner: true });
            }
            if (path === '/api/v1/me/secretary/image-preferences' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as {
                    show_ai_generated_images: boolean;
                    own_secretary_fallback: 'silhouette' | 'peridot';
                };
                secretary = {
                    ...secretary,
                    profile: {
                        ...secretary.profile,
                        viewer_preferences: {
                            configured: true,
                            show_ai_generated_images: body.show_ai_generated_images,
                            own_secretary_fallback: body.own_secretary_fallback,
                            fallback: body.own_secretary_fallback,
                            can_update: true,
                        },
                    },
                };

                return response(secretary.profile.viewer_preferences);
            }
            if (path === '/api/v1/secretaries/11?world_id=1') {
                return response({ ...secretary.profile, name: secretary.name, is_owner: true });
            }
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);
            if (path === '/api/v1/me/underground') {
                return response({
                    stage: 'not_started', secretary_name: 'エメラルド', combat_level: 1,
                    combat_xp: 0, next_level_xp: 100, shard_balance: 0,
                    shopkeeper_name: null, battle: null,
                });
            }
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') {
                return response({
                    stage: 'initial_descent', secretary_name: secretary.name,
                    combat_level: 1, combat_xp: 0, next_level_xp: 100,
                    shard_balance: 0, shopkeeper_name: null, battle: null,
                });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(window.location.pathname).toBe('/underground');
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(false);
        expect(wrapper.get('.underground-unnamed-gate').text()).toContain('こっちより先に？？？って露骨な方触りなさいよ');
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/me/underground')).toBe(false);
        const headerButtons = wrapper.findAll('.site-header nav button');
        const undergroundButtonIndex = headerButtons.findIndex((button) => button.text() === '地底');
        const optionsButtonIndex = headerButtons.findIndex((button) => button.text() === 'オプション');
        expect(undergroundButtonIndex).toBeGreaterThanOrEqual(0);
        expect(optionsButtonIndex).toBe(undergroundButtonIndex + 1);
        const secretaryButton = wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === '？？？')!;
        expect(secretaryButton.exists()).toBe(true);
        await secretaryButton.trigger('click');
        await flushPromises();

        expect(wrapper.get('.secretary-story').text()).toContain('怪獣に踏み荒らされた地から妙な施設が見つかった');
        expect(wrapper.get('.secretary-story').text()).toContain('その手の趣向の持ち主に合わせた整形の線も考えたが、そのような跡は見受けられなかった。');
        expect(wrapper.findAll('.secretary-story p').at(-1)?.text()).toBe('「私の名前は——」');
        expect(wrapper.get('.secretary-page-title').text()).toBe('秘書');
        expect(wrapper.get('.secretary-name').text()).toBe('？？？');
        expect(wrapper.get<HTMLInputElement>('#secretary-name').element.value).toBe('ペリドット');
        await wrapper.get('.secretary-naming-form').trigger('submit');
        await flushPromises();

        const namingRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/name' && init?.method === 'POST'
        ));
        expect(JSON.parse(String(namingRequest?.[1]?.body))).toEqual({ name: 'ペリドット' });
        expect(wrapper.find('.secretary-story').exists()).toBe(false);
        expect(wrapper.get('.secretary-page-title').text()).toBe('秘書');
        expect(wrapper.get('.secretary-name').text()).toBe('ペリドット');
        expect(wrapper.get('.secretary-main-profile').text()).toContain('内政Lv1');
        expect(wrapper.get('.secretary-main-profile').text()).toContain('資金・食糧最大+1%');
        expect(wrapper.get('.secretary-main-profile').text()).toContain('討伐経験値0');
        expect(wrapper.get('.secretary-no-image').text()).toBe('No image');
        expect(wrapper.get('.secretary-image-preference-notice').text()).toContain('画像表示設定が未設定です');
        await wrapper.get('.secretary-image-preference-notice button').trigger('click');
        expect(wrapper.get('.image-settings').text()).toContain('一部で使用されているAI生成画像を表示する');
        expect(wrapper.get('.image-settings').text()).toContain('デフォルトの秘書画像の表示方法');
        await wrapper.get('.image-settings input[value="true"]').setValue();
        await wrapper.get('.image-settings form').trigger('submit');
        await flushPromises();
        const imagePreferenceRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/image-preferences' && init?.method === 'PATCH'
        ));
        expect(JSON.parse(String(imagePreferenceRequest?.[1]?.body))).toEqual({
            show_ai_generated_images: true,
            own_secretary_fallback: 'silhouette',
        });
        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-biography textarea').setValue('更新した経歴');
        await wrapper.get('.secretary-biography form').trigger('submit');
        await flushPromises();
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/secretaries/11?world_id=1')).toBe(true);
        expect(wrapper.get<HTMLTextAreaElement>('.secretary-biography textarea').element.value).toBe('更新した経歴');
        const initialTabs = wrapper.findAll('[role="tab"]');
        expect(initialTabs.map((tab) => tab.text())).toEqual(['メイン', '熟練度', '装備', '倉庫', '設定']);
        await initialTabs[1]!.trigger('click');
        expect(wrapper.get('.secretary-section-title').text()).toBe('パッシブスキル');
        const skillRows = wrapper.findAll('.secretary-skill');
        const agriculturalSkill = skillRows[0]!;
        const defenseSkill = skillRows[4]!;
        expect(agriculturalSkill.get('.secretary-skill-name').text()).toBe('農業政策');
        expect(agriculturalSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv0', 'XP 0 / 1']);
        expect(agriculturalSkill.get('.secretary-skill-effect').text()).toBe('小麦生産＋0.0%');
        expect(defenseSkill.get('.secretary-skill-name').text()).toBe('最終防衛ライン');
        expect(defenseSkill.findAll('.secretary-skill-progress span').map((span) => span.text())).toEqual(['Lv1', 'XP 0 / 100']);
        expect(defenseSkill.get('.secretary-skill-effect').text()).toBe('防衛されなかったミサイルを1ターンにつき1発まで迎撃');
        expect(skillRows[5]!.get('.secretary-skill-effect').text()).toBe('自然人口上限 +500人 / 誘致人口上限 +1,000人');
        expect(skillRows[6]!.get('.secretary-skill-effect').text()).toBe('自然人口増加 +2.50%');
        expect(wrapper.get('.secretary-skills').text()).not.toContain('次のlevelまで');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'ペリドット')).toBe(true);

        const secretaryGetCount = () => fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/secretary?world_id=1').length;
        const beforeTabSwitch = secretaryGetCount();
        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs.map((tab) => tab.text())).toEqual(['メイン', '熟練度', '装備', '倉庫', '設定']);
        expect(tabs[1]!.attributes('aria-selected')).toBe('true');
        await tabs[1]!.trigger('keydown', { key: 'ArrowRight' });
        expect(wrapper.findAll('[role="tab"]')[2]!.attributes('aria-selected')).toBe('true');
        await wrapper.findAll('[role="tab"]')[2]!.trigger('keydown', { key: 'ArrowLeft' });
        expect(wrapper.findAll('[role="tab"]')[1]!.attributes('aria-selected')).toBe('true');
        await tabs[2]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment li')).toHaveLength(5);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('古びた弓');
        expect(wrapper.findAll('.secretary-equipment li').slice(1).every((slot) => slot.text().includes('空き'))).toBe(true);
        await wrapper.findAll('[role="tab"]')[3]!.trigger('click');
        expect(wrapper.get('.secretary-section-title').text()).toBe('倉庫 2 / 50');
        expect(wrapper.findAll('.item-effect').map((effect) => effect.text())).toEqual([
            '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            '資金繰りの際、追加で3億円を得る。',
        ]);
        expect(wrapper.get('.secretary-warehouse').text()).toContain('施設の最奥');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('貴金属が使われた豪華な指輪');
        expect(wrapper.get('.item-flavor').classes()).toContain('item-flavor');
        expect(secretaryGetCount()).toBe(beforeTabSwitch);

        await wrapper.findAll('[role="tab"]')[3]!.trigger('keydown', { key: 'End' });
        expect(wrapper.findAll('[role="tab"]')[4]!.attributes('aria-selected')).toBe('true');
        expect(wrapper.get('.secretary-settings').text()).toContain('基本設定');
        expect(wrapper.findAll('.secretary-settings .secretary-image-slot')).toHaveLength(6);
        expect(wrapper.get<HTMLInputElement>('.secretary-rename-form input').element.value).toBe('ペリドット');
        await wrapper.get('.secretary-rename-form input').setValue('エメラルド');
        await wrapper.get('.secretary-rename-form').trigger('submit');
        await flushPromises();
        const renameRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/secretary/name' && init?.method === 'PATCH'
        ));
        expect(JSON.parse(String(renameRequest?.[1]?.body))).toEqual({ name: 'エメラルド' });
        expect(wrapper.text()).toContain('秘書の名前を「エメラルド」に変更しました。');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'エメラルド')).toBe(true);
        await wrapper.get('#secretary-nickname').setValue('エメ');
        await wrapper.findAll('.secretary-basic-settings form')[1]!.trigger('submit');
        await flushPromises();
        const nicknameRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/secretary/profile' && init?.method === 'PATCH'
        ));
        expect(JSON.parse(String(nicknameRequests.at(-1)?.[1]?.body))).toEqual({ nickname: 'エメ' });
        expect(wrapper.get('.secretary-name').text()).toBe('エメ');
        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'オプション')!.trigger('click');
        expect(wrapper.find('.secretary-rename-form').exists()).toBe(false);
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'エメラルド')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-underground-entry button').text()).toBe('地下へ');
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('あなたの秘書は、暗く狭い場所で目を覚ました。');
        const undergroundRequest = fetchMock.mock.calls.find(([path]) => (
            String(path) === '/api/v1/me/underground/entry'
        ));
        expect(JSON.parse(String(undergroundRequest?.[1]?.body))).toEqual({
            request_id: expect.any(String),
        });
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'TOP')!.trigger('click');
        await flushPromises();
        expect(window.location.pathname).toBe('/');
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'エメラルド')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        window.history.pushState({ page: 'secretary' }, '', '/');
        window.dispatchEvent(new PopStateEvent('popstate', { state: { page: 'secretary' } }));
        await flushPromises();
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(true);
    });

    it('replaces the Underground history entry when escape completion redirects to Secretary', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        const returnedState = {
            stage: 'returned_after_tutorial', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 0,
            shopkeeper_name: null, battle: null,
        };
        const escapeState = {
            ...returnedState,
            stage: 'escape_pending',
            battle: {
                id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
                encounter_name: 'ジャイアントラット', result: 'victory', rounds: 1,
                xp_awarded: 5, shard_delta: 0, detail_available: true,
                actions: [{ round: 1, side: 'player', action_label: '連続斬り', amount: 90 }],
            },
        };
        const replaceSpy = vi.spyOn(window.history, 'replaceState');
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/underground') return response(escapeState);
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') return response(returnedState);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();

        expect(window.location.pathname).toBe('/');
        expect(wrapper.find('.underground-panel').exists()).toBe(false);
        expect(wrapper.find('.secretary-panel').exists()).toBe(true);
        expect(replaceSpy).toHaveBeenCalledWith({ page: 'secretary' }, '', '/');
        expect(fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/underground/story/advance')).toHaveLength(1);
    });

    it('shows the unlocked Underground projection, named seal story, and escaped battle history', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        serverSecretary.underground = {
            available: true, stage: 'underground_open', combat_level: 1,
            combat_xp: 5, next_level_xp: 100,
        };
        const growthPath = {
            key: 'guardianship_blue', label: '護身', color: 'blue',
            description: ['粘り強い戦いを求める成長方針。'], default_build_key: 'pure_tank',
            stats: { vitality: 40, might: 20, finesse: 15, spirit: 15, agility: 10 },
            max_hp: 660, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 2, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: 5, points_per_level: 10,
        };
        const playtest = {
            notice: 'α版の戦闘検証用完成形ビルドです。正式な育成・装備状態ではありません。',
            default_build_key: 'pure_tank',
            builds: [
                { key: 'pure_attacker', label: '攻撃特化', description: '短期戦' },
                { key: 'pure_tank', label: '護身特化', description: '防御判断' },
                { key: 'pure_healer', label: '祝福特化', description: '回復' },
                { key: 'balanced', label: '混成', description: '混成' },
            ],
            enemies: [
                { key: 'depth_stalker', label: '深層追跡者', description: '通常の適正戦闘' },
                { key: 'pressure_construct', label: '予兆を放つ圧力試験体', description: '防御判断' },
                { key: 'crystal_warden', label: '輝晶守護者', description: 'boss' },
            ],
        };
        let openState = {
            stage: 'underground_open', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, next_level_requirement: 100, xp_to_next_level: 95,
            shard_balance: 2350, banked_shard_balance: 5000, current_hp: 321, unspent_stp: 3,
            allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 },
            current_stats: growthPath.stats,
            combat_stats: { vitality: 41, might: 21, finesse: 16, spirit: 16, agility: 11 },
            status_breakdown: {
                vitality: { baseline: 40, natural_growth: 0, allocated_stp: 0, equipment: 1, final: 41 },
                might: { baseline: 20, natural_growth: 0, allocated_stp: 0, equipment: 1, final: 21 },
                finesse: { baseline: 15, natural_growth: 0, allocated_stp: 0, equipment: 1, final: 16 },
                spirit: { baseline: 15, natural_growth: 0, allocated_stp: 0, equipment: 1, final: 16 },
                agility: { baseline: 10, natural_growth: 0, allocated_stp: 0, equipment: 1, final: 11 },
            },
            skill_points_total: 20, skill_points_unspent: 20, skill_points_spent: 0,
            skill_tree_identity: 'secretary-underground-skill-tree-alpha-v2',
            skill_trees: [{ key: 'martial', label: '戦技', invested_points: 6, full_points: 100, nodes: [{
                    key: 'martial_dagger_flurry', label: '短剣乱舞', summary: '短剣・細剣で3回攻撃し、出血を狙う。',
                    type: 'active', rank: 1, max_rank: 1, point_cost: 6, invested_points_required: 15,
                    prerequisite: 'martial_precision_cut', can_acquire: false, unavailable_reason: '最大rankです',
                    skill_key: 'dagger_flurry', mp_cost: 1200, cooldown: 2, required_weapon_styles: ['dagger', 'rapier'] as string[], recommended_stats: ['might', 'finesse'] as string[], active_slot: null,
                }] },
                { key: 'guardianship', label: '護身', invested_points: 0, full_points: 100, nodes: [] },
                { key: 'miracle', label: '祝福', invested_points: 0, full_points: 100, nodes: [{
                    key: 'miracle_holy_bolt', label: '聖晶弾', summary: '精神を活かす祝福の単体攻撃。',
                    type: 'active', rank: 0, max_rank: 1, point_cost: 5, invested_points_required: 0,
                    prerequisite: null, can_acquire: true, unavailable_reason: null as string | null,
                    skill_key: 'holy_bolt', mp_cost: 700, cooldown: 0, required_weapon_styles: [] as string[], recommended_stats: ['spirit', 'finesse'] as string[], active_slot: null,
                }, {
                    key: 'miracle_spirit_channel', label: '精神導路', summary: '祝福与ダメージを強化する。',
                    type: 'passive', rank: 0, max_rank: 5, point_cost: 1, invested_points_required: 15,
                    prerequisite: null, can_acquire: false, unavailable_reason: '祝福へあと15SP必要',
                    skill_key: null, mp_cost: null, cooldown: null, required_weapon_styles: [] as string[], recommended_stats: null, active_slot: null,
                }, {
                    key: 'miracle_mending_prayer', label: '治癒祈祷', summary: '自身のHPを回復する。',
                    type: 'active', rank: 0, max_rank: 1, point_cost: 6, invested_points_required: 0,
                    prerequisite: 'miracle_holy_bolt', can_acquire: false, unavailable_reason: '前提skill未取得',
                    skill_key: 'mending_prayer', mp_cost: 1200, cooldown: 2, required_weapon_styles: [] as string[], recommended_stats: ['spirit'] as string[], active_slot: null,
                }, {
                    key: 'miracle_crystal_cycle', label: '輝石循環', summary: '1 actionを使って自身のMPを3000回復する。',
                    type: 'active', rank: 0, max_rank: 1, point_cost: 6, invested_points_required: 35,
                    prerequisite: 'miracle_mending_prayer', can_acquire: false, unavailable_reason: '祝福へあと35SP必要',
                    skill_key: 'crystal_cycle', mp_cost: 0, cooldown: 3, required_weapon_styles: [] as string[], recommended_stats: [] as string[], active_slot: null,
                }] }],
            active_slots: [null, null, null, null, null] as Array<{
                key: string; label: string; summary: string; mp_cost: number; cooldown: number; required_weapon_styles: string[];
            } | null>, passive_modifiers: {},
            equipment_summary: { used: 2, capacity: 500, equipped: { weapon: {
                id: 2, key: 'iron_longsword', name: '鉄の長剣', category: 'weapon', weapon_style: 'longsword',
                rank: 1, item_level: 1, rarity: 'common', buy_price: 120, sell_price: 60, equipped_slot: 'weapon',
                weapon_power: 31, physical_defense: 4, magical_defense: 0, max_hp: 0,
                stats: { vitality: 3, might: 2, finesse: 0, spirit: 0, agility: 0 },
            }, armor: null, accessory: null } },
            shopkeeper_name: '<b>店員</b>', true_name_branch: false,
            tutorial_projection: { stats: { vitality: 10, might: 10, finesse: 10, spirit: 10, agility: 10 }, weapon: 'starter knife' },
            contract_completed: true, growth_paths: null, growth_path: growthPath, playtest,
            default_hunting_ground_key: 'shallow_caves',
            hunting_grounds: [
                {
                    key: 'shallow_caves', name: '浅い洞窟', locked: false, unlock_condition: null,
                    item_level_min: 5, item_level_max: 30,
                },
                {
                    key: 'black_crystal_cave', name: '黒晶洞', locked: true,
                    unlock_condition: '試練1を初回clear', item_level_min: 30, item_level_max: 60,
                },
            ],
            trial: {
                key: 'trial_01', label: '地下に眠る古代遺跡', total_battles: 10,
                first_cleared: false,
                active_run: null as null | {
                    key: string; label: string; run_key: string; status: string;
                    next_battle_index: number; total_battles: number;
                },
            },
            battle: null,
            next_battle_at: null as string | null,
        };
        const summary = {
            id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
            player_display_name: '過去のペリドット',
            encounter_name: '<b>ジャイアントラット</b>', result: 'victory', rounds: 2,
            xp_awarded: 5, shard_delta: 0, detail_available: true, actions: null,
        };
        const historyBattles = [summary, ...Array.from({ length: 5 }, (_, index) => ({
            ...summary,
            id: `66666666-6666-4666-8666-66666666666${index + 2}`,
            encounter_name: `履歴${index + 2}`,
            context: index < 2 ? 'trial' : 'tutorial',
            result: index === 0 ? 'defeat' : index === 1 ? 'withdrawal' : 'victory',
            detail_available: index >= 2,
            trial_run_key: index < 2 ? '77777777-7777-4777-8777-777777777777' : null,
            trial_battle_index: index < 2 ? index + 3 : null,
            trial_total_battles: index < 2 ? 10 : null,
            trial_status: index === 0 ? 'defeated' : index === 1 ? 'withdrawn' : null,
            trial_next_battle_index: index < 2 ? 1 : null,
            interbattle_heal_amount: 0,
        }))];
        const playtestBattle = {
            id: '44444444-4444-4444-8444-444444444444', context: 'playtest',
            player_display_name: '過去のペリドット',
            build_name: '護身特化', encounter_name: '深層追跡者', result: 'victory', rounds_count: 2,
            xp_awarded: 0, shard_delta: 0, detail_available: true,
            summary: { damage_prevented: 120, final_mp: 9700 },
            rounds: [
                {
                    round: 1,
                    start_state: {
                        player: { hp: 660, max_hp: 660, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                        enemy: { hp: 200, max_hp: 200, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                    actions: [
                        { type: 'decision', side: '秘書', label: '防御', reason: 'priority_rule_0' },
                        { type: 'action', side: '秘書', actor_name: '過去のペリドット', label: '治癒祈祷' },
                        { type: 'mp_cost', side: '秘書', actor_name: '過去のペリドット', label: 'MP消費', amount: 1146 },
                        { type: 'role_stack_gain', side: '秘書', actor_name: '過去のペリドット', label: '増加: 恩寵', amount: 1 },
                        { type: 'recovery', side: '秘書', actor_name: '過去のペリドット', target_name: '過去のペリドット', label: '治癒祈祷', amount: 84 },
                        { type: 'counter', side: '対戦相手', actor_name: '深層追跡者', target_name: '過去のペリドット', label: '反撃', amount: 7 },
                        { type: 'mp_recovery', side: '秘書', actor_name: '過去のペリドット', label: 'MP回復', amount: 3000 },
                    ],
                    end_state: {
                        player: { hp: 650, max_hp: 660, mp: 9700, barrier: 40, statuses: [], role_stacks: { fighting_spirit: 1, grace: 0 } },
                        enemy: { hp: 100, max_hp: 200, mp: 0, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                },
                {
                    round: 2,
                    start_state: {
                        player: { hp: 650, max_hp: 660, mp: 9700, barrier: 40, statuses: [], role_stacks: { fighting_spirit: 1, grace: 0 } },
                        enemy: { hp: 100, max_hp: 200, mp: 0, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                    actions: [
                        {
                            type: 'status_applied', side: '秘書', actor_name: '過去のペリドット',
                            target_name: '深層追跡者', label: '付与: 出血', amount: 0,
                        },
                        {
                            type: 'status_resisted', side: '対戦相手', actor_name: '深層追跡者',
                            target_name: '過去のペリドット', label: '抵抗: 鈍足', amount: 0,
                        },
                    ],
                    end_state: {
                        player: { hp: 640, max_hp: 660, mp: 9700, barrier: 20, statuses: [], role_stacks: { fighting_spirit: 2, grace: 1 } },
                        enemy: { hp: 0, max_hp: 200, mp: 0, barrier: 0, statuses: [{ label: '出血', remaining: 1, stacks: 1 }], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    },
                },
            ],
            rewards: { xp: 0, shards: 0, g: 0, drops: [] },
        };
        const explorationBattle = {
            id: '55555555-5555-4555-8555-555555555555', context: 'exploration',
            player_display_name: 'ペリドット', encounter_name: '輝石虫', result: 'victory',
            rounds_count: 1, xp_awarded: 1150, shard_delta: 0, detail_available: true,
            combat_level_before: 1, combat_level_after: 6, stp_awarded: 25, unspent_stp_after: 25,
            summary: { damage_dealt: 1, damage_received: 0 },
            rounds: [{
                round: 1,
                actions: [
                    { type: 'damage', side: '秘書', actor_name: 'ペリドット', target_name: '輝石虫', label: '通常攻撃', amount: 0, complete_guarded: true },
                    { type: 'damage', side: '秘書', actor_name: 'ペリドット', target_name: '輝石虫', label: '通常攻撃', amount: 1, complete_guarded: false },
                ],
                end_state: {
                    player: { hp: 660, max_hp: 660, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                    enemy: { hp: 0, max_hp: 1, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                },
            }],
            rewards: { xp: 1150, shards: 0 },
            hunting_ground: {
                key: 'shallow_caves', name: '浅い洞窟',
                content_identity: 'secretary-underground-exploration-alpha-v1',
                item_level_min: 5, item_level_max: 30,
            },
            drop: {
                identity: 'secretary-underground-exploration-drop-alpha-v1',
                status: 'granted',
                item: {
                    instance_identity: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    name: '浅層の短剣', category: 'weapon', item_level: 15,
                    rarity: 'common', rarity_label: 'レギュラー',
                    affixes: [{
                        key: 'miracle_damage_bps', label: '魔法攻撃力アップ',
                        target: 'miracle_damage_bps', value: 250,
                    }],
                },
            },
        };
        const blackCrystalExplorationBattle = {
            ...explorationBattle,
            id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            encounter_name: '黒晶虫',
            hunting_ground: {
                key: 'black_crystal_cave', name: '黒晶洞',
                content_identity: 'secretary-underground-black-crystal-cave-alpha-v1',
                item_level_min: 30, item_level_max: 60,
            },
            drop: {
                ...explorationBattle.drop,
                item: {
                    ...explorationBattle.drop.item,
                    instance_identity: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                    name: '黒晶の短剣', item_level: 50,
                },
            },
        };
        const trialRun = {
            key: 'trial_01', label: '地下に眠る古代遺跡',
            run_key: '77777777-7777-4777-8777-777777777777', status: 'active',
            next_battle_index: 1, total_battles: 10,
        };
        const interbattleTrialBattle = {
            ...explorationBattle,
            id: '77777777-7777-4777-8777-777777777778', context: 'trial',
            encounter_name: '試練の地底鼠', xp_awarded: 35, shard_delta: 10,
            combat_level_before: 1, combat_level_after: 1, stp_awarded: 0, unspent_stp_after: 3,
            trial_run_key: trialRun.run_key, trial_battle_index: 1, trial_total_battles: 10,
            trial_status: 'active', trial_next_battle_index: 2, interbattle_heal_amount: 80,
            hunting_ground: null, drop: null,
            challenge_intro: '　崩れかけた石壁の向こうに広がっていた不思議な空間。\n　土と岩に埋もれたそこは、明らかに人の手で造られた古い石造りの遺跡であった。\n　入り口からは生暖かい風が吹いている……そこが魔物の巣窟であることは、明らかであった。',
            first_clear_story: null,
        };
        const trialBattle = {
            ...explorationBattle,
            id: '88888888-8888-4888-8888-888888888888', context: 'trial',
            encounter_name: 'ワイバーン', xp_awarded: 370, shard_delta: 69,
            rounds_count: 40,
            rounds: [
                {
                    round: 20,
                    actions: [{ type: 'warning', side: 'system', label: '洞窟が崩れそうだ……', amount: 0 }],
                    end_state: null,
                },
                {
                    round: 40,
                    actions: [
                        { type: 'damage', side: '秘書', actor_name: 'ペリドット', target_name: 'ワイバーン', label: '通常攻撃', amount: 420, agility_combo_hits: 2 },
                        { type: 'phase_transition', side: '対戦相手', actor_name: 'ワイバーン', label: '天井が崩落し、ワイバーンは宙に舞い上がる……！', amount: 0 },
                    ],
                    end_state: null,
                },
            ],
            combat_level_before: 6, combat_level_after: 6, stp_awarded: 0, unspent_stp_after: 25,
            trial_run_key: trialRun.run_key, trial_battle_index: 10, trial_total_battles: 10,
            trial_status: 'cleared', trial_next_battle_index: 1, interbattle_heal_amount: 0,
            hunting_ground: null, drop: null,
            challenge_intro: null,
            first_clear_story: {
                title: '●封印の解放',
                body: '　ワイバーンの肉体が自らの魔力に耐え切れず、内から光を放ちながら崩壊していくその瞬間。',
                system_messages: ['ペリドットは一つ目の封印の地を制覇した。', 'SPを40入手した。'],
            },
        };
        const repeatTrialBattle = {
            ...trialBattle,
            id: '99999999-9999-4999-8999-999999999999',
            challenge_intro: null,
            first_clear_story: null,
        };
        let battleDetailGets = 0;
        let explorationAttempts = 0;
        let trialFightAttempts = 0;
        let innAttempts = 0;
        let bankTransferAttempts = 0;
        let releaseInnRetry!: () => void;
        const innRetryGate = new Promise<void>((resolve) => { releaseInnRetry = resolve; });
        const explorationResults = new Map<string, typeof explorationBattle>();
        const innResults = new Map<string, typeof openState>();
        const bankTransferResults = new Map<string, typeof openState>();
        const stpPayloads: Array<{ request_id: string; allocations: Record<string, number> }> = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/underground') return response(openState);
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') return response(openState);
            if (path === '/api/v1/me/underground/trial/start' && init?.method === 'POST') {
                openState = { ...openState, trial: { ...openState.trial, active_run: trialRun } };
                return response(trialRun);
            }
            if (path === '/api/v1/me/underground/trial/fight' && init?.method === 'POST') {
                trialFightAttempts++;
                if (trialFightAttempts <= 2) {
                    openState = {
                        ...openState,
                        current_hp: 280,
                        trial: {
                            ...openState.trial,
                            active_run: { ...trialRun, next_battle_index: 2 },
                        },
                    };
                    if (trialFightAttempts === 1) throw new TypeError('Trial response lost');
                    return response(interbattleTrialBattle);
                }
                if (trialFightAttempts === 4) {
                    openState = { ...openState, trial: { ...openState.trial, active_run: null } };
                    return new Response(JSON.stringify({
                        code: 'underground_trial_run_stale',
                        message: '封印の地の進行状態が更新されています。',
                    }), { status: 409, headers: { 'Content-Type': 'application/json' } });
                }
                openState = {
                    ...openState,
                    current_hp: 321,
                    skill_points_total: 60,
                    skill_points_unspent: 60,
                    hunting_grounds: openState.hunting_grounds.map((ground) => (
                        ground.key === 'black_crystal_cave' ? { ...ground, locked: false } : ground
                    )),
                    trial: { ...openState.trial, first_cleared: true, active_run: null },
                };
                return response(trialFightAttempts === 5 ? repeatTrialBattle : trialBattle);
            }
            if (path === '/api/v1/me/underground/explore' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as {
                    request_id: string;
                    hunting_ground_key: string;
                };
                const duplicate = explorationResults.get(payload.request_id);
                if (duplicate) return response(duplicate);
                const battle = payload.hunting_ground_key === 'black_crystal_cave'
                    ? blackCrystalExplorationBattle
                    : explorationBattle;
                explorationResults.set(payload.request_id, battle);
                explorationAttempts++;
                openState = {
                    ...openState,
                    next_battle_at: explorationAttempts === 3
                        ? new Date(Date.now() + 10_000).toISOString()
                        : null,
                };
                if (explorationAttempts === 1 || explorationAttempts === 3) {
                    throw new TypeError('Explore response lost');
                }
                return response(battle);
            }
            if (path === '/api/v1/me/underground/inn/rest' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as { request_id: string };
                const duplicate = innResults.get(payload.request_id);
                if (duplicate) {
                    await innRetryGate;
                    openState = duplicate;
                    return response(openState);
                }
                openState = { ...openState, shard_balance: openState.shard_balance - 10, current_hp: growthPath.max_hp };
                innResults.set(payload.request_id, openState);
                innAttempts++;
                if (innAttempts === 1) throw new TypeError('Inn response lost');
                return response(openState);
            }
            if (path === '/api/v1/me/underground/bank/transfer' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as {
                    request_id: string;
                    action: string;
                    amount?: number;
                };
                const duplicate = bankTransferResults.get(payload.request_id);
                if (duplicate) {
                    openState = duplicate;
                    return response(openState);
                }
                const amount = payload.action === 'deposit_all'
                    ? openState.shard_balance
                    : payload.action === 'withdraw_all'
                        ? openState.banked_shard_balance
                        : payload.amount ?? 0;
                const deposit = payload.action.startsWith('deposit');
                openState = {
                    ...openState,
                    shard_balance: openState.shard_balance + (deposit ? -amount : amount),
                    banked_shard_balance: openState.banked_shard_balance + (deposit ? amount : -amount),
                };
                bankTransferResults.set(payload.request_id, openState);
                bankTransferAttempts++;
                if (bankTransferAttempts === 1) throw new TypeError('Failed to fetch');
                return response(openState);
            }
            if (path === '/api/v1/me/underground/status/stp' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as {
                    request_id: string;
                    allocations: Record<string, number>;
                };
                stpPayloads.push(payload);
                const vitality = payload.allocations.vitality ?? 0;
                const allocatedVitality = openState.allocated_stp.vitality + vitality;
                openState = {
                    ...openState,
                    unspent_stp: openState.unspent_stp - vitality,
                    allocated_stp: { ...openState.allocated_stp, vitality: allocatedVitality },
                    status_breakdown: {
                        ...openState.status_breakdown,
                        vitality: {
                            baseline: 40,
                            natural_growth: 0,
                            allocated_stp: allocatedVitality,
                            equipment: 1,
                            final: 41 + allocatedVitality,
                        },
                    },
                };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/skills/acquire' && init?.method === 'POST') {
                openState = {
                    ...openState,
                    skill_points_unspent: 15,
                    skill_points_spent: 5,
                    skill_trees: openState.skill_trees.map((tree) => tree.key !== 'miracle' ? tree : {
                        ...tree,
                        invested_points: 5,
                        nodes: tree.nodes.map((node) => ({ ...node, rank: 1, can_acquire: false, unavailable_reason: '最大rankです' })),
                    }),
                };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/skills/loadout' && init?.method === 'PUT') {
                openState = {
                    ...openState,
                    active_slots: [{ key: 'holy_bolt', label: '聖晶弾', summary: '精神を活かす祝福の単体攻撃。', mp_cost: 700, cooldown: 0, required_weapon_styles: [] }, null, null, null, null],
                };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/playtest' && init?.method === 'POST') return response(playtestBattle);
            if (path === '/api/v1/me/underground/battles') return response(historyBattles);
            if (path === `/api/v1/me/underground/battles/${summary.id}`) {
                battleDetailGets++;
                if (battleDetailGets > 1) {
                    return new Response(JSON.stringify({ message: '戦闘ログを読み込めませんでした。' }), {
                        status: 500,
                        headers: { 'Content-Type': 'application/json' },
                    });
                }
                return response({
                    ...summary,
                    actions: [{ round: 1, side: 'player', action: 'quick_slash', action_label: '連続斬り', amount: 90 }],
                });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        window.localStorage.setItem('hakoniwa.underground.selected-hunting-ground', 'black_crystal_cave');
        const wrapper = mount(App, { attachTo: document.body });
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('内政Lv');
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('戦闘Lv1');
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();

        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('戦闘Lv1');
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('経験値5 / 100');
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('戦闘開始MP10000 / 10000');
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('銀行預金5000 G');
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('未使用STP3');
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-equipment').text()).toContain('武器鉄の長剣');
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get('.underground-explore-button').attributes('disabled')).toBeUndefined();
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get('.underground-explore-button').text()).toContain('探索する');
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get<HTMLSelectElement>('.underground-ground-selector').element.value).toBe('shallow_caves');
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get('.underground-ground-selector').text()).toContain('浅い洞窟');
        expect(window.localStorage.getItem('hakoniwa.underground.selected-hunting-ground')).toBe('shallow_caves');
        await openUndergroundView(wrapper, '冒険', '試練');
        expect(wrapper.get('.underground-trial-entry').attributes('disabled')).toBeUndefined();
        await openUndergroundView(wrapper, '冒険', '試練');
        expect(wrapper.get('.underground-trial-entry').text()).toContain('試練を開始');
        await openUndergroundView(wrapper, '冒険', '試練');
        expect(wrapper.get('select[aria-label="試練を選択"]').text()).toContain('地下に眠る古代遺跡');
        await openUndergroundView(wrapper, '冒険', '試練');
        await wrapper.get('.underground-trial-entry').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Trial response lost');
        await openUndergroundView(wrapper, '冒険', '試練');
        await wrapper.get('.underground-trial-entry').trigger('click');
        await flushPromises();
        const trialStartRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/trial/start' && init?.method === 'POST'
        ));
        expect(trialStartRequests).toHaveLength(1);
        const trialFightRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/trial/fight' && init?.method === 'POST'
        ));
        expect(trialFightRequests).toHaveLength(2);
        expect(JSON.parse(String(trialFightRequests[1]?.[1]?.body)))
            .toEqual(JSON.parse(String(trialFightRequests[0]?.[1]?.body)));
        expect(wrapper.get('.underground-trial-intro').text()).toContain('崩れかけた石壁の向こう');
        expect(wrapper.get('.underground-interbattle-heal').text()).toBe('体力が少し回復した');
        expect(wrapper.get('.underground-trial-next').text()).toContain('次の階層へ');
        await wrapper.get('.underground-trial-next').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/trial/fight' && init?.method === 'POST'
        ))).toHaveLength(3);
        expect(wrapper.find('.underground-trial-next').exists()).toBe(false);
        expect(wrapper.find('.underground-interbattle-heal').exists()).toBe(false);
        expect(wrapper.get('.underground-first-clear-story h2').text()).toBe('●封印の解放');
        expect(wrapper.get('.underground-first-clear-results').text()).toContain('ペリドットは一つ目の封印の地を制覇した。');
        expect(wrapper.get('.underground-first-clear-results').text()).toContain('SPを40入手した。');
        expect(wrapper.findAll('.underground-action-log .is-warning')).toHaveLength(2);
        expect(wrapper.findAll('.underground-action-log .is-warning')[0]!.text()).toContain('洞窟が崩れそうだ……');
        expect(wrapper.findAll('.underground-action-log .is-warning')[1]!.text()).toContain('天井が崩落し、ワイバーンは宙に舞い上がる……！');
        expect(wrapper.findAll('.underground-agility-combo')).toHaveLength(1);
        expect(wrapper.get('.underground-agility-combo').text()).toBe('2連続ヒット！');
        expect(wrapper.get('.underground-agility-combo').element.parentElement?.textContent).toContain('ワイバーンに420ダメージ。');
        expect(wrapper.get('.underground-battle-log').text().indexOf('崩れかけた石壁の向こう'))
            .toBeLessThan(wrapper.get('.underground-battle-log').text().indexOf('遭遇'));
        expect(wrapper.get('.underground-battle-log').text().indexOf('戦闘終了'))
            .toBeLessThan(wrapper.get('.underground-battle-log').text().indexOf('封印の解放'));
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get<HTMLSelectElement>('.underground-ground-selector').setValue('black_crystal_cave');
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get<HTMLSelectElement>('.underground-ground-selector').element.selectedOptions[0]?.text).toBe('黒晶洞');
        expect(window.localStorage.getItem('hakoniwa.underground.selected-hunting-ground')).toBe('black_crystal_cave');
        await openUndergroundView(wrapper, '冒険', '試練');
        await wrapper.get('.underground-trial-entry').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('封印の地の進行状態が更新されています。');
        await openUndergroundView(wrapper, '冒険', '試練');
        await wrapper.get('.underground-trial-entry').trigger('click');
        await flushPromises();
        const recoveredTrialStarts = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/trial/start' && init?.method === 'POST'
        ));
        expect(recoveredTrialStarts).toHaveLength(3);
        const recoveredTrialFights = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/trial/fight' && init?.method === 'POST'
        ));
        expect(recoveredTrialFights).toHaveLength(5);
        expect(JSON.parse(String(recoveredTrialFights[4]?.[1]?.body)).request_id)
            .not.toBe(JSON.parse(String(recoveredTrialFights[3]?.[1]?.body)).request_id);
        expect(wrapper.find('.underground-first-clear-story').exists()).toBe(false);
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, '冒険', '戦闘履歴');
        expect(wrapper.get('.underground-history').text()).toContain('履歴5');
        expect(wrapper.get('.underground-history').text()).not.toContain('履歴6');
        await wrapper.findAll('.underground-history li button')[1]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-result h2').text()).toBe('敗北');
        expect(wrapper.find('.underground-trial-next').exists()).toBe(false);
        expect(wrapper.find('.underground-interbattle-heal').exists()).toBe(false);
        await wrapper.get('.underground-battle-back').trigger('click');
        await wrapper.findAll('.underground-history li button')[2]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-result h2').text()).toBe('撤退');
        expect(wrapper.find('.underground-trial-next').exists()).toBe(false);
        expect(wrapper.find('.underground-interbattle-heal').exists()).toBe(false);
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, 'キャラクター', 'STP配分');
        expect(wrapper.get('.underground-status-table').text()).toContain('初期値');
        const vitalityStp = wrapper.get<HTMLInputElement>('.underground-stp-control input');
        expect(vitalityStp.attributes('max')).toBe('3');
        await vitalityStp.setValue('4');
        expect(vitalityStp.element.value).toBe('3');
        expect(wrapper.get('.underground-progression-panel .button.primary').text()).toBe('3 STPを一括確定');
        await wrapper.get('.underground-progression-panel .button.primary').trigger('click');
        await flushPromises();
        expect(stpPayloads).toHaveLength(1);
        expect(stpPayloads[0]!.allocations).toEqual({ vitality: 3 });
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('未使用STP0');
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        expect(wrapper.findAll('.underground-progression-note').map((note) => note.text()).join(' '))
            .toContain('SPを消費することでスキルを習得できます');
        expect(wrapper.get('.underground-skill-jump').text()).toBe('アクティブスキル設定へ');
        expect(wrapper.find('#underground-active-loadout').exists()).toBe(true);
        await wrapper.get('.underground-skill-jump').trigger('click');
        expect(document.activeElement).toBe(wrapper.get('#underground-loadout-title').element);
        expect(wrapper.get('#skill-tab-martial').attributes('aria-selected')).toBe('true');
        await wrapper.get('#skill-tab-miracle').trigger('click');
        expect(wrapper.get('#skill-tab-miracle').attributes('aria-selected')).toBe('true');
        expect(wrapper.find('#skill-panel-martial').exists()).toBe(false);
        await wrapper.get('#skill-node-miracle_mending_prayer').trigger('click');
        expect(wrapper.get('.skill-detail').text()).toContain('前提');
        expect(wrapper.get('.skill-detail button').attributes('disabled')).toBeDefined();
        await wrapper.get('#skill-node-miracle_holy_bolt').trigger('click');
        expect(wrapper.get('.skill-detail').text()).toContain('精神');
        await wrapper.get('.skill-detail button').trigger('click');
        await flushPromises();
        await wrapper.get('.underground-loadout-grid select').setValue('holy_bolt');
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await wrapper.get('.underground-active-loadout .button.primary').trigger('click');
        await flushPromises();
        const loadoutCall = fetchMock.mock.calls.find(([path, init]) => String(path) === '/api/v1/me/underground/skills/loadout' && init?.method === 'PUT');
        expect(JSON.parse(String(loadoutCall?.[1]?.body)).slots).toEqual(['holy_bolt', null, null, null, null]);
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get<HTMLSelectElement>('.underground-ground-selector').setValue('shallow_caves');
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Explore response lost');
        const failedExplorationRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/explore' && init?.method === 'POST'
        ));
        expect(failedExplorationRequests).toHaveLength(1);
        const failedExplorationPayload = JSON.parse(String(failedExplorationRequests[0]?.[1]?.body)) as {
            request_id: string;
            hunting_ground_key: string;
        };
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get<HTMLSelectElement>('.underground-ground-selector').setValue('black_crystal_cave');
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        const explorationRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/explore' && init?.method === 'POST'
        ));
        expect(explorationRequests).toHaveLength(2);
        const recoveredPayload = JSON.parse(String(explorationRequests[1]?.[1]?.body)) as {
            request_id: string;
            hunting_ground_key: string;
            borrowed_secretary_ids: number[];
        };
        expect(recoveredPayload).toEqual({
            request_id: failedExplorationPayload.request_id,
            hunting_ground_key: 'shallow_caves',
            borrowed_secretary_ids: [],
        });
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, '冒険', '探索');
        await wrapper.get('.underground-explore-button').trigger('click');
        await flushPromises();
        const changedGroundRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/explore' && init?.method === 'POST'
        ));
        expect(changedGroundRequests).toHaveLength(3);
        const changedGroundPayload = JSON.parse(String(changedGroundRequests[2]?.[1]?.body)) as {
            request_id: string;
            hunting_ground_key: string;
            borrowed_secretary_ids: number[];
        };
        expect(changedGroundPayload).toEqual({
            request_id: expect.any(String),
            hunting_ground_key: 'black_crystal_cave',
            borrowed_secretary_ids: [],
        });
        expect(changedGroundPayload.request_id).not.toBe(failedExplorationPayload.request_id);
        const explorationLog = wrapper.get('.underground-battle-log').text();
        expect(explorationLog).toContain('輝石虫は完全防御し、HPダメージは0。');
        expect(explorationLog.indexOf('Round 1')).toBeLessThan(explorationLog.indexOf('戦闘終了'));
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +1150・輝石の欠片 +0G');
        expect(wrapper.get('.underground-battle-result').text()).toContain('狩場: 黒晶洞');
        expect(wrapper.get('.underground-equipment-drop').text()).toContain('レギュラー・Item Lv 50・黒晶の短剣');
        expect(wrapper.get('.underground-equipment-drop').text()).toContain('魔法攻撃力アップ');
        expect(wrapper.get('.underground-level-up').attributes('role')).toBe('status');
        expect(wrapper.get('.underground-level-up strong').text()).toBe('LEVEL UP！');
        expect(wrapper.get('.underground-level-up').text()).toContain('戦闘Lv 1 → 6');
        expect(wrapper.get('.underground-level-up').text()).toContain('未使用STP +25（合計 25）');
        expect(wrapper.get('.underground-exploration-repeat').text()).toContain('もう一度ここを探索する');
        await wrapper.get('.underground-exploration-repeat').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Explore response lost');
        await wrapper.get('.underground-exploration-repeat').trigger('click');
        await flushPromises();
        const repeatedExplorationRequests = fetchMock.mock.calls.filter(([path, init]) => (
            String(path) === '/api/v1/me/underground/explore' && init?.method === 'POST'
        ));
        expect(repeatedExplorationRequests).toHaveLength(5);
        const repeatPayload = JSON.parse(String(repeatedExplorationRequests[3]?.[1]?.body)) as {
            request_id: string;
            hunting_ground_key: string;
            borrowed_secretary_ids: number[];
        };
        expect(repeatPayload).toEqual({
            request_id: expect.any(String),
            hunting_ground_key: 'black_crystal_cave',
            borrowed_secretary_ids: [],
        });
        expect(repeatPayload.request_id).not.toBe(changedGroundPayload.request_id);
        expect(JSON.parse(String(repeatedExplorationRequests[4]?.[1]?.body))).toEqual(repeatPayload);
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get<HTMLSelectElement>('.underground-ground-selector').element.value).toBe('black_crystal_cave');
        await openUndergroundView(wrapper, '冒険', '探索');
        const exploreButton = wrapper.get('.underground-explore-button');
        expect(exploreButton.attributes('disabled')).toBeDefined();
        expect(exploreButton.element.parentElement?.textContent).toMatch(/あと(?:9|10)秒/);
        await openUndergroundView(wrapper, 'ショップ');
        expect(wrapper.get('.ug-shop-rest').text()).toContain('あなたのコンビニ、箱庭ダンジョン店です！');
        const innButton = wrapper.findAll('.ug-shop-rest button')
            .find((button) => button.text().includes('宿で休む'));
        expect(innButton).toBeDefined();
        expect(innButton!.attributes('disabled')).toBeUndefined();
        await innButton!.trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Inn response lost');
        const failedInnRequests = fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/underground/inn/rest'
        ));
        expect(failedInnRequests).toHaveLength(1);
        const failedInnPayload = JSON.parse(String(failedInnRequests[0]?.[1]?.body)) as { request_id: string };
        await innButton!.trigger('click');
        expect(innButton!.attributes('disabled')).toBeDefined();
        expect(innButton!.text()).toContain('休憩中…');
        releaseInnRetry();
        await flushPromises();
        await openUndergroundView(wrapper, 'ショップ');
        expect(wrapper.get('.ug-shop-rest').text()).toContain('いい夢は見られましたか？　それじゃ、頑張ってくださいね！');
        await openUndergroundView(wrapper, 'ショップ');
        expect(wrapper.get('.ug-shop-rest [role=status]').text()).toBe('HPが全回復しました。');
        await openUndergroundView(wrapper, '冒険', '力試し');
        await wrapper.get('.underground-playtest .button').trigger('click');
        await flushPromises();
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, 'ショップ');
        expect(wrapper.get('.ug-shop-rest').text()).toContain('あなたのコンビニ、箱庭ダンジョン店です！');
        expect(wrapper.find('.underground-inn-result').exists()).toBe(false);
        const innRequests = fetchMock.mock.calls.filter(([path]) => String(path) === '/api/v1/me/underground/inn/rest');
        expect(innRequests).toHaveLength(2);
        expect(JSON.parse(String(innRequests[1]?.[1]?.body))).toEqual({
            request_id: failedInnPayload.request_id,
        });
        const bankButton = wrapper.findAll('.ug-tabs button').find(button => button.text() === '銀行');
        expect(bankButton).toBeDefined();
        await bankButton!.trigger('click');
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 2340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 5000 G');
        await wrapper.get('#underground-bank-amount').setValue('2000');
        await wrapper.findAll('.underground-bank .underground-shop-entries button')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Failed to fetch');
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 2340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 5000 G');
        const failedBankRequest = fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/underground/bank/transfer'
        ));
        expect(failedBankRequest).toHaveLength(1);
        const failedBankPayload = JSON.parse(String(failedBankRequest[0]?.[1]?.body)) as {
            request_id: string;
            action: string;
            amount: number;
        };
        await wrapper.findAll('.underground-bank .underground-shop-entries button')[0]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-bank').text()).toContain('手持ち: 340 G');
        expect(wrapper.get('.underground-bank').text()).toContain('預金: 7000 G');
        const bankRequests = fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/underground/bank/transfer'
        ));
        expect(bankRequests).toHaveLength(2);
        expect(JSON.parse(String(bankRequests[1]?.[1]?.body))).toEqual({
            request_id: failedBankPayload.request_id, action: 'deposit', amount: 2000,
        });
        await openUndergroundView(wrapper, '冒険', '戦闘履歴');
        const historyButton = wrapper.get('.underground-history li button');
        expect(historyButton.text()).toContain('<b>ジャイアントラット</b>');
        expect(historyButton.find('b').exists()).toBe(false);
        await historyButton.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-opening').text()).toContain('<b>ジャイアントラット</b>');
        expect(wrapper.get('.underground-battle-opening').text()).toContain('過去のペリドットは戦闘を開始した。');
        expect(wrapper.get('.underground-battle-opening').text()).not.toContain('勝利');
        expect(wrapper.get('.underground-battle-log').text()).toContain('連続斬り');
        expect(wrapper.get('.underground-battle-log').text()).not.toContain('quick_slash');
        expect(wrapper.get('.underground-battle-result').text()).toContain('勝利');
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +5・輝石の欠片 +0');
        expect(wrapper.get('.underground-log-jump').text()).toBe('末尾へ');
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, 'ショップ');
        expect(wrapper.get('.ug-shop-rest').text()).toContain('あなたのコンビニ、箱庭ダンジョン店です！');
        expect(wrapper.find('.underground-inn-result').exists()).toBe(false);
        await openUndergroundView(wrapper, '冒険', '戦闘履歴');
        await wrapper.get('.underground-history li button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('戦闘ログを読み込めませんでした。');
        expect(wrapper.get('.underground-battle-log').text()).toContain('<b>ジャイアントラット</b>');
        await wrapper.get('.underground-battle-back').trigger('click');
        await openUndergroundView(wrapper, '冒険', '力試し');
        expect(wrapper.get('.underground-playtest').text()).toContain('正式な育成・装備状態ではありません');
        expect(wrapper.get<HTMLSelectElement>('#underground-build').element.value).toBe('pure_tank');
        await openUndergroundView(wrapper, '冒険', '力試し');
        await wrapper.get('.underground-playtest .button').trigger('click');
        await flushPromises();
        const playtestRequest = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/underground/playtest' && init?.method === 'POST'
        ));
        expect(JSON.parse(String(playtestRequest?.[1]?.body))).toEqual({
            request_id: expect.any(String), build_key: 'pure_tank', enemy_key: 'depth_stalker',
        });
        expect(wrapper.get('.underground-battle-log').text()).toContain('過去のペリドットは「防御」を使用した。');
        expect(wrapper.get('.underground-battle-log').text()).not.toContain('priority_rule_0');
        expect(wrapper.get('.underground-combat-summary').text()).toContain('防いだダメージ120');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('ラウンド数');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('残HP');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('最終MP');
        expect(wrapper.findAll('.underground-matchup .underground-matchup-card')).toHaveLength(2);
        expect(wrapper.findAll('.underground-matchup .underground-vitals progress.hp')).toHaveLength(2);
        expect(wrapper.findAll('.underground-matchup .underground-vitals progress.mp')).toHaveLength(2);
        expect(wrapper.findAll('.underground-round-state')).toHaveLength(0);
        expect(wrapper.findAll('.underground-round-start')).toHaveLength(2);
        expect(wrapper.findAll('.underground-round-start')[0]!.text()).toContain('HP 660');
        expect(wrapper.findAll('.underground-round-start')[0]!.text()).toContain('HP 200');
        expect(wrapper.findAll('.underground-round-start')[1]!.text()).toContain('HP 100');
        const firstRoundLog = wrapper.findAll('.underground-round')[0]!;
        expect(firstRoundLog.findAll('.underground-action-log > li')).toHaveLength(4);
        expect(firstRoundLog.get('.underground-action-cost').text()).toBe('MP −1,146');
        expect(firstRoundLog.get('.underground-action-details').findAll('p').map((line) => line.text())).toEqual([
            '過去のペリドットは「治癒祈祷」を使用した。',
            '過去のペリドットはMPを1146消費した。',
            '過去のペリドットの恩寵が1増加した。',
            '過去のペリドットは「治癒祈祷」で過去のペリドットのHPを84回復した。',
        ]);
        expect(firstRoundLog.get('[data-action-type="counter"]').text()).toContain('7ダメージ');
        expect(firstRoundLog.get('.is-support').text()).toContain('MPを3000回復');
        expect(firstRoundLog.get('.is-support').text()).not.toContain('自然');
        expect(wrapper.findAll('.underground-round')).toHaveLength(2);
        expect(wrapper.findAll('.underground-round')[0]!.text()).toContain('第1ラウンド 開始');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('第2ラウンド（前ラウンド終了時）');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('深層追跡者に出血が付与された。');
        expect(wrapper.findAll('.underground-round')[1]!.text()).toContain('過去のペリドットは鈍足を防いだ。');
        expect(wrapper.findAll('.underground-final-state .underground-matchup-card')[0]!.get('h2').text()).toBe('過去のペリドット');
        expect(wrapper.findAll('.underground-final-state .underground-matchup-card')[0]!.text()).not.toContain('状態 なし');
        expect(wrapper.findAll('.underground-final-state .underground-matchup-card')[0]!.text()).toContain('闘志 2');
        expect(wrapper.findAll('.underground-final-state .underground-matchup-card')[0]!.text()).toContain('恩寵 1');
        expect(wrapper.findAll('.underground-final-state .underground-matchup-card')[1]!.text()).toContain('HP 0');
        expect(wrapper.get('.underground-combat-details').attributes('open')).toBeUndefined();
        expect(wrapper.find('.underground-round-viewer').exists()).toBe(false);
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +0・輝石の欠片 +0G・ドロップなし');
        wrapper.unmount();
        const restoredWrapper = mount(UndergroundPanel);
        await flushPromises();
        await openUndergroundView(restoredWrapper, '冒険', '探索');
        expect(restoredWrapper.get<HTMLSelectElement>('.underground-ground-selector').element.value)
            .toBe('black_crystal_cave');
        expect(restoredWrapper.get<HTMLSelectElement>('.underground-ground-selector').element.selectedOptions[0]?.text).toBe('黒晶洞');
        restoredWrapper.unmount();
    });

    it('renders and saves the unlocked awakening gauge technique and plain-text battle event', async () => {
        const growthPath = {
            key: 'free_black', label: '自由', color: 'black', description: ['自由型'], default_build_key: 'balanced',
            stats: { vitality: 20, might: 20, finesse: 20, spirit: 20, agility: 20 },
            max_hp: 500, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 1, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: 6, points_per_level: 10,
        };
        let state = {
            stage: 'underground_open', secretary_name: '表示秘書', combat_level: 1,
            combat_xp: 0, next_level_xp: 100, next_level_requirement: 100, xp_to_next_level: 100,
            shard_balance: 0, banked_shard_balance: 0, current_hp: 500, unspent_stp: 0,
            allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 },
            current_stats: growthPath.stats, combat_stats: growthPath.stats, status_breakdown: null,
            equipment: null, equipment_summary: null,
            skill_points_total: 20, skill_points_unspent: 20, skill_points_spent: 0,
            skill_tree_identity: 'secretary-underground-skill-tree-alpha-v2', skill_trees: [],
            active_slots: [null, null, null, null, null], passive_modifiers: {},
            shopkeeper_name: '案内係', true_name_branch: false,
            tutorial_projection: { stats: growthPath.stats, weapon: 'starter knife' },
            contract_completed: true, growth_paths: null, growth_path: growthPath, playtest: null,
            trial: { key: 'trial_01', label: '地下に眠る古代遺跡', total_battles: 10, first_cleared: true, active_run: null },
            awakening: {
                identity: 'secretary-underground-awakening-v2', unlocked: true, current: 1000, maximum: 1000,
                custom_message: '<b>{secretary_name}</b>、限界突破！' as string | null,
                default_message: '魔力が{secretary_name}の全身を駆け巡る――！',
                technique: {
                    key: 'limitless_reprise', name: '無窮再演',
                    summary: 'MPを全回復し、通常active skillのcooldownを全解除。そのまま行動。',
                    consumes_action: false,
                },
                techniques: [{
                    key: 'limitless_reprise', name: '無窮再演',
                    summary: 'MPを全回復し、通常active skillのcooldownを全解除。そのまま行動。',
                    consumes_action: false,
                }, {
                    key: 'formless_strike', name: '無相の一撃',
                    summary: '技巧100%を使い、enemyのphysical・magical defenseの低い側を参照するdirect attack。',
                    consumes_action: true,
                }],
                selected_technique_key: 'limitless_reprise',
            },
            battle: null, next_battle_at: null,
        };
        const battle = {
            id: '77777777-7777-4777-8777-777777777777', context: 'exploration',
            player_display_name: '表示秘書', encounter_name: '迷い人の影', result: 'victory',
            rounds_count: 1, xp_awarded: 0, shard_delta: 0, detail_available: true,
            summary: { result: 'victory', awakening_triggered: true, awakening_technique_used: true },
            rounds: [{
                round: 1,
                start_state: {
                    player: {
                        hp: 500, max_hp: 500, mp: 10000, barrier: 0, statuses: [],
                        role_stacks: { fighting_spirit: 0, grace: 0 }, awakened: false,
                        awakening_unlocked: true, awakening_gauge: 1000, awakening_gauge_max: 1000,
                    },
                    enemy: { hp: 100, max_hp: 100, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                },
                actions: [{
                    type: 'awakening', side: '秘書', actor_name: '表示秘書', target_name: '表示秘書', label: '覚醒',
                    lines: ['<img src=x onerror=alert(1)>', '表示秘書は覚醒した！', 'HP/MPが全回復した！', '生命・武力・技巧・精神・敏捷が30%上昇した！'],
                    amount: 0,
                }],
                end_state: {
                    player: {
                        hp: 650, max_hp: 650, mp: 10000, barrier: 0, statuses: [],
                        role_stacks: { fighting_spirit: 0, grace: 0 }, awakened: true,
                        awakening_unlocked: true, awakening_gauge: 0, awakening_gauge_max: 1000,
                    },
                    enemy: { hp: 0, max_hp: 100, mp: 10000, barrier: 0, statuses: [], role_stacks: { fighting_spirit: 0, grace: 0 } },
                },
            }],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground' && init?.method === undefined) return response(state);
            if (path === '/api/v1/me/underground/battles') return response([battle]);
            if (path === `/api/v1/me/underground/battles/${battle.id}`) return response(battle);
            if (path === '/api/v1/me/underground/awakening/message' && init?.method === 'PUT') {
                const payload = JSON.parse(String(init.body)) as { message: string };
                state = {
                    ...state,
                    awakening: {
                        ...state.awakening,
                        custom_message: payload.message.trim() === '' ? null : payload.message,
                    },
                };
                return response(state);
            }
            if (path === '/api/v1/me/underground/awakening/technique' && init?.method === 'PUT') {
                const payload = JSON.parse(String(init.body)) as { technique_key: string };
                const technique = state.awakening.techniques.find((candidate) => candidate.key === payload.technique_key)!;
                state = {
                    ...state,
                    awakening: {
                        ...state.awakening,
                        technique,
                        selected_technique_key: technique.key,
                    },
                };
                return response(state);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-awakening-gauge').attributes('data-full')).toBe('true');
        expect(wrapper.get('.underground-awakening-gauge').text()).toBe('覚醒ゲージ');
        expect(wrapper.get('.underground-awakening-gauge').text()).not.toContain('1000 / 1000');
        expect(wrapper.get('.underground-awakening-gauge progress').attributes('max')).toBe('1000');
        expect(wrapper.get<HTMLProgressElement>('.underground-awakening-gauge progress').element.value).toBe(1000);
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        expect(wrapper.get('.underground-awakening-settings').text()).toContain('無窮再演');
        expect(wrapper.get('.underground-awakening-settings').text()).toContain('無相の一撃');
        expect(wrapper.get('.underground-awakening-settings').text()).toContain('技巧100%');
        expect(wrapper.get('.underground-awakening-settings').text()).toContain('覚醒中に1度だけ使用可能');
        expect(wrapper.get('.underground-awakening-settings').text()).toContain('通常actionを消費せず');
        await wrapper.get<HTMLInputElement>('input[value="formless_strike"]').setValue();
        await wrapper.get('.underground-awakening-technique-save').trigger('click');
        await flushPromises();
        const techniqueSave = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/underground/awakening/technique' && init?.method === 'PUT'
        ));
        expect(JSON.parse(String(techniqueSave?.[1]?.body))).toEqual({
            request_id: expect.any(String), technique_key: 'formless_strike',
        });
        expect(wrapper.get<HTMLInputElement>('input[value="formless_strike"]').element.checked).toBe(true);
        expect(wrapper.get<HTMLTextAreaElement>('#underground-awakening-message').element.value)
            .toBe('<b>{secretary_name}</b>、限界突破！');
        await wrapper.get('#underground-awakening-message').setValue('<script>表示秘書</script>覚醒');
        await wrapper.get('.underground-awakening-message-save').trigger('click');
        await flushPromises();
        const save = fetchMock.mock.calls.find(([path, init]) => (
            String(path) === '/api/v1/me/underground/awakening/message' && init?.method === 'PUT'
        ));
        expect(JSON.parse(String(save?.[1]?.body))).toEqual({
            request_id: expect.any(String), message: '<script>表示秘書</script>覚醒',
        });
        expect(wrapper.find('.underground-awakening-settings script').exists()).toBe(false);

        await wrapper.get('#underground-awakening-message').setValue('');
        await wrapper.get('.underground-awakening-message-save').trigger('click');
        await flushPromises();
        expect(wrapper.get<HTMLTextAreaElement>('#underground-awakening-message').element.value)
            .toBe(state.awakening.default_message);

        await openUndergroundView(wrapper, '冒険', '戦闘履歴');
        await wrapper.get('.underground-history li button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-action-log .is-awakening').text()).toContain('<img src=x onerror=alert(1)>');
        expect(wrapper.find('.underground-action-log .is-awakening img').exists()).toBe(false);
        expect(wrapper.get('.underground-action-log .is-awakening').text()).toContain('生命・武力・技巧・精神・敏捷が30%上昇した！');
        expect(wrapper.get('.underground-combat-summary').text()).not.toContain('awakening_triggered');
        expect(wrapper.get('.underground-round-start .underground-combatant-awakening').attributes('data-full')).toBe('true');
        expect(wrapper.get<HTMLProgressElement>('.underground-final-state .underground-combatant-awakening progress').element.value).toBe(0);
        expect(wrapper.get('.underground-final-state').text()).toContain('Awaken!');
    });

    it('returns to the Secretary when a concurrent escape already advanced the persisted stage', async () => {
        const battle = {
            id: '11111111-1111-4111-8111-111111111111', context: 'tutorial',
            encounter_name: 'ジャイアントラット', result: 'victory', rounds: 2,
            xp_awarded: 5, shard_delta: 0, detail_available: true,
            actions: [{ round: 1, side: 'player', action: 'quick_slash', amount: 90 }],
        };
        const escapeState = {
            stage: 'escape_pending', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 0,
            shopkeeper_name: null, battle,
        };
        const returnedState = { ...escapeState, stage: 'returned_after_tutorial', battle: null };
        let stateGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground') {
                stateGets++;
                return response(stateGets === 1 ? escapeState : returnedState);
            }
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') return response(null, 409);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();

        expect(wrapper.emitted('returnToSecretary')).toHaveLength(1);
    });

    it('renders the story battle outcome as a penalty-free player defeat', async () => {
        const lossState = {
            stage: 'special_loss_complete', secretary_name: 'ペリドット', combat_level: 1,
            combat_xp: 5, next_level_xp: 100, shard_balance: 9,
            shopkeeper_name: 'ダミー',
            battle: {
                id: '22222222-2222-4222-8222-222222222222', context: 'scripted_loss',
                encounter_name: '（ダミー）', result: 'defeat', rounds: 1,
                xp_awarded: 0, shard_delta: 0, detail_available: true,
                actions: [{ round: 1, side: 'enemy', action: 'heavy_strike', amount: 999 }],
            },
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            if (String(input) === '/api/v1/me/underground') return response(lossState);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        expect(wrapper.get('.underground-battle-result').text()).toContain('敗北');
        expect(wrapper.get('.underground-battle-result').text()).toContain('経験値 +0・輝石の欠片 +0');
    });

    it('completes the normal first-player flow from tutorial through shop explanation and main unlock', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let stage = 'not_started';
        let combatXp = 0;
        let shopkeeperName: string | null = null;
        let battle: Record<string, unknown> | null = null;
        let contractCompleted = false;
        let growthPath: Record<string, unknown> | null = null;
        const pathFixture = (key: string, label: string, color: string, build: string) => ({
            key, label, color, description: [`${label}の説明`], default_build_key: build,
            stats: { vitality: 40, might: 20, finesse: 15, spirit: 15, agility: 10 },
            max_hp: 660, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 2, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: 5, points_per_level: 10,
        });
        const growthPaths = [
            pathFixture('martial_red', '戦技', 'red', 'pure_attacker'),
            pathFixture('guardianship_blue', '護身', 'blue', 'pure_tank'),
            pathFixture('blessing_green', '祝福', 'green', 'pure_healer'),
            pathFixture('free_black', '自由', 'black', 'balanced'),
        ];
        const projection = () => ({
            stage,
            secretary_name: 'ペリドット',
            combat_level: 1,
            combat_xp: combatXp,
            next_level_xp: 100,
            shard_balance: 0,
            shopkeeper_name: shopkeeperName,
            true_name_branch: false,
            tutorial_projection: { stats: { vitality: 10, might: 10, finesse: 10, spirit: 10, agility: 10 }, weapon: 'starter knife' },
            contract_completed: contractCompleted,
            growth_paths: stage === 'crystal_selection' ? growthPaths : null,
            growth_path: growthPath,
            playtest: stage === 'underground_open' ? {
                notice: 'α版の戦闘検証用完成形ビルドです。正式な育成・装備状態ではありません。',
                default_build_key: 'pure_tank', builds: [], enemies: [],
            } : null,
            default_hunting_ground_key: stage === 'underground_open' ? 'shallow_caves' : null,
            hunting_grounds: stage === 'underground_open' ? [
                {
                    key: 'shallow_caves', name: '浅い洞窟', locked: false, unlock_condition: null,
                    item_level_min: 5, item_level_max: 30,
                },
                {
                    key: 'black_crystal_cave', name: '黒晶洞', locked: true,
                    unlock_condition: '試練1を初回clear', item_level_min: 30, item_level_max: 60,
                },
            ] : null,
            trial: stage === 'underground_open' ? {
                key: 'trial_01', label: '地下に眠る古代遺跡', total_battles: 10,
                first_cleared: false, active_run: null,
            } : null,
            battle,
        });
        const tutorialBattle = {
            id: '33333333-3333-4333-8333-333333333333', context: 'tutorial',
            encounter_name: 'ジャイアントラット', result: 'victory', rounds: 1,
            xp_awarded: 5, shard_delta: 0, detail_available: true,
            actions: [{ round: 1, side: 'player', action: 'quick_slash', amount: 90 }],
        };
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                serverSecretary.underground = {
                    available: true, stage, combat_level: 1,
                    combat_xp: combatXp, next_level_xp: 100,
                };
                return response(serverSecretary);
            }
            if (path === '/api/v1/me/underground' && init?.method === undefined) return response(projection());
            if (path === '/api/v1/me/underground/entry' && init?.method === 'POST') {
                stage = stage === 'not_started' ? 'initial_descent' : 'shopkeeper_encounter';
                battle = null;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/tutorial' && init?.method === 'POST') {
                stage = 'escape_pending';
                combatXp = 5;
                battle = tutorialBattle;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/story/advance' && init?.method === 'POST') {
                const action = String(JSON.parse(String(init.body)).action);
                const transitions: Record<string, string> = {
                    initial_story_complete: 'tutorial_ready',
                    escape_complete: 'returned_after_tutorial',
                    shopkeeper_encounter_complete: 'shopkeeper_naming',
                    shop_explanation_complete: 'contract_ready',
                    growth_path_story_complete: 'underground_open',
                };
                stage = transitions[action] ?? stage;
                if (stage !== 'escape_pending') battle = null;
                return response(projection());
            }
            if (path === '/api/v1/me/underground/shopkeeper/name' && init?.method === 'POST') {
                shopkeeperName = String(JSON.parse(String(init.body)).name).trim();
                stage = 'shop_explanation';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/contract' && init?.method === 'POST') {
                contractCompleted = true;
                stage = 'crystal_selection';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/growth-path' && init?.method === 'POST') {
                const key = String(JSON.parse(String(init.body)).growth_path_key);
                growthPath = growthPaths.find((candidate) => candidate.key === key) ?? null;
                stage = 'growth_path_selected';
                return response(projection());
            }
            if (path === '/api/v1/me/underground/battles') return response([tutorialBattle]);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();
        await wrapper.findAll('.site-header nav button')
            .find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();

        expect(wrapper.get('.underground-story').text()).toContain('首都に核シェルターを作るための工事');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-panel h1').text()).toBe('ジャイアントラット');
        expect(wrapper.find('.underground-tutorial-stats').exists()).toBe(false);
        await wrapper.get('.underground-battle-preview .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-battle-result').text()).toContain('勝利');
        await wrapper.get('.underground-after-battle .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.secretary-profile-summary dl').text()).toContain('戦闘Lv1');

        await wrapper.get('.secretary-underground-entry button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('また来ましたね');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        await wrapper.get('#underground-shopkeeper-name').setValue('通常店員');
        await wrapper.get('.underground-name-form').trigger('submit');
        await flushPromises();
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-contract').text()).toBe('契約する');
        await wrapper.get('.underground-contract').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-growth-grid').text()).toContain('Lv2以降: 自然成長 5 / 未使用STP +5');
        await wrapper.findAll('.underground-growth-card .button')[1]!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-story').text()).toContain('ふふ、とってもお似合いですよ、その能力');
        await wrapper.get('.underground-panel > .button').trigger('click');
        await flushPromises();

        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('経験値5 / 100');
        expect(wrapper.find('.underground-currency-note').exists()).toBe(false);
        await openUndergroundView(wrapper, 'キャラクター', '能力');
        expect(wrapper.get('.underground-summary').text()).toContain('MP10000 / 10000');
        expect(wrapper.get('.underground-growth-summary').text()).toContain('自然回復 300 MP / ラウンド');
        await openUndergroundView(wrapper, '冒険', '探索');
        const adventureButtons = wrapper.findAll('.underground-entries button');
        const exploreButton = adventureButtons.find((button) => button.text().includes('探索する'))!;
        expect(exploreButton.attributes('disabled')).toBeUndefined();
        await openUndergroundView(wrapper, '冒険', '探索');
        expect(wrapper.get('.underground-ground-selector').text()).toContain('浅い洞窟');
        await openUndergroundView(wrapper, '冒険', '試練');
        expect(wrapper.findAll('.underground-entries button').some((button) => button.text().includes('試練を開始'))).toBe(true);
        expect(stage).toBe('underground_open');
    });

    it('keeps a Secretary load failure visible instead of treating it as an absent Secretary', async () => {
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false,
                can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                return new Response(JSON.stringify({ message: 'Secretaryを読み込めませんでした。' }), {
                    status: 500,
                    headers: { 'Content-Type': 'application/json' },
                });
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        expect(wrapper.text()).toContain('Secretaryを読み込めませんでした。');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === '？？？')).toBe(false);
    });

    it('keeps a committed equipment mutation when the scoped projection refresh fails', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let scopedSecretaryGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                scopedSecretaryGets++;
                return scopedSecretaryGets <= 2 ? response(serverSecretary) : response(null, 500);
            }
            if (path === '/api/v1/me/secretary/equipment/1/options?world_id=1') {
                const current = serverSecretary.equipment.slots[0]!.item;
                return response({
                    slot: 1,
                    equipment_version: serverSecretary.equipment_version,
                    effect_context: serverSecretary.effect_context,
                    current_item: current,
                    items: serverSecretary.inventory.items,
                    category_limits: serverSecretary.equipment.category_limits,
                });
            }
            if (path === '/api/v1/me/secretary/equipment/1' && init?.method === 'PUT') {
                serverSecretary.equipment_version = 2;
                serverSecretary.inventory.items[0]!.equipped_slot = null;
                serverSecretary.inventory.items[0]!.is_equipped = false;
                serverSecretary.equipment.slots[0]!.item = null;

                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
        await wrapper.findAll('.secretary-equipment button')[0]!.trigger('click');
        await flushPromises();
        await wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[0]!.setValue(true);
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();

        const put = fetchMock.mock.calls.find(([path, request]) => (
            String(path) === '/api/v1/me/secretary/equipment/1' && request?.method === 'PUT'
        ));
        expect(JSON.parse(String(put?.[1]?.body))).toEqual({ item_id: null, expected_version: 1 });
        expect(wrapper.find('.equipment-modal').exists()).toBe(false);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('空き');
        expect(wrapper.text()).toContain('装備は変更されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('装備を変更できませんでした。');
    });

    it('keeps committed naming and rename results when scoped projection refreshes fail', async () => {
        const serverSecretary = structuredClone(unnamedSecretaryFixture);
        let scopedSecretaryGets = 0;
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') {
                scopedSecretaryGets++;
                return scopedSecretaryGets <= 2 ? response(serverSecretary) : response(null, 500);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'POST') {
                const body = JSON.parse(String(init.body)) as { name: string };
                serverSecretary.name = body.name;
                serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
                serverSecretary.header_label = body.name;
                serverSecretary.profile.name = body.name;
                serverSecretary.profile.battle_display_name = body.name;

                return response(serverSecretary);
            }
            if (path === '/api/v1/me/secretary/name' && init?.method === 'PATCH') {
                const body = JSON.parse(String(init.body)) as { name: string };
                serverSecretary.name = body.name;
                serverSecretary.header_label = body.name;
                serverSecretary.profile.name = body.name;
                serverSecretary.profile.battle_display_name = body.name;

                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === '？？？')!.trigger('click');
        await flushPromises();
        await wrapper.get('.secretary-naming-form').trigger('submit');
        await flushPromises();

        expect(wrapper.find('.secretary-story').exists()).toBe(false);
        expect(wrapper.get('.secretary-name').text()).toBe('ペリドット');
        expect(wrapper.text()).toContain('秘書は「ペリドット」と命名されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('Secretaryを命名できませんでした。');

        await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === '設定')!.trigger('click');
        await wrapper.get('.secretary-rename-form input').setValue('エメラルド');
        await wrapper.get('.secretary-rename-form').trigger('submit');
        await flushPromises();

        expect(wrapper.get<HTMLInputElement>('.secretary-rename-form input').element.value).toBe('エメラルド');
        expect(wrapper.findAll('.site-header nav button').some((button) => button.text() === 'エメラルド')).toBe(true);
        expect(wrapper.text()).toContain('秘書の名前は「エメラルド」に変更されましたが、最新の効果表示を読み込めませんでした。');
        expect(wrapper.text()).not.toContain('秘書名を変更できませんでした。');
    });

    it('keeps a v10 warehouse ruleset-neutral without substituting category text as an effect', async () => {
        const secretary = structuredClone(unnamedSecretaryFixture);
        secretary.name = 'ペリドット';
        secretary.named_at = '2026-08-16T15:00:00+09:00';
        secretary.header_label = 'ペリドット';
        secretary.effect_context = {
            source: 'owned_world', world_id: 1, ruleset_version_id: 10,
            ruleset_key: 'hakoniwa-2s-plus-v10', ruleset_version: 10,
        };
        secretary.inventory.items.forEach((item) => { item.effect_text = null; });
        const fetchMock = vi.fn(async (input: RequestInfo | URL) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') return response({
                id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [],
            });
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(secretary);

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('[role="tab"]')[3]!.trigger('click');

        expect(wrapper.findAll('.item-effect')).toHaveLength(0);
        expect(wrapper.get('.secretary-warehouse').text()).toContain('弓');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('指輪');
        expect(wrapper.get('.secretary-warehouse').text()).toContain('貴金属が使われた豪華な指輪');
        expect(fetchMock.mock.calls.some(([path]) => String(path) === '/api/v1/me/secretary')).toBe(false);
    });

    it('loads authoritative equipment options and handles duplicate submit, stale refresh, validation, and success', async () => {
        let serverSecretary = structuredClone(unnamedSecretaryFixture);
        serverSecretary.name = 'ペリドット';
        serverSecretary.named_at = '2026-08-16T15:00:00+09:00';
        serverSecretary.header_label = 'ペリドット';
        let optionsCalls = 0;
        let putCalls = 0;
        let resolveFirstPut: ((response: Response) => void) | undefined;
        const firstPut = new Promise<Response>((resolve) => { resolveFirstPut = resolve; });

        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            const lobby = publicResponse(path);
            if (lobby !== null) return lobby;
            if (path === '/api/v1/me') {
                return response({ id: 1, display_name: 'Owner', can_manage_announcements: false, can_manage_inquiries: false, providers: [] });
            }
            if (path === '/api/v1/me/nation') return response(ownerNationFixture);
            if (path === '/api/v1/me/secretary?world_id=1') return response(serverSecretary);
            if (path === '/api/v1/me/secretary/equipment/1/options?world_id=1') {
                optionsCalls++;
                const current = serverSecretary.equipment.slots[0]!.item;
                return response({
                    slot: 1,
                    equipment_version: serverSecretary.equipment_version,
                    effect_context: {
                        source: 'owned_world', world_id: 1, ruleset_version_id: 10,
                        ruleset_key: 'hakoniwa-2s-plus-v10', ruleset_version: 10,
                    },
                    current_item: current === null ? null : {
                        id: current.id, key: current.key, name: current.name, level: current.level,
                        category: current.category, category_label: current.category_label,
                        equipped_slot: current.equipped_slot, effect_text: current.effect_text,
                    },
                    items: serverSecretary.inventory.items.map((item) => ({
                        id: item.id, key: item.key, name: item.name, level: item.level,
                        category: item.category, category_label: item.category_label,
                        equipped_slot: item.equipped_slot, effect_text: item.effect_text,
                    })),
                    category_limits: serverSecretary.equipment.category_limits,
                });
            }
            if (path === '/api/v1/me/secretary/equipment/1' && init?.method === 'PUT') {
                putCalls++;
                if (putCalls === 1) {
                    serverSecretary.equipment_version = 2;
                    return firstPut;
                }
                if (putCalls === 2) {
                    return new Response(JSON.stringify({
                        code: 'secretary_equipment_invalid',
                        message: 'この装備は選択できません。',
                    }), { status: 422, headers: { 'Content-Type': 'application/json' } });
                }

                serverSecretary.equipment_version = 3;
                serverSecretary.inventory.items[0]!.equipped_slot = null;
                serverSecretary.inventory.items[0]!.is_equipped = false;
                serverSecretary.equipment.slots[0]!.item = null;
                return response(serverSecretary);
            }

            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(App);
        await flushPromises();

        await wrapper.findAll('.site-header nav button').find((button) => button.text() === 'ペリドット')!.trigger('click');
        await flushPromises();
        const scopedSecretaryGets = () => fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/secretary?world_id=1'
        )).length;
        await wrapper.findAll('[role="tab"]')[2]!.trigger('click');
        expect(wrapper.findAll('.secretary-equipment button')).toHaveLength(5);
        expect(wrapper.get('.equipment-category-limits').text()).toContain('弓・1個まで');
        expect(wrapper.get('.equipment-category-limits').text()).toContain('衣服・1個まで');
        expect(wrapper.get('.equipment-category-limits').text()).not.toContain('アクセサリー');
        expect(wrapper.get('.equipment-category-limits').text()).not.toContain('99');
        await wrapper.findAll('.secretary-equipment button')[0]!.trigger('click');
        await flushPromises();

        expect(wrapper.get('.equipment-modal').attributes('aria-modal')).toBe('true');
        expect(wrapper.findAll('.equipment-option-row').map((row) => row.text())).toEqual([
            '外す',
            '古びた弓Lv110%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            '指輪Lv3資金繰りの際、追加で3億円を得る。',
        ]);
        expect(wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[1]!.element.checked).toBe(true);
        const submit = wrapper.get('.equipment-modal-footer button');
        const beforeStaleRefresh = scopedSecretaryGets();
        await submit.trigger('click');
        await submit.trigger('click');
        expect(putCalls).toBe(1);

        resolveFirstPut?.(new Response(JSON.stringify({
            code: 'secretary_equipment_version_conflict',
            message: '装備状態が更新されています。',
        }), { status: 409, headers: { 'Content-Type': 'application/json' } }));
        await flushPromises();
        expect(scopedSecretaryGets()).toBe(beforeStaleRefresh + 1);
        expect(optionsCalls).toBe(2);
        expect(wrapper.get('.equipment-modal-notice').text()).toContain('最新の候補から選び直してください');
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(true);

        await wrapper.findAll<HTMLInputElement>('.equipment-option-row input')[0]!.setValue(true);
        expect(wrapper.get<HTMLButtonElement>('.equipment-modal-footer button').element.disabled).toBe(false);
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();
        expect(wrapper.get('.equipment-modal-error').text()).toBe('この装備は選択できません。');
        expect(wrapper.find('.equipment-modal').exists()).toBe(true);

        const beforeSuccessfulReload = scopedSecretaryGets();
        await wrapper.get('.equipment-modal-footer button').trigger('click');
        await flushPromises();
        expect(putCalls).toBe(3);
        expect(scopedSecretaryGets()).toBe(beforeSuccessfulReload + 1);
        expect(wrapper.find('.equipment-modal').exists()).toBe(false);
        expect(wrapper.findAll('.secretary-equipment li')[0]!.text()).toContain('空き');
        const successfulBody = JSON.parse(String(fetchMock.mock.calls.filter(([path]) => (
            String(path) === '/api/v1/me/secretary/equipment/1'
        )).at(-1)?.[1]?.body));
        expect(successfulBody).toEqual({ item_id: null, expected_version: 2 });
    });

    it('opens the shop, shows carried balance, and retries an ambiguous purchase with the same UUID', async () => {
        const item = {
            key: 'iron_dagger', name: '鉄の短剣', category: 'weapon', weapon_style: 'dagger', rank: 1, item_level: 1,
            rarity: 'common', buy_price: 120, sell_price: 60, owned: false, equipped_slot: null,
            weapon_power: 30, physical_defense: 0, magical_defense: 0, max_hp: 0,
            stats: { vitality: 0, might: 0, finesse: 3, spirit: 0, agility: 2 }, effect_text: null,
        };
        const state = {
            stage: 'underground_open', secretary_name: 'ペリドット', combat_level: 1, combat_xp: 5,
            next_level_xp: 100, next_level_requirement: 95, xp_to_next_level: 95, shard_balance: 240,
            banked_shard_balance: 1000, current_hp: 660, unspent_stp: 0,
            allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 }, current_stats: null,
            combat_stats: null, status_breakdown: null,
            equipment_summary: { used: 1, capacity: 500, equipped: { weapon: { ...item, id: 1, key: 'starter_knife', name: '護身用ナイフ', buy_price: null, sell_price: 0, equipped_slot: 'weapon' }, armor: null, accessory: null } },
            skill_points_total: 0, skill_points_unspent: 0, skill_points_spent: 0, skill_tree_identity: null,
            skill_trees: null, active_slots: [null, null, null, null, null], passive_modifiers: {}, shopkeeper_name: '案内人',
            true_name_branch: false, tutorial_projection: { stats: { vitality: 10, might: 10, finesse: 10, spirit: 10, agility: 10 }, weapon: 'starter knife' },
            contract_completed: true, growth_paths: null, growth_path: null, playtest: null, battle: null,
        };
        let owned = false;
        const purchasePayloads: string[] = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground') return response(state);
            if (path === '/api/v1/me/underground/battles') return response([]);
            if (path === '/api/v1/me/underground/equipment/shop') return response({
                catalog_identity: 'test-catalog', currency_label: '輝石の欠片 G', shard_balance: owned ? 120 : 240,
                banked_shard_balance: 1000, bank_auto_withdraw: false,
                items: [{ ...item, owned }], owned_items: owned ? [{ ...item, id: 42, owned: undefined }] : [],
            });
            if (path === '/api/v1/me/underground/equipment/shop/purchase' && init?.method === 'POST') {
                purchasePayloads.push(String(init.body));
                if (purchasePayloads.length === 1) return response(null, 503);
                owned = true;
                return response({ shard_balance: 120, banked_shard_balance: 1000, vault: { used: 2, capacity: 500, equipped: { weapon: null, armor: null, accessory: null } } });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        await openUndergroundView(wrapper, 'ショップ');
        await flushPromises();
        expect(wrapper.get('#underground-equipment-shop-title').text()).toBe('装備ショップ');
        expect(wrapper.get('.underground-equipment-screen').text()).toContain('手持ち');
        expect(wrapper.get('.underground-equipment-screen').text()).toContain('銀行');
        await wrapper.get('.underground-equipment-card .button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('HTTP 503');
        await wrapper.get('.underground-equipment-card .button').trigger('click');
        await flushPromises();
        expect(purchasePayloads).toHaveLength(2);
        expect(JSON.parse(purchasePayloads[0]!).request_id)
            .toBe(JSON.parse(purchasePayloads[1]!).request_id);
        expect(wrapper.get('.underground-equipment-screen').text()).toContain('所有済み');
        wrapper.unmount();
    });

    it('opens the Guide room, selects a respec path, and retries confirmation with the same UUID', async () => {
        const growthPath = (key: string, label: string, color: string) => ({
            key, label, color, description: [`${label}の成長方針`], default_build_key: 'balanced',
            stats: { vitality: 26, might: 22, finesse: 20, spirit: 20, agility: 12 },
            max_hp: 548, max_mp: 10000, natural_recovery: 300,
            natural_growth: { vitality: 1, might: 1, finesse: 1, spirit: 1, agility: 0 },
            unspent_stp_per_level: key === 'free_black' ? 6 : 5, points_per_level: 10,
        });
        const paths = [
            growthPath('martial_red', '戦技', 'red'),
            growthPath('free_black', '自由', 'black'),
        ];
        const skillTrees = [{
            key: 'martial', label: '戦技', invested_points: 5, full_points: 10, nodes: [{
                key: 'quick_cut_node', label: '早業', summary: '素早く斬る。', type: 'active' as const,
                rank: 1, max_rank: 1, point_cost: 5, invested_points_required: 0, prerequisite: null,
                can_acquire: false, unavailable_reason: null, skill_key: 'quick_cut', mp_cost: 100,
                cooldown: 0, required_weapon_styles: [], recommended_stats: ['finesse'], active_slot: null,
            }, {
                key: 'guard_training', label: '防御の心得', summary: '守りを固める。', type: 'passive' as const,
                rank: 0, max_rank: 1, point_cost: 5, invested_points_required: 0, prerequisite: null,
                can_acquire: true, unavailable_reason: null, skill_key: null, mp_cost: null,
                cooldown: null, required_weapon_styles: [], recommended_stats: null, active_slot: null,
            }],
        }];
        let respecProjection = { cost: 20, last_completed_at: null as string | null, next_available_at: null as string | null, growth_paths: paths };
        let respecCommitted = false;
        let openState: any = {
            stage: 'underground_open', secretary_name: 'ペリドット', combat_level: 2, combat_xp: 100,
            next_level_xp: 200, next_level_requirement: 100, xp_to_next_level: 100, shard_balance: 240,
            banked_shard_balance: 1000, current_hp: 400, unspent_stp: 5,
            allocated_stp: { vitality: 0, might: 0, finesse: 0, spirit: 0, agility: 0 }, current_stats: paths[0]!.stats,
            combat_stats: paths[0]!.stats,
            status_breakdown: {
                vitality: { baseline: 26, natural_growth: 1, allocated_stp: 0, equipment: 0, final: 27 },
                might: { baseline: 22, natural_growth: 1, allocated_stp: 0, equipment: 0, final: 23 },
                finesse: { baseline: 20, natural_growth: 1, allocated_stp: 0, equipment: 0, final: 21 },
                spirit: { baseline: 20, natural_growth: 1, allocated_stp: 0, equipment: 0, final: 21 },
                agility: { baseline: 12, natural_growth: 0, allocated_stp: 0, equipment: 0, final: 12 },
            },
            equipment_summary: { used: 1, capacity: 500, equipped: { weapon: null, armor: null, accessory: null } },
            skill_points_total: 20, skill_points_unspent: 5, skill_points_spent: 15, skill_tree_identity: 'tree-v1',
            skill_trees: skillTrees, active_slots: [null, null, null, null, null], passive_modifiers: {}, shopkeeper_name: '案内人',
            true_name_branch: false, tutorial_projection: { stats: paths[0]!.stats, weapon: 'starter knife' },
            contract_completed: true, growth_paths: null, growth_path: paths[0]!, playtest: null,
            default_hunting_ground_key: null, hunting_grounds: [],
            respec: respecProjection,
            trial: { key: 'trial_01', label: '地下に眠る古代遺跡', total_battles: 10, first_cleared: false, active_run: null },
            awakening: null,
            recollections: {
                available: true,
                trial_02_first_cleared: true,
                past_available: true,
                max_completed: 0,
                entries: [1, 2, 3, 4, 5].map((chapter) => ({
                    key: `past_${chapter}`,
                    kind: 'past',
                    title: `過去について問う・${chapter}`,
                    chapter,
                    experienced: false,
                    completed: false,
                    locked: chapter > 1,
                })),
                serious_talk: null,
            },
            ai: {
                schema_version: 2, max_rules: 16, max_conditions_per_rule: 2, is_custom: false,
                rules: [{ conditions: [{ type: 'always' }], action: 'normal_attack' }],
                default_rules: [{ conditions: [{ type: 'always' }], action: 'normal_attack' }],
                hash: 'a'.repeat(64),
                catalog: {
                    condition_types: [{ key: 'always', label: '常に', value_kind: 'none' }],
                    actions: [{ key: 'normal_attack', label: '通常攻撃' }, { key: 'jump', label: '後ろのruleへ移動' }],
                    targets: [], skills: [], statuses: [], role_stacks: [],
                },
            },
            battle: null, next_battle_at: null,
        };
        const respecPayloads: Array<{ request_id: string; growth_path_key: string }> = [];
        const recollectionPayloads: Array<{ request_id: string; chapter: number }> = [];
        const skillPayloads: Array<{ request_id: string; node_key: string }> = [];
        const loadoutPayloads: Array<{ request_id: string; slots: Array<string | null> }> = [];
        const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
            const path = String(input);
            if (path === '/api/v1/me/underground') return response(openState);
            if (path === '/api/v1/me/underground/battles') {
                if (respecCommitted) throw new TypeError('Battle history refresh failed');
                return response([]);
            }
            if (path === '/api/v1/me/underground/recollections/read' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as { request_id: string; chapter: number };
                recollectionPayloads.push(payload);
                if (recollectionPayloads.length === 1) throw new TypeError('Recollection response lost');
                const chapter = payload.chapter;
                openState = {
                    ...openState,
                    recollections: {
                        ...openState.recollections,
                        max_completed: chapter,
                        entries: [1, 2, 3, 4, 5].map((entryChapter) => ({
                            key: `past_${entryChapter}`,
                            kind: 'past',
                            title: `過去について問う・${entryChapter}`,
                            chapter: entryChapter,
                            experienced: entryChapter <= chapter,
                            completed: entryChapter <= chapter,
                            locked: entryChapter > chapter + 1,
                            ...(entryChapter <= chapter ? { body: [`past-${entryChapter}`] } : {}),
                        })),
                        serious_talk: chapter >= 5 ? {
                            title: '案内人に真剣な話をする',
                            initial_scene: 'root',
                            scenes: {
                                root: {
                                    lines: ['「まだ、何か？」'],
                                    choices: [
                                        { key: 'true_name', label: '本名を聞く', next: 'true_name' },
                                        { key: 'embrace', label: '抱き締める', next: 'embrace' },
                                        { key: 'leave', label: '戻る', next: 'guide' },
                                    ],
                                },
                                true_name: {
                                    lines: ['「私は、自分のことが嫌いです」'],
                                    choices: [
                                        { key: 'ask_again', label: 'それでも教えて欲しい', next: 'true_name_branch' },
                                        { key: 'tell_dream', label: 'あなたについて知ることが私の夢だと伝える', next: 'true_name_reveal' },
                                        { key: 'leave', label: '立ち去る', next: 'root' },
                                    ],
                                },
                                true_name_reveal: {
                                    lines: ['「………………」', '「リカ。」'],
                                    choices: [
                                        { key: 'back', label: '本名を聞いた後の選択に戻る', next: 'true_name' },
                                    ],
                                },
                                embrace: {
                                    lines: ['「……夢魔が、抱かれるのは慣れてます」'],
                                    choices: [
                                        { key: 'more', label: 'もっと抱き締める', next: 'embrace_more' },
                                    ],
                                },
                                embrace_more: {
                                    lines: ['「実に感情的ですね」'],
                                    choices: [
                                        { key: 'back', label: 'はじめに戻る', next: 'root' },
                                    ],
                                },
                            },
                        } : null,
                    },
                };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/respec' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as { request_id: string; growth_path_key: string };
                respecPayloads.push(payload);
                if (respecPayloads.length === 1) throw new TypeError('Respec response lost');
                respecProjection = {
                    ...respecProjection,
                    last_completed_at: '2099-08-20T00:00:00+09:00',
                    next_available_at: '2099-08-21T00:00:00+09:00',
                };
                openState = {
                    ...openState,
                    shard_balance: 220,
                    skill_points_unspent: 20,
                    skill_points_spent: 0,
                    skill_trees: openState.skill_trees.map((tree: (typeof skillTrees)[number]) => ({
                        ...tree,
                        invested_points: 0,
                        nodes: tree.nodes.map((node: (typeof skillTrees)[number]['nodes'][number]) => ({ ...node, rank: 0, can_acquire: true, active_slot: null })),
                    })),
                    active_slots: [null, null, null, null, null],
                    respec: respecProjection,
                };
                respecCommitted = true;
                return response(openState);
            }
            if (path === '/api/v1/me/underground/skills/acquire' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as { request_id: string; node_key: string };
                skillPayloads.push(payload);
                if (!respecCommitted) throw new TypeError('Skill response lost');
                openState = {
                    ...openState,
                    skill_trees: openState.skill_trees.map((tree: (typeof skillTrees)[number]) => ({
                        ...tree,
                        nodes: tree.nodes.map((node: (typeof skillTrees)[number]['nodes'][number]) => node.key === payload.node_key
                            ? { ...node, rank: 1, can_acquire: false }
                            : node),
                    })),
                };
                return response(openState);
            }
            if (path === '/api/v1/me/underground/skills/loadout' && init?.method === 'PUT') {
                const payload = JSON.parse(String(init.body)) as { request_id: string; slots: Array<string | null> };
                loadoutPayloads.push(payload);
                if (!respecCommitted) throw new TypeError('Loadout response lost');
                return response(openState);
            }
            if (path === '/api/v1/me/underground/guide-conversation/start' && init?.method === 'POST') {
                return response({
                    topic_id: 42,
                    initial_line: 'DBから選んだ話題',
                    choices: [
                        { position: 1, text: '返事をする' },
                        { position: 2, text: '別の返事' },
                    ],
                });
            }
            if (path === '/api/v1/me/underground/guide-conversation/reply' && init?.method === 'POST') {
                const payload = JSON.parse(String(init.body)) as { topic_id: number; position: number };
                return response({ topic_id: payload.topic_id, position: payload.position, reply_line: '選択への返答' });
            }
            if (path === '/api/v1/me/underground/guide-conversation/punch' && init?.method === 'POST') {
                return response({ punch_line: '「いぎゃっ！？」' });
            }
            return response(null, 404);
        });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(UndergroundPanel);
        await flushPromises();

        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await wrapper.get('#underground-active-loadout select').setValue('quick_cut');
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await wrapper.get('#underground-active-loadout .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Loadout response lost');
        await wrapper.get('#skill-node-guard_training').trigger('click');
        await wrapper.get('.skill-detail button').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Skill response lost');
        await openUndergroundView(wrapper, 'キャラクター', 'STP配分');
        await wrapper.get('input[aria-label="生命の今回の配分"]').setValue(2);
        expect(wrapper.get('.underground-progression-panel .button.primary').text()).toBe('2 STPを一括確定');
        await openUndergroundView(wrapper, 'キャラクター', '戦法');
        expect(wrapper.get('.underground-ai-editor').text()).toContain('初期設定を表示しています');
        await openUndergroundView(wrapper, 'ショップ', '案内人と話す');
        const guideAction = (label: string) => wrapper.findAll('.underground-guide-actions > button')
            .find((button) => button.text() === label)!;
        await guideAction('少しお話をする').trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('案内人「DBから選んだ話題」');
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('返事をする');
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('げんこつ');
        await wrapper.findAll('.underground-guide-conversation-choices button')
            .find((button) => button.text() === 'げんこつ')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('案内人「いぎゃっ！？」');
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('もう一度げんこつ');
        await wrapper.findAll('.underground-guide-conversation-choices button')
            .find((button) => button.text() === 'やめる')!.trigger('click');
        expect(wrapper.find('.underground-guide-conversation').exists()).toBe(false);
        await guideAction('少しお話をする').trigger('click');
        await flushPromises();
        await wrapper.findAll('.underground-guide-conversation-choices button')
            .find((button) => button.text() === '返事をする')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('案内人「選択への返答」');
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('げんこつ');
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('話をやめる');
        await wrapper.findAll('.underground-guide-conversation-choices button')
            .find((button) => button.text() === 'げんこつ')!.trigger('click');
        await flushPromises();
        expect(wrapper.get('.underground-guide-conversation').text()).toContain('案内人「いぎゃっ！？」');
        expect(wrapper.findAll('.underground-guide-actions > button').map((button) => button.text()))
            .not.toContain('過去について問う');
        await guideAction('案内人の過去を聞く').trigger('click');
        expect(wrapper.find('.underground-recollection-section-start').exists()).toBe(true);
        const firstRecollection = wrapper.findAll('.underground-recollection-list button')
            .find((button) => button.text().includes('過去について問う・1'))!;
        await firstRecollection.trigger('click');
        await wrapper.get('.underground-recollection-detail .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Recollection response lost');
        await wrapper.get('.underground-recollection-detail .button.primary').trigger('click');
        await flushPromises();
        expect(recollectionPayloads).toHaveLength(2);
        expect(recollectionPayloads[1]).toEqual(recollectionPayloads[0]);
        for (const chapter of [2, 3, 4, 5]) {
            await wrapper.findAll('.underground-recollection-list button')
                .find((button) => button.text().includes(`過去について問う・${chapter}`))!.trigger('click');
            await wrapper.get('.underground-recollection-detail .button.primary').trigger('click');
            await flushPromises();
        }
        expect(wrapper.get('.underground-guide-room').text()).toContain('案内人に真剣な話をする');
        await guideAction('案内人に真剣な話をする').trigger('click');
        expect(wrapper.get('.underground-guide-serious-talk').text()).not.toContain('闘いを挑む');
        expect(wrapper.get('.underground-guide-serious-talk').text()).toContain('本名を聞く');
        expect(wrapper.get('.underground-guide-serious-talk').text()).toContain('抱き締める');
        expect(wrapper.get('.underground-guide-serious-talk').text()).toContain('戻る');
        expect(wrapper.find('.underground-guide-serious-talk .eyebrow').exists()).toBe(false);
        const seriousTalkChoice = (label: string) => wrapper.findAll('.underground-serious-talk-actions > button')
            .find((button) => button.text() === label)!;
        await seriousTalkChoice('本名を聞く').trigger('click');
        expect(wrapper.findAll('.underground-serious-talk-actions > button').map((button) => button.text()))
            .toEqual(['それでも教えて欲しい', 'あなたについて知ることが私の夢だと伝える', '立ち去る']);
        await seriousTalkChoice('あなたについて知ることが私の夢だと伝える').trigger('click');
        expect(wrapper.get('.underground-guide-serious-talk').text()).toContain('「リカ。」');
        await guideAction('案内人に真剣な話をする').trigger('click');
        await seriousTalkChoice('抱き締める').trigger('click');
        await seriousTalkChoice('もっと抱き締める').trigger('click');
        expect(wrapper.findAll('.underground-serious-talk-actions > button').map((button) => button.text()))
            .toEqual(['はじめに戻る']);
        await guideAction('再振りをしたい').trigger('click');
        expect(wrapper.get('.underground-respec-explanations').text()).toContain('SP・STP・成長方針を再設定します。');
        expect(wrapper.get('.underground-respec-explanations').text()).toContain('輝石のかけらが Lv × 10 G 必要です。');
        expect(wrapper.get('.underground-respec-explanations').text()).toContain('一度行うと24時間は再び行うことができません。');
        await wrapper.findAll('.underground-respec-growth-card [role="radio"]')[1]!.trigger('click');
        expect(wrapper.get('.underground-respec-selection').text()).toBe('選択中: 自由');
        await wrapper.get('.underground-respec-submit').trigger('click');
        expect(wrapper.get('[role="dialog"]').text()).toContain('再振りを実行しますか？');
        expect(wrapper.get('[role="dialog"]').text()).toContain('この操作は取り消せません。');
        await wrapper.get('[role="dialog"] .button.primary').trigger('click');
        await flushPromises();
        expect(wrapper.get('[role="alert"]').text()).toContain('Respec response lost');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
        await wrapper.get('[role="dialog"] .button.primary').trigger('click');
        await flushPromises();
        expect(respecPayloads).toHaveLength(2);
        expect(respecPayloads[0]).toEqual({ request_id: expect.any(String), growth_path_key: 'free_black' });
        expect(respecPayloads[1]).toEqual(respecPayloads[0]);
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(wrapper.get('[role="alert"]').text()).toContain('Battle history refresh failed');
        expect(wrapper.get('.underground-respec-notice').text()).toContain('次の再振りまであと');
        expect(wrapper.get('.underground-respec-submit').attributes('disabled')).toBeDefined();
        await openUndergroundView(wrapper, 'キャラクター', 'STP配分');
        expect((wrapper.get('input[aria-label="生命の今回の配分"]').element as HTMLInputElement).value).toBe('0');
        expect(wrapper.get('.underground-progression-panel .button.primary').attributes('disabled')).toBeDefined();
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        expect((wrapper.get('#underground-active-loadout select').element as HTMLSelectElement).value).not.toBe('quick_cut');
        const passiveNode = wrapper.findAll('.skill-node')
            .find((node) => node.text().includes('防御の心得'))!;
        await passiveNode.trigger('click');
        await wrapper.get('.skill-detail button').trigger('click');
        await flushPromises();
        expect(skillPayloads).toHaveLength(2);
        expect(skillPayloads[1]!.request_id).not.toBe(skillPayloads[0]!.request_id);
        const activeNode = wrapper.findAll('.skill-node')
            .find((node) => node.text().includes('早業'))!;
        await activeNode.trigger('click');
        await wrapper.get('.skill-detail button').trigger('click');
        await flushPromises();
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await wrapper.get('#underground-active-loadout select').setValue('quick_cut');
        await openUndergroundView(wrapper, 'キャラクター', 'スキル・覚醒');
        await wrapper.get('#underground-active-loadout .button.primary').trigger('click');
        await flushPromises();
        expect(loadoutPayloads).toHaveLength(2);
        expect(loadoutPayloads[1]!.request_id).not.toBe(loadoutPayloads[0]!.request_id);
        wrapper.unmount();
    });
});
