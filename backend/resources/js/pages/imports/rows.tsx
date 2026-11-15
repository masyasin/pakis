import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    FileSpreadsheet,
    ListChecks,
    Pencil,
    Play,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ImportSteps } from '@/components/import/import-steps';
import type { ImportBatchSummary } from '@/components/import/import-steps';
import { DataTable } from '@/components/pakis/data-table';
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
import { cn } from '@/lib/utils';
import { cancel, index, mapping } from '@/routes/admin/imports';
import type { ListingProps } from '@/types/listing';

type RowError = { field: string; label: string; message: string };

type ImportRowItem = {
    id: number;
    row_number: number;
    status: string;
    data: Record<string, unknown>;
    errors: RowError[];
};

type Props = ListingProps<ImportRowItem> & {
    batch: ImportBatchSummary;
    mappedFields: { key: string; label: string }[];
    can: { remap: boolean; cancel: boolean };
};

/** Interval cek ulang saat validasi berjalan di antrean. */
const POLL_MS = 2500;

const text = (value: unknown): string | null =>
    typeof value === 'string' && value !== '' ? value : null;

function hasError(row: ImportRowItem, field: string): boolean {
    return row.errors.some((e) => e.field === field);
}

function Cell({ error, children }: { error: boolean; children: ReactNode }) {
    return (
        <span
            className={cn(
                error &&
                    'rounded bg-destructive/10 px-1 font-medium text-destructive',
            )}
        >
            {children}
        </span>
    );
}

const columns: DataTableColumn<ImportRowItem>[] = [
    {
        key: 'row_number',
        header: 'Baris',
        sortKey: 'row_number',
        className: 'w-16 tabular-nums',
        cell: (r) => r.row_number,
    },
    {
        key: 'name',
        header: 'Nama',
        primary: true,
        cell: (r) => (
            <span className="grid gap-0.5">
                <Cell error={hasError(r, 'full_name')}>
                    {text(r.data.full_name) ?? '–'}
                </Cell>
                <span className="text-xs text-muted-foreground">
                    {[r.data.position, r.data.location]
                        .map(text)
                        .filter(Boolean)
                        .join(' · ')}
                </span>
            </span>
        ),
    },
    {
        key: 'employee_number',
        header: 'No. pegawai',
        cell: (r) => (
            <Cell error={hasError(r, 'employee_number')}>
                <span className="font-mono text-xs">
                    {text(r.data.employee_number) ?? 'otomatis'}
                </span>
            </Cell>
        ),
    },
    {
        key: 'nik',
        header: 'NIK',
        cell: (r) => (
            <Cell error={hasError(r, 'nik')}>
                <span className="font-mono text-xs">
                    {text(r.data.nik) ?? '–'}
                </span>
            </Cell>
        ),
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => <StatusBadge status={`import_row.${r.status}`} />,
    },
    {
        key: 'errors',
        header: 'Pesan galat',
        className: 'min-w-[16rem]',
        cell: (r) =>
            r.errors.length === 0 ? (
                <span className="text-muted-foreground">–</span>
            ) : (
                <ul className="space-y-1 text-sm">
                    {r.errors.map((e, i) => {
                        const value = text(r.data[e.field]);

                        return (
                            <li key={i} className="text-destructive">
                                <span className="font-medium">{e.label}</span>
                                {value && e.field !== 'nik' && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        «{value}»
                                    </span>
                                )}
                                : {e.message}
                            </li>
                        );
                    })}
                </ul>
            ),
    },
];

function Stat({
    label,
    value,
    icon,
    tone,
}: {
    label: string;
    value: number;
    icon: ReactNode;
    tone: string;
}) {
    return (
        <div className="flex flex-col items-start gap-2 rounded-xl border bg-card p-3 shadow-[0_1px_2px_rgb(0_0_0/0.04),0_4px_12px_-6px_rgb(0_0_0/0.08)] sm:flex-row sm:items-center sm:gap-3 sm:p-4">
            <span
                className={cn(
                    'grid size-8 place-items-center rounded-lg sm:size-10',
                    tone,
                )}
            >
                {icon}
            </span>
            <span>
                <span className="block text-xs text-muted-foreground">
                    {label}
                </span>
                <span className="text-lg font-bold tabular-nums sm:text-xl">
                    {value.toLocaleString('id-ID')}
                </span>
            </span>
        </div>
    );
}

/** Impor pegawai langkah 3: hasil validasi per baris (07 §4, BR-054). */
export default function ImportRows({
    rows,
    listing,
    batch,
    mappedFields,
    can,
}: Props) {
    const validating = batch.status === 'mapped' && batch.failure === null;
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [cancelling, setCancelling] = useState(false);

    useEffect(() => {
        if (!validating) {
            return;
        }
        const timer = window.setInterval(
            () => router.reload({ only: ['batch', 'rows', 'listing'] }),
            POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [validating]);

    return (
        <>
            <Head title={`Validasi ${batch.original_name}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Link
                    href={index()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Riwayat impor
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="flex flex-wrap items-center gap-2 text-[1.4rem] leading-tight font-bold">
                            Hasil validasi
                            <StatusBadge
                                status={`import.${batch.status}`}
                                label={batch.status_label}
                            />
                        </h1>
                        <p className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
                            <FileSpreadsheet
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                {batch.original_name}
                            </span>
                            {mappedFields.length > 0 && (
                                <span className="hidden shrink-0 sm:inline">
                                    · {mappedFields.length} kolom terpetakan
                                </span>
                            )}
                        </p>
                    </div>
                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                        {can.remap && (
                            <Button variant="outline" asChild>
                                <Link href={mapping(batch.id)}>
                                    <Pencil aria-hidden="true" />
                                    Ubah pemetaan
                                </Link>
                            </Button>
                        )}
                        {can.cancel && (
                            <Button
                                variant="outline"
                                className="border-destructive/40 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                onClick={() => setConfirmCancel(true)}
                            >
                                <XCircle aria-hidden="true" />
                                Batalkan
                            </Button>
                        )}
                    </div>
                </div>

                <ImportSteps current={3} />

                {batch.failure && (
                    <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        <AlertTriangle
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {batch.failure}
                    </p>
                )}

                {validating ? (
                    <div
                        className="flex items-center gap-3 rounded-xl border bg-card p-5"
                        role="status"
                    >
                        <Spinner />
                        <span>
                            Memeriksa {batch.total_rows.toLocaleString('id-ID')}{' '}
                            baris… Halaman ini diperbarui otomatis.
                        </span>
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-3 gap-2 sm:gap-3">
                            <Stat
                                label="Total baris"
                                value={batch.total_rows}
                                tone="bg-muted text-muted-foreground"
                                icon={
                                    <ListChecks
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                }
                            />
                            <Stat
                                label="Valid"
                                value={batch.valid_rows}
                                tone="bg-primary/10 text-primary"
                                icon={
                                    <CheckCircle2
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                }
                            />
                            <Stat
                                label="Galat"
                                value={batch.error_rows}
                                tone="bg-destructive/10 text-destructive"
                                icon={
                                    <AlertTriangle
                                        className="size-5"
                                        aria-hidden="true"
                                    />
                                }
                            />
                        </div>

                        {batch.status === 'validated' && (
                            <div className="flex flex-col gap-3 rounded-xl border border-dashed bg-muted/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">
                                    {batch.error_rows > 0
                                        ? 'Perbaiki baris galat di berkas lalu unggah ulang, atau lanjutkan nanti dengan hanya baris valid.'
                                        : 'Semua baris valid.'}{' '}
                                    Langkah 4 (simpan ke data pegawai) menyusul.
                                </p>
                                <Button disabled className="shrink-0">
                                    <Play aria-hidden="true" />
                                    Proses impor
                                </Button>
                            </div>
                        )}

                        <DataTable
                            rows={rows}
                            listing={listing}
                            columns={columns}
                            title="Baris berkas"
                        />
                    </>
                )}
            </div>

            <Dialog open={confirmCancel} onOpenChange={setConfirmCancel}>
                <DialogContent>
                    <DialogTitle>Batalkan impor ini?</DialogTitle>
                    <DialogDescription>
                        Berkas yang diunggah dihapus dari server dan impor tidak
                        bisa dilanjutkan. Tidak ada data pegawai yang berubah.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Kembali</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={cancelling}
                            onClick={() =>
                                router.post(
                                    cancel(batch.id).url,
                                    {},
                                    {
                                        onStart: () => setCancelling(true),
                                        onFinish: () => {
                                            setCancelling(false);
                                            setConfirmCancel(false);
                                        },
                                    },
                                )
                            }
                        >
                            {cancelling && <Spinner />}
                            Batalkan impor
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

ImportRows.layout = {
    breadcrumbs: [
        { title: 'Impor Pegawai', href: index() },
        { title: 'Hasil validasi', href: '#' },
    ],
};
