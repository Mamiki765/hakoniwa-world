export interface SceneAsset {
    id: string;
    url: string | null;
    creation_method: 'self_made' | 'ai_generated' | 'commissioned_or_permitted' | 'other' | null;
    credit?: string | null;
    credit_url?: string | null;
    show_credit?: boolean;
}

export interface ScenePlacement {
    x: number;
    y: number;
    height: number;
    pivot_x?: number;
    pivot_y?: number;
    layer?: number;
}

export interface SceneActor {
    key: string;
    name: string;
    asset: SceneAsset;
    placement: ScenePlacement;
    mobile?: Partial<ScenePlacement>;
    own_character?: boolean;
}

export interface UndergroundScene {
    background?: SceneAsset | null;
    still?: SceneAsset | null;
    actors: SceneActor[];
}

export interface HomeBackground {
    key: string;
    name: string;
    asset: SceneAsset | null;
}

export interface UndergroundVisuals {
    display_name: string;
    icon_url: string | null;
    show_ai: boolean;
    scenes: Record<string, UndergroundScene>;
    portrait: SceneAsset | null;
    awakened_portrait: SceneAsset | null;
    home_backgrounds: HomeBackground[];
    home_background_key: string;
}

export type ResidenceItemKey = 'villa' | 'mirror' | 'trophy_shelf' | 'vault_expansion' | 'resonance_expansion';

export interface ResidenceState {
    villa_owned: boolean;
    mirror_owned: boolean;
    trophy_shelf_owned: boolean;
    vault_expansion_owned: boolean;
    resonance_expansion_owned: boolean;
    exchange_intro_page: number;
    mirror_event_completed: boolean;
    items: Record<ResidenceItemKey, { name: string; price: number; capacity_before?: number; capacity_after?: number }>;
}

export type UndergroundDestination = 'home' | 'adventure' | 'character' | 'shop' | 'exchange' | 'villa';

export const undergroundDestinations: Array<{ key: UndergroundDestination; label: string; icon: string }> = [
    { key: 'home', label: 'ホーム', icon: '⌂' },
    { key: 'adventure', label: '冒険', icon: '♧' },
    { key: 'character', label: 'キャラクター', icon: '♙' },
    { key: 'shop', label: 'ショップ', icon: '⚖' },
    { key: 'exchange', label: '交流場', icon: '♧' },
    { key: 'villa', label: '別荘', icon: '▤' },
];

export function sceneAssetVisible(asset: SceneAsset | null | undefined, showAi: boolean): asset is SceneAsset {
    return Boolean(asset?.url && (showAi || asset.creation_method !== 'ai_generated'));
}
