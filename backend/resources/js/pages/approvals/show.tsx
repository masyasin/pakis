import { Head, Link, usePage } from '@inertiajs/react';
import {
    Ban,
    CalendarClock,
    Check,
    ExternalLink,
    FileText,
    Hourglass,
    ListChecks,
    LockKeyhole,
    Stamp,
    UserRound,
    UserRoundCheck,
    Wallet,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import { ApprovalDecisionDialog } from '@/components/pakis/approval-decision-dialog';
import type {
    ApprovalDecision,
    ApprovalDecisionKind,
} from '@/components/pakis/approval-decision-dialog';
import { ApprovalTimeline } from '@/components/pakis/approval-timeline';
import type { ApprovalTimelineStep } from '@/components/pakis/approval-timeline';
import { ActionLabel } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { formatDateTimeIn, formatWaiting } from '@/lib/approval-format';
import { index } from '@/routes/admin/approvals';
import type { ApprovalRow } from './index';

type ApprovalDetail = ApprovalRow & {
    context: Record<string, unknown> | unknown[];
    document_url: string | null;
    decided_at: string | null;
    rule: { name: string };
    acting_for: { id: number; name: string } | null;
    steps: ApprovalTimelineStep[];
};

type Props = {
    approval: ApprovalDetail;
    reauthAmount: string | null;
};

/** Nilai konteks dokumen apa adanya (diisi dokumen pemilik; tanpa data sensitif, Approvable). */
function contextValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    if (Array.isArray(value)) {
        return value.map(contextValue).filter(Boolean).join(', ');
    }
    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value as string | number | boolean);
}

/** Detail permintaan persetujuan (15 §5 `admin.approvals.show`, PA-27). */
export default function ApprovalShow({ approval, reauthAmount }: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [deciding, setDeciding] = useState<ApprovalDecision | null>(null);
    const decide = (kind: ApprovalDecisionKind) =>
        setDeciding({ kind, approval });
    const at = (iso: string | null) =>
        iso ? formatDateTimeIn(iso, timezone) : null;
    const context = Array.isArray(approval.context)
        ? []
        : Object.entries(approval.context).filter(
              ([, v]) => contextValue(v) !== '',
          );

    useBreadcrumbTail([{ title: approval.summary, href: '#' }]);

    return (
        <>
            <Head title={`Persetujuan · ${approval.summary}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref={index()}
                    backLabel="Kotak persetujuan"
                    kind={approval.document_type_label}
                    title={approval.summary}
                    icon={Stamp}
                    status={`approval.${approval.status}`}
                    statusLabel={approval.status_label}
                    badges={
                        approval.escalated && (
                            <StatusBadge
                                status="approval.escalated"
                                label="Dieskalasi"
                                tone="danger"
                            />
                        )
                    }
                    meta={[
                        {
                            icon: UserRound,
                            label: 'Pengaju',
                            value: approval.requester?.name,
                        },
                        {
                            icon: CalendarClock,
                            label: 'Diajukan',
                            value: at(approval.created_at),
                        },
                    ]}
                    highlights={[
                        {
                            icon: Wallet,
                            label: 'Nominal',
                            value: approval.amount_label,
                        },
                        {
                            icon: ListChecks,
                            label: 'Langkah',
                            value: `${approval.current_step} dari ${approval.steps_total}`,
                        },
                        {
                            icon: Hourglass,
                            label: 'Menunggu',
                            value:
                                approval.waiting_since &&
                                formatWaiting(approval.waiting_since),
                            tone: 'warning',
                        },
                    ]}
                    actions={
                        approval.can.decide || approval.can.cancel ? (
                            <>
                                {approval.can.cancel && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => decide('cancel')}
                                        aria-label="Batalkan Permintaan"
                                    >
                                        <Ban aria-hidden="true" />
                                        <ActionLabel>
                                            Batalkan Permintaan
                                        </ActionLabel>
                                    </Button>
                                )}
                                {approval.can.decide && (
                                    <>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => decide('reject')}
                                            aria-label="Tolak"
                                        >
                                            <X aria-hidden="true" />
                                            <ActionLabel>Tolak</ActionLabel>
                                        </Button>
                                        <Button
                                            size="sm"
                                            onClick={() => decide('approve')}
                                            aria-label="Setujui"
                                        >
                                            <Check aria-hidden="true" />
                                            <ActionLabel>Setujui</ActionLabel>
                                        </Button>
                                    </>
                                )}
                            </>
                        ) : undefined
                    }
                />

                {approval.acting_for && (
                    <p
                        role="status"
                        className="flex items-start gap-2 rounded-lg border border-status-info/30 bg-status-info-bg px-3 py-2.5 text-sm text-status-info"
                    >
                        <UserRoundCheck
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Anda memutuskan atas nama{' '}
                            <strong>{approval.acting_for.name}</strong>{' '}
                            (delegasi). Keputusan tercatat atas nama Anda
                            beserta pemberi delegasi.
                        </span>
                    </p>
                )}

                {approval.requires_password && approval.can.decide && (
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <LockKeyhole
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        Nominal {reauthAmount ? `≥ ${reauthAmount}` : 'besar'}:
                        keputusan perlu konfirmasi password.
                    </p>
                )}

                <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                    <div className="grid gap-4">
                        <DetailCard title="Ringkasan" icon={FileText}>
                            <FieldGrid>
                                <Field
                                    label="Pengaju"
                                    value={approval.requester?.name}
                                />
                                <Field
                                    label="Diajukan"
                                    value={at(approval.created_at)}
                                />
                                <Field
                                    label="Alasan persetujuan"
                                    value={approval.reason}
                                    wide
                                />
                                <Field
                                    label="Aturan"
                                    value={approval.rule.name}
                                />
                                <Field
                                    label="Langkah"
                                    value={`${approval.current_step} dari ${approval.steps_total}`}
                                />
                                {approval.waiting_since && (
                                    <Field
                                        label="Menunggu"
                                        value={formatWaiting(
                                            approval.waiting_since,
                                        )}
                                        hint={`Sejak ${at(approval.waiting_since)}`}
                                    />
                                )}
                                {approval.decided_at && (
                                    <Field
                                        label="Selesai"
                                        value={at(approval.decided_at)}
                                    />
                                )}
                            </FieldGrid>
                            {approval.document_url && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-4"
                                    asChild
                                >
                                    <Link href={approval.document_url}>
                                        <ExternalLink aria-hidden="true" />
                                        Buka dokumen
                                    </Link>
                                </Button>
                            )}
                        </DetailCard>

                        {context.length > 0 && (
                            <DetailCard title="Data dokumen" icon={ListChecks}>
                                <FieldGrid>
                                    {context.map(([key, value]) => (
                                        <Field
                                            key={key}
                                            label={key}
                                            value={contextValue(value)}
                                        />
                                    ))}
                                </FieldGrid>
                            </DetailCard>
                        )}
                    </div>

                    <DetailCard title="Langkah persetujuan" icon={ListChecks}>
                        <ApprovalTimeline steps={approval.steps} />
                    </DetailCard>
                </div>
            </div>

            <ApprovalDecisionDialog
                pending={deciding}
                onClose={() => setDeciding(null)}
                reauthAmount={reauthAmount}
            />
        </>
    );
}

ApprovalShow.layout = {
    breadcrumbs: [{ title: 'Persetujuan', href: index() }],
};
