import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { ActiveFilterChips } from '@/components/pakis/active-filter-chips';
import { EmptyState } from '@/components/pakis/empty-state';
import { FilterBar } from '@/components/pakis/filter-bar';
import { Pagination } from '@/components/pakis/pagination';
import { OverrideDialog } from '@/components/schedule/override-dialog';
import type { OverrideTarget } from '@/components/schedule/override-dialog';
import { ScheduleTabs } from '@/components/schedule/schedule-tabs';
import { Button } from '@/components/ui/button';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { useListing } from '@/hooks/use-listing';
import { useLookupLabels } from '@/hooks/use-lookup-labels';
import { todayIn } from '@/lib/employee-format';
import {
    defaultFilters,
    filterChips,
    formatNumber,
    isEmptyValue,
} from '@/lib/listing-query';
import {
    describeCell,
    formatMonth,
    monthDays,
    shiftMonth,
    shiftTint,
} from '@/lib/schedule';
import type { CalendarDay, EmployeeRef, ScheduleCell } from '@/lib/schedule';
import { cn } from '@/lib/utils';
import { show as showEmployee } from '@/routes/admin/employees';
import { index } from '@/routes/admin/schedules';
import type { ListingProps } from '@/types/listing';

type CalendarRow = {
    id: number;
    employee: EmployeeRef;
    location: string | null;
    position: string | null;
    cells: Record<string, ScheduleCell>;
};

type Props = ListingProps<CalendarRow> & { can: { manage: boolean } };

function CellContent({ cell }: { cell: ScheduleCell }) {
    if (cell.kind === 'shift' && cell.shift) {
        return (
            <span className="font-mono text-[0.68rem] leading-none font-semibold">
                {cell.shift.code}
            </span>
        );
    }
    if (cell.kind === 'holiday' || cell.kind === 'off') {
        return <span className="text-[0.65rem] leading-none">Libur</span>;
    }

    return (
        <span
            className="text-[0.7rem] leading-none text-muted-foreground"
            aria-hidden="true"
        >
            –
        </span>
    );
}

function cellClass(cell: ScheduleCell): string {
    switch (cell.kind) {
        case 'holiday':
            return 'bg-status-danger-bg text-status-danger';
        case 'off':
            return 'bg-status-muted-bg text-status-muted';
        case 'unscheduled':
            return 'bg-transparent';
        default:
            return 'text-foreground';
    }
}

/** Jadwal · Kalender (05 §4.9 `admin.schedules.calendar`, 07 §3: paging 25 pegawai, BR-061/BR-062). */
export default function SchedulesCalendar({ rows, listing, can }: Props) {
    const { timezone } = usePage().props;
    const { spec, state, pagination } = listing;
    const controls = useListing(listing);
    useLookupLabels(spec, state.filters);
    const [target, setTarget] = useState<OverrideTarget | null>(null);

    const rawMonth = state.filters.month;
    const month = typeof rawMonth === 'string' ? rawMonth : '';
    const days = monthDays(month);
    const today = todayIn(timezone);
    const monthFilter = spec.filters.find((f) => f.key === 'month');
    const monthOptions =
        monthFilter?.type === 'select'
            ? (monthFilter.options ?? []).map((o) => String(o.value))
            : [];
    const canGo = (delta: number) =>
        monthOptions.includes(shiftMonth(month, delta));

    // Chip "Bulan" disembunyikan: bulan selalu ada dan diganti lewat navigasi di atas grid.
    const chips = filterChips(spec, state.filters).filter(
        (c) => c.key !== 'month',
    );
    const isFiltered =
        state.q !== '' ||
        Object.entries(state.filters).some(
            ([key, value]) =>
                key !== 'month' &&
                !isEmptyValue(value) &&
                JSON.stringify(value) !==
                    JSON.stringify(defaultFilters(spec)[key]),
        );
    const invalid = Object.entries(state.invalid_filters);

    useBreadcrumbTail([{ title: 'Kalender', href: '#' }]);

    const openCell = (row: CalendarRow, day: CalendarDay, cell: ScheduleCell) =>
        setTarget({
            employee: row.employee,
            date: day.date,
            existing:
                cell.override_id !== null
                    ? {
                          id: cell.override_id,
                          // Pengecualian "libur" di hari libur tampil sebagai holiday: shift tetap kosong.
                          shiftId:
                              cell.source === 'override'
                                  ? (cell.shift?.id ?? null)
                                  : null,
                          note: cell.note,
                      }
                    : null,
            current: describeCell(cell),
        });

    return (
        <>
            <Head title="Jadwal · Kalender" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="space-y-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Jadwal
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Jadwal efektif per tanggal: pengecualian, hari libur
                            lokasi, lalu pola.
                            {can.manage &&
                                ' Klik sel untuk mengganti shift atau meliburkan.'}
                        </p>
                    </div>
                    <ScheduleTabs active="kalender" />
                </div>

                <section
                    className="@container space-y-3 rounded-xl border bg-card p-3 shadow-md @lg:p-4"
                    aria-label="Kalender jadwal"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <h2 className="mr-auto text-base font-bold capitalize">
                            {formatMonth(month)}
                        </h2>
                        <div className="flex items-center gap-1">
                            <Button
                                variant="outline"
                                size="icon"
                                disabled={!canGo(-1) || controls.isLoading}
                                onClick={() =>
                                    controls.setFilter(
                                        'month',
                                        shiftMonth(month, -1),
                                    )
                                }
                                aria-label="Bulan sebelumnya"
                            >
                                <ChevronLeft aria-hidden="true" />
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={
                                    !monthOptions.includes(today.slice(0, 7)) ||
                                    month === today.slice(0, 7)
                                }
                                onClick={() =>
                                    controls.setFilter(
                                        'month',
                                        today.slice(0, 7),
                                    )
                                }
                            >
                                Bulan ini
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                disabled={!canGo(1) || controls.isLoading}
                                onClick={() =>
                                    controls.setFilter(
                                        'month',
                                        shiftMonth(month, 1),
                                    )
                                }
                                aria-label="Bulan berikutnya"
                            >
                                <ChevronRight aria-hidden="true" />
                            </Button>
                        </div>
                    </div>

                    <FilterBar
                        spec={spec}
                        state={state}
                        listing={controls}
                        canReset={isFiltered}
                    />
                    <ActiveFilterChips
                        chips={chips}
                        onRemove={controls.removeFilter}
                    />

                    {invalid.length > 0 && (
                        <p
                            role="status"
                            className="flex items-start gap-2 rounded-md bg-status-warning-bg px-3 py-2 text-xs text-status-warning"
                        >
                            <TriangleAlert
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden
                            />
                            <span>
                                Sebagian filter diabaikan:{' '}
                                {invalid
                                    .map(([key, message]) => {
                                        const label =
                                            spec.filters.find(
                                                (f) => f.key === key,
                                            )?.label ?? key;

                                        return `${label} (${message})`;
                                    })
                                    .join('; ')}
                            </span>
                        </p>
                    )}

                    <Legend />

                    {rows.length === 0 ? (
                        <div className="rounded-lg border">
                            {isFiltered ? (
                                <EmptyState
                                    variant="no-results"
                                    onReset={controls.reset}
                                />
                            ) : (
                                <EmptyState variant="no-data" />
                            )}
                        </div>
                    ) : (
                        <div
                            className={cn(
                                'overflow-x-auto rounded-lg border transition-opacity',
                                controls.isLoading &&
                                    'pointer-events-none opacity-60',
                            )}
                            aria-busy={controls.isLoading}
                        >
                            <table className="w-max min-w-full border-separate border-spacing-0 text-sm">
                                <caption className="sr-only">
                                    Jadwal {formatMonth(month)}, pegawai ×
                                    tanggal
                                </caption>
                                <thead>
                                    <tr>
                                        <th
                                            scope="col"
                                            className="sticky left-0 z-[2] w-28 min-w-28 border-r border-b bg-card px-3 py-2 text-left text-xs font-semibold sm:w-52 sm:min-w-52"
                                        >
                                            Pegawai
                                        </th>
                                        {days.map((d) => (
                                            <th
                                                key={d.date}
                                                scope="col"
                                                className={cn(
                                                    'w-10 min-w-10 border-b px-0 py-1.5 text-center text-[0.68rem] font-medium',
                                                    d.isSunday &&
                                                        'bg-status-muted-bg',
                                                    d.date === today &&
                                                        'text-primary',
                                                )}
                                            >
                                                <span className="block text-muted-foreground">
                                                    {d.weekday}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'mx-auto mt-0.5 flex size-6 items-center justify-center rounded-full text-xs font-semibold tabular-nums',
                                                        d.date === today &&
                                                            'bg-primary text-primary-foreground',
                                                    )}
                                                >
                                                    {d.day}
                                                </span>
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((row) => (
                                        <tr key={row.id} className="group">
                                            <th
                                                scope="row"
                                                className="sticky left-0 z-[1] max-w-28 border-r border-b bg-card px-3 py-1.5 text-left font-normal group-hover:bg-muted sm:max-w-52"
                                            >
                                                <Link
                                                    href={showEmployee(
                                                        { employee: row.id },
                                                        {
                                                            query: {
                                                                tab: 'jadwal',
                                                            },
                                                        },
                                                    )}
                                                    className="block truncate text-sm font-medium hover:underline"
                                                >
                                                    {row.employee.name}
                                                </Link>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {row.location ??
                                                        row.employee.number}
                                                </span>
                                            </th>
                                            {days.map((d) => {
                                                const cell = row.cells[d.date];
                                                if (!cell) {
                                                    return (
                                                        <td
                                                            key={d.date}
                                                            className="border-b"
                                                        />
                                                    );
                                                }
                                                const label = `${row.employee.name}, ${d.weekday} ${d.day}: ${describeCell(cell)}`;
                                                const style =
                                                    cell.kind === 'shift' &&
                                                    cell.shift
                                                        ? {
                                                              backgroundColor:
                                                                  shiftTint(
                                                                      cell.shift
                                                                          .color,
                                                                  ),
                                                              boxShadow: `inset 3px 0 0 ${cell.shift.color}`,
                                                          }
                                                        : undefined;
                                                const content = (
                                                    <>
                                                        <CellContent
                                                            cell={cell}
                                                        />
                                                        {cell.override && (
                                                            <span
                                                                aria-hidden="true"
                                                                className="absolute top-0.5 right-0.5 size-1.5 rounded-full bg-status-warning"
                                                            />
                                                        )}
                                                    </>
                                                );

                                                return (
                                                    <td
                                                        key={d.date}
                                                        className="border-b p-0.5"
                                                    >
                                                        {can.manage ? (
                                                            <button
                                                                type="button"
                                                                title={label}
                                                                aria-label={
                                                                    label
                                                                }
                                                                onClick={() =>
                                                                    openCell(
                                                                        row,
                                                                        d,
                                                                        cell,
                                                                    )
                                                                }
                                                                className={cn(
                                                                    'relative flex h-9 w-9 items-center justify-center rounded-md transition-[filter] hover:brightness-95 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:hover:brightness-125',
                                                                    cellClass(
                                                                        cell,
                                                                    ),
                                                                )}
                                                                style={style}
                                                            >
                                                                {content}
                                                            </button>
                                                        ) : (
                                                            <span
                                                                title={label}
                                                                aria-label={
                                                                    label
                                                                }
                                                                role="img"
                                                                className={cn(
                                                                    'relative flex h-9 w-9 items-center justify-center rounded-md',
                                                                    cellClass(
                                                                        cell,
                                                                    ),
                                                                )}
                                                                style={style}
                                                            >
                                                                {content}
                                                            </span>
                                                        )}
                                                    </td>
                                                );
                                            })}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex flex-wrap items-center gap-3 pt-1 text-sm">
                        <p
                            className="mr-auto text-muted-foreground tabular-nums"
                            aria-live="polite"
                        >
                            {pagination.total === 0
                                ? 'Tidak ada pegawai'
                                : `Pegawai ${formatNumber(pagination.from ?? 0)}–${formatNumber(pagination.to ?? 0)} dari ${formatNumber(pagination.total)}`}
                        </p>
                        <Pagination
                            page={pagination.page}
                            lastPage={pagination.last_page}
                            onChange={controls.setPage}
                            disabled={controls.isLoading}
                        />
                    </div>
                </section>
            </div>

            <OverrideDialog
                target={target}
                onClose={() => setTarget(null)}
                defaultDate={today}
            />
        </>
    );
}

function Legend() {
    const item = (swatch: string, label: string) => (
        <li className="inline-flex items-center gap-1.5">
            <span
                aria-hidden="true"
                className={cn('size-3 rounded-sm border', swatch)}
            />
            {label}
        </li>
    );

    return (
        <ul
            className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
            aria-label="Keterangan"
        >
            {item('bg-primary/20', 'Shift (warna shift)')}
            {item('bg-status-danger-bg', 'Hari libur')}
            {item('bg-status-muted-bg', 'Libur pegawai')}
            {item('bg-transparent', 'Belum dijadwalkan (–)')}
            <li className="inline-flex items-center gap-1.5">
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-status-warning"
                />
                Pengecualian
            </li>
        </ul>
    );
}

SchedulesCalendar.layout = {
    breadcrumbs: [{ title: 'Jadwal', href: index() }],
};
