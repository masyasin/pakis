/**
 * Uang rupiah dari server (string kanonik "12500.00", ADR-101) → tampilan id-ID "Rp12.500" atau
 * "Rp12.500,50". Diproses sebagai teks: float tidak pernah dipakai untuk uang.
 */
export function formatRupiah(canonical: string | null | undefined): string {
    if (canonical === null || canonical === undefined || canonical === '') {
        return '–';
    }

    const match = /^(-?)(\d+)(?:\.(\d+))?$/.exec(canonical.trim());
    if (!match) {
        return canonical;
    }

    const [, sign, whole, fraction = ''] = match;
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const cents = fraction.padEnd(2, '0').slice(0, 2);

    return `${sign}Rp${grouped}${cents === '00' ? '' : `,${cents}`}`;
}

/** "12500.00" → "12.500" untuk diisi kembali ke input (tanpa "Rp"). */
export function moneyInputValue(canonical: string): string {
    return formatRupiah(canonical).replace('Rp', '');
}
