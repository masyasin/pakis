import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft, ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';

/** Satu sel ringkasan di dasar kartu (maks. 4 sel; 2 kolom di HP). */
export type DetailHeroSummary = { label: string; value: ReactNode }[];

/** Satu butir meta berikon di bawah judul (jabatan, area, prinsipal …). Nilai kosong disembunyikan. */
export type DetailHeroMeta = {
    icon: LucideIcon;
    /** Label untuk pembaca layar; tidak tampil. */
    label: string;
    value: ReactNode;
};

export type DetailHeroAvatar = {
    /** Foto (URL); bila null dipakai `icon`, lalu `initials`. */
    photoUrl?: string | null;
    photoAlt?: string;
    icon?: LucideIcon;
    initials?: string;
    /** Lingkaran untuk orang/kios, kotak membulat untuk barang (foto produk tidak terpotong bundar). */
    shape?: 'circle' | 'square';
};

const SUMMARY_COLS: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-4',
};

/**
 * Kartu identitas di atas halaman detail (pola profil pegawai, 07 §1): pita gradien warna merek
 * (token `brand-panel-*`, ikut Pengaturan branding), avatar/ikon, judul, kode, badge status, meta
 * berikon, slot aksi di kanan avatar, dan baris ringkasan. Dipakai semua halaman detail bertipe
 * "kepala + tab" (pegawai, pengguna, kios, produk, supplier, daftar harga) agar terasa satu aplikasi.
 * Halaman dokumen bergaris (PO, penerimaan) memakai `DocumentHeader`, bukan kartu ini.
 */
export function DetailHero({
    title,
    code,
    badges,
    avatar,
    meta = [],
    actions,
    notice,
    summary = [],
    muted = false,
}: {
    title: string;
    /** Nomor/kode/username, tampil sebagai chip monospace. */
    code?: string | null;
    /** `StatusBadge` dkk. di samping kode. */
    badges?: ReactNode;
    avatar: DetailHeroAvatar;
    meta?: DetailHeroMeta[];
    /** Tombol aksi (size="sm"); pakai `MoreActionsMenu` untuk aksi sekunder. */
    actions?: ReactNode;
    /** Pita info di bawah identitas (mis. "Nonaktif sejak …"). */
    notice?: ReactNode;
    summary?: DetailHeroSummary;
    /** Data nonaktif: pita merek diredupkan agar statusnya terbaca sekilas. */
    muted?: boolean;
}) {
    const visibleMeta = meta.filter(
        (m) => m.value !== null && m.value !== undefined && m.value !== '',
    );
    const cells = summary.slice(0, 4);

    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_8px_24px_-12px_rgb(0_0_0/0.12)]">
            <div
                aria-hidden="true"
                className={cn(
                    'relative h-20 bg-gradient-to-r from-primary via-primary/85 to-primary-strong sm:h-24',
                    muted && 'opacity-60 grayscale',
                )}
            >
                {/* Kilau halus agar pita tidak datar. */}
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_88%_-30%,rgb(255_255_255/0.22),transparent_50%),radial-gradient(circle_at_10%_130%,rgb(255_255_255/0.10),transparent_45%)]" />
            </div>

            <div className="px-4 pb-5 sm:px-6">
                <div className="relative z-10 -mt-10 flex flex-wrap items-end justify-between gap-3 sm:-mt-12">
                    <HeroAvatar avatar={avatar} title={title} />

                    {actions && (
                        <div className="flex flex-wrap justify-end gap-2">
                            {actions}
                        </div>
                    )}
                </div>

                <div className="mt-3 space-y-2">
                    <h1 className="text-[1.5rem] leading-tight font-bold break-words sm:text-[1.75rem]">
                        {title}
                    </h1>
                    {(code || badges) && (
                        <div className="flex flex-wrap items-center gap-2">
                            {code && (
                                <span className="rounded-md border bg-muted px-2 py-0.5 font-mono text-xs tabular-nums">
                                    {code}
                                </span>
                            )}
                            {badges}
                        </div>
                    )}
                    {visibleMeta.length > 0 && (
                        <ul className="flex flex-wrap gap-x-5 gap-y-1.5 pt-1 text-sm text-muted-foreground">
                            {visibleMeta.map(({ icon: Icon, label, value }) => (
                                <li
                                    key={label}
                                    className="inline-flex items-center gap-1.5"
                                >
                                    <Icon
                                        className="size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="sr-only">{label}: </span>
                                    <span className="text-foreground/80">
                                        {value}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {notice}
                </div>
            </div>

            {cells.length > 0 && (
                <dl className={cn('grid border-t', SUMMARY_COLS[cells.length])}>
                    {cells.map((s, i) => (
                        <div
                            key={s.label}
                            className={cn(
                                'min-w-0 px-4 py-3 sm:px-6',
                                // HP 2 kolom: garis tengah di kolom kanan, garis atas di baris kedua.
                                // Layar lebar satu baris: garis kiri di setiap sel kecuali pertama.
                                i % 2 === 1 && 'border-l',
                                i >= 2 && 'border-t sm:border-t-0',
                                i > 0 && i % 2 === 0 && 'sm:border-l',
                            )}
                        >
                            <dt className="text-xs text-muted-foreground">
                                {s.label}
                            </dt>
                            <dd className="mt-0.5 truncate text-sm font-semibold">
                                {s.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </section>
    );
}

function HeroAvatar({
    avatar,
    title,
}: {
    avatar: DetailHeroAvatar;
    title: string;
}) {
    const shape = avatar.shape === 'square' ? 'rounded-2xl' : 'rounded-full';
    const Icon = avatar.icon;

    if (avatar.photoUrl) {
        return (
            <img
                src={avatar.photoUrl}
                alt={avatar.photoAlt ?? `Foto ${title}`}
                className={cn(
                    'size-20 bg-card object-cover ring-4 ring-card sm:size-24',
                    shape,
                )}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'grid size-20 place-items-center bg-sidebar-accent text-2xl font-bold text-primary ring-4 ring-card sm:size-24 sm:text-3xl',
                shape,
            )}
        >
            {Icon ? (
                <Icon className="size-9 sm:size-10" strokeWidth={1.75} />
            ) : (
                avatar.initials
            )}
        </span>
    );
}

/** Tautan kembali ke daftar di atas kartu detail (sama di semua halaman detail). */
export function DetailBackLink({
    href,
    label,
}: {
    href: NonNullable<InertiaLinkProps['href']>;
    label: string;
}) {
    return (
        <Button variant="ghost" size="sm" className="-ml-2" asChild>
            <Link href={href}>
                <ArrowLeft aria-hidden="true" />
                {label}
            </Link>
        </Button>
    );
}

export type DetailMenuAction = {
    key: string;
    label: string;
    icon: LucideIcon;
    onSelect: () => void;
    destructive?: boolean;
    /** Garis pemisah sebelum butir ini (memisah aksi data dari aksi status). */
    separatorBefore?: boolean;
};

/**
 * Menu "Aksi lain" untuk aksi sekunder di kartu detail, agar baris tombol tetap ringkas (maks.
 * dua-tiga tombol langsung). Tidak dirender bila tidak ada butir.
 */
export function MoreActionsMenu({
    items,
    label = 'Aksi lain',
}: {
    items: DetailMenuAction[];
    label?: string;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        // modal={false}: menu tertutup tanpa mengunci halaman sebelum dialog aksi terbuka.
        <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    {label}
                    <ChevronDown aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {items.map((item, i) => {
                    const Icon = item.icon;

                    return (
                        <Fragment key={item.key}>
                            {item.separatorBefore && i > 0 && (
                                <DropdownMenuSeparator />
                            )}
                            <DropdownMenuItem
                                variant={
                                    item.destructive ? 'destructive' : 'default'
                                }
                                onSelect={item.onSelect}
                            >
                                <Icon aria-hidden="true" />
                                {item.label}
                            </DropdownMenuItem>
                        </Fragment>
                    );
                })}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * Label tombol aksi di kartu detail: di HP hanya ikon (tombol tetap punya `aria-label`), di layar
 * lebar ikon + teks. Sama dengan tombol Ubah/Hapus di profil pegawai.
 */
export function ActionLabel({ children }: { children: ReactNode }) {
    return <span className="hidden sm:inline">{children}</span>;
}
