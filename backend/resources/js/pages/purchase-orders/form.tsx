import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { MoneyInput, trimZeroFraction } from '@/components/pakis/money-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import { formatRupiah } from '@/lib/money';
import { poLineTotal, poTotals } from '@/lib/po-totals';
import { parseQtyInput } from '@/lib/qty';
import type { PurchaseOrderDetail } from './show';

type LastPrice = {
    last_price: string | null;
    last_price_unit_id: number | null;
    last_price_unit: string | null;
    lead_time_days: number | null;
};

type Props = {
    order: PurchaseOrderDetail | null;
    today: string;
    defaultTermDays: number;
    /** Harga beli terakhir per produk dari supplier terpilih (dimuat ulang saat supplier berubah). */
    lastPrices?: Record<string, LastPrice>;
};

type Line = {
    product_id: string;
    product_unit_id: string;
    qty: string;
    unit_price: string;
    discount_pct: string;
    note: string;
};

const EMPTY_LINE: Line = {
    product_id: '',
    product_unit_id: '',
    qty: '',
    unit_price: '',
    discount_pct: '',
    note: '',
};
const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** "2,5" → "2.5" untuk pratinjau; teks belum sah dibiarkan (pratinjau baris jadi "–"). */
const canonicalPct = (text: string) => text.trim().replace(',', '.');

/**
 * Buat/ubah PO draf (FR-A24, BR-160). Total di layar hanya pratinjau dengan rumus yang sama dengan
 * server; angka resmi dihitung ulang saat disimpan. Harga beli terakhir dari supplier terpilih
 * ditawarkan sebagai saran, tidak pernah mengunci harga.
 */
export default function PurchaseOrderForm({
    order,
    today,
    defaultTermDays,
    lastPrices = {},
}: Props) {
    const editing = order !== null;
    useState(() => {
        if (order) {
            rememberLabels('suppliers', [
                { value: order.supplier.id, label: order.supplier.name },
            ]);
            rememberLabels('stock_locations', [
                {
                    value: order.stock_location.id,
                    label: order.stock_location.name,
                },
            ]);
            rememberLabels(
                'products',
                order.lines.map((l) => ({
                    value: l.product_id,
                    label: `${l.sku} · ${l.name}`,
                })),
            );
            rememberLabels(
                'product_units',
                order.lines.map((l) => ({
                    value: l.product_unit_id,
                    label: l.unit,
                })),
            );
        }

        return true;
    });

    const form = useForm({
        supplier_id: order ? String(order.supplier.id) : '',
        stock_location_id: order ? String(order.stock_location.id) : '',
        order_date: order?.order_date ?? today,
        expected_date: order?.expected_date ?? '',
        payment_term_days: order ? String(order.payment_term_days) : '',
        note: order?.note ?? '',
        lines: order
            ? order.lines.map<Line>((l) => ({
                  product_id: String(l.product_id),
                  product_unit_id: String(l.product_unit_id),
                  qty: trimZeroFraction(l.qty).replace('.', ','),
                  unit_price: l.unit_price,
                  discount_pct:
                      trimZeroFraction(l.discount_pct) === '0'
                          ? ''
                          : trimZeroFraction(l.discount_pct).replace('.', ','),
                  note: l.note ?? '',
              }))
            : [{ ...EMPTY_LINE }],
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;

    const setLine = (index: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const chooseSupplier = (id: string) => {
        setData('supplier_id', id);
        if (id !== '') {
            router.reload({ only: ['lastPrices'], data: { supplier_id: id } });
        }
    };

    // Saran harga: isi harga hanya bila masih kosong dan satuannya sama dengan harga terakhir.
    const suggestPrice = (line: Line, unitId: string): string => {
        const last = lastPrices[line.product_id];

        return line.unit_price === '' &&
            last?.last_price &&
            String(last.last_price_unit_id) === unitId
            ? last.last_price
            : line.unit_price;
    };

    const preview = data.lines.map((l) => ({
        qty: parseQtyInput(l.qty) ?? '',
        unitPrice: l.unit_price,
        discountPct: canonicalPct(l.discount_pct),
    }));
    const totals = poTotals(preview);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            supplier_id: d.supplier_id === '' ? null : Number(d.supplier_id),
            stock_location_id:
                d.stock_location_id === '' ? null : Number(d.stock_location_id),
            order_date: d.order_date,
            expected_date: d.expected_date === '' ? null : d.expected_date,
            payment_term_days:
                d.payment_term_days === '' ? null : d.payment_term_days,
            note: d.note,
            // Total TIDAK dikirim: server menghitung sendiri (BR-160).
            lines: d.lines
                .filter((l) => l.product_unit_id !== '')
                .map((l) => ({
                    product_unit_id: Number(l.product_unit_id),
                    qty: l.qty,
                    unit_price: l.unit_price,
                    discount_pct: l.discount_pct,
                    note: l.note,
                })),
        }));
        if (editing) {
            form.put(`/pembelian/po/${order.id}`, { preserveScroll: true });
        } else {
            form.post('/pembelian/po', { preserveScroll: true });
        }
    };

    const title = editing ? `Ubah PO ${order.number}` : 'Buat Purchase Order';
    const back = editing ? `/pembelian/po/${order.id}` : '/pembelian/po';

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
                    {editing ? 'Kembali ke PO' : 'Daftar purchase order'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>

                <SettingsSection
                    title="Kepala PO"
                    description={
                        editing
                            ? 'Hanya PO draf yang bisa diubah. Nomor PO tetap.'
                            : 'Nomor PO dibuat otomatis saat disimpan (mis. PO-2611-0001). PO disimpan sebagai draf lalu diajukan untuk persetujuan.'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="po-supplier">Supplier</Label>
                            <AsyncSelect
                                id="po-supplier"
                                source="suppliers"
                                value={one(data.supplier_id)}
                                onChange={(v) => chooseSupplier(first(v))}
                                placeholder="Cari kode atau nama supplier"
                            />
                            <InputError message={err.supplier_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="po-location">Gudang tujuan</Label>
                            <AsyncSelect
                                id="po-location"
                                source="stock_locations"
                                params={{ binnable: '1' }}
                                value={one(data.stock_location_id)}
                                onChange={(v) =>
                                    setData('stock_location_id', first(v))
                                }
                                placeholder="Gudang atau toko penerima barang"
                            />
                            <InputError message={err.stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="po-date">Tanggal PO</Label>
                            <Input
                                id="po-date"
                                type="date"
                                value={data.order_date}
                                onChange={(e) =>
                                    setData('order_date', e.target.value)
                                }
                            />
                            <InputError message={err.order_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="po-expected">
                                Perkiraan datang (opsional)
                            </Label>
                            <Input
                                id="po-expected"
                                type="date"
                                min={data.order_date}
                                value={data.expected_date}
                                onChange={(e) =>
                                    setData('expected_date', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Lewat tanggal ini, PO tampil sebagai terlambat
                                di dasbor.
                            </p>
                            <InputError message={err.expected_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="po-term">Tempo bayar (hari)</Label>
                            <Input
                                id="po-term"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={365}
                                value={data.payment_term_days}
                                placeholder={`Ikuti supplier (bawaan ${defaultTermDays} hari)`}
                                onChange={(e) =>
                                    setData('payment_term_days', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Kosongkan untuk memakai tempo supplier. 0 =
                                tunai.
                            </p>
                            <InputError message={err.payment_term_days} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="po-note">Catatan (opsional)</Label>
                            <textarea
                                id="po-note"
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
                    title="Barang dipesan"
                    description="Qty dalam satuan yang dipilih; diskon dalam persen per baris. Total dihitung ulang sistem saat disimpan."
                >
                    <InputError message={err.lines} />
                    <ul className="space-y-3">
                        {data.lines.map((line, index) => {
                            const last = lastPrices[line.product_id];
                            const total = poLineTotal(preview[index]);

                            return (
                                <li
                                    key={index}
                                    className="grid gap-3 rounded-lg border p-3 lg:grid-cols-[minmax(0,2.2fr)_minmax(0,1.2fr)_7rem_minmax(0,1.4fr)_6rem_8rem_auto] lg:items-start"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`po-product-${index}`}>
                                            Produk
                                        </Label>
                                        <AsyncSelect
                                            id={`po-product-${index}`}
                                            source="products"
                                            value={one(line.product_id)}
                                            onChange={(v) =>
                                                setLine(index, {
                                                    product_id: first(v),
                                                    product_unit_id: '',
                                                })
                                            }
                                        />
                                        {last?.last_price && (
                                            <p className="text-xs text-muted-foreground">
                                                Harga terakhir{' '}
                                                {formatRupiah(last.last_price)}
                                                {last.last_price_unit &&
                                                    ` / ${last.last_price_unit.toLowerCase()}`}
                                                {last.lead_time_days !== null &&
                                                    ` · kirim ±${last.lead_time_days} hari`}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`po-unit-${index}`}>
                                            Satuan
                                        </Label>
                                        <AsyncSelect
                                            id={`po-unit-${index}`}
                                            source="product_units"
                                            params={{
                                                parent: line.product_id,
                                            }}
                                            value={one(line.product_unit_id)}
                                            disabled={line.product_id === ''}
                                            onChange={(v) =>
                                                setLine(index, {
                                                    product_unit_id: first(v),
                                                    unit_price: suggestPrice(
                                                        line,
                                                        first(v),
                                                    ),
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                err[
                                                    `lines.${index}.product_unit_id`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`po-qty-${index}`}>
                                            Qty
                                        </Label>
                                        <Input
                                            id={`po-qty-${index}`}
                                            inputMode="decimal"
                                            autoComplete="off"
                                            className="text-right tabular-nums"
                                            value={line.qty}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    qty: e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={err[`lines.${index}.qty`]}
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`po-price-${index}`}>
                                            Harga satuan
                                        </Label>
                                        <MoneyInput
                                            id={`po-price-${index}`}
                                            className="w-full"
                                            value={line.unit_price}
                                            invalid={
                                                !!err[
                                                    `lines.${index}.unit_price`
                                                ]
                                            }
                                            onChange={(v) =>
                                                setLine(index, {
                                                    unit_price: v,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                err[`lines.${index}.unit_price`]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`po-disc-${index}`}>
                                            Diskon %
                                        </Label>
                                        <Input
                                            id={`po-disc-${index}`}
                                            inputMode="decimal"
                                            autoComplete="off"
                                            className="text-right tabular-nums"
                                            placeholder="0"
                                            value={line.discount_pct}
                                            onChange={(e) =>
                                                setLine(index, {
                                                    discount_pct:
                                                        e.target.value,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={
                                                err[
                                                    `lines.${index}.discount_pct`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <span className="text-sm font-medium">
                                            Jumlah
                                        </span>
                                        <span className="flex h-9 items-center justify-end text-sm font-semibold tabular-nums">
                                            {total === null
                                                ? '–'
                                                : formatRupiah(total)}
                                        </span>
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
                            );
                        })}
                    </ul>
                    <div className="mt-4 flex flex-wrap items-start justify-between gap-4">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={data.lines.length >= 200}
                            onClick={() =>
                                setData('lines', [
                                    ...data.lines,
                                    { ...EMPTY_LINE },
                                ])
                            }
                        >
                            <Plus aria-hidden="true" />
                            Tambah Baris
                        </Button>
                        <dl className="grid min-w-64 grid-cols-[1fr_auto] gap-x-6 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">Subtotal</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(totals.subtotal)}
                            </dd>
                            <dt className="text-muted-foreground">Diskon</dt>
                            <dd className="text-right tabular-nums">
                                {formatRupiah(totals.discountTotal)}
                            </dd>
                            <dt className="border-t pt-1.5 font-semibold">
                                Perkiraan total
                            </dt>
                            <dd className="border-t pt-1.5 text-right text-base font-bold tabular-nums">
                                {formatRupiah(totals.grandTotal)}
                            </dd>
                        </dl>
                    </div>
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

PurchaseOrderForm.layout = {
    breadcrumbs: [{ title: 'Purchase Order', href: '/pembelian/po' }],
};
