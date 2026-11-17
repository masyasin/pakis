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
};

export type DocumentTotal = {
    label: string;
    value: ReactNode;
    /** Baris total utama (tebal, garis atas). */
    emphasis?: boolean;
};

/**
 * Tabel baris "dokumen bergaris" (15 §7.1): tabel di layar lebar, kartu per baris di HP (07 §3.2),
 * plus ringkasan total di bawah. Hanya tampilan; penyuntingan baris dilakukan di halaman form.
 */
export function DocumentLines<T>({
    rows,
    rowKey,
    columns,
    totals,
    empty = 'Belum ada baris.',
    caption,
}: {
    rows: T[];
    rowKey: (row: T) => string | number;
    columns: DocumentLineColumn<T>[];
    totals?: DocumentTotal[];
    empty?: string;
    caption?: string;
}) {
    const primary = columns.find((c) => c.primary) ?? columns[0];
    const rest = columns.filter((c) => c !== primary);

    return (
        <div className="space-y-3">
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
                            <thead className="bg-muted/50 text-left text-xs text-muted-foreground">
                                <tr>
                                    {columns.map((c) => (
                                        <th
                                            key={c.key}
                                            scope="col"
                                            className={cn(
                                                'px-3 py-2 font-medium whitespace-nowrap',
                                                c.numeric && 'text-right',
                                            )}
                                        >
                                            {c.header}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {rows.map((row) => (
                                    <tr key={rowKey(row)}>
                                        {columns.map((c) => (
                                            <td
                                                key={c.key}
                                                className={cn(
                                                    'px-3 py-2 align-top',
                                                    c.numeric &&
                                                        'text-right whitespace-nowrap tabular-nums',
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
                    <ul className="divide-y rounded-lg border md:hidden">
                        {rows.map((row) => (
                            <li key={rowKey(row)} className="space-y-2 p-3">
                                <div className="font-medium">
                                    {primary.cell(row)}
                                </div>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                    {rest.map((c) => (
                                        <div key={c.key} className="contents">
                                            <dt className="text-muted-foreground">
                                                {c.header}
                                            </dt>
                                            <dd
                                                className={cn(
                                                    'min-w-0 text-right',
                                                    c.numeric && 'tabular-nums',
                                                )}
                                            >
                                                {c.cell(row)}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </li>
                        ))}
                    </ul>
                </>
            )}
            {totals && totals.length > 0 && (
                <dl className="ml-auto grid w-full max-w-sm grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 text-sm">
                    {totals.map((t) => (
                        <div key={t.label} className="contents">
                            <dt
                                className={cn(
                                    'text-muted-foreground',
                                    t.emphasis &&
                                        'border-t pt-1.5 font-semibold text-foreground',
                                )}
                            >
                                {t.label}
                            </dt>
                            <dd
                                className={cn(
                                    'text-right tabular-nums',
                                    t.emphasis &&
                                        'border-t pt-1.5 text-base font-bold',
                                )}
                            >
                                {t.value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}
