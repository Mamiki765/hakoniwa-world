export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
        public readonly code: string | null = null,
    ) {
        super(message);
    }
}

export interface ApiEnvelope<T> {
    data: T;
    meta?: Record<string, unknown>;
}

interface UndergroundAdmission {
    request_id: string;
    token: string;
    expires_at: number;
    format: number;
}

// Component request IDs remain local intent handles. Only the issuer chooses
// the UUID sent to a mutation. Retain an issued token after mutation failure;
// never turn an uncertain or expired operation into a new request automatically.
const undergroundAdmissions = new Map<string, Promise<UndergroundAdmission>>();
let undergroundReloadRequired = false;
const admissionMessage = '受付期限切れ、または古い画面からの操作です。再読込して操作し直してください。';

function needsUndergroundAdmission(path: string, method: string): boolean {
    return path.startsWith('/api/v1/me/underground/')
        && !['GET', 'HEAD', 'OPTIONS'].includes(method)
        && path !== '/api/v1/me/underground/requests'
        && path !== '/api/v1/me/underground/equipment/vault/bulk-sell/preview';
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
    return (await apiEnvelope<T>(path, init)).data;
}

export async function apiEnvelope<T>(path: string, init: RequestInit = {}): Promise<ApiEnvelope<T>> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
    const headers = new Headers(init.headers);
    if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json');
    }
    if (!(init.body instanceof FormData) && !headers.has('Content-Type')) {
        headers.set('Content-Type', 'application/json');
    }
    if (!headers.has('X-CSRF-TOKEN')) {
        headers.set('X-CSRF-TOKEN', csrf);
    }
    const method = (init.method ?? 'GET').toUpperCase();
    if (needsUndergroundAdmission(path, method)) {
        if (undergroundReloadRequired) {
            throw new ApiError(409, admissionMessage, {}, 'underground_request_reload_required');
        }
        const body = typeof init.body === 'string' ? JSON.parse(init.body) as Record<string, unknown> : {};
        const intent = typeof body.request_id === 'string' ? body.request_id : crypto.randomUUID();
        const key = `${method}:${path}:${intent}`;
        let pending = undergroundAdmissions.get(key);
        if (!pending) {
            pending = api<UndergroundAdmission>('/api/v1/me/underground/requests', {
                method: 'POST', body: JSON.stringify({ method, path }),
            }).catch((error) => {
                // No mutation was sent: let an explicit retry request admission again.
                undergroundAdmissions.delete(key);
                throw error;
            });
            undergroundAdmissions.set(key, pending);
        }
        const admission = await pending;
        headers.set('X-Underground-Receipt', admission.token);
        headers.set('X-Underground-Request-Id', admission.request_id);
        if (typeof body.request_id === 'string') {
            init = { ...init, body: JSON.stringify({ ...body, request_id: admission.request_id }) };
        }
    }
    const response = await fetch(path, {
        ...init,
        credentials: 'same-origin',
        headers,
    });
    const payload = await response.json().catch(() => ({ message: response.statusText })) as {
        data?: T;
        meta?: Record<string, unknown>;
        message?: string;
        errors?: Record<string, string[]>;
        code?: string;
    };

    if (!response.ok) {
        if (['underground_request_expired', 'underground_request_reload_required'].includes(payload.code ?? '')) {
            undergroundReloadRequired = true;
        }
        throw new ApiError(
            response.status,
            payload.message ?? `HTTP ${response.status}`,
            payload.errors ?? {},
            payload.code ?? null,
        );
    }

    return { data: payload.data as T, meta: payload.meta };
}
