export interface UserIdentity {
    provider: 'discord' | 'google';
    display_name: string | null;
    linked_at: string;
}

export interface CurrentUser {
    id: number;
    display_name: string;
    providers: UserIdentity[];
}

export interface World { id: number; key: string; name: string; turn: number }
export interface MapSpace { id: number; world_id: number; key: string; name: string }

export interface Nation {
    id: number;
    world_id: number;
    name: string;
    money: number;
    money_display: string;
    resources: NationResource[];
    state: string;
    current_turn: number;
    total_population: number;
    territory_cell_count: number;
    capital: { x: number; y: number } | null;
}

export interface PublicWorldSummary {
    id: number;
    key: string;
    name: string;
    current_turn: number;
    nation_count: number;
    total_population: number;
}

export interface PublicNationSummary {
    id: number;
    world_id: number;
    name: string;
    state: string;
    total_population: number;
    territory_cell_count: number;
    money_display: string;
    money_bucket: string;
    last_updated_turn: number;
    comment: string | null;
}

export interface PublicRankingEntry extends PublicNationSummary {
    rank: number;
}

export interface PublicEvent {
    id: number;
    type: 'nation_created' | 'system_announcement' | 'turn_event';
    message: string;
    metadata: Record<string, string | number>;
    occurred_at: string;
}

export interface PublicNationDetail extends PublicNationSummary {
    world: { id: number; name: string; current_turn: number };
    capital: { x: number; y: number } | null;
    map_space: MapSpace;
}

export interface NationResource {
    key: string;
    name: string;
    category: string;
    unit: string;
    nutrition_per_unit: number | null;
    storable: boolean;
    tradable: boolean;
    amount: number;
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

export interface MapCell {
    x: number;
    y: number;
    terrain: string;
    terrain_name: string;
    facility: string | null;
    facility_name: string | null;
    display_name: string;
    owner_nation_id: number | null;
    owner_name: string | null;
    details: MapCellDetail[];
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
    description: string;
    cost_money: number;
    execution_phase: string;
    parameter_schema: Record<string, CommandParameterSchema>;
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
}

export interface CommandParameterSchema {
    label: string;
    type: 'integer';
    minimum: number;
    maximum: number;
    default: number;
    quick_presets: number[];
    required: boolean;
    meaning: string;
}

export interface CommandQueueItem {
    id: number;
    command_key: string;
    command_name: string;
    queue_position: number;
    target_x: number;
    target_y: number;
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
    keep_amount: number | null;
    version: number;
}
