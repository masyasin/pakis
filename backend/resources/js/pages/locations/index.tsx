import { Head, Link, router } from '@inertiajs/react';
import { MapPinOff, Pencil, Plus, Trash2 } from 'lucide-react';
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
} from '@/routes/admin/locations';
import type { ListingProps } from '@/types/listing';

type LocationRow = {
    id: number;
    code: string;
    name: string;
    type: string | null;
    village: string | null;
    district: string | null;
    has_coordinates: boolean;
    radius_m: number;
    radius_is_default: boolean;
    is_active: boolean;
};

type Props = ListingProps<LocationRow> & { can: { manage: boolean } };

const columns: DataTableColumn<LocationRow>[] = [
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
    { key: 'type', header: 'Tipe', cell: (r) => r.type ?? '–' },
    {
        key: 'area',
        header: 'Desa / Kec.',
        cell: (r) =>
            r.village ? (
                <span>
                    {r.village}
                    {r.district && (
                        <span className="text-muted-foreground">
                            {' '}
                            · {r.district}
                        </span>
                    )}
                </span>
            ) : (
                '–'
            ),
    },
    // Kepala lokasi dipilih setelah modul pegawai (T-024); jumlah pegawai setelah penugasan (T-025).
    {
        key: 'radius',
        header: 'Radius',
        className: 'whitespace-nowrap tabular-nums',
        cell: (r) => (
            <span>
                {r.radius_m.toLocaleString('id-ID')} m
                {r.radius_is_default && (
                    <span className="text-muted-foreground"> (bawaan)</span>
                )}
            </span>
        ),
    },
    {
        key: 'coordinates',
        header: 'Koordinat',
        cell: (r) =>
            r.has_coordinates ? (
                <StatusBadge
                    status="location.coordinates"
                    label="Ada"
                    tone="success"
                />
            ) : (
                // BR-041: lokasi tanpa koordinat tidak bisa dipakai validasi radius absen.
                <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-0.5 text-xs font-bold whitespace-nowrap text-status-warning">
                    <MapPinOff className="size-3.5" aria-hidden="true" />
                    Belum ada koordinat
                </span>
            ),
    },
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={r.is_active ? 'location.active' : 'location.inactive'}
            />
        ),
    },
];

/** Lokasi kerja (05 §4.5, FR-022, BR-041). */
export default function LocationsIndex({ rows, listing, can }: Props) {
    const [deleting, setDeleting] = useState<LocationRow | null>(null);
    const [processing, setProcessing] = useState(false);

    return (
        <>
            <Head title="Lokasi" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Lokasi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kantor, gudang, dan toko tempat pegawai bertugas.
                        Koordinat & radius dipakai untuk memeriksa absen di
                        lokasi.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar lokasi"
                    toolbarActions={
                        <>
                            <ExportButton
                                href={exportMethod().url}
                                total={listing.pagination.total}
                            />
                            {can.manage && (
                                <Button asChild>
                                    <Link href={create()}>
                                        <Plus aria-hidden="true" />
                                        Tambah Lokasi
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                    emptyAction={
                        can.manage ? (
                            <Button asChild>
                                <Link href={create()}>
                                    <Plus aria-hidden="true" />
                                    Tambah Lokasi
                                </Link>
                            </Button>
                        ) : undefined
                    }
                    rowActions={
                        can.manage
                            ? (r) => (
                                  <div className="flex justify-end gap-1">
                                      <Button variant="ghost" size="sm" asChild>
                                          <Link
                                              href={edit(r.id)}
                                              aria-label={`Ubah lokasi ${r.name}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus lokasi ${r.name}`}
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
                    <DialogTitle>Hapus lokasi {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        Hanya lokasi yang belum pernah dipakai penugasan atau
                        absensi yang bisa dihapus. Bila sudah dipakai,
                        nonaktifkan lokasi lewat tombol Ubah.
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
                                    // BR-041: lokasi terpakai ditolak server dengan galat `location`.
                                    onError: (errors) =>
                                        toast.error(
                                            errors.location ??
                                                'Lokasi tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Lokasi
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

LocationsIndex.layout = {
    breadcrumbs: [{ title: 'Lokasi', href: index() }],
};
