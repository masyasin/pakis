import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatNumber } from '@/lib/listing-query';
import type { ListingProps } from '@/types/listing';

type ExportRow = {
    id: number;
    listing: string;
    status: string;
    status_label: string;
    row_count: number | null;
    created_at: string | null;
    expires_at: string | null;
    download_url: string | null;
};

const columns: DataTableColumn<ExportRow>[] = [
    {
        key: 'created_at',
        header: 'Waktu',
        sortKey: 'created_at',
        className: 'whitespace-nowrap',
        cell: (r) => r.created_at ?? '–',
    },
    {
        key: 'listing',
        header: 'Daftar',
        primary: true,
        cell: (r) => <span className="font-mono text-xs">{r.listing}</span>,
    },
    {
        key: 'row_count',
        header: 'Jumlah baris',
        className: 'text-right tabular-nums',
        cell: (r) => (r.row_count === null ? '–' : formatNumber(r.row_count)),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => <StatusBadge status={`export.${r.status}`} />,
    },
    {
        key: 'expires_at',
        header: 'Kedaluwarsa',
        className: 'whitespace-nowrap',
        cell: (r) => r.expires_at ?? '–',
    },
];

/** Halaman Unduhan: berkas ekspor milik sendiri (05 §4.7). */
export default function ExportsIndex({
    rows,
    listing,
}: ListingProps<ExportRow>) {
    return (
        <>
            <Head title="Unduhan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Unduhan</h1>
                    <p className="text-sm text-muted-foreground">
                        Berkas ekspor Anda. Tautan unduh berlaku sebentar; muat
                        ulang halaman bila tautan kedaluwarsa.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar berkas unduhan"
                    rowActions={(r) =>
                        r.download_url ? (
                            // <a> biasa: unduhan berkas bukan kunjungan Inertia.
                            <Button asChild size="sm" variant="outline">
                                <a href={r.download_url} download>
                                    <Download className="size-4" aria-hidden />
                                    Unduh
                                </a>
                            </Button>
                        ) : null
                    }
                />
            </div>
        </>
    );
}

ExportsIndex.layout = {
    breadcrumbs: [{ title: 'Unduhan', href: '/unduhan' }],
};
