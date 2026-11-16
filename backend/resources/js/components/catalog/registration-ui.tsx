import { StatusBadge } from '@/components/pakis/status-badge';

/** Baris izin edar dari server (`ProductRegistrationController::row`). */
export type RegistrationRow = {
    id: number;
    registration_no: string;
    holder_name: string | null;
    issued_at: string | null;
    valid_until: string;
    days_left: number | null;
    status: 'active' | 'expired' | 'revoked';
    status_label: string;
    expiring: boolean;
    note: string | null;
    document_url: string | null;
};

/** Lencana status izin: berlaku (hijau), akan habis (kuning), habis/dicabut (merah/abu). */
export function RegistrationBadge({ row }: { row: RegistrationRow }) {
    if (row.status === 'active' && row.days_left !== null) {
        return row.expiring ? (
            <StatusBadge status="expiring" label="Akan habis" tone="warning" />
        ) : (
            <StatusBadge
                status="active"
                label={row.status_label}
                tone="success"
            />
        );
    }

    return (
        <StatusBadge
            status={row.status}
            label={row.status_label}
            tone={row.status === 'revoked' ? 'muted' : 'danger'}
        />
    );
}

/** "12 hari lagi" / "Hari ini" / "–" untuk izin yang tidak berlaku lagi. */
export function daysLeftLabel(days: number | null): string {
    if (days === null) {
        return '–';
    }

    return days === 0 ? 'Habis hari ini' : `${days} hari lagi`;
}
