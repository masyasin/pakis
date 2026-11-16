import { Head, Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { CalendarX2, ClipboardPen, Smartphone, UserX } from 'lucide-react';
import { formatLongDate } from '@/lib/local-date';
import { dashboard } from '@/routes/admin';
import { index as attendanceIndex } from '@/routes/admin/attendance';

/*
 * Dasbor (07 §4, FR-040, T-044) dengan tata letak prototipe (konsep/PAKIS_ONE_Prototipe_v1.0.html,
 * Dashboard Owner): baris kartu angka, lalu kartu grafik + kartu daftar "perlu tindakan". Semua
 * angka dari server dalam scope pengguna; kartu "perlu tindakan" menaut ke daftar dengan filter
 * yang sama (URL state), jadi angkanya = jumlah baris di daftar itu.
 */
type Summary = {
    date: string;
    present: number;
    late: number;
    not_yet: number;
    off: number;
    week: { date: string; present: number; late: number }[];
};

type PendingItem = {
    key: 'corrections' | 'devices' | 'unscheduled' | 'no_account';
    count: number;
    href: string;
};

type Props = {
    attendance: Summary | null;
    pending: PendingItem[];
};

const PENDING: Record<
    PendingItem['key'],
    { title: string; note: string; icon: LucideIcon }
> = {
    corrections: {
        title: 'Koreksi presensi menunggu',
        note: 'Pengajuan pegawai yang perlu diputuskan',
        icon: ClipboardPen,
    },
    devices: {
        title: 'Perangkat menunggu',
        note: 'HP baru yang perlu disetujui untuk Pakis Pro',
        icon: Smartphone,
    },
    unscheduled: {
        title: 'Pegawai tanpa jadwal',
        note: 'Belum punya pola shift',
        icon: CalendarX2,
    },
    no_account: {
        title: 'Pegawai tanpa akun',
        note: 'Belum bisa masuk ke Pakis Pro',
        icon: UserX,
    },
};

const WEEKDAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

/** Nama hari dari tanggal kalender `YYYY-MM-DD` (tanpa zona: tanggal sudah lokal). */
function weekday(date: string): string {
    const [y, m, d] = date.split('-').map(Number);

    return WEEKDAY[new Date(Date.UTC(y, m - 1, d)).getUTCDay()];
}

function Empty() {
    return (
        <>
            <span aria-hidden="true">—</span>
            <span className="sr-only">Belum ada data</span>
        </>
    );
}

/** Batang hadir + garis telat (SVG, skala 0–100). Teks label di HTML agar tidak mengecil di HP. */
function WeekChart({ week }: { week: Summary['week'] }) {
    const max = Math.max(1, ...week.map((d) => d.present));
    const step = 100 / week.length;
    const y = (v: number) => 98 - (v / max) * 90;
    const line = week
        .map((d, i) => `${(i + 0.5) * step},${y(d.late)}`)
        .join(' ');

    return (
        <svg
            viewBox="0 0 100 100"
            preserveAspectRatio="none"
            className="absolute inset-0 size-full"
            role="img"
            aria-label={week
                .map(
                    (d) =>
                        `${weekday(d.date)} ${d.date}: hadir ${d.present}, telat ${d.late}`,
                )
                .join('; ')}
        >
            {[2, 34, 66, 98].map((gy) => (
                <line
                    key={gy}
                    x1="0"
                    y1={gy}
                    x2="100"
                    y2={gy}
                    className="stroke-border"
                    vectorEffect="non-scaling-stroke"
                    strokeDasharray={gy === 98 ? undefined : '4 4'}
                />
            ))}
            {week.map((d, i) => (
                <rect
                    key={d.date}
                    x={i * step + step * 0.22}
                    width={step * 0.56}
                    y={y(d.present)}
                    height={98 - y(d.present)}
                    rx="1"
                    className="fill-primary/70"
                />
            ))}
            <polyline
                points={line}
                fill="none"
                className="stroke-status-warning"
                strokeWidth="2"
                vectorEffect="non-scaling-stroke"
            />
        </svg>
    );
}

export default function Dashboard({ attendance, pending }: Props) {
    const { auth, timezone } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const today = formatLongDate(new Date(), timezone);
    const tiles: {
        label: string;
        dot: string;
        value: number | undefined;
        note: string;
        href?: string;
    }[] = [
        {
            label: 'Hadir hari ini',
            dot: 'bg-status-success',
            value: attendance?.present,
            note: 'Sudah presensi masuk',
            href: attendanceIndex().url,
        },
        {
            label: 'Telat',
            dot: 'bg-status-warning',
            value: attendance?.late,
            note: 'Masuk melewati toleransi',
            href: attendanceIndex({
                query: { filter: { status: ['late'] } },
            }).url,
        },
        {
            label: 'Belum presensi',
            dot: 'bg-status-muted',
            value: attendance?.not_yet,
            note: 'Hari kerja, belum presensi masuk',
        },
        {
            label: 'Libur / off',
            dot: 'bg-status-info',
            value: attendance?.off,
            note: 'Hari libur atau jadwal off',
        },
    ];
    const pendingTotal = pending.reduce((sum, p) => sum + p.count, 0);
    const box = 'block min-w-0 rounded-xl border bg-card p-4 shadow-md';

    return (
        <>
            <Head title="Dasbor" />

            <div className="mx-auto w-full max-w-[1440px] space-y-3.5 p-4 md:px-7 md:py-6">
                <header className="mb-4.5">
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Selamat datang, {firstName}
                    </h1>
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        Ringkasan kehadiran tim hari ini, {today}.
                    </p>
                </header>

                {attendance && (
                    <section aria-label="Ringkasan kehadiran hari ini">
                        <div className="grid grid-cols-2 gap-3.5 min-[1100px]:grid-cols-4">
                            {tiles.map((tile) => {
                                const content = (
                                    <>
                                        <span className="flex items-center gap-2 text-[0.82rem] text-muted-foreground">
                                            <span
                                                aria-hidden="true"
                                                className={`size-2 shrink-0 rounded-full ${tile.dot}`}
                                            />
                                            {tile.label}
                                        </span>
                                        <span className="my-0.5 block text-[1.55rem] font-extrabold tracking-tight tabular-nums">
                                            {tile.value ?? <Empty />}
                                        </span>
                                        <span className="block text-[0.8rem] text-muted-foreground">
                                            {tile.note}
                                        </span>
                                    </>
                                );

                                return tile.href ? (
                                    <Link
                                        key={tile.label}
                                        href={tile.href}
                                        className={`${box} transition-colors hover:bg-muted/40`}
                                    >
                                        {content}
                                    </Link>
                                ) : (
                                    <div key={tile.label} className={box}>
                                        {content}
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}

                <div
                    className={
                        attendance && pending.length > 0
                            ? 'grid gap-3.5 min-[1100px]:grid-cols-[1.6fr_1fr]'
                            : 'grid gap-3.5'
                    }
                >
                    {attendance && (
                        <section className="flex min-w-0 flex-col rounded-xl border bg-card p-4 shadow-md">
                            <h2 className="mb-2.5 text-base font-bold">
                                Kehadiran 7 hari
                            </h2>
                            <div className="relative min-h-40 flex-1 md:min-h-48">
                                <WeekChart week={attendance.week} />
                            </div>
                            <div
                                className="mt-1.5 grid grid-cols-7 text-center text-[11px] text-muted-foreground"
                                aria-hidden="true"
                            >
                                {attendance.week.map((d) => (
                                    <span key={d.date}>
                                        {weekday(d.date)}
                                        <span className="block font-semibold text-foreground tabular-nums">
                                            {d.present}
                                        </span>
                                    </span>
                                ))}
                            </div>
                            <p className="mt-2 text-[0.8rem] text-muted-foreground">
                                Batang: jumlah hadir per hari. Garis: jumlah
                                telat.
                            </p>
                        </section>
                    )}

                    {pending.length > 0 && (
                        <section className="min-w-0 rounded-xl border bg-card p-4 shadow-md">
                            <h2 className="mb-2.5 flex items-center gap-2 text-base font-bold">
                                Perlu tindakan
                                <span className="ml-auto inline-flex items-center rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-bold text-status-warning tabular-nums">
                                    {pendingTotal}
                                </span>
                            </h2>
                            {/* Daftar .item prototipe: ikon berlatar amber muda, judul, keterangan. */}
                            <ul className="flex flex-col gap-2.5">
                                {pending.map((item) => {
                                    const {
                                        title,
                                        note,
                                        icon: Icon,
                                    } = PENDING[item.key];

                                    return (
                                        <li key={item.key}>
                                            <Link
                                                href={item.href}
                                                className="flex items-start gap-3 rounded-xl border bg-card p-2.5 transition-colors hover:bg-muted/40"
                                            >
                                                <span className="grid size-[34px] shrink-0 place-items-center rounded-[10px] bg-status-warning-bg text-status-warning">
                                                    <Icon
                                                        className="size-[18px]"
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-[0.92rem] font-semibold">
                                                        {title}
                                                    </span>
                                                    <span className="block text-[0.82rem] text-muted-foreground">
                                                        {note}
                                                    </span>
                                                </span>
                                                <span className="self-center text-base font-bold tabular-nums">
                                                    {item.count}
                                                </span>
                                            </Link>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dasbor',
            href: dashboard(),
        },
    ],
};
