import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type DocumentLineColumn<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    /** Kolom angka: rata kanan + tabular. */
    numeric?: boolean;
    /** Ditampilkan sebagai judul kartu di HP (satu kolom). */
    primary?: boolean;
    /** Nilai utama baris (mis. Jumlah): tebal di tabel, baris penutup kartu di HP. */
    emphasis?: boolean;
    /** Sembunyikan di kartu HP bila sudah terwakili kolom lain. */
    hideOnMobile?: boolean;
};

export type DocumentTotal = {
    label: string;
    value: ReactNode;
    /** Baris total utama (tebal, garis atas). */
    emphasis?: boolean;
};

/**
 * Tabel baris dokumen (15 §7.1): tabel di layar lebar, kartu per baris di HP tanpa gulir mendatar
 * (07 §3.2), plus kotak ringkasan total di kanan bawah. Generik untuk PO, penerimaan, mutasi, retur:
 * kolom dan baris total diatur halaman. Hanya tampilan; penyuntingan baris ada di halaman form.
 */
export function DocumentLines<T>({
    rows,
    rowKey,
    columns,
    totals,
    empty = 'Belum ada baris.',
    caption,
    footer,
}: {
    rows: T[];
    rowKey: (row: T) => string | number;
    columns: DocumentLineColumn<T>[];
    totals?: DocumentTotal[];
    empty?: string;
    caption?: string;
    /** Catatan di kiri kotak total (mis. status penerimaan). */
    footer?: ReactNode;
}) {
    const primary = columns.find((c) => c.primary) ?? columns[0];
    const rest = columns.filter((c) => c !== primary && !c.hideOnMobile);
    const details = rest.filter((c) => !c.emphasis);
    const closing = rest.filter((c) => c.emphasis);

    return (
        <div className="space-y-4">
            {rows.length === 0 ? (
                <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-lg border md:block">
                        <table className="w-full text-sm">
                            {caption && (
                                <caption className="sr-only">{caption}</caption>
                            )}
                            <thead className="border-b bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    {columns.map((c) => (
                                        <th
                                            key={c.key}
                                            scope="col"
                                            className={cn(
                                                'px-4 py-2.5 font-medium whitespace-nowrap',
                                                c.numeric && 'text-right',
                                            )}
                                        >
                                            {c.header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-border/70">
                                {rows.map((row) => (
                                    <tr
                                        key={rowKey(row)}
                                        className="transition-colors even:bg-muted/25 hover:bg-muted/40"
                                    >
                                        {columns.map((c) => (
                                            <td
                                                key={c.key}
                                                className={cn(
                                                    'px-4 py-3 align-top',
                                                    c.numeric &&
                                                        'text-right whitespace-nowrap tabular-nums',
                                                    c.emphasis &&
                                                        'font-semibold',
                                                )}
                                            >
                                                {c.cell(row)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <ul className="grid gap-2.5 md:hidden" aria-label={caption}>
                        {rows.map((row) => (
                            <li
                                key={rowKey(row)}
                                className="rounded-lg border bg-card p-3"
                            >
                                <div className="min-w-0 text-sm">
                                    {primary.cell(row)}
                                </div>
                                {details.length > 0 && (
                                    <dl className="mt-2.5 grid gap-1.5 border-t border-dashed pt-2.5 text-sm">
                                        {details.map((c) => (
                                            <div
                                                key={c.key}
                                                className="flex items-baseline justify-between gap-3"
                                            >
                                                <dt className="shrink-0 text-xs text-muted-foreground">
                                                    {c.header}
                                                </dt>
                                                <dd
                                                    className={cn(
                                                        'min-w-0 text-right',
                                                        c.numeric &&
                                                            'tabular-nums',
                                                    )}
                                                >
                                                    {c.cell(row)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                                {closing.length > 0 && (
                                    <dl className="mt-2.5 grid gap-1 border-t pt-2.5">
                                        {closing.map((c) => (
                                            <div
                                                key={c.key}
                                                className="flex items-baseline justify-between gap-3"
                                            >
                                                <dt className="text-xs text-muted-foreground">
                                                    {c.header}
                                                </dt>
                                                <dd className="min-w-0 text-right text-sm font-semibold tabular-nums">
                                                    {c.cell(row)}
                                                </dd>
                                            </div>
                                        ))}
                                    </dl>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}
            {((totals && totals.length > 0) || footer) && (
                <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div className="min-w-0 flex-1 text-sm text-muted-foreground">
                        {footer}
                    </div>
                    {totals && totals.length > 0 && (
                        <dl
                            aria-label="Ringkasan total"
                            className="grid w-full gap-2 rounded-lg border bg-muted/30 p-4 text-sm md:max-w-sm"
                        >
                            {totals.map((t) => (
                                <div
                                    key={t.label}
                                    className={cn(
                                        'flex items-baseline justify-between gap-6',
                                        // Garis utuh selebar kotak di atas Total, bukan per sel.
                                        t.emphasis && 'mt-1 border-t pt-3',
                                    )}
                                >
                                    <dt
                                        className={cn(
                                            'text-muted-foreground',
                                            t.emphasis &&
                                                'font-semibold text-foreground',
                                        )}
                                    >
                                        {t.label}
                                    </dt>
                                    <dd
                                        className={cn(
                                            'text-right tabular-nums',
                                            t.emphasis &&
                                                'text-lg font-bold text-primary',
                                        )}
                                    >
                                        {t.value}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </div>
            )}
        </div>
    );
}
