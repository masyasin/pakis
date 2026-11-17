import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    CalendarClock,
    ClipboardList,
    History,
    Hourglass,
    Lock,
    PackageCheck,
    Pencil,
    Printer,
    ReceiptText,
    Send,
    Stamp,
    Truck,
    Wallet,
    CalendarDays,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { ActionLabel, MoreActionsMenu } from '@/components/pakis/detail-hero';
import type {
    DetailHeroHighlight,
    DetailMenuAction,
} from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentLines } from '@/components/pakis/document-lines';
import type { DocumentLineColumn } from '@/components/pakis/document-lines';
import { DocumentTimeline } from '@/components/pakis/document-timeline';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { Auth } from '@/types';

export type PurchaseOrderLine = {
    id: number;
    line_no: number;
    product_id: number;
    sku: string;
    name: string;
    product_unit_id: number;
    unit: string;
    qty: string;
    qty_base: string;
    unit_price: string;
    discount_pct: string;
    line_total: string;
    qty_received_base: string;
    /** Satuan dasar untuk kolom "Diterima" (opsional; disediakan T-080). */
    base_unit?: string | null;
    note: string | null;
};

export type PurchaseOrderDetail = {
    id: number;
    number: string;
    supplier: { id: number; code: string; name: string };
    stock_location: { id: number; code: string; name: string };
    order_date: string;
    expected_date: string | null;
    payment_term_days: number;
    subtotal: string;
    discount_total: string;
    tax_total: string;
    grand_total: string;
    status: string;
    status_label: string;
    approval_request_id: number | null;
    submitted_at: string | null;
    approved_at: string | null;
    closed_at: string | null;
    close_reason: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    overdue: boolean;
    lines: PurchaseOrderLine[];
};

type HistoryEntry = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
    reason: string | null;
};

/** Penerimaan barang atas PO ini (slot T-080; tampil bila dikirim server & tidak kosong). */
export type PurchaseOrderReceipt = {
    id: number;
    number: string;
    date: string;
    status: string;
    status_label: string;
    /** Tautan detail penerimaan bila pengguna boleh membukanya. */
    url?: string | null;
};

type Props = {
    order: PurchaseOrderDetail;
    history: HistoryEntry[];
    can: {
        edit: boolean;
        submit: boolean;
        cancel: boolean;
        close: boolean;
        /** Terima barang (T-080); belum dikirim server = tombol tersembunyi. */
        receive?: boolean;
    };
    receipts?: PurchaseOrderReceipt[];
};

const TEXTAREA =
    'min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** Status PO yang punya arti progres penerimaan barang. */
const RECEIPT_STATUSES = ['approved', 'partially_received', 'received'];

/** Status yang boleh menerima barang (tombol tetap menunggu `can.receive` dari server). */
const RECEIVABLE_STATUSES = ['approved', 'partially_received'];

/** "0.0000" → "–", "2.5000" → "2,5%". */
function percent(value: string): string {
    const trimmed = value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;

    return trimmed === '0' ? '–' : `${trimmed.replace('.', ',')}%`;
}

const lineComplete = (l: PurchaseOrderLine) =>
    Number(l.qty_base) > 0 && Number(l.qty_received_base) >= Number(l.qty_base);

export const PO_LINE_COLUMNS: DocumentLineColumn<PurchaseOrderLine>[] = [
    {
        key: 'product',
        header: 'Produk',
        primary: true,
        cell: (l) => (
            <span className="grid gap-0.5">
                <span className="font-medium break-words">{l.name}</span>
                <span className="font-mono text-xs text-muted-foreground">
                    {l.sku}
                </span>
                {l.note && (
                    <span className="text-xs text-muted-foreground">
                        {l.note}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'qty',
        header: 'Qty',
        numeric: true,
        cell: (l) => (
            <>
                <span className="font-medium">{formatQty(l.qty)}</span>{' '}
                <span className="text-muted-foreground">
                    {l.unit.toLowerCase()}
                </span>
            </>
        ),
    },
    {
        key: 'unit_price',
        header: 'Harga satuan',
        numeric: true,
        cell: (l) => formatRupiah(l.unit_price),
    },
    {
        key: 'discount_pct',
        header: 'Diskon',
        numeric: true,
        cell: (l) => percent(l.discount_pct),
    },
    {
        key: 'line_total',
        header: 'Jumlah',
        numeric: true,
        emphasis: true,
        cell: (l) => formatRupiah(l.line_total),
    },
];

/** Kolom progres terima per baris (satuan dasar), disisipkan sebelum "Jumlah". */
const RECEIVED_COLUMN: DocumentLineColumn<PurchaseOrderLine> = {
    key: 'received',
    header: 'Diterima',
    numeric: true,
    cell: (l) => {
        const ordered = Number(l.qty_base);
        const got = Number(l.qty_received_base);
        const pct = ordered > 0 ? Math.min(100, (got / ordered) * 100) : 0;

        return (
            <span className="inline-grid justify-items-end gap-1">
                <span>
                    {formatQty(l.qty_received_base)} / {formatQty(l.qty_base)}
                    {l.base_unit && (
                        <span className="text-muted-foreground">
                            {' '}
                            {l.base_unit.toLowerCase()}
                        </span>
                    )}
                </span>
                <span
                    aria-hidden="true"
                    className="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                >
                    <span
                        className={
                            lineComplete(l)
                                ? 'block h-full bg-status-success'
                                : 'block h-full bg-status-warning'
                        }
                        style={{ width: `${pct}%` }}
                    />
                </span>
            </span>
        );
    },
};

type Dialog = 'submit' | 'cancel' | 'close' | null;

/**
 * Detail PO (15 §7.1 dokumen bergaris, FR-A24). Aksi muncul sesuai status & izin dari server
 * (Policy); server tetap memeriksa ulang setiap aksi.
 */
export default function PurchaseOrderShow({
    order,
    history,
    can,
    receipts = [],
}: Props) {
    const { timezone, auth } = usePage<{ timezone: string; auth: Auth }>()
        .props;
    const [dialog, setDialog] = useState<Dialog>(null);
    const received = order.lines.some((l) => Number(l.qty_received_base) > 0);
    const showReceipt = RECEIPT_STATUSES.includes(order.status);
    const completeLines = order.lines.filter(lineComplete).length;
    const receiptProgress = `${completeLines} dari ${order.lines.length} baris lengkap`;
    const canReceive =
        can.receive === true && RECEIVABLE_STATUSES.includes(order.status);
    // Tautan ke supplier hanya UX; rute detail supplier tetap dijaga `can:suppliers.view`.
    const canViewSupplier = auth.permissions.includes('suppliers.view');
    const paymentTerm =
        order.payment_term_days === 0
            ? 'Tunai'
            : `${order.payment_term_days} hari`;

    const columns = showReceipt
        ? [
              ...PO_LINE_COLUMNS.slice(0, -1),
              RECEIVED_COLUMN,
              ...PO_LINE_COLUMNS.slice(-1),
          ]
        : PO_LINE_COLUMNS;

    const highlights: DetailHeroHighlight[] = [
        {
            icon: Wallet,
            label: 'Total',
            value: formatRupiah(order.grand_total),
        },
        {
            icon: CalendarClock,
            label: 'Perkiraan datang',
            value: order.expected_date && formatCivilDate(order.expected_date),
            tone: order.overdue ? 'danger' : 'default',
        },
        showReceipt
            ? {
                  icon: PackageCheck,
                  label: 'Diterima',
                  value: receiptProgress,
                  tone:
                      completeLines === order.lines.length
                          ? 'success'
                          : 'warning',
              }
            : { icon: Hourglass, label: 'Tempo bayar', value: paymentTerm },
    ];

    const menu: DetailMenuAction[] = [
        {
            key: 'print',
            label: 'Cetak',
            icon: Printer,
            href: `/pembelian/po/${order.id}/pdf`,
            newTab: true,
        },
    ];
    if (can.close) {
        menu.push({
            key: 'close',
            label: 'Tutup PO',
            icon: Lock,
            destructive: true,
            separatorBefore: true,
            onSelect: () => setDialog('close'),
        });
    }
    if (can.cancel) {
        menu.push({
            key: 'cancel',
            label: 'Batalkan PO',
            icon: Ban,
            destructive: true,
            separatorBefore: !can.close,
            onSelect: () => setDialog('cancel'),
        });
    }

    return (
        <>
            <Head title={`PO ${order.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/pembelian/po"
                    backLabel="Daftar purchase order"
                    kind="Purchase order"
                    icon={ReceiptText}
                    number={order.number}
                    status={`purchase_order.${order.status}`}
                    statusLabel={order.status_label}
                    muted={['cancelled', 'closed', 'rejected'].includes(
                        order.status,
                    )}
                    badges={
                        order.overdue && (
                            <StatusBadge
                                status="po.overdue"
                                label="Terlambat"
                                tone="danger"
                            />
                        )
                    }
                    meta={[
                        {
                            icon: Truck,
                            label: 'Supplier ke gudang tujuan',
                            value: (
                                <>
                                    {order.supplier.name} →{' '}
                                    {order.stock_location.name}
                                </>
                            ),
                        },
                        {
                            icon: CalendarDays,
                            label: 'Tanggal PO',
                            value: formatCivilDate(order.order_date),
                        },
                    ]}
                    highlights={highlights}
                    actions={
                        <>
                            <MoreActionsMenu items={menu} />
                            {can.edit && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/pembelian/po/${order.id}/ubah`}
                                        aria-label={`Ubah PO ${order.number}`}
                                    >
                                        <Pencil aria-hidden="true" />
                                        <ActionLabel>Ubah</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                            {can.submit && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('submit')}
                                    aria-label={`Ajukan PO ${order.number}`}
                                >
                                    <Send aria-hidden="true" />
                                    <ActionLabel>Ajukan PO</ActionLabel>
                                </Button>
                            )}
                            {canReceive && (
                                <Button size="sm" asChild>
                                    <Link
                                        href={`/penerimaan/baru?po=${order.id}`}
                                        aria-label={`Terima barang PO ${order.number}`}
                                    >
                                        <PackageCheck aria-hidden="true" />
                                        <ActionLabel>Terima Barang</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard
                        title="Barang dipesan"
                        icon={ClipboardList}
                        action={
                            <span className="rounded-full border bg-muted/50 px-2.5 py-0.5 text-xs text-muted-foreground tabular-nums">
                                {order.lines.length} produk
                            </span>
                        }
                    >
                        <DocumentLines
                            rows={order.lines}
                            rowKey={(l) => l.id}
                            columns={columns}
                            caption={`Baris PO ${order.number}`}
                            footer={
                                received && (
                                    <p className="inline-flex items-center gap-1.5 text-xs">
                                        <PackageCheck
                                            className="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                        Sebagian barang sudah diterima di
                                        gudang.
                                    </p>
                                )
                            }
                            totals={[
                                {
                                    label: 'Subtotal',
                                    value: formatRupiah(order.subtotal),
                                },
                                {
                                    label: 'Diskon',
                                    value: formatRupiah(order.discount_total),
                                },
                                {
                                    label: 'Pajak',
                                    value: formatRupiah(order.tax_total),
                                },
                                {
                                    label: 'Total',
                                    value: formatRupiah(order.grand_total),
                                    emphasis: true,
                                },
                            ]}
                        />
                    </DetailCard>

                    <div className="space-y-5">
                        <DetailCard title="Ringkasan" icon={ReceiptText}>
                            <FieldGrid>
                                <Field
                                    label="Supplier"
                                    value={
                                        canViewSupplier ? (
                                            <Link
                                                href={`/supplier/${order.supplier.id}`}
                                                className="font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {order.supplier.name}
                                            </Link>
                                        ) : (
                                            order.supplier.name
                                        )
                                    }
                                    hint={order.supplier.code}
                                    wide
                                />
                                <Field
                                    label="Gudang tujuan"
                                    value={order.stock_location.name}
                                    wide
                                />
                                <Field
                                    label="Tanggal PO"
                                    value={formatCivilDate(order.order_date)}
                                />
                                <Field
                                    label="Perkiraan datang"
                                    value={
                                        order.expected_date &&
                                        formatCivilDate(order.expected_date)
                                    }
                                />
                                <Field
                                    label="Tempo bayar"
                                    value={paymentTerm}
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={order.created_by}
                                />
                                {showReceipt && (
                                    <Field
                                        label="Penerimaan"
                                        value={receiptProgress}
                                        wide
                                    />
                                )}
                                <Field
                                    label="Catatan"
                                    value={order.note}
                                    wide
                                />
                                {order.close_reason && (
                                    <Field
                                        label="Alasan ditutup"
                                        value={order.close_reason}
                                        wide
                                    />
                                )}
                            </FieldGrid>
                        </DetailCard>

                        {order.approval_request_id !== null && (
                            <DetailCard title="Persetujuan" icon={Stamp}>
                                <p className="text-sm text-muted-foreground">
                                    {order.status === 'submitted'
                                        ? 'PO menunggu keputusan penyetuju sesuai nilainya.'
                                        : 'Riwayat keputusan tercatat di permintaan persetujuan.'}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    asChild
                                >
                                    <Link
                                        href={`/persetujuan/${order.approval_request_id}`}
                                    >
                                        Lihat persetujuan
                                    </Link>
                                </Button>
                            </DetailCard>
                        )}

                        {receipts.length > 0 && (
                            <DetailCard title="Penerimaan" icon={PackageCheck}>
                                <ul className="divide-y">
                                    {receipts.map((r) => (
                                        <li
                                            key={r.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2 text-sm first:pt-0 last:pb-0"
                                        >
                                            <span className="grid min-w-0">
                                                {r.url ? (
                                                    <Link
                                                        href={r.url}
                                                        className="font-mono text-sm font-medium text-primary underline-offset-4 hover:underline"
                                                    >
                                                        {r.number}
                                                    </Link>
                                                ) : (
                                                    <span className="font-mono text-sm font-medium">
                                                        {r.number}
                                                    </span>
                                                )}
                                                <span className="text-xs text-muted-foreground">
                                                    {formatCivilDate(r.date)}
                                                </span>
                                            </span>
                                            <StatusBadge
                                                status={`goods_receipt.${r.status}`}
                                                label={r.status_label}
                                            />
                                        </li>
                                    ))}
                                </ul>
                            </DetailCard>
                        )}

                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat PO ${order.number}`}
                                items={history.map((h) => ({
                                    key: h.id,
                                    title: h.event_label,
                                    meta: `${h.actor} · ${formatDateTimeIn(h.occurred_at, timezone)}`,
                                    note: h.reason,
                                }))}
                            />
                        </DetailCard>
                    </div>
                </div>
            </div>

            {dialog !== null && (
                <ActionDialog
                    kind={dialog}
                    order={order}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

const ACTION = {
    submit: {
        title: 'Ajukan PO?',
        description:
            'PO dikirim ke penyetuju sesuai nilainya dan tidak bisa diubah selama menunggu. Bila ditolak, PO bisa diperbaiki lalu diajukan lagi.',
        button: 'Ajukan PO',
        path: 'ajukan',
        icon: Send,
        tone: 'default',
    },
    cancel: {
        title: 'Batalkan PO?',
        description:
            'PO yang dibatalkan tidak bisa diaktifkan kembali. Nomornya tetap tercatat.',
        button: 'Batalkan PO',
        path: 'batal',
        icon: Ban,
        tone: 'danger',
    },
    close: {
        title: 'Tutup PO?',
        description:
            'Sisa barang yang belum datang tidak lagi ditunggu. Tulis alasannya agar jelas di riwayat.',
        button: 'Tutup PO',
        path: 'tutup',
        icon: Lock,
        tone: 'danger',
    },
} as const;

function ActionDialog({
    kind,
    order,
    onClose,
}: {
    kind: Exclude<Dialog, null>;
    order: PurchaseOrderDetail;
    onClose: () => void;
}) {
    const action = ACTION[kind];
    const form = useForm({ reason: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => (kind === 'close' ? d : {}));
        form.post(`/pembelian/po/${order.id}/${action.path}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={action.icon}
            tone={action.tone}
            title={action.title}
            description={action.description}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button
                        type="submit"
                        variant={
                            action.tone === 'danger' ? 'destructive' : 'default'
                        }
                        disabled={form.processing}
                    >
                        {action.button}
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                <span className="font-mono font-medium">{order.number}</span> ·{' '}
                {order.supplier.name} · {formatRupiah(order.grand_total)}
            </p>
            {kind === 'close' && (
                <div className="grid gap-2">
                    <Label htmlFor="po-close-reason">Alasan menutup</Label>
                    <textarea
                        id="po-close-reason"
                        className={TEXTAREA}
                        maxLength={255}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                    <InputError message={form.errors.reason} />
                </div>
            )}
        </FormDialog>
    );
}

PurchaseOrderShow.layout = {
    breadcrumbs: [{ title: 'Purchase Order', href: '/pembelian/po' }],
};
