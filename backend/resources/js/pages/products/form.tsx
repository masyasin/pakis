import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Package } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type FlagKey =
    | 'tracks_batch'
    | 'tracks_expiry'
    | 'tracks_serial'
    | 'requires_registration';
type Flags = Record<FlagKey, boolean>;
type Option = { value: string; label: string };
type CategoryOption = {
    value: number;
    label: string;
    kind_label: string;
    flags: Flags;
};
type UnitOption = { value: number; label: string; integer_only: boolean };

type ProductData = Flags & {
    id: number;
    sku: string;
    name: string;
    product_category_id: number;
    brand: string | null;
    principal_id: number | null;
    active_ingredient: string | null;
    formulation_id: number | null;
    package_size: string | null;
    base_unit_id: number;
    description: string | null;
    usage_note: string | null;
    is_restricted: boolean;
    restricted_note: string | null;
    warranty_months: number | null;
    min_shelf_life_months: number | null;
    is_sellable: boolean;
    is_purchasable: boolean;
    status: string;
    photo_url: string | null;
};

type Props = {
    product: ProductData | null;
    categories: CategoryOption[];
    units: UnitOption[];
    formulations: { value: number; label: string }[];
    statuses: Option[];
    skuMode: 'manual' | 'auto';
};

const FLAGS: { key: FlagKey; label: string; hint: string }[] = [
    {
        key: 'tracks_batch',
        label: 'Lacak nomor batch',
        hint: 'Stok dicatat per batch',
    },
    {
        key: 'tracks_expiry',
        label: 'Lacak tanggal kedaluwarsa',
        hint: 'Butuh lacak batch',
    },
    {
        key: 'tracks_serial',
        label: 'Lacak nomor seri',
        hint: 'Per unit, biasanya alat',
    },
    {
        key: 'requires_registration',
        label: 'Wajib izin edar',
        hint: 'Tanpa izin aktif tidak bisa dijual',
    },
];

const numberOrEmpty = (v: number | null) => (v === null ? '' : String(v));

/** Tambah/ubah produk (15 §5, FR-A08, BR-151). */
export default function ProductForm({
    product,
    categories,
    units,
    formulations,
    statuses,
    skuMode,
}: Props) {
    const editing = product !== null;
    const firstCategory = categories[0];
    const form = useForm({
        sku: '',
        name: product?.name ?? '',
        product_category_id: String(
            product?.product_category_id ?? firstCategory?.value ?? '',
        ),
        brand: product?.brand ?? '',
        principal_id: product?.principal_id ? String(product.principal_id) : '',
        active_ingredient: product?.active_ingredient ?? '',
        formulation_id: product?.formulation_id
            ? String(product.formulation_id)
            : '',
        package_size: product?.package_size ?? '',
        base_unit_id: String(product?.base_unit_id ?? units[0]?.value ?? ''),
        description: product?.description ?? '',
        usage_note: product?.usage_note ?? '',
        tracks_batch:
            product?.tracks_batch ?? firstCategory?.flags.tracks_batch ?? true,
        tracks_expiry:
            product?.tracks_expiry ??
            firstCategory?.flags.tracks_expiry ??
            true,
        tracks_serial:
            product?.tracks_serial ??
            firstCategory?.flags.tracks_serial ??
            false,
        requires_registration:
            product?.requires_registration ??
            firstCategory?.flags.requires_registration ??
            true,
        flag_reason: '',
        is_restricted: product?.is_restricted ?? false,
        restricted_note: product?.restricted_note ?? '',
        warranty_months: numberOrEmpty(product?.warranty_months ?? null),
        min_shelf_life_months: numberOrEmpty(
            product?.min_shelf_life_months ?? null,
        ),
        is_sellable: product?.is_sellable ?? true,
        is_purchasable: product?.is_purchasable ?? true,
        status: product?.status ?? 'active',
        photo: null as File | null,
        remove_photo: false,
    });
    const [preview, setPreview] = useState<string | null>(
        product?.photo_url ?? null,
    );
    const category = categories.find(
        (c) => String(c.value) === form.data.product_category_id,
    );

    // BR-151: penanda wajib kategori yang dimatikan → minta alasan (server menegakkannya juga).
    const relaxed = useMemo(
        () =>
            category
                ? FLAGS.filter(
                      (f) => category.flags[f.key] && !form.data[f.key],
                  )
                : [],
        [category, form.data],
    );

    const pickCategory = (value: string) => {
        form.setData('product_category_id', value);
        const picked = categories.find((c) => String(c.value) === value);
        if (picked && !editing) {
            // Produk baru: penanda awal mengikuti kategori.
            form.setData((d) => ({
                ...d,
                product_category_id: value,
                ...picked.flags,
            }));
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => {
            const payload: Record<string, unknown> = { ...d };
            if (editing || skuMode === 'auto') {
                delete payload.sku;
            }
            if (!d.photo) {
                delete payload.photo;
            }

            return editing ? { ...payload, _method: 'put' } : payload;
        });
        form.post(editing ? `/produk/${product.id}` : '/produk', {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const title = editing ? `Ubah · ${product.name}` : 'Tambah Produk';

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href={editing ? `/produk/${product.id}` : '/produk'}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {editing ? 'Kembali ke produk' : 'Daftar produk'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>

                <SettingsSection title="Identitas produk">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {editing ? (
                            <div className="grid gap-2">
                                <Label>SKU</Label>
                                <p className="font-mono text-sm">
                                    {product.sku}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    SKU tidak bisa diubah setelah dibuat.
                                </p>
                            </div>
                        ) : skuMode === 'manual' ? (
                            <div className="grid gap-2">
                                <Label htmlFor="product-sku">SKU</Label>
                                <Input
                                    id="product-sku"
                                    value={form.data.sku}
                                    onChange={(e) =>
                                        form.setData(
                                            'sku',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    className="font-mono"
                                    maxLength={30}
                                    autoComplete="off"
                                    aria-invalid={
                                        !!form.errors.sku || undefined
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    3–30 karakter: huruf besar, angka, strip.
                                </p>
                                <InputError message={form.errors.sku} />
                            </div>
                        ) : (
                            <div className="grid gap-2">
                                <Label>SKU</Label>
                                <p className="text-sm text-muted-foreground">
                                    Dibuat otomatis saat disimpan.
                                </p>
                            </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="product-name">Nama produk</Label>
                            <Input
                                id="product-name"
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                maxLength={150}
                                aria-invalid={!!form.errors.name || undefined}
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-category">Kategori</Label>
                            <NativeSelect
                                id="product-category"
                                value={form.data.product_category_id}
                                onChange={(e) => pickCategory(e.target.value)}
                            >
                                {categories.map((c) => (
                                    <option
                                        key={c.value}
                                        value={String(c.value)}
                                    >
                                        {c.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            {category && (
                                <p className="text-xs text-muted-foreground">
                                    Jenis: {category.kind_label}
                                </p>
                            )}
                            <InputError
                                message={form.errors.product_category_id}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-principal">
                                Prinsipal (opsional)
                            </Label>
                            <AsyncSelect
                                id="product-principal"
                                source="principals"
                                value={
                                    form.data.principal_id === ''
                                        ? []
                                        : [form.data.principal_id]
                                }
                                onChange={(v) =>
                                    form.setData(
                                        'principal_id',
                                        String(v[0] ?? ''),
                                    )
                                }
                                placeholder="Cari produsen / pemilik merek"
                            />
                            <InputError message={form.errors.principal_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-brand">
                                Merek (opsional)
                            </Label>
                            <Input
                                id="product-brand"
                                value={form.data.brand}
                                onChange={(e) =>
                                    form.setData('brand', e.target.value)
                                }
                                maxLength={80}
                            />
                            <InputError message={form.errors.brand} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-ingredient">
                                Bahan aktif (opsional)
                            </Label>
                            <Input
                                id="product-ingredient"
                                value={form.data.active_ingredient}
                                onChange={(e) =>
                                    form.setData(
                                        'active_ingredient',
                                        e.target.value,
                                    )
                                }
                                placeholder="mis. Abamektin 18 g/l"
                                maxLength={255}
                            />
                            <InputError
                                message={form.errors.active_ingredient}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-formulation">
                                Formulasi (opsional)
                            </Label>
                            <NativeSelect
                                id="product-formulation"
                                value={form.data.formulation_id}
                                onChange={(e) =>
                                    form.setData(
                                        'formulation_id',
                                        e.target.value,
                                    )
                                }
                            >
                                <option value="">Tidak ada</option>
                                {formulations.map((f) => (
                                    <option
                                        key={f.value}
                                        value={String(f.value)}
                                    >
                                        {f.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={form.errors.formulation_id} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-package">
                                Ukuran kemasan (opsional)
                            </Label>
                            <Input
                                id="product-package"
                                value={form.data.package_size}
                                onChange={(e) =>
                                    form.setData('package_size', e.target.value)
                                }
                                placeholder="mis. 500 ml"
                                maxLength={40}
                            />
                            <InputError message={form.errors.package_size} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-base-unit">
                                Satuan dasar stok
                            </Label>
                            <NativeSelect
                                id="product-base-unit"
                                value={form.data.base_unit_id}
                                onChange={(e) =>
                                    form.setData('base_unit_id', e.target.value)
                                }
                            >
                                {units.map((u) => (
                                    <option
                                        key={u.value}
                                        value={String(u.value)}
                                    >
                                        {u.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <p className="text-xs text-muted-foreground">
                                Satuan terkecil yang disimpan di stok. Satuan
                                lain (dus, pak) ditambah di halaman produk.
                            </p>
                            <InputError message={form.errors.base_unit_id} />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Penanda perilaku"
                    description="Awalnya mengikuti kategori. Boleh diperketat; melonggarkan butuh alasan yang tercatat."
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        {FLAGS.map((f) => (
                            <div key={f.key} className="flex items-start gap-3">
                                <Checkbox
                                    id={`product-${f.key}`}
                                    checked={form.data[f.key]}
                                    onCheckedChange={(v) =>
                                        form.setData(f.key, v === true)
                                    }
                                />
                                <div className="space-y-0.5">
                                    <Label htmlFor={`product-${f.key}`}>
                                        {f.label}
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        {f.hint}
                                        {category?.flags[f.key] &&
                                            ' · wajib di kategori'}
                                    </p>
                                </div>
                            </div>
                        ))}
                    </div>
                    <InputError message={form.errors.tracks_expiry} />
                    {(relaxed.length > 0 || form.errors.flag_reason) && (
                        <div className="grid gap-2">
                            <Label htmlFor="product-flag-reason">
                                Alasan melonggarkan (
                                {relaxed
                                    .map((f) => f.label.toLowerCase())
                                    .join(', ')}
                                )
                            </Label>
                            <Input
                                id="product-flag-reason"
                                value={form.data.flag_reason}
                                onChange={(e) =>
                                    form.setData('flag_reason', e.target.value)
                                }
                                maxLength={255}
                                aria-invalid={
                                    !!form.errors.flag_reason || undefined
                                }
                            />
                            <InputError message={form.errors.flag_reason} />
                        </div>
                    )}
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="product-restricted"
                            checked={form.data.is_restricted}
                            onCheckedChange={(v) =>
                                form.setData('is_restricted', v === true)
                            }
                        />
                        <div className="space-y-0.5">
                            <Label htmlFor="product-restricted">
                                Pestisida terbatas
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                Hanya boleh dijual ke pembeli yang memenuhi
                                syarat (BR-112).
                            </p>
                        </div>
                    </div>
                    {form.data.is_restricted && (
                        <div className="grid gap-2">
                            <Label htmlFor="product-restricted-note">
                                Catatan pembatasan (opsional)
                            </Label>
                            <Input
                                id="product-restricted-note"
                                value={form.data.restricted_note}
                                onChange={(e) =>
                                    form.setData(
                                        'restricted_note',
                                        e.target.value,
                                    )
                                }
                                maxLength={255}
                            />
                        </div>
                    )}
                </SettingsSection>

                <SettingsSection title="Penjualan & penyimpanan">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="product-warranty">
                                Garansi (bulan, opsional)
                            </Label>
                            <Input
                                id="product-warranty"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={120}
                                value={form.data.warranty_months}
                                onChange={(e) =>
                                    form.setData(
                                        'warranty_months',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.warranty_months} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-shelf">
                                Sisa umur simpan minimal (bulan, opsional)
                            </Label>
                            <Input
                                id="product-shelf"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={120}
                                value={form.data.min_shelf_life_months}
                                onChange={(e) =>
                                    form.setData(
                                        'min_shelf_life_months',
                                        e.target.value,
                                    )
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                Kosong = memakai pengaturan umum.
                            </p>
                            <InputError
                                message={form.errors.min_shelf_life_months}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="product-status">Status</Label>
                            <NativeSelect
                                id="product-status"
                                value={form.data.status}
                                onChange={(e) =>
                                    form.setData('status', e.target.value)
                                }
                            >
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <div className="flex flex-col gap-3 pt-6">
                            {(['is_sellable', 'is_purchasable'] as const).map(
                                (key) => (
                                    <div
                                        key={key}
                                        className="flex items-center gap-3"
                                    >
                                        <Checkbox
                                            id={`product-${key}`}
                                            checked={form.data[key]}
                                            onCheckedChange={(v) =>
                                                form.setData(key, v === true)
                                            }
                                        />
                                        <Label htmlFor={`product-${key}`}>
                                            {key === 'is_sellable'
                                                ? 'Bisa dijual'
                                                : 'Bisa dibeli'}
                                        </Label>
                                    </div>
                                ),
                            )}
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="product-description">
                            Deskripsi (opsional)
                        </Label>
                        <textarea
                            id="product-description"
                            value={form.data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            rows={3}
                            maxLength={5000}
                            className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="product-usage">
                            Petunjuk pemakaian (opsional)
                        </Label>
                        <textarea
                            id="product-usage"
                            value={form.data.usage_note}
                            onChange={(e) =>
                                form.setData('usage_note', e.target.value)
                            }
                            rows={3}
                            maxLength={5000}
                            placeholder="Ringkasan dari label resmi"
                            className="min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Foto produk"
                    description="JPG, PNG, atau WebP. Foto diperkecil otomatis dan metadatanya dibuang."
                >
                    <div className="flex flex-wrap items-center gap-4">
                        {preview ? (
                            <img
                                src={preview}
                                alt="Pratinjau foto produk"
                                className="size-24 rounded-lg border object-cover"
                            />
                        ) : (
                            <span className="grid size-24 place-items-center rounded-lg border bg-muted text-muted-foreground">
                                <Package
                                    className="size-8"
                                    aria-hidden="true"
                                />
                            </span>
                        )}
                        <div className="grid gap-2">
                            <Input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                aria-label="Pilih foto produk"
                                onChange={(e) => {
                                    const file = e.target.files?.[0] ?? null;
                                    form.setData((d) => ({
                                        ...d,
                                        photo: file,
                                        remove_photo: false,
                                    }));
                                    setPreview(
                                        file
                                            ? URL.createObjectURL(file)
                                            : (product?.photo_url ?? null),
                                    );
                                }}
                            />
                            {editing && product.photo_url && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="product-remove-photo"
                                        checked={form.data.remove_photo}
                                        onCheckedChange={(v) => {
                                            form.setData((d) => ({
                                                ...d,
                                                remove_photo: v === true,
                                                photo: null,
                                            }));
                                            setPreview(
                                                v === true
                                                    ? null
                                                    : product.photo_url,
                                            );
                                        }}
                                    />
                                    <Label htmlFor="product-remove-photo">
                                        Hapus foto
                                    </Label>
                                </div>
                            )}
                            <InputError message={form.errors.photo} />
                        </div>
                    </div>
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link
                            href={editing ? `/produk/${product.id}` : '/produk'}
                        >
                            Batal
                        </Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Simpan Produk' : 'Tambah Produk'}
                    </Button>
                </div>
            </form>
        </>
    );
}

ProductForm.layout = {
    breadcrumbs: [{ title: 'Produk', href: '/produk' }],
};
