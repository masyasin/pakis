import { Link } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarDays,
    CalendarX2,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import {
    DetailCard,
    EmptyPanel,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import { StatusBadge } from '@/components/pakis/status-badge';
import { OverrideDialog } from '@/components/schedule/override-dialog';
import type { OverrideTarget } from '@/components/schedule/override-dialog';
import { DayOffLabel, ShiftChip } from '@/components/schedule/shift-chip';
import { Button } from '@/components/ui/button';
import { formatCivilDate } from '@/lib/local-date';
import { DAYS, summarizePattern } from '@/lib/schedule';
import type { EmployeeRef, PatternDays, ShiftSummary } from '@/lib/schedule';
import { index as schedules } from '@/routes/admin/schedules';

export type EmployeeScheduleData = {
    patterns: {
        id: number;
        effective_from: string;
        status: 'current' | 'scheduled' | 'past';
        days: PatternDays;
    }[];
    overrides: {
        id: number;
        date: string;
        shift: ShiftSummary | null;
        note: string | null;
    }[];
    upcomingDays: number;
    canManage: boolean;
};

function WeekRow({ days }: { days: PatternDays }) {
    return (
        // Tujuh kolom sempit di HP: warna shift jadi garis di atas kode (bukan titik di samping)
        // agar kode tidak meluap dari kotak harinya.
        <ul
            className="grid grid-cols-7 gap-1 sm:gap-1.5"
            aria-label="Pola mingguan"
        >
            {DAYS.map((d) => {
                const shift = days[d.key];

                return (
                    <li
                        key={d.key}
                        title={
                            shift
                                ? `${d.long}: ${shift.name}`
                                : `${d.long}: Libur`
                        }
                        className="grid min-w-0 justify-items-center gap-1 rounded-md border bg-card px-0.5 py-2 text-center"
                    >
                        <span className="text-[0.7rem] text-muted-foreground">
                            {d.short}
                        </span>
                        <span
                            aria-hidden="true"
                            className="h-1 w-5 rounded-full bg-muted"
                            style={
                                shift
                                    ? { backgroundColor: shift.color }
                                    : undefined
                            }
                        />
                        {shift ? (
                            <span className="max-w-full truncate font-mono text-[0.68rem] font-semibold sm:text-[0.8rem]">
                                {shift.code}
                            </span>
                        ) : (
                            <DayOffLabel className="text-[0.68rem] sm:text-[0.8rem]" />
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

const STATUS = {
    current: { label: 'Berlaku', tone: 'success' },
    scheduled: { label: 'Terjadwal', tone: 'info' },
    past: { label: 'Riwayat', tone: 'muted' },
} as const;

/** Tab Jadwal di detail pegawai (07 §4, T-035, BR-061). */
export function EmployeeSchedulePanel({
    employee,
    schedule,
    today,
}: {
    employee: EmployeeRef;
    schedule: EmployeeScheduleData;
    today: string;
}) {
    const [target, setTarget] = useState<OverrideTarget | null>(null);
    const { patterns, overrides, canManage, upcomingDays } = schedule;
    const current = patterns.find((p) => p.status === 'current') ?? null;

    // Pola ditetapkan dari halaman Jadwal (massal); di sini langsung tersaring ke pegawai ini.
    const setPattern = canManage ? (
        <Button variant="outline" size="sm" asChild>
            <Link href={schedules({ query: { q: employee.number } })}>
                <Pencil aria-hidden="true" />
                Ubah pola
            </Link>
        </Button>
    ) : undefined;

    const addOverride = canManage ? (
        <Button
            variant="outline"
            size="sm"
            onClick={() => setTarget({ employee, date: null, existing: null })}
        >
            <Plus aria-hidden="true" />
            Tambah pengecualian
        </Button>
    ) : undefined;

    return (
        <div className="grid gap-4 lg:grid-cols-2">
            {patterns.length === 0 ? (
                <EmptyPanel
                    icon={CalendarDays}
                    title="Belum ada pola jadwal"
                    text="Tanpa pola, pegawai dianggap tidak dijadwalkan dan tidak bisa presensi (kecuali pengaturan mengizinkan)."
                    action={setPattern}
                />
            ) : (
                <DetailCard
                    title="Pola mingguan"
                    icon={CalendarDays}
                    action={setPattern}
                    className="lg:col-span-2"
                >
                    {current ? (
                        <div className="mb-4 grid gap-2">
                            <p className="text-sm text-muted-foreground">
                                Berlaku sejak{' '}
                                {formatCivilDate(current.effective_from)}
                            </p>
                            <WeekRow days={current.days} />
                        </div>
                    ) : (
                        <p className="mb-4 text-sm text-muted-foreground">
                            Belum ada pola yang berlaku hari ini.
                        </p>
                    )}

                    <Timeline>
                        {patterns.map((p, i) => (
                            <TimelineItem
                                key={p.id}
                                active={p.status === 'current'}
                                last={i === patterns.length - 1}
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-semibold">
                                        Mulai{' '}
                                        {formatCivilDate(p.effective_from)}
                                    </span>
                                    <StatusBadge
                                        status={`schedule.${p.status}`}
                                        label={STATUS[p.status].label}
                                        tone={STATUS[p.status].tone}
                                    />
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {summarizePattern(p.days)}
                                </p>
                            </TimelineItem>
                        ))}
                    </Timeline>
                </DetailCard>
            )}

            <DetailCard
                title={`Pengecualian ${upcomingDays} hari ke depan`}
                icon={CalendarX2}
                action={addOverride}
                className="lg:col-span-2"
            >
                {overrides.length === 0 ? (
                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CalendarClock className="size-4" aria-hidden="true" />
                        Tidak ada pengecualian. Jadwal mengikuti pola dan hari
                        libur.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {overrides.map((o) => (
                            <li
                                key={o.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 py-2"
                            >
                                <span className="min-w-32 text-sm tabular-nums">
                                    {formatCivilDate(o.date)}
                                </span>
                                {o.shift ? (
                                    <ShiftChip shift={o.shift} />
                                ) : (
                                    <DayOffLabel className="text-sm" />
                                )}
                                <span className="mr-auto text-sm text-muted-foreground">
                                    {o.note ?? ''}
                                </span>
                                {canManage && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Ubah pengecualian ${formatCivilDate(o.date)}`}
                                        onClick={() =>
                                            setTarget({
                                                employee,
                                                date: o.date,
                                                existing: {
                                                    id: o.id,
                                                    shiftId:
                                                        o.shift?.id ?? null,
                                                    note: o.note,
                                                },
                                            })
                                        }
                                    >
                                        <Pencil aria-hidden="true" />
                                        Ubah
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
            </DetailCard>

            <OverrideDialog
                target={target}
                onClose={() => setTarget(null)}
                defaultDate={today}
            />
        </div>
    );
}
