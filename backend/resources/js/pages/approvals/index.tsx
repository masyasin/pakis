import { Head, Link, usePage } from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronRight,
    Inbox,
    LockKeyhole,
    Send,
    Users,
    Workflow,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { ApprovalDecisionDialog } from '@/components/pakis/approval-decision-dialog';
import type {
    ApprovalDecision,
    ApprovalDecisionKind,
} from '@/components/pakis/approval-decision-dialog';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { PageTabs } from '@/components/pakis/page-tabs';
import type { PageTab } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { formatDateTimeIn, formatWaiting } from '@/lib/approval-format';
import { index as delegationsIndex } from '@/routes/admin/approval-delegations';
import { index as rulesIndex } from '@/routes/admin/approval-rules';
import { index, show } from '@/routes/admin/approvals';
import type { ListingProps } from '@/types/listing';

export type ApprovalRow = {
    id: number;
    document_type: string;
    document_type_label: string;
    summary: string;
    amount: string | null;
    amount_label: string | null;
    reason: string;
    status: string;
    status_label: string;
    current_step: number;
    steps_total: number;
    requester: { id: number; name: string } | null;
    created_at: string | null;
    waiting_since: string | null;
    escalated: boolean;
    requires_password: boolean;
    /** PA-38: pembatalan oleh pengelola setelah ada keputusan wajib beralasan. */
    cancel_requires_note?: boolean;
    can: { decide: boolean; cancel: boolean };
};

type Tab = 'mine' | 'submitted' | 'all';

type Props = ListingProps<ApprovalRow> & {
    tab: Tab;
    tabs: { value: Tab; label: string; count: number | null }[];
    documentTypes: { value: string; label: string }[];
    reauthAmount: string | null;
    can: { viewAll: boolean; configure: boolean };
};

const TAB_ICON = { mine: Inbox, submitted: Send, all: Users } as const;

/** Hanya untuk kerangka muat (skeleton); baris dirender sebagai kartu. */
const columns: DataTableColumn<ApprovalRow>[] = [
    {
        key: 'summary',
        header: 'Dokumen',
        primary: true,
        cell: (r) => r.summary,
    },
    { key: 'amount', header: 'Nominal', cell: (r) => r.amount_label },
    { key: 'requester', header: 'Pengaju', cell: (r) => r.requester?.name },
    { key: 'status', header: 'Status', cell: (r) => r.status_label },
];

const SUBTITLE: Record<Tab, string> = {
    mine: 'Permintaan yang menunggu keputusan Anda, langsung atau lewat delegasi.',
    submitted: 'Permintaan persetujuan atas dokumen yang Anda ajukan.',
    all: 'Semua permintaan persetujuan dalam cakupan data Anda.',
};

/** Kartu satu permintaan (15 §7.1 "Kotak masuk persetujuan"). */
function ApprovalCard({
    row,
    timezone,
    showCancel,
    onDecide,
}: {
    row: ApprovalRow;
    timezone: string;
    /** Di tab "Menunggu saya" batal hanya lewat detail, agar kartu fokus pada Setujui/Tolak. */
    showCancel: boolean;
    onDecide: (kind: ApprovalDecisionKind, row: ApprovalRow) => void;
}) {
    const waiting = row.waiting_since ? formatWaiting(row.waiting_since) : null;

    return (
        <article className="grid gap-3 p-4 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-start">
            <div className="grid min-w-0 gap-1.5">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        {row.document_type_label}
                    </span>
                    <StatusBadge
                        status={`approval.${row.status}`}
                        label={row.status_label}
                    />
                    {row.escalated && (
                        <StatusBadge
                            status="approval.escalated"
                            label="Dieskalasi"
                            tone="danger"
                        />
                    )}
                </div>
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <Link
                        href={show(row.id)}
                        className="min-w-0 text-base font-semibold break-words hover:underline focus-visible:underline focus-visible:outline-none"
                    >
                        {row.summary}
                    </Link>
                    {row.amount_label && (
                        <span className="text-base font-bold whitespace-nowrap tabular-nums">
                            {row.amount_label}
                        </span>
                    )}
                </div>
                <p className="text-sm text-muted-foreground">{row.reason}</p>
                <p className="flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
                    {row.requester && (
                        <span>
                            Diajukan{' '}
                            <span className="font-medium text-foreground">
                                {row.requester.name}
                            </span>
                            {row.created_at && (
                                <span className="tabular-nums">
                                    {' '}
                                    ·{' '}
                                    {formatDateTimeIn(row.created_at, timezone)}
                                </span>
                            )}
                        </span>
                    )}
                    <span className="tabular-nums">
                        Langkah {row.current_step}/{row.steps_total}
                    </span>
                    {waiting && (
                        <span className="font-medium text-status-warning tabular-nums">
                            Menunggu {waiting}
                        </span>
                    )}
                    {row.requires_password && (
                        <span className="inline-flex items-center gap-1">
                            <LockKeyhole
                                className="size-3"
                                aria-hidden="true"
                            />
                            Perlu konfirmasi password
                        </span>
                    )}
                </p>
            </div>

            <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                {row.can.decide && (
                    <>
                        <Button
                            size="sm"
                            className="flex-1 sm:flex-none"
                            aria-label={`Setujui ${row.summary}`}
                            onClick={() => onDecide('approve', row)}
                        >
                            <Check aria-hidden="true" />
                            Setujui
                        </Button>
                        <Button
                            size="sm"
                            variant="outline"
                            className="flex-1 sm:flex-none"
                            aria-label={`Tolak ${row.summary}`}
                            onClick={() => onDecide('reject', row)}
                        >
                            <X aria-hidden="true" />
                            Tolak
                        </Button>
                    </>
                )}
                {row.can.cancel && showCancel && (
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-label={`Batalkan ${row.summary}`}
                        onClick={() => onDecide('cancel', row)}
                    >
                        <Ban aria-hidden="true" />
                        Batalkan
                    </Button>
                )}
                <Button size="sm" variant="ghost" asChild>
                    <Link
                        href={show(row.id)}
                        aria-label={`Detail ${row.summary}`}
                    >
                        Detail
                        <ChevronRight aria-hidden="true" />
                    </Link>
                </Button>
            </div>
        </article>
    );
}

/** Kotak masuk persetujuan (15 §5, §7.1; listing `approvals` 15 §7.3; PA-27). */
export default function ApprovalsIndex({
    rows,
    listing,
    tab,
    tabs,
    reauthAmount,
    can,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [deciding, setDeciding] = useState<ApprovalDecision | null>(null);

    const pageTabs: PageTab[] = tabs.map((t) => ({
        key: t.value,
        label: t.label,
        count: t.count,
        icon: TAB_ICON[t.value],
        href: index({ query: { filter: { tab: t.value } } }),
    }));

    return (
        <>
            <Head title="Persetujuan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Persetujuan
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {SUBTITLE[tab]}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={delegationsIndex()}>
                                <Users aria-hidden="true" />
                                Delegasi
                            </Link>
                        </Button>
                        {can.configure && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={rulesIndex()}>
                                    <Workflow aria-hidden="true" />
                                    Aturan persetujuan
                                </Link>
                            </Button>
                        )}
                    </div>
                </div>

                {pageTabs.length > 1 && (
                    <PageTabs
                        label="Kotak persetujuan"
                        active={tab}
                        tabs={pageTabs}
                    />
                )}

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title={
                        tabs.find((t) => t.value === tab)?.label ??
                        'Daftar persetujuan'
                    }
                    // Tab diatur PageTabs (kunjungan penuh, agar `tabs`/`tab` ikut diperbarui).
                    hiddenFilters={['tab']}
                    renderItem={(row) => (
                        <ApprovalCard
                            row={row}
                            timezone={timezone}
                            showCancel={tab !== 'mine'}
                            onDecide={(kind, r) =>
                                setDeciding({ kind, approval: r })
                            }
                        />
                    )}
                />
            </div>

            <ApprovalDecisionDialog
                pending={deciding}
                onClose={() => setDeciding(null)}
                reauthAmount={reauthAmount}
            />
        </>
    );
}

ApprovalsIndex.layout = {
    breadcrumbs: [{ title: 'Persetujuan', href: index() }],
};
