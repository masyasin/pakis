import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    ListChecks,
    Pencil,
    Play,
    RefreshCw,
    SkipForward,
    UserPlus,
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
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { cn } from '@/lib/utils';
import { show as showEmployee } from '@/routes/admin/employees';
import {
    cancel,
    errors as errorFile,
    index,
    mapping,
    process,
} from '@/routes/admin/imports';
import { index as rowsIndex } from '@/routes/admin/imports/rows';
import type { ListingProps } from '@/types/listing';

type RowError = { field: string; label: string; message: string };

type ImportRowItem = {
    id: number;
    row_number: number;
    status: string;
    data: Record<string, unknown>;
    errors: RowError[];
    warnings: string[];
    employee_id: number | null;
};

type Props = ListingProps<ImportRowItem> & {
    batch: ImportBatchSummary;
    mappedFields: { key: string; label: string }[];
    can: {
        remap: boolean;
        cancel: boolean;
        process: boolean;
        download_errors: boolean;
        view_employee: boolean;
        update_existing: boolean;
    };
};

/** Interval cek ulang saat validasi/proses berjalan di antrean. */
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

function buildColumns(
    canViewEmployee: boolean,
): DataTableColumn<ImportRowItem>[] {
    return [
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
            cell: (r) => {
                const name = text(r.data.full_name) ?? '–';

                return (
                    <span className="grid gap-0.5">
                        <Cell error={hasError(r, 'full_name')}>
                            {canViewEmployee && r.employee_id !== null ? (
                                <Link
                                    href={showEmployee(r.employee_id)}
                                    className="font-medium text-primary hover:underline"
                                >
                                    {name}
                                </Link>
                            ) : (
                                name
                            )}
                        </Cell>
                        <span className="text-xs text-muted-foreground">
                            {[r.data.position, r.data.location]
                                .map(text)
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    </span>
                );
            },
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
            header: 'Pesan',
            className: 'min-w-[16rem]',
            cell: (r) =>
                r.errors.length === 0 && r.warnings.length === 0 ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    <ul className="space-y-1 text-sm">
                        {r.errors.map((e, i) => {
                            const value = text(r.data[e.field]);

                            return (
                                <li key={`e${i}`} className="text-destructive">
                                    <span className="font-medium">
                                        {e.label}
                                    </span>
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
                        {r.warnings.map((w, i) => (
                            <li
                                key={`w${i}`}
                                className="flex items-start gap-1.5 text-status-warning"
                            >
                                <AlertTriangle
                                    className="mt-0.5 size-3.5 shrink-0"
                                    aria-hidden="true"
                                />
                                {w}
                            </li>
                        ))}
                    </ul>
                ),
        },
    ];
}

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

const icon = (Icon: typeof ListChecks) => (
    <Icon className="size-5" aria-hidden="true" />
);

function DownloadErrors({ batchId }: { batchId: number }) {
    return (
        <Button variant="outline" asChild>
            {/* Unduhan berkas, bukan kunjungan Inertia. */}
            <a href={errorFile(batchId).url}>
                <Download aria-hidden="true" />
                Unduh baris galat
            </a>
        </Button>
    );
}

/**
 * Impor pegawai langkah 3–4 (07 §4, BR-054): hasil validasi per baris, proses ke data pegawai
 * (T-028), lalu laporan akhir di halaman yang sama.
 */
export default function ImportRows({
    rows,
    listing,
    batch,
    mappedFields,
    can,
}: Props) {
    const validating = batch.status === 'mapped' && batch.failure === null;
    const processing = batch.status === 'processing';
    const finished = batch.status === 'completed' || batch.status === 'failed';
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [cancelling, setCancelling] = useState(false);
    const [confirmProcess, setConfirmProcess] = useState(false);
    const [updateExisting, setUpdateExisting] = useState(false);
    const [submitting, setSubmitting] = useState(false);

    const title = finished
        ? 'Laporan impor'
        : processing
          ? 'Memproses impor'
          : 'Hasil validasi';
    useBreadcrumbTail([
        { title: batch.original_name, href: rowsIndex(batch.id).url },
        { title, href: '#' },
    ]);

    useEffect(() => {
        if (!validating && !processing) {
            return;
        }
        const timer = window.setInterval(
            () => router.reload({ only: ['batch', 'rows', 'listing', 'can'] }),
            POLL_MS,
        );

        return () => window.clearInterval(timer);
    }, [validating, processing]);

    const columns = buildColumns(can.view_employee);

    return (
        <>
            <Head title={`${title} ${batch.original_name}`} />
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
                            {title}
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
                    {(can.remap || can.cancel) && (
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
                    )}
                </div>

                <ImportSteps
                    current={processing || finished ? 4 : 3}
                    complete={batch.status === 'completed'}
                />

                {batch.failure && (
                    <p className="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        <AlertTriangle
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {batch.failure}
                    </p>
                )}

                {validating || processing ? (
                    <div
                        className="flex items-center gap-3 rounded-xl border bg-card p-5"
                        role="status"
                    >
                        <Spinner />
                        <span>
                            {validating
                                ? `Memeriksa ${batch.total_rows.toLocaleString('id-ID')} baris…`
                                : `Menyimpan ${batch.valid_rows.toLocaleString('id-ID')} baris ke data pegawai…`}{' '}
                            Halaman ini diperbarui otomatis
                            {processing &&
                                ', dan Anda akan mendapat notifikasi saat selesai'}
                            .
                        </span>
                    </div>
                ) : (
                    <>
                        {finished ? (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 sm:gap-3">
                                <Stat
                                    label="Dibuat"
                                    value={batch.created_rows}
                                    tone="bg-primary/10 text-primary"
                                    icon={icon(UserPlus)}
                                />
                                <Stat
                                    label="Diperbarui"
                                    value={batch.updated_rows}
                                    tone="bg-status-info-bg text-status-info"
                                    icon={icon(RefreshCw)}
                                />
                                <Stat
                                    label="Dilewati"
                                    value={batch.skipped_rows}
                                    tone="bg-muted text-muted-foreground"
                                    icon={icon(SkipForward)}
                                />
                                <Stat
                                    label="Galat"
                                    value={batch.error_rows}
                                    tone="bg-destructive/10 text-destructive"
                                    icon={icon(AlertTriangle)}
                                />
                            </div>
                        ) : (
                            <div className="grid grid-cols-3 gap-2 sm:gap-3">
                                <Stat
                                    label="Total baris"
                                    value={batch.total_rows}
                                    tone="bg-muted text-muted-foreground"
                                    icon={icon(ListChecks)}
                                />
                                <Stat
                                    label="Valid"
                                    value={batch.valid_rows}
                                    tone="bg-primary/10 text-primary"
                                    icon={icon(CheckCircle2)}
                                />
                                <Stat
                                    label="Galat"
                                    value={batch.error_rows}
                                    tone="bg-destructive/10 text-destructive"
                                    icon={icon(AlertTriangle)}
                                />
                            </div>
                        )}

                        {batch.status === 'validated' && (
                            <div className="flex flex-col gap-3 rounded-xl border border-dashed bg-muted/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                                <p className="text-sm text-muted-foreground">
                                    {batch.valid_rows === 0
                                        ? 'Tidak ada baris valid. Perbaiki berkas lalu unggah ulang.'
                                        : batch.error_rows > 0
                                          ? `${batch.valid_rows.toLocaleString('id-ID')} baris valid siap disimpan. Baris galat dilewati; unduh, perbaiki, lalu unggah ulang nanti.`
                                          : 'Semua baris valid dan siap disimpan ke data pegawai.'}
                                </p>
                                <div className="grid shrink-0 gap-2 sm:flex">
                                    {can.download_errors && (
                                        <DownloadErrors batchId={batch.id} />
                                    )}
                                    <Button
                                        disabled={!can.process}
                                        onClick={() => setConfirmProcess(true)}
                                    >
                                        <Play aria-hidden="true" />
                                        Proses impor
                                    </Button>
                                </div>
                            </div>
                        )}

                        {finished && (
                            <div
                                className={cn(
                                    'flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between',
                                    batch.status === 'failed'
                                        ? 'border-destructive/30 bg-destructive/5'
                                        : 'bg-card',
                                )}
                            >
                                <div className="space-y-1 text-sm">
                                    <p
                                        className={cn(
                                            'flex items-center gap-2 font-medium',
                                            batch.status === 'failed'
                                                ? 'text-destructive'
                                                : 'text-foreground',
                                        )}
                                    >
                                        {batch.status === 'failed' ? (
                                            <AlertTriangle
                                                className="size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        ) : (
                                            <CheckCircle2
                                                className="size-4 shrink-0 text-primary"
                                                aria-hidden="true"
                                            />
                                        )}
                                        {batch.status === 'failed'
                                            ? 'Proses berhenti di tengah jalan. Baris yang sudah diproses tetap tersimpan; unggah ulang berkas untuk melanjutkan sisanya (data tidak akan ganda).'
                                            : 'Impor selesai.'}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {batch.completed_at_local &&
                                            `Selesai ${batch.completed_at_local}. `}
                                        {batch.update_existing
                                            ? 'Pegawai yang sudah terdaftar diperbarui.'
                                            : 'Pegawai yang sudah terdaftar dilewati.'}{' '}
                                        Lokasi & jabatan pegawai lama tidak
                                        diubah otomatis.
                                    </p>
                                </div>
                                {can.download_errors && (
                                    <DownloadErrors batchId={batch.id} />
                                )}
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

            <Dialog open={confirmProcess} onOpenChange={setConfirmProcess}>
                <DialogContent>
                    <DialogTitle>Proses impor ini?</DialogTitle>
                    <DialogDescription>
                        {batch.valid_rows.toLocaleString('id-ID')} baris valid
                        disimpan ke data pegawai. Pegawai baru dibuat beserta
                        penugasan utamanya.
                        {batch.error_rows > 0 &&
                            ` ${batch.error_rows.toLocaleString('id-ID')} baris galat dilewati.`}
                    </DialogDescription>
                    <div className="flex items-start gap-3 rounded-lg border bg-muted/30 p-3">
                        <Checkbox
                            id="update-existing"
                            checked={updateExisting && can.update_existing}
                            disabled={!can.update_existing}
                            onCheckedChange={(v) =>
                                setUpdateExisting(v === true)
                            }
                            className="mt-0.5"
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="update-existing">
                                Perbarui data yang sudah ada
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Pegawai yang sudah terdaftar (NIK, no. pegawai,
                                atau nama + tanggal lahir sama) diperbarui
                                dengan isi sel yang tidak kosong. Bila tidak
                                dicentang, baris itu dilewati. Lokasi & jabatan
                                pegawai lama tidak pernah diubah otomatis.
                                {!can.update_existing &&
                                    ' Anda tidak punya izin mengubah data pegawai, jadi opsi ini tidak tersedia.'}
                            </p>
                        </div>
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Kembali</Button>
                        </DialogClose>
                        <Button
                            disabled={submitting}
                            onClick={() =>
                                router.post(
                                    process(batch.id).url,
                                    {
                                        update_existing:
                                            updateExisting &&
                                            can.update_existing,
                                    },
                                    {
                                        onStart: () => setSubmitting(true),
                                        onFinish: () => {
                                            setSubmitting(false);
                                            setConfirmProcess(false);
                                        },
                                    },
                                )
                            }
                        >
                            {submitting ? (
                                <Spinner />
                            ) : (
                                <Play aria-hidden="true" />
                            )}
                            Proses {batch.valid_rows.toLocaleString('id-ID')}{' '}
                            baris
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

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
    breadcrumbs: [{ title: 'Impor Pegawai', href: index() }],
};
