export class ApiError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        public readonly errors: Record<string, string[]> = {},
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
    const response = await fetch(path, {
        ...init,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            ...init.headers,
        },
    });
    const payload = await response.json().catch(() => ({ message: response.statusText })) as {
        data?: T;
        meta?: Record<string, unknown>;
        message?: string;
        errors?: Record<string, string[]>;
    };

    if (!response.ok) {
        throw new ApiError(response.status, payload.message ?? `HTTP ${response.status}`, payload.errors ?? {});
    }

    return { data: payload.data as T, meta: payload.meta };
}
