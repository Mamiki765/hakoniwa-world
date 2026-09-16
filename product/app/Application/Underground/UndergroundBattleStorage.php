<?php

namespace App\Application\Underground;

/** Defines the v1 permanent/detail split without introducing another gameplay authority. */
final class UndergroundBattleStorage
{
    public const COMPACTION_VERSION = 1;

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function detailPresentation(array $snapshot): array
    {
        $presentation = [];
        foreach (['initial_state', 'portrait_events', 'player_image_references'] as $key) {
            if (array_key_exists($key, $snapshot)) {
                $presentation[$key] = $snapshot[$key];
            }
        }
        if (is_array($snapshot['party'] ?? null)) {
            $party = $snapshot['party'];
            $members = is_array($party['members'] ?? null) ? $party['members'] : [];
            $party['members'] = array_map(
                fn (mixed $member): mixed => is_array($member)
                    ? $this->presentationMember($member)
                    : $member,
                $members,
            );
            $presentation['party'] = $party;
        }

        return $presentation;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function compactSnapshot(array $snapshot): array
    {
        foreach ([
            'ai', 'initial_state', 'portrait_events', 'player_image_references',
            'progression_stats', 'combat_stats', 'allocated_stp', 'equipment',
            'skill_tree_identity', 'targeting_contract_identity', 'acquired_skill_nodes',
            'equipped_active_skills', 'effective_passive_modifiers', 'party_awakening',
            'actor', 'loadout', 'enemy',
        ] as $key) {
            unset($snapshot[$key]);
        }

        if (is_array($snapshot['summary'] ?? null)) {
            unset($snapshot['summary']['final_state'], $snapshot['summary']['awakening']);
        }
        if (is_array($snapshot['party'] ?? null)) {
            $members = is_array($snapshot['party']['members'] ?? null)
                ? $snapshot['party']['members']
                : [];
            $snapshot['party']['members'] = array_map(
                fn (mixed $member): mixed => is_array($member)
                    ? $this->permanentMember($member)
                    : $member,
                $members,
            );
        }
        if (is_array($snapshot['encounter'] ?? null)) {
            unset($snapshot['encounter']['definition']);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function compactPartyMemberSnapshot(array $snapshot): array
    {
        return $this->permanentMember($snapshot);
    }

    /** @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function presentationMember(array $member): array
    {
        return array_filter([
            'team' => $member['team'] ?? null,
            'combatant_id' => $member['combatant_id'] ?? null,
            'source_type' => $member['source_type'] ?? null,
            'display_name' => $member['display_name'] ?? ($member['label'] ?? null),
            'label' => $member['label'] ?? null,
            'image_references' => $member['image_references'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function permanentMember(array $member): array
    {
        return array_filter([
            'team' => $member['team'] ?? null,
            'combatant_id' => $member['combatant_id'] ?? null,
            'source_type' => $member['source_type'] ?? null,
            'source' => $member['source'] ?? null,
            'display_name' => $member['display_name'] ?? ($member['label'] ?? null),
            'label' => $member['label'] ?? null,
            'original_combat_level' => $member['original_combat_level'] ?? null,
            'effective_combat_level' => $member['effective_combat_level'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
