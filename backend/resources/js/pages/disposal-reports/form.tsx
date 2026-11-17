import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Option = { value: string | number; label: string };

type Props = { methods: Option[]; reasons: Option[] };

type Line = { product_id: string; stock_batch_id: string; qty_base: string };

const EMPTY: Line = { product_id: '', stock_batch_id: '', qty_base: '' };
const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/**
 * Buat berita acara draf (BR-163). Batch dipilih per produk dari lokasi; hanya batch karantina atau
 * kedaluwarsa yang diterima server. Nilai dihitung server dari HPP saat disimpan.
 */
export default function DisposalReportForm({ methods, reasons }: Props) {
    const form = useForm({
        stock_location_id: '',
        reason_id: '',
        method: String(methods[0]?.value ?? 'destroy'),
        planned_date: '',
        note: '',
        lines: [{ ...EMPTY }] as Line[],
    });
    const { data, setData } = form;
    const err = form.errors as Record<string, string | undefined>;

    const setLine = (i: number, patch: Partial<Line>) =>
        setData(
            'lines',
            data.lines.map((l, k) => (k === i ? { ...l, ...patch } : l)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            stock_location_id:
                d.stock_location_id === '' ? null : Number(d.stock_location_id),
            reason_id: d.reason_id === '' ? null : Number(d.reason_id),
            method: d.method,
            planned_date: d.planned_date === '' ? null : d.planned_date,
            note: d.note,
            lines: d.lines
                .filter((l) => l.stock_batch_id !== '')
                .map((l) => ({
                    stock_batch_id: Number(l.stock_batch_id),
                    qty_base: l.qty_base,
                })),
        }));
        form.post('/pemusnahan', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Buat Berita Acara" />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1100px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href="/pemusnahan"
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar berita acara
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    Buat Berita Acara
                </h1>

                <SettingsSection
                    title="Kepala berita acara"
                    description="Nomor dibuat otomatis (mis. BAP-2026-0001). Draf diajukan untuk persetujuan, lalu dicetak dan dilaksanakan dengan saksi."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="bap-location">Lokasi</Label>
                            <AsyncSelect
                                id="bap-location"
                                source="stock_locations"
                                params={{ binnable: '1' }}
                                value={
                                    data.stock_location_id === ''
                                        ? []
                                        : [data.stock_location_id]
                                }
                                onChange={(v) =>
                                    setData((d) => ({
                                        ...d,
                                        stock_location_id: String(v[0] ?? ''),
                                        lines: d.lines.map((l) => ({
                                            ...l,
                                            stock_batch_id: '',
                                        })),
                                    }))
                                }
                            />
                            <InputError message={err.stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="bap-method">Metode</Label>
                            <NativeSelect
                                id="bap-method"
                                value={data.method}
                                onChange={(e) =>
                                    setData('method', e.target.value)
                                }
                            >
                                {methods.map((m) => (
                                    <option key={m.value} value={m.value}>
                                        {m.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={err.method} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="bap-reason">Alasan</Label>
                            <NativeSelect
                                id="bap-reason"
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
                            <Label htmlFor="bap-date">
                                Rencana pelaksanaan (opsional)
                            </Label>
                            <Input
                                id="bap-date"
                                type="date"
                                value={data.planned_date}
                                onChange={(e) =>
                                    setData('planned_date', e.target.value)
                                }
                            />
                            <InputError message={err.planned_date} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="bap-note">Catatan (opsional)</Label>
                            <textarea
                                id="bap-note"
                                className={TEXTAREA}
                                maxLength={2000}
                                value={data.note}
                                onChange={(e) =>
                                    setData('note', e.target.value)
                                }
                            />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Batch"
                    description="Hanya batch karantina atau kedaluwarsa. Qty dalam satuan dasar."
                >
                    <InputError message={err.lines} />
                    <ul className="space-y-3">
                        {data.lines.map((line, i) => (
                            <li
                                key={i}
                                className="grid gap-3 rounded-lg border p-3 lg:grid-cols-[minmax(0,2fr)_minmax(0,2fr)_8rem_auto] lg:items-start"
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`bap-product-${i}`}>
                                        Produk
                                    </Label>
                                    <AsyncSelect
                                        id={`bap-product-${i}`}
                                        source="products"
                                        value={
                                            line.product_id === ''
                                                ? []
                                                : [line.product_id]
                                        }
                                        onChange={(v) =>
                                            setLine(i, {
                                                product_id: String(v[0] ?? ''),
                                                stock_batch_id: '',
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`bap-batch-${i}`}>
                                        Batch
                                    </Label>
                                    <AsyncSelect
                                        id={`bap-batch-${i}`}
                                        source="returnable_batches"
                                        params={{
                                            product_id:
                                                line.product_id || undefined,
                                            stock_location_id:
                                                data.stock_location_id ||
                                                undefined,
                                        }}
                                        disabled={
                                            line.product_id === '' ||
                                            data.stock_location_id === ''
                                        }
                                        value={
                                            line.stock_batch_id === ''
                                                ? []
                                                : [line.stock_batch_id]
                                        }
                                        onChange={(v) =>
                                            setLine(i, {
                                                stock_batch_id: String(
                                                    v[0] ?? '',
                                                ),
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[`lines.${i}.stock_batch_id`]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`bap-qty-${i}`}>Qty</Label>
                                    <Input
                                        id={`bap-qty-${i}`}
                                        inputMode="decimal"
                                        autoComplete="off"
                                        className="text-right tabular-nums"
                                        value={line.qty_base}
                                        onChange={(e) =>
                                            setLine(i, {
                                                qty_base: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={err[`lines.${i}.qty_base`]}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="justify-self-end lg:mt-6"
                                    aria-label={`Hapus baris ${i + 1}`}
                                    disabled={data.lines.length === 1}
                                    onClick={() =>
                                        setData(
                                            'lines',
                                            data.lines.filter(
                                                (_, k) => k !== i,
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
                            setData('lines', [...data.lines, { ...EMPTY }])
                        }
                    >
                        <Plus aria-hidden="true" />
                        Tambah Batch
                    </Button>
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href="/pemusnahan">Batal</Link>
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

DisposalReportForm.layout = {
    breadcrumbs: [{ title: 'Pemusnahan', href: '/pemusnahan' }],
};
