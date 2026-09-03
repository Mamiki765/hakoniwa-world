export interface UndergroundAiCondition {
    type: string;
    percent?: number;
    status?: string;
    stacks?: number;
    skill?: string;
    round?: number;
    modulo?: number;
    equals?: number;
}

export interface UndergroundAiRule {
    conditions: UndergroundAiCondition[];
    action: string;
    jump_to?: number;
}

export interface UndergroundAiCatalog {
    condition_types: Array<{ key: string; label: string; value_kind: string }>;
    actions: Array<{ key: string; label: string }>;
    skills: Array<{ key: string; label: string; summary: string }>;
    statuses: Array<{ key: string; label: string; max_stacks: number }>;
    role_stacks: Array<{ key: string; label: string; max_stacks: number }>;
}

export interface UndergroundAiConfiguration {
    schema_version: number;
    max_rules: number;
    max_conditions_per_rule: number;
    is_custom: boolean;
    rules: UndergroundAiRule[];
    default_rules: UndergroundAiRule[];
    hash: string;
    catalog: UndergroundAiCatalog;
}
