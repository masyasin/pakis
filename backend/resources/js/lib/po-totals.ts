/**
 * Perkiraan total PO di layar, rumus SAMA dengan `PurchaseOrderTotals` di server (BR-160):
 * baris = qty × harga × (1 − diskon%), dibulatkan half-up 2 desimal; subtotal = Σ qty×harga;
 * diskon = subtotal − Σ baris. Dihitung dengan BigInt berskala tetap, tidak pernah float
 * (ADR-101). Ini hanya pratinjau: angka resmi selalu dihitung ulang server saat disimpan.
 */

/** "12.5" pada skala 3 → 12500n; nilai tidak sah → null. */
function scaled(value: string, scale: number): bigint | null {
    const match = /^(\d+)(?:\.(\d+))?$/.exec(value.trim());
    if (!match || (match[2] ?? '').length > scale) {
        return null;
    }

    return BigInt(match[1] + (match[2] ?? '').padEnd(scale, '0'));
}

/** Bagi bilangan non-negatif dengan pembulatan half-up. */
function divideHalfUp(value: bigint, divisor: bigint): bigint {
    return (value + divisor / 2n) / divisor;
}

/** Sen (skala 2) → string kanonik "12500.00". */
function fromCents(cents: bigint): string {
    const text = cents.toString().padStart(3, '0');

    return `${text.slice(0, -2)}.${text.slice(-2)}`;
}

export type PoLineInput = {
    /** Qty kanonik ("2.5"), maks 3 desimal. */
    qty: string;
    /** Harga kanonik ("12500.00"), maks 2 desimal. */
    unitPrice: string;
    /** Diskon persen kanonik ("2.5"), maks 4 desimal; kosong = 0. */
    discountPct: string;
};

/** Total satu baris, atau null bila ada isian yang belum sah. */
export function poLineTotal(line: PoLineInput): string | null {
    const qty = scaled(line.qty, 3);
    const price = scaled(line.unitPrice, 2);
    const discount = scaled(
        line.discountPct === '' ? '0' : line.discountPct,
        4,
    );
    if (
        qty === null ||
        price === null ||
        discount === null ||
        discount >= 1_000_000n
    ) {
        return null;
    }

    // qty(3) × harga(2) × (100 − diskon)(4) = skala 9; bagi 100 lalu ke skala 2 = bagi 10^9.
    return fromCents(
        divideHalfUp(qty * price * (1_000_000n - discount), 1_000_000_000n),
    );
}

export type PoTotals = {
    subtotal: string;
    discountTotal: string;
    grandTotal: string;
};

/** Total dokumen dari baris yang sudah sah (baris belum lengkap dilewati). */
export function poTotals(lines: PoLineInput[]): PoTotals {
    let gross = 0n;
    let net = 0n;
    for (const line of lines) {
        const total = poLineTotal(line);
        const qty = scaled(line.qty, 3);
        const price = scaled(line.unitPrice, 2);
        if (total === null || qty === null || price === null) {
            continue;
        }
        // Bruto skala 5 → sen (half-up), sama dengan Money::of() di server.
        gross += divideHalfUp(qty * price, 1000n);
        net += scaled(total, 2) ?? 0n;
    }

    return {
        subtotal: fromCents(gross),
        discountTotal: fromCents(gross - net),
        grandTotal: fromCents(net),
    };
}
