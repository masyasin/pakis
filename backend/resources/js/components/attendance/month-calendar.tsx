import { Link } from '@inertiajs/react';
import { STATUS_MAP } from '@/components/pakis/status-badge';
import type { StatusTone } from '@/components/pakis/status-badge';
import { cn } from '@/lib/utils';
import { WEEKDAYS, monthGrid } from '@/lib/month-grid';
import { show } from '@/routes/admin/attendance';

export type CalendarDay = {
    id: number;
    date: string;
    status: string;
    status_label: string;
    shift: string | null;
    check_in: string | null;
    check_out: string | null;
    late_minutes: number;
};

/** Warna sel = nada status 07 §6 (sama dengan StatusBadge), versi blok kalender. */
const TONE_CELL: Record<StatusTone, string> = {
    success:
        'bg-status-success-bg text-status-success border-status-success/30',
    warning:
        'bg-status-warning-bg text-status-warning border-status-warning/30',
    danger: 'bg-status-danger-bg text-status-danger border-status-danger/30',
    info: 'bg-status-info-bg text-status-info border-status-info/30',
    muted: 'bg-status-muted-bg text-status-muted border-status-muted/30',
};

const LEGEND = [
    'present',
    'late',
    'early_leave',
    'no_checkout',
    'absent',
    'holiday',
    'off',
];

/**
 * Kalender bulan satu pegawai (07 §4 "Rekap bulanan": klik baris → kalender dengan warna status).
 * Tiap hari bertaut ke detail presensi harian; hari tanpa data (belum ditutup/di luar scope) kosong.
 */
export function MonthCalendar({
    month,
    days,
}: {
    month: string;
    days: CalendarDay[];
}) {
    const byDate = new Map(days.map((d) => [d.date, d]));

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-7 gap-1 text-center text-[11px] font-medium text-muted-foreground sm:gap-1.5 sm:text-xs">
                {WEEKDAYS.map((w) => (
                    <div key={w}>{w}</div>
                ))}
            </div>
            <div className="grid gap-1 sm:gap-1.5">
                {monthGrid(month).map((week, i) => (
                    <div key={i} className="grid grid-cols-7 gap-1 sm:gap-1.5">
                        {week.map((cell, j) => {
                            if (cell === null) {
                                return <div key={j} aria-hidden="true" />;
                            }
                            const day = byDate.get(cell.date);
                            if (!day) {
                                return (
                                    <div
                                        key={j}
                                        className="flex aspect-square flex-col rounded-md border border-dashed p-1 text-[11px] text-muted-foreground sm:text-xs"
                                    >
                                        {cell.day}
                                    </div>
                                );
                            }
                            const tone =
                                STATUS_MAP[day.status]?.tone ?? 'muted';

                            return (
                                <Link
                                    key={j}
                                    href={show(day.id)}
                                    title={`${day.status_label}${day.check_in ? ` · masuk ${day.check_in}` : ''}${day.check_out ? ` · pulang ${day.check_out}` : ''}`}
                                    className={cn(
                                        'flex aspect-square flex-col justify-between rounded-md border p-1 text-left text-[11px] transition hover:ring-2 hover:ring-ring/40 sm:text-xs',
                                        TONE_CELL[tone],
                                    )}
                                >
                                    <span className="font-semibold">
                                        {cell.day}
                                    </span>
                                    <span className="hidden truncate tabular-nums sm:block">
                                        {day.check_in ?? ''}
                                    </span>
                                    <span className="sr-only">
                                        {day.status_label}
                                    </span>
                                </Link>
                            );
                        })}
                    </div>
                ))}
            </div>
            <ul className="flex flex-wrap gap-x-3 gap-y-1.5 text-xs">
                {LEGEND.map((s) => {
                    const def = STATUS_MAP[s];

                    return (
                        <li
                            key={s}
                            className="inline-flex items-center gap-1.5"
                        >
                            <span
                                className={cn(
                                    'size-3 rounded-sm border',
                                    TONE_CELL[def?.tone ?? 'muted'],
                                )}
                                aria-hidden="true"
                            />
                            {def?.label ?? s}
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}
