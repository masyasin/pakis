import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Ban,
    CalendarClock,
    ClipboardList,
    History,
    PackageCheck,
    Pencil,
    Scale,
    Send,
    Stamp,
    TriangleAlert,
    Truck,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty, parseQtyInput } from '@/lib/qty';

type BatchRef = { id: number; batch_no: string; expiry_date: string | null };

export type StockTransferLine = {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    source_batch: BatchRef | null;
    dest_batch: BatchRef | null;
    qty_requested_base: string;
    qty_shipped_base: string | null;
    qty_received_base: string | null;
    variance_base: string | null;
    variance_reason: string | null;
    dest_bin: { id: number; code: string; name: string | null } | null;
    fefo_override: boolean;
};

type LocationRef = { id: number; code: string; name: string; type: string };

export type StockTransferDetail = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    from_location: LocationRef;
    to_location: LocationRef;
    vehicle: { id: number; plate_no: string; name: string } | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    shipped_by: string | null;
    shipped_by_id: number | null;
    shipped_at: string | null;
    received_by: string | null;
    received_at: string | null;
    approval_request_id: number | null;
    overdue: boolean;
    /** Selisih ditolak/ditarik: baris selisih terbuka untuk dihitung ulang (PA-66). */
    recount_open: boolean;
    /** Nilai selisih (HPP); null bila tanpa izin lihat biaya atau tanpa selisih. */
    variance_value: string | null;
    lines: StockTransferLine[];
};

type HistoryEntry = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
    reason: string | null;
};

type Props = {
    transfer: StockTransferDetail;
    history: HistoryEntry[];
    can: { edit: boolean; ship: boolean; cancel: boolean; receive: boolean };
};

type Dialog = 'ship' | 'cancel' | 'receive' | null;

const FINAL = ['closed', 'cancelled'];

const hasVariance = (l: StockTransferLine) =>
    l.variance_base !== null && Number(l.variance_base) > 0;

function BatchCell({ batch }: { batch: BatchRef | null }) {
    if (batch === null) {
        return <span className="text-muted-foreground">FEFO saat kirim</span>;
    }

    return (
        <span className="grid gap-0.5">
            <span className="font-mono text-xs">{batch.batch_no}</span>
            {batch.expiry_date && (
                <span className="text-xs text-muted-foreground">
                    ED {formatCivilDate(batch.expiry_date)}
                </span>
            )}
        </span>
    );
}

const qtyOrDash = (q: string | null, unit: string) =>
    q === null ? '–' : `${formatQty(q)} ${unit.toLowerCase()}`;

const COLUMNS: DocumentLineColumn<StockTransferLine>[] = [
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
                {l.fefo_override && (
                    <span className="inline-flex items-center gap-1 text-xs text-status-warning">
                        <TriangleAlert
                            className="size-3.5 shrink-0"
                            aria-hidden="true"
                        />
                        Batch dipilih manual (bukan FEFO pertama)
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'batch',
        header: 'Batch',
        cell: (l) => <BatchCell batch={l.source_batch} />,
    },
    {
        key: 'requested',
        header: 'Diminta',
        numeric: true,
        cell: (l) => qtyOrDash(l.qty_requested_base, l.unit),
    },
    {
        key: 'shipped',
        header: 'Dikirim',
        numeric: true,
        cell: (l) => qtyOrDash(l.qty_shipped_base, l.unit),
    },
    {
        key: 'received',
        header: 'Diterima',
        numeric: true,
        emphasis: true,
        cell: (l) => (
            <span className="inline-grid justify-items-end gap-0.5">
                <span>{qtyOrDash(l.qty_received_base, l.unit)}</span>
                {l.dest_bin && (
                    <span className="text-xs font-normal text-muted-foreground">
                        Rak {l.dest_bin.code}
                    </span>
                )}
            </span>
        ),
    },
    {
        key: 'variance',
        header: 'Selisih',
        numeric: true,
        cell: (l) =>
            hasVariance(l) ? (
                <span className="inline-grid justify-items-end gap-0.5">
                    <span className="font-medium text-status-warning">
                        −{formatQty(l.variance_base ?? '0')}
                    </span>
                    {l.variance_reason && (
                        <span className="text-xs text-muted-foreground">
                            {l.variance_reason}
                        </span>
                    )}
                </span>
            ) : (
                '–'
            ),
    },
];

/**
 * Detail mutasi (15 §7.1 dokumen bergaris, BR-161). Alur: draf → kirim (stok pindah ke "dalam
 * perjalanan") → terima per baris di lokasi tujuan oleh orang lain → selisih lewat persetujuan.
 * Aksi muncul sesuai status & izin dari server; server tetap memeriksa ulang setiap aksi.
 */
export default function StockTransferShow({ transfer, history, can }: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [dialog, setDialog] = useState<Dialog>(null);
    const at = (iso: string | null) => iso && formatDateTimeIn(iso, timezone);
    const varianceLines = transfer.lines.filter(hasVariance);
    const awaitingApproval =
        transfer.status === 'received_with_variance' &&
        transfer.approval_request_id !== null;

    const highlights: DetailHeroHighlight[] = [
        {
            icon: Truck,
            label: 'Dikirim',
            value: at(transfer.shipped_at),
            tone: transfer.overdue ? 'danger' : 'default',
        },
        {
            icon: PackageCheck,
            label: 'Diterima',
            value: at(transfer.received_at),
        },
        {
            icon: Scale,
            label: 'Selisih',
            value:
                varianceLines.length === 0
                    ? null
                    : transfer.variance_value !== null
                      ? formatRupiah(transfer.variance_value)
                      : `${varianceLines.length} baris`,
            tone: varianceLines.length > 0 ? 'warning' : 'default',
        },
    ];

    const menu: DetailMenuAction[] = [];
    if (can.cancel) {
        menu.push({
            key: 'cancel',
            label: 'Batalkan mutasi',
            icon: Ban,
            destructive: true,
            onSelect: () => setDialog('cancel'),
        });
    }

    return (
        <>
            <Head title={`Mutasi ${transfer.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/mutasi"
                    backLabel="Daftar mutasi stok"
                    kind="Mutasi stok"
                    icon={ArrowLeftRight}
                    number={transfer.number}
                    status={`stock_transfer.${transfer.status}`}
                    statusLabel={transfer.status_label}
                    muted={FINAL.includes(transfer.status)}
                    badges={
                        transfer.overdue && (
                            <StatusBadge
                                status="stock_transfer.overdue"
                                label="Terlambat"
                                tone="danger"
                            />
                        )
                    }
                    meta={[
                        {
                            icon: ArrowLeftRight,
                            label: 'Dari lokasi ke lokasi',
                            value: (
                                <>
                                    {transfer.from_location.name} →{' '}
                                    {transfer.to_location.name}
                                </>
                            ),
                        },
                        {
                            icon: Truck,
                            label: 'Kendaraan',
                            value: transfer.vehicle?.plate_no,
                        },
                    ]}
                    highlights={highlights}
                    notice={
                        transfer.recount_open && (
                            <p className="text-sm">
                                Selisih ditolak atau ditarik. Hitung ulang
                                barang yang kurang lalu catat lagi
                                penerimaannya.
                            </p>
                        )
                    }
                    actions={
                        <>
                            {menu.length > 0 && (
                                <MoreActionsMenu items={menu} />
                            )}
                            {can.edit && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/mutasi/${transfer.id}/ubah`}
                                        aria-label={`Ubah mutasi ${transfer.number}`}
                                    >
                                        <Pencil aria-hidden="true" />
                                        <ActionLabel>Ubah</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                            {can.ship && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('ship')}
                                    aria-label={`Kirim mutasi ${transfer.number}`}
                                >
                                    <Send aria-hidden="true" />
                                    <ActionLabel>Kirim</ActionLabel>
                                </Button>
                            )}
                            {can.receive && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('receive')}
                                    aria-label={`Terima mutasi ${transfer.number}`}
                                >
                                    <PackageCheck aria-hidden="true" />
                                    <ActionLabel>
                                        {transfer.recount_open
                                            ? 'Hitung Ulang'
                                            : 'Terima'}
                                    </ActionLabel>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard
                        title="Barang dimutasi"
                        icon={ClipboardList}
                        action={
                            <span className="rounded-full border bg-muted/50 px-2.5 py-0.5 text-xs text-muted-foreground tabular-nums">
                                {transfer.lines.length} baris
                            </span>
                        }
                    >
                        <DocumentLines
                            rows={transfer.lines}
                            rowKey={(l) => l.id}
                            columns={COLUMNS}
                            caption={`Baris mutasi ${transfer.number}`}
                            footer={
                                transfer.status === 'draft' && (
                                    <p className="text-xs">
                                        Baris tanpa batch dialokasikan otomatis
                                        (ED terdekat dulu) saat dikirim.
                                    </p>
                                )
                            }
                        />
                    </DetailCard>

                    <div className="space-y-5">
                        <DetailCard title="Ringkasan" icon={ArrowLeftRight}>
                            <FieldGrid>
                                <Field
                                    label="Dari"
                                    value={transfer.from_location.name}
                                    hint={transfer.from_location.code}
                                    wide
                                />
                                <Field
                                    label="Ke"
                                    value={transfer.to_location.name}
                                    hint={transfer.to_location.code}
                                    wide
                                />
                                <Field
                                    label="Kendaraan"
                                    value={
                                        transfer.vehicle &&
                                        `${transfer.vehicle.plate_no} · ${transfer.vehicle.name}`
                                    }
                                    wide
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={transfer.created_by}
                                />
                                <Field
                                    label="Dikirim oleh"
                                    value={transfer.shipped_by}
                                />
                                <Field
                                    label="Diterima oleh"
                                    value={transfer.received_by}
                                />
                                <Field
                                    label="Catatan"
                                    value={transfer.note}
                                    wide
                                />
                            </FieldGrid>
                        </DetailCard>

                        {(transfer.approval_request_id !== null ||
                            varianceLines.length > 0) && (
                            <DetailCard
                                title="Persetujuan selisih"
                                icon={Stamp}
                            >
                                <p className="text-sm text-muted-foreground">
                                    {awaitingApproval
                                        ? 'Barang yang kurang tetap tercatat dalam perjalanan sampai selisihnya diputuskan.'
                                        : transfer.status === 'closed'
                                          ? 'Selisih disetujui dan dikeluarkan dari stok dalam perjalanan.'
                                          : 'Selisih belum diajukan ulang. Hitung ulang barangnya.'}
                                </p>
                                {transfer.approval_request_id !== null && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="mt-3"
                                        asChild
                                    >
                                        <Link
                                            href={`/persetujuan/${transfer.approval_request_id}`}
                                        >
                                            Lihat persetujuan
                                        </Link>
                                    </Button>
                                )}
                            </DetailCard>
                        )}

                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat mutasi ${transfer.number}`}
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

            {(dialog === 'ship' || dialog === 'cancel') && (
                <ConfirmDialog
                    kind={dialog}
                    transfer={transfer}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'receive' && (
                <ReceiveDialog
                    transfer={transfer}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

const CONFIRM = {
    ship: {
        title: 'Kirim mutasi?',
        description:
            'Stok berpindah dari lokasi asal ke "dalam perjalanan" sekarang. Setelah dikirim, mutasi tidak bisa diubah atau dibatalkan.',
        button: 'Kirim',
        path: 'kirim',
        icon: Send,
        tone: 'default',
    },
    cancel: {
        title: 'Batalkan mutasi?',
        description:
            'Draf dibatalkan dan batch yang ditahan dilepas. Nomornya tetap tercatat.',
        button: 'Batalkan mutasi',
        path: 'batal',
        icon: Ban,
        tone: 'danger',
    },
} as const;

function ConfirmDialog({
    kind,
    transfer,
    onClose,
}: {
    kind: 'ship' | 'cancel';
    transfer: StockTransferDetail;
    onClose: () => void;
}) {
    const action = CONFIRM[kind];
    const form = useForm({});

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/mutasi/${transfer.id}/${action.path}`, {
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
                <span className="font-mono font-medium">{transfer.number}</span>{' '}
                · {transfer.from_location.name} → {transfer.to_location.name}
            </p>
            {/* Galat aksi (stok kurang, izin edar, lokasi nonaktif) dari server; toast juga tampil. */}
            {Object.values(form.errors as Record<string, string>).map(
                (message) => (
                    <InputError key={message} message={message} />
                ),
            )}
        </FormDialog>
    );
}

type ReceiveRow = {
    line_id: number;
    qty_received: string;
    dest_bin_id: string;
    variance_reason: string;
};

/** "48.000" → "48" (isian awal yang nyaman diketik ulang, koma desimal Indonesia). */
const toInput = (q: string | null) =>
    q === null
        ? ''
        : q
              .replace(/\.0+$/, '')
              .replace(/(\.\d*?)0+$/, '$1')
              .replace('.', ',');

/**
 * Terima per baris (BR-161). Qty = jumlah TOTAL yang dihitung (bukan tambahan), maksimal jumlah
 * dikirim. Kurang dari dikirim wajib alasan; sisanya tetap di "dalam perjalanan" sampai selisih
 * diputuskan. Saat hitung ulang hanya baris yang masih selisih yang tampil, dan rak tetap rak
 * penerimaan pertama.
 */
function ReceiveDialog({
    transfer,
    onClose,
}: {
    transfer: StockTransferDetail;
    onClose: () => void;
}) {
    const lines = transfer.recount_open
        ? transfer.lines.filter(hasVariance)
        : transfer.lines;
    const form = useForm<{ lines: ReceiveRow[] }>({
        lines: lines.map((l) => ({
            line_id: l.id,
            qty_received: toInput(
                transfer.recount_open
                    ? l.qty_received_base
                    : l.qty_shipped_base,
            ),
            dest_bin_id: l.dest_bin ? String(l.dest_bin.id) : '',
            variance_reason: l.variance_reason ?? '',
        })),
    });
    const err = form.errors as Record<string, string | undefined>;

    const setRow = (index: number, patch: Partial<ReceiveRow>) =>
        form.setData(
            'lines',
            form.data.lines.map((r, i) =>
                i === index ? { ...r, ...patch } : r,
            ),
        );

    const short = (index: number) => {
        const counted = parseQtyInput(form.data.lines[index].qty_received);
        const shipped = lines[index].qty_shipped_base;

        return (
            counted !== null &&
            shipped !== null &&
            Number(counted) < Number(shipped)
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            lines: d.lines.map((r) => ({
                line_id: r.line_id,
                qty_received: r.qty_received,
                dest_bin_id:
                    r.dest_bin_id === '' ? null : Number(r.dest_bin_id),
                variance_reason: r.variance_reason,
            })),
        }));
        form.post(`/mutasi/${transfer.id}/terima`, {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={PackageCheck}
            size="lg"
            title={
                transfer.recount_open
                    ? `Hitung ulang ${transfer.number}`
                    : `Terima ${transfer.number}`
            }
            description="Isi jumlah yang benar-benar dihitung per baris. Barang yang kurang tetap tercatat dalam perjalanan sampai selisihnya disetujui."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Simpan Penerimaan
                    </Button>
                </>
            }
        >
            <InputError message={err.lines ?? err.form} />
            <ul className="space-y-3">
                {lines.map((line, index) => {
                    const row = form.data.lines[index];

                    return (
                        <li
                            key={line.id}
                            className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,1.6fr)_8rem_minmax(0,1fr)]"
                        >
                            <div className="grid content-start gap-0.5 sm:col-span-3">
                                <span className="font-medium break-words">
                                    {line.name}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    <span className="font-mono">
                                        {line.sku}
                                    </span>
                                    {line.source_batch &&
                                        ` · batch ${line.source_batch.batch_no}`}{' '}
                                    · dikirim{' '}
                                    {qtyOrDash(
                                        line.qty_shipped_base,
                                        line.unit,
                                    )}
                                    {transfer.recount_open &&
                                        ` · sudah diterima ${qtyOrDash(line.qty_received_base, line.unit)}`}
                                </span>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`rcv-qty-${index}`}>
                                    Jumlah dihitung
                                </Label>
                                <Input
                                    id={`rcv-qty-${index}`}
                                    inputMode="decimal"
                                    autoComplete="off"
                                    className="text-right tabular-nums"
                                    value={row.qty_received}
                                    onChange={(e) =>
                                        setRow(index, {
                                            qty_received: e.target.value,
                                        })
                                    }
                                />
                                <InputError
                                    message={err[`lines.${index}.qty_received`]}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={`rcv-bin-${index}`}>
                                    Rak tujuan (opsional)
                                </Label>
                                <AsyncSelect
                                    id={`rcv-bin-${index}`}
                                    source="stock_bins"
                                    params={{
                                        parent: String(transfer.to_location.id),
                                    }}
                                    value={
                                        row.dest_bin_id === ''
                                            ? []
                                            : [row.dest_bin_id]
                                    }
                                    disabled={line.dest_bin !== null}
                                    onChange={(v) =>
                                        setRow(index, {
                                            dest_bin_id: String(v[0] ?? ''),
                                        })
                                    }
                                    placeholder="Tanpa rak"
                                />
                                <InputError
                                    message={err[`lines.${index}.dest_bin_id`]}
                                />
                            </div>
                            {short(index) && (
                                <div className="grid gap-1.5 sm:col-span-3">
                                    <Label htmlFor={`rcv-reason-${index}`}>
                                        Alasan selisih
                                    </Label>
                                    <Input
                                        id={`rcv-reason-${index}`}
                                        maxLength={255}
                                        placeholder="Mis. 2 botol pecah di jalan"
                                        value={row.variance_reason}
                                        onChange={(e) =>
                                            setRow(index, {
                                                variance_reason: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[
                                                `lines.${index}.variance_reason`
                                            ]
                                        }
                                    />
                                </div>
                            )}
                        </li>
                    );
                })}
            </ul>
            <p className="inline-flex items-start gap-1.5 text-xs text-muted-foreground">
                <UserRound
                    className="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                Penerimaan harus dicatat orang lain, bukan pengirim mutasi.
            </p>
            <p className="inline-flex items-start gap-1.5 text-xs text-muted-foreground">
                <CalendarClock
                    className="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />
                Tanggal terima = hari ini.
            </p>
        </FormDialog>
    );
}

StockTransferShow.layout = {
    breadcrumbs: [{ title: 'Mutasi Stok', href: '/mutasi' }],
};
