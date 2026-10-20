export function formatAnnouncementDate(value: string): string {
    return new Intl.DateTimeFormat('ja-JP', {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'Asia/Tokyo',
    }).format(new Date(value));
}
