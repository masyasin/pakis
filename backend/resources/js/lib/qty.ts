/**
 * Aritmetika kuantitas tanpa float (ADR-101), selaras dengan `App\Support\Money\Qty` di server:
 * skala 3 desimal, pembulatan half-up. Angka disimpan sebagai BigInt bernilai ×1000.
 */

const SCALE = 3n;
const FACTOR = 10n ** SCALE;

/**
 * Teks ketikan id-ID → string kanonik ("1.234,5" → "1234.5"), atau null bila bukan angka.
 * Aturan sama dengan `Qty::fromInput()`: koma = desimal, titik = ribuan; tanpa koma, pola ribuan
 * murni ("1.500") dianggap ribuan, selain itu titik dianggap desimal ("2.5").
 */
export function parseQtyInput(input: string): string | null {
    let text = input.replace(/[\s ]/g, '');
    if (text.includes(',')) {
        text = text.replace(/\./g, '').replace(',', '.');
    } else if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) {
        text = text.replace(/\./g, '');
    }

    return /^-?\d+(\.\d+)?$/.test(text) ? text : null;
}

/** String kanonik → BigInt ×1000, dibulatkan half-up (menjauhi nol untuk tepat setengah). */
function toScaled(value: string): bigint {
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = (negative ? value.slice(1) : value).split(
        '.',
    );
    const digits = BigInt(
        whole +
            fraction.padEnd(Number(SCALE) + 1, '0').slice(0, Number(SCALE) + 1),
    );
    const scaled = (digits + 5n) / 10n;

    return negative ? -scaled : scaled;
}

/** BigInt ×1000 → string kanonik 3 desimal ("240.000"). */
function fromScaled(scaled: bigint): string {
    const negative = scaled < 0n;
    const abs = negative ? -scaled : scaled;
    const whole = abs / FACTOR;
    const fraction = (abs % FACTOR).toString().padStart(Number(SCALE), '0');

    return `${negative ? '-' : ''}${whole}.${fraction}`;
}

/** qty × faktor satuan → qty satuan dasar, 3 desimal half-up (BR-150). */
export function toBaseQty(qty: string, factor: string): string {
    const product = toScaled(qty) * toScaled(factor); // skala 6
    const half = FACTOR / 2n;
    const rounded =
        product >= 0n
            ? (product + half) / FACTOR
            : -((-product + half) / FACTOR);

    return fromScaled(rounded);
}

/** Bilangan bulat? (satuan `integer_only` menolak pecahan, `QTY_NOT_INTEGER`). */
export function isWholeQty(qty: string): boolean {
    return toScaled(qty) % FACTOR === 0n;
}

/** Tampilan id-ID tanpa nol di belakang koma: "1234.500" → "1.234,5". */
export function formatQty(canonical: string): string {
    const negative = canonical.startsWith('-');
    const [whole, fraction = ''] = (
        negative ? canonical.slice(1) : canonical
    ).split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const trimmed = fraction.replace(/0+$/, '');

    return `${negative ? '-' : ''}${grouped}${trimmed ? `,${trimmed}` : ''}`;
}
