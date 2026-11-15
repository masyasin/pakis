/**
 * Tipe & utilitas halaman Jadwal (T-035, BR-061/BR-062). Bentuk data mengikuti
 * `EffectiveSchedule::toArray()` dan `Shift::summary()` di backend.
 */

export type ShiftSummary = {
    id: number;
    code: string;
    name: string;
    color: string;
    /** false = shift sudah dinonaktifkan/dihapus tetapi masih tercatat di jadwal lama. */
    active?: boolean;
};

export type DayKey = 'mon' | 'tue' | 'wed' | 'thu' | 'fri' | 'sat' | 'sun';

/** Urutan ISO (Senin pertama), sama dengan `ShiftPattern::DAYS`. */
export const DAYS: { key: DayKey; short: string; long: string }[] = [
    { key: 'mon', short: 'Sen', long: 'Senin' },
    { key: 'tue', short: 'Sel', long: 'Selasa' },
    { key: 'wed', short: 'Rab', long: 'Rabu' },
    { key: 'thu', short: 'Kam', long: 'Kamis' },
    { key: 'fri', short: 'Jum', long: 'Jumat' },
    { key: 'sat', short: 'Sab', long: 'Sabtu' },
    { key: 'sun', short: 'Min', long: 'Minggu' },
];

export type PatternDays = Record<DayKey, ShiftSummary | null>;

export type ScheduleKind = 'shift' | 'holiday' | 'off' | 'unscheduled';

export type ScheduleCell = {
    kind: ScheduleKind;
    source: 'override' | 'pattern' | 'holiday' | 'none';
    shift: ShiftSummary | null;
    holiday: string | null;
    note: string | null;
    override: boolean;
    override_id: number | null;
};

export type EmployeeRef = { id: number; name: string; number: string };

export type CalendarDay = {
    date: string;
    day: number;
    weekday: string;
    isSunday: boolean;
};

/**
 * Kolom tanggal satu bulan `YYYY-MM`. Dihitung di UTC karena nilainya tanggal sipil (bukan momen),
 * agar tidak bergeser di perangkat yang zonanya berbeda dari kantor.
 */
export function monthDays(month: string): CalendarDay[] {
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    if (!match) {
        return [];
    }

    const year = Number(match[1]);
    const monthIndex = Number(match[2]) - 1;
    const count = new Date(Date.UTC(year, monthIndex + 1, 0)).getUTCDate();

    return Array.from({ length: count }, (_, i) => {
        const date = new Date(Date.UTC(year, monthIndex, i + 1));
        const isoWeekday = ((date.getUTCDay() + 6) % 7) + 1; // 1 = Senin … 7 = Minggu

        return {
            date: date.toISOString().slice(0, 10),
            day: i + 1,
            weekday: DAYS[isoWeekday - 1].short,
            isSunday: isoWeekday === 7,
        };
    });
}

/** `2026-11` → `2026-12` (atau sebelumnya dengan `delta` negatif). */
export function shiftMonth(month: string, delta: number): string {
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    if (!match) {
        return month;
    }
    const date = new Date(
        Date.UTC(Number(match[1]), Number(match[2]) - 1 + delta, 1),
    );

    return date.toISOString().slice(0, 7);
}

/** `2026-11` → "November 2026". */
export function formatMonth(month: string): string {
    const match = /^(\d{4})-(\d{2})$/.exec(month);
    if (!match) {
        return month;
    }

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: 'UTC',
        month: 'long',
        year: 'numeric',
    }).format(new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, 1)));
}

/** Label singkat sel kalender untuk pembaca layar & tooltip. */
export function describeCell(cell: ScheduleCell): string {
    const parts: string[] = [];
    if (cell.kind === 'shift' && cell.shift) {
        parts.push(`Shift ${cell.shift.code} (${cell.shift.name})`);
        if (cell.holiday) {
            parts.push(`masuk di hari libur ${cell.holiday}`);
        }
    } else if (cell.kind === 'holiday') {
        parts.push(`Libur: ${cell.holiday ?? 'hari libur'}`);
    } else if (cell.kind === 'off') {
        parts.push('Libur');
    } else {
        parts.push('Belum dijadwalkan');
    }
    if (cell.override) {
        parts.push('pengecualian');
    }
    if (cell.note) {
        parts.push(cell.note);
    }

    return parts.join(' · ');
}

/** Latar lembut dari warna shift, terbaca di tema terang & gelap (teks tetap warna foreground). */
export function shiftTint(color: string): string {
    return `color-mix(in srgb, ${color} 22%, transparent)`;
}

/**
 * Ringkasan pola untuk layar sempit: hari berurutan dengan isi sama digabung, mis.
 * "Sen–Sab SLS · Min Libur" atau "Sen–Jum PAGI · Sab SIANG · Min Libur".
 */
export function summarizePattern(days: PatternDays): string {
    const groups: { from: string; to: string; label: string }[] = [];
    DAYS.forEach((d) => {
        const label = days[d.key]?.code ?? 'Libur';
        const last = groups.at(-1);
        if (last && last.label === label) {
            last.to = d.short;
        } else {
            groups.push({ from: d.short, to: d.short, label });
        }
    });

    return groups
        .map((g) =>
            g.from === g.to
                ? `${g.from} ${g.label}`
                : `${g.from}–${g.to} ${g.label}`,
        )
        .join(' · ');
}
