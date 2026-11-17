import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

/**
 * Rupiah kanonik ("50000000.5") → teks id-ID ("50.000.000,5"). String diproses per digit, bukan
 * lewat Number, agar nominal besar tidak dibulatkan float (ADR-101).
 */
export function groupRupiah(canonical: string): string {
    const negative = canonical.startsWith('-');
    const [intPart, frac] = canonical.replace('-', '').split('.');
    const grouped = (intPart || '0').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const text = frac !== undefined ? `${grouped},${frac}` : grouped;

    return negative ? `-${text}` : text;
}

/**
 * Teks ketikan rupiah id-ID ("50.000.000,50") → nilai kanonik untuk server ("50000000.50").
 * Titik = pemisah ribuan, koma = desimal (maks 2 digit). Teks kosong → ''.
 */
export function parseRupiah(text: string): string {
    const negative = text.trim().startsWith('-');
    const [intRaw, ...rest] = text.split(',');
    const intDigits = intRaw.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
    const frac = rest.join('').replace(/\D/g, '').slice(0, 2);

    if (intDigits === '' && frac === '') {
        return '';
    }

    const value = frac !== '' ? `${intDigits || '0'}.${frac}` : intDigits;

    return negative ? `-${value}` : value;
}

/** "50000000.00" → "50000000", "7.50" → "7.5": bentuk pembanding & tampilan tanpa sen nol. */
export function trimZeroFraction(canonical: string): string {
    return canonical.includes('.')
        ? canonical.replace(/0+$/, '').replace(/\.$/, '')
        : canonical;
}

/** Rapikan teks ketikan: ribuan diberi titik, koma desimal yang sedang diketik dipertahankan. */
export function reformatRupiahInput(text: string): string {
    const canonical = parseRupiah(text);
    if (canonical === '') {
        return '';
    }
    const [intPart, frac] = canonical.split('.');

    return text.includes(',')
        ? `${groupRupiah(intPart)},${frac ?? ''}`
        : groupRupiah(intPart);
}

/**
 * Input rupiah berformat id-ID (15 §7.2 `MoneyInput`). Teks ketikan disimpan lokal (agar "50.000,"
 * tidak hilang saat mengetik), nilai yang dikirim ke induk selalu string kanonik ("50000000.5")
 * sesuai validasi server — tidak pernah Number/float.
 */
export function MoneyInput({
    id,
    value,
    onChange,
    disabled,
    describedBy,
    invalid,
    placeholder,
    className,
}: {
    id: string;
    /** Nilai kanonik ("12500.00" / "12500" / ""). */
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    describedBy?: string;
    invalid?: boolean;
    placeholder?: string;
    /** Lebar kotak; bawaan `w-56` (cukup untuk ratusan miliar). */
    className?: string;
}) {
    const [text, setText] = useState('');
    // Teks lokal dipakai selama masih mewakili nilai induk; bila induk berubah (mis. nilai awal),
    // tampilan diturunkan ulang dari nilai kanonik.
    const display =
        trimZeroFraction(parseRupiah(text)) === trimZeroFraction(value)
            ? text
            : value === ''
              ? ''
              : groupRupiah(trimZeroFraction(value));

    return (
        <div
            className={cn(
                'flex w-56 items-center rounded-md border border-input shadow-xs focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30',
                invalid && 'border-destructive',
                className,
            )}
        >
            <span className="pl-3 text-sm text-muted-foreground" aria-hidden>
                Rp
            </span>
            <Input
                id={id}
                inputMode="decimal"
                autoComplete="off"
                className="border-0 text-right tabular-nums shadow-none focus-visible:ring-0 dark:bg-transparent"
                value={display}
                disabled={disabled}
                aria-describedby={describedBy}
                aria-invalid={invalid ? true : undefined}
                placeholder={placeholder}
                onChange={(e) => {
                    const next = reformatRupiahInput(e.target.value);
                    setText(next);
                    onChange(parseRupiah(next));
                }}
            />
        </div>
    );
}
