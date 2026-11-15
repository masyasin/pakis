import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import type { StatusTone } from '@/components/pakis/status-badge';
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
} from '@/routes/admin/positions';
import type { ListingProps } from '@/types/listing';

type PositionRow = {
    id: number;
    code: string;
    name: string;
    department: string | null;
    parent: string | null;
    level: number;
    attendance_mode: 'location' | 'any_location' | 'anywhere';
    attendance_mode_label: string;
    is_active: boolean;
};

type Props = ListingProps<PositionRow> & { can: { manage: boolean } };

/** Warna badge mode absen: makin longgar validasi lokasinya, makin "info". */
const MODE_TONE: Record<PositionRow['attendance_mode'], StatusTone> = {
    location: 'success',
    any_location: 'info',
    anywhere: 'warning',
};

const columns: DataTableColumn<PositionRow>[] = [
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
        key: 'department',
        header: 'Departemen',
        cell: (r) => r.department ?? '–',
    },
    {
        key: 'parent',
        header: 'Atasan',
        cell: (r) =>
            r.parent ?? <span className="text-muted-foreground">–</span>,
    },
    {
        key: 'level',
        header: 'Level',
        sortKey: 'level',
        className: 'tabular-nums',
        cell: (r) => r.level,
    },
    {
        key: 'mode',
        header: 'Mode absen',
        cell: (r) => (
            <StatusBadge
                status={`position.mode.${r.attendance_mode}`}
                label={r.attendance_mode_label}
                tone={MODE_TONE[r.attendance_mode]}
            />
        ),
    },
    // Jumlah pegawai menyusul T-025 (penugasan).
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={r.is_active ? 'position.active' : 'position.inactive'}
            />
        ),
    },
];

/** Jabatan (05 §4.5, FR-024, BR-042). */
export default function PositionsIndex({ rows, listing, can }: Props) {
    const [deleting, setDeleting] = useState<PositionRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Jabatan
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Jabatan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Jabatan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Jabatan per departemen, atasannya, dan mode absen
                        bawaan. Mode absen bisa ditimpa per pegawai di
                        penugasan.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar jabatan"
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
                                              aria-label={`Ubah jabatan ${r.name}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus jabatan ${r.name}`}
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
                    <DialogTitle>Hapus jabatan {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        Hanya jabatan yang bukan atasan jabatan lain dan belum
                        dipakai penugasan yang bisa dihapus. Bila sudah dipakai,
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
                                            errors.position ??
                                                'Jabatan tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Jabatan
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

PositionsIndex.layout = {
    breadcrumbs: [{ title: 'Jabatan', href: index() }],
};
