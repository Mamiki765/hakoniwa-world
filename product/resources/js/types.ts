export interface UserIdentity {
    provider: 'discord' | 'google';
    display_name: string | null;
    linked_at: string;
}

export interface CurrentUser {
    id: number;
    display_name: string;
    can_manage_announcements: boolean;
    providers: UserIdentity[];
}

export interface Announcement {
    id: number;
    title: string;
    body: string;
    created_at: string;
    updated_at: string;
}

export interface World { id: number; key: string; name: string; turn: number }
export interface MapBounds { min_x: number; max_x: number; min_y: number; max_y: number }
export interface MapSpace {
    id: number;
    world_id: number;
    key: string;
    name: string;
    bounds_revision: string;
    bounds: MapBounds;
}

export interface Nation {
    id: number;
    world_id: number;
    nation_number: number;
    name: string;
    owner_name: string;
    comment: string;
    money: number;
    money_display: string;
    money_capacity: number;
    money_remaining_capacity: number;
    money_is_at_capacity: boolean;
    total_food_tons: number;
    food_total_tons: number;
    food_capacity_tons: number;
    food_remaining_capacity_tons: number;
    food_is_at_capacity: boolean;
    farm_capacity_people: number;
    factory_capacity_people: number;
    mine_capacity_people: number;
    food_resources: FoodResource[];
    resources: NationResource[];
    state: string;
    current_turn: number;
    registered_turn: number;
    survival_turns: number;
    finance_only_turns: number;
    activity_status: 'active' | 'finance_only';
    total_population: number;
    territory_cell_count: number;
    owned_land_cells: number;
    capital: { x: number; y: number } | null;
}

export interface PublicWorldSummary {
    id: number;
    key: string;
    name: string;
    current_turn: number;
    nation_count: number;
    total_population: number;
    contact_url: string | null;
    turn_status: 'normal' | 'failed' | 'blocked' | 'delayed';
    last_successful_turn_at: string | null;
    next_scheduled_turn_at: string;
    turn_schedule_timezone: 'Asia/Tokyo';
}

export interface PublicNationSummary {
    id: number;
    world_id: number;
    nation_number: number;
    name: string;
    owner_name: string;
    state: string;
    total_population: number;
    territory_cell_count: number;
    owned_land_cells: number;
    money_display: string;
    money_bucket: string;
    food_total_tons: number;
    farm_capacity_people: number;
    factory_capacity_people: number;
    mine_capacity_people: number;
    registered_turn: number;
    survival_turns: number;
    finance_only_turns: number;
    activity_status: 'active' | 'finance_only';
    last_updated_turn: number;
    comment: string;
}

export interface PublicAwardAchievement {
    key: string;
    name: string;
    recurring: boolean;
    count: number;
    awarded_turns?: number[];
    asset: AssetDescriptor;
}

export interface PublicMonsterKillSpecies {
    key: string;
    name: string;
    kill_count: number;
}

export interface PublicMonsterKillAchievement {
    total_count: number;
    asset: AssetDescriptor;
    species: PublicMonsterKillSpecies[];
}

export interface PublicRankingAchievements {
    awards: PublicAwardAchievement[];
    monster_kills: PublicMonsterKillAchievement | null;
}

export interface PublicRankingEntry extends PublicNationSummary {
    rank: number;
    achievements: PublicRankingAchievements;
}

export interface PublicEvent {
    id: number;
    type: string;
    message: string;
    importance: 'info' | 'notable' | 'warning';
    target_turn: number;
}

export interface PublicEventPage {
    groups: Array<{ target_turn: number; events: PublicEvent[] }>;
    page: number;
    anchor_turn: number;
    turn_range: { start: number; end: number } | null;
    turns_per_page: number;
    has_newer_page: boolean;
    has_older_page: boolean;
}

export interface MajorNewsFeed {
    groups: Array<{ target_turn: number; events: PublicEvent[] }>;
    limit: number;
}

export interface PublicNationDetail extends PublicNationSummary {
    world: { id: number; name: string; current_turn: number };
    capital: { x: number; y: number } | null;
    monster_final_blow_count: number;
    monster_kill_stats: Array<{
        key: string;
        name: string;
        kill_count: number;
        first_killed_turn: number;
        last_killed_turn: number;
    }>;
    map_space: MapSpace;
}

export interface PlayerIslandEvent {
    id: number;
    type: string;
    message: string;
    importance: 'info' | 'notable' | 'warning';
    target_turn: number;
    confidential: boolean;
    summary: null | {
        money: { start: number; end: number; delta: number };
        population: { start: number; end: number; delta: number };
        food: { start: number; end: number; delta: number };
    };
}

export interface PlayerIslandEventGroup {
    target_turn: number;
    events: PlayerIslandEvent[];
}

export interface PlayerIslandEventPage {
    groups: PlayerIslandEventGroup[];
    page: number;
    anchor_turn: number;
    turn_range: { start: number; end: number } | null;
    turns_per_page: number;
    has_newer_page: boolean;
    has_older_page: boolean;
}

export type MessageBoardEntry = {
    key: string;
    created_at: string;
} & ({
    kind: 'public';
    body: string;
    author: {
        type: 'owner' | 'other_nation';
        label: '島主' | '他島';
        nation: { nation_number: number; name: string };
    } | {
        type: 'visitor';
        label: '観光客';
        display_name: string;
        visitor_code: string;
    };
} | {
    kind: 'secret_placeholder';
    text: '--秘密通信あり--';
} | {
    kind: 'secret';
    label: '秘密通信';
    direction: 'incoming' | 'outgoing';
    body: string;
    counterpart: { nation_number: number; name: string };
});

export interface MessageBoardTimeline {
    board: { nation_number: number; name: string };
    entries: MessageBoardEntry[];
    viewer: {
        authenticated: boolean;
        can_post: boolean;
        author_type: 'owner' | 'other_nation' | 'visitor' | null;
        can_send_secret: boolean;
    };
    contract: {
        latest_limit: 16;
        body_max_characters: 140;
        cooldown_seconds: 10;
        secret_cost_money?: 100;
        secret_cost_display?: '100億円';
    };
}

export interface NationResource {
    key: string;
    name: string;
    category: string;
    unit: string;
    unit_label: string | null;
    nutrition_per_unit: number | null;
    storable: boolean;
    tradable: boolean;
    amount: number;
    capacity: number | null;
    remaining_capacity: number | null;
    is_at_capacity: boolean;
}

export interface FoodResource {
    key: string;
    name: string;
    balance: number;
    unit: 'ton';
    unit_label: 'トン';
}

export interface AssetDescriptor {
    key: string;
    url: string | null;
    available: boolean;
    fallback_label: string;
    fallback_style: string;
}

export interface MapCellDetail {
    key: string;
    label: string;
    value: number | string;
    unit: string | null;
    formatted: string;
    visibility: 'public' | 'owner';
}

export interface MonsterOverlay {
    id: number;
    key: string;
    name: string;
    asset_key: string;
    asset_url: string | null;
    asset: AssetDescriptor;
    current_hp: number;
    spawned_max_hp: number;
    hp_range: { min: number; max: number };
    skill_description: string;
    hardened_now: boolean;
    public_state: 'alive';
    coordinate: { x: number; y: number };
    host_nation: { nation_number: number; name: string } | null;
    host_label: string;
}

export interface MapCell {
    x: number;
    y: number;
    terrain: string;
    terrain_name: string;
    facility: string | null;
    facility_name: string | null;
    display_name: string;
    owner_nation_id: number | null;
    owner_nation_number: number | null;
    owner_name: string | null;
    details: MapCellDetail[];
    monster: MonsterOverlay | null;
    asset: AssetDescriptor;
    overlays: AssetDescriptor[];
    aria_label: string;
    version: number | string;
    updated_at: string | null;
}

export interface MapChunk {
    world_id: number;
    map_space_id: number;
    chunk_x: number;
    chunk_y: number;
    chunk_size: number;
    version: number | string;
    state: 'generated' | 'empty';
    cells: MapCell[];
}

export interface CommandDefinition {
    key: string;
    name: string;
    command_suffix?: string | null;
    command_suffix_tone?: 'danger' | null;
    confirmation_message?: string | null;
    description: string;
    target_type: 'cell' | 'nation';
    quantity_semantics: 'ordinary' | 'selector' | 'unused';
    quantity_default: number | null;
    quantity_options: Array<{ value: number; key: string; label: string }>;
    parameters: Record<string, {
        label: string;
        type: 'integer';
        input_semantics: 'number' | 'nation_selector';
        options: Array<{
            value: number;
            label: string;
            nation_number: number;
        }>;
        minimum: number;
        maximum: number;
        required: boolean;
        nullable?: boolean;
        default?: number;
    }>;
    cost_money: number;
    execution_phase: string;
    initial_facility_capacity: null | {
        facility_key: string;
        facility_scale: number;
        capacity_people: number;
        scale_unit_people: number;
        initial_scale: number;
        scale_increment: number;
        maximum_scale: number;
        formatted: string;
    };
    applicable: boolean;
    available: boolean;
    shortfall_money: number;
    unavailable_reason: string | null;
    execution_preview_status: 'target_required' | 'currently_executable' | 'currently_unavailable' | 'executable_after_queue';
    execution_warnings: string[];
}

export interface DevelopmentPlanQuantityContract {
    type: 'integer';
    minimum: number;
    maximum: number;
    default: number;
    quick_presets: number[];
}

export interface CommandCatalog {
    commands: CommandDefinition[];
    quantity_contract: DevelopmentPlanQuantityContract;
}

export interface CommandQueueItem {
    id: number;
    command_key: string;
    command_name: string;
    command_suffix?: string | null;
    command_suffix_tone?: 'danger' | null;
    queue_position: number;
    target_x: number;
    target_y: number;
    quantity: number;
    quantity_semantics: 'ordinary' | 'selector' | 'unused';
    quantity_label: string | null;
    parameters: Record<string, unknown>;
    status: string;
    queued_at: string | null;
}

export interface CommandQueue {
    version: number;
    limit: number;
    explicit_count: number;
    items: CommandQueueItem[];
    plan: EffectivePlanSlot[];
}

export type EffectivePlanSlot = {
    position: number;
    kind: 'automatic_finance';
    editable: false;
    command_name: '資金繰り';
    quantity: null;
} | (CommandQueueItem & {
    position: number;
    kind: 'explicit';
    editable: true;
});

export interface SalePolicy {
    resource_id: number;
    resource_key: string;
    resource_name: string;
    amount: number;
    policy: 'sell_all' | 'stockpile' | 'keep_amount';
    allowed_policies?: Array<'sell_all' | 'stockpile' | 'keep_amount'>;
    keep_amount: number | null;
    version: number;
}
