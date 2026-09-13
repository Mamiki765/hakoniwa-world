export interface UndergroundSkillNode {
    key: string;
    label: string;
    summary: string;
    type: 'active' | 'passive';
    rank: number;
    max_rank: number;
    point_cost: number;
    invested_points_required: number;
    prerequisite: string | null;
    can_acquire: boolean;
    unavailable_reason: string | null;
    skill_key: string | null;
    mp_cost: number | null;
    cooldown: number | null;
    consumes_action?: boolean;
    required_weapon_styles: string[];
    recommended_stats: string[] | null;
    active_slot: number | null;
}

export interface UndergroundSkillTree {
    key: string;
    label: string;
    invested_points: number;
    full_points: number;
    nodes: UndergroundSkillNode[];
}
