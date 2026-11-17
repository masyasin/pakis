import { Head, Link } from '@inertiajs/react';
import {
    CalendarCheck,
    CalendarDays,
    ClipboardPen,
    CloudOff,
    ImageOff,
    LogIn,
    LogOut,
    MapPin,
    MapPinned,
    PencilLine,
    TriangleAlert,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ActionLabel } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { LocationDiagram } from '@/components/pakis/location-diagram';
import { ManualAttendanceDialog } from '@/components/pakis/manual-attendance-dialog';
import type { ManualAttendanceOptions } from '@/components/pakis/manual-attendance-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { formatDate } from '@/lib/employee-format';
import type { DiagramArea, LatLng } from '@/lib/location-diagram';
import { index, show } from '@/routes/admin/attendance';
import { show as showCorrection } from '@/routes/admin/attendance-corrections';
import { show as showEmployee } from '@/routes/admin/employees';

type LogCard = {
    id: number;
    type: 'in' | 'out';
    recorded_at: string | null;
    server_received_at: string | null;
    client_time: string | null;
    is_offline: boolean;
    accuracy_m: number | null;
    distance_m: number | null;
    within_radius: boolean;
    location: string | null;
    notes: { text: string; tone: 'info' | 'warning' }[];
    app_version: string | null;
    has_selfie: boolean;
    selfie_url: string | null;
    map: { point: LatLng; area: DiagramArea | null } | null;
};

type Day = {
    id: number;
    work_date: string;
    employee: { id: number; name: string | null; number: string | null };
    location: string | null;
    position: string | null;
    department: string | null;
    shift: {
        code: string;
        name: string;
        start: string | null;
        end: string | null;
        late_tolerance_min: number;
        checkout_close: string | null;
    } | null;
    status: string;
    status_label: string;
    late_minutes: number;
    early_leave_minutes: number;
    work_minutes: number;
    is_offline: boolean;
    is_corrected: boolean;
    has_anomaly: boolean;
    resolved_at: string | null;
};

type CorrectionItem = {
    id: number;
    number: string;
    type_label: string;
    proposed_check_in: string | null;
    proposed_check_out: string | null;
    reason: string;
    status: string;
    status_label: string;
    submitted_at: string | null;
};

type Props = {
    day: Day;
    logs: { in: LogCard | null; out: LogCard | null };
    corrections: CorrectionItem[];
    manual: ManualAttendanceOptions | null;
    can: {
        view_photo: boolean;
        view_employee: boolean;
        view_corrections: boolean;
        manual: boolean;
    };
};

const minutes = (m: number) =>
    m >= 60 ? `${Math.floor(m / 60)} j ${m % 60} mnt` : `${m} mnt`;

/** `YYYY-MM-DD HH:MM:SS` lokal → `HH:MM` (+ tanggal bila beda hari kerja). */
function clock(value: string | null, workDate: string): string {
    if (value === null) {
        return '–';
    }
    const [date, time] = value.split(' ');

    return date === workDate
        ? time.slice(0, 5)
        : `${formatDate(date)} ${time.slice(0, 5)}`;
}

function Card({
    title,
    children,
    icon,
}: {
    title: string;
    children: ReactNode;
    icon?: ReactNode;
}) {
    return (
        <section className="rounded-xl border bg-card p-4 shadow-[0_1px_2px_rgb(0_0_0/0.04)] md:p-5">
            <h2 className="mb-3 flex items-center gap-2 text-base font-semibold">
                {icon}
                {title}
            </h2>
            {children}
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4 border-b py-1.5 text-sm last:border-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{children}</dd>
        </div>
    );
}

function LogPanel({
    title,
    log,
    workDate,
    canPhoto,
    icon,
}: {
    title: string;
    log: LogCard | null;
    workDate: string;
    canPhoto: boolean;
    icon: ReactNode;
}) {
    if (log === null) {
        return (
            <Card title={title} icon={icon}>
                <p className="text-sm text-muted-foreground">
                    Belum ada presensi.
                </p>
            </Card>
        );
    }

    return (
        <Card title={title} icon={icon}>
            <div className="grid gap-4 lg:grid-cols-[1fr_auto]">
                <dl>
                    <Row label="Waktu">{clock(log.recorded_at, workDate)}</Row>
                    <Row label="Sumber">
                        {log.is_offline ? (
                            <span className="inline-flex items-center gap-1">
                                <CloudOff
                                    className="size-3.5"
                                    aria-hidden="true"
                                />
                                Offline (dikirim{' '}
                                {clock(log.server_received_at, workDate)})
                            </span>
                        ) : (
                            'Online'
                        )}
                    </Row>
                    <Row label="Lokasi cocok">{log.location ?? '–'}</Row>
                    <Row label="Jarak">
                        {log.distance_m === null
                            ? '–'
                            : `${log.distance_m.toLocaleString('id-ID')} m`}
                    </Row>
                    <Row label="Radius">
                        {log.within_radius ? (
                            'Di dalam radius'
                        ) : (
                            <span className="text-status-danger">
                                Di luar radius
                            </span>
                        )}
                    </Row>
                    <Row label="Akurasi GPS">
                        {log.accuracy_m === null ? '–' : `±${log.accuracy_m} m`}
                    </Row>
                    {log.app_version && (
                        <Row label="Versi aplikasi">{log.app_version}</Row>
                    )}
                    {log.notes.length > 0 && (
                        <Row label="Keterangan">
                            <ul className="space-y-0.5">
                                {log.notes.map((n) => (
                                    <li
                                        key={n.text}
                                        className={
                                            n.tone === 'warning'
                                                ? 'text-status-warning'
                                                : 'text-status-info'
                                        }
                                    >
                                        {n.text}
                                    </li>
                                ))}
                            </ul>
                        </Row>
                    )}
                </dl>
                {canPhoto && (
                    <div className="grid content-start gap-4 sm:grid-cols-2 lg:w-[30rem]">
                        <figure className="space-y-2">
                            {log.selfie_url ? (
                                <img
                                    src={log.selfie_url}
                                    alt={`Selfie ${title.toLowerCase()}`}
                                    className="mx-auto aspect-square w-full max-w-60 rounded-lg border object-cover"
                                    loading="lazy"
                                    referrerPolicy="no-referrer"
                                />
                            ) : (
                                <div className="mx-auto grid aspect-square w-full max-w-60 place-items-center rounded-lg border bg-muted/30 text-center text-xs text-muted-foreground">
                                    <span className="grid justify-items-center gap-1 p-3">
                                        <ImageOff
                                            className="size-5"
                                            aria-hidden="true"
                                        />
                                        {log.has_selfie
                                            ? 'Foto tidak tersedia'
                                            : 'Tanpa foto (sudah dihapus atau tidak diwajibkan)'}
                                    </span>
                                </div>
                            )}
                            <figcaption className="text-center text-xs text-muted-foreground">
                                Tautan foto berlaku 5 menit.
                            </figcaption>
                        </figure>
                        {log.map && (
                            <LocationDiagram
                                point={log.map.point}
                                area={log.map.area}
                                distanceM={log.distance_m}
                                withinRadius={log.within_radius}
                            />
                        )}
                    </div>
                )}
            </div>
        </Card>
    );
}

/** Detail absensi satu pegawai pada satu tanggal (05 §4.10, 07 §4 "Absensi harian (detail)"). */
export default function AttendanceShow({
    day,
    logs,
    corrections,
    manual,
    can,
}: Props) {
    const name = day.employee.name ?? 'Pegawai';
    const [manualOpen, setManualOpen] = useState(false);
    useBreadcrumbTail([
        { title: name, href: show(day.id).url },
        { title: formatDate(day.work_date), href: '#' },
    ]);

    const marks: [boolean, LucideIcon, string][] = [
        [day.is_offline, CloudOff, 'Ada presensi offline'],
        [day.is_corrected, PencilLine, 'Dikoreksi'],
        [day.has_anomaly, TriangleAlert, 'Ada anomali'],
    ];

    return (
        <>
            <Head title={`Presensi ${name} ${formatDate(day.work_date)}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref={index()}
                    backLabel="Presensi Harian"
                    kind="Presensi harian"
                    title={name}
                    number={day.employee.number}
                    icon={CalendarCheck}
                    status={day.status}
                    statusLabel={day.status_label}
                    meta={[
                        {
                            icon: CalendarDays,
                            label: 'Tanggal',
                            value: formatDate(day.work_date),
                        },
                        {
                            icon: MapPin,
                            label: 'Lokasi',
                            value: day.location,
                        },
                    ]}
                    notice={
                        marks.some(([on]) => on) && (
                            <p className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-status-warning">
                                {marks
                                    .filter(([on]) => on)
                                    .map(([, Icon, label]) => (
                                        <span
                                            key={label}
                                            className="inline-flex items-center gap-1"
                                        >
                                            <Icon
                                                className="size-3.5"
                                                aria-hidden="true"
                                            />
                                            {label}
                                        </span>
                                    ))}
                            </p>
                        )
                    }
                    actions={
                        (can.manual && manual) || can.view_employee ? (
                            <>
                                {can.manual && manual && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setManualOpen(true)}
                                        aria-label="Isi presensi manual"
                                    >
                                        <ClipboardPen aria-hidden="true" />
                                        <ActionLabel>
                                            Isi presensi manual
                                        </ActionLabel>
                                    </Button>
                                )}
                                {can.view_employee && (
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={showEmployee(
                                                day.employee.id,
                                                {
                                                    query: { tab: 'presensi' },
                                                },
                                            )}
                                            aria-label="Lihat pegawai"
                                        >
                                            <UserRound aria-hidden="true" />
                                            <ActionLabel>
                                                Lihat pegawai
                                            </ActionLabel>
                                        </Link>
                                    </Button>
                                )}
                            </>
                        ) : undefined
                    }
                />

                <div className="grid gap-4 md:grid-cols-2">
                    <Card title="Jadwal">
                        <dl>
                            <Row label="Shift">
                                {day.shift
                                    ? `${day.shift.name} (${day.shift.code})`
                                    : 'Tidak dijadwalkan'}
                            </Row>
                            {day.shift && (
                                <>
                                    <Row label="Jam kerja">
                                        {day.shift.start} – {day.shift.end}
                                    </Row>
                                    <Row label="Toleransi telat">
                                        {day.shift.late_tolerance_min} mnt
                                    </Row>
                                    <Row label="Batas pulang">
                                        {day.shift.checkout_close}
                                    </Row>
                                </>
                            )}
                            <Row label="Lokasi">{day.location ?? '–'}</Row>
                            <Row label="Jabatan">{day.position ?? '–'}</Row>
                            <Row label="Departemen">
                                {day.department ?? '–'}
                            </Row>
                        </dl>
                    </Card>
                    <Card title="Hasil">
                        <dl>
                            <Row label="Status">
                                <StatusBadge
                                    status={day.status}
                                    label={day.status_label}
                                />
                            </Row>
                            <Row label="Telat">
                                {day.late_minutes > 0
                                    ? minutes(day.late_minutes)
                                    : '–'}
                            </Row>
                            <Row label="Pulang cepat">
                                {day.early_leave_minutes > 0
                                    ? minutes(day.early_leave_minutes)
                                    : '–'}
                            </Row>
                            <Row label="Lama kerja">
                                {day.work_minutes > 0
                                    ? minutes(day.work_minutes)
                                    : '–'}
                            </Row>
                            <Row label="Dihitung">
                                {day.resolved_at
                                    ? clock(day.resolved_at, day.work_date)
                                    : '–'}
                            </Row>
                        </dl>
                    </Card>
                </div>

                <LogPanel
                    title="Masuk"
                    log={logs.in}
                    workDate={day.work_date}
                    canPhoto={can.view_photo}
                    icon={
                        <LogIn
                            className="size-4 text-primary"
                            aria-hidden="true"
                        />
                    }
                />
                <LogPanel
                    title="Pulang"
                    log={logs.out}
                    workDate={day.work_date}
                    canPhoto={can.view_photo}
                    icon={
                        <LogOut
                            className="size-4 text-primary"
                            aria-hidden="true"
                        />
                    }
                />
                {!can.view_photo && (logs.in || logs.out) && (
                    <p className="flex items-center gap-2 text-xs text-muted-foreground">
                        <MapPinned className="size-3.5" aria-hidden="true" />
                        Foto & titik koordinat hanya terlihat oleh pemegang izin
                        lihat foto presensi.
                    </p>
                )}

                <Card
                    title="Riwayat koreksi"
                    icon={
                        <PencilLine
                            className="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                    }
                >
                    {corrections.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada koreksi.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {corrections.map((c) => (
                                <li
                                    key={c.id}
                                    className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                                >
                                    <span className="grid">
                                        {can.view_corrections ? (
                                            <Link
                                                href={showCorrection(c.id)}
                                                className="font-mono text-xs underline-offset-4 hover:underline"
                                            >
                                                {c.number}
                                            </Link>
                                        ) : (
                                            <span className="font-mono text-xs">
                                                {c.number}
                                            </span>
                                        )}
                                        <span>
                                            {c.type_label}:{' '}
                                            <span className="tabular-nums">
                                                {c.proposed_check_in ?? '–'} →{' '}
                                                {c.proposed_check_out ?? '–'}
                                            </span>{' '}
                                            · {c.reason}
                                        </span>
                                    </span>
                                    <StatusBadge
                                        status={`correction.${c.status}`}
                                        label={c.status_label}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </Card>
            </div>
            {can.manual && manual && (
                <ManualAttendanceDialog
                    open={manualOpen}
                    onOpenChange={setManualOpen}
                    options={manual}
                    preset={{
                        employee: {
                            id: day.employee.id,
                            label: `${name} (${day.employee.number ?? ''})`,
                        },
                        work_date: day.work_date,
                    }}
                />
            )}
        </>
    );
}

AttendanceShow.layout = {
    breadcrumbs: [{ title: 'Presensi Harian', href: index() }],
};
