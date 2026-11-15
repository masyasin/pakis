import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * Bagian tampilan detail pegawai (07 §1: radius 12 px, bayangan lembut, kontras AA terang/gelap).
 * Dipakai semua sub-tab agar setiap modul (termasuk modul Fase HR nanti) tampil seragam.
 */

/** Kartu bersection dengan judul berikon dan aksi opsional di kanan judul. */
export function DetailCard({
    title,
    icon: Icon,
    action,
    className,
    children,
}: {
    title: string;
    icon: LucideIcon;
    action?: ReactNode;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_4px_12px_-6px_rgb(0_0_0/0.08)]',
                className,
            )}
        >
            <header className="flex items-center justify-between gap-3 border-b px-4 py-3 sm:px-5">
                <h3 className="flex items-center gap-2.5 text-sm font-semibold">
                    <span className="grid size-7 place-items-center rounded-lg bg-sidebar-accent text-primary">
                        <Icon className="size-4" aria-hidden="true" />
                    </span>
                    {title}
                </h3>
                {action}
            </header>
            <div className="p-4 sm:p-5">{children}</div>
        </section>
    );
}

/** Grid label–nilai: label kecil di atas nilai, dua kolom di layar lebar. */
export function FieldGrid({
    children,
    columns = 2,
}: {
    children: ReactNode;
    columns?: 1 | 2 | 3;
}) {
    return (
        <dl
            className={cn(
                'grid gap-x-6 gap-y-4',
                columns === 2 && 'sm:grid-cols-2',
                columns === 3 && 'sm:grid-cols-2 lg:grid-cols-3',
            )}
        >
            {children}
        </dl>
    );
}

/** Satu isian. Nilai kosong ditulis "Belum diisi" agar jelas bedanya dengan nilai "–" sungguhan. */
export function Field({
    label,
    value,
    hint,
    mono = false,
    wide = false,
}: {
    label: string;
    value: ReactNode | null | undefined;
    hint?: ReactNode;
    mono?: boolean;
    wide?: boolean;
}) {
    const empty = value === null || value === undefined || value === '';

    return (
        <div className={cn('min-w-0', wide && 'sm:col-span-full')}>
            <dt className="text-xs font-medium tracking-wide text-muted-foreground">
                {label}
            </dt>
            <dd
                className={cn(
                    'mt-1 text-sm break-words',
                    mono && !empty && 'font-mono tabular-nums',
                    empty && 'text-muted-foreground italic',
                )}
            >
                {empty ? 'Belum diisi' : value}
                {!empty && hint && (
                    <span className="ml-1.5 text-xs text-muted-foreground not-italic">
                        {hint}
                    </span>
                )}
            </dd>
        </div>
    );
}

/** Keadaan kosong berikon untuk modul yang belum aktif / belum ada data. */
export function EmptyPanel({
    icon: Icon,
    title,
    text,
    action,
}: {
    icon: LucideIcon;
    title: string;
    text: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed bg-card/60 px-6 py-10 text-center">
            <span className="grid size-12 place-items-center rounded-full bg-sidebar-accent text-primary">
                <Icon className="size-6" aria-hidden="true" />
            </span>
            <div className="space-y-1">
                <p className="text-sm font-semibold">{title}</p>
                <p className="mx-auto max-w-md text-sm text-muted-foreground">
                    {text}
                </p>
            </div>
            {action}
        </div>
    );
}

/** Garis waktu vertikal (riwayat penugasan, riwayat perubahan). */
export function Timeline({ children }: { children: ReactNode }) {
    return <ol className="relative space-y-1">{children}</ol>;
}

export function TimelineItem({
    active = false,
    last = false,
    children,
}: {
    active?: boolean;
    last?: boolean;
    children: ReactNode;
}) {
    return (
        <li className="relative flex gap-4 pb-4">
            <div className="relative flex w-4 shrink-0 justify-center">
                <span
                    aria-hidden="true"
                    className={cn(
                        'relative z-10 mt-1.5 size-3 rounded-full border-2',
                        active
                            ? 'border-primary bg-primary ring-4 ring-primary/15'
                            : 'border-muted-foreground/40 bg-card',
                    )}
                />
                {!last && (
                    <span
                        aria-hidden="true"
                        className="absolute top-5 bottom-0 w-px bg-border"
                    />
                )}
            </div>
            <div
                className={cn(
                    'min-w-0 flex-1 rounded-xl border bg-card p-4 text-sm',
                    active && 'border-primary/40 bg-sidebar-accent/40',
                )}
            >
                {children}
            </div>
        </li>
    );
}
