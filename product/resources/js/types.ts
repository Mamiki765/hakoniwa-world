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

export interface World {
    id: number;
    key: string;
    name: string;
    turn: number;
}

export interface MapSpace {
    id: number;
    world_id: number;
    key: string;
    name: string;
}

export interface Nation {
    id: number;
    world_id: number;
    name: string;
    money: number;
    resources: NationResource[];
    state: string;
    capital: { q: number; r: number } | null;
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

export interface MapCell {
    q: number;
    r: number;
    terrain: string;
    facility: string | null;
    owner_nation_id: number | null;
    owner_name: string | null;
    population: number;
    asset: AssetDescriptor;
    version: number;
    updated_at: string;
}

export interface MapChunk {
    world_id: number;
    map_space_id: number;
    chunk_q: number;
    chunk_r: number;
    chunk_size: number;
    version: number;
    state: 'generated' | 'empty';
    cells: MapCell[];
}
