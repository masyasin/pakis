import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/relative-time';
import { destroy } from '@/routes/admin/import-profiles';
import { index as imports } from '@/routes/admin/imports';
import type { ListingProps } from '@/types/listing';

type ProfileRow = {
    id: number;
    name: string;
    columns: number;
    creator: string | null;
    updated_at: string | null;
};

const columns: DataTableColumn<ProfileRow>[] = [
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (r) => <span className="font-medium">{r.name}</span>,
    },
    {
        key: 'columns',
        header: 'Jumlah kolom',
        className: 'tabular-nums',
        cell: (r) => r.columns,
    },
    {
        key: 'creator',
        header: 'Dibuat oleh',
        cell: (r) => r.creator ?? '–',
    },
    {
        key: 'updated_at',
        header: 'Terakhir dipakai',
        sortKey: 'updated_at',
        cell: (r) => (r.updated_at ? formatDateTime(r.updated_at) : '–'),
    },
];

/** Profil pemetaan impor (05 §4.7, 07 §3.4 `import_profiles`). */
export default function ImportProfilesIndex({
    rows,
    listing,
}: ListingProps<ProfileRow>) {
    const [deleting, setDeleting] = useState<ProfileRow | null>(null);
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <Head title="Profil pemetaan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <Link
                    href={imports()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Riwayat impor
                </Link>
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Profil pemetaan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pasangan judul kolom → field yang tersimpan. Berkas
                        dengan judul kolom yang sama otomatis memakai profil
                        ini.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar profil pemetaan"
                    rowActions={(r) => (
                        <div className="flex justify-end">
                            <Button
                                variant="ghost"
                                size="sm"
                                aria-label={`Hapus profil ${r.name}`}
                                onClick={() => setDeleting(r)}
                            >
                                <Trash2 aria-hidden="true" />
                            </Button>
                        </div>
                    )}
                />
            </div>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Hapus profil {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        Impor yang sudah berjalan tidak terpengaruh. Berkas
                        berikutnya perlu dipetakan ulang.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={() => {
                                if (!deleting) {
                                    return;
                                }
                                router.delete(destroy(deleting.id).url, {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ImportProfilesIndex.layout = {
    breadcrumbs: [
        { title: 'Impor Pegawai', href: imports() },
        { title: 'Profil pemetaan', href: '#' },
    ],
};
