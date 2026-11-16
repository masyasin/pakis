import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import {
    daysLeftLabel,
    RegistrationBadge,
} from '@/components/catalog/registration-ui';
import type { RegistrationRow } from '@/components/catalog/registration-ui';
import { formatCivilDate } from '@/lib/local-date';
import type { ListingProps } from '@/types/listing';

type Row = RegistrationRow & {
    product: { id: number; sku: string; name: string };
};

type Props = ListingProps<Row> & { thresholdDays: number };

/** Daftar izin edar (15 §7.3 `product_registrations`, FR-A10). Bawaan: izin berlaku yang segera habis. */
export default function ProductRegistrationsIndex({
    rows,
    listing,
    thresholdDays,
}: Props) {
    const columns: DataTableColumn<Row>[] = [
        {
            key: 'product',
            header: 'Produk',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/produk/${r.product.id}?tab=izin`}
                    className="hover:underline"
                >
                    <span className="block font-medium">{r.product.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.product.sku}
                    </span>
                </Link>
            ),
        },
        {
            key: 'registration_no',
            header: 'Nomor izin',
            sortKey: 'registration_no',
            cell: (r) => (
                <span>
                    <span className="block font-mono text-sm">
                        {r.registration_no}
                    </span>
                    {r.holder_name && (
                        <span className="block text-xs text-muted-foreground">
                            {r.holder_name}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'valid_until',
            header: 'Berlaku sampai',
            sortKey: 'valid_until',
            cell: (r) => (
                <span>
                    {formatCivilDate(r.valid_until)}
                    <span className="block text-xs text-muted-foreground">
                        {daysLeftLabel(r.days_left)}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => <RegistrationBadge row={r} />,
        },
        {
            key: 'document',
            header: 'Berkas',
            cell: (r) =>
                r.document_url ? (
                    <a
                        href={r.document_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
                    >
                        <FileText className="size-4" aria-hidden="true" />
                        Lihat
                    </a>
                ) : (
                    <span className="text-sm text-muted-foreground">–</span>
                ),
        },
    ];

    return (
        <>
            <Head title="Izin Edar" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Izin Edar
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Awalnya menampilkan izin berlaku yang habis dalam{' '}
                        {thresholdDays} hari. Hapus filter untuk melihat semua
                        izin. Perpanjangan ditambahkan dari halaman produk.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar izin edar"
                />
            </div>
        </>
    );
}

ProductRegistrationsIndex.layout = {
    breadcrumbs: [{ title: 'Izin Edar', href: '/izin-edar' }],
};
