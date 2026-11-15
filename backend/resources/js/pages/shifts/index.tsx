import { Head, Link, router } from '@inertiajs/react';
import { Moon, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
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
import { formatClock } from '@/lib/shift-time';
import { create, destroy, edit, index } from '@/routes/admin/shifts';
import type { ListingProps } from '@/types/listing';

export type ShiftRow = {
    id: number;
    code: string;
    name: string;
    start_time: string;
    end_time: string;
    crosses_midnight: boolean;
    late_tolerance_min: number;
    checkin_open_min: number;
    checkout_close_min: number;
    color: string;
    is_active: boolean;
    employees_count: number | null;
};

type Props = ListingProps<ShiftRow> & { can: { manage: boolean } };

/** Shift (05 §4.9, 07 §3.4 `shifts`, FR-031). */
export default function ShiftsIndex({ rows, listing, can }: Props) {
    const [deleting, setDeleting] = useState<ShiftRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const columns: DataTableColumn<ShiftRow>[] = [
        {
            key: 'code',
            header: 'Kode',
            cell: (s) => (
                <span className="inline-flex items-center gap-2 font-mono text-[0.8rem]">
                    {/* Warna shift dipakai di kalender jadwal (T-035); di sini sebagai penanda. */}
                    <span
                        aria-hidden="true"
                        className="size-3 shrink-0 rounded-full border"
                        style={{ backgroundColor: s.color }}
                    />
                    {s.code}
                </span>
            ),
        },
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            primary: true,
            cell: (s) => <span className="font-medium">{s.name}</span>,
        },
        {
            key: 'start_time',
            header: 'Jam',
            sortKey: 'start_time',
            cell: (s) => (
                <span className="inline-flex flex-wrap items-center gap-2 tabular-nums">
                    {formatClock(s.start_time)}–{formatClock(s.end_time)}
                    {s.crosses_midnight && (
                        <Badge variant="secondary" className="gap-1">
                            <Moon className="size-3" aria-hidden="true" />
                            Lintas hari
                        </Badge>
                    )}
                </span>
            ),
        },
        {
            key: 'late_tolerance_min',
            header: 'Toleransi telat',
            className: 'tabular-nums',
            cell: (s) => `${s.late_tolerance_min} mnt`,
        },
        {
            key: 'employees_count',
            header: 'Pegawai',
            className: 'tabular-nums',
            // Jumlah pegawai baru ada setelah pola jadwal (T-035).
            cell: (s) =>
                s.employees_count === null ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    s.employees_count.toLocaleString('id-ID')
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (s) => (
                <StatusBadge
                    status={s.is_active ? 'shift.active' : 'shift.inactive'}
                />
            ),
        },
    ];

    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Shift
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Shift" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Shift
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Jam kerja yang dipakai jadwal pegawai, beserta batas
                        waktu masuk dan pulang.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar shift"
                    rowHref={can.manage ? (s) => edit(s.id).url : undefined}
                    emptyAction={addButton}
                    toolbarActions={addButton}
                    rowActions={
                        can.manage
                            ? (s) => (
                                  <div className="flex justify-end gap-1">
                                      <Button variant="ghost" size="sm" asChild>
                                          <Link
                                              href={edit(s.id)}
                                              aria-label={`Ubah shift ${s.name}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus shift ${s.name}`}
                                          onClick={(e) => {
                                              e.stopPropagation();
                                              setDeleting(s);
                                          }}
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
                    <DialogTitle>Hapus shift {deleting?.name}?</DialogTitle>
                    <DialogDescription>
                        Hanya shift yang belum pernah dipakai jadwal atau
                        presensi yang bisa dihapus. Bila sudah dipakai,
                        nonaktifkan saja. Tercatat di audit.
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
                                            errors.shift ??
                                                'Shift tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Shift
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ShiftsIndex.layout = {
    breadcrumbs: [{ title: 'Shift', href: index() }],
};
