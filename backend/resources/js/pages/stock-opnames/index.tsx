import { Head, Link, usePage } from '@inertiajs/react';
import { EyeOff, Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn } from '@/lib/approval-format';
import type { ListingProps } from '@/types/listing';

export type StockOpnameRow = {
    id: number;
    number: string;
    location: string;
    scope: string;
    scope_label: string;
    blind_count: boolean;
    lines_count: number;
    status: string;
    status_label: string;
    created_at: string | null;
    posted_at: string | null;
};

type Props = ListingProps<StockOpnameRow> & { canCreate: boolean };

/**
 * Daftar stock opname (15 §7.3 `stock_opnames`, FR-A28). Hanya opname di lokasi dalam scope Stok
 * pengguna. Hitung buta ditandai ikon mata tertutup.
 */
export default function StockOpnamesIndex({ rows, listing, canCreate }: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const when = (iso: string | null) =>
        iso === null ? '–' : formatDateTimeIn(iso, timezone);

    const columns: DataTableColumn<StockOpnameRow>[] = [
        {
            key: 'number',
            header: 'Nomor',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/opname/${r.id}`}
                    className="font-mono font-medium hover:underline"
                >
                    {r.number}
                </Link>
            ),
        },
        { key: 'location', header: 'Lokasi', cell: (r) => r.location },
        {
            key: 'scope',
            header: 'Cakupan',
            cell: (r) => (
                <span className="inline-flex items-center gap-1.5">
                    {r.scope_label}
                    {r.blind_count && (
                        <EyeOff
                            className="size-3.5 text-muted-foreground"
                            aria-label="Hitung buta"
                        />
                    )}
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
            key: 'created_at',
            header: 'Dibuat',
            sortKey: 'created_at',
            cell: (r) => when(r.created_at),
        },
        {
            key: 'posted_at',
            header: 'Diposting',
            cell: (r) => when(r.posted_at),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`stock_opname.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Stock Opname" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Stock Opname
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Hitung fisik stok per lokasi, rak, kategori, atau
                        produk. Selisih diposting setelah ditinjau dan, bila
                        ada, disetujui sesuai nilainya.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar stock opname"
                    toolbarActions={
                        canCreate && (
                            <Button asChild>
                                <Link href="/opname/baru">
                                    <Plus aria-hidden="true" />
                                    Buat Opname
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

StockOpnamesIndex.layout = {
    breadcrumbs: [{ title: 'Stock Opname', href: '/opname' }],
};
