import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatRupiah } from '@/lib/money';
import type { ListingProps } from '@/types/listing';

export type PurchaseReturnRow = {
    id: number;
    number: string;
    created_at: string | null;
    supplier: string;
    stock_location: string;
    reason: string;
    lines: number;
    total_cost: string | null;
    status: string;
    status_label: string;
};

type Props = ListingProps<PurchaseReturnRow> & { showCost: boolean };

/**
 * Daftar retur ke prinsipal (15 §7.3 `purchase_returns`, FR-A26). Hanya retur di lokasi stok dalam
 * scope pengguna. Nilai hanya untuk pemegang izin harga beli (SEC-107).
 */
export default function PurchaseReturnsIndex({
    rows,
    listing,
    showCost,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const columns: DataTableColumn<PurchaseReturnRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/pembelian/retur/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        {
            key: 'created_at',
            header: 'Dibuat',
            sortKey: 'created_at',
            cell: (r) =>
                r.created_at ? formatDateTimeIn(r.created_at, timezone) : '–',
        },
        { key: 'supplier', header: 'Supplier', cell: (r) => r.supplier },
        {
            key: 'stock_location',
            header: 'Lokasi stok',
            cell: (r) => r.stock_location,
        },
        { key: 'reason', header: 'Alasan', cell: (r) => r.reason },
        {
            key: 'lines',
            header: 'Baris',
            className: 'text-right tabular-nums',
            cell: (r) => r.lines,
        },
        ...(showCost
            ? [
                  {
                      key: 'total_cost',
                      header: 'Nilai',
                      sortKey: 'total_cost',
                      className: 'text-right tabular-nums',
                      cell: (r: PurchaseReturnRow) =>
                          formatRupiah(r.total_cost),
                  },
              ]
            : []),
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`purchase_return.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Retur ke Prinsipal" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Retur ke Prinsipal
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Barang rusak, mendekati ED, atau ditarik prinsipal
                        dikembalikan dari batch tertentu. Setelah disetujui stok
                        direservasi, lalu berkurang saat dikirim.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar retur ke prinsipal"
                    toolbarActions={
                        <Button asChild>
                            <Link href="/pembelian/retur/baru">
                                <Plus aria-hidden="true" />
                                Buat Retur
                            </Link>
                        </Button>
                    }
                />
            </div>
        </>
    );
}

PurchaseReturnsIndex.layout = {
    breadcrumbs: [{ title: 'Retur ke Prinsipal', href: '/pembelian/retur' }],
};
