import { usePage } from '@inertiajs/react';
import {
    CircleCheck,
    CircleDashed,
    CircleDot,
    CircleSlash,
    CircleX,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDateTimeIn } from '@/lib/approval-format';
import { cn } from '@/lib/utils';

export type ApprovalTimelineStep = {
    step_order: number;
    approver_type: string;
    approver_label: string;
    escalation_label: string;
    status: string;
    status_label: string;
    activated_at: string | null;
    escalate_at: string | null;
    escalated: boolean;
    decided_by: { id: number; name: string } | null;
    acting_for: { id: number; name: string } | null;
    decided_at: string | null;
    note: string | null;
    /** Calon penyetuju langkah yang sedang menunggu (maks. 10 nama, termasuk penerima delegasi). */
    waiting_for: string[];
};

const ICON: Record<string, { icon: LucideIcon; className: string }> = {
    approved: { icon: CircleCheck, className: 'text-status-success' },
    rejected: { icon: CircleX, className: 'text-status-danger' },
    pending: { icon: CircleDot, className: 'text-status-warning' },
    skipped: { icon: CircleSlash, className: 'text-muted-foreground' },
    waiting: { icon: CircleDashed, className: 'text-muted-foreground' },
};

/**
 * Riwayat langkah persetujuan (komponen `ApprovalTimeline`, 15 §7.2): urut langkah, siapa yang
 * memutuskan (dan atas nama siapa bila lewat delegasi), catatan, dan untuk langkah aktif siapa saja
 * yang ditunggu serta kapan eskalasi otomatis. Waktu di zona kantor.
 */
export function ApprovalTimeline({ steps }: { steps: ApprovalTimelineStep[] }) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const at = (iso: string | null) =>
        iso ? formatDateTimeIn(iso, timezone) : null;

    return (
        <ol className="grid gap-0" aria-label="Langkah persetujuan">
            {steps.map((step, index) => {
                const { icon: Icon, className } =
                    ICON[step.status] ?? ICON.waiting;
                const last = index === steps.length - 1;

                return (
                    <li
                        key={step.step_order}
                        className="relative grid grid-cols-[1.75rem_minmax(0,1fr)] gap-x-3"
                    >
                        <span className="flex flex-col items-center">
                            <Icon
                                className={cn(
                                    'mt-0.5 size-5 shrink-0',
                                    className,
                                )}
                                aria-hidden="true"
                            />
                            {!last && (
                                <span
                                    className="my-1 w-px flex-1 bg-border"
                                    aria-hidden="true"
                                />
                            )}
                        </span>
                        <div className={cn('grid gap-1', !last && 'pb-5')}>
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span className="text-xs font-semibold text-muted-foreground">
                                    Langkah {step.step_order}
                                </span>
                                <StatusBadge
                                    status={`approval_step.${step.status}`}
                                    label={step.status_label}
                                />
                                {step.escalated && (
                                    <StatusBadge
                                        status="approval_step.escalated"
                                        label="Dieskalasi"
                                        tone="danger"
                                    />
                                )}
                            </div>
                            <p className="text-sm font-medium break-words">
                                {step.approver_label}
                            </p>

                            {step.decided_by && (
                                <p className="text-sm">
                                    {step.status === 'rejected'
                                        ? 'Ditolak'
                                        : 'Disetujui'}{' '}
                                    oleh{' '}
                                    <span className="font-medium">
                                        {step.decided_by.name}
                                    </span>
                                    {step.acting_for && (
                                        <>
                                            {' '}
                                            atas nama{' '}
                                            <span className="font-medium">
                                                {step.acting_for.name}
                                            </span>
                                        </>
                                    )}
                                    {step.decided_at && (
                                        <span className="text-muted-foreground tabular-nums">
                                            {' '}
                                            · {at(step.decided_at)}
                                        </span>
                                    )}
                                </p>
                            )}

                            {step.note && (
                                <p className="rounded-md bg-muted/60 px-3 py-2 text-sm break-words whitespace-pre-line">
                                    {step.note}
                                </p>
                            )}

                            {step.status === 'pending' && (
                                <div className="grid gap-0.5 text-xs text-muted-foreground">
                                    {step.activated_at && (
                                        <span className="tabular-nums">
                                            Menunggu sejak{' '}
                                            {at(step.activated_at)}
                                        </span>
                                    )}
                                    {step.waiting_for.length > 0 && (
                                        <span>
                                            Menunggu:{' '}
                                            {step.waiting_for.join(', ')}
                                        </span>
                                    )}
                                    {step.escalate_at && !step.escalated && (
                                        <span className="tabular-nums">
                                            Eskalasi otomatis{' '}
                                            {at(step.escalate_at)} (
                                            {step.escalation_label.replace(
                                                /^Eskalasi: /,
                                                '',
                                            )}
                                            )
                                        </span>
                                    )}
                                    {step.escalated && (
                                        <span>{step.escalation_label}</span>
                                    )}
                                </div>
                            )}
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
