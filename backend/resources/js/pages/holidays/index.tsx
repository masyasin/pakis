import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { Badge } from '@/components/ui/badge';
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
import { formatCivilRange } from '@/lib/local-date';
import {
    create,
    destroy,
    edit,
    exportMethod,
    index,
} from '@/routes/admin/holidays';
import type { ListingProps } from '@/types/listing';

type HolidayRow = {
    id: number;
    start_date: string;
    end_date: string;
    days: number;
    name: string;
    description: string | null;
    is_national: boolean;
    applies_to_all: boolean;
    locations: string[];
};

type Props = ListingProps<HolidayRow> & { can: { manage: boolean } };

/** Banyak lokasi diringkas agar baris tetap satu-dua baris di HP. */
const LOCATIONS_SHOWN = 2;

function AppliesTo({ row }: { row: HolidayRow }) {
    if (row.applies_to_all) {
        return <span>Semua lokasi</span>;
    }

    const shown = row.locations.slice(0, LOCATIONS_SHOWN).join(', ');
    const more = row.locations.length - LOCATIONS_SHOWN;

    return (
        <span title={row.locations.join(', ')}>
            {shown}
            {more > 0 && (
                <span className="text-muted-foreground"> +{more} lokasi</span>
            )}
        </span>
    );
}

const columns: DataTableColumn<HolidayRow>[] = [
    {
        key: 'date',
        header: 'Tanggal',
        sortKey: 'date',
        cell: (r) => (
            <span className="grid">
                <span className="tabular-nums">
                    {formatCivilRange(r.start_date, r.end_date)}
                </span>
                {r.days > 1 && (
                    <span className="text-xs text-muted-foreground">
                        {r.days} hari
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.name}</span>
                {r.description && (
                    <span className="text-xs font-normal text-muted-foreground">
                        {r.description}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'is_national',
        header: 'Jenis',
        cell: (r) => (
            <Badge variant={r.is_national ? 'secondary' : 'outline'}>
                {r.is_national ? 'Nasional' : 'Perusahaan'}
            </Badge>
        ),
    },
    {
        key: 'applies',
        header: 'Berlaku',
        cell: (r) => <AppliesTo row={r} />,
    },
];

/** Hari libur (05 §4.9, FR-033, BR-062). */
export default function HolidaysIndex({ rows, listing, can }: Props) {
    const [deleting, setDeleting] = useState<HolidayRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Hari Libur
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Hari Libur" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Hari Libur
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pada hari libur pegawai tidak dihitung alpa, kecuali
                        dijadwalkan masuk lewat pengecualian jadwal.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar hari libur"
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
                                              aria-label={`Ubah hari libur ${r.name}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus hari libur ${r.name}`}
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
                        Hapus hari libur {deleting?.name}?
                    </DialogTitle>
                    <DialogDescription>
                        {deleting &&
                            formatCivilRange(
                                deleting.start_date,
                                deleting.end_date,
                            )}
                        . Presensi yang sudah dihitung untuk tanggal tersebut
                        ikut dihitung ulang otomatis.
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
                                    onError: () =>
                                        toast.error(
                                            'Hari libur tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Hari Libur
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

HolidaysIndex.layout = {
    breadcrumbs: [{ title: 'Hari Libur', href: index() }],
};
