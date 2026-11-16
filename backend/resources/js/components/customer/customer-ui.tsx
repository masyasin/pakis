import type { StatusTone } from '@/components/pakis/status-badge';
import { formatPhone } from '@/lib/employee-format';
import { cn } from '@/lib/utils';

/** Nilai `customers.status` (15 §4.6); label datang dari server (`status_label`). */
export type CustomerStatus =
    | 'prospect'
    | 'active'
    | 'credit_hold'
    | 'inactive'
    | 'merged';

export const CUSTOMER_STATUS_TONE: Record<CustomerStatus, StatusTone> = {
    prospect: 'info',
    active: 'success',
    credit_hold: 'warning',
    inactive: 'muted',
    merged: 'muted',
};

/**
 * HP pemilik kios. Server sudah memutuskan masking (SEC-108: penuh hanya untuk `customers.manage`),
 * jadi nilai termasking (`6281•••••789`) ditampilkan apa adanya tanpa tombol buka; nilai penuh
 * diformat `0812-3456-7890` agar mudah dibaca.
 */
export function CustomerPhone({
    phone,
    masked,
    className,
}: {
    phone: string | null;
    masked: boolean;
    className?: string;
}) {
    if (phone === null || phone === '') {
        return (
            <span className={cn('text-muted-foreground', className)}>–</span>
        );
    }

    return (
        <span className={cn('font-mono tabular-nums', className)}>
            {masked ? phone : formatPhone(phone)}
        </span>
    );
}
