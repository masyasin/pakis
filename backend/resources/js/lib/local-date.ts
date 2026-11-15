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

/**
 * Tanggal sipil `YYYY-MM-DD` (kolom DATE: hari libur, `work_date`) → "Min, 17 Agu 2025".
 * Nilainya sudah tanggal lokal kantor, jadi dibaca sebagai UTC agar tidak bergeser satu hari
 * di perangkat yang zonanya di barat UTC. Nilai tidak sah dikembalikan apa adanya.
 */
export function formatCivilDate(isoDate: string): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
    if (!match) {
        return isoDate;
    }

    const date = new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
    );
    const parts = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'UTC',
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((p) => p.type === type)?.value ?? '';

    return `${part('weekday')}, ${part('day')} ${part('month')} ${part('year')}`;
}

/**
 * Rentang tanggal sipil inklusif yang ringkas: satu hari → "Sen, 17 Agu 2026"; satu tahun →
 * "30 Mar – 3 Apr 2026"; lintas tahun → "31 Des 2026 – 2 Jan 2027".
 */
export function formatCivilRange(start: string, end: string): string {
    if (start === end) {
        return formatCivilDate(start);
    }

    const [from, to] = [start, end].map(
        (iso) => formatCivilDate(iso).split(', ')[1] ?? iso,
    );
    const sameYear = start.slice(0, 4) === end.slice(0, 4);

    return sameYear
        ? `${from.replace(/ \d{4}$/, '')} – ${to}`
        : `${from} – ${to}`;
}

/**
 * Jumlah hari kalender inklusif antara dua tanggal sipil (30 Mar–3 Apr = 5). Null bila salah
 * satu tidak sah atau selesai sebelum mulai (form menampilkan galat server sebagai gantinya).
 */
export function civilDayCount(start: string, end: string): number | null {
    const toUtc = (iso: string) => {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);

        return m ? Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) : NaN;
    };
    const diff = (toUtc(end) - toUtc(start)) / 86_400_000;

    return Number.isFinite(diff) && diff >= 0 ? diff + 1 : null;
}
