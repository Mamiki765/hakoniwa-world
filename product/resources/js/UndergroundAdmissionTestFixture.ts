import { vi } from 'vitest';

/** Keep gameplay mocks focused; admission itself has a separate API-client test. */
export function stubUndergroundFetch(fetchMock: (input: RequestInfo | URL, init?: RequestInit) => Response | Promise<Response>): void {
    vi.stubGlobal('fetch', (input: RequestInfo | URL, init?: RequestInit) => {
        if (String(input) === '/api/v1/me/underground/requests') {
            return Promise.resolve(new Response(JSON.stringify({ data: {
                request_id: crypto.randomUUID(), token: 'server-admission-fixture',
                expires_at: Math.floor(Date.now() / 1000) + 86400, format: 1,
            } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        }
        return fetchMock(input, init);
    });
}
