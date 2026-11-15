import { Link } from '@inertiajs/react';
import {
    CalendarOff,
    CircleCheck,
    CircleX,
    Clock,
    DoorOpen,
    LogOut,
    PartyPopper,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { STATUS_MAP } from '@/components/pakis/status-badge';
import type { StatusTone } from '@/components/pakis/status-badge';
import { WEEKDAYS, monthGrid } from '@/lib/month-grid';
import { cn } from '@/lib/utils';
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

/** Nada status 07 §6 (sama dengan StatusBadge) untuk blok kalender. */
const TONE: Record<StatusTone, { cell: string; dot: string; text: string }> = {
    success: {
        cell: 'bg-status-success-bg',
        dot: 'bg-status-success',
        text: 'text-status-success',
    },
    warning: {
        cell: 'bg-status-warning-bg',
        dot: 'bg-status-warning',
        text: 'text-status-warning',
    },
    danger: {
        cell: 'bg-status-danger-bg',
        dot: 'bg-status-danger',
        text: 'text-status-danger',
    },
    info: {
        cell: 'bg-status-info-bg',
        dot: 'bg-status-info',
        text: 'text-status-info',
    },
    muted: {
        cell: 'bg-status-muted-bg',
        dot: 'bg-status-muted',
        text: 'text-status-muted',
    },
};

/** Ikon per status: membedakan status yang warnanya sama (telat / pulang cepat / tidak absen pulang). */
const ICON: Record<string, LucideIcon> = {
    present: CircleCheck,
    late: Clock,
    early_leave: LogOut,
    no_checkout: DoorOpen,
    absent: CircleX,
    holiday: PartyPopper,
    off: CalendarOff,
};

const ORDER = [
    'present',
    'late',
    'early_leave',
    'no_checkout',
    'absent',
    'holiday',
    'off',
];

const WORKED = ['present', 'late', 'early_leave', 'no_checkout'];

function toneOf(status: string) {
    return TONE[STATUS_MAP[status]?.tone ?? 'muted'];
}

/** Teks singkat di sel (layar lebar): jam masuk untuk hari hadir, selain itu nama status. */
function cellText(day: CalendarDay): string {
    if (WORKED.includes(day.status) && day.check_in) {
        return day.late_minutes > 0
            ? `${day.check_in} · +${day.late_minutes}m`
            : day.check_in;
    }

    return day.status_label;
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: string;
    tone?: string;
}) {
    return (
        <div className="rounded-lg border bg-card px-3 py-2">
            <div className="text-[11px] text-muted-foreground">{label}</div>
            <div
                className={cn(
                    'text-lg leading-tight font-semibold tabular-nums',
                    tone,
                )}
            >
                {value}
            </div>
        </div>
    );
}

/**
 * Kalender bulan satu pegawai (07 §4 "Rekap bulanan": klik baris → kalender dengan warna status).
 * Ringkasan di atas memakai rumus BR-072 (hadir ÷ hari kerja). Tiap hari bertaut ke detail
 * presensi harian; hari tanpa data (belum ditutup / di luar scope) hanya menampilkan tanggal.
 */
export function MonthCalendar({
    month,
    today,
    days,
}: {
    month: string;
    today: string;
    days: CalendarDay[];
}) {
    const byDate = new Map(days.map((d) => [d.date, d]));
    const count = (statuses: string[]) =>
        days.filter((d) => statuses.includes(d.status)).length;
    const worked = count(WORKED);
    const absent = count(['absent']);
    const late = days.filter((d) => d.late_minutes > 0).length;
    const pct = worked + absent > 0 ? (100 * worked) / (worked + absent) : null;
    const legend = ORDER.map((s) => ({ status: s, n: count([s]) })).filter(
        (l) => l.n > 0,
    );

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Stat
                    label="Hadir"
                    value={String(worked)}
                    tone="text-status-success"
                />
                <Stat
                    label="Telat"
                    value={String(late)}
                    tone={late > 0 ? 'text-status-warning' : undefined}
                />
                <Stat
                    label="Tidak hadir"
                    value={String(absent)}
                    tone={absent > 0 ? 'text-status-danger' : undefined}
                />
                <Stat
                    label="% kehadiran"
                    value={
                        pct === null
                            ? '–'
                            : `${pct.toLocaleString('id-ID', { maximumFractionDigits: 1 })}%`
                    }
                />
            </div>

            <div className="rounded-xl border bg-card p-2 sm:p-3">
                <div className="mb-1.5 grid grid-cols-7 text-center text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                    {WEEKDAYS.map((w, i) => (
                        <div
                            key={w}
                            className={cn(
                                'py-1',
                                i >= 5 && 'text-status-danger/80',
                            )}
                        >
                            {w}
                        </div>
                    ))}
                </div>
                <div className="grid gap-1">
                    {monthGrid(month).map((week, i) => (
                        <div key={i} className="grid grid-cols-7 gap-1">
                            {week.map((cell, j) => {
                                if (cell === null) {
                                    return <div key={j} aria-hidden="true" />;
                                }
                                const day = byDate.get(cell.date);
                                const isToday = cell.date === today;
                                const weekend = j >= 5;

                                if (!day) {
                                    return (
                                        <div
                                            key={j}
                                            className={cn(
                                                'flex h-12 items-start rounded-lg p-1.5 text-xs sm:h-16 sm:text-sm',
                                                cell.date > today
                                                    ? 'text-muted-foreground/40'
                                                    : 'text-muted-foreground/70',
                                                weekend && 'bg-muted/40',
                                                isToday &&
                                                    'ring-2 ring-primary/50 ring-inset',
                                            )}
                                        >
                                            {cell.day}
                                        </div>
                                    );
                                }

                                const tone = toneOf(day.status);
                                const Icon = ICON[day.status] ?? CircleCheck;
                                const title = [
                                    day.status_label,
                                    day.shift,
                                    day.check_in && `masuk ${day.check_in}`,
                                    day.check_out && `pulang ${day.check_out}`,
                                    day.late_minutes > 0 &&
                                        `telat ${day.late_minutes} mnt`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ');

                                return (
                                    <Link
                                        key={j}
                                        href={show(day.id)}
                                        title={title}
                                        aria-label={`${cell.day}: ${title}`}
                                        className={cn(
                                            'group flex h-12 flex-col justify-between rounded-lg p-1.5 transition hover:-translate-y-px hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none sm:h-16',
                                            tone.cell,
                                            isToday &&
                                                'ring-2 ring-primary/50 ring-inset',
                                        )}
                                    >
                                        <span className="flex items-center justify-between">
                                            <span
                                                className={cn(
                                                    'text-xs font-semibold sm:text-sm',
                                                    tone.text,
                                                )}
                                            >
                                                {cell.day}
                                            </span>
                                            <Icon
                                                className={cn(
                                                    'size-3.5 sm:size-4',
                                                    tone.text,
                                                )}
                                                aria-hidden="true"
                                            />
                                        </span>
                                        <span
                                            className={cn(
                                                'hidden truncate text-[11px] font-medium tabular-nums sm:block',
                                                tone.text,
                                            )}
                                        >
                                            {cellText(day)}
                                        </span>
                                    </Link>
                                );
                            })}
                        </div>
                    ))}
                </div>
            </div>

            {legend.length > 0 && (
                <ul className="flex flex-wrap gap-1.5 text-xs">
                    {legend.map(({ status, n }) => {
                        const tone = toneOf(status);
                        const Icon = ICON[status] ?? CircleCheck;

                        return (
                            <li
                                key={status}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-medium',
                                    tone.cell,
                                    tone.text,
                                )}
                            >
                                <Icon className="size-3.5" aria-hidden="true" />
                                {STATUS_MAP[status]?.label ?? status}
                                <span className="tabular-nums opacity-80">
                                    {n}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}
