import { Head, Link, router, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    ArrowLeftRight,
    BadgeDollarSign,
    CalendarClock,
    CalendarX2,
    ClipboardList,
    ClipboardPen,
    FileBadge,
    FileUp,
    PackageCheck,
    PackageOpen,
    Percent,
    RefreshCw,
    ShieldAlert,
    Smartphone,
    Stamp,
    Store,
    Tags,
    Trash2,
    Truck,
    Undo2,
    UserX,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { Input } from '@/components/ui/input';
import { formatCivilRange, formatLongDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes/admin';
import { index as attendanceIndex } from '@/routes/admin/attendance';

/*
 * Dasbor (07 §4, FR-040, T-044; grafik keputusan Mas 16 Nov 2026). Semua angka dari server dalam
 * scope pengguna (TodaySummary + AttendanceInsights). Kartu "perlu tindakan" menaut ke daftar dengan
 * filter yang sama, jadi angkanya = jumlah baris di daftar itu. Data dimuat ulang otomatis tiap
 * `ui.dashboard_refresh_seconds` lewat partial reload (hanya prop data, halaman tidak berkedip).
 */
type Summary = {
    date: string;
    present: number;
    late: number;
    not_yet: number;
    off: number;
    week: { date: string; present: number; late: number }[];
};

type Charts = {
    from: string;
    to: string;
    max_days: number;
    trend: { date: string; on_time: number; late: number; absent: number }[];
    locations: { name: string; present: number; late: number }[];
    check_ins: { time: string; count: number }[];
    check_ins_outside: number;
};

type PendingItem = {
    key:
        | 'approvals'
        | 'corrections'
        | 'devices'
        | 'unscheduled'
        | 'registrations_expiring'
        | 'po_overdue'
        | 'transfers_unreceived'
        | 'no_account'
        // Kartu tambahan Paket A (T-087A, PA-73); server tidak mengirimnya bila bernilai 0.
        | 'quarantine_batches'
        | 'expired_batches'
        | 'opnames_open'
        | 'disposals_approved'
        | 'returns_open'
        | 'receipts_draft'
        | 'customers_unverified'
        | 'price_drafts'
        | 'promos_ending'
        | 'imports_pending'
        | 'products_unpriced';
    count: number;
    href: string;
};

/** Widget "Stok mendekati ED" (15 §7.4, T-078); `value` null bila tanpa `stock.view_cost`. */
type NearExpiry = {
    thresholds: number[];
    show_value: boolean;
    more: number;
    locations: {
        id: number;
        name: string;
        buckets: { days: number; batches: number; value: string | null }[];
    }[];
};

type BelowMinimum = {
    total: number;
    items: {
        product_id: number;
        sku: string;
        name: string;
        location_id: number;
        location: string;
        qty_on_hand: string;
        min_qty_base: string;
    }[];
};

/** Banner "Saldo awal masih dibuka" (T-087A); `value` null bila tanpa `stock.view_cost`. */
type OpeningBalance = { count: number; value: string | null; href: string };

/** Produk aktif tanpa stok minimum per lokasi (T-087A). */
type LevelsUnset = {
    total: number;
    items: { location_id: number; location: string; missing: number }[];
};

/** Grafik stok (T-087A): nilai per lokasi (null tanpa `stock.view_cost`), mutasi per hari, ED per bulan. */
type StockCharts = {
    value: { location_id: number; location: string; value: string }[] | null;
    flow: { date: string; in: number; out: number }[];
    expiry: { month: string; batches: number; value: string | null }[];
    expiry_days: number;
};

type Props = {
    attendance: Summary | null;
    charts: Charts | null;
    pending: PendingItem[];
    near_expiry?: NearExpiry | null;
    below_min?: BelowMinimum | null;
    opening_balance?: OpeningBalance | null;
    levels_unset?: LevelsUnset | null;
    stock_charts?: StockCharts | null;
    refresh_seconds: number;
};

const PENDING: Record<
    PendingItem['key'],
    { title: string; note: string; icon: LucideIcon }
> = {
    // Menunggu persetujuan saya (15 §7.4, T-067).
    approvals: {
        title: 'Menunggu persetujuan saya',
        note: 'Permintaan yang menunggu keputusan Anda',
        icon: Stamp,
    },
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
    // Izin edar akan habis (15 §7.4, T-071).
    registrations_expiring: {
        title: 'Izin edar akan habis',
        note: 'Produk yang perlu perpanjangan izin',
        icon: FileBadge,
    },
    // PO terlambat (15 §7.4, T-079).
    po_overdue: {
        title: 'PO terlambat',
        note: 'Barang belum datang lewat perkiraan',
        icon: Truck,
    },
    // Mutasi belum diterima (15 §7.4, T-082): dikirim ke lokasi saya, lewat batas jam.
    transfers_unreceived: {
        title: 'Mutasi belum diterima',
        note: 'Kiriman ke lokasi Anda lewat batas jam',
        icon: ArrowLeftRight,
    },
    no_account: {
        title: 'Pegawai tanpa akun',
        note: 'Belum bisa masuk ke Pakis Pro',
        icon: UserX,
    },
    // Kartu tambahan Paket A (T-087A, PA-73).
    quarantine_batches: {
        title: 'Batch di karantina',
        note: 'Perlu dilepas, diretur, atau dimusnahkan',
        icon: ShieldAlert,
    },
    expired_batches: {
        title: 'Batch kedaluwarsa masih ada stok',
        note: 'Karantinakan atau ajukan pemusnahan',
        icon: CalendarClock,
    },
    opnames_open: {
        title: 'Opname belum selesai',
        note: 'Sedang dihitung, ditinjau, atau menunggu persetujuan',
        icon: ClipboardList,
    },
    disposals_approved: {
        title: 'Pemusnahan belum dilaksanakan',
        note: 'Berita acara sudah disetujui',
        icon: Trash2,
    },
    returns_open: {
        title: 'Retur ke prinsipal belum selesai',
        note: 'Belum dikirim atau belum dikonfirmasi',
        icon: Undo2,
    },
    receipts_draft: {
        title: 'Penerimaan barang masih draf',
        note: 'Belum diperiksa dan diposting',
        icon: PackageCheck,
    },
    customers_unverified: {
        title: 'Kios menunggu verifikasi',
        note: 'Prospek yang perlu diverifikasi',
        icon: Store,
    },
    price_drafts: {
        title: 'Daftar harga punya versi draf',
        note: 'Belum diajukan untuk terbit',
        icon: Tags,
    },
    promos_ending: {
        title: 'Promo segera berakhir',
        note: 'Promo aktif yang hampir habis masa berlakunya',
        icon: Percent,
    },
    imports_pending: {
        title: 'Impor perlu tindakan',
        note: 'Sudah divalidasi belum diproses, atau gagal',
        icon: FileUp,
    },
    products_unpriced: {
        title: 'Produk tanpa harga',
        note: 'Dijual, tetapi belum ada di daftar harga berlaku',
        icon: BadgeDollarSign,
    },
};

/** Warna seri (token `--viz-*` di app.css, divalidasi untuk buta warna di tema terang & gelap). */
const SERIES = {
    on_time: { label: 'Tepat waktu', color: 'var(--viz-on-time)' },
    late: { label: 'Telat', color: 'var(--viz-late)' },
    absent: { label: 'Tidak hadir', color: 'var(--viz-absent)' },
} as const;

const NEUTRAL = 'var(--border)';
const DATA_KEYS = [
    'attendance',
    'charts',
    'pending',
    'near_expiry',
    'below_min',
    'opening_balance',
    'levels_unset',
    'stock_charts',
];
const WEEKDAY = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];

/** Nama hari & tanggal dari tanggal kalender `YYYY-MM-DD` (tanpa zona: tanggal sudah lokal). */
function civil(date: string): { weekday: string; day: number; month: number } {
    const [y, m, d] = date.split('-').map(Number);

    return {
        weekday: WEEKDAY[new Date(Date.UTC(y, m - 1, d)).getUTCDay()],
        day: d,
        month: m,
    };
}

function clock(date: Date, timeZone: string): string {
    return new Intl.DateTimeFormat('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
        timeZone,
    }).format(date);
}

/** Animasi grafik hanya bila pengguna tidak meminta gerak dikurangi. */
function useReducedMotion(): boolean {
    const [reduced, setReduced] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReduced(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return reduced;
}

function Empty() {
    return (
        <>
            <span aria-hidden="true">—</span>
            <span className="sr-only">Belum ada data</span>
        </>
    );
}

function Card({
    title,
    action,
    note,
    className,
    children,
}: {
    title: string;
    action?: ReactNode;
    note?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'flex min-w-0 flex-col rounded-xl border bg-card p-4 shadow-md',
                className,
            )}
        >
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <h2 className="text-base font-bold">{title}</h2>
                {action && <div className="ml-auto">{action}</div>}
            </div>
            {children}
            {note && (
                <p className="mt-2 text-[0.8rem] text-muted-foreground">
                    {note}
                </p>
            )}
        </section>
    );
}

/** Legenda: identitas seri tidak hanya lewat warna (nama tertulis di samping tanda warna). */
function Legend({ items }: { items: { label: string; color: string }[] }) {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-[0.8rem] text-muted-foreground">
            {items.map((item) => (
                <li key={item.label} className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className="size-2.5 rounded-[3px]"
                        style={{ background: item.color }}
                    />
                    {item.label}
                </li>
            ))}
        </ul>
    );
}

type TipRow = { name: string; value: number | string; color: string };

/** Isi tooltip: teks memakai warna tinta biasa, warna seri hanya pada tanda di sampingnya. */
function TipBox({ title, rows }: { title: string; rows: TipRow[] }) {
    return (
        <div className="min-w-36 rounded-lg border bg-popover px-3 py-2 text-xs text-popover-foreground shadow-lg">
            <p className="mb-1 font-semibold">{title}</p>
            {rows.map((row) => (
                <p key={row.name} className="flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        className="size-2 rounded-[2px]"
                        style={{ background: row.color }}
                    />
                    <span className="text-muted-foreground">{row.name}</span>
                    <span className="ml-auto pl-3 font-semibold tabular-nums">
                        {row.value}
                    </span>
                </p>
            ))}
        </div>
    );
}

type TipArgs = {
    active?: boolean;
    payload?: readonly { payload?: unknown }[];
};

/** Konten tooltip recharts: ambil baris data yang sedang ditunjuk, lalu tampilkan lewat `render`. */
function tip<T>(render: (row: T) => ReactNode) {
    function Content({ active, payload }: TipArgs) {
        const row = payload?.[0]?.payload as T | undefined;

        return active && row ? render(row) : null;
    }

    return Content;
}

const axisTick = { fontSize: 11, fill: 'var(--muted-foreground)' };

/** Label sumbu dua baris dari nilai `Hari|tanggal`. */
function StackedTick({
    x,
    y,
    payload,
}: {
    x?: number | string;
    y?: number | string;
    payload?: { value?: string | number };
}) {
    const [top, bottom] = String(payload?.value ?? '').split('|');

    return (
        <text
            x={x}
            y={y}
            textAnchor="middle"
            fontSize={11}
            fill="var(--muted-foreground)"
        >
            <tspan x={x} dy="0.9em">
                {top}
            </tspan>
            <tspan x={x} dy="1.2em" fill="var(--foreground)">
                {bottom}
            </tspan>
        </text>
    );
}

function TrendChart({
    trend,
    animate,
}: {
    trend: Charts['trend'];
    animate: boolean;
}) {
    const long = trend.length > 7;
    const data = trend.map((d) => {
        const c = civil(d.date);

        return {
            ...d,
            label: long ? `${c.day}/${c.month}` : `${c.weekday}|${c.day}`,
            full: `${c.weekday}, ${c.day}/${c.month}`,
        };
    });
    const keys = ['on_time', 'late', 'absent'] as const;

    return (
        <>
            <div className="h-56 md:h-64" aria-hidden="true">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={data}
                        margin={{ top: 4, right: 4, bottom: 0, left: -18 }}
                        barCategoryGap={long ? '18%' : '28%'}
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            dataKey="label"
                            // 7 hari: dua baris (hari / tanggal) agar tidak berdempetan di HP.
                            tick={long ? axisTick : StackedTick}
                            height={long ? 30 : 36}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                            interval={long ? 'preserveStartEnd' : 0}
                            minTickGap={8}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={tip<(typeof data)[number]>((row) => (
                                <TipBox
                                    title={row.full}
                                    rows={keys.map((k) => ({
                                        name: SERIES[k].label,
                                        value: row[k],
                                        color: SERIES[k].color,
                                    }))}
                                />
                            ))}
                        />
                        {keys.map((k, i) => (
                            <Bar
                                key={k}
                                dataKey={k}
                                stackId="hari"
                                fill={SERIES[k].color}
                                // Celah 2px warna kartu antarsegmen; ujung atas membulat.
                                stroke="var(--card)"
                                strokeWidth={1}
                                radius={
                                    i === keys.length - 1
                                        ? [4, 4, 0, 0]
                                        : undefined
                                }
                                isAnimationActive={animate}
                                animationDuration={500}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Kehadiran per hari</caption>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Tepat waktu</th>
                        <th>Telat</th>
                        <th>Tidak hadir</th>
                    </tr>
                </thead>
                <tbody>
                    {trend.map((d) => (
                        <tr key={d.date}>
                            <td>{d.date}</td>
                            <td>{d.on_time}</td>
                            <td>{d.late}</td>
                            <td>{d.absent}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

/** Komposisi hari ini dari angka kartu (TodaySummary), tanpa query tambahan. */
function TodayDonut({
    summary,
    animate,
}: {
    summary: Summary;
    animate: boolean;
}) {
    const onTime = summary.present - summary.late;
    const expected = summary.present + summary.not_yet;
    const rate =
        expected > 0 ? Math.round((summary.present / expected) * 100) : null;
    const slices = [
        {
            name: SERIES.on_time.label,
            value: onTime,
            color: SERIES.on_time.color,
        },
        {
            name: SERIES.late.label,
            value: summary.late,
            color: SERIES.late.color,
        },
        { name: 'Belum presensi', value: summary.not_yet, color: NEUTRAL },
    ];
    const hasData = slices.some((s) => s.value > 0);

    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3">
            <div className="relative size-44" aria-hidden="true">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={
                                hasData
                                    ? slices
                                    : [
                                          {
                                              name: 'kosong',
                                              value: 1,
                                              color: NEUTRAL,
                                          },
                                      ]
                            }
                            dataKey="value"
                            nameKey="name"
                            innerRadius="72%"
                            outerRadius="100%"
                            paddingAngle={hasData ? 2 : 0}
                            stroke="var(--card)"
                            strokeWidth={2}
                            startAngle={90}
                            endAngle={-270}
                            isAnimationActive={animate}
                            animationDuration={600}
                        >
                            {(hasData ? slices : [{ color: NEUTRAL }]).map(
                                (s, i) => (
                                    <Cell key={i} fill={s.color} />
                                ),
                            )}
                        </Pie>
                        {hasData && (
                            <Tooltip
                                content={tip<(typeof slices)[number]>((row) => (
                                    <TipBox title="Hari ini" rows={[row]} />
                                ))}
                            />
                        )}
                    </PieChart>
                </ResponsiveContainer>
                <div className="pointer-events-none absolute inset-0 grid place-content-center text-center">
                    <span className="text-[1.6rem] leading-none font-extrabold tabular-nums">
                        {rate === null ? '—' : `${rate}%`}
                    </span>
                    <span className="mt-1 text-[0.72rem] text-muted-foreground">
                        kehadiran
                    </span>
                </div>
            </div>
            <p className="sr-only">
                Kehadiran hari ini {rate === null ? 'belum ada' : `${rate}%`}:
                tepat waktu {onTime}, telat {summary.late}, belum presensi{' '}
                {summary.not_yet}.
            </p>
            <Legend
                items={slices.map((s) => ({
                    label: `${s.name} ${s.value}`,
                    color: s.color,
                }))}
            />
        </div>
    );
}

function LocationChart({
    locations,
    animate,
}: {
    locations: Charts['locations'];
    animate: boolean;
}) {
    const data = locations.map((l) => ({
        ...l,
        on_time: l.present - l.late,
    }));
    const keys = ['on_time', 'late'] as const;

    return (
        <>
            <div
                style={{ height: Math.max(120, data.length * 40 + 24) }}
                aria-hidden="true"
            >
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={data}
                        layout="vertical"
                        margin={{ top: 0, right: 8, bottom: 0, left: 0 }}
                        barCategoryGap="30%"
                    >
                        <CartesianGrid
                            horizontal={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            type="number"
                            allowDecimals={false}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <YAxis
                            type="category"
                            dataKey="name"
                            width={104}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={tip<(typeof data)[number]>((row) => (
                                <TipBox
                                    title={row.name}
                                    rows={keys.map((k) => ({
                                        name: SERIES[k].label,
                                        value: row[k],
                                        color: SERIES[k].color,
                                    }))}
                                />
                            ))}
                        />
                        {keys.map((k, i) => (
                            <Bar
                                key={k}
                                dataKey={k}
                                stackId="lokasi"
                                fill={SERIES[k].color}
                                stroke="var(--card)"
                                strokeWidth={1}
                                radius={i === 1 ? [0, 4, 4, 0] : undefined}
                                isAnimationActive={animate}
                                animationDuration={500}
                            />
                        ))}
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Hadir hari ini per lokasi</caption>
                <thead>
                    <tr>
                        <th>Lokasi</th>
                        <th>Hadir</th>
                        <th>Telat</th>
                    </tr>
                </thead>
                <tbody>
                    {locations.map((l) => (
                        <tr key={l.name}>
                            <td>{l.name}</td>
                            <td>{l.present}</td>
                            <td>{l.late}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

function CheckInChart({
    checkIns,
    animate,
}: {
    checkIns: Charts['check_ins'];
    animate: boolean;
}) {
    return (
        <>
            <div className="h-48" aria-hidden="true">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={checkIns}
                        margin={{ top: 4, right: 4, bottom: 0, left: -18 }}
                        barCategoryGap="12%"
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            dataKey="time"
                            tick={axisTick}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                            interval="preserveStartEnd"
                            minTickGap={12}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={tip<Charts['check_ins'][number]>((row) => (
                                <TipBox
                                    title={`Pukul ${row.time}`}
                                    rows={[
                                        {
                                            name: 'Presensi masuk',
                                            value: row.count,
                                            color: 'var(--primary)',
                                        },
                                    ]}
                                />
                            ))}
                        />
                        <Bar
                            dataKey="count"
                            fill="var(--primary)"
                            radius={[4, 4, 0, 0]}
                            isAnimationActive={animate}
                            animationDuration={500}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Presensi masuk hari ini per 15 menit</caption>
                <thead>
                    <tr>
                        <th>Mulai pukul</th>
                        <th>Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    {checkIns.map((b) => (
                        <tr key={b.time}>
                            <td>{b.time}</td>
                            <td>{b.count}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

/** Geser tanggal sipil `YYYY-MM-DD` sebanyak `days` hari (dibaca sebagai UTC, tanpa zona). */
function shiftDate(iso: string, days: number): string {
    const [y, m, d] = iso.split('-').map(Number);

    return new Date(Date.UTC(y, m - 1, d + days)).toISOString().slice(0, 10);
}

/**
 * Filter rentang grafik (satu baris di atas grafik): pilihan cepat + tanggal dari–sampai. Nilai
 * dikirim apa adanya; server merapikan rentang yang terbalik/terlalu panjang dan mengembalikan
 * rentang yang benar-benar dipakai.
 */
function RangeFilter({
    from,
    to,
    today,
    maxDays,
    onChange,
}: {
    from: string;
    to: string;
    today: string;
    maxDays: number;
    onChange: (range: { dari: string; sampai: string }) => void;
}) {
    const [draft, setDraft] = useState({ from, to });
    // Ikuti rentang dari server (mis. setelah dirapikan) tanpa efek: bandingkan saat render.
    const [synced, setSynced] = useState({ from, to });
    if (synced.from !== from || synced.to !== to) {
        setSynced({ from, to });
        setDraft({ from, to });
    }

    const presets = [
        { label: '7 hari', from: shiftDate(today, -6), to: today },
        { label: '30 hari', from: shiftDate(today, -29), to: today },
        { label: 'Bulan ini', from: `${today.slice(0, 8)}01`, to: today },
    ];
    const apply = (next: { from: string; to: string }) => {
        setDraft(next);
        if (next.from && next.to) {
            onChange({ dari: next.from, sampai: next.to });
        }
    };

    return (
        <section
            aria-label="Rentang grafik"
            className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-card px-4 py-3 shadow-md"
        >
            <div className="flex flex-wrap gap-1.5">
                {presets.map((p) => {
                    const active = p.from === from && p.to === to;

                    return (
                        <button
                            key={p.label}
                            type="button"
                            aria-pressed={active}
                            onClick={() => apply({ from: p.from, to: p.to })}
                            className={cn(
                                'rounded-md border px-3 py-1.5 text-[0.82rem] font-medium transition-colors',
                                active
                                    ? 'border-primary bg-sidebar-accent text-primary'
                                    : 'bg-card hover:bg-muted',
                            )}
                        >
                            {p.label}
                        </button>
                    );
                })}
            </div>
            <div className="flex flex-wrap items-center gap-2 text-[0.82rem] text-muted-foreground">
                <label className="flex items-center gap-1.5">
                    Dari
                    <Input
                        type="date"
                        value={draft.from}
                        max={today}
                        onChange={(e) =>
                            apply({ ...draft, from: e.target.value })
                        }
                        className="h-8 w-[9.5rem]"
                    />
                </label>
                <label className="flex items-center gap-1.5">
                    Sampai
                    <Input
                        type="date"
                        value={draft.to}
                        max={today}
                        onChange={(e) =>
                            apply({ ...draft, to: e.target.value })
                        }
                        className="h-8 w-[9.5rem]"
                    />
                </label>
            </div>
            <p className="text-[0.8rem] text-muted-foreground lg:ml-auto">
                Rentang paling panjang {maxDays} hari
            </p>
        </section>
    );
}

/**
 * Tabel per lokasi stok × ambang ED (kumulatif, ED ≤ N hari). Sel = jumlah batch, nilai HPP di
 * bawahnya bila boleh dilihat. Tabel biasa (bukan grafik) agar angka bisa dibaca persis di HP.
 */
function NearExpiryTable({ data }: { data: NearExpiry }) {
    return (
        <div className="-mx-1 overflow-x-auto">
            <table className="w-full min-w-[320px] text-sm">
                <caption className="sr-only">
                    Jumlah batch mendekati ED per lokasi stok
                </caption>
                <thead>
                    <tr className="border-b text-left text-[0.78rem] text-muted-foreground">
                        <th scope="col" className="px-1 py-1.5 font-medium">
                            Lokasi stok
                        </th>
                        {data.thresholds.map((days) => (
                            <th
                                key={days}
                                scope="col"
                                className="px-1 py-1.5 text-right font-medium whitespace-nowrap"
                            >
                                ≤ {days} hari
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {data.locations.map((location) => (
                        <tr
                            key={location.id}
                            className="border-b last:border-0"
                        >
                            <th
                                scope="row"
                                className="max-w-[12rem] truncate px-1 py-2 text-left font-medium"
                            >
                                {location.name}
                            </th>
                            {location.buckets.map((bucket) => (
                                <td
                                    key={bucket.days}
                                    className="px-1 py-2 text-right align-top tabular-nums"
                                >
                                    <span className="block font-semibold">
                                        {bucket.batches} batch
                                    </span>
                                    {bucket.value !== null && (
                                        <span className="block text-[0.78rem] text-muted-foreground">
                                            {formatRupiah(bucket.value)}
                                        </span>
                                    )}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function BelowMinimumList({ data }: { data: BelowMinimum }) {
    return (
        <ul className="divide-y text-sm">
            {data.items.map((item) => (
                <li
                    key={`${item.product_id}-${item.location_id}`}
                    className="flex items-start gap-3 py-2"
                >
                    <span className="grid min-w-0 flex-1 gap-0.5">
                        <span className="font-medium break-words">
                            {item.name}
                        </span>
                        <span className="text-[0.78rem] text-muted-foreground">
                            {item.sku} · {item.location}
                        </span>
                    </span>
                    <span className="text-right tabular-nums">
                        <span className="block font-semibold text-destructive">
                            {formatQty(item.qty_on_hand)}
                        </span>
                        <span className="block text-[0.78rem] text-muted-foreground">
                            min {formatQty(item.min_qty_base)}
                        </span>
                    </span>
                </li>
            ))}
        </ul>
    );
}

const STOCK_SERIES = {
    in: { label: 'Masuk', color: 'var(--viz-series-1)' },
    out: { label: 'Keluar', color: 'var(--viz-series-2)' },
} as const;
const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];
const compact = new Intl.NumberFormat('id-ID', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

function monthLabel(ym: string): string {
    const [y, m] = ym.split('-').map(Number);

    return `${MONTHS[m - 1]} ${y}`;
}

/** Nilai stok (HPP) per lokasi: batang mendatar satu seri, diurutkan dari server (terbesar dulu). */
function StockValueChart({
    rows,
    animate,
}: {
    rows: NonNullable<StockCharts['value']>;
    animate: boolean;
}) {
    // Angka plot saja; teks selalu dari string kanonik server (tanpa pembulatan float).
    const data = rows.map((r) => ({ ...r, plot: Number(r.value) }));

    return (
        <>
            <div
                style={{ height: Math.max(120, data.length * 40 + 24) }}
                aria-hidden="true"
            >
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={data}
                        layout="vertical"
                        margin={{ top: 0, right: 8, bottom: 0, left: 0 }}
                        barCategoryGap="30%"
                    >
                        <CartesianGrid
                            horizontal={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            type="number"
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                            tickFormatter={(v: number) => compact.format(v)}
                        />
                        <YAxis
                            type="category"
                            dataKey="location"
                            width={104}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={tip<(typeof data)[number]>((row) => (
                                <TipBox
                                    title={row.location}
                                    rows={[
                                        {
                                            name: 'Nilai stok',
                                            value: formatRupiah(row.value),
                                            color: 'var(--viz-series-1)',
                                        },
                                    ]}
                                />
                            ))}
                        />
                        <Bar
                            dataKey="plot"
                            fill="var(--viz-series-1)"
                            radius={[0, 4, 4, 0]}
                            isAnimationActive={animate}
                            animationDuration={500}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Nilai stok per lokasi</caption>
                <thead>
                    <tr>
                        <th>Lokasi</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r) => (
                        <tr key={r.location_id}>
                            <td>{r.location}</td>
                            <td>{formatRupiah(r.value)}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

/** Baris mutasi masuk vs keluar per hari: dua garis 2px, legenda di atas, tooltip per hari. */
function StockFlowChart({
    flow,
    animate,
}: {
    flow: StockCharts['flow'];
    animate: boolean;
}) {
    const data = flow.map((d) => {
        const c = civil(d.date);

        return {
            ...d,
            label: `${c.day}/${c.month}`,
            full: `${c.weekday}, ${c.day}/${c.month}`,
        };
    });
    const keys = ['in', 'out'] as const;

    return (
        <>
            <div className="h-56" aria-hidden="true">
                <ResponsiveContainer width="100%" height="100%">
                    <LineChart
                        data={data}
                        margin={{ top: 8, right: 8, bottom: 0, left: -18 }}
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            dataKey="label"
                            tick={axisTick}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                            interval="preserveStartEnd"
                            minTickGap={8}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ stroke: 'var(--border)' }}
                            content={tip<(typeof data)[number]>((row) => (
                                <TipBox
                                    title={row.full}
                                    rows={keys.map((k) => ({
                                        name: STOCK_SERIES[k].label,
                                        value: row[k],
                                        color: STOCK_SERIES[k].color,
                                    }))}
                                />
                            ))}
                        />
                        {keys.map((k) => (
                            <Line
                                key={k}
                                type="monotone"
                                dataKey={k}
                                stroke={STOCK_SERIES[k].color}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{
                                    r: 4,
                                    stroke: 'var(--card)',
                                    strokeWidth: 2,
                                }}
                                isAnimationActive={animate}
                                animationDuration={500}
                            />
                        ))}
                    </LineChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Baris mutasi stok per hari</caption>
                <thead>
                    <tr>
                        <th>Tanggal</th>
                        <th>Masuk</th>
                        <th>Keluar</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((d) => (
                        <tr key={d.date}>
                            <td>{d.full}</td>
                            <td>{d.in}</td>
                            <td>{d.out}</td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

/** Batch mendekati ED per bulan (cakrawala = ambang ED terbesar): batang satu seri jumlah batch. */
function ExpiryMonthChart({
    rows,
    animate,
}: {
    rows: StockCharts['expiry'];
    animate: boolean;
}) {
    const data = rows.map((r) => ({ ...r, label: monthLabel(r.month) }));

    return (
        <>
            <div className="h-56" aria-hidden="true">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                        data={data}
                        margin={{ top: 4, right: 4, bottom: 0, left: -18 }}
                        barCategoryGap="28%"
                    >
                        <CartesianGrid
                            vertical={false}
                            stroke="var(--border)"
                            strokeDasharray="4 4"
                        />
                        <XAxis
                            dataKey="label"
                            tick={axisTick}
                            tickLine={false}
                            axisLine={{ stroke: 'var(--border)' }}
                        />
                        <YAxis
                            allowDecimals={false}
                            tick={axisTick}
                            tickLine={false}
                            axisLine={false}
                        />
                        <Tooltip
                            cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                            content={tip<(typeof data)[number]>((row) => (
                                <TipBox
                                    title={row.label}
                                    rows={[
                                        {
                                            name: 'Batch',
                                            value: row.batches,
                                            color: 'var(--viz-series-1)',
                                        },
                                        ...(row.value !== null
                                            ? [
                                                  {
                                                      name: 'Nilai',
                                                      value: formatRupiah(
                                                          row.value,
                                                      ),
                                                      color: 'transparent',
                                                  },
                                              ]
                                            : []),
                                    ]}
                                />
                            ))}
                        />
                        <Bar
                            dataKey="batches"
                            fill="var(--viz-series-1)"
                            radius={[4, 4, 0, 0]}
                            isAnimationActive={animate}
                            animationDuration={500}
                        />
                    </BarChart>
                </ResponsiveContainer>
            </div>
            <table className="sr-only">
                <caption>Batch mendekati ED per bulan</caption>
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th>Batch</th>
                        <th>Nilai</th>
                    </tr>
                </thead>
                <tbody>
                    {data.map((r) => (
                        <tr key={r.month}>
                            <td>{r.label}</td>
                            <td>{r.batches}</td>
                            <td>
                                {r.value === null ? '–' : formatRupiah(r.value)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </>
    );
}

function LevelsUnsetList({ data }: { data: LevelsUnset }) {
    return (
        <ul className="divide-y text-sm">
            {data.items.map((item) => (
                <li key={item.location_id}>
                    <Link
                        href={`/stok/minimum?filter[stock_location_id]=${item.location_id}&filter[unset]=1`}
                        className="flex items-center gap-3 py-2 hover:underline"
                    >
                        <span className="min-w-0 flex-1 font-medium break-words">
                            {item.location}
                        </span>
                        <span className="font-semibold tabular-nums">
                            {item.missing.toLocaleString('id-ID')} produk
                        </span>
                    </Link>
                </li>
            ))}
        </ul>
    );
}

function NoData({ children }: { children: ReactNode }) {
    return (
        <p className="grid flex-1 place-items-center rounded-lg border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}

export default function Dashboard({
    attendance,
    charts,
    pending: pendingItems,
    near_expiry: nearExpiry = null,
    below_min: belowMin = null,
    opening_balance: openingBalance = null,
    levels_unset: levelsUnset = null,
    stock_charts: stockCharts = null,
    refresh_seconds: refreshSeconds,
}: Props) {
    // Item yang belum dikenal build frontend ini (server lebih baru dari aset) dilewati, bukan
    // membuat seluruh dasbor gagal dirender.
    const pending = pendingItems.filter((p) => Object.hasOwn(PENDING, p.key));
    const { auth, timezone } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';
    const today = formatLongDate(new Date(), timezone);
    const reduced = useReducedMotion();
    const [updatedAt, setUpdatedAt] = useState(() => new Date());
    const [loading, setLoading] = useState(false);
    // Animasi hanya saat pertama tampil; muat ulang berkala tidak menggerakkan ulang grafik.
    const [animate, setAnimate] = useState(!reduced);

    const reload = useCallback(
        (data?: Record<string, string>) => {
            setAnimate(data !== undefined && !reduced);
            router.reload({
                only: DATA_KEYS,
                data,
                onStart: () => setLoading(true),
                onSuccess: () => setUpdatedAt(new Date()),
                onFinish: () => setLoading(false),
            });
        },
        [reduced],
    );

    useEffect(() => {
        if (refreshSeconds <= 0) {
            return;
        }

        // Tab tersembunyi tidak memuat ulang: dasbor yang ditinggal terbuka tidak membebani server.
        const id = window.setInterval(() => {
            if (document.visibilityState === 'visible') {
                reload();
            }
        }, refreshSeconds * 1000);

        return () => window.clearInterval(id);
    }, [refreshSeconds, reload]);

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
    const hasCheckIns = (charts?.check_ins.length ?? 0) > 0;
    // Baris bawah dua kolom: bila kartunya ganjil, "Perlu tindakan" selebar penuh (daftar 2 kolom)
    // agar tidak ada sel kosong di sebelahnya.
    const lowerCards =
        (charts ? 1 : 0) +
        (charts && charts.locations.length > 0 ? 1 : 0) +
        (nearExpiry ? 1 : 0) +
        (belowMin ? 1 : 0) +
        (levelsUnset && levelsUnset.items.length > 0 ? 1 : 0) +
        (pending.length > 0 ? 1 : 0);
    const pendingWide = lowerCards % 2 === 1;
    const rangeText = charts ? formatCivilRange(charts.from, charts.to) : '';

    return (
        <>
            <Head title="Dasbor" />

            <div className="mx-auto w-full max-w-[1440px] space-y-3.5 p-4 md:px-7 md:py-6">
                <header className="mb-4.5 flex flex-wrap items-end gap-x-4 gap-y-2">
                    <div className="min-w-0">
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Selamat datang, {firstName}
                        </h1>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            Ringkasan kehadiran tim hari ini, {today}.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => reload()}
                        disabled={loading}
                        className="ml-auto inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[0.8rem] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:opacity-70"
                    >
                        <RefreshCw
                            className={cn(
                                'size-3.5',
                                loading && 'motion-safe:animate-spin',
                            )}
                            aria-hidden="true"
                        />
                        <span aria-live="polite">
                            Diperbarui pukul {clock(updatedAt, timezone)}
                        </span>
                        <span className="sr-only">, muat ulang sekarang</span>
                    </button>
                </header>

                {openingBalance && (
                    <Link
                        href={openingBalance.href}
                        className="flex items-start gap-3 rounded-xl border border-status-warning/40 bg-status-warning-bg p-3.5 text-sm transition-colors hover:bg-status-warning-bg/70"
                    >
                        <PackageOpen
                            className="mt-0.5 size-5 shrink-0 text-status-warning"
                            aria-hidden="true"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block font-semibold">
                                Saldo awal stok masih dibuka
                            </span>
                            <span className="block text-muted-foreground">
                                {openingBalance.count.toLocaleString('id-ID')}{' '}
                                baris saldo awal sudah masuk
                                {openingBalance.value !== null &&
                                    `, senilai ${formatRupiah(openingBalance.value)}`}
                                . Setelah nilainya cocok dengan pembukuan lama,
                                tutup saldo awal di Pengaturan › Gudang & stok.
                            </span>
                        </span>
                    </Link>
                )}

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

                {attendance && charts && (
                    <RangeFilter
                        from={charts.from}
                        to={charts.to}
                        today={attendance.date}
                        maxDays={charts.max_days}
                        onChange={reload}
                    />
                )}

                {attendance && charts && (
                    <div className="grid gap-3.5 min-[1100px]:grid-cols-[1.75fr_1fr]">
                        <Card
                            title="Kehadiran per hari"
                            note={`${rangeText}. Tidak hadir terisi setelah hari ditutup, jadi hari ini baru muncul besok.`}
                        >
                            <div className="mb-2">
                                <Legend items={Object.values(SERIES)} />
                            </div>
                            <TrendChart
                                trend={charts.trend}
                                animate={animate}
                            />
                        </Card>

                        <Card title="Komposisi hari ini">
                            <TodayDonut
                                summary={attendance}
                                animate={animate}
                            />
                        </Card>
                    </div>
                )}

                {stockCharts && (
                    <div className="grid gap-3.5 lg:grid-cols-2">
                        {stockCharts.value && stockCharts.value.length > 0 && (
                            <Card
                                title="Nilai stok per lokasi"
                                note="HPP rata-rata × stok di tangan, gudang & toko dalam wilayah Anda."
                            >
                                <StockValueChart
                                    rows={stockCharts.value}
                                    animate={animate}
                                />
                            </Card>
                        )}
                        <Card
                            title="Mutasi stok per hari"
                            note={
                                stockCharts.flow.length > 0
                                    ? `${formatCivilRange(stockCharts.flow[0].date, stockCharts.flow[stockCharts.flow.length - 1].date)}. Jumlah baris mutasi, bukan qty.`
                                    : undefined
                            }
                        >
                            <div className="mb-2">
                                <Legend items={Object.values(STOCK_SERIES)} />
                            </div>
                            <StockFlowChart
                                flow={stockCharts.flow}
                                animate={animate}
                            />
                        </Card>
                        <Card
                            title="Batch mendekati ED per bulan"
                            note={`Batch tersedia & karantina yang ED-nya dalam ${stockCharts.expiry_days} hari ke depan.`}
                            // Grafik nilai hanya untuk pemegang izin biaya: bila ada, kartu ini
                            // ketiga (ganjil) dan dibuat selebar penuh agar tidak ada sel kosong.
                            className={cn(
                                stockCharts.value &&
                                    stockCharts.value.length > 0 &&
                                    'lg:col-span-2',
                            )}
                        >
                            <ExpiryMonthChart
                                rows={stockCharts.expiry}
                                animate={animate}
                            />
                        </Card>
                    </div>
                )}

                <div className="grid gap-3.5 lg:grid-cols-2">
                    {charts && (
                        <Card
                            title="Sebaran jam masuk"
                            note={`Jumlah presensi masuk per 15 menit, ${rangeText}.${charts.check_ins_outside > 0 ? ` ${charts.check_ins_outside} presensi di jam yang jauh dari kebiasaan tidak ditampilkan.` : ''}`}
                        >
                            {hasCheckIns ? (
                                <CheckInChart
                                    checkIns={charts.check_ins}
                                    animate={animate}
                                />
                            ) : (
                                <NoData>
                                    Belum ada presensi masuk di rentang ini.
                                </NoData>
                            )}
                        </Card>
                    )}

                    {charts && charts.locations.length > 0 && (
                        <Card
                            title="Hadir per lokasi"
                            note={`Jumlah hari hadir per lokasi, ${rangeText}.`}
                        >
                            <div className="mb-2">
                                <Legend items={[SERIES.on_time, SERIES.late]} />
                            </div>
                            <LocationChart
                                locations={charts.locations}
                                animate={animate}
                            />
                        </Card>
                    )}

                    {nearExpiry && (
                        <Card
                            title="Stok mendekati ED"
                            note={
                                nearExpiry.more > 0
                                    ? `Batch tersedia per lokasi stok, kumulatif. ${nearExpiry.more} lokasi lain tidak ditampilkan.`
                                    : 'Batch tersedia per lokasi stok, kumulatif (kolom terbesar memuat yang lebih dekat).'
                            }
                        >
                            {nearExpiry.locations.length > 0 ? (
                                <NearExpiryTable data={nearExpiry} />
                            ) : (
                                <NoData>
                                    Tidak ada batch yang mendekati ED.
                                </NoData>
                            )}
                        </Card>
                    )}

                    {belowMin && (
                        <Card
                            title="Stok di bawah minimum"
                            action={
                                <Link
                                    href="/stok/minimum?filter[below_min]=1"
                                    className="text-sm font-medium text-primary hover:underline"
                                >
                                    Lihat semua
                                </Link>
                            }
                            note={
                                belowMin.total > belowMin.items.length
                                    ? `${belowMin.items.length} terbawah dari ${belowMin.total} produk per lokasi.`
                                    : 'Urut dari yang paling jauh di bawah minimum.'
                            }
                        >
                            {belowMin.items.length > 0 ? (
                                <BelowMinimumList data={belowMin} />
                            ) : (
                                <NoData>
                                    Semua produk di atas stok minimum.
                                </NoData>
                            )}
                        </Card>
                    )}

                    {levelsUnset && levelsUnset.items.length > 0 && (
                        <Card
                            title="Produk tanpa stok minimum"
                            note={`${levelsUnset.total.toLocaleString('id-ID')} produk per lokasi belum diatur. Klik lokasi untuk mengaturnya.`}
                        >
                            <LevelsUnsetList data={levelsUnset} />
                        </Card>
                    )}

                    {pending.length > 0 && (
                        <section
                            className={cn(
                                'min-w-0 rounded-xl border bg-card p-4 shadow-md',
                                pendingWide && 'lg:col-span-2',
                            )}
                        >
                            <h2 className="mb-2.5 flex items-center gap-2 text-base font-bold">
                                Perlu tindakan
                                <span className="ml-auto inline-flex items-center rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-bold text-status-warning tabular-nums">
                                    {pendingTotal}
                                </span>
                            </h2>
                            <ul
                                className={cn(
                                    'grid gap-2.5',
                                    pendingWide && 'lg:grid-cols-2',
                                )}
                            >
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
