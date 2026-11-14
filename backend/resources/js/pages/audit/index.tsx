import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import type { ListingProps } from '@/types/listing';

type AuditRow = {
    id: number;
    occurred_at: string;
    actor: string;
    event: string;
    object: string | null;
    ip: string | null;
};

const columns: DataTableColumn<AuditRow>[] = [
    {
        key: 'occurred_at',
        header: 'Waktu',
        sortKey: 'occurred_at',
        className: 'whitespace-nowrap tabular-nums',
        cell: (r) => r.occurred_at,
    },
    { key: 'actor', header: 'Pelaku', cell: (r) => r.actor },
    {
        key: 'event',
        header: 'Event',
        primary: true,
        cell: (r) => <span className="font-mono text-xs">{r.event}</span>,
    },
    {
        key: 'object',
        header: 'Objek',
        cell: (r) =>
            r.object ? (
                <span className="font-mono text-xs">{r.object}</span>
            ) : (
                '–'
            ),
    },
    {
        key: 'ip',
        header: 'IP',
        className: 'font-mono text-xs',
        cell: (r) => r.ip ?? '–',
    },
];

type Props = ListingProps<AuditRow> & { canExport: boolean };

/** Audit log (05 §4.4). Klik baris untuk melihat nilai lama/baru. */
export default function AuditIndex({ rows, listing, canExport }: Props) {
    return (
        <>
            <Head title="Audit log" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Audit log</h1>
                    <p className="text-sm text-muted-foreground">
                        Jejak perubahan data penting. Catatan tidak bisa diubah
                        atau dihapus.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar audit log"
                    rowHref={(r) => `/audit/${r.id}`}
                    toolbarActions={
                        canExport ? (
                            <ExportButton
                                href="/audit/ekspor"
                                total={listing.pagination.total}
                            />
                        ) : undefined
                    }
                />
            </div>
        </>
    );
}

AuditIndex.layout = {
    breadcrumbs: [{ title: 'Audit log', href: '/audit' }],
};
