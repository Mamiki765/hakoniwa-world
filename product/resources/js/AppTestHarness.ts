import { afterEach, beforeEach, vi } from 'vitest';
import type { AssetDescriptor, MapCell, MapChunk, Nation, PublicNationDetail, Secretary, UndergroundSurfaceMap, UndergroundSurfaceMapSlot } from './types';

export const response = (data: unknown, status = 200) => new Response(JSON.stringify({ data, message: status === 401 ? 'Unauthenticated.' : undefined }), {
    status,
    headers: { 'Content-Type': 'application/json' },
});

export const envelopeResponse = (data: unknown, meta: Record<string, unknown>) => new Response(JSON.stringify({ data, meta }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
});

export const validationResponse = (errors: Record<string, string[]>) => new Response(JSON.stringify({
    message: '入力内容を確認してください。',
    errors,
}), { status: 422, headers: { 'Content-Type': 'application/json' } });

export const emptyChunk: MapChunk = {
    world_id: 1, map_space_id: 2, chunk_x: 0, chunk_y: 0, chunk_size: 16,
    version: 'empty', state: 'empty', cells: [],
};

export const publicDetail: PublicNationDetail = {
    id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', state_label: '', recovery_remaining_turns: null,
    karma: 0, karma_badge: null, total_population: 1000,
    owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
    farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
    registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
    last_updated_turn: 1, comment: '公開コメント', world: { id: 1, name: '箱庭諸島２S＋', current_turn: 1 },
    capital: { x: 12, y: 8 }, secretary_id: 11,
    underground_surface_map: null,
    monster_final_blow_count: 1,
    monster_kill_stats: [{
        key: 'inora', name: 'いのら', kill_count: 1, first_killed_turn: 12, last_killed_turn: 12,
    }],
    map_space: { id: 2, world_id: 1, key: 'surface', name: '地上', bounds_revision: 'bounds-0-59', bounds: { min_x: 0, max_x: 59, min_y: 0, max_y: 59 } },
};

export const resourceForecastFixture: Nation['resource_forecast'] = {
    rows: [
        { key: 'food', name: '食料', production: 55_000, consumption: 60_000, delta: -5_000, holding: 12_000 },
        { key: 'industrial_goods', name: '工業品', production: 15_000, consumption: 0, delta: 15_000, holding: 1_200 },
        { key: 'minerals', name: '鉱物', production: 46_154, consumption: 0, delta: 46_154, holding: 0 },
        { key: 'oil', name: '石油', production: 500, consumption: 0, delta: 500, holding: 123 },
    ],
    food_holding_note: '食料の所持は小麦換算です。',
    workforce: { status: 'unemployment', label: '失業率', percentage_tenths: 160, population: 60_000, demand: 50_400 },
};

export const ownerNationFixture: Nation = {
    id: 3, world_id: 1, nation_number: 1, name: '自島', owner_name: '自島主', comment: '',
    money: 100, money_display: '100億円', money_capacity: 9999, money_remaining_capacity: 9899,
    money_is_at_capacity: false, total_food_tons: 10000, food_total_tons: 10000,
    food_capacity_tons: 999900, food_remaining_capacity_tons: 989900, food_is_at_capacity: false,
    farm_capacity_people: 10000, factory_capacity_people: 0, mine_capacity_people: 0,
    resource_forecast: resourceForecastFixture,
    food_resources: [], resources: [], state: 'active', state_label: '', karma: 0, karma_positive: false,
    recovery_remaining_turns: null, state_reason: null,
    state_started_turn: null, resume_at_turn: null, manual_dormancy_days: null,
    dormancy_remaining_turns: null, dormancy_remaining_days: null, abandonment_remaining_turns: 2060,
    can_request_dormancy: true, winter_theme_active: false, current_turn: 1, registered_turn: 1,
    survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only', total_population: 1000,
    territory_cell_count: 19, owned_land_cells: 17, capital: { x: 12, y: 8 },
};

export const undergroundAsset = (key: string, fallbackLabel: string): AssetDescriptor => ({
    key, url: null, available: false, fallback_label: fallbackLabel, fallback_style: key.replaceAll('.', '-'),
});
export const surfaceCellFixture: MapCell = {
    x: 12, y: 8, terrain: 'plain', terrain_name: '平地', facility: null, facility_name: null,
    display_name: '平地', owner_nation_id: 3, owner_nation_number: 1, owner_name: '自島',
    within_viewer_visibility: false, details: [], monster: null,
    asset: undergroundAsset('tile.plain', '平'), overlays: [], aria_label: '平地 (12, 8)', version: 1, updated_at: null,
};
const undergroundOffsets: UndergroundSurfaceMapSlot['offset_x'][] = [-2, -1, 1, 2];
export const undergroundSurfaceMapFixture: UndergroundSurfaceMap = {
    unlocked_layers: 2,
    facility_slots_per_layer: 4,
    total_facility_slots: 8,
    capital: { x: 12, y: 8 },
    entrance: { asset_key: 'underground.entrance', counts_as_facility_slot: false },
    assets: {
        soil: undergroundAsset('underground.soil', '土'),
        entrance: undergroundAsset('underground.entrance', '入口'),
        ladder: undergroundAsset('underground.ladder', '梯'),
        road: undergroundAsset('underground.road', '空'),
        underground_city: undergroundAsset('underground.city', '都'),
        underground_farm: undergroundAsset('underground.farm', '農'),
        underground_factory: undergroundAsset('underground.factory', '工'),
        underground_missile_base: undergroundAsset('underground.missile_base', '基'),
    },
    layers: [1, 2].map((layer) => {
        const z = -(layer + 1);
        return {
            layer,
            z,
            ladder: { asset_key: 'underground.ladder' as const, counts_as_facility_slot: false as const },
            slots: undergroundOffsets.map((offsetX, slotIndex) => ({
                slot_index: slotIndex,
                offset_x: offsetX,
                coordinate: { x: 12 + offsetX, y: 8, z },
                coordinate_label: `(${12 + offsetX}, 8, ${z})`,
                relative_label: `(X${offsetX > 0 ? '+' : ''}${offsetX}, Y, ${z})`,
                facility_key: layer === 1 && slotIndex === 0 ? 'underground_farm' : null,
                asset_key: layer === 1 && slotIndex === 0 ? 'underground.farm' : 'underground.road',
            })),
        };
    }),
};

export const unnamedSecretaryFixture: Secretary = {
    id: 11,
    name: null,
    named_at: null,
    header_label: '？？？',
    profile: {
        id: 11,
        name: null,
        nickname: null,
        battle_display_name: '？？？',
        portrait_preference: 'full_body',
        is_owner: true,
        domestic_level: 1,
        secretary_level: 1,
        passive_level_total: 1,
        capacity_bonus_percent: 1,
        monster_experience: 0,
        combat_level: 1,
        biography: '',
        main_image: {
            display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null,
        },
        images: {
            icon: { slot: 'icon', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
            bust: { slot: 'bust', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
            full_body: { slot: 'full_body', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
            awakening_icon: { slot: 'awakening_icon', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
            awakening_bust: { slot: 'awakening_bust', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
            awakening_full_body: { slot: 'awakening_full_body', display: 'none', url: null, creation_method: null, creation_method_label: null, credit: null },
        },
        editable_image_metadata: null,
        viewer_preferences: {
            configured: false, show_ai_generated_images: null,
            own_secretary_fallback: null, fallback: null, can_update: true,
        },
        equipment: {
            slot_count: 5,
            category_limits: [
                { category: 'bow', label: '弓', maximum_equipped: 1 },
                { category: 'clothing', label: '衣服', maximum_equipped: 1 },
            ],
            slots: [
                { slot: 1, item: null },
                { slot: 2, item: null },
                { slot: 3, item: null },
                { slot: 4, item: null },
                { slot: 5, item: null },
            ],
        },
    },
    effect_context: {
        source: 'owned_world', world_id: 1, ruleset_version_id: 11,
        ruleset_key: 'test-hakoniwa-2s-plus-v11-secretary-items', ruleset_version: 11,
    },
    equipment_version: 1,
    skills: [
        { key: 'agricultural_policy', name: '農業政策', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '小麦生産＋0.0%' },
        { key: 'specialty_development', name: '特産品開発', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '工場生産＋0.0%' },
        { key: 'gold_vein_survey', name: '金鉱脈調査', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '採掘場生産＋0.0%' },
        { key: 'forest_management', name: '森林管理', level: 0, experience: 0, required_experience: 1, remaining_experience: 1, effect: '伐採資金・森林増加＋0%' },
        { key: 'final_defense_line', name: '最終防衛ライン', level: 1, experience: 0, required_experience: 100, remaining_experience: 100, effect: '防衛されなかったミサイルを1ターンにつき1発まで迎撃' },
        { key: 'declining_birthrate_policy', name: '少子化対策', level: 10, experience: 460000, required_experience: 560000, remaining_experience: 100000, effect: '自然人口上限 +500人 / 誘致人口上限 +1,000人' },
        { key: 'indomitable', name: '不屈', level: 10, experience: 0, required_experience: 560000, remaining_experience: 560000, effect: '自然人口増加 +2.50%' },
    ],
    inventory: {
        capacity: 50,
        used: 2,
        items: [{
            id: 21, key: 'old_bow', name: '古びた弓', level: 1, category: 'bow', category_label: '弓',
            equipped_slot: 1, is_equipped: true, is_escrowed: false, rarity: 'novice', rarity_label: 'ノービス',
            fixed_sale_price_money: 100, fixed_sale_label: '売却（100億円）',
            effect_text: '10%の確率で、自領の地上にいる怪獣に1ダメージを与える。',
            flavor_text: '秘書が捕らえられていた施設の最奥から見つかった、大きく古ぼけた弓。宝石があしらわれており、どこか不思議な力を感じさせる。',
            obtained_at: '2026-08-17T00:00:00Z',
        }, {
            id: 22, key: 'ring', name: '指輪', level: 3, category: 'accessory', category_label: 'アクセサリー',
            equipped_slot: null, is_equipped: false, is_escrowed: false, rarity: 'novice', rarity_label: 'ノービス',
            fixed_sale_price_money: 100, fixed_sale_label: '売却（100億円）',
            effect_text: '資金繰りの際、追加で3億円を得る。',
            flavor_text: '貴金属が使われた豪華な指輪。魔法の道具ではないが、贈り物にはぴったりだ。',
            obtained_at: '2026-08-18T00:00:00Z',
        }],
    },
    equipment: {
        slot_count: 5,
        category_limits: [
            { category: 'bow', label: '弓', maximum_equipped: 1 },
            { category: 'clothing', label: '衣服', maximum_equipped: 1 },
        ],
        slots: [
            { slot: 1, item: null },
            { slot: 2, item: null },
            { slot: 3, item: null },
            { slot: 4, item: null },
            { slot: 5, item: null },
        ],
    },
};
unnamedSecretaryFixture.equipment.slots[0]!.item = unnamedSecretaryFixture.inventory.items[0]!;
unnamedSecretaryFixture.profile.equipment = unnamedSecretaryFixture.equipment;

export function publicResponse(path: string): Response | null {
    if (path === '/api/v1/public/announcements/latest') return response([
        { id: 2, title: 'ver 1.0.2のお知らせ', body: 'queue fix', created_at: '2026-08-02T03:00:00+09:00', updated_at: '2026-08-02T03:00:00+09:00' },
        { id: 1, title: 'ver 1.0.1のお知らせ', body: 'resource fix', created_at: '2026-08-01T03:00:00+09:00', updated_at: '2026-08-01T03:00:00+09:00' },
    ]);
    if (path === '/api/v1/public/worlds') return response([{ id: 1, key: 'shared-world', name: '箱庭諸島２S＋', turn: 1 }]);
    if (path.endsWith('/summary')) return response({
        id: 1, key: 'shared-world', name: '箱庭諸島２S＋', current_turn: 1, nation_count: 1, total_population: 1000, contact_url: null,
        hakoniwa_calendar: { year: 1, month: 1, label: '箱庭歴 1年1月' },
        turn_status: 'normal', last_successful_turn_at: '2026-08-09T13:00:00Z',
        next_scheduled_turn_at: '2099-08-09T15:00:00Z', turn_schedule_timezone: 'Asia/Tokyo',
    });
    if (path.endsWith('/rankings')) return response([{
        rank: 1, id: 7, world_id: 1, nation_number: 1, name: '公開島', state: 'active', state_label: '',
        recovery_remaining_turns: null, karma: 0, karma_badge: null, total_population: 1000,
        owner_name: '公開島主', territory_cell_count: 19, owned_land_cells: 17, money_display: '約500億円', money_bucket: '500', food_total_tons: 10_000,
        farm_capacity_people: 10_000, factory_capacity_people: 30_000, mine_capacity_people: 5_000,
        registered_turn: 1, survival_turns: 0, finance_only_turns: 100, activity_status: 'finance_only',
        last_updated_turn: 1, comment: '公開コメント',
        achievements: { awards: [], monster_kills: null },
    }]);
    if (path.endsWith('/major-news')) return response({ groups: [], limit: 15 });
    if (/^\/api\/v1\/public\/worlds\/\d+\/events/.test(path)) return response({
        groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
        turns_per_page: 2, has_newer_page: false, has_older_page: false,
    });
    if (/^\/api\/v1\/public\/nations\/\d+\/events/.test(path)) return response({
        groups: [], page: 1, anchor_turn: 1, turn_range: { start: 1, end: 1 },
        turns_per_page: 12, has_newer_page: false, has_older_page: false,
    });
    if (/^\/api\/v1\/nations\/\d+\/message-board$/.test(path)) return response({
        board: { nation_number: 1, name: '公開島' }, entries: [],
        viewer: { authenticated: false, can_post: false, author_type: null, can_send_secret: false },
        contract: {
            latest_limit: 16, body_max_characters: 140, cooldown_seconds: 10,
            secret_cost_money: 100, secret_cost_display: '100億円',
        },
    });
    return null;
}

export function installAppTestLifecycle(): void {
    beforeEach(() => {
        document.documentElement.dataset.theme = 'system';
        document.cookie = 'hakoniwa_theme=; Path=/; Max-Age=0; SameSite=Lax';
        window.localStorage.removeItem('hakoniwa.underground.selected-hunting-ground');
        for (const key of Object.keys(window.localStorage)) {
            if (key.startsWith('hakoniwa.underground.party-member-ids.')) window.localStorage.removeItem(key);
        }
        const meta = document.createElement('meta');
        meta.name = 'hakoniwa-application-version';
        meta.content = '3.0.0';
        document.head.append(meta);
    });

    afterEach(() => {
        document.documentElement.dataset.theme = 'system';
        document.cookie = 'hakoniwa_theme=; Path=/; Max-Age=0; SameSite=Lax';
        window.localStorage.removeItem('hakoniwa.underground.selected-hunting-ground');
        for (const key of Object.keys(window.localStorage)) {
            if (key.startsWith('hakoniwa.underground.party-member-ids.')) window.localStorage.removeItem(key);
        }
        document.querySelector('meta[name="hakoniwa-application-version"]')?.remove();
        vi.unstubAllGlobals();
        vi.useRealTimers();
        window.history.replaceState({}, '', '/');
    });
}
