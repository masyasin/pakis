import { Head, Link, usePage } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type StockMovementRow = {
    id: number;
    moved_at: string;
    business_date: string;
    sku: string;
    name: string;
    location: string;
    batch_no: string;
    type: string;
    type_label: string;
    qty: string;
    qty_after: string;
    document: { number: string; url: string | null } | null;
    user: string | null;
    /** Hanya ada bila `stock.view_cost` (SEC-107). */
    unit_cost?: string | null;
};

type Props = ListingProps<StockMovementRow> & {
    canExport: boolean;
    canViewCost: boolean;
};

/**
 * Kartu stok (15 §7.3 `stock_movements`, FR-A30): semua mutasi batch dalam scope Stok, rentang
 * tanggal wajib (bawaan bulan berjalan). Tautan ke dokumen sumber; biaya satuan hanya untuk
 * `stock.view_cost` (SEC-107).
 */
export default function StockMovements({
    rows,
    listing,
    canExport,
    canViewCost,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const columns: DataTableColumn<StockMovementRow>[] = [
        {
            key: 'moved_at',
            header: 'Waktu',
            sortKey: 'moved_at',
            cell: (r) => formatDateTimeIn(r.moved_at, timezone),
        },
        {
            key: 'product',
            header: 'Produk · batch',
            primary: true,
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-medium break-words">{r.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku} · {r.batch_no}
                    </span>
                </span>
            ),
        },
        { key: 'location', header: 'Lokasi', cell: (r) => r.location },
        {
            key: 'type',
            header: 'Jenis · dokumen',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span>{r.type_label}</span>
                    {r.document &&
                        (r.document.url ? (
                            <Link
                                href={r.document.url}
                                className="font-mono text-xs text-primary hover:underline"
                            >
                                {r.document.number}
                            </Link>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                {r.document.number}
                            </span>
                        ))}
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'Qty',
            className: 'text-right tabular-nums',
            cell: (r) => (
                <span
                    className={
                        r.qty.startsWith('-') ? 'text-destructive' : undefined
                    }
                >
                    {r.qty.startsWith('-') ? '' : '+'}
                    {formatQty(r.qty)}
                </span>
            ),
        },
        {
            key: 'qty_after',
            header: 'Saldo',
            className: 'text-right tabular-nums',
            cell: (r) => formatQty(r.qty_after),
        },
        ...(canViewCost
            ? [
                  {
                      key: 'unit_cost',
                      header: 'Biaya satuan',
                      className: 'text-right tabular-nums',
                      cell: (r: StockMovementRow) =>
                          r.unit_cost ? formatRupiah(r.unit_cost) : '–',
                  },
              ]
            : []),
        { key: 'user', header: 'Oleh', cell: (r) => r.user ?? 'Sistem' },
    ];

    return (
        <>
            <Head title="Kartu Stok" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Kartu Stok
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Riwayat keluar-masuk per batch. Pilih produk atau lokasi
                        di filter; rentang tanggal paling lama satu tahun.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Kartu stok"
                    toolbarActions={
                        canExport && (
                            <ExportButton
                                href="/stok/kartu/ekspor"
                                total={listing.pagination.total}
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

StockMovements.layout = {
    breadcrumbs: [{ title: 'Kartu Stok', href: '/stok/kartu' }],
};
