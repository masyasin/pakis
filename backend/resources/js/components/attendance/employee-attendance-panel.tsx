import { Link } from '@inertiajs/react';
import {
    CalendarCheck,
    CloudOff,
    PencilLine,
    TriangleAlert,
} from 'lucide-react';
import { DetailCard, EmptyPanel } from '@/components/employee/detail-ui';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDate } from '@/lib/employee-format';
import { index, show } from '@/routes/admin/attendance';

export type EmployeeAttendanceRow = {
    id: number;
    work_date: string;
    shift: string | null;
    check_in: string | null;
    check_out: string | null;
    late_minutes: number;
    status: string;
    status_label: string;
    is_offline: boolean;
    is_corrected: boolean;
    has_anomaly: boolean;
};

export type EmployeeAttendanceData = {
    rows: EmployeeAttendanceRow[];
    summary: Record<string, number>;
    from: string;
    to: string;
};

/** Ringkasan 30 hari: status yang paling sering dicari HRD lebih dulu. */
const SUMMARY: [string, string][] = [
    ['present', 'Hadir'],
    ['late', 'Telat'],
    ['early_leave', 'Pulang cepat'],
    ['no_checkout', 'Tidak absen pulang'],
    ['absent', 'Tidak hadir'],
];

/** Tab Presensi di detail pegawai (T-038): 14 hari terakhir + ringkasan 30 hari. */
export function EmployeeAttendancePanel({
    employeeNumber,
    data,
}: {
    employeeNumber: string;
    data: EmployeeAttendanceData | null;
}) {
    if (data === null) {
        return (
            <EmptyPanel
                icon={CalendarCheck}
                title="Presensi tidak bisa dilihat"
                text="Anda tidak punya izin melihat presensi."
            />
        );
    }

    const allLink = index({
        query: {
            q: employeeNumber,
            'filter[work_date][from]': data.from,
            'filter[work_date][to]': data.to,
        },
    });

    if (data.rows.length === 0) {
        return (
            <EmptyPanel
                icon={CalendarCheck}
                title="Belum ada presensi"
                text="Belum ada presensi 14 hari terakhir. Status Tidak hadir/Libur muncul setelah jam tutup hari."
            />
        );
    }

    return (
        <DetailCard
            title="Presensi 14 hari terakhir"
            icon={CalendarCheck}
            action={
                <Link
                    href={allLink}
                    className="text-sm text-primary hover:underline"
                >
                    Lihat di Presensi Harian
                </Link>
            }
        >
            <dl className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-5">
                {SUMMARY.map(([key, label]) => (
                    <div key={key} className="rounded-lg border px-3 py-2">
                        <dt className="text-xs text-muted-foreground">
                            {label} (30 hari)
                        </dt>
                        <dd className="text-lg font-semibold tabular-nums">
                            {data.summary[key] ?? 0}
                        </dd>
                    </div>
                ))}
            </dl>
            <ul className="divide-y">
                {data.rows.map((r) => (
                    <li key={r.id}>
                        <Link
                            href={show(r.id)}
                            className="-mx-2 flex flex-wrap items-center gap-x-4 gap-y-1 rounded-md px-2 py-2.5 hover:bg-muted/50"
                        >
                            <span className="grid min-w-32">
                                <span className="text-sm">
                                    {formatDate(r.work_date)}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {r.shift ?? 'Tanpa shift'}
                                </span>
                            </span>
                            <span className="mr-auto text-sm tabular-nums">
                                {r.check_in === null && r.check_out === null
                                    ? '–'
                                    : `${r.check_in ?? '–'} – ${r.check_out ?? '–'}`}
                                {r.late_minutes > 0 && (
                                    <span className="ml-2 text-xs text-status-warning">
                                        telat {r.late_minutes} mnt
                                    </span>
                                )}
                            </span>
                            <span className="flex items-center gap-1.5 text-muted-foreground">
                                {r.is_offline && (
                                    <CloudOff
                                        className="size-3.5"
                                        aria-label="Offline"
                                    />
                                )}
                                {r.is_corrected && (
                                    <PencilLine
                                        className="size-3.5"
                                        aria-label="Dikoreksi"
                                    />
                                )}
                                {r.has_anomaly && (
                                    <TriangleAlert
                                        className="size-3.5 text-status-warning"
                                        aria-label="Anomali"
                                    />
                                )}
                            </span>
                            <StatusBadge
                                status={r.status}
                                label={r.status_label}
                            />
                        </Link>
                    </li>
                ))}
            </ul>
        </DetailCard>
    );
}
