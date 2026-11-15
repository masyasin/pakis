import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { MonthCalendar } from '@/components/attendance/month-calendar';
import type { CalendarDay } from '@/components/attendance/month-calendar';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { index as dailyIndex } from '@/routes/admin/attendance';
import { exportMethod, index } from '@/routes/admin/attendance-summary';
import type { ListingProps } from '@/types/listing';

type SummaryRow = {
    employee: { id: number; name: string | null; number: string | null };
    location: string | null;
    scheduled_days: number;
    present_days: number;
    late_count: number;
    late_minutes_total: number;
    early_leave_count: number;
    no_checkout_days: number;
    absent_days: number;
    off_days: number;
    attendance_pct: number | null;
};

type Calendar = {
    employee: { id: number; name: string | null; number: string | null };
    month: string;
    days: CalendarDay[];
};

type Props = ListingProps<SummaryRow> & {
    month: string;
    calendar: Calendar | null;
    can: { export: boolean };
};

const num = (n: number) => n.toLocaleString('id-ID');

const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

function monthLabel(month: string): string {
    const [y, m] = month.split('-').map(Number);

    return `${MONTHS[m - 1] ?? ''} ${y}`;
}

/** % kehadiran + bilah kecil; warna mengikuti ambang sederhana (bukan aturan bisnis). */
function Pct({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">–</span>;
    }

    return (
        <span className="flex min-w-24 items-center gap-2">
            <span
                className="h-1.5 w-14 overflow-hidden rounded-full bg-muted"
                aria-hidden="true"
            >
                <span
                    className={cn(
                        'block h-full rounded-full',
                        value >= 95
                            ? 'bg-status-success'
                            : value >= 85
                              ? 'bg-status-warning'
                              : 'bg-status-danger',
                    )}
                    style={{ width: `${Math.min(100, value)}%` }}
                />
            </span>
            <span className="tabular-nums">
                {value.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%
            </span>
        </span>
    );
}

const columns: DataTableColumn<SummaryRow>[] = [
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
    { key: 'location', header: 'Lokasi', cell: (r) => r.location ?? '–' },
    {
        key: 'scheduled_days',
        header: 'Hari kerja',
        className: 'tabular-nums',
        cell: (r) => num(r.scheduled_days),
    },
    {
        key: 'present_days',
        header: 'Hadir',
        className: 'tabular-nums',
        cell: (r) => num(r.present_days),
    },
    {
        key: 'late_count',
        header: 'Telat',
        sortKey: 'late_count',
        className: 'tabular-nums',
        cell: (r) => num(r.late_count),
    },
    {
        key: 'late_minutes_total',
        header: 'Menit telat',
        className: 'tabular-nums',
        cell: (r) => num(r.late_minutes_total),
    },
    {
        key: 'early_leave_count',
        header: 'Pulang cepat',
        className: 'tabular-nums',
        cell: (r) => num(r.early_leave_count),
    },
    {
        key: 'no_checkout_days',
        header: 'Tidak absen pulang',
        className: 'tabular-nums',
        cell: (r) => num(r.no_checkout_days),
    },
    {
        key: 'absent_days',
        header: 'Tidak hadir',
        className: 'tabular-nums',
        cell: (r) => num(r.absent_days),
    },
    {
        key: 'off_days',
        header: 'Libur',
        className: 'tabular-nums',
        cell: (r) => num(r.off_days),
    },
    {
        key: 'attendance_pct',
        header: '% kehadiran',
        sortKey: 'attendance_pct',
        cell: (r) => <Pct value={r.attendance_pct} />,
    },
];

/** URL halaman ini dengan/tanpa `kalender=` (state filter/sort/halaman lain tetap). */
function withCalendar(url: string, employeeId: number | null): string {
    const [path, query = ''] = url.split('?');
    const params = new URLSearchParams(query);
    if (employeeId === null) {
        params.delete('kalender');
    } else {
        params.set('kalender', String(employeeId));
    }
    const qs = params.toString();

    return qs ? `${path}?${qs}` : path;
}

/** Rekap bulanan presensi (05 §4.10, 07 §3.4 `attendance_summary`, BR-072). */
export default function AttendanceSummary({
    rows,
    listing,
    month,
    calendar,
    can,
}: Props) {
    const { url } = usePage();

    return (
        <>
            <Head title="Rekap Bulanan Presensi" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Rekap Bulanan Presensi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {monthLabel(month)}. % kehadiran = hadir ÷ hari kerja
                        (libur & hari tidak dijadwalkan tidak dihitung). Klik
                        baris untuk melihat kalender pegawai. Rincian harian ada
                        di{' '}
                        <Link
                            href={dailyIndex()}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            Presensi Harian
                        </Link>
                        .
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Rekap per pegawai"
                    rowHref={(r) => withCalendar(url, r.employee.id)}
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

            <Dialog
                open={calendar !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        router.visit(withCalendar(url, null), {
                            preserveScroll: true,
                            preserveState: true,
                        });
                    }
                }}
            >
                <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                    {calendar && (
                        <>
                            <DialogTitle className="flex items-center gap-2">
                                <CalendarDays
                                    className="size-5 text-primary"
                                    aria-hidden="true"
                                />
                                {calendar.employee.name}
                            </DialogTitle>
                            <DialogDescription>
                                <span className="font-mono">
                                    {calendar.employee.number}
                                </span>{' '}
                                · {monthLabel(calendar.month)}. Klik tanggal
                                untuk rincian.
                            </DialogDescription>
                            <MonthCalendar
                                month={calendar.month}
                                days={calendar.days}
                            />
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

AttendanceSummary.layout = {
    breadcrumbs: [{ title: 'Rekap Bulanan', href: index() }],
};
