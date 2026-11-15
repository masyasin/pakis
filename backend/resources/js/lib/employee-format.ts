/**
 * Format tampilan data pegawai (T-024, 07 §7).
 */

/**
 * Tanggal sipil `YYYY-MM-DD` (kolom DATE: tanggal lahir, tanggal masuk) → "3 Nov 2026". Dibaca
 * sebagai UTC karena nilainya sudah tanggal kalender, bukan momen waktu: tanpa ini tanggal bisa
 * bergeser satu hari di perangkat yang zonanya di barat UTC. Nilai tidak sah dikembalikan apa adanya.
 */
export function formatDate(isoDate: string | null): string {
    if (isoDate === null) {
        return '–';
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);
    if (!match) {
        return isoDate;
    }

    return new Intl.DateTimeFormat('id-ID', {
        timeZone: 'UTC',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    }).format(
        new Date(
            Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
        ),
    );
}

/** `YYYY-MM-DD` → [tahun, bulan, hari] atau null. */
function civil(isoDate: string): [number, number, number] | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate);

    return match
        ? [Number(match[1]), Number(match[2]), Number(match[3])]
        : null;
}

/** Selisih penuh (tahun, bulan) antara dua tanggal sipil, seperti umur di KTP. */
function fullYearsMonths(
    from: [number, number, number],
    to: [number, number, number],
): { years: number; months: number } {
    let months = (to[0] - from[0]) * 12 + (to[1] - from[1]);
    if (to[2] < from[2]) {
        months -= 1;
    }
    months = Math.max(0, months);

    return { years: Math.floor(months / 12), months: months % 12 };
}

/** Tanggal hari ini sebagai tanggal sipil di zona kantor (bukan zona perangkat). */
export function todayIn(timeZone: string, now: Date = new Date()): string {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(now);
}

/** Masa kerja "9 th 9 bln" / "4 bln" / "Kurang dari sebulan" dari tanggal masuk sampai `until`. */
export function formatTenure(joinDate: string, until: string): string {
    const from = civil(joinDate);
    const to = civil(until);
    if (!from || !to) {
        return '–';
    }

    const { years, months } = fullYearsMonths(from, to);
    if (years === 0 && months === 0) {
        return 'Kurang dari sebulan';
    }

    return [years > 0 ? `${years} th` : '', months > 0 ? `${months} bln` : '']
        .filter(Boolean)
        .join(' ');
}

/** Umur dalam tahun penuh pada tanggal `on`, atau null bila tanggal lahir tidak ada/tidak sah. */
export function ageOn(birthDate: string | null, on: string): number | null {
    const from = birthDate ? civil(birthDate) : null;
    const to = civil(on);

    return from && to ? fullYearsMonths(from, to).years : null;
}

/** HP tersimpan `62812…` (BR-050) → tampil `0812-3456-7890` seperti yang dikenal pegawai. */
export function formatPhone(phone: string | null): string {
    if (phone === null || phone === '') {
        return '–';
    }

    const local = phone.startsWith('62') ? `0${phone.slice(2)}` : phone;

    return local.replace(/^(\d{4})(\d{4})(\d+)$/, '$1-$2-$3');
}
