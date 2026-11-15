import { cn } from '@/lib/utils';
import type { ShiftSummary } from '@/lib/schedule';

/**
 * Kode shift dengan titik warna (warna shift T-033). Warna hanya penanda; kode tetap tertulis agar
 * tidak bergantung pada warna (aksesibilitas). Shift nonaktif tetap tampil, dicoret tipis.
 */
export function ShiftChip({
    shift,
    className,
}: {
    shift: ShiftSummary;
    className?: string;
}) {
    const inactive = shift.active === false;

    return (
        <span
            title={inactive ? `${shift.name} (nonaktif)` : shift.name}
            className={cn(
                'inline-flex items-center gap-1.5 font-mono text-[0.8rem] whitespace-nowrap',
                inactive && 'text-muted-foreground line-through',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className="size-2.5 shrink-0 rounded-full border"
                style={{ backgroundColor: shift.color }}
            />
            {shift.code}
        </span>
    );
}

/** "Libur" untuk hari tanpa shift di pola / pengecualian libur. */
export function DayOffLabel({ className }: { className?: string }) {
    return (
        <span className={cn('text-[0.8rem] text-muted-foreground', className)}>
            Libur
        </span>
    );
}
