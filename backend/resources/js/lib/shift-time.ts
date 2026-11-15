/**
 * Jam shift (BR-060) untuk tampilan. Nilai jam = jam dinding lokal `HH:MM` (bukan UTC), jadi
 * dihitung sebagai menit sejak 00.00 tanpa objek Date/zona waktu.
 */

const MINUTES_PER_DAY = 24 * 60;

/** `07:30` → `07.30` (format jam Indonesia, 07 §7). */
export function formatClock(hhmm: string): string {
    return hhmm.slice(0, 5).replace(':', '.');
}

function toMinutes(hhmm: string): number | null {
    const match = /^(\d{2}):(\d{2})/.exec(hhmm);
    if (!match) {
        return null;
    }

    return Number(match[1]) * 60 + Number(match[2]);
}

/** Menit (boleh negatif / ≥ 24 jam) → `HH.MM` + penanda hari. */
function clockAt(minutes: number): { clock: string; dayOffset: number } {
    const dayOffset = Math.floor(minutes / MINUTES_PER_DAY);
    const inDay =
        ((minutes % MINUTES_PER_DAY) + MINUTES_PER_DAY) % MINUTES_PER_DAY;
    const hh = String(Math.floor(inDay / 60)).padStart(2, '0');
    const mm = String(inDay % 60).padStart(2, '0');

    return { clock: `${hh}.${mm}`, dayOffset };
}

function label({ clock, dayOffset }: { clock: string; dayOffset: number }) {
    if (dayOffset > 0) {
        return `${clock} (hari berikutnya)`;
    }
    if (dayOffset < 0) {
        return `${clock} (hari sebelumnya)`;
    }

    return clock;
}

export type ShiftWindowInput = {
    start_time: string;
    end_time: string;
    crosses_midnight: boolean;
    checkin_open_min: number;
    checkout_close_min: number;
};

/**
 * Ringkasan jendela absen BR-060: masuk sah dari `start − checkin_open_min` sampai `end`; pulang
 * paling lambat `end + checkout_close_min`. Shift lintas hari: `end` jatuh di hari berikutnya.
 * Null bila jam belum lengkap.
 */
export function shiftWindowSummary(shift: ShiftWindowInput): string | null {
    const start = toMinutes(shift.start_time);
    const endRaw = toMinutes(shift.end_time);
    if (start === null || endRaw === null) {
        return null;
    }

    const end = shift.crosses_midnight ? endRaw + MINUTES_PER_DAY : endRaw;
    const checkinFrom = clockAt(start - (shift.checkin_open_min || 0));
    const checkinTo = clockAt(end);
    const checkoutTo = clockAt(end + (shift.checkout_close_min || 0));

    return `Absen masuk ${label(checkinFrom)} – ${label(checkinTo)}; absen pulang paling lambat ${label(checkoutTo)}.`;
}
