export class ApiError extends Error {
    constructor(public readonly status: number, message: string) {
        super(message);
    }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
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
    const payload = await response.json().catch(() => ({ message: response.statusText })) as { data?: T; message?: string };

    if (!response.ok) {
        throw new ApiError(response.status, payload.message ?? `HTTP ${response.status}`);
    }

    return payload.data as T;
}
