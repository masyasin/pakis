/**
 * Format tampilan mesin persetujuan (T-067). Waktu dari server ISO-8601 UTC; ditampilkan di zona
 * kantor `general.timezone` (prop bersama `timezone`), bukan zona perangkat.
 */

/** `16 Nov 2026 10.05` di zona `timeZone` (07 §7). Nilai rusak dikembalikan apa adanya. */
export function formatDateTimeIn(iso: string, timeZone: string): string {
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) {
        return iso;
    }
    const parts = new Intl.DateTimeFormat('id-ID', {
        timeZone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(at);
    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${part('day')} ${part('month')} ${part('year')} ${part('hour')}.${part('minute')}`;
}

/**
 * Lama menunggu sejak `iso` (kartu kotak masuk, 15 §7.1 "umur menunggu"): "< 1 jam", "5 jam",
 * "2 hari 3 jam". Jam sisa tidak ditulis bila 0 atau bila sudah ≥ 7 hari (cukup "9 hari").
 */
export function formatWaiting(iso: string, now: Date = new Date()): string {
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) {
        return '';
    }
    const hours = Math.max(
        0,
        Math.floor((now.getTime() - at.getTime()) / 3_600_000),
    );
    if (hours < 1) {
        return '< 1 jam';
    }
    if (hours < 24) {
        return `${hours} jam`;
    }
    const days = Math.floor(hours / 24);
    const rest = hours % 24;

    return rest === 0 || days >= 7
        ? `${days} hari`
        : `${days} hari ${rest} jam`;
}

/** Cookie XSRF-TOKEN Laravel untuk permintaan JSON di luar Inertia (pola halaman Penomoran). */
export function xsrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}
