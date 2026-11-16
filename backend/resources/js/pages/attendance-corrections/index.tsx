import { Head } from '@inertiajs/react';
import { Check, ClipboardPen, Paperclip, X } from 'lucide-react';
import { useState } from 'react';
import { CorrectionDecisionDialog } from '@/components/pakis/correction-decision-dialog';
import type { CorrectionDecision } from '@/components/pakis/correction-decision-dialog';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { ManualAttendanceDialog } from '@/components/pakis/manual-attendance-dialog';
import type { ManualAttendanceOptions } from '@/components/pakis/manual-attendance-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { localDateTime, proposedTimes } from '@/lib/correction-format';
import type { CorrectionRow } from '@/lib/correction-format';
import { formatDate } from '@/lib/employee-format';
import {
    exportMethod,
    index,
    show,
} from '@/routes/admin/attendance-corrections';
import type { ListingProps } from '@/types/listing';

type Props = ListingProps<CorrectionRow> & {
    manual: ManualAttendanceOptions | null;
    can: { manual: boolean; export: boolean };
};

const dash = <span className="text-muted-foreground">–</span>;

const columns: DataTableColumn<CorrectionRow>[] = [
    {
        key: 'number',
        header: 'Nomor',
        className: 'font-mono text-xs whitespace-nowrap',
        cell: (r) => (
            <span className="inline-flex items-center gap-1">
                {r.number}
                {r.has_attachment && (
                    <Paperclip
                        className="size-3.5 text-muted-foreground"
                        aria-label="Ada lampiran"
                    />
                )}
            </span>
        ),
    },
    {
        key: 'submitted_at',
        header: 'Diajukan',
        sortKey: 'created_at',
        className: 'whitespace-nowrap tabular-nums',
        cell: (r) => localDateTime(r.submitted_at),
    },
    {
        key: 'employee',
        header: 'Pegawai',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.employee.name}</span>
                <span className="font-mono text-xs text-muted-foreground">
                    {r.employee.employee_number}
                </span>
            </span>
        ),
    },
    {
        key: 'work_date',
        header: 'Tanggal kerja',
        sortKey: 'work_date',
        className: 'whitespace-nowrap',
        cell: (r) => formatDate(r.work_date),
    },
    {
        key: 'type',
        header: 'Jenis',
        cell: (r) => (
            <span className="grid">
                <span>{r.type_label}</span>
                {r.is_manual && (
                    <span className="text-xs text-muted-foreground">
                        Presensi manual
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'proposed',
        header: 'Jam usulan',
        className: 'tabular-nums whitespace-nowrap',
        cell: (r) => proposedTimes(r),
    },
    { key: 'reason', header: 'Alasan', cell: (r) => r.reason.name },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={`correction.${r.status}`}
                label={r.status_label}
            />
        ),
    },
    {
        key: 'approver',
        header: 'Penyetuju',
        cell: (r) => r.approver?.name ?? dash,
    },
];

/** Koreksi presensi (05 §4.10, FR-038, 07 §3.4 `attendance_corrections`). Default: menunggu. */
export default function AttendanceCorrectionsIndex({
    rows,
    listing,
    manual,
    can,
}: Props) {
    const [decision, setDecision] = useState<CorrectionDecision | null>(null);
    const [manualOpen, setManualOpen] = useState(false);

    return (
        <>
            <Head title="Koreksi Presensi" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Koreksi Presensi
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Pengajuan koreksi jam masuk/pulang dari Pakis Pro.
                            Yang disetujui langsung mengganti jam presensi; log
                            asli tetap tersimpan.
                        </p>
                    </div>
                    {can.manual && manual && (
                        <Button onClick={() => setManualOpen(true)}>
                            <ClipboardPen aria-hidden="true" />
                            Isi presensi manual
                        </Button>
                    )}
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar koreksi"
                    rowHref={(r) => show(r.id).url}
                    toolbarActions={
                        can.export ? (
                            <ExportButton
                                href={exportMethod().url}
                                total={listing.pagination.total}
                            />
                        ) : undefined
                    }
                    rowActions={(r) =>
                        r.can_decide ? (
                            <div className="flex flex-wrap justify-end gap-1">
                                <Button
                                    size="sm"
                                    aria-label={`Setujui koreksi ${r.number}`}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setDecision({
                                            kind: 'approve',
                                            correction: r,
                                        });
                                    }}
                                >
                                    <Check aria-hidden="true" />
                                    Setujui
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Tolak koreksi ${r.number}`}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        setDecision({
                                            kind: 'reject',
                                            correction: r,
                                        });
                                    }}
                                >
                                    <X aria-hidden="true" />
                                    Tolak
                                </Button>
                            </div>
                        ) : null
                    }
                />
            </div>

            <CorrectionDecisionDialog
                pending={decision}
                onClose={() => setDecision(null)}
            />
            {manual && (
                <ManualAttendanceDialog
                    open={manualOpen}
                    onOpenChange={setManualOpen}
                    options={manual}
                />
            )}
        </>
    );
}

AttendanceCorrectionsIndex.layout = {
    breadcrumbs: [{ title: 'Koreksi Presensi', href: index() }],
};
