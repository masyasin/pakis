import { Head, Link } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatCivilDate } from '@/lib/local-date';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type StockTraceRow = {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    location: string;
    location_code: string;
    bin: string | null;
    batch_no: string;
    expiry_date: string | null;
    received_date: string | null;
    status: string;
    status_label: string;
    qty_on_hand: string;
};

type TraceExample = {
    product_id: number;
    sku: string;
    name: string;
    batch_no: string;
};

type Props = ListingProps<StockTraceRow> & {
    searched: boolean;
    /** Batch terakhir masuk dalam scope, sebagai titik mulai saat belum mencari. */
    examples: TraceExample[];
};

/**
 * Telusur batch untuk recall (15 §7.3 `stock_batches_trace`, UAT-A17): pilih produk di filter dan
 * ketik nomor batch → semua lokasi yang pernah menyimpan batch itu (termasuk saldo 0) dalam scope
 * Stok. Riwayat mutasi dibuka lewat Kartu Stok. Paket B menambah kios penerima.
 */
export default function StockTrace({
    rows,
    listing,
    searched,
    examples,
}: Props) {
    const columns: DataTableColumn<StockTraceRow>[] = [
        {
            key: 'location',
            header: 'Lokasi · rak',
            sortKey: 'location',
            primary: true,
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-medium">{r.location}</span>
                    <span className="text-xs text-muted-foreground">
                        {r.bin ? `Rak ${r.bin}` : 'Tanpa rak'}
                    </span>
                </span>
            ),
        },
        {
            key: 'batch',
            header: 'Produk · batch',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="break-words">{r.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku} · {r.batch_no}
                        {r.expiry_date &&
                            ` · ED ${formatCivilDate(r.expiry_date)}`}
                    </span>
                </span>
            ),
        },
        {
            key: 'received',
            header: 'Diterima',
            sortKey: 'received_date',
            cell: (r) =>
                r.received_date ? formatCivilDate(r.received_date) : '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`stock_batch.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
        {
            key: 'qty',
            header: 'Sisa',
            className: 'text-right tabular-nums',
            cell: (r) => `${formatQty(r.qty_on_hand)} ${r.unit.toLowerCase()}`,
        },
        {
            key: 'history',
            header: 'Riwayat',
            cell: (r) => (
                <Link
                    href={`/stok/kartu?filter[product_id][]=${r.product_id}&q=${encodeURIComponent(r.batch_no)}`}
                    className="text-sm font-medium text-primary hover:underline"
                >
                    Kartu stok
                </Link>
            ),
        },
    ];

    return (
        <>
            <Head title="Telusur Batch" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Telusur Batch
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {searched
                            ? 'Semua lokasi yang menyimpan batch ini, termasuk yang sudah habis.'
                            : 'Pilih produk di filter lalu ketik nomor batch untuk melihat di mana saja batch itu berada.'}
                    </p>
                </div>

                {!searched && examples.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-muted-foreground">Contoh:</span>
                        {examples.map((e) => (
                            <Link
                                key={`${e.product_id}-${e.batch_no}`}
                                href={`/stok/telusur?filter[product_id][]=${e.product_id}&q=${encodeURIComponent(e.batch_no)}`}
                                className="rounded-full border bg-card px-3 py-1 hover:border-primary"
                            >
                                <span className="font-mono">{e.batch_no}</span>
                                <span className="text-muted-foreground">
                                    {' '}
                                    · {e.sku}
                                </span>
                            </Link>
                        ))}
                    </div>
                )}

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Hasil telusur batch"
                />
            </div>
        </>
    );
}

StockTrace.layout = {
    breadcrumbs: [{ title: 'Telusur Batch', href: '/stok/telusur' }],
};
