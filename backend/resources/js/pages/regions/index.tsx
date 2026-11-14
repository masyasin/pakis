import { Head } from '@inertiajs/react';
import { Terminal } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import type { ListingProps } from '@/types/listing';

type RegionRow = {
    code: string;
    name: string;
    district: string | null;
    regency: string | null;
    province: string | null;
};

const columns: DataTableColumn<RegionRow>[] = [
    {
        key: 'code',
        header: 'Kode desa',
        sortKey: 'code',
        cell: (r) => <span className="font-mono text-xs">{r.code}</span>,
    },
    {
        key: 'name',
        header: 'Desa/Kelurahan',
        sortKey: 'name',
        primary: true,
        cell: (r) => r.name,
    },
    { key: 'district', header: 'Kecamatan', cell: (r) => r.district ?? '–' },
    { key: 'regency', header: 'Kab/Kota', cell: (r) => r.regency ?? '–' },
    { key: 'province', header: 'Provinsi', cell: (r) => r.province ?? '–' },
];

type Props = ListingProps<RegionRow> & { imported: boolean };

/** Master wilayah (05 §4.5, FR-021): daftar desa/kelurahan dengan filter berjenjang. */
export default function RegionsIndex({ rows, listing, imported }: Props) {
    return (
        <>
            <Head title="Wilayah" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Wilayah</h1>
                    <p className="text-sm text-muted-foreground">
                        Provinsi, kab/kota, kecamatan, dan desa/kelurahan untuk
                        alamat lokasi & pegawai. Saring berjenjang lewat Filter.
                    </p>
                </div>

                {!imported && (
                    <div
                        role="status"
                        className="flex items-start gap-2.5 rounded-lg bg-status-info-bg px-3.5 py-3 text-sm text-status-info"
                    >
                        <Terminal
                            className="mt-px size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Data wilayah belum diimpor. Minta tim teknis
                            menjalankan{' '}
                            <code className="font-mono text-xs">
                                php artisan regions:import
                            </code>{' '}
                            dengan berkas data wilayah.
                        </span>
                    </div>
                )}

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar desa/kelurahan"
                    rowKey={(r) => r.code}
                />
            </div>
        </>
    );
}

RegionsIndex.layout = {
    breadcrumbs: [{ title: 'Wilayah', href: '/wilayah' }],
};
