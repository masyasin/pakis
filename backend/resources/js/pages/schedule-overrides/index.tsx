import { Head, Link, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { OverrideDialog } from '@/components/schedule/override-dialog';
import type { OverrideTarget } from '@/components/schedule/override-dialog';
import { ScheduleTabs } from '@/components/schedule/schedule-tabs';
import { DayOffLabel, ShiftChip } from '@/components/schedule/shift-chip';
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
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { todayIn } from '@/lib/employee-format';
import { formatCivilDate } from '@/lib/local-date';
import type { EmployeeRef, ShiftSummary } from '@/lib/schedule';
import { show as showEmployee } from '@/routes/admin/employees';
import { destroy, exportMethod } from '@/routes/admin/schedule-overrides';
import { index } from '@/routes/admin/schedules';
import type { ListingProps } from '@/types/listing';

type OverrideRow = {
    id: number;
    date: string;
    employee: EmployeeRef;
    shift: ShiftSummary | null;
    note: string | null;
};

type Props = ListingProps<OverrideRow> & { can: { manage: boolean } };

const columns: DataTableColumn<OverrideRow>[] = [
    {
        key: 'date',
        header: 'Tanggal',
        sortKey: 'date',
        cell: (r) => (
            <span className="whitespace-nowrap tabular-nums">
                {formatCivilDate(r.date)}
            </span>
        ),
    },
    {
        key: 'employee_name',
        header: 'Pegawai',
        sortKey: 'employee_name',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <Link
                    href={showEmployee(
                        { employee: r.employee.id },
                        { query: { tab: 'jadwal' } },
                    )}
                    className="font-medium hover:underline"
                >
                    {r.employee.name}
                </Link>
                <span className="font-mono text-xs text-muted-foreground">
                    {r.employee.number}
                </span>
            </span>
        ),
    },
    {
        key: 'shift',
        header: 'Jadwal',
        cell: (r) =>
            r.shift ? (
                <span className="inline-flex items-center gap-2">
                    <ShiftChip shift={r.shift} />
                    <span className="text-sm text-muted-foreground">
                        {r.shift.name}
                    </span>
                </span>
            ) : (
                <DayOffLabel className="text-sm" />
            ),
    },
    {
        key: 'note',
        header: 'Catatan',
        cell: (r) =>
            r.note ? (
                <span className="text-sm">{r.note}</span>
            ) : (
                <span className="text-sm text-muted-foreground">—</span>
            ),
    },
];

/** Jadwal · Pengecualian (05 §4.9, 07 §3.4 `schedule_overrides`, FR-032, BR-061/BR-062). */
export default function ScheduleOverridesIndex({ rows, listing, can }: Props) {
    const { timezone } = usePage().props;
    const [target, setTarget] = useState<OverrideTarget | null>(null);
    const [deleting, setDeleting] = useState<OverrideRow | null>(null);
    const [processing, setProcessing] = useState(false);
    useBreadcrumbTail([{ title: 'Pengecualian', href: '#' }]);

    const addButton = can.manage ? (
        <Button
            onClick={() =>
                setTarget({ employee: null, date: null, existing: null })
            }
        >
            <Plus aria-hidden="true" />
            Tambah Pengecualian
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Jadwal · Pengecualian" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="space-y-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Jadwal
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Pengecualian per tanggal menang atas pola dan hari
                            libur: ganti shift, atau liburkan pegawai.
                        </p>
                    </div>
                    <ScheduleTabs active="pengecualian" />
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pengecualian jadwal"
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
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Ubah pengecualian ${r.employee.name} ${formatCivilDate(r.date)}`}
                                          onClick={() =>
                                              setTarget({
                                                  employee: r.employee,
                                                  date: r.date,
                                                  existing: {
                                                      id: r.id,
                                                      shiftId:
                                                          r.shift?.id ?? null,
                                                      note: r.note,
                                                  },
                                              })
                                          }
                                      >
                                          <Pencil aria-hidden="true" />
                                          Ubah
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          aria-label={`Hapus pengecualian ${r.employee.name} ${formatCivilDate(r.date)}`}
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

            <OverrideDialog
                target={target}
                onClose={() => setTarget(null)}
                defaultDate={todayIn(timezone)}
            />

            <Dialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
            >
                <DialogContent>
                    <DialogTitle>Hapus pengecualian jadwal?</DialogTitle>
                    <DialogDescription>
                        {deleting &&
                            `${deleting.employee.name}, ${formatCivilDate(deleting.date)}. `}
                        Tanggal itu kembali mengikuti pola dan hari libur.
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
                                            'Pengecualian tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setProcessing(false);
                                        setDeleting(null);
                                    },
                                });
                            }}
                        >
                            {processing && <Spinner />}
                            Hapus Pengecualian
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ScheduleOverridesIndex.layout = {
    breadcrumbs: [{ title: 'Jadwal', href: index() }],
};
