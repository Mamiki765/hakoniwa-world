import { describe, expect, it } from 'vitest';
import { shouldReleasePendingExplorationRequest } from './undergroundExplorationPending';

describe('pending exploration request recovery', () => {
    it('releases the frozen request after validation or a definitive party conflict', () => {
        expect(shouldReleasePendingExplorationRequest({ status: 422 })).toBe(true);
        expect(shouldReleasePendingExplorationRequest({ status: 409, code: 'underground_party_member_unavailable' })).toBe(true);
    });

    it('retains the request for unknown, transport, and request-id conflicts', () => {
        expect(shouldReleasePendingExplorationRequest({ status: 409, code: 'underground_request_conflict' })).toBe(false);
        expect(shouldReleasePendingExplorationRequest({ status: 409, code: 'unknown_runtime_failure' })).toBe(false);
        expect(shouldReleasePendingExplorationRequest(new Error('network failure'))).toBe(false);
        expect(shouldReleasePendingExplorationRequest({ status: 500 })).toBe(false);
    });
});
