import { Head, Link } from '@inertiajs/react';
import {
    CalendarDays,
    Check,
    ClipboardPen,
    Download,
    IdCard,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { CorrectionDecisionDialog } from '@/components/pakis/correction-decision-dialog';
import type { CorrectionDecision } from '@/components/pakis/correction-decision-dialog';
import { ActionLabel } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { localDateTime } from '@/lib/correction-format';
import type { CorrectionRow } from '@/lib/correction-format';
import { formatDate } from '@/lib/employee-format';
import { show as showDay } from '@/routes/admin/attendance';
import { attachment, index, show } from '@/routes/admin/attendance-corrections';

type Props = {
    correction: Omit<CorrectionRow, 'can_decide'>;
    day: {
        id: number;
        shift: string | null;
        check_in: string | null;
        check_out: string | null;
        status: string;
        status_label: string;
        is_corrected: boolean;
        visible: boolean;
    } | null;
    can: { decide: boolean; own: boolean };
};

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card p-4 shadow-[0_1px_2px_rgb(0_0_0/0.04)] md:p-5">
            <h2 className="mb-3 text-base font-semibold">{title}</h2>
            {children}
        </section>
    );
}

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex justify-between gap-4 border-b py-1.5 text-sm last:border-0">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right font-medium">{children}</dd>
        </div>
    );
}

/** Detail koreksi (07 §4 "Koreksi (detail)"): pengajuan, presensi tercatat vs usulan, keputusan. */
export default function AttendanceCorrectionShow({
    correction: c,
    day,
    can,
}: Props) {
    const [decision, setDecision] = useState<CorrectionDecision | null>(null);
    useBreadcrumbTail([{ title: c.number, href: show(c.id).url }]);
    const usesIn = c.type !== 'check_out';
    const usesOut = c.type !== 'check_in';
    const approved = c.status === 'approved';

    return (
        <>
            <Head title={`Koreksi ${c.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref={index()}
                    backLabel="Koreksi Presensi"
                    kind="Koreksi presensi"
                    title={c.employee.name}
                    number={c.number}
                    icon={ClipboardPen}
                    status={`correction.${c.status}`}
                    statusLabel={c.status_label}
                    meta={[
                        {
                            icon: IdCard,
                            label: 'Nomor pegawai',
                            value: (
                                <span className="font-mono">
                                    {c.employee.employee_number}
                                </span>
                            ),
                        },
                        {
                            icon: CalendarDays,
                            label: 'Tanggal',
                            value: formatDate(c.work_date),
                        },
                    ]}
                    actions={
                        can.decide ? (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setDecision({
                                            kind: 'reject',
                                            correction: c,
                                        })
                                    }
                                    aria-label="Tolak"
                                >
                                    <X aria-hidden="true" />
                                    <ActionLabel>Tolak</ActionLabel>
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() =>
                                        setDecision({
                                            kind: 'approve',
                                            correction: c,
                                        })
                                    }
                                    aria-label="Setujui Koreksi"
                                >
                                    <Check aria-hidden="true" />
                                    <ActionLabel>Setujui Koreksi</ActionLabel>
                                </Button>
                            </>
                        ) : undefined
                    }
                />
                {can.own && c.status === 'pending' && (
                    <p className="text-sm text-muted-foreground">
                        Ini koreksi milik Anda sendiri, jadi harus diputuskan
                        orang lain.
                    </p>
                )}

                <div className="grid gap-4 md:grid-cols-2">
                    <Card title="Pengajuan">
                        <dl>
                            <Row label="Jenis">
                                {c.type_label}
                                {c.is_manual && ' (presensi manual)'}
                            </Row>
                            <Row label="Alasan">{c.reason.name}</Row>
                            <Row label="Diajukan">
                                {localDateTime(c.submitted_at)}
                            </Row>
                            <Row label="Penyetuju">
                                {c.approver?.name ??
                                    'Kepala lokasi / HRD (tanpa atasan)'}
                            </Row>
                            <Row label="Lampiran">
                                {c.has_attachment ? (
                                    <a
                                        href={attachment(c.id).url}
                                        className="inline-flex items-center gap-1 underline-offset-4 hover:underline"
                                    >
                                        <Download
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        Unduh
                                    </a>
                                ) : (
                                    '–'
                                )}
                            </Row>
                        </dl>
                        <p className="mt-3 text-sm whitespace-pre-line">
                            {c.note}
                        </p>
                    </Card>

                    <Card
                        title={
                            approved
                                ? 'Presensi (sudah dikoreksi)'
                                : 'Presensi tercatat vs usulan'
                        }
                    >
                        <dl>
                            <Row label="Shift">{day?.shift ?? '–'}</Row>
                            {usesIn && (
                                <Row label="Masuk">
                                    <span className="tabular-nums">
                                        {approved
                                            ? (day?.check_in ?? '–')
                                            : `${day?.check_in ?? '–'} → ${c.proposed_check_in ?? '–'}`}
                                    </span>
                                </Row>
                            )}
                            {usesOut && (
                                <Row label="Pulang">
                                    <span className="tabular-nums">
                                        {approved
                                            ? (day?.check_out ?? '–')
                                            : `${day?.check_out ?? '–'} → ${c.proposed_check_out ?? '–'}`}
                                    </span>
                                </Row>
                            )}
                            <Row label="Status hari">
                                {day ? (
                                    <StatusBadge
                                        status={day.status}
                                        label={day.status_label}
                                    />
                                ) : (
                                    'Belum ada baris presensi'
                                )}
                            </Row>
                        </dl>
                        {day?.visible && (
                            <Button
                                variant="link"
                                className="mt-2 h-auto px-0"
                                asChild
                            >
                                <Link href={showDay(day.id)}>
                                    Lihat detail presensi & log asli
                                </Link>
                            </Button>
                        )}
                    </Card>
                </div>

                {c.decided_at && (
                    <Card title="Keputusan">
                        <dl>
                            <Row label="Status">{c.status_label}</Row>
                            <Row label="Oleh">{c.decided_by?.name ?? '–'}</Row>
                            <Row label="Waktu">
                                {localDateTime(c.decided_at)}
                            </Row>
                        </dl>
                        {c.decision_note && (
                            <p className="mt-3 text-sm whitespace-pre-line">
                                {c.decision_note}
                            </p>
                        )}
                    </Card>
                )}
            </div>

            <CorrectionDecisionDialog
                pending={decision}
                onClose={() => setDecision(null)}
            />
        </>
    );
}

AttendanceCorrectionShow.layout = {
    breadcrumbs: [{ title: 'Koreksi Presensi', href: index() }],
};
