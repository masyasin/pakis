/**
 * Grid kalender satu bulan, minggu dimulai Senin (kebiasaan kantor di Indonesia). Murni tanggal
 * sipil `YYYY-MM-DD` tanpa zona waktu, jadi tidak pernah bergeser hari (bandingkan
 * `new Date(iso)` yang bisa mundur sehari di zona negatif).
 */
export type MonthCell = { date: string; day: number } | null;

const pad = (n: number) => String(n).padStart(2, '0');

/** `2026-11` → baris minggu berisi 7 sel; sel di luar bulan = null. */
export function monthGrid(month: string): MonthCell[][] {
    const [y, m] = month.split('-').map(Number);
    const daysInMonth = new Date(Date.UTC(y, m, 0)).getUTCDate();
    // getUTCDay: 0 = Minggu … 6 = Sabtu → geser agar 0 = Senin.
    const lead = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;

    const cells: MonthCell[] = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= daysInMonth; d++) {
        cells.push({ date: `${y}-${pad(m)}-${pad(d)}`, day: d });
    }
    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    const weeks: MonthCell[][] = [];
    for (let i = 0; i < cells.length; i += 7) {
        weeks.push(cells.slice(i, i + 7));
    }

    return weeks;
}

export const WEEKDAYS = [
    'Sen',
    'Sel',
    'Rab',
    'Kam',
    'Jum',
    'Sab',
    'Min',
] as const;
