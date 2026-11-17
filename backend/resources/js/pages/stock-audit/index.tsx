import { Head, Link, usePage } from '@inertiajs/react';
import { ClipboardCheck, ListOrdered } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { PageTabs } from '@/components/pakis/page-tabs';
import type { PageTab } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type FefoViolationRow = {
    id: number;
    document: { type: string; number: string; url: string };
    created_at: string | null;
    shipped_at: string | null;
    from: string;
    to: string;
    sku: string;
    name: string;
    unit: string;
    batch_no: string | null;
    expiry_date: string | null;
    qty: string;
    user: string | null;
};

export type OpnameCounterRow = {
    key: string;
    opname: { id: number; number: string; url: string };
    status: string;
    status_label: string;
    location: string;
    frozen_at: string | null;
    counter: string;
    lines_counted: number;
    variance_lines: number;
    variance_qty: string;
    recount_differs: number;
};

type Props =
    | (ListingProps<FefoViolationRow> & { tab: 'fefo' })
    | (ListingProps<OpnameCounterRow> & { tab: 'counters' });

const TABS: PageTab[] = [
    {
        key: 'fefo',
        label: 'Pelanggaran FEFO',
        href: '/stok/audit/fefo',
        icon: ListOrdered,
    },
    {
        key: 'counters',
        label: 'Selisih per penghitung',
        href: '/stok/audit/penghitung',
        icon: ClipboardCheck,
    },
];

/**
 * Laporan Audit Stok (PA-72, izin `stock.audit_reports`, bawaan Owner). Tab "Pelanggaran FEFO":
 * baris mutasi yang memakai batch selain ED terdekat (PA-64 b). Tab "Selisih per penghitung":
 * per opname × penghitung, berapa baris hitungan pertamanya menyimpang dari sistem (PA-67 a).
 */
export default function StockAuditIndex(props: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;

    return (
        <>
            <Head title="Laporan Audit Stok" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Laporan Audit Stok
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pola yang perlu ditinjau pemilik: batch yang keluar
                        tidak urut ED, dan penghitung opname yang sering
                        selisih.
                    </p>
                </div>
                <PageTabs
                    label="Jenis laporan audit stok"
                    active={props.tab}
                    tabs={TABS}
                />
                {props.tab === 'fefo' ? (
                    <FefoTable {...props} timezone={timezone} />
                ) : (
                    <CounterTable {...props} timezone={timezone} />
                )}
            </div>
        </>
    );
}

function FefoTable({
    rows,
    listing,
    timezone,
}: ListingProps<FefoViolationRow> & { timezone: string }) {
    const columns: DataTableColumn<FefoViolationRow>[] = [
        {
            key: 'number',
            header: 'Dokumen',
            sortKey: 'number',
            primary: true,
            cell: (r) => (
                <Link
                    href={r.document.url}
                    className="font-mono font-medium hover:underline"
                >
                    {r.document.number}
                </Link>
            ),
        },
        {
            key: 'created_at',
            header: 'Tanggal · dikirim',
            sortKey: 'created_at',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span>
                        {r.created_at
                            ? formatDateTimeIn(r.created_at, timezone)
                            : '–'}
                    </span>
                    {r.user && (
                        <span className="text-xs text-muted-foreground">
                            oleh {r.user}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'route',
            header: 'Asal → tujuan',
            cell: (r) => `${r.from} → ${r.to}`,
        },
        {
            key: 'product',
            header: 'Produk · batch dipakai',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="break-words">{r.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku} · {r.batch_no ?? '–'}
                        {r.expiry_date &&
                            ` · ED ${formatCivilDate(r.expiry_date)}`}
                    </span>
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'Qty',
            className: 'text-right tabular-nums',
            cell: (r) => `${formatQty(r.qty)} ${r.unit.toLowerCase()}`,
        },
    ];

    return (
        <DataTable
            rows={rows}
            listing={listing}
            columns={columns}
            title="Pelanggaran FEFO"
        />
    );
}

function CounterTable({
    rows,
    listing,
    timezone,
}: ListingProps<OpnameCounterRow> & { timezone: string }) {
    const columns: DataTableColumn<OpnameCounterRow>[] = [
        {
            key: 'counter',
            header: 'Penghitung',
            primary: true,
            cell: (r) => <span className="font-medium">{r.counter}</span>,
        },
        {
            key: 'opname',
            header: 'Opname',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <Link
                        href={r.opname.url}
                        className="font-mono font-medium hover:underline"
                    >
                        {r.opname.number}
                    </Link>
                    <span className="text-xs text-muted-foreground">
                        {r.location}
                    </span>
                </span>
            ),
        },
        {
            key: 'frozen_at',
            header: 'Mulai hitung',
            sortKey: 'frozen_at',
            cell: (r) =>
                r.frozen_at ? formatDateTimeIn(r.frozen_at, timezone) : '–',
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={`stock_opname.${r.status}`}
                    label={r.status_label}
                />
            ),
        },
        {
            key: 'lines',
            header: 'Baris dihitung',
            className: 'text-right tabular-nums',
            cell: (r) => r.lines_counted,
        },
        {
            key: 'variance_lines',
            header: 'Baris selisih',
            sortKey: 'variance_lines',
            className: 'text-right tabular-nums',
            cell: (r) => (
                <span
                    className={
                        r.variance_lines > 0 ? 'text-destructive' : undefined
                    }
                >
                    {r.variance_lines}
                </span>
            ),
        },
        {
            key: 'variance_qty',
            header: 'Total |selisih|',
            className: 'text-right tabular-nums',
            cell: (r) => formatQty(r.variance_qty),
        },
        {
            key: 'recount',
            header: 'Hitung ulang beda',
            className: 'text-right tabular-nums',
            cell: (r) => r.recount_differs,
        },
    ];

    return (
        <DataTable
            rows={rows}
            listing={listing}
            columns={columns}
            rowKey={(r) => r.key}
            title="Selisih opname per penghitung"
        />
    );
}

StockAuditIndex.layout = {
    breadcrumbs: [{ title: 'Laporan Audit Stok', href: '/stok/audit/fefo' }],
};
