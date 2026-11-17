import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { MoneyInput } from '@/components/pakis/money-input';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { GoodsReceiptDetail } from './show';

type Option = { value: number; label: string };

export type OrderLineForReceipt = {
    id: number;
    line_no: number;
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
    qty: string;
    qty_base: string;
    qty_received_base: string;
    remaining_base: string;
    unit_cost: string | null;
};

type OrderForReceipt = {
    id: number;
    number: string;
    status: string;
    status_label: string;
    open: boolean;
    supplier: { id: number; name: string };
    stock_location: { id: number; name: string };
    lines: OrderLineForReceipt[];
};

type Props = {
    receipt: GoodsReceiptDetail | null;
    order: OrderForReceipt | null;
    now: string;
    allowWithoutPo: boolean;
    tolerancePct: string;
    minShelfLifeMonths: number;
    rejectReasons: Option[];
    bins: Option[];
};

type Line = {
    id: number | null;
    purchase_order_line_id: number | null;
    product_id: string;
    product_unit_id: string;
    title: string;
    unit: string;
    base_unit: string;
    factor: string;
    remaining_base: string | null;
    tracks_batch: boolean;
    tracks_expiry: boolean;
    tracks_serial: boolean;
    requires_registration: boolean;
    qty_received: string;
    qty_rejected: string;
    reject_reason_id: string;
    batch_no: string;
    expiry_date: string;
    registration_no_seen: string;
    stock_bin_id: string;
    unit_cost: string;
};

const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const decimalText = (canonical: string) =>
    canonical.replace(/\.?0+$/, '').replace('.', ',');

function fromOrderLine(l: OrderLineForReceipt): Line {
    // Qty awal = sisa PO bila satuannya satuan dasar; selain itu diisi petugas (sisa tampil sebagai petunjuk).
    const prefill =
        l.factor === '1' || l.factor === '1.000'
            ? decimalText(l.remaining_base)
            : '';

    return {
        id: null,
        purchase_order_line_id: l.id,
        product_id: String(l.product_id),
        product_unit_id: String(l.product_unit_id),
        title: `${l.sku} · ${l.name}`,
        unit: l.unit,
        base_unit: l.base_unit,
        factor: l.factor,
        remaining_base: l.remaining_base,
        tracks_batch: l.tracks_batch,
        tracks_expiry: l.tracks_expiry,
        tracks_serial: l.tracks_serial,
        requires_registration: l.requires_registration,
        qty_received: prefill === '0' ? '' : prefill,
        qty_rejected: '',
        reject_reason_id: '',
        batch_no: '',
        expiry_date: '',
        registration_no_seen: '',
        stock_bin_id: '',
        unit_cost: '',
    };
}

const BLANK_FREE_LINE: Line = {
    id: null,
    purchase_order_line_id: null,
    product_id: '',
    product_unit_id: '',
    title: '',
    unit: '',
    base_unit: '',
    factor: '1',
    remaining_base: null,
    tracks_batch: true,
    tracks_expiry: true,
    tracks_serial: false,
    requires_registration: true,
    qty_received: '',
    qty_rejected: '',
    reject_reason_id: '',
    batch_no: '',
    expiry_date: '',
    registration_no_seen: '',
    stock_bin_id: '',
    unit_cost: '',
};

/**
 * Enter dari scanner HID (keyboard wedge) di isian batch/izin tidak boleh mengirim form: pindah ke
 * isian berikutnya saja (15 §7.1 "Pemindai").
 */
function nextOnEnter(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key !== 'Enter') {
        return;
    }
    event.preventDefault();
    const fields = Array.from(
        event.currentTarget.form?.querySelectorAll<HTMLElement>(
            'input:not([type=hidden]):not([disabled]), select, textarea',
        ) ?? [],
    );
    const index = fields.indexOf(event.currentTarget);
    fields[index + 1]?.focus();
}

/**
 * Buat/ubah draf penerimaan (FR-A25, BR-158). Dari PO: baris PO terisi otomatis, biaya mengikuti PO
 * (tidak bisa diubah, SEC-104). Tanpa PO (bila diizinkan setting): supplier, lokasi, produk, dan biaya
 * diketik. Pemeriksaan sisa umur/izin/over-receipt dilakukan server saat "Periksa".
 */
export default function GoodsReceiptForm({
    receipt,
    order,
    now,
    allowWithoutPo,
    tolerancePct,
    minShelfLifeMonths,
    rejectReasons,
    bins,
}: Props) {
    const editing = receipt !== null;
    const withPo = order !== null;
    const orderLines = order?.lines ?? [];

    const initialLines = (): Line[] => {
        if (receipt) {
            return receipt.lines.map((l) => ({
                id: l.id,
                purchase_order_line_id: l.purchase_order_line_id,
                product_id: String(l.product_id),
                product_unit_id: String(l.product_unit_id),
                title: `${l.sku} · ${l.name}`,
                unit: l.unit,
                base_unit: l.base_unit,
                factor: l.factor,
                remaining_base:
                    orderLines.find((o) => o.id === l.purchase_order_line_id)
                        ?.remaining_base ?? null,
                tracks_batch: l.tracks_batch,
                tracks_expiry: l.tracks_expiry,
                tracks_serial: l.tracks_serial,
                requires_registration: l.requires_registration,
                qty_received: decimalText(l.qty_received),
                qty_rejected:
                    Number(l.qty_rejected_base) > 0
                        ? decimalText(l.qty_rejected_base)
                        : '',
                reject_reason_id: l.reject_reason_id
                    ? String(l.reject_reason_id)
                    : '',
                batch_no: l.batch_no === '-' ? '' : l.batch_no,
                expiry_date: l.expiry_date ?? '',
                registration_no_seen: l.registration_no_seen ?? '',
                stock_bin_id: l.stock_bin_id ? String(l.stock_bin_id) : '',
                unit_cost: l.unit_cost ?? '',
            }));
        }

        return withPo
            ? orderLines
                  .filter((l) => Number(l.remaining_base) > 0)
                  .map(fromOrderLine)
            : [{ ...BLANK_FREE_LINE }];
    };

    const form = useForm({
        purchase_order_id: order ? String(order.id) : '',
        supplier_id: receipt ? String(receipt.supplier.id) : '',
        stock_location_id: receipt ? String(receipt.stock_location.id) : '',
        received_at: receipt?.received_at_local ?? now,
        supplier_delivery_no: receipt?.supplier_delivery_no ?? '',
        vehicle_plate: receipt?.vehicle_plate ?? '',
        note: receipt?.note ?? '',
        lines: initialLines(),
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;
    const [addLineId, setAddLineId] = useState('');

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const missingPoLines = orderLines.filter(
        (o) => !data.lines.some((l) => l.purchase_order_line_id === o.id),
    );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            purchase_order_id:
                d.purchase_order_id === '' ? null : Number(d.purchase_order_id),
            supplier_id: d.supplier_id === '' ? null : Number(d.supplier_id),
            stock_location_id:
                d.stock_location_id === '' ? null : Number(d.stock_location_id),
            received_at: d.received_at,
            supplier_delivery_no: d.supplier_delivery_no,
            vehicle_plate: d.vehicle_plate,
            note: d.note,
            lines: d.lines.map((l) => ({
                id: l.id,
                purchase_order_line_id: l.purchase_order_line_id,
                product_unit_id: withPo
                    ? null
                    : l.product_unit_id === ''
                      ? null
                      : Number(l.product_unit_id),
                qty_received: l.qty_received === '' ? '0' : l.qty_received,
                qty_rejected: l.qty_rejected,
                reject_reason_id:
                    l.reject_reason_id === ''
                        ? null
                        : Number(l.reject_reason_id),
                batch_no: l.batch_no,
                expiry_date: l.expiry_date,
                registration_no_seen: l.registration_no_seen,
                stock_bin_id:
                    l.stock_bin_id === '' ? null : Number(l.stock_bin_id),
                // Biaya hanya untuk penerimaan tanpa PO; dengan PO server memakai harga PO (SEC-104).
                ...(withPo ? {} : { unit_cost: l.unit_cost }),
            })),
        }));
        if (editing) {
            form.put(`/penerimaan/${receipt.id}`, { preserveScroll: true });
        } else {
            form.post('/penerimaan', { preserveScroll: true });
        }
    };

    const title = editing
        ? `Ubah Penerimaan ${receipt.number}`
        : 'Terima Barang';
    const back = editing
        ? `/penerimaan/${receipt.id}`
        : order
          ? `/pembelian/po/${order.id}`
          : '/penerimaan';

    if (!withPo && !editing && !allowWithoutPo) {
        return (
            <>
                <Head title={title} />
                <div className="mx-auto w-full max-w-3xl space-y-4 p-4 md:px-7 md:py-6">
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                    <p className="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        Penerimaan selalu dimulai dari PO yang sudah disetujui.
                        Buka detail PO lalu tekan "Terima Barang".
                    </p>
                    <Button variant="outline" asChild>
                        <Link href="/penerimaan">Kembali</Link>
                    </Button>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href={back}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {editing
                        ? 'Kembali ke penerimaan'
                        : order
                          ? `Kembali ke PO ${order.number}`
                          : 'Daftar penerimaan'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>
                {order && !order.open && (
                    <p className="flex items-center gap-2 rounded-lg border border-status-danger/40 bg-status-danger-bg p-3 text-sm text-status-danger">
                        <AlertTriangle className="size-4" aria-hidden="true" />
                        PO {order.number} berstatus {order.status_label}; barang
                        belum/tidak bisa diterima.
                    </p>
                )}
                <InputError message={err.form ?? err.purchase_order_id} />

                <SettingsSection
                    title="Kepala penerimaan"
                    description={
                        order
                            ? `Dari PO ${order.number} · ${order.supplier.name} → ${order.stock_location.name}. Nomor penerimaan dibuat otomatis (mis. GR-2611-0001).`
                            : 'Penerimaan tanpa PO: biaya per satuan dasar diketik dan tercatat di audit.'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {!withPo && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="gr-supplier">
                                        Supplier
                                    </Label>
                                    <AsyncSelect
                                        id="gr-supplier"
                                        source="suppliers"
                                        value={one(data.supplier_id)}
                                        onChange={(v) =>
                                            setData('supplier_id', first(v))
                                        }
                                    />
                                    <InputError message={err.supplier_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="gr-location">
                                        Lokasi stok
                                    </Label>
                                    <AsyncSelect
                                        id="gr-location"
                                        source="stock_locations"
                                        params={{ binnable: '1' }}
                                        value={one(data.stock_location_id)}
                                        onChange={(v) =>
                                            setData(
                                                'stock_location_id',
                                                first(v),
                                            )
                                        }
                                    />
                                    <InputError
                                        message={err.stock_location_id}
                                    />
                                </div>
                            </>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="gr-received">Waktu terima</Label>
                            <Input
                                id="gr-received"
                                type="datetime-local"
                                value={data.received_at}
                                onChange={(e) =>
                                    setData('received_at', e.target.value)
                                }
                            />
                            <InputError message={err.received_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="gr-sj">
                                Nomor surat jalan supplier
                            </Label>
                            <Input
                                id="gr-sj"
                                maxLength={60}
                                autoComplete="off"
                                value={data.supplier_delivery_no}
                                onKeyDown={nextOnEnter}
                                onChange={(e) =>
                                    setData(
                                        'supplier_delivery_no',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={err.supplier_delivery_no} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="gr-plate">
                                Plat kendaraan pengirim (opsional)
                            </Label>
                            <Input
                                id="gr-plate"
                                maxLength={15}
                                autoComplete="off"
                                value={data.vehicle_plate}
                                onKeyDown={nextOnEnter}
                                onChange={(e) =>
                                    setData('vehicle_plate', e.target.value)
                                }
                            />
                            <InputError message={err.vehicle_plate} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="gr-note">Catatan (opsional)</Label>
                            <textarea
                                id="gr-note"
                                className={TEXTAREA}
                                maxLength={2000}
                                value={data.note}
                                onChange={(e) =>
                                    setData('note', e.target.value)
                                }
                            />
                            <InputError message={err.note} />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Barang diterima"
                    description={`Isi jumlah yang diterima baik dan yang ditolak (dengan alasan). Batch & ED wajib untuk produk yang melacaknya. Batas sisa umur bawaan ${minShelfLifeMonths} bulan; toleransi lebih dari PO ${tolerancePct.replace('.', ',')}%. Baris yang izin edarnya tidak cocok otomatis masuk rak karantina.`}
                >
                    <InputError message={err.lines} />
                    {data.lines.length === 0 && (
                        <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                            Semua baris PO sudah diterima.
                        </p>
                    )}
                    <ul className="space-y-3">
                        {data.lines.map((line, index) => {
                            const e = (f: string) => err[`lines.${index}.${f}`];
                            const orderLine = orderLines.find(
                                (o) => o.id === line.purchase_order_line_id,
                            );

                            return (
                                <li
                                    key={`${line.purchase_order_line_id ?? 'x'}-${index}`}
                                    className="space-y-3 rounded-lg border p-3"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        {withPo ? (
                                            <div className="grid min-w-0">
                                                <span className="font-medium break-words">
                                                    {line.title}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Satuan{' '}
                                                    {line.unit.toLowerCase()}
                                                    {line.factor !== '1' &&
                                                        ` (isi ${formatQty(line.factor)} ${line.base_unit.toLowerCase()})`}
                                                    {line.remaining_base !==
                                                        null &&
                                                        ` · sisa PO ${formatQty(line.remaining_base)} ${line.base_unit.toLowerCase()}`}
                                                    {orderLine?.unit_cost &&
                                                        ` · biaya ${formatRupiah(orderLine.unit_cost)} / ${line.base_unit.toLowerCase()}`}
                                                    {line.tracks_serial &&
                                                        ' · nomor seri diisi di halaman penerimaan'}
                                                </span>
                                            </div>
                                        ) : (
                                            <div className="grid flex-1 gap-3 sm:grid-cols-2">
                                                <div className="grid gap-1.5">
                                                    <Label
                                                        htmlFor={`gr-product-${index}`}
                                                    >
                                                        Produk
                                                    </Label>
                                                    <AsyncSelect
                                                        id={`gr-product-${index}`}
                                                        source="products"
                                                        value={one(
                                                            line.product_id,
                                                        )}
                                                        onChange={(v) =>
                                                            setLine(index, {
                                                                product_id:
                                                                    first(v),
                                                                product_unit_id:
                                                                    '',
                                                            })
                                                        }
                                                    />
                                                </div>
                                                <div className="grid gap-1.5">
                                                    <Label
                                                        htmlFor={`gr-unit-${index}`}
                                                    >
                                                        Satuan
                                                    </Label>
                                                    <AsyncSelect
                                                        id={`gr-unit-${index}`}
                                                        source="product_units"
                                                        params={{
                                                            parent: line.product_id,
                                                        }}
                                                        disabled={
                                                            line.product_id ===
                                                            ''
                                                        }
                                                        value={one(
                                                            line.product_unit_id,
                                                        )}
                                                        onChange={(v) =>
                                                            setLine(index, {
                                                                product_unit_id:
                                                                    first(v),
                                                            })
                                                        }
                                                    />
                                                    <InputError
                                                        message={e(
                                                            'product_unit_id',
                                                        )}
                                                    />
                                                </div>
                                            </div>
                                        )}
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            aria-label={`Hapus baris ${index + 1} (barang tidak datang)`}
                                            disabled={data.lines.length === 1}
                                            onClick={() =>
                                                setData(
                                                    'lines',
                                                    data.lines.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 aria-hidden="true" />
                                        </Button>
                                    </div>
                                    <InputError
                                        message={e('purchase_order_line_id')}
                                    />

                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor={`gr-qty-${index}`}>
                                                Diterima baik
                                                {line.unit &&
                                                    ` (${line.unit.toLowerCase()})`}
                                            </Label>
                                            <Input
                                                id={`gr-qty-${index}`}
                                                inputMode="decimal"
                                                autoComplete="off"
                                                className="text-right tabular-nums"
                                                value={line.qty_received}
                                                onKeyDown={nextOnEnter}
                                                onChange={(ev) =>
                                                    setLine(index, {
                                                        qty_received:
                                                            ev.target.value,
                                                    })
                                                }
                                            />
                                            <InputError
                                                message={e('qty_received')}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`gr-reject-${index}`}
                                            >
                                                Ditolak (opsional)
                                            </Label>
                                            <Input
                                                id={`gr-reject-${index}`}
                                                inputMode="decimal"
                                                autoComplete="off"
                                                className="text-right tabular-nums"
                                                placeholder="0"
                                                value={line.qty_rejected}
                                                onKeyDown={nextOnEnter}
                                                onChange={(ev) =>
                                                    setLine(index, {
                                                        qty_rejected:
                                                            ev.target.value,
                                                    })
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5 lg:col-span-2">
                                            <Label
                                                htmlFor={`gr-reason-${index}`}
                                            >
                                                Alasan ditolak
                                            </Label>
                                            <NativeSelect
                                                id={`gr-reason-${index}`}
                                                value={line.reject_reason_id}
                                                disabled={
                                                    line.qty_rejected === '' ||
                                                    line.qty_rejected === '0'
                                                }
                                                onChange={(ev) =>
                                                    setLine(index, {
                                                        reject_reason_id:
                                                            ev.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">
                                                    Pilih alasan
                                                </option>
                                                {rejectReasons.map((r) => (
                                                    <option
                                                        key={r.value}
                                                        value={r.value}
                                                    >
                                                        {r.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError
                                                message={e('reject_reason_id')}
                                            />
                                        </div>
                                        {line.tracks_batch && (
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`gr-batch-${index}`}
                                                >
                                                    Nomor batch
                                                </Label>
                                                <Input
                                                    id={`gr-batch-${index}`}
                                                    autoComplete="off"
                                                    maxLength={60}
                                                    className="font-mono"
                                                    placeholder="Pindai / ketik"
                                                    value={line.batch_no}
                                                    onKeyDown={nextOnEnter}
                                                    onChange={(ev) =>
                                                        setLine(index, {
                                                            batch_no:
                                                                ev.target.value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={e('batch_no')}
                                                />
                                            </div>
                                        )}
                                        {line.tracks_expiry && (
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`gr-ed-${index}`}
                                                >
                                                    Kedaluwarsa (ED)
                                                </Label>
                                                <Input
                                                    id={`gr-ed-${index}`}
                                                    type="date"
                                                    value={line.expiry_date}
                                                    onChange={(ev) =>
                                                        setLine(index, {
                                                            expiry_date:
                                                                ev.target.value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={e('expiry_date')}
                                                />
                                            </div>
                                        )}
                                        {line.requires_registration && (
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`gr-reg-${index}`}
                                                >
                                                    No. izin di kemasan
                                                </Label>
                                                <Input
                                                    id={`gr-reg-${index}`}
                                                    autoComplete="off"
                                                    maxLength={60}
                                                    className="font-mono"
                                                    placeholder="Pindai / ketik"
                                                    value={
                                                        line.registration_no_seen
                                                    }
                                                    onKeyDown={nextOnEnter}
                                                    onChange={(ev) =>
                                                        setLine(index, {
                                                            registration_no_seen:
                                                                ev.target.value,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={e(
                                                        'registration_no_seen',
                                                    )}
                                                />
                                            </div>
                                        )}
                                        <div className="grid gap-1.5">
                                            <Label htmlFor={`gr-bin-${index}`}>
                                                Rak tujuan
                                            </Label>
                                            <NativeSelect
                                                id={`gr-bin-${index}`}
                                                value={line.stock_bin_id}
                                                onChange={(ev) =>
                                                    setLine(index, {
                                                        stock_bin_id:
                                                            ev.target.value,
                                                    })
                                                }
                                            >
                                                <option value="">
                                                    Tanpa rak
                                                </option>
                                                {bins.map((b) => (
                                                    <option
                                                        key={b.value}
                                                        value={b.value}
                                                    >
                                                        {b.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <InputError
                                                message={e('stock_bin_id')}
                                            />
                                        </div>
                                        {!withPo && (
                                            <div className="grid gap-1.5">
                                                <Label
                                                    htmlFor={`gr-cost-${index}`}
                                                >
                                                    Biaya per satuan dasar
                                                </Label>
                                                <MoneyInput
                                                    id={`gr-cost-${index}`}
                                                    className="w-full"
                                                    value={line.unit_cost}
                                                    invalid={!!e('unit_cost')}
                                                    onChange={(v) =>
                                                        setLine(index, {
                                                            unit_cost: v,
                                                        })
                                                    }
                                                />
                                                <InputError
                                                    message={e('unit_cost')}
                                                />
                                            </div>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    <div className="mt-4 flex flex-wrap items-end gap-2">
                        {withPo && missingPoLines.length > 0 && (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="gr-add-line">
                                        Tambah baris PO
                                    </Label>
                                    <NativeSelect
                                        id="gr-add-line"
                                        value={addLineId}
                                        onChange={(ev) =>
                                            setAddLineId(ev.target.value)
                                        }
                                    >
                                        <option value="">Pilih baris</option>
                                        {missingPoLines.map((o) => (
                                            <option key={o.id} value={o.id}>
                                                {o.sku} · {o.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={addLineId === ''}
                                    onClick={() => {
                                        const picked = orderLines.find(
                                            (o) => String(o.id) === addLineId,
                                        );
                                        if (picked) {
                                            setData('lines', [
                                                ...data.lines,
                                                fromOrderLine(picked),
                                            ]);
                                        }
                                        setAddLineId('');
                                    }}
                                >
                                    <Plus aria-hidden="true" />
                                    Tambah
                                </Button>
                            </>
                        )}
                        {!withPo && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={data.lines.length >= 200}
                                onClick={() =>
                                    setData('lines', [
                                        ...data.lines,
                                        { ...BLANK_FREE_LINE },
                                    ])
                                }
                            >
                                <Plus aria-hidden="true" />
                                Tambah Baris
                            </Button>
                        )}
                    </div>
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={back}>Batal</Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={form.processing || data.lines.length === 0}
                    >
                        {form.processing && <Spinner />}
                        Simpan Draf
                    </Button>
                </div>
            </form>
        </>
    );
}

GoodsReceiptForm.layout = {
    breadcrumbs: [{ title: 'Penerimaan Barang', href: '/penerimaan' }],
};
