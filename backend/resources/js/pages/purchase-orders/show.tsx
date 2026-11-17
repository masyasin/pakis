import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    ClipboardList,
    History,
    Lock,
    Pencil,
    Printer,
    ReceiptText,
    Send,
    Stamp,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentLines } from '@/components/pakis/document-lines';
import type { DocumentLineColumn } from '@/components/pakis/document-lines';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';

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

type Props = {
    order: PurchaseOrderDetail;
    history: HistoryEntry[];
    can: { edit: boolean; submit: boolean; cancel: boolean; close: boolean };
};

const TEXTAREA =
    'min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** "0.0000" → "–", "2.5000" → "2,5%". */
function percent(value: string): string {
    const trimmed = value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;

    return trimmed === '0' ? '–' : `${trimmed.replace('.', ',')}%`;
}

export const PO_LINE_COLUMNS: DocumentLineColumn<PurchaseOrderLine>[] = [
    {
        key: 'product',
        header: 'Produk',
        primary: true,
        cell: (l) => (
            <span>
                <span className="block font-medium">{l.name}</span>
                <span className="block font-mono text-xs text-muted-foreground">
                    {l.sku}
                </span>
                {l.note && (
                    <span className="block text-xs text-muted-foreground">
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
        cell: (l) => `${formatQty(l.qty)} ${l.unit.toLowerCase()}`,
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
        cell: (l) => (
            <span className="font-semibold">{formatRupiah(l.line_total)}</span>
        ),
    },
];

type Dialog = 'submit' | 'cancel' | 'close' | null;

/**
 * Detail PO (15 §7.1 dokumen bergaris, FR-A24). Aksi muncul sesuai status & izin dari server
 * (Policy); server tetap memeriksa ulang setiap aksi.
 */
export default function PurchaseOrderShow({ order, history, can }: Props) {
    const timezone = usePage().props.timezone;
    const [dialog, setDialog] = useState<Dialog>(null);
    const received = order.lines.some((l) => Number(l.qty_received_base) > 0);

    return (
        <>
            <Head title={`PO ${order.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/pembelian/po"
                    backLabel="Daftar purchase order"
                    number={order.number}
                    status={`purchase_order.${order.status}`}
                    statusLabel={order.status_label}
                    badges={
                        order.overdue && (
                            <StatusBadge
                                status="po.overdue"
                                label="Terlambat"
                                tone="danger"
                            />
                        )
                    }
                    meta={
                        <>
                            {order.supplier.name} → {order.stock_location.name}{' '}
                            · {formatCivilDate(order.order_date)}
                        </>
                    }
                    actions={
                        <>
                            {can.edit && (
                                <Button variant="outline" asChild>
                                    <Link
                                        href={`/pembelian/po/${order.id}/ubah`}
                                    >
                                        <Pencil aria-hidden="true" />
                                        Ubah
                                    </Link>
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <a
                                    href={`/pembelian/po/${order.id}/pdf`}
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Printer aria-hidden="true" />
                                    Cetak
                                </a>
                            </Button>
                            {can.cancel && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('cancel')}
                                >
                                    <Ban aria-hidden="true" />
                                    Batalkan PO
                                </Button>
                            )}
                            {can.close && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('close')}
                                >
                                    <Lock aria-hidden="true" />
                                    Tutup PO
                                </Button>
                            )}
                            {can.submit && (
                                <Button onClick={() => setDialog('submit')}>
                                    <Send aria-hidden="true" />
                                    Ajukan PO
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard title="Barang dipesan" icon={ClipboardList}>
                        <DocumentLines
                            rows={order.lines}
                            rowKey={(l) => l.id}
                            columns={PO_LINE_COLUMNS}
                            caption={`Baris PO ${order.number}`}
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
                        {received && (
                            <p className="mt-3 text-xs text-muted-foreground">
                                Sebagian barang sudah diterima di gudang.
                            </p>
                        )}
                    </DetailCard>

                    <div className="space-y-5">
                        <DetailCard title="Ringkasan" icon={ReceiptText}>
                            <FieldGrid>
                                <Field
                                    label="Supplier"
                                    value={order.supplier.name}
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
                                    value={
                                        order.payment_term_days === 0
                                            ? 'Tunai'
                                            : `${order.payment_term_days} hari`
                                    }
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={order.created_by}
                                />
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

                        <DetailCard title="Riwayat" icon={History}>
                            {history.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Belum ada riwayat.
                                </p>
                            ) : (
                                <ol className="space-y-3">
                                    {history.map((h) => (
                                        <li key={h.id} className="text-sm">
                                            <span className="block font-medium">
                                                {h.event_label}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {h.actor} ·{' '}
                                                {formatDateTimeIn(
                                                    h.occurred_at,
                                                    timezone,
                                                )}
                                            </span>
                                            {h.reason && (
                                                <span className="mt-0.5 block text-xs">
                                                    {h.reason}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ol>
                            )}
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
