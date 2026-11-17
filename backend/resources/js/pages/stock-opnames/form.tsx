import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Option = { value: string; label: string };

type Props = {
    scopes: Option[];
    blindDefault: boolean;
};

const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** Sumber lookup pilihan cakupan per jenis. */
const SCOPE_SOURCE: Record<string, string> = {
    bin: 'stock_bins',
    category: 'product_categories',
    product: 'products',
};

/**
 * Buat opname draf (FR-A28, BR-162). Stok baru "dibekukan" saat opname dimulai dari halaman
 * detail, bukan saat disimpan. Hitung buta menyembunyikan jumlah sistem dari penghitung sampai
 * lembar hitung ditutup (SEC-103).
 */
export default function StockOpnameForm({ scopes, blindDefault }: Props) {
    const form = useForm({
        stock_location_id: '',
        scope: 'full',
        scope_ids: [] as string[],
        blind_count: blindDefault,
        note: '',
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;
    const source = SCOPE_SOURCE[data.scope];

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            stock_location_id:
                d.stock_location_id === '' ? null : Number(d.stock_location_id),
            scope: d.scope,
            scope_ids: d.scope === 'full' ? null : d.scope_ids.map(Number),
            blind_count: d.blind_count,
            note: d.note,
        }));
        form.post('/opname', { preserveScroll: true });
    };

    return (
        <>
            <Head title="Buat Stock Opname" />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[960px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href="/opname"
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Daftar stock opname
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    Buat Stock Opname
                </h1>

                <SettingsSection
                    title="Cakupan hitung"
                    description="Nomor dibuat otomatis (mis. OPN-2611-0001). Opname disimpan sebagai draf; stok dibekukan saat Anda menekan Mulai Hitung."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="opn-location">Lokasi</Label>
                            <AsyncSelect
                                id="opn-location"
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
                                        // Rak milik lokasi lama tidak berlaku lagi.
                                        scope_ids:
                                            d.scope === 'bin'
                                                ? []
                                                : d.scope_ids,
                                    }))
                                }
                                placeholder="Gudang atau toko"
                            />
                            <InputError message={err.stock_location_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="opn-scope">Cakupan</Label>
                            <NativeSelect
                                id="opn-scope"
                                value={data.scope}
                                onChange={(e) =>
                                    setData((d) => ({
                                        ...d,
                                        scope: e.target.value,
                                        scope_ids: [],
                                    }))
                                }
                            >
                                {scopes.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={err.scope} />
                        </div>
                        {source && (
                            <div className="grid gap-2 sm:col-span-2">
                                <Label htmlFor="opn-scope-ids">
                                    Pilih{' '}
                                    {data.scope === 'bin'
                                        ? 'rak'
                                        : data.scope === 'category'
                                          ? 'kategori'
                                          : 'produk'}
                                </Label>
                                <AsyncSelect
                                    id="opn-scope-ids"
                                    source={source}
                                    multiple
                                    params={
                                        data.scope === 'bin'
                                            ? {
                                                  parent:
                                                      data.stock_location_id ||
                                                      undefined,
                                              }
                                            : undefined
                                    }
                                    disabled={
                                        data.scope === 'bin' &&
                                        data.stock_location_id === ''
                                    }
                                    value={data.scope_ids}
                                    onChange={(v) =>
                                        setData('scope_ids', v.map(String))
                                    }
                                />
                                <InputError
                                    message={
                                        err.scope_ids ?? err['scope_ids.0']
                                    }
                                />
                            </div>
                        )}
                        <div className="flex items-start gap-3 sm:col-span-2">
                            <Checkbox
                                id="opn-blind"
                                checked={data.blind_count}
                                onCheckedChange={(c) =>
                                    setData('blind_count', c === true)
                                }
                            />
                            <div className="grid gap-1">
                                <Label htmlFor="opn-blind">Hitung buta</Label>
                                <p className="text-xs text-muted-foreground">
                                    Penghitung tidak melihat jumlah di sistem
                                    sampai semua baris selesai dihitung.
                                </p>
                            </div>
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="opn-note">Catatan (opsional)</Label>
                            <textarea
                                id="opn-note"
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

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href="/opname">Batal</Link>
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

StockOpnameForm.layout = {
    breadcrumbs: [{ title: 'Stock Opname', href: '/opname' }],
};
