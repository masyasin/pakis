import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
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
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/admin/departments';
import type { ListingProps } from '@/types/listing';

type DepartmentRow = {
    id: number;
    code: string;
    name: string;
    parent: string | null;
    is_active: boolean;
};

type Props = ListingProps<DepartmentRow> & { can: { manage: boolean } };

const columns: DataTableColumn<DepartmentRow>[] = [
    {
        key: 'code',
        header: 'Kode',
        sortKey: 'code',
        cell: (r) => <span className="font-mono text-xs">{r.code}</span>,
    },
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (r) => <span className="font-medium">{r.name}</span>,
    },
    {
        key: 'parent',
        header: 'Induk',
        cell: (r) =>
            r.parent ?? (
                <span className="text-muted-foreground">– (puncak)</span>
            ),
    },
    // Jumlah jabatan menyusul T-023; jumlah pegawai menyusul T-025.
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={
                    r.is_active ? 'department.active' : 'department.inactive'
                }
            />
        ),
    },
];

/** Departemen (05 §4.5, FR-023). */
export default function DepartmentsIndex({ rows, listing, can }: Props) {
    const [deleting, setDeleting] = useState<DepartmentRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Departemen
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Departemen" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Departemen
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Struktur bagian di perusahaan. Departemen bisa punya
                        induk, mis. "Gudang Undaan" di bawah "Operasional".
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar departemen"
                    toolbarActions={
                        <>
                            <ExportButton
                                href={exportMethod().url}
                                total={listing.pagination.total}
                            />
                            {addButton}
                        </>
                    }
                    emptyAction={addButton}
                    rowActions={
                        can.manage
                            ? (r) => (
                                  <div className="flex justify-end gap-1">
                                      <Button variant="ghost" size="sm" asChild>
                                          <Link
                                              href={edit(r.id)}
                                              aria-label={`Ubah departemen ${r.name}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus departemen ${r.name}`}
                                          onClick={() => setDeleting(r)}
                                      >
                                          <Trash2 aria-hidden="true" />
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Hapus departemen {deleting?.name}?
                    </DialogTitle>
                    <DialogDescription>
                        Hanya departemen tanpa sub-departemen dan belum dipakai
                        jabatan yang bisa dihapus. Bila sudah dipakai,
                        nonaktifkan lewat tombol Ubah.
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
                                    onError: (errors) =>
                                        toast.error(
                                            errors.department ??
                                                'Departemen tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Departemen
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

DepartmentsIndex.layout = {
    breadcrumbs: [{ title: 'Departemen', href: index() }],
};
