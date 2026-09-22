import { afterEach, expect, it, vi } from 'vitest';

afterEach(() => { vi.unstubAllGlobals(); vi.resetModules(); });

it('reuses server admission after uncertain delivery and requires reload after expiry', async () => {
    const { api } = await import('./client');
    const sent: Array<{ id: string; token: string | null }> = [];
    let issues = 0;
    let expiry = false;
    vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        if (String(input).endsWith('/requests')) {
            issues++;
            expect(JSON.parse(String(init?.body))).toEqual({ method: 'POST', path: '/api/v1/me/underground/inn/rest' });
            return new Response(JSON.stringify({ data: { request_id: 'server-generated-id', token: 'signed', format: 1, expires_at: 1 } }));
        }
        sent.push({ id: JSON.parse(String(init?.body)).request_id, token: new Headers(init?.headers).get('X-Underground-Receipt') });
        if (sent.length === 1) throw new TypeError('Connection lost after settlement');
        if (expiry) return new Response(JSON.stringify({ code: 'underground_request_expired', message: '再読込して操作し直してください。' }), { status: 409 });
        return new Response(JSON.stringify({ data: { duplicate: true } }));
    }));
    const action = { method: 'POST', body: JSON.stringify({ request_id: 'local-intent' }) };
    await expect(api('/api/v1/me/underground/inn/rest', action)).rejects.toThrow('Connection lost');
    await expect(api('/api/v1/me/underground/inn/rest', action)).resolves.toEqual({ duplicate: true });
    expect(issues).toBe(1);
    expect(sent).toEqual([{ id: 'server-generated-id', token: 'signed' }, { id: 'server-generated-id', token: 'signed' }]);
    expiry = true;
    await expect(api('/api/v1/me/underground/inn/rest', action)).rejects.toMatchObject({ code: 'underground_request_expired' });
    await expect(api('/api/v1/me/underground/inn/rest', { ...action, body: JSON.stringify({ request_id: 'another-intent' }) }))
        .rejects.toMatchObject({ code: 'underground_request_reload_required' });
    expect(issues).toBe(1);
    expect(sent).toHaveLength(3);
});
