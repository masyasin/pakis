import { Head } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import type { ListingProps } from '@/types/listing';

type DemoUser = {
    id: number;
    name: string;
    email: string;
    verified: boolean;
    mfa: boolean;
    created_at: string | null;
};

const columns: DataTableColumn<DemoUser>[] = [
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (u) => u.name,
    },
    { key: 'email', header: 'Email', sortKey: 'email', cell: (u) => u.email },
    {
        key: 'verified',
        header: 'Verifikasi',
        cell: (u) => (
            <StatusBadge
                status={u.verified ? 'verified' : 'unverified'}
                label={u.verified ? 'Terverifikasi' : 'Belum'}
                tone={u.verified ? 'success' : 'warning'}
            />
        ),
    },
    {
        key: 'mfa',
        header: 'MFA',
        cell: (u) => (
            <StatusBadge
                status={u.mfa ? 'mfa-on' : 'mfa-off'}
                label={u.mfa ? 'Aktif' : 'Belum aktif'}
                tone={u.mfa ? 'info' : 'muted'}
            />
        ),
    },
    {
        key: 'created_at',
        header: 'Dibuat',
        sortKey: 'created_at',
        className: 'whitespace-nowrap',
        cell: (u) => u.created_at ?? '–',
    },
];

/** Halaman uji visual DataTable (hanya local, T-005). */
export default function ListingDemo({ rows, listing }: ListingProps<DemoUser>) {
    return (
        <>
            <Head title="Demo daftar" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Demo daftar</h1>
                    <p className="text-sm text-muted-foreground">
                        Halaman uji komponen DataTable (hanya lingkungan lokal).
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pengguna demo"
                    toolbarActions={
                        listing.spec.exportable ? (
                            <ExportButton
                                href="/dev/listing-demo/ekspor"
                                total={listing.pagination.total}
                            />
                        ) : undefined
                    }
                />
            </div>
        </>
    );
}

ListingDemo.layout = {
    breadcrumbs: [{ title: 'Demo daftar', href: '/dev/listing-demo' }],
};
