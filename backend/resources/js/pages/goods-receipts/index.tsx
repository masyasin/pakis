import { Head, Link, usePage } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDateTimeIn } from '@/lib/approval-format';
import type { ListingProps } from '@/types/listing';

export type GoodsReceiptRow = {
    id: number;
    number: string;
    received_at: string;
    supplier: string;
    stock_location: string;
    purchase_order: string | null;
    supplier_delivery_no: string | null;
    status: string;
    status_label: string;
};

type Props = ListingProps<GoodsReceiptRow> & { canCreate: boolean };

/**
 * Daftar penerimaan barang (15 §7.3 `goods_receipts`, FR-A25). Hanya penerimaan di lokasi stok dalam
 * scope pengguna. Penerimaan baru dimulai dari detail PO ("Terima Barang"), jadi halaman ini tanpa
 * tombol tambah.
 */
export default function GoodsReceiptsIndex({ rows, listing }: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const columns: DataTableColumn<GoodsReceiptRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/penerimaan/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        {
            key: 'received_at',
            header: 'Diterima',
            sortKey: 'received_at',
            cell: (r) => formatDateTimeIn(r.received_at, timezone),
        },
        { key: 'supplier', header: 'Supplier', cell: (r) => r.supplier },
        {
            key: 'stock_location',
            header: 'Lokasi stok',
            cell: (r) => r.stock_location,
        },
        {
            key: 'purchase_order',
            header: 'PO',
            cell: (r) => (
                <span className="font-mono text-xs">
                    {r.purchase_order ?? '–'}
                </span>
            ),
        },
        {
            key: 'supplier_delivery_no',
            header: 'Surat jalan',
            cell: (r) => r.supplier_delivery_no ?? '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`goods_receipt.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Penerimaan Barang" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Penerimaan Barang
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Barang datang dari supplier dicek per baris (jumlah,
                        batch, ED, izin edar), diperiksa, lalu diposting ke stok
                        oleh orang yang berbeda. Mulai dari detail PO dengan
                        tombol "Terima Barang".
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar penerimaan barang"
                    toolbarActions={
                        <ExportButton
                            href="/penerimaan/ekspor"
                            total={listing.pagination.total}
                        />
                    }
                />
            </div>
        </>
    );
}

GoodsReceiptsIndex.layout = {
    breadcrumbs: [{ title: 'Penerimaan Barang', href: '/penerimaan' }],
};
