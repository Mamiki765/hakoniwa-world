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
        throw new ApiError(
            response.status,
            payload.message ?? `HTTP ${response.status}`,
            payload.errors ?? {},
            payload.code ?? null,
        );
    }

    return { data: payload.data as T, meta: payload.meta };
}
