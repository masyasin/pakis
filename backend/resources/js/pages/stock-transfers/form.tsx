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
import type { StockTransferDetail } from './show';

type Option = { value: number; label: string };

type Props = {
    transfer: StockTransferDetail | null;
    /** Gudang/toko aktif (tujuan boleh di luar scope pengirim). */
    destinations: Array<Option & { type: string }>;
    /** Kendaraan pengangkut (informasi saja, tidak mengubah stok kendaraan). */
    vehicles: Option[];
};

type Line = {
    product_id: string;
    qty_base: string;
    batch: BatchPick | null;
};

const EMPTY_LINE: Line = { product_id: '', qty_base: '', batch: null };
const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** "48.000" → "48", "2.500" → "2,5" untuk isian awal saat mengubah draf. */
const toInput = (q: string) =>
    q
        .replace(/\.0+$/, '')
        .replace(/(\.\d*?)0+$/, '$1')
        .replace('.', ',');

/**
 * Buat/ubah mutasi draf (FR-A27, BR-155/156/161). Asal = gudang/toko dalam scope pengirim; tujuan =
 * gudang/toko lain. Qty dalam satuan dasar produk. Batch boleh dipilih (langsung ditahan untuk
 * mutasi ini) atau dikosongkan agar sistem memilih ED terdekat saat kirim.
 */
export default function StockTransferForm({
    transfer,
    destinations,
    vehicles,
}: Props) {
    const editing = transfer !== null;
    useState(() => {
        if (transfer) {
            rememberLabels('stock_locations', [
                {
                    value: transfer.from_location.id,
                    label: transfer.from_location.name,
                },
            ]);
            rememberLabels(
                'products',
                transfer.lines.map((l) => ({
                    value: l.product_id,
                    label: `${l.sku} · ${l.name}`,
                })),
            );
        }

        return true;
    });

    const form = useForm({
        from_stock_location_id: transfer
            ? String(transfer.from_location.id)
            : '',
        to_stock_location_id: transfer ? String(transfer.to_location.id) : '',
        vehicle_id: transfer?.vehicle ? String(transfer.vehicle.id) : '',
        note: transfer?.note ?? '',
        lines: transfer
            ? transfer.lines.map<Line>((l) => ({
                  product_id: String(l.product_id),
                  qty_base: toInput(l.qty_requested_base),
                  // Batch terpilih ditampilkan ulang sebagai opsi minimal; server memeriksa ulang.
                  batch: l.source_batch
                      ? {
                            batch: {
                                value: l.source_batch.id,
                                label: l.source_batch.batch_no,
                                batch_no: l.source_batch.batch_no,
                                expiry_date: l.source_batch.expiry_date,
                            } as BatchOption,
                            fefoOverride: l.fefo_override,
                        }
                      : null,
              }))
            : [{ ...EMPTY_LINE }],
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;
    const fromId =
        data.from_stock_location_id === ''
            ? null
            : Number(data.from_stock_location_id);

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const chooseSource = (id: string) => {
        setData((d) => ({
            ...d,
            from_stock_location_id: id,
            // Batch milik lokasi asal lama tidak berlaku lagi.
            lines: d.lines.map((l) => ({ ...l, batch: null })),
        }));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            from_stock_location_id:
                d.from_stock_location_id === ''
                    ? null
                    : Number(d.from_stock_location_id),
            to_stock_location_id:
                d.to_stock_location_id === ''
                    ? null
                    : Number(d.to_stock_location_id),
            vehicle_id: d.vehicle_id === '' ? null : Number(d.vehicle_id),
            note: d.note,
            // fefo_override TIDAK dikirim: dihitung server dari urutan FEFO (BR-156).
            lines: d.lines
                .filter((l) => l.product_id !== '')
                .map((l) => ({
                    product_id: Number(l.product_id),
                    qty_base: l.qty_base,
                    source_batch_id: l.batch ? l.batch.batch.value : null,
                })),
        }));
        if (editing) {
            form.put(`/mutasi/${transfer.id}`, { preserveScroll: true });
        } else {
            form.post('/mutasi', { preserveScroll: true });
        }
    };

    const title = editing
        ? `Ubah Mutasi ${transfer.number}`
        : 'Buat Mutasi Stok';
    const back = editing ? `/mutasi/${transfer.id}` : '/mutasi';

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
                    {editing ? 'Kembali ke mutasi' : 'Daftar mutasi stok'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>

                <SettingsSection
                    title="Kepala mutasi"
                    description={
                        editing
                            ? 'Hanya mutasi draf yang bisa diubah. Nomor mutasi tetap.'
                            : 'Nomor mutasi dibuat otomatis saat disimpan (mis. MUT-2611-0001). Mutasi disimpan sebagai draf lalu dikirim dari halaman detail.'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="st-from">Dari (lokasi asal)</Label>
                            <AsyncSelect
                                id="st-from"
                                source="stock_locations"
                                params={{ binnable: '1' }}
                                value={one(data.from_stock_location_id)}
                                onChange={(v) => chooseSource(first(v))}
                                placeholder="Gudang atau toko pengirim"
                            />
                            <InputError message={err.from_stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="st-to">Ke (lokasi tujuan)</Label>
                            <NativeSelect
                                id="st-to"
                                value={data.to_stock_location_id}
                                onChange={(e) =>
                                    setData(
                                        'to_stock_location_id',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">Pilih gudang atau toko</option>
                                {destinations
                                    .filter(
                                        (d) =>
                                            String(d.value) !==
                                            data.from_stock_location_id,
                                    )
                                    .map((d) => (
                                        <option key={d.value} value={d.value}>
                                            {d.label}
                                        </option>
                                    ))}
                            </NativeSelect>
                            <InputError message={err.to_stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="st-vehicle">
                                Kendaraan (opsional)
                            </Label>
                            <NativeSelect
                                id="st-vehicle"
                                value={data.vehicle_id}
                                onChange={(e) =>
                                    setData('vehicle_id', e.target.value)
                                }
                            >
                                <option value="">Tanpa kendaraan</option>
                                {vehicles.map((v) => (
                                    <option key={v.value} value={v.value}>
                                        {v.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <p className="text-xs text-muted-foreground">
                                Hanya catatan pengangkut; stok kendaraan tidak
                                berubah.
                            </p>
                            <InputError message={err.vehicle_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="st-note">Catatan (opsional)</Label>
                            <textarea
                                id="st-note"
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
                    title="Barang dimutasi"
                    description="Qty dalam satuan dasar produk. Kosongkan batch agar sistem memilih ED terdekat saat kirim; batch yang dipilih langsung ditahan untuk mutasi ini."
                >
                    <InputError message={err.lines} />
                    <ul className="space-y-3">
                        {data.lines.map((line, index) => (
                            <li
                                key={index}
                                className="grid gap-3 rounded-lg border p-3 lg:grid-cols-[minmax(0,2fr)_8rem_minmax(0,2fr)_auto] lg:items-start"
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`st-product-${index}`}>
                                        Produk
                                    </Label>
                                    <AsyncSelect
                                        id={`st-product-${index}`}
                                        source="products"
                                        value={one(line.product_id)}
                                        onChange={(v) =>
                                            setLine(index, {
                                                product_id: first(v),
                                                batch: null,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[`lines.${index}.product_id`]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`st-qty-${index}`}>
                                        Qty
                                    </Label>
                                    <Input
                                        id={`st-qty-${index}`}
                                        inputMode="decimal"
                                        autoComplete="off"
                                        className="text-right tabular-nums"
                                        value={line.qty_base}
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
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`st-batch-${index}`}>
                                        Batch (opsional)
                                    </Label>
                                    <BatchPicker
                                        id={`st-batch-${index}`}
                                        productId={
                                            line.product_id === ''
                                                ? null
                                                : Number(line.product_id)
                                        }
                                        stockLocationId={fromId}
                                        value={line.batch}
                                        disabled={
                                            line.product_id === '' ||
                                            fromId === null
                                        }
                                        onChange={(batch) =>
                                            setLine(index, { batch })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[
                                                `lines.${index}.source_batch_id`
                                            ]
                                        }
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
                            setData('lines', [...data.lines, { ...EMPTY_LINE }])
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

StockTransferForm.layout = {
    breadcrumbs: [{ title: 'Mutasi Stok', href: '/mutasi' }],
};
