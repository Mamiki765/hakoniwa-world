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
    resources: NationResource[];
    state: string;
    capital: { x: number; y: number } | null;
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
    available: boolean;
    unavailable_reason: string | null;
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
    items: CommandQueueItem[];
}

export interface SalePolicy {
    resource_id: number;
    resource_key: string;
    resource_name: string;
    amount: number;
    policy: 'sell_all' | 'stockpile' | 'keep_amount';
    keep_amount: number | null;
    version: number;
}
