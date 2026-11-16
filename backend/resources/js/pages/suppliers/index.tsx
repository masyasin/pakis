import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import type { ListingProps } from '@/types/listing';

type SupplierRow = {
    id: number;
    code: string;
    name: string;
    principal: string | null;
    tax_id: string | null;
    contact_name: string | null;
    phone: string | null;
    payment_term_days: number;
    products_count: number;
    is_active: boolean;
};

type Props = ListingProps<SupplierRow> & { canManage: boolean };

/** Daftar supplier (15 §7.3 `suppliers`, FR-A11). Rekening tidak pernah tampil di daftar (SEC-108). */
export default function SuppliersIndex({ rows, listing, canManage }: Props) {
    const columns: DataTableColumn<SupplierRow>[] = [
        {
            key: 'name',
            header: 'Supplier',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <Link href={`/supplier/${r.id}`} className="hover:underline">
                    <span className="block font-medium">{r.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.code}
                    </span>
                </Link>
            ),
        },
        {
            key: 'principal',
            header: 'Prinsipal',
            cell: (r) => r.principal ?? '–',
        },
        {
            key: 'contact',
            header: 'Kontak',
            cell: (r) => (
                <span>
                    {r.contact_name ?? '–'}
                    {r.phone && (
                        <span className="block text-xs text-muted-foreground">
                            {r.phone}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'payment_term_days',
            header: 'Tempo bayar',
            cell: (r) =>
                r.payment_term_days === 0
                    ? 'Tunai'
                    : `${r.payment_term_days} hari`,
        },
        {
            key: 'products_count',
            header: 'Produk dipasok',
            cell: (r) =>
                r.products_count > 0 ? `${r.products_count} produk` : '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={r.is_active ? 'active' : 'inactive'}
                    label={r.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={r.is_active ? 'success' : 'muted'}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Supplier" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Supplier
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pihak tempat Pakis membeli barang, dengan tempo bayar,
                        rekening, dan produk yang dipasok. Supplier nonaktif
                        tidak bisa dipilih di pesanan pembelian.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar supplier"
                    toolbarActions={
                        canManage && (
                            <Button asChild>
                                <Link href="/supplier/baru">
                                    <Plus aria-hidden="true" />
                                    Tambah Supplier
                                </Link>
                            </Button>
                        )
                    }
                />
            </div>
        </>
    );
}

SuppliersIndex.layout = {
    breadcrumbs: [{ title: 'Supplier', href: '/supplier' }],
};
