import { Head, Link, router } from '@inertiajs/react';
import { Ban, Check, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { DataTable } from '@/components/pakis/data-table';
import { FieldTripDecisionDialog } from '@/components/pakis/field-trip-decision-dialog';
import type { FieldTripDecision } from '@/components/pakis/field-trip-decision-dialog';
import type { DataTableColumn } from '@/components/pakis/data-table';
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
import { formatCivilRange } from '@/lib/local-date';
import { cancel, create, edit, index } from '@/routes/admin/field-trips';
import type { ListingProps } from '@/types/listing';

export type FieldTripRow = {
    id: number;
    number: string;
    start_date: string;
    end_date: string;
    days: number;
    destination: string;
    purpose: string | null;
    rule: { value: string; label: string };
    status: { value: string; label: string };
    employees: { id: number; name: string; number: string }[];
    can?: { update: boolean; cancel: boolean; decide?: boolean };
    /** Diajukan pegawai dari Pakis Pro (T-036A bagian 2). */
    is_request?: boolean;
};

type Props = ListingProps<FieldTripRow> & {
    can: { manage: boolean; approve?: boolean };
};

/** Banyak pegawai diringkas agar baris tetap pendek di HP. */
const NAMES_SHOWN = 2;

export function TripEmployees({
    employees,
}: {
    employees: FieldTripRow['employees'];
}) {
    const shown = employees
        .slice(0, NAMES_SHOWN)
        .map((e) => e.name)
        .join(', ');
    const more = employees.length - NAMES_SHOWN;

    return (
        <span title={employees.map((e) => e.name).join(', ')}>
            {shown}
            {more > 0 && (
                <span className="text-muted-foreground"> +{more}</span>
            )}
        </span>
    );
}

const columns: DataTableColumn<FieldTripRow>[] = [
    {
        key: 'start_date',
        header: 'Tanggal',
        sortKey: 'start_date',
        cell: (r) => (
            <span className="grid">
                <span className="whitespace-nowrap tabular-nums">
                    {formatCivilRange(r.start_date, r.end_date)}
                </span>
                <span className="text-xs text-muted-foreground">
                    {r.days} hari
                </span>
            </span>
        ),
    },
    {
        key: 'destination',
        header: 'Tujuan',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.destination}</span>
                <span className="font-mono text-xs font-normal text-muted-foreground">
                    {r.number}
                </span>
                {r.is_request && (
                    <span className="text-xs text-muted-foreground">
                        Diajukan dari Pakis Pro
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'employees',
        header: 'Pegawai',
        cell: (r) => <TripEmployees employees={r.employees} />,
    },
    {
        key: 'rule',
        header: 'Aturan lokasi',
        cell: (r) => <span className="text-sm">{r.rule.label}</span>,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={`field_trip.${r.status.value}`}
                label={r.status.label}
            />
        ),
    },
];

/** Tugas luar kota (05 §4.9, 07 §3.4 `field_trips`, BR-071). */
export default function FieldTripsIndex({ rows, listing, can }: Props) {
    const [cancelling, setCancelling] = useState<FieldTripRow | null>(null);
    const [deciding, setDeciding] = useState<FieldTripDecision | null>(null);
    const decide = (kind: FieldTripDecision['kind'], r: FieldTripRow) =>
        setDeciding({
            kind,
            trip: {
                id: r.id,
                number: r.number,
                destination: r.destination,
                who: r.employees.map((e) => e.name).join(', '),
            },
        });
    const [processing, setProcessing] = useState(false);

    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Tugas
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Tugas Luar Kota" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Tugas Luar Kota
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Selama tanggal tugas, pegawai boleh presensi sesuai
                        aturan tugas di samping lokasi biasanya. Jadwal & shift
                        tidak berubah.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar tugas luar kota"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={
                        can.manage || can.approve
                            ? (r) => (
                                  <div className="flex justify-end gap-1">
                                      {r.can?.decide && (
                                          <>
                                              <Button
                                                  size="sm"
                                                  aria-label={`Setujui tugas ${r.number}`}
                                                  onClick={() =>
                                                      decide('approve', r)
                                                  }
                                              >
                                                  <Check aria-hidden="true" />
                                                  Setujui
                                              </Button>
                                              <Button
                                                  size="sm"
                                                  variant="ghost"
                                                  aria-label={`Tolak pengajuan ${r.number}`}
                                                  onClick={() =>
                                                      decide('reject', r)
                                                  }
                                              >
                                                  <X aria-hidden="true" />
                                                  Tolak
                                              </Button>
                                          </>
                                      )}
                                      {r.can?.update && (
                                          <Button
                                              variant="ghost"
                                              size="sm"
                                              asChild
                                          >
                                              <Link
                                                  href={edit(r.id)}
                                                  aria-label={`Ubah tugas ${r.number}`}
                                              >
                                                  <Pencil aria-hidden="true" />
                                                  Ubah
                                              </Link>
                                          </Button>
                                      )}
                                      {r.can?.cancel && (
                                          <Button
                                              variant="ghost"
                                              size="sm"
                                              aria-label={`Batalkan tugas ${r.number}`}
                                              onClick={() => setCancelling(r)}
                                          >
                                              <Ban aria-hidden="true" />
                                          </Button>
                                      )}
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            <FieldTripDecisionDialog
                pending={deciding}
                onClose={() => setDeciding(null)}
            />

            <Dialog
                open={cancelling !== null}
                onOpenChange={(open) => !open && setCancelling(null)}
            >
                <DialogContent>
                    <DialogTitle>
                        Batalkan tugas {cancelling?.number}?
                    </DialogTitle>
                    <DialogDescription>
                        {cancelling &&
                            `${cancelling.destination}, ${formatCivilRange(cancelling.start_date, cancelling.end_date)}. `}
                        Setelah dibatalkan, pegawai kembali hanya bisa presensi
                        di lokasi biasanya. Presensi yang sudah tercatat tidak
                        berubah.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Tidak</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={() => {
                                if (!cancelling) {
                                    return;
                                }
                                router.post(
                                    cancel(cancelling.id).url,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onStart: () => setProcessing(true),
                                        onError: () =>
                                            toast.error(
                                                'Tugas tidak bisa dibatalkan.',
                                            ),
                                        onFinish: () => {
                                            setProcessing(false);
                                            setCancelling(null);
                                        },
                                    },
                                );
                            }}
                        >
                            {processing && <Spinner />}
                            Batalkan Tugas
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

FieldTripsIndex.layout = {
    breadcrumbs: [{ title: 'Tugas Luar Kota', href: index() }],
};
