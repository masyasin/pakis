import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, TriangleAlert } from 'lucide-react';
import type { KeyboardEvent, ReactNode } from 'react';
import { ActiveFilterChips } from '@/components/pakis/active-filter-chips';
import { EmptyState } from '@/components/pakis/empty-state';
import { FilterBar } from '@/components/pakis/filter-bar';
import { Pagination } from '@/components/pakis/pagination';
import { PerPageSelect } from '@/components/pakis/per-page-select';
import { TableSkeleton } from '@/components/pakis/table-skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useListing } from '@/hooks/use-listing';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    defaultFilters,
    filterChips,
    formatNumber,
    isEmptyValue,
} from '@/lib/listing-query';
import { cn } from '@/lib/utils';
import type { Listing } from '@/types/listing';

export type DataTableColumn<Row> = {
    key: string;
    header: string;
    /** Key sort di spec. Header bisa diklik hanya bila key ini ada di `spec.sorts`. */
    sortKey?: string;
    cell: (row: Row) => ReactNode;
    className?: string;
    /** Kolom utama: judul kartu di tampilan HP. */
    primary?: boolean;
};

type Props<Row> = {
    rows: Row[];
    listing: Listing;
    columns: DataTableColumn<Row>[];
    /** Nama daftar untuk pembaca layar, mis. "Daftar pegawai". */
    title: string;
    rowKey?: (row: Row) => string | number;
    rowHref?: (row: Row) => string;
    rowActions?: (row: Row) => ReactNode;
    /** Tombol di empty state "Belum ada data" (mis. "Tambah Pegawai", bila berhak). */
    emptyAction?: ReactNode;
    toolbarActions?: ReactNode;
};

function sameFilters(
    a: Record<string, unknown>,
    b: Record<string, unknown>,
): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return [...keys].every(
        (k) =>
            (isEmptyValue(a[k]) && isEmptyValue(b[k])) ||
            JSON.stringify(a[k]) === JSON.stringify(b[k]),
    );
}

/**
 * Daftar standar PAKIS ONE (07 §3): paging server-side, cari, filter, sort, chip, dua jenis empty
 * state, skeleton, tampilan kartu < 768 px. Semua state tersinkron ke URL lewat useListing.
 */
export function DataTable<Row>({
    rows,
    listing,
    columns,
    title,
    rowKey = (row) => (row as { id: number }).id,
    rowHref,
    rowActions,
    emptyAction,
    toolbarActions,
}: Props<Row>) {
    const { spec, state, pagination } = listing;
    const controls = useListing(listing);
    const isMobile = useIsMobile();

    const sortableKeys = new Set(spec.sorts.map((s) => s.key));
    const chips = filterChips(spec, state.filters);
    const isFiltered =
        state.q !== '' || !sameFilters(state.filters, defaultFilters(spec));
    const canReset = isFiltered || state.sort !== spec.default_sort;
    const invalid = Object.entries(state.invalid_filters);

    const open = (row: Row) => rowHref && router.visit(rowHref(row));
    const onRowKey = (e: KeyboardEvent, row: Row) => {
        if (rowHref && e.key === 'Enter') {
            open(row);
        }
    };

    const summary =
        pagination.total === 0
            ? 'Tidak ada data'
            : `Menampilkan ${formatNumber(pagination.from ?? 0)}–${formatNumber(pagination.to ?? 0)} dari ${formatNumber(pagination.total)}`;

    const body = (() => {
        if (rows.length === 0 && controls.isLoading) {
            return <TableSkeleton columns={Math.min(columns.length, 5)} />;
        }

        if (rows.length === 0) {
            return isFiltered ? (
                <EmptyState variant="no-results" onReset={controls.reset} />
            ) : (
                <EmptyState variant="no-data" action={emptyAction} />
            );
        }

        if (isMobile) {
            const primary = columns.find((c) => c.primary) ?? columns[0];
            const rest = columns.filter((c) => c !== primary);

            return (
                <ul
                    className="divide-y"
                    aria-label={title}
                    data-testid="card-list"
                >
                    {rows.map((row) => (
                        <li
                            key={rowKey(row)}
                            className={cn(
                                'space-y-2 p-4',
                                rowHref && 'cursor-pointer active:bg-muted/50',
                            )}
                            tabIndex={rowHref ? 0 : undefined}
                            onClick={() => open(row)}
                            onKeyDown={(e) => onRowKey(e, row)}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="font-medium">
                                    {primary.cell(row)}
                                </div>
                                {rowActions && (
                                    <div onClick={(e) => e.stopPropagation()}>
                                        {rowActions(row)}
                                    </div>
                                )}
                            </div>
                            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                                {rest.map((c) => (
                                    <div key={c.key} className="contents">
                                        <dt className="text-muted-foreground">
                                            {c.header}
                                        </dt>
                                        <dd>{c.cell(row)}</dd>
                                    </div>
                                ))}
                            </dl>
                        </li>
                    ))}
                </ul>
            );
        }

        return (
            <Table aria-label={title}>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        {columns.map((column) => {
                            const sortable =
                                column.sortKey !== undefined &&
                                sortableKeys.has(column.sortKey);
                            const active =
                                sortable &&
                                state.sort.replace(/^-/, '') === column.sortKey;
                            const desc = active && state.sort.startsWith('-');

                            return (
                                <TableHead
                                    key={column.key}
                                    className={column.className}
                                    aria-sort={
                                        sortable
                                            ? active
                                                ? desc
                                                    ? 'descending'
                                                    : 'ascending'
                                                : 'none'
                                            : undefined
                                    }
                                >
                                    {sortable ? (
                                        <button
                                            type="button"
                                            className="-ml-1 inline-flex items-center gap-1 rounded px-1 py-0.5 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            onClick={() =>
                                                controls.toggleSort(
                                                    column.sortKey!,
                                                )
                                            }
                                        >
                                            {column.header}
                                            {active ? (
                                                desc ? (
                                                    <ArrowDown
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                ) : (
                                                    <ArrowUp
                                                        className="size-3.5"
                                                        aria-hidden
                                                    />
                                                )
                                            ) : (
                                                <ArrowUpDown
                                                    className="size-3.5 opacity-40"
                                                    aria-hidden
                                                />
                                            )}
                                        </button>
                                    ) : (
                                        column.header
                                    )}
                                </TableHead>
                            );
                        })}
                        {rowActions && (
                            <TableHead className="w-12">
                                <span className="sr-only">Aksi</span>
                            </TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => (
                        <TableRow
                            key={rowKey(row)}
                            className={cn(rowHref && 'cursor-pointer')}
                            tabIndex={rowHref ? 0 : undefined}
                            onClick={() => open(row)}
                            onKeyDown={(e) => onRowKey(e, row)}
                        >
                            {columns.map((column) => (
                                <TableCell
                                    key={column.key}
                                    className={column.className}
                                >
                                    {column.cell(row)}
                                </TableCell>
                            ))}
                            {rowActions && (
                                <TableCell onClick={(e) => e.stopPropagation()}>
                                    {rowActions(row)}
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        );
    })();

    return (
        <section className="space-y-3" aria-label={title}>
            <FilterBar
                spec={spec}
                state={state}
                listing={controls}
                canReset={canReset}
                actions={toolbarActions}
            />

            <ActiveFilterChips chips={chips} onRemove={controls.removeFilter} />

            {invalid.length > 0 && (
                <p
                    role="status"
                    className="flex items-start gap-2 rounded-md bg-status-warning-bg px-3 py-2 text-xs text-status-warning"
                >
                    <TriangleAlert
                        className="mt-0.5 size-3.5 shrink-0"
                        aria-hidden
                    />
                    <span>
                        Sebagian filter diabaikan:{' '}
                        {invalid
                            .map(([key, message]) => {
                                const label =
                                    spec.filters.find((f) => f.key === key)
                                        ?.label ?? key;

                                return `${label} (${message})`;
                            })
                            .join('; ')}
                    </span>
                </p>
            )}

            <div
                className={cn(
                    'overflow-hidden rounded-xl border bg-card transition-opacity',
                    controls.isLoading &&
                        rows.length > 0 &&
                        'pointer-events-none opacity-60',
                )}
                aria-busy={controls.isLoading}
            >
                {body}
            </div>

            <div className="flex flex-col-reverse items-center justify-between gap-3 text-sm sm:flex-row">
                <p
                    className="text-muted-foreground"
                    aria-live="polite"
                    data-testid="listing-summary"
                >
                    {summary}
                </p>
                <div className="flex items-center gap-3">
                    <PerPageSelect
                        value={pagination.per_page}
                        options={spec.per_page_options}
                        onChange={controls.setPerPage}
                    />
                    <Pagination
                        page={pagination.page}
                        lastPage={pagination.last_page}
                        onChange={controls.setPage}
                        disabled={controls.isLoading}
                    />
                </div>
            </div>
        </section>
    );
}
