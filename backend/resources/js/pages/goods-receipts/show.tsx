import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    ClipboardCheck,
    History,
    PackageCheck,
    Pencil,
    ReceiptText,
    ScanBarcode,
    Stamp,
    Truck,
    Undo2,
    Upload,
    Wallet,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { ActionLabel } from '@/components/pakis/detail-hero';
import type { DetailHeroHighlight } from '@/components/pakis/detail-hero';
import { DocumentHeader } from '@/components/pakis/document-header';
import { DocumentLines } from '@/components/pakis/document-lines';
import type { DocumentLineColumn } from '@/components/pakis/document-lines';
import { DocumentTimeline } from '@/components/pakis/document-timeline';
import { FormDialog } from '@/components/pakis/form-dialog';
import { PhotoCapture } from '@/components/pakis/photo-capture';
import { ScanField } from '@/components/pakis/scan-field';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';

export type GoodsReceiptLine = {
    id: number;
    purchase_order_line_id: number | null;
    product_id: number;
    sku: string;
    name: string;
    tracks_batch: boolean;
    tracks_expiry: boolean;
    tracks_serial: boolean;
    requires_registration: boolean;
    product_unit_id: number;
    unit: string;
    base_unit: string;
    factor: string;
    qty_received: string;
    qty_received_base: string;
    qty_rejected_base: string;
    reject_reason_id: number | null;
    reject_reason: string | null;
    batch_no: string;
    expiry_date: string | null;
    registration_no_seen: string | null;
    stock_bin_id: number | null;
    bin: string | null;
    shelf_life_months: string | null;
    line_status: string;
    line_status_label: string;
    stock_batch_id: number | null;
    batch_status: string | null;
    serials: { id: number; serial_no: string; input_method: string }[];
    /** Hanya untuk pemegang `purchase_orders.view` / `stock.view_cost` (SEC-107). */
    unit_cost?: string;
    line_value?: string;
};

export type GoodsReceiptDetail = {
    id: number;
    number: string;
    purchase_order: { id: number; number: string; status: string } | null;
    supplier: { id: number; code: string; name: string };
    stock_location: { id: number; code: string; name: string };
    received_at: string;
    received_at_local: string;
    business_date: string;
    supplier_delivery_no: string | null;
    vehicle_plate: string | null;
    status: string;
    status_label: string;
    approval_request_id: number | null;
    checked_by: string | null;
    checked_at: string | null;
    posted_by: string | null;
    posted_at: string | null;
    note: string | null;
    created_by: string | null;
    created_at: string | null;
    show_cost: boolean;
    total_value: string | null;
    lines: GoodsReceiptLine[];
};

type HistoryEntry = {
    id: number;
    occurred_at: string;
    actor: string;
    event_label: string;
};

type Props = {
    receipt: GoodsReceiptDetail;
    history: HistoryEntry[];
    twoPerson: boolean;
    can: {
        edit: boolean;
        check: boolean;
        post: boolean;
        post_blocked_same_person: boolean;
        serials: boolean;
        view_po: boolean;
        /** Retur batch penerimaan ini ke prinsipal (T-081). */
        return?: boolean;
    };
};

const unitLower = (s: string) => s.toLowerCase();
const months = (v: string) => v.replace(/\.0$/, '').replace('.', ',');

function serialTarget(l: GoodsReceiptLine): number | null {
    const n = Number(l.qty_received_base);

    return Number.isInteger(n) ? n : null;
}

/**
 * Detail penerimaan (15 §7.1 dokumen bergaris, FR-A25). Aksi sesuai status & izin dari server:
 * draf → Periksa (+ isi nomor seri), diperiksa → Posting oleh orang lain bila aturan dua orang aktif
 * (SEC-104). Harga beli hanya tampil bila server mengirimnya (SEC-107).
 */
export default function GoodsReceiptShow({
    receipt,
    history,
    twoPerson,
    can,
}: Props) {
    const { timezone } = usePage<{ timezone: string }>().props;
    const [dialog, setDialog] = useState<'check' | 'post' | null>(null);
    const [serialLine, setSerialLine] = useState<GoodsReceiptLine | null>(null);
    const short = receipt.lines.filter(
        (l) => l.line_status === 'short_shelf_life',
    ).length;
    const mismatch = receipt.lines.filter(
        (l) => l.line_status === 'registration_mismatch',
    ).length;
    const checked = receipt.status !== 'draft';
    const serialMissing = receipt.lines.filter(
        (l) => l.tracks_serial && l.serials.length !== serialTarget(l),
    ).length;

    const columns: DocumentLineColumn<GoodsReceiptLine>[] = [
        {
            key: 'product',
            header: 'Produk',
            primary: true,
            cell: (l) => (
                <span className="grid gap-0.5">
                    <span className="font-medium break-words">{l.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {l.sku}
                        {l.batch_no !== '-' && ` · batch ${l.batch_no}`}
                    </span>
                    {checked && l.line_status !== 'ok' && (
                        <span>
                            <StatusBadge
                                status={`receipt_line.${l.line_status}`}
                                label={l.line_status_label}
                            />
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'Diterima',
            numeric: true,
            cell: (l) => (
                <span className="grid justify-items-end">
                    <span>
                        <span className="font-medium">
                            {formatQty(l.qty_received)}
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {unitLower(l.unit)}
                        </span>
                    </span>
                    {l.factor !== '1' && (
                        <span className="text-xs text-muted-foreground">
                            = {formatQty(l.qty_received_base)}{' '}
                            {unitLower(l.base_unit)}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'rejected',
            header: 'Ditolak',
            numeric: true,
            cell: (l) =>
                Number(l.qty_rejected_base) > 0 ? (
                    <span className="grid justify-items-end">
                        <span className="text-status-danger">
                            {formatQty(l.qty_rejected_base)}{' '}
                            {unitLower(l.base_unit)}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {l.reject_reason}
                        </span>
                    </span>
                ) : (
                    '–'
                ),
        },
        {
            key: 'expiry',
            header: 'ED · sisa umur',
            cell: (l) =>
                l.expiry_date ? (
                    <span className="grid">
                        <span>{formatCivilDate(l.expiry_date)}</span>
                        {l.shelf_life_months !== null && (
                            <span
                                className={
                                    l.line_status === 'short_shelf_life'
                                        ? 'text-xs text-status-warning'
                                        : 'text-xs text-muted-foreground'
                                }
                            >
                                sisa {months(l.shelf_life_months)} bulan
                            </span>
                        )}
                    </span>
                ) : (
                    '–'
                ),
        },
        {
            key: 'reg',
            header: 'Izin di kemasan',
            hideOnMobile: true,
            cell: (l) => (
                <span className="font-mono text-xs">
                    {l.registration_no_seen ?? '–'}
                </span>
            ),
        },
        {
            key: 'bin',
            header: 'Rak',
            cell: (l) =>
                l.line_status === 'registration_mismatch' && checked
                    ? 'Q-01 (karantina)'
                    : (l.bin ?? '–'),
        },
    ];
    if (receipt.lines.some((l) => l.tracks_serial)) {
        columns.push({
            key: 'serials',
            header: 'Nomor seri',
            cell: (l) =>
                l.tracks_serial ? (
                    <span className="inline-flex flex-wrap items-center gap-2">
                        <span
                            className={
                                l.serials.length === serialTarget(l)
                                    ? 'text-status-success'
                                    : 'text-status-warning'
                            }
                        >
                            {l.serials.length}/{serialTarget(l) ?? '?'}
                        </span>
                        {can.serials ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setSerialLine(l)}
                                aria-label={`Isi nomor seri ${l.sku}`}
                            >
                                <ScanBarcode aria-hidden="true" />
                                Isi
                            </Button>
                        ) : (
                            l.serials.length > 0 && (
                                <span className="font-mono text-xs text-muted-foreground">
                                    {l.serials
                                        .slice(0, 3)
                                        .map((s) => s.serial_no)
                                        .join(', ')}
                                    {l.serials.length > 3 && ' …'}
                                </span>
                            )
                        )}
                    </span>
                ) : (
                    '–'
                ),
        });
    }
    if (receipt.show_cost) {
        columns.push(
            {
                key: 'unit_cost',
                header: 'Biaya / satuan dasar',
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
            icon: CalendarClock,
            label: 'Diterima',
            value: formatDateTimeIn(receipt.received_at, timezone),
        },
        receipt.show_cost
            ? {
                  icon: Wallet,
                  label: 'Nilai diterima',
                  value: formatRupiah(receipt.total_value),
              }
            : {
                  icon: PackageCheck,
                  label: 'Baris',
                  value: `${receipt.lines.length} produk`,
              },
        checked
            ? {
                  icon: ClipboardCheck,
                  label: 'Pemeriksaan',
                  value:
                      short + mismatch === 0
                          ? 'Semua baris sesuai'
                          : [
                                short > 0 && `${short} sisa umur pendek`,
                                mismatch > 0 && `${mismatch} izin tidak cocok`,
                            ]
                                .filter(Boolean)
                                .join(' · '),
                  tone: short + mismatch === 0 ? 'success' : 'warning',
              }
            : {
                  icon: ClipboardCheck,
                  label: 'Pemeriksaan',
                  value: 'Belum diperiksa',
              },
    ];

    return (
        <>
            <Head title={`Penerimaan ${receipt.number}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <DocumentHeader
                    backHref="/penerimaan"
                    backLabel="Daftar penerimaan"
                    kind="Penerimaan barang"
                    icon={PackageCheck}
                    number={receipt.number}
                    status={`goods_receipt.${receipt.status}`}
                    statusLabel={receipt.status_label}
                    muted={receipt.status === 'rejected'}
                    meta={[
                        {
                            icon: Truck,
                            label: 'Supplier ke lokasi stok',
                            value: (
                                <>
                                    {receipt.supplier.name} →{' '}
                                    {receipt.stock_location.name}
                                </>
                            ),
                        },
                        {
                            icon: ReceiptText,
                            label: 'Purchase order',
                            value: receipt.purchase_order?.number,
                        },
                    ]}
                    highlights={highlights}
                    notice={
                        can.post_blocked_same_person && can.post ? (
                            <p className="text-sm">
                                Anda yang memeriksa penerimaan ini. Posting
                                harus dilakukan rekan lain (aturan dua orang).
                            </p>
                        ) : serialMissing > 0 && receipt.status === 'draft' ? (
                            <p className="text-sm">
                                {serialMissing} baris alat belum lengkap nomor
                                serinya. Isi sebelum diperiksa.
                            </p>
                        ) : undefined
                    }
                    actions={
                        <>
                            {can.edit && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/penerimaan/${receipt.id}/ubah`}
                                        aria-label={`Ubah penerimaan ${receipt.number}`}
                                    >
                                        <Pencil aria-hidden="true" />
                                        <ActionLabel>Ubah</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                            {can.check && (
                                <Button
                                    size="sm"
                                    onClick={() => setDialog('check')}
                                    aria-label={`Periksa penerimaan ${receipt.number}`}
                                >
                                    <ClipboardCheck aria-hidden="true" />
                                    <ActionLabel>Periksa</ActionLabel>
                                </Button>
                            )}
                            {can.return && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link
                                        href={`/pembelian/retur/baru?gr=${receipt.id}`}
                                        aria-label={`Retur penerimaan ${receipt.number} ke prinsipal`}
                                    >
                                        <Undo2 aria-hidden="true" />
                                        <ActionLabel>Retur ke Prinsipal</ActionLabel>
                                    </Link>
                                </Button>
                            )}
                            {can.post && (
                                <Button
                                    size="sm"
                                    disabled={can.post_blocked_same_person}
                                    onClick={() => setDialog('post')}
                                    aria-label={`Posting penerimaan ${receipt.number}`}
                                >
                                    <PackageCheck aria-hidden="true" />
                                    <ActionLabel>Posting ke Stok</ActionLabel>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <DetailCard
                        title="Barang diterima"
                        icon={PackageCheck}
                        action={
                            <span className="rounded-full border bg-muted/50 px-2.5 py-0.5 text-xs text-muted-foreground tabular-nums">
                                {receipt.lines.length} produk
                            </span>
                        }
                    >
                        <DocumentLines
                            rows={receipt.lines}
                            rowKey={(l) => l.id}
                            columns={columns}
                            caption={`Baris penerimaan ${receipt.number}`}
                            footer={
                                mismatch > 0 &&
                                checked && (
                                    <p className="text-xs">
                                        Baris dengan izin edar tidak cocok masuk
                                        rak karantina Q-01 saat diposting dan
                                        tidak bisa dijual sampai dilepas.
                                    </p>
                                )
                            }
                            totals={
                                receipt.show_cost
                                    ? [
                                          {
                                              label: 'Nilai diterima',
                                              value: formatRupiah(
                                                  receipt.total_value,
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
                                    label="Purchase order"
                                    value={
                                        receipt.purchase_order &&
                                        (can.view_po ? (
                                            <Link
                                                href={`/pembelian/po/${receipt.purchase_order.id}`}
                                                className="font-mono font-medium text-primary underline-offset-4 hover:underline"
                                            >
                                                {receipt.purchase_order.number}
                                            </Link>
                                        ) : (
                                            receipt.purchase_order.number
                                        ))
                                    }
                                    wide
                                />
                                <Field
                                    label="Supplier"
                                    value={receipt.supplier.name}
                                    hint={receipt.supplier.code}
                                    wide
                                />
                                <Field
                                    label="Lokasi stok"
                                    value={receipt.stock_location.name}
                                    wide
                                />
                                <Field
                                    label="Surat jalan"
                                    value={receipt.supplier_delivery_no}
                                />
                                <Field
                                    label="Kendaraan"
                                    value={receipt.vehicle_plate}
                                />
                                <Field
                                    label="Dibuat oleh"
                                    value={receipt.created_by}
                                />
                                <Field
                                    label="Diperiksa oleh"
                                    value={receipt.checked_by}
                                />
                                <Field
                                    label="Diposting oleh"
                                    value={receipt.posted_by}
                                />
                                <Field
                                    label="Catatan"
                                    value={receipt.note}
                                    wide
                                />
                            </FieldGrid>
                        </DetailCard>

                        {receipt.approval_request_id !== null && (
                            <DetailCard title="Persetujuan" icon={Stamp}>
                                <p className="text-sm text-muted-foreground">
                                    {receipt.status === 'pending_approval'
                                        ? 'Ada batch dengan sisa umur di bawah batas. Menunggu keputusan kepala gudang.'
                                        : 'Keputusan tercatat di permintaan persetujuan.'}
                                </p>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-3"
                                    asChild
                                >
                                    <Link
                                        href={`/persetujuan/${receipt.approval_request_id}`}
                                    >
                                        Lihat persetujuan
                                    </Link>
                                </Button>
                            </DetailCard>
                        )}

                        <DetailCard title="Riwayat" icon={History}>
                            <DocumentTimeline
                                label={`Riwayat penerimaan ${receipt.number}`}
                                items={history.map((h) => ({
                                    key: h.id,
                                    title: h.event_label,
                                    meta: `${h.actor} · ${formatDateTimeIn(h.occurred_at, timezone)}`,
                                }))}
                            />
                        </DetailCard>
                    </div>
                </div>
            </div>

            {dialog !== null && (
                <StepDialog
                    kind={dialog}
                    receipt={receipt}
                    twoPerson={twoPerson}
                    shortLines={short}
                    onClose={() => setDialog(null)}
                />
            )}
            {serialLine !== null && (
                <SerialDialog
                    receipt={receipt}
                    line={serialLine}
                    onClose={() => setSerialLine(null)}
                />
            )}
        </>
    );
}

function StepDialog({
    kind,
    receipt,
    twoPerson,
    shortLines,
    onClose,
}: {
    kind: 'check' | 'post';
    receipt: GoodsReceiptDetail;
    twoPerson: boolean;
    shortLines: number;
    onClose: () => void;
}) {
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;
    const check = kind === 'check';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(
            `/penerimaan/${receipt.id}/${check ? 'periksa' : 'posting'}`,
            { preserveScroll: true, onSuccess: () => onClose() },
        );
    };
    const firstError = Object.values(errors).find(Boolean);

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={check ? ClipboardCheck : PackageCheck}
            title={check ? 'Periksa penerimaan?' : 'Posting ke stok?'}
            description={
                check
                    ? 'Sistem menghitung sisa umur tiap batch, mencocokkan nomor izin edar, dan memastikan jumlah tidak melebihi PO. Setelah diperiksa, penerimaan siap diposting.'
                    : shortLines > 0
                      ? 'Ada batch dengan sisa umur pendek, jadi penerimaan akan dikirim ke kepala gudang untuk disetujui. Stok bertambah setelah disetujui.'
                      : `Stok bertambah dan PO diperbarui. Langkah ini tidak bisa dibatalkan.${twoPerson ? ' Posting harus oleh orang selain pemeriksa.' : ''}`
            }
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {check ? 'Periksa' : 'Posting'}
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                <span className="font-mono font-medium">{receipt.number}</span>{' '}
                · {receipt.supplier.name} · {receipt.lines.length} baris
            </p>
            <InputError message={firstError} />
        </FormDialog>
    );
}

type SerialMode = 'scan' | 'paste' | 'manual';

/**
 * Nomor seri satu baris (BR-144, SEC-109): scan satu per satu (scanner HID / foto barcode), tempel
 * daftar (satu per baris), atau ketik manual dengan foto label wajib. Yang dikirim = daftar lengkap
 * baris itu; server menormalisasi, menolak kembar, dan mencocokkan jumlah saat diperiksa.
 */
function SerialDialog({
    receipt,
    line,
    onClose,
}: {
    receipt: GoodsReceiptDetail;
    line: GoodsReceiptLine;
    onClose: () => void;
}) {
    const target = serialTarget(line);
    const [mode, setMode] = useState<SerialMode>('scan');
    const [list, setList] = useState<string[]>(
        line.serials.map((s) => s.serial_no),
    );
    const [pasted, setPasted] = useState('');
    const form = useForm<{
        serials: string[];
        method: 'scan' | 'manual';
        photo: File | null;
        _method: 'put';
    }>({ serials: [], method: 'scan', photo: null, _method: 'put' });
    const errors = form.errors as Record<string, string | undefined>;
    const normalize = (s: string) => s.replace(/\s+/g, '').toUpperCase();

    const add = (code: string): boolean | string => {
        const no = normalize(code);
        if (list.includes(no)) {
            return `${no} sudah ada di daftar.`;
        }
        if (target !== null && list.length >= target) {
            return `Sudah ${target} nomor seri (sesuai jumlah diterima).`;
        }
        setList([...list, no]);

        return true;
    };

    const finalList =
        mode === 'paste'
            ? [
                  ...list,
                  ...pasted
                      .split(/[\r\n,;]+/)
                      .map(normalize)
                      .filter((s) => s !== '' && !list.includes(s)),
              ]
            : list;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            ...d,
            serials: finalList,
            method: mode === 'manual' ? 'manual' : 'scan',
            photo: mode === 'manual' ? d.photo : null,
        }));
        form.post(`/penerimaan/${receipt.id}/baris/${line.id}/seri`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            size="lg"
            onOpenChange={(o) => !o && onClose()}
            icon={ScanBarcode}
            title={`Nomor seri ${line.sku}`}
            description={`${line.name}. Butuh ${target ?? '?'} nomor seri (sama dengan jumlah diterima). Nomor disimpan huruf besar tanpa spasi.`}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Simpan {finalList.length} nomor
                    </Button>
                </>
            }
        >
            <div
                role="tablist"
                aria-label="Cara input"
                className="flex flex-wrap gap-2"
            >
                {(
                    [
                        ['scan', 'Scan satu-satu'],
                        ['paste', 'Tempel daftar'],
                        ['manual', 'Manual + foto label'],
                    ] as const
                ).map(([key, label]) => (
                    <Button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={mode === key}
                        size="sm"
                        variant={mode === key ? 'default' : 'outline'}
                        onClick={() => setMode(key)}
                    >
                        {label}
                    </Button>
                ))}
            </div>

            {mode === 'paste' ? (
                <div className="grid gap-2">
                    <Label htmlFor="serial-paste">
                        Tempel daftar (satu nomor per baris)
                    </Label>
                    <textarea
                        id="serial-paste"
                        className="min-h-32 w-full rounded-md border bg-transparent px-3 py-2 font-mono text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        value={pasted}
                        onChange={(e) => setPasted(e.target.value)}
                    />
                </div>
            ) : (
                <ScanField
                    id="serial-scan"
                    label={
                        mode === 'manual'
                            ? 'Ketik nomor seri lalu Enter'
                            : 'Pindai nomor seri'
                    }
                    autoFocus
                    onScan={add}
                    hint="Scanner barcode langsung menambah nomor ke daftar."
                />
            )}

            {mode === 'manual' && (
                <PhotoCapture
                    id="serial-photo"
                    label="Foto label nomor seri"
                    value={form.data.photo}
                    onChange={(file) => form.setData('photo', file)}
                    error={errors.photo}
                    hint="Wajib untuk input manual: foto label yang memuat nomor seri."
                />
            )}

            <div className="grid gap-2">
                <p className="text-sm font-medium">
                    Daftar ({finalList.length}
                    {target !== null && `/${target}`})
                </p>
                {list.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Belum ada nomor seri.
                    </p>
                ) : (
                    <ul className="flex max-h-48 flex-wrap gap-1.5 overflow-y-auto">
                        {list.map((no) => (
                            <li
                                key={no}
                                className="inline-flex items-center gap-1 rounded-md border bg-muted/40 py-0.5 pr-1 pl-2 font-mono text-xs"
                            >
                                {no}
                                <button
                                    type="button"
                                    className="rounded p-0.5 hover:bg-muted"
                                    aria-label={`Hapus ${no}`}
                                    onClick={() =>
                                        setList(list.filter((s) => s !== no))
                                    }
                                >
                                    <X className="size-3" aria-hidden="true" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <InputError
                    message={errors.serials ?? errors.form ?? errors.method}
                />
            </div>
            <p className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                <Upload className="size-3.5" aria-hidden="true" />
                Daftar yang disimpan menggantikan daftar lama baris ini.
            </p>
        </FormDialog>
    );
}

GoodsReceiptShow.layout = {
    breadcrumbs: [{ title: 'Penerimaan Barang', href: '/penerimaan' }],
};
