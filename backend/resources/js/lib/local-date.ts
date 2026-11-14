/**
 * Tanggal panjang "Minggu 15 November 2026" (gaya prototipe) menurut zona tampilan
 * `general.timezone`, bukan zona perangkat: dekat tengah malam keduanya bisa berbeda hari.
 */
export function formatLongDate(date: Date, timeZone: string): string {
    const parts = new Intl.DateTimeFormat('id-ID', {
        timeZone,
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${part('weekday')} ${part('day')} ${part('month')} ${part('year')}`;
}
