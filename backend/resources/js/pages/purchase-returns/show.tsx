import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Ban,
    CalendarClock,
    CheckCheck,
    History,
    PackageX,
    Pencil,
    ReceiptText,
    Send,
    Stamp,
    Truck,
    Wallet,
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

export type PurchaseReturnLine = {
    id: number;
    stock_batch_id: number;
    product_id: number;
    sku: string;
    name: string;
    tracks_serial: boolean;
    base_unit: string;
    batch_no: string;
    expiry_date: string | null;
    batch_status: string;
    bin: string | null;
    qty_base: string;
    available_base: string;
    reservation_status: string | null;
    /** Nomor seri `in_stock` di batch (hanya saat bisa dikirim). */
    serial_options: string[];
    /** Hanya untuk pemegang izin harga beli (SEC-107). */
    unit_cost?: string;
    line_value?: string;
};

export type PurchaseReturnDetail = {
    id: number;
    number: string;
    supplier: { id: number; code: string; name: string };
    stock_location: { id: number; code: string; name: string };
    goods_receipt: { id: number; number: string } | null;
    reason: { id: number; name: string };
    status: string;
    status_label: string;
    approval_request_id: number | null;
    shipped_at: string | null;
    closed_at: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    show_cost: boolean;
    total_cost: string | null;
    lines: PurchaseReturnLine[];
};

type HistoryEntry = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
    note: string | null;
};

type Props = {
    purchaseReturn: PurchaseReturnDetail;
    history: HistoryEntry[];
    can: {
        edit: boolean;
        submit: boolean;
        ship: boolean;
        close: boolean;
        cancel: boolean;
        view_receipt: boolean;
    };
};

type Dialog = 'submit' | 'ship' | 'close' | 'cancel' | null;

const TEXTAREA =
    'min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * Detail retur ke prinsipal (15 §7.1 dokumen bergaris, FR-A26). Aksi sesuai status & izin dari server.
 * Kirim mengurangi stok dan mencatat event akuntansi; produk bernomor seri memilih nomornya di dialog
 * Kirim (PA-68).
 */
export default function PurchaseReturnShow({
    purchaseReturn: ret,
    history,
    can,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [dialog, setDialog] = useState<Dialog>(null);

    const columns: DocumentLineColumn<PurchaseReturnLine>[] = [
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
                </span>
            ),
        },
        {
            key: 'batch',
            header: 'Batch',
            cell: (l) => (
                <span className="inline-flex flex-wrap items-center gap-1.5">
                    <span className="font-mono">{l.batch_no}</span>
                    {l.batch_status !== 'available' && (
                        <StatusBadge status={`stock_batch.${l.batch_status}`} />
                    )}
                </span>
            ),
        },
        {
            key: 'expiry',
            header: 'ED · rak',
            cell: (l) =>
                `${l.expiry_date ? formatCivilDate(l.expiry_date) : 'Tanpa ED'}${l.bin ? ` · ${l.bin}` : ''}`,
        },
        {
            key: 'qty',
            header: 'Qty',
            numeric: true,
            cell: (l) => (
                <>
                    <span className="font-medium">{formatQty(l.qty_base)}</span>{' '}
                    <span className="text-muted-foreground">
                        {l.base_unit.toLowerCase()}
                    </span>
                </>
            ),
        },
        {
            key: 'reservation',
            header: 'Reservasi',
            hideOnMobile: true,
            cell: (l) =>
                l.reservation_status === 'active'
                    ? 'Aktif'
                    : l.reservation_status === 'consumed'
                      ? 'Terpakai'
                      : l.reservation_status === 'released'
                        ? 'Dilepas'
                        : '–',
        },
    ];
    if (ret.show_cost) {
        columns.push(
            {
                key: 'unit_cost',
                header: 'Biaya batch',
                numeric: true,
                hideOnMobile: true,
                cell: (l) => formatRupiah(l.unit_cost),
            },
            {
                key: 'line_value',
                header: 'Nilai',
                numeric: true,
                emphasis: true,
                cell: (l) => formatRupiah(l.line_value),
            },
        );
    }

    const highlights: DetailHeroHighlight[] = [
        {
            icon: ReceiptText,
            label: 'Alasan',
            value: ret.reason.name,
        },
        ret.show_cost
            ? {
                  icon: Wallet,
                  label: 'Nilai biaya beli',
                  value: formatRupiah(ret.total_cost),
              }
            : {
                  icon: PackageX,
                  label: 'Baris',
                  value: `${ret.lines.length} batch`,
              },
        {
            icon: CalendarClock,
            label: ret.shipped_at ? 'Dikirim' : 'Dibuat',
            value: formatDateTimeIn(
                ret.shipped_at ?? ret.created_at ?? '',
                timezone,
            ),
        },
    ];

    const menu: DetailMenuAction[] = [];
    if (can.cancel) {
        menu.push({
            key: 'cancel',
            label: 'Batalkan retur',
            icon: Ban,
            destructive: true,
            onSelect: () => setDialog('cancel'),
        });
    }

    return (
        <>
            <Head title={`Retur ${ret.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/pembelian/retur"
                    backLabel="Daftar retur"
                    kind="Retur ke prinsipal"
                    icon={PackageX}
                    number={ret.number}
                    status={`purchase_return.${ret.status}`}
                    statusLabel={ret.status_label}
                    muted={['cancelled', 'rejected', 'closed'].includes(
                        ret.status,
                    )}
                    meta={[
                        {
                            icon: Truck,
                            label: 'Lokasi stok ke supplier',
                            value: (
                                <>
                                    {ret.stock_location.name} →{' '}
                                    {ret.supplier.name}
                                </>
                            ),
                        },
                    ]}
                    highlights={highlights}
                    actions={
                        <>
                            {menu.length > 0 && (
                                <MoreActionsMenu items={menu} />
                            )}
                            {can.edit && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/pembelian/retur/${ret.id}/ubah`}
                                        aria-label={`Ubah retur ${ret.number}`}
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
                                    aria-label={`Ajukan retur ${ret.number}`}
                                >
                                    <Send aria-hidden="true" />
                                    <ActionLabel>Ajukan</ActionLabel>
                                </Button>
                            )}
                            {can.ship && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('ship')}
                                    aria-label={`Kirim retur ${ret.number}`}
                                >
                                    <Truck aria-hidden="true" />
                                    <ActionLabel>Kirim</ActionLabel>
                                </Button>
                            )}
                            {can.close && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('close')}
                                    aria-label={`Tutup retur ${ret.number}`}
                                >
                                    <CheckCheck aria-hidden="true" />
                                    <ActionLabel>Tutup</ActionLabel>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard
                        title="Barang diretur"
                        icon={PackageX}
                        action={
                            <span className="rounded-full border bg-muted/50 px-2.5 py-0.5 text-xs text-muted-foreground tabular-nums">
                                {ret.lines.length} batch
                            </span>
                        }
                    >
                        <DocumentLines
                            rows={ret.lines}
                            rowKey={(l) => l.id}
                            columns={columns}
                            caption={`Baris retur ${ret.number}`}
                            totals={
                                ret.show_cost
                                    ? [
                                          {
                                              label: 'Nilai biaya beli',
                                              value: formatRupiah(
                                                  ret.total_cost,
                                              ),
                                              emphasis: true,
                                          },
                                      ]
                                    : undefined
                            }
                        />
                    </DetailCard>

                    <div className="space-y-5">
                        <DetailCard title="Ringkasan" icon={ReceiptText}>
                            <FieldGrid>
                                <Field
                                    label="Supplier"
                                    value={ret.supplier.name}
                                    hint={ret.supplier.code}
                                    wide
                                />
                                <Field
                                    label="Lokasi stok"
                                    value={ret.stock_location.name}
                                    wide
                                />
                                <Field
                                    label="Penerimaan"
                                    value={
                                        ret.goods_receipt &&
                                        (can.view_receipt ? (
                                            <Link
                                                href={`/penerimaan/${ret.goods_receipt.id}`}
                                                className="font-mono font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {ret.goods_receipt.number}
                                            </Link>
                                        ) : (
                                            ret.goods_receipt.number
                                        ))
                                    }
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={ret.created_by}
                                />
                                <Field label="Catatan" value={ret.note} wide />
                            </FieldGrid>
                        </DetailCard>

                        {ret.approval_request_id !== null && (
                            <DetailCard title="Persetujuan" icon={Stamp}>
                                <p className="text-sm text-muted-foreground">
                                    {ret.status === 'pending_approval'
                                        ? 'Menunggu keputusan penyetuju. Stok belum direservasi.'
                                        : 'Keputusan tercatat di permintaan persetujuan.'}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    asChild
                                >
                                    <Link
                                        href={`/persetujuan/${ret.approval_request_id}`}
                                    >
                                        Lihat persetujuan
                                    </Link>
                                </Button>
                            </DetailCard>
                        )}

                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat retur ${ret.number}`}
                                items={history.map((h) => ({
                                    key: h.id,
                                    title: h.event_label,
                                    meta: `${h.actor} · ${formatDateTimeIn(h.occurred_at, timezone)}`,
                                    note: h.note,
                                }))}
                            />
                        </DetailCard>
                    </div>
                </div>
            </div>

            {dialog !== null && (
                <ActionDialog
                    kind={dialog}
                    ret={ret}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

const ACTION = {
    submit: {
        title: 'Ajukan retur?',
        description:
            'Retur dikirim ke penyetuju. Setelah disetujui, qty batch direservasi agar tidak terpakai dokumen lain.',
        button: 'Ajukan',
        path: 'ajukan',
        icon: Send,
        tone: 'default',
    },
    ship: {
        title: 'Kirim retur ke prinsipal?',
        description:
            'Stok batch berkurang dan nilai retur dicatat untuk akuntansi. Langkah ini tidak bisa dibatalkan.',
        button: 'Kirim',
        path: 'kirim',
        icon: Truck,
        tone: 'default',
    },
    close: {
        title: 'Tutup retur?',
        description:
            'Tutup setelah prinsipal mengonfirmasi penerimaan barang (mis. nota kredit). Tulis catatan konfirmasinya.',
        button: 'Tutup retur',
        path: 'tutup',
        icon: CheckCheck,
        tone: 'default',
    },
    cancel: {
        title: 'Batalkan retur?',
        description:
            'Reservasi stok (bila ada) dilepas. Retur yang dibatalkan tidak bisa diaktifkan kembali.',
        button: 'Batalkan retur',
        path: 'batal',
        icon: Ban,
        tone: 'danger',
    },
} as const;

function ActionDialog({
    kind,
    ret,
    onClose,
}: {
    kind: Exclude<Dialog, null>;
    ret: PurchaseReturnDetail;
    onClose: () => void;
}) {
    const action = ACTION[kind];
    const serialLines = ret.lines.filter((l) => l.tracks_serial);
    const form = useForm<{
        confirmation: string;
        reason: string;
        serials: Record<string, string[]>;
    }>({
        confirmation: '',
        reason: '',
        serials: {},
    });
    const errors = form.errors as Record<string, string | undefined>;

    const toggle = (lineId: number, serial: string) => {
        const current = form.data.serials[lineId] ?? [];
        form.setData('serials', {
            ...form.data.serials,
            [lineId]: current.includes(serial)
                ? current.filter((s) => s !== serial)
                : [...current, serial],
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) =>
            kind === 'close'
                ? { confirmation: d.confirmation }
                : kind === 'cancel'
                  ? { reason: d.reason }
                  : kind === 'ship'
                    ? { serials: d.serials }
                    : {},
        );
        form.post(`/pembelian/retur/${ret.id}/${action.path}`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };
    const firstError = Object.entries(errors).find(
        ([k, v]) => v && !k.startsWith('serials.'),
    )?.[1];

    return (
        <FormDialog
            open
            size={kind === 'ship' && serialLines.length > 0 ? 'lg' : 'sm'}
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
                <span className="font-mono font-medium">{ret.number}</span> ·{' '}
                {ret.supplier.name} · {ret.lines.length} batch
            </p>
            {kind === 'ship' &&
                serialLines.map((l) => {
                    const need = Number(l.qty_base);
                    const chosen = form.data.serials[l.id] ?? [];

                    return (
                        <fieldset key={l.id} className="grid gap-2">
                            <legend className="text-sm font-medium">
                                Nomor seri {l.sku} · pilih {need} (
                                {chosen.length} dipilih)
                            </legend>
                            <div className="flex max-h-40 flex-wrap gap-1.5 overflow-y-auto">
                                {l.serial_options.map((s) => (
                                    <label
                                        key={s}
                                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-md border px-2 py-1 font-mono text-xs has-[:checked]:border-primary has-[:checked]:bg-primary/10"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={chosen.includes(s)}
                                            onChange={() => toggle(l.id, s)}
                                        />
                                        {s}
                                    </label>
                                ))}
                            </div>
                            <InputError message={errors[`serials.${l.id}`]} />
                        </fieldset>
                    );
                })}
            {kind === 'close' && (
                <div className="grid gap-2">
                    <Label htmlFor="rtb-confirmation">
                        Catatan konfirmasi prinsipal
                    </Label>
                    <textarea
                        id="rtb-confirmation"
                        className={TEXTAREA}
                        maxLength={1000}
                        value={form.data.confirmation}
                        onChange={(e) =>
                            form.setData('confirmation', e.target.value)
                        }
                    />
                </div>
            )}
            {kind === 'cancel' && (
                <div className="grid gap-2">
                    <Label htmlFor="rtb-cancel-reason">
                        Alasan batal (opsional)
                    </Label>
                    <textarea
                        id="rtb-cancel-reason"
                        className={TEXTAREA}
                        maxLength={255}
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </div>
            )}
            <InputError message={firstError} />
        </FormDialog>
    );
}

PurchaseReturnShow.layout = {
    breadcrumbs: [{ title: 'Retur ke Prinsipal', href: '/pembelian/retur' }],
};
