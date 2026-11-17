import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import { ArrowLeft, ChevronDown } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import { FernMotif } from '@/components/pakis/fern-motif';
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

export type DetailHeroHighlightTone =
    | 'default'
    | 'success'
    | 'warning'
    | 'danger';

/**
 * Sorotan status di pita kepala (maks. 3): hal yang paling sering dicari sekilas (izin edar,
 * verifikasi + limit, MFA …). Jangan menggandakan sel `summary`; nilai kosong disembunyikan.
 */
export type DetailHeroHighlight = {
    icon?: LucideIcon;
    label: string;
    value: ReactNode;
    tone?: DetailHeroHighlightTone;
};

const MAX_HIGHLIGHTS = 3;

const HIGHLIGHT_TONE: Record<DetailHeroHighlightTone, string> = {
    default: 'text-primary',
    success: 'text-status-success',
    warning: 'text-status-warning',
    danger: 'text-status-danger',
};

const SUMMARY_COLS: Record<number, string> = {
    1: 'grid-cols-1',
    2: 'grid-cols-2',
    3: 'grid-cols-2 sm:grid-cols-3',
    4: 'grid-cols-2 sm:grid-cols-4',
};

/**
 * Kartu identitas di atas halaman detail (pola profil pegawai, 07 §1): pita tipis gradien warna
 * merek (ikut Pengaturan branding) bermotif daun pakis, sorotan status di kanan pita, avatar/foto,
 * judul, kode, badge status, meta berikon, slot aksi di kanan avatar, dan baris ringkasan. Dipakai semua halaman detail bertipe
 * "kepala + tab" (pegawai, pengguna, kios, produk, supplier, daftar harga) agar terasa satu aplikasi.
 * Halaman dokumen (PO, penerimaan, persetujuan …) memakai `DocumentHeader`, varian kartu ini dengan
 * ikon dokumen dan nomor monospace sebagai judul.
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
    highlights = [],
    muted = false,
    monoTitle = false,
    eyebrow,
}: {
    title: string;
    /** Judul berupa nomor dokumen: monospace, angka sejajar, boleh patah di mana saja. */
    monoTitle?: boolean;
    /** Label jenis kecil di atas judul (mis. "Purchase order"). */
    eyebrow?: ReactNode;
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
    /** Sorotan status (maks. 3). Kartu lebar: di kanan pita; kartu sempit (HP): di bawah judul. */
    highlights?: DetailHeroHighlight[];
    /** Data nonaktif: pita merek diredupkan agar statusnya terbaca sekilas. */
    muted?: boolean;
}) {
    const visibleMeta = meta.filter(
        (m) => m.value !== null && m.value !== undefined && m.value !== '',
    );
    const cells = summary.slice(0, 4);
    const marks = highlights
        .filter(
            (h) => h.value !== null && h.value !== undefined && h.value !== '',
        )
        .slice(0, MAX_HIGHLIGHTS);

    return (
        // @container: letak sorotan mengikuti lebar kartu (sidebar terbuka/tertutup), bukan layar.
        <section className="@container overflow-hidden rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_8px_24px_-12px_rgb(0_0_0/0.12)]">
            {/* Pita sengaja tipis agar tidak terasa kosong pada data tanpa foto (keluhan T-074). */}
            <div className="relative h-12 @3xl:h-14">
                <div
                    aria-hidden="true"
                    className={cn(
                        'absolute inset-0 overflow-hidden bg-gradient-to-r from-primary via-primary/85 to-primary-strong',
                        // Hanya latar yang diredupkan; sorotan di atasnya tetap terbaca.
                        muted && 'opacity-60 grayscale',
                    )}
                >
                    {/* Kilau halus agar pita tidak datar. */}
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_88%_-30%,rgb(255_255_255/0.22),transparent_50%),radial-gradient(circle_at_10%_130%,rgb(255_255_255/0.10),transparent_45%)]" />
                    <FernMotif className="absolute inset-y-0 right-0 h-full w-[min(36rem,75%)] text-white opacity-[0.09] dark:opacity-[0.12]" />
                </div>

                {marks.length > 0 && (
                    <HighlightList
                        items={marks}
                        className="relative hidden h-full items-center justify-end gap-2 pr-6 pl-36 @3xl:flex"
                        itemClassName="border-white/40 bg-card/95 shadow-sm"
                    />
                )}
            </div>

            <div className="px-4 pb-4 sm:px-6 sm:pb-5">
                {/* HP: avatar 64 px dan tombol (ikon saja) sejajar tepi bawah avatar dalam satu baris. */}
                <div className="relative z-10 -mt-6 flex items-end justify-between gap-3 sm:-mt-8 @3xl:-mt-10">
                    <HeroAvatar
                        // key: tanda gagal-muat foto ikut direset saat URL bertanda tangan berganti.
                        key={avatar.photoUrl ?? 'none'}
                        avatar={avatar}
                        title={title}
                    />

                    {actions && (
                        <div className="flex min-w-0 flex-wrap justify-end gap-2">
                            {actions}
                        </div>
                    )}
                </div>

                <div className="mt-3 space-y-2.5 sm:space-y-2">
                    {eyebrow && (
                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            {eyebrow}
                        </p>
                    )}
                    <h1
                        className={cn(
                            // HP: judul lebih kecil agar nama panjang tidak memakan banyak baris.
                            'text-lg leading-snug font-bold sm:text-[1.5rem] sm:leading-tight @3xl:text-[1.75rem]',
                            monoTitle
                                ? 'font-mono tracking-tight break-all tabular-nums'
                                : 'break-words',
                        )}
                    >
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
                    {marks.length > 0 && (
                        <HighlightList
                            items={marks}
                            className="flex flex-wrap gap-2 @3xl:hidden"
                            itemClassName="bg-muted/50"
                        />
                    )}
                    {visibleMeta.length > 0 && (
                        // HP: satu kolom agar butir panjang (alamat) tidak patah di tengah baris lain.
                        <ul className="flex flex-col gap-1.5 text-sm text-muted-foreground sm:flex-row sm:flex-wrap sm:gap-x-5 sm:pt-1">
                            {visibleMeta.map(({ icon: Icon, label, value }) => (
                                <li
                                    key={label}
                                    className="flex min-w-0 items-start gap-1.5"
                                >
                                    <Icon
                                        className="mt-0.5 size-4 shrink-0"
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
                                // Tiga sel di HP: sel terakhir selebar kartu, bukan sel yatim setengah.
                                cells.length === 3 &&
                                    i === 2 &&
                                    'col-span-2 sm:col-span-1',
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
    // URL foto bertanda tangan bisa kedaluwarsa/ditolak: jatuh ke ikon/inisial, bukan gambar rusak.
    // Yang disimpan hanya tanda gagal; URL-nya sendiri tidak pernah ditaruh di state.
    const [failed, setFailed] = useState(false);

    if (avatar.photoUrl && !failed) {
        return (
            <img
                src={avatar.photoUrl}
                alt={avatar.photoAlt ?? `Foto ${title}`}
                onError={() => setFailed(true)}
                className={cn(
                    'size-16 shrink-0 bg-card object-cover ring-4 ring-card sm:size-20 @3xl:size-24',
                    shape,
                )}
            />
        );
    }

    return (
        <span
            aria-hidden="true"
            className={cn(
                'grid size-16 shrink-0 place-items-center bg-sidebar-accent text-xl font-bold text-primary ring-4 ring-card sm:size-20 sm:text-2xl @3xl:size-24 @3xl:text-3xl',
                shape,
            )}
        >
            {Icon ? (
                <Icon
                    className="size-7 sm:size-9 @3xl:size-10"
                    strokeWidth={1.75}
                />
            ) : (
                avatar.initials
            )}
        </span>
    );
}

function HighlightList({
    items,
    className,
    itemClassName,
}: {
    items: DetailHeroHighlight[];
    className: string;
    itemClassName: string;
}) {
    return (
        <ul aria-label="Sorotan" className={className}>
            {items.map(({ icon: Icon, label, value, tone = 'default' }) => (
                <li
                    key={label}
                    data-tone={tone}
                    className={cn(
                        'inline-flex h-7 max-w-full min-w-0 items-center gap-1.5 rounded-full border px-2.5 text-xs text-card-foreground @3xl:max-w-[20rem]',
                        itemClassName,
                    )}
                >
                    {Icon && (
                        <Icon
                            className={cn(
                                'size-3.5 shrink-0',
                                HIGHLIGHT_TONE[tone],
                            )}
                            aria-hidden="true"
                        />
                    )}
                    <span className="shrink-0 text-muted-foreground">
                        {label}
                        <span className="sr-only">: </span>
                    </span>
                    <span
                        className={cn(
                            'truncate font-semibold',
                            tone !== 'default' && HIGHLIGHT_TONE[tone],
                        )}
                    >
                        {value}
                    </span>
                </li>
            ))}
        </ul>
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
    /** Aksi di halaman (membuka dialog). Abaikan bila memakai `href`. */
    onSelect?: () => void;
    /** Butir berupa tautan sungguhan (klik tengah/salin tautan tetap jalan), mis. Cetak PDF. */
    href?: string;
    /** Buka `href` di tab baru (berkas cetak), bukan navigasi Inertia. */
    newTab?: boolean;
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
                            {item.href ? (
                                <DropdownMenuItem
                                    variant={
                                        item.destructive
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    asChild
                                >
                                    {item.newTab ? (
                                        <a
                                            href={item.href}
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            <Icon aria-hidden="true" />
                                            {item.label}
                                        </a>
                                    ) : (
                                        <Link href={item.href}>
                                            <Icon aria-hidden="true" />
                                            {item.label}
                                        </Link>
                                    )}
                                </DropdownMenuItem>
                            ) : (
                                <DropdownMenuItem
                                    variant={
                                        item.destructive
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    onSelect={item.onSelect}
                                >
                                    <Icon aria-hidden="true" />
                                    {item.label}
                                </DropdownMenuItem>
                            )}
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
