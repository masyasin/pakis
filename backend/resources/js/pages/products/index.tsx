import { Head, Link } from '@inertiajs/react';
import { Package, Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import type { ListingProps } from '@/types/listing';

type ProductRow = {
    id: number;
    sku: string;
    name: string;
    category: string;
    kind_label: string;
    active_ingredient: string | null;
    package_size: string | null;
    base_unit: string;
    is_restricted: boolean;
    status: 'active' | 'inactive' | 'discontinued';
    status_label: string;
    photo_url: string | null;
};

type Props = ListingProps<ProductRow> & { canManage: boolean };

const STATUS_TONE = {
    active: 'success',
    inactive: 'muted',
    discontinued: 'warning',
} as const;

/** Thumbnail kecil; produk tanpa foto memakai ikon netral. */
function Thumb({ url, name }: { url: string | null; name: string }) {
    return url ? (
        <img
            src={url}
            alt=""
            loading="lazy"
            className="size-10 shrink-0 rounded-md border bg-muted object-cover"
            title={name}
        />
    ) : (
        <span
            aria-hidden="true"
            className="grid size-10 shrink-0 place-items-center rounded-md border bg-muted text-muted-foreground"
        >
            <Package className="size-5" />
        </span>
    );
}

/** Daftar produk (15 §7.3 `products`, FR-A08): cari SKU, nama, bahan aktif, atau scan barcode. */
export default function ProductsIndex({ rows, listing, canManage }: Props) {
    const columns: DataTableColumn<ProductRow>[] = [
        {
            key: 'name',
            header: 'Produk',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/produk/${r.id}`}
                    className="flex items-center gap-3 hover:underline"
                >
                    <Thumb url={r.photo_url} name={r.name} />
                    <span className="min-w-0">
                        <span className="block font-medium">{r.name}</span>
                        <span className="block font-mono text-xs text-muted-foreground">
                            {r.sku}
                        </span>
                    </span>
                </Link>
            ),
        },
        {
            key: 'category',
            header: 'Kategori',
            cell: (r) => (
                <span>
                    {r.category}
                    <span className="block text-xs text-muted-foreground">
                        {r.kind_label}
                    </span>
                </span>
            ),
        },
        {
            key: 'active_ingredient',
            header: 'Bahan aktif',
            cell: (r) => r.active_ingredient ?? '–',
        },
        {
            key: 'package',
            header: 'Kemasan',
            cell: (r) => (
                <span>
                    {r.package_size ?? '–'}
                    <span className="block text-xs text-muted-foreground">
                        satuan dasar {r.base_unit.toLowerCase()}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <span className="flex flex-wrap gap-1">
                    <StatusBadge
                        status={r.status}
                        label={r.status_label}
                        tone={STATUS_TONE[r.status]}
                    />
                    {r.is_restricted && (
                        <StatusBadge
                            status="restricted"
                            label="Terbatas"
                            tone="danger"
                        />
                    )}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Produk" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Produk
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Master produk dengan satuan, konversi, dan barcode. Cari
                        dengan SKU, nama, bahan aktif, atau langsung scan
                        barcode.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar produk"
                    toolbarActions={
                        <>
                            <ExportButton
                                href="/produk/ekspor"
                                total={listing.pagination.total}
                            />
                            {canManage && (
                                <Button asChild>
                                    <Link href="/produk/baru">
                                        <Plus aria-hidden="true" />
                                        Tambah Produk
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

ProductsIndex.layout = {
    breadcrumbs: [{ title: 'Produk', href: '/produk' }],
};
