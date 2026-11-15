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

/** HP tersimpan `62812…` (BR-050) → tampil `0812-3456-7890` seperti yang dikenal pegawai. */
export function formatPhone(phone: string | null): string {
    if (phone === null || phone === '') {
        return '–';
    }

    const local = phone.startsWith('62') ? `0${phone.slice(2)}` : phone;

    return local.replace(/^(\d{4})(\d{4})(\d+)$/, '$1-$2-$3');
}
