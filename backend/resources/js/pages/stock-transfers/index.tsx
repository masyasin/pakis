import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';
import type { ListingProps } from '@/types/listing';

export type StockTransferRow = {
    id: number;
    number: string;
    from_location: string;
    to_location: string;
    lines_count: number;
    status: string;
    status_label: string;
    created_at: string | null;
    shipped_at: string | null;
    received_at: string | null;
    overdue: boolean;
};

type Props = ListingProps<StockTransferRow> & { canCreate: boolean };

/**
 * Daftar mutasi stok antar lokasi (15 §7.3 `stock_transfers`, FR-A27). Tampil mutasi yang lokasi
 * asal ATAU tujuannya dalam scope Stok pengguna. "Terlambat" = sudah dikirim tetapi belum diterima
 * melewati batas jam di Pengaturan (BR-161).
 */
export default function StockTransfersIndex({
    rows,
    listing,
    canCreate,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const when = (iso: string | null) =>
        iso === null ? '–' : formatDateTimeIn(iso, timezone);

    const columns: DataTableColumn<StockTransferRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/mutasi/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        {
            key: 'route',
            header: 'Dari → ke',
            cell: (r) => (
                <span className="inline-flex flex-wrap items-center gap-1.5">
                    <span>{r.from_location}</span>
                    <ArrowRight
                        className="size-3.5 shrink-0 text-muted-foreground"
                        aria-label="ke"
                    />
                    <span>{r.to_location}</span>
                </span>
            ),
        },
        {
            key: 'lines_count',
            header: 'Baris',
            className: 'text-right tabular-nums',
            cell: (r) => r.lines_count,
        },
        {
            key: 'shipped_at',
            header: 'Dikirim',
            sortKey: 'shipped_at',
            cell: (r) => (
                <span className="inline-flex flex-wrap items-center gap-1.5">
                    {when(r.shipped_at)}
                    {r.overdue && (
                        <StatusBadge
                            status="stock_transfer.overdue"
                            label="Terlambat"
                            tone="danger"
                        />
                    )}
                </span>
            ),
        },
        {
            key: 'received_at',
            header: 'Diterima',
            cell: (r) => when(r.received_at),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`stock_transfer.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Mutasi Stok" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Mutasi Stok
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pindah stok antar gudang dan toko. Barang yang dikirim
                        tercatat dalam perjalanan sampai diterima di lokasi
                        tujuan oleh orang lain.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar mutasi stok"
                    toolbarActions={
                        canCreate && (
                            <Button asChild>
                                <Link href="/mutasi/baru">
                                    <Plus aria-hidden="true" />
                                    Buat Mutasi
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

StockTransfersIndex.layout = {
    breadcrumbs: [{ title: 'Mutasi Stok', href: '/mutasi' }],
};
