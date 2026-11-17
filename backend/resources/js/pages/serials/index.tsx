import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import type { ListingProps } from '@/types/listing';

export type SerialRow = {
    id: number;
    serial_no: string;
    sku: string;
    product: string;
    stock_location: string | null;
    bin: string | null;
    batch_no: string | null;
    status: string;
    status_label: string;
    input_method: string;
    created_at: string | null;
};

/**
 * Daftar nomor seri (15 §7.3 `serial_numbers`, BR-144). Hanya nomor seri yang sudah masuk stok lewat
 * penerimaan terposting, di lokasi stok dalam scope pengguna. Cari persis atau awal nomor seri.
 */
export default function SerialsIndex({
    rows,
    listing,
}: ListingProps<SerialRow>) {
    const columns: DataTableColumn<SerialRow>[] = [
        {
            key: 'serial_no',
            header: 'Nomor seri',
            sortKey: 'serial_no',
            primary: true,
            cell: (r) => (
                <span className="font-mono font-medium">{r.serial_no}</span>
            ),
        },
        {
            key: 'product',
            header: 'Produk',
            cell: (r) => (
                <span className="grid">
                    <span>{r.product}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku}
                    </span>
                </span>
            ),
        },
        {
            key: 'stock_location',
            header: 'Lokasi',
            cell: (r) =>
                r.stock_location
                    ? `${r.stock_location}${r.bin ? ` · ${r.bin}` : ''}`
                    : '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`serial.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
        {
            key: 'input_method',
            header: 'Cara input',
            cell: (r) => (r.input_method === 'manual' ? 'Manual' : 'Scan'),
        },
    ];

    return (
        <>
            <Head title="Nomor Seri" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Nomor Seri
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Nomor seri alat (sprayer dan sejenisnya) yang sudah
                        masuk stok. Satu nomor seri hanya sekali per produk.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar nomor seri"
                    toolbarActions={
                        <ExportButton
                            href="/nomor-seri/ekspor"
                            total={listing.pagination.total}
                        />
                    }
                />
            </div>
        </>
    );
}

SerialsIndex.layout = {
    breadcrumbs: [{ title: 'Nomor Seri', href: '/nomor-seri' }],
};
