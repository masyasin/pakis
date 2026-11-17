import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { BatchPicker } from '@/components/pakis/batch-picker';
import type { BatchOption, BatchPick } from '@/components/pakis/batch-picker';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import { formatQty } from '@/lib/qty';
import type { PurchaseReturnDetail } from './show';

type Option = { value: number; label: string };

type PrefillLine = {
    product_id: number;
    product_label: string;
    stock_batch_id: number;
    batch_no: string;
    expiry_date: string | null;
    status: string;
    bin: string | null;
    available_base: string;
    unit: string;
};

type Prefill = {
    goods_receipt: { id: number; number: string };
    supplier: { id: number; name: string };
    stock_location: { id: number; name: string };
    lines: PrefillLine[];
};

type Props = {
    return: PurchaseReturnDetail | null;
    prefill: Prefill | null;
    reasons: Option[];
};

type Line = { product_id: string; batch: BatchPick | null; qty_base: string };

const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';
const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const decimalText = (canonical: string) =>
    canonical.replace(/\.?0+$/, '').replace('.', ',');

function batchOption(b: {
    id: number;
    batch_no: string;
    expiry_date: string | null;
    status: string;
    bin: string | null;
    available_base: string;
    unit: string;
}): BatchOption {
    return {
        value: b.id,
        label: b.batch_no,
        batch_no: b.batch_no,
        expiry_date: b.expiry_date,
        days_to_expiry: null,
        expiry_state: b.status === 'expired' ? 'expired' : 'none',
        available_base: b.available_base,
        unit: b.unit,
        bin: b.bin,
        fefo_rank: 1,
        status: b.status,
    };
}

/**
 * Buat/ubah draf retur ke prinsipal (FR-A26). Batch dipilih lewat `BatchPicker` mode retur (lookup
 * `returnable_batches`: termasuk karantina & kedaluwarsa). Qty dalam satuan dasar; biaya beli batch
 * dan totalnya dihitung server (BR-159). Dari penerimaan (`?gr=`) baris batchnya terisi otomatis.
 */
export default function PurchaseReturnForm({
    return: current,
    prefill,
    reasons,
}: Props) {
    const editing = current !== null;
    useState(() => {
        const supplier = current?.supplier ?? prefill?.supplier;
        const location = current?.stock_location ?? prefill?.stock_location;
        if (supplier) {
            rememberLabels('suppliers', [
                { value: supplier.id, label: supplier.name },
            ]);
        }
        if (location) {
            rememberLabels('stock_locations', [
                { value: location.id, label: location.name },
            ]);
        }
        rememberLabels(
            'products',
            current
                ? current.lines.map((l) => ({
                      value: l.product_id,
                      label: `${l.sku} · ${l.name}`,
                  }))
                : (prefill?.lines ?? []).map((l) => ({
                      value: l.product_id,
                      label: l.product_label,
                  })),
        );

        return true;
    });

    const initialLines = (): Line[] => {
        if (current) {
            return current.lines.map((l) => ({
                product_id: String(l.product_id),
                batch: {
                    batch: batchOption({
                        id: l.stock_batch_id,
                        batch_no: l.batch_no,
                        expiry_date: l.expiry_date,
                        status: l.batch_status,
                        bin: l.bin,
                        available_base: l.available_base,
                        unit: l.base_unit,
                    }),
                    fefoOverride: false,
                },
                qty_base: decimalText(l.qty_base),
            }));
        }
        if (prefill && prefill.lines.length > 0) {
            return prefill.lines.map((l) => ({
                product_id: String(l.product_id),
                batch: {
                    batch: batchOption({ ...l, id: l.stock_batch_id }),
                    fefoOverride: false,
                },
                qty_base: '',
            }));
        }

        return [{ product_id: '', batch: null, qty_base: '' }];
    };

    const form = useForm({
        supplier_id: String(current?.supplier.id ?? prefill?.supplier.id ?? ''),
        stock_location_id: String(
            current?.stock_location.id ?? prefill?.stock_location.id ?? '',
        ),
        reason_id: current ? String(current.reason.id) : '',
        goods_receipt_id:
            current?.goods_receipt?.id ?? prefill?.goods_receipt.id ?? null,
        note: current?.note ?? '',
        lines: initialLines(),
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;
    const receipt = current?.goods_receipt ?? prefill?.goods_receipt ?? null;
    const locationId =
        data.stock_location_id === '' ? null : Number(data.stock_location_id);

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            supplier_id: d.supplier_id === '' ? null : Number(d.supplier_id),
            stock_location_id:
                d.stock_location_id === '' ? null : Number(d.stock_location_id),
            reason_id: d.reason_id === '' ? null : Number(d.reason_id),
            goods_receipt_id: d.goods_receipt_id,
            note: d.note,
            // Biaya tidak dikirim: server memakai biaya beli batch (BR-159).
            lines: d.lines
                .filter((l) => l.batch !== null)
                .map((l) => ({
                    stock_batch_id: l.batch?.batch.value,
                    qty_base: l.qty_base,
                })),
        }));
        if (editing) {
            form.put(`/pembelian/retur/${current.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/pembelian/retur', { preserveScroll: true });
        }
    };

    const title = editing
        ? `Ubah Retur ${current.number}`
        : 'Buat Retur ke Prinsipal';
    const back = editing
        ? `/pembelian/retur/${current.id}`
        : '/pembelian/retur';

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
                    {editing ? 'Kembali ke retur' : 'Daftar retur'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>
                <InputError message={err.form} />

                <SettingsSection
                    title="Kepala retur"
                    description={
                        receipt
                            ? `Dari penerimaan ${receipt.number}. Nomor retur dibuat otomatis (mis. RTB-2611-0001).`
                            : 'Nomor retur dibuat otomatis (mis. RTB-2611-0001). Retur disimpan sebagai draf lalu diajukan untuk persetujuan.'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="rtb-supplier">
                                Supplier / prinsipal
                            </Label>
                            <AsyncSelect
                                id="rtb-supplier"
                                source="suppliers"
                                value={one(data.supplier_id)}
                                disabled={receipt !== null}
                                onChange={(v) =>
                                    setData('supplier_id', first(v))
                                }
                            />
                            <InputError message={err.supplier_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rtb-location">Lokasi stok</Label>
                            <AsyncSelect
                                id="rtb-location"
                                source="stock_locations"
                                params={{ binnable: '1' }}
                                value={one(data.stock_location_id)}
                                disabled={receipt !== null}
                                onChange={(v) => {
                                    setData((d) => ({
                                        ...d,
                                        stock_location_id: first(v),
                                        lines: d.lines.map((l) => ({
                                            ...l,
                                            batch: null,
                                        })),
                                    }));
                                }}
                            />
                            <InputError message={err.stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rtb-reason">Alasan retur</Label>
                            <NativeSelect
                                id="rtb-reason"
                                value={data.reason_id}
                                onChange={(e) =>
                                    setData('reason_id', e.target.value)
                                }
                            >
                                <option value="">Pilih alasan</option>
                                {reasons.map((r) => (
                                    <option key={r.value} value={r.value}>
                                        {r.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={err.reason_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="rtb-note">Catatan (opsional)</Label>
                            <textarea
                                id="rtb-note"
                                className={TEXTAREA}
                                maxLength={2000}
                                value={data.note}
                                onChange={(e) =>
                                    setData('note', e.target.value)
                                }
                            />
                            <InputError
                                message={err.note ?? err.goods_receipt_id}
                            />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Barang diretur"
                    description="Pilih batch per produk; batch karantina dan kedaluwarsa ikut ditawarkan. Jumlah dalam satuan dasar dan tidak boleh melebihi yang tersedia."
                >
                    <InputError message={err.lines} />
                    <ul className="space-y-3">
                        {data.lines.map((line, index) => (
                            <li
                                key={index}
                                className="grid gap-3 rounded-lg border p-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_9rem_auto] lg:items-start"
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`rtb-product-${index}`}>
                                        Produk
                                    </Label>
                                    <AsyncSelect
                                        id={`rtb-product-${index}`}
                                        source="products"
                                        value={one(line.product_id)}
                                        onChange={(v) =>
                                            setLine(index, {
                                                product_id: first(v),
                                                batch: null,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`rtb-batch-${index}`}>
                                        Batch
                                    </Label>
                                    <BatchPicker
                                        id={`rtb-batch-${index}`}
                                        source="returnable_batches"
                                        productId={
                                            line.product_id === ''
                                                ? null
                                                : Number(line.product_id)
                                        }
                                        stockLocationId={locationId}
                                        value={line.batch}
                                        onChange={(pick) =>
                                            setLine(index, { batch: pick })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[`lines.${index}.stock_batch_id`]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`rtb-qty-${index}`}>
                                        Qty
                                        {line.batch &&
                                            ` (${line.batch.batch.unit.toLowerCase()})`}
                                    </Label>
                                    <Input
                                        id={`rtb-qty-${index}`}
                                        inputMode="decimal"
                                        autoComplete="off"
                                        className="text-right tabular-nums"
                                        value={line.qty_base}
                                        placeholder={
                                            line.batch
                                                ? `maks ${formatQty(line.batch.batch.available_base)}`
                                                : ''
                                        }
                                        onChange={(e) =>
                                            setLine(index, {
                                                qty_base: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={err[`lines.${index}.qty_base`]}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="justify-self-end lg:mt-6"
                                    aria-label={`Hapus baris ${index + 1}`}
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
                            </li>
                        ))}
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        className="mt-4"
                        disabled={data.lines.length >= 200}
                        onClick={() =>
                            setData('lines', [
                                ...data.lines,
                                { product_id: '', batch: null, qty_base: '' },
                            ])
                        }
                    >
                        <Plus aria-hidden="true" />
                        Tambah Baris
                    </Button>
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={back}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan Draf
                    </Button>
                </div>
            </form>
        </>
    );
}

PurchaseReturnForm.layout = {
    breadcrumbs: [{ title: 'Retur ke Prinsipal', href: '/pembelian/retur' }],
};
