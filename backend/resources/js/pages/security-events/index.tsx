import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import type { StatusTone } from '@/components/pakis/status-badge';
import type { ListingProps } from '@/types/listing';

type SecurityRow = {
    id: number;
    occurred_at: string;
    event: string;
    event_label: string;
    user: string | null;
    identifier: string | null;
    channel: string;
    ip: string | null;
};

/** Event yang perlu perhatian lebih menonjol (SEC-041). */
const TONE: Record<string, StatusTone> = {
    login_failed: 'warning',
    mfa_failed: 'warning',
    lockout: 'danger',
    attendance_mock_detected: 'danger',
    mass_export: 'danger',
    sensitive_data_viewed: 'warning',
    login_success: 'success',
    mfa_enabled: 'success',
    password_changed: 'info',
};

const columns: DataTableColumn<SecurityRow>[] = [
    {
        key: 'occurred_at',
        header: 'Waktu',
        sortKey: 'occurred_at',
        className: 'whitespace-nowrap tabular-nums',
        cell: (r) => r.occurred_at,
    },
    {
        key: 'event',
        header: 'Event',
        primary: true,
        cell: (r) => (
            <StatusBadge
                status={r.event}
                label={r.event_label}
                tone={TONE[r.event] ?? 'muted'}
            />
        ),
    },
    {
        key: 'user',
        header: 'Pengguna / identifier',
        cell: (r) => r.user ?? r.identifier ?? '–',
    },
    { key: 'channel', header: 'Kanal', cell: (r) => r.channel },
    {
        key: 'ip',
        header: 'IP',
        className: 'font-mono text-xs',
        cell: (r) => r.ip ?? '–',
    },
];

type Props = ListingProps<SecurityRow> & { canExport: boolean };

/** Log keamanan (05 §4.4). */
export default function SecurityEventsIndex({
    rows,
    listing,
    canExport,
}: Props) {
    return (
        <>
            <Head title="Log keamanan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Log keamanan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Login, perubahan akses, perangkat, dan ekspor besar.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar log keamanan"
                    toolbarActions={
                        canExport ? (
                            <ExportButton
                                href="/log-keamanan/ekspor"
                                total={listing.pagination.total}
                            />
                        ) : undefined
                    }
                />
            </div>
        </>
    );
}

SecurityEventsIndex.layout = {
    breadcrumbs: [{ title: 'Log keamanan', href: '/log-keamanan' }],
};
