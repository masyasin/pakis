import { Head, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { CalendarX2, ClipboardPen, Smartphone, UserX } from 'lucide-react';
import { formatLongDate } from '@/lib/local-date';
import { dashboard } from '@/routes/admin';

/*
 * Dasbor (07 §4) dengan tata letak prototipe (konsep/PAKIS_ONE_Prototipe_v1.0.html, Dashboard Owner):
 * baris kartu angka, lalu kartu grafik + kartu daftar "perlu tindakan". Angka & daftar diisi saat
 * modul absensi tersedia (Fase 0,5). Sengaja "—", bukan angka contoh, agar tidak dikira data nyata.
 */
const TILES = [
    { label: 'Hadir hari ini', dot: 'bg-status-success' },
    { label: 'Telat', dot: 'bg-status-warning' },
    { label: 'Belum absen', dot: 'bg-status-muted' },
    { label: 'Libur / off', dot: 'bg-status-info' },
] as const;

const PENDING: { title: string; note: string; icon: LucideIcon }[] = [
    {
        title: 'Koreksi absen menunggu',
        note: 'Pengajuan pegawai yang perlu disetujui',
        icon: ClipboardPen,
    },
    {
        title: 'Perangkat menunggu',
        note: 'HP baru yang perlu disetujui untuk Pakis Pro',
        icon: Smartphone,
    },
    {
        title: 'Pegawai tanpa jadwal',
        note: 'Belum punya shift pada minggu ini',
        icon: CalendarX2,
    },
    {
        title: 'Pegawai tanpa akun',
        note: 'Belum bisa masuk ke Pakis Pro',
        icon: UserX,
    },
];

const DAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];

function Empty() {
    return (
        <>
            <span aria-hidden="true">—</span>
            <span className="sr-only">Belum ada data</span>
        </>
    );
}

export default function Dashboard() {
    const { auth, timezone } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const today = formatLongDate(new Date(), timezone);

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

                {/* Kartu angka (.kpi prototipe): label kecil, angka besar tebal, keterangan. */}
                <section aria-label="Ringkasan kehadiran hari ini">
                    <dl className="grid grid-cols-2 gap-3.5 min-[1100px]:grid-cols-4">
                        {TILES.map((tile) => (
                            <div
                                key={tile.label}
                                className="min-w-0 rounded-xl border bg-card p-4 shadow-md"
                            >
                                <dt className="flex items-center gap-2 text-[0.82rem] text-muted-foreground">
                                    <span
                                        aria-hidden="true"
                                        className={`size-2 shrink-0 rounded-full ${tile.dot}`}
                                    />
                                    {tile.label}
                                </dt>
                                <dd className="my-0.5 text-[1.55rem] font-extrabold tracking-tight tabular-nums">
                                    <Empty />
                                </dd>
                                <dd className="text-[0.8rem] text-muted-foreground">
                                    Tersedia setelah absensi aktif
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <div className="grid gap-3.5 min-[1100px]:grid-cols-[1.6fr_1fr]">
                    <section className="flex min-w-0 flex-col rounded-xl border bg-card p-4 shadow-md">
                        <h2 className="mb-2.5 flex items-center gap-2 text-base font-bold">
                            Kehadiran 7 hari
                            <span className="ml-auto inline-flex items-center rounded-full bg-status-info-bg px-2 py-0.5 text-xs font-bold text-status-info">
                                Belum ada data
                            </span>
                        </h2>
                        {/* Kerangka grafik tanpa batang agar tata letak stabil saat data tersedia. Teks
                            ditulis di HTML, bukan di SVG, supaya tidak ikut mengecil di layar HP. */}
                        <div className="relative min-h-40 flex-1 md:min-h-48">
                            <svg
                                viewBox="0 0 100 100"
                                preserveAspectRatio="none"
                                className="absolute inset-0 size-full"
                                aria-hidden="true"
                            >
                                {[2, 34, 66, 98].map((y) => (
                                    <line
                                        key={y}
                                        x1="0"
                                        y1={y}
                                        x2="100"
                                        y2={y}
                                        className="stroke-border"
                                        vectorEffect="non-scaling-stroke"
                                        strokeDasharray={
                                            y === 98 ? undefined : '4 4'
                                        }
                                    />
                                ))}
                            </svg>
                            <p className="absolute inset-0 grid place-items-center px-4 text-center text-sm text-muted-foreground">
                                Grafik tampil setelah ada data absensi
                            </p>
                        </div>
                        <div
                            className="mt-1.5 grid grid-cols-7 text-center text-[11px] text-muted-foreground"
                            aria-hidden="true"
                        >
                            {DAYS.map((d) => (
                                <span key={d}>{d}</span>
                            ))}
                        </div>
                        <p className="mt-2 text-[0.8rem] text-muted-foreground">
                            Batang: jumlah hadir per hari. Garis: jumlah telat.
                        </p>
                    </section>

                    <section className="min-w-0 rounded-xl border bg-card p-4 shadow-md">
                        <h2 className="mb-2.5 flex items-center gap-2 text-base font-bold">
                            Perlu tindakan
                            <span className="ml-auto inline-flex items-center rounded-full bg-status-muted-bg px-2 py-0.5 text-xs font-bold text-status-muted">
                                <Empty />
                            </span>
                        </h2>
                        {/* Daftar .item prototipe: ikon berlatar amber muda, judul, keterangan. */}
                        <ul className="flex flex-col gap-2.5">
                            {PENDING.map(({ title, note, icon: Icon }) => (
                                <li
                                    key={title}
                                    className="flex items-start gap-3 rounded-xl border bg-card p-2.5"
                                >
                                    <span className="grid size-[34px] shrink-0 place-items-center rounded-[10px] bg-status-warning-bg text-status-warning">
                                        <Icon
                                            className="size-[18px]"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <p className="text-[0.92rem] font-semibold">
                                            {title}
                                        </p>
                                        <p className="text-[0.82rem] text-muted-foreground">
                                            {note}
                                        </p>
                                    </div>
                                    <span className="self-center text-base font-bold text-muted-foreground tabular-nums">
                                        <Empty />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
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
