import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import type { ListingProps } from '@/types/listing';

export type PurchaseOrderRow = {
    id: number;
    number: string;
    supplier: string;
    stock_location: string;
    order_date: string;
    expected_date: string | null;
    grand_total: string;
    status: string;
    status_label: string;
    overdue: boolean;
};

type Props = ListingProps<PurchaseOrderRow> & { canCreate: boolean };

/**
 * Daftar purchase order (15 §7.3 `purchase_orders`, FR-A24). Hanya PO ke gudang dalam scope Stok
 * pengguna; "Terlambat" = PO terbuka yang lewat perkiraan datang (tanggal kantor).
 */
export default function PurchaseOrdersIndex({
    rows,
    listing,
    canCreate,
}: Props) {
    const columns: DataTableColumn<PurchaseOrderRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/pembelian/po/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        {
            key: 'order_date',
            header: 'Tanggal',
            sortKey: 'order_date',
            cell: (r) => formatCivilDate(r.order_date),
        },
        { key: 'supplier', header: 'Supplier', cell: (r) => r.supplier },
        {
            key: 'stock_location',
            header: 'Gudang tujuan',
            cell: (r) => r.stock_location,
        },
        {
            key: 'expected_date',
            header: 'Perkiraan datang',
            cell: (r) =>
                r.expected_date === null ? (
                    '–'
                ) : (
                    <span className="inline-flex flex-wrap items-center gap-1.5">
                        {formatCivilDate(r.expected_date)}
                        {r.overdue && (
                            <StatusBadge
                                status="po.overdue"
                                label="Terlambat"
                                tone="danger"
                            />
                        )}
                    </span>
                ),
        },
        {
            key: 'grand_total',
            header: 'Total',
            sortKey: 'grand_total',
            className: 'text-right tabular-nums',
            cell: (r) => formatRupiah(r.grand_total),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`purchase_order.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Purchase Order" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Purchase Order
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pesanan pembelian ke supplier. PO disetujui sesuai
                        nilainya sebelum dikirim, lalu barangnya diterima di
                        gudang tujuan.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar purchase order"
                    toolbarActions={
                        <>
                            <ExportButton
                                href="/pembelian/po/ekspor"
                                total={listing.pagination.total}
                            />
                            {canCreate && (
                                <Button asChild>
                                    <Link href="/pembelian/po/baru">
                                        <Plus aria-hidden="true" />
                                        Buat PO
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
            </div>
        </>
    );
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Purchase Order', href: '/pembelian/po' }],
};
