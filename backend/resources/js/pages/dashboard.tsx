import { Head, usePage } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { dashboard } from '@/routes';

/*
 * Kerangka dasbor (07 §4). Angka & daftar diisi saat modul absensi tersedia
 * (Fase 0,5). Sengaja menampilkan "—" dan bukan angka contoh agar tidak
 * dikira data nyata.
 */
const TILES = [
    { label: 'Hadir', dot: 'bg-status-success' },
    { label: 'Telat', dot: 'bg-status-warning' },
    { label: 'Belum absen', dot: 'bg-status-muted' },
    { label: 'Libur / off', dot: 'bg-status-info' },
] as const;

export default function Dashboard() {
    const { auth } = usePage().props;
    const firstName = auth.user?.name.split(' ')[0] ?? '';

    return (
        <>
            <Head title="Dasbor" />

            <div className="flex flex-1 flex-col gap-8 px-4 py-6 md:px-6 md:py-8">
                <header className="space-y-1.5">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Selamat datang, {firstName}
                    </h1>
                    <p className="max-w-prose text-[15px] text-muted-foreground">
                        Ringkasan kehadiran hari ini akan tampil di sini setelah
                        modul absensi aktif.
                    </p>
                </header>

                <section aria-label="Ringkasan kehadiran hari ini">
                    <dl className="grid grid-cols-2 gap-3 md:gap-4 xl:grid-cols-4">
                        {TILES.map((tile) => (
                            <div
                                key={tile.label}
                                className="rounded-xl border bg-card p-4 shadow-xs md:p-5"
                            >
                                <dt className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <span
                                        aria-hidden="true"
                                        className={`size-2 rounded-full ${tile.dot}`}
                                    />
                                    {tile.label}
                                </dt>
                                <dd className="mt-3 text-3xl font-semibold tracking-tight text-muted-foreground tabular-nums">
                                    <span aria-hidden="true">—</span>
                                    <span className="sr-only">
                                        Belum ada data
                                    </span>
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>

                <section className="rounded-xl border bg-card shadow-xs">
                    <div className="border-b px-5 py-4">
                        <h2 className="font-semibold">Perlu tindakan</h2>
                    </div>
                    <div className="flex flex-col items-center gap-3 px-6 py-14 text-center">
                        <span className="flex size-12 items-center justify-center rounded-full bg-sidebar-accent text-sidebar-accent-foreground">
                            <ClipboardCheck
                                className="size-6"
                                aria-hidden="true"
                            />
                        </span>
                        <div className="space-y-1">
                            <p className="font-medium">
                                Belum ada yang menunggu
                            </p>
                            <p className="max-w-sm text-sm text-muted-foreground">
                                Koreksi absensi, perangkat baru, dan pegawai
                                tanpa jadwal akan muncul di sini.
                            </p>
                        </div>
                    </div>
                </section>
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
