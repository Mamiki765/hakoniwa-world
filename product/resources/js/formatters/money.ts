export function formatExactMoney(money: number): string {
    return `${Math.trunc(money).toLocaleString('ja-JP')}億円`;
}

export function formatPublicMoney(money: number): { display: string; bucket: string } {
    const normalized = Math.max(0, Math.trunc(money));
    if (normalized < 500) return { display: '500億円未満', bucket: 'under_500' };
    const bucket = normalized < 1000 ? 500 : Math.floor(normalized / 1000) * 1000;
    const display = bucket < 10_000
        ? `${bucket.toLocaleString('ja-JP')}億円`
        : `${(bucket / 10_000).toLocaleString('ja-JP', { maximumFractionDigits: 1 })}兆円`;
    return { display: `約${display}`, bucket: String(bucket) };
}
