import { useId } from 'react';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/pakis/native-select';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatQty, isWholeQty, parseQtyInput, toBaseQty } from '@/lib/qty';
import { cn } from '@/lib/utils';

export type QtyUnit = {
    id: number;
    label: string;
    /** "1 satuan ini = factor satuan dasar", string desimal (mis. "24"). */
    factor: string;
    integerOnly: boolean;
};

type Props = {
    label: string;
    /** Teks ketikan apa adanya (format id-ID); kirim `parseQtyInput(value)` ke server. */
    value: string;
    onChange: (value: string) => void;
    units: QtyUnit[];
    unitId: number;
    onUnitChange: (unitId: number) => void;
    /** Nama satuan dasar produk, untuk teks konversi ("botol"). */
    baseUnitLabel: string;
    error?: string;
    className?: string;
};

/**
 * Input kuantitas + pilih satuan (15 §7 "QtyInput", BR-150): desimal id-ID, dan di bawahnya
 * konversi ke satuan dasar ("= 240 botol") yang dihitung tanpa float, sama dengan UnitConverter di
 * server. Satuan `integer_only` diberi peringatan saat diketik pecahan; server tetap penentunya
 * (`QTY_NOT_INTEGER`).
 */
export function QtyInput({
    label,
    value,
    onChange,
    units,
    unitId,
    onUnitChange,
    baseUnitLabel,
    error,
    className,
}: Props) {
    const id = useId();
    const unit = units.find((u) => u.id === unitId) ?? units[0];
    const canonical = parseQtyInput(value);
    const fractionNotAllowed =
        canonical !== null &&
        unit?.integerOnly === true &&
        !isWholeQty(canonical);
    const hint =
        canonical === null || unit === undefined
            ? null
            : fractionNotAllowed
              ? `Satuan ${unit.label.toLowerCase()} hanya bilangan bulat.`
              : unit.factor === '1'
                ? null
                : `= ${formatQty(toBaseQty(canonical, unit.factor))} ${baseUnitLabel.toLowerCase()}`;

    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={`${id}-qty`}>{label}</Label>
            <div className="flex gap-2">
                <Input
                    id={`${id}-qty`}
                    inputMode="decimal"
                    autoComplete="off"
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    className="min-w-0 flex-1 tabular-nums"
                    aria-invalid={!!error || fractionNotAllowed || undefined}
                    aria-describedby={hint ? `${id}-hint` : undefined}
                />
                <NativeSelect
                    aria-label="Satuan"
                    value={String(unit?.id ?? '')}
                    onChange={(e) => onUnitChange(Number(e.target.value))}
                    className="w-32 shrink-0"
                >
                    {units.map((u) => (
                        <option key={u.id} value={String(u.id)}>
                            {u.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
            {hint && (
                <p
                    id={`${id}-hint`}
                    className={cn(
                        'text-xs tabular-nums',
                        fractionNotAllowed
                            ? 'text-status-warning'
                            : 'text-muted-foreground',
                    )}
                >
                    {hint}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}
