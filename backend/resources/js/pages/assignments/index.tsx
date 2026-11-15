import { Head, Link } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Badge } from '@/components/ui/badge';
import { formatDate } from '@/lib/employee-format';
import { exportMethod, index } from '@/routes/admin/assignments';
import { show } from '@/routes/admin/employees';
import type { ListingProps } from '@/types/listing';

type AssignmentRow = {
    id: number;
    employee: { id: number; name: string | null; number: string | null };
    position: string | null;
    department: string | null;
    location: string | null;
    supervisor: string | null;
    mode_label: string | null;
    mode_from_position: boolean;
    is_primary: boolean;
    start_date: string;
    end_date: string | null;
    running: boolean;
    scheduled: boolean;
};

type Props = ListingProps<AssignmentRow> & { can: { export: boolean } };

const dash = <span className="text-muted-foreground">–</span>;

const columns: DataTableColumn<AssignmentRow>[] = [
    {
        key: 'employee',
        header: 'Pegawai',
        sortKey: 'employee_name',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.employee.name ?? '–'}</span>
                <span className="font-mono text-xs text-muted-foreground">
                    {r.employee.number}
                </span>
            </span>
        ),
    },
    {
        key: 'position',
        header: 'Jabatan',
        cell: (r) =>
            r.position ? (
                <span className="grid">
                    <span>{r.position}</span>
                    {r.department && (
                        <span className="text-xs text-muted-foreground">
                            {r.department}
                        </span>
                    )}
                </span>
            ) : (
                dash
            ),
    },
    { key: 'location', header: 'Lokasi', cell: (r) => r.location ?? dash },
    { key: 'supervisor', header: 'Atasan', cell: (r) => r.supervisor ?? dash },
    {
        key: 'mode',
        header: 'Mode absen',
        cell: (r) =>
            r.mode_label ? (
                <span>
                    {r.mode_label}
                    {r.mode_from_position && (
                        <span className="text-xs text-muted-foreground">
                            {' '}
                            (ikut jabatan)
                        </span>
                    )}
                </span>
            ) : (
                dash
            ),
    },
    {
        key: 'start_date',
        header: 'Mulai',
        sortKey: 'start_date',
        className: 'whitespace-nowrap',
        cell: (r) => formatDate(r.start_date),
    },
    {
        key: 'end_date',
        header: 'Selesai',
        className: 'whitespace-nowrap',
        cell: (r) => (r.end_date ? formatDate(r.end_date) : dash),
    },
    {
        key: 'kind',
        header: 'Jenis',
        cell: (r) => (
            <span className="flex flex-wrap gap-1.5">
                {r.is_primary ? (
                    <Badge variant="secondary">Utama</Badge>
                ) : (
                    <Badge variant="outline">Tambahan</Badge>
                )}
                {r.scheduled ? (
                    <StatusBadge
                        status="assignment.scheduled"
                        label="Terjadwal"
                        tone="info"
                    />
                ) : r.running ? (
                    <StatusBadge
                        status="assignment.active"
                        label="Berjalan"
                        tone="success"
                    />
                ) : (
                    <StatusBadge
                        status="assignment.ended"
                        label="Selesai"
                        tone="muted"
                    />
                )}
            </span>
        ),
    },
];

/** Penugasan (05 §4.6, FR-026). Mutasi & penugasan tambahan dibuat dari detail pegawai. */
export default function AssignmentsIndex({ rows, listing, can }: Props) {
    return (
        <>
            <Head title="Penugasan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Penugasan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Riwayat jabatan, lokasi, dan atasan setiap pegawai.
                        Mutasi dan penugasan tambahan (rangkap) dibuat dari{' '}
                        <Link
                            href="/pegawai"
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            halaman pegawai
                        </Link>
                        .
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar penugasan"
                    rowHref={(r) =>
                        show(r.employee.id, { query: { tab: 'penugasan' } }).url
                    }
                    toolbarActions={
                        can.export ? (
                            <ExportButton
                                href={exportMethod().url}
                                total={listing.pagination.total}
                            />
                        ) : undefined
                    }
                />
            </div>
        </>
    );
}

AssignmentsIndex.layout = {
    breadcrumbs: [{ title: 'Penugasan', href: index() }],
};
