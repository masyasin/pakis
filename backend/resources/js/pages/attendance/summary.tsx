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
    today: string;
    days: CalendarDay[];
};

/** Inisial untuk avatar kecil di kepala dialog ("Eko Wibowo" → "EW"). */
const initials = (name: string | null) =>
    (name ?? '?')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase())
        .join('');

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
                <DialogContent className="max-h-[92vh] gap-4 overflow-y-auto p-4 sm:max-w-2xl sm:p-6">
                    {calendar && (
                        <>
                            <div className="flex items-center gap-3 pr-8">
                                <span
                                    className="grid size-11 shrink-0 place-items-center rounded-full bg-primary/10 text-sm font-bold text-primary"
                                    aria-hidden="true"
                                >
                                    {initials(calendar.employee.name)}
                                </span>
                                <div className="min-w-0">
                                    <DialogTitle className="truncate text-lg">
                                        {calendar.employee.name}
                                    </DialogTitle>
                                    <DialogDescription className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                        <span className="font-mono text-xs">
                                            {calendar.employee.number}
                                        </span>
                                        <span className="inline-flex items-center gap-1">
                                            <CalendarDays
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {monthLabel(calendar.month)}
                                        </span>
                                    </DialogDescription>
                                </div>
                            </div>
                            <MonthCalendar
                                month={calendar.month}
                                today={calendar.today}
                                days={calendar.days}
                            />
                            <p className="text-xs text-muted-foreground">
                                Klik tanggal berwarna untuk rincian presensi.
                                Tanggal tanpa warna belum ada data (belum
                                ditutup atau tidak dijadwalkan).
                            </p>
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
