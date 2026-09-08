/**
 * Decide whether a failed exploration request is known not to have started.
 *
 * Transport failures and unknown server failures keep the request identity so
 * the same payload can be retried. Validation and the user-facing party/content
 * conflicts below are definitive responses from the server; retaining their
 * stale payload would make a later retry ignore the player's new party.
 */
const DEFINITIVE_EXPLORATION_CONFLICTS = new Set([
    'underground_party_invalid',
    'underground_party_self_borrow',
    'underground_party_member_unavailable',
    'underground_party_leader_changed',
    'underground_exploration_locked',
    'underground_hunting_ground_locked',
    'underground_trial_active',
    'underground_battle_cooldown',
    'underground_secretary_missing',
    'underground_request_id_invalid',
]);

export function shouldReleasePendingExplorationRequest(error: unknown): boolean {
    if (!error || typeof error !== 'object') return false;

    const status = (error as { status?: unknown }).status;
    if (status === 422) return true;
    if (status !== 409) return false;

    const code = (error as { code?: unknown }).code;
    return typeof code === 'string' && DEFINITIVE_EXPLORATION_CONFLICTS.has(code);
}
