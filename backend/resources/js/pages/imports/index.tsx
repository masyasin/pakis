import { Head, Link } from '@inertiajs/react';
import { FileSpreadsheet, Settings2, Upload } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTime } from '@/lib/relative-time';
import { index as profiles } from '@/routes/admin/import-profiles';
import { create, index, show } from '@/routes/admin/imports';
import type { ListingProps } from '@/types/listing';

type BatchRow = {
    id: number;
    original_name: string;
    status: string;
    status_label: string;
    total_rows: number;
    valid_rows: number;
    error_rows: number;
    created_rows: number;
    updated_rows: number;
    created_at: string | null;
    creator: string | null;
};

const num = (value: number) => (
    <span className="tabular-nums">{value.toLocaleString('id-ID')}</span>
);

const columns: DataTableColumn<BatchRow>[] = [
    {
        key: 'created_at',
        header: 'Waktu',
        sortKey: 'created_at',
        cell: (r) => (r.created_at ? formatDateTime(r.created_at) : '–'),
    },
    {
        key: 'original_name',
        header: 'Nama berkas',
        sortKey: 'original_name',
        primary: true,
        cell: (r) => (
            <span className="flex min-w-0 items-center gap-2 font-medium">
                <FileSpreadsheet
                    className="size-4 shrink-0 text-primary"
                    aria-hidden="true"
                />
                <span className="truncate">{r.original_name}</span>
            </span>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => (
            <StatusBadge status={`import.${r.status}`} label={r.status_label} />
        ),
    },
    {
        key: 'total',
        header: 'Total',
        sortKey: 'total_rows',
        className: 'text-right',
        cell: (r) => num(r.total_rows),
    },
    {
        key: 'valid',
        header: 'Valid',
        className: 'text-right',
        cell: (r) => num(r.valid_rows),
    },
    {
        key: 'errors',
        header: 'Galat',
        className: 'text-right',
        cell: (r) =>
            r.error_rows > 0 ? (
                <span className="font-medium text-destructive tabular-nums">
                    {r.error_rows.toLocaleString('id-ID')}
                </span>
            ) : (
                num(0)
            ),
    },
    {
        key: 'created',
        header: 'Dibuat',
        className: 'text-right',
        cell: (r) => num(r.created_rows),
    },
    {
        key: 'updated',
        header: 'Diperbarui',
        className: 'text-right',
        cell: (r) => num(r.updated_rows),
    },
    {
        key: 'creator',
        header: 'Oleh',
        cell: (r) => r.creator ?? '–',
    },
];

/** Riwayat impor pegawai (05 §4.7, 07 §3.4 `import_batches`). */
export default function ImportsIndex({
    rows,
    listing,
}: ListingProps<BatchRow>) {
    const uploadButton = (
        <Button asChild>
            <Link href={create()}>
                <Upload aria-hidden="true" />
                Impor Pegawai
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Impor Pegawai" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Impor Pegawai
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Masukkan banyak pegawai sekaligus dari Excel atau CSV.
                        Setiap baris diperiksa dulu sebelum disimpan.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Riwayat impor"
                    rowHref={(r) => show(r.id).url}
                    toolbarActions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={profiles()}>
                                    <Settings2 aria-hidden="true" />
                                    <span className="hidden sm:inline">
                                        Profil pemetaan
                                    </span>
                                    <span className="sm:hidden">Profil</span>
                                </Link>
                            </Button>
                            {uploadButton}
                        </>
                    }
                    emptyAction={uploadButton}
                />
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Impor Pegawai', href: index() }],
};
