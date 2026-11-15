import { Head } from '@inertiajs/react';
import { CloudOff, PencilLine, TriangleAlert } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDate } from '@/lib/employee-format';
import { exportMethod, index, show } from '@/routes/admin/attendance';
import type { ListingProps } from '@/types/listing';

type AttendanceRow = {
    id: number;
    work_date: string;
    employee: { id: number; name: string | null; number: string | null };
    location: string | null;
    shift: { code: string; name: string } | null;
    check_in: string | null;
    check_out: string | null;
    late_minutes: number;
    status: string;
    status_label: string;
    is_offline: boolean;
    is_corrected: boolean;
    has_anomaly: boolean;
};

type Props = ListingProps<AttendanceRow> & { can: { export: boolean } };

const dash = <span className="text-muted-foreground">–</span>;

/** Penanda baris (07 §3.4): ikon + label teks agar tidak bergantung warna saja. */
function Marks({ row }: { row: AttendanceRow }) {
    const marks: [boolean, LucideIcon, string, string][] = [
        [row.is_offline, CloudOff, 'Offline', 'text-status-info'],
        [row.is_corrected, PencilLine, 'Dikoreksi', 'text-muted-foreground'],
        [row.has_anomaly, TriangleAlert, 'Anomali', 'text-status-warning'],
    ];
    const active = marks.filter(([on]) => on);
    if (active.length === 0) {
        return dash;
    }

    return (
        <span className="flex flex-wrap gap-x-2 gap-y-1">
            {active.map(([, Icon, label, tone]) => (
                <span
                    key={label}
                    className={`inline-flex items-center gap-1 text-xs ${tone}`}
                >
                    <Icon className="size-3.5" aria-hidden="true" />
                    {label}
                </span>
            ))}
        </span>
    );
}

const columns: DataTableColumn<AttendanceRow>[] = [
    {
        key: 'work_date',
        header: 'Tanggal',
        sortKey: 'work_date',
        className: 'whitespace-nowrap',
        cell: (r) => formatDate(r.work_date),
    },
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
    { key: 'location', header: 'Lokasi', cell: (r) => r.location ?? dash },
    {
        key: 'shift',
        header: 'Shift',
        cell: (r) => (r.shift ? r.shift.name : dash),
    },
    {
        key: 'check_in',
        header: 'Masuk',
        sortKey: 'check_in_at',
        className: 'tabular-nums whitespace-nowrap',
        cell: (r) => r.check_in ?? dash,
    },
    {
        key: 'check_out',
        header: 'Pulang',
        className: 'tabular-nums whitespace-nowrap',
        cell: (r) => r.check_out ?? dash,
    },
    {
        key: 'late_minutes',
        header: 'Telat (mnt)',
        sortKey: 'late_minutes',
        className: 'tabular-nums',
        cell: (r) => (r.late_minutes > 0 ? r.late_minutes : dash),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => <StatusBadge status={r.status} label={r.status_label} />,
    },
    { key: 'marks', header: 'Penanda', cell: (r) => <Marks row={r} /> },
];

/** Presensi harian (05 §4.10, FR-039, 07 §3.4 `attendance_days`). Default: hari ini. */
export default function AttendanceIndex({ rows, listing, can }: Props) {
    return (
        <>
            <Head title="Presensi Harian" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Presensi Harian
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Hasil presensi per pegawai per hari. Status "Tidak
                        hadir" muncul setelah jam tutup hari.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar presensi"
                    rowHref={(r) => show(r.id).url}
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

AttendanceIndex.layout = {
    breadcrumbs: [{ title: 'Presensi Harian', href: index() }],
};
