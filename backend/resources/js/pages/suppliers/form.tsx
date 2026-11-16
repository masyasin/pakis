import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';

type SupplierData = {
    id: number;
    code: string;
    name: string;
    principal_id: number | null;
    principal: string | null;
    tax_id: string | null;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    village_code: string | null;
    district_code: string | null;
    regency_code: string | null;
    province_code: string | null;
    payment_term_days: number;
    bank_name: string | null;
    bank_account_masked: string | null;
    bank_account_name: string | null;
    is_active: boolean;
};

type ProductRow = {
    product_id: number;
    sku: string;
    name: string;
    supplier_sku: string | null;
    lead_time_days: number | null;
    is_preferred: boolean;
};

type Props = {
    supplier: SupplierData | null;
    labels: LookupOption[];
    products: ProductRow[];
};

type ProductLine = {
    product_id: string;
    supplier_sku: string;
    lead_time_days: string;
    is_preferred: boolean;
};

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const TEXTAREA =
    'min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

/** Tambah/ubah supplier (15 §5, FR-A11, SEC-108). Nomor rekening lama tidak pernah dikirim ke form. */
export default function SupplierForm({ supplier, labels, products }: Props) {
    const editing = supplier !== null;
    useState(() => {
        rememberLabels('regions', labels);
        rememberLabels(
            'products',
            products.map((p) => ({
                value: p.product_id,
                label: `${p.sku} · ${p.name}`,
            })),
        );
        if (supplier?.principal_id && supplier.principal) {
            rememberLabels('principals', [
                { value: supplier.principal_id, label: supplier.principal },
            ]);
        }

        return true;
    });

    const form = useForm({
        name: supplier?.name ?? '',
        principal_id: supplier?.principal_id
            ? String(supplier.principal_id)
            : '',
        tax_id: supplier?.tax_id ?? '',
        contact_name: supplier?.contact_name ?? '',
        phone: supplier?.phone ?? '',
        email: supplier?.email ?? '',
        address: supplier?.address ?? '',
        province: supplier?.province_code ?? '',
        regency: supplier?.regency_code ?? '',
        district: supplier?.district_code ?? '',
        village_code: supplier?.village_code ?? '',
        payment_term_days: String(supplier?.payment_term_days ?? 30),
        bank_name: supplier?.bank_name ?? '',
        bank_account_no: '',
        bank_account_name: supplier?.bank_account_name ?? '',
        clear_bank_account: false,
        is_active: supplier?.is_active ?? true,
        products: products.map<ProductLine>((p) => ({
            product_id: String(p.product_id),
            supplier_sku: p.supplier_sku ?? '',
            lead_time_days:
                p.lead_time_days === null ? '' : String(p.lead_time_days),
            is_preferred: p.is_preferred,
        })),
    });
    const { data, setData, errors } = form;
    const err = errors as Record<string, string | undefined>;

    // Mengganti wilayah induk mengosongkan turunannya (pola form pegawai).
    const setRegion = (
        level: 'province' | 'regency' | 'district' | 'village_code',
        value: string,
    ) => {
        const order = [
            'province',
            'regency',
            'district',
            'village_code',
        ] as const;
        const next = { ...data, [level]: value };
        order.slice(order.indexOf(level) + 1).forEach((l) => (next[l] = ''));
        setData(next);
    };

    const setLine = (index: number, patch: Partial<ProductLine>) =>
        setData(
            'products',
            data.products.map((l, i) => (i === index ? { ...l, ...patch } : l)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((d) => ({
            name: d.name,
            principal_id: d.principal_id === '' ? null : Number(d.principal_id),
            tax_id: d.tax_id,
            contact_name: d.contact_name,
            phone: d.phone,
            email: d.email,
            address: d.address,
            village_code: d.village_code === '' ? null : d.village_code,
            payment_term_days: d.payment_term_days,
            bank_name: d.bank_name,
            bank_account_no: d.bank_account_no,
            bank_account_name: d.bank_account_name,
            clear_bank_account: d.clear_bank_account,
            is_active: d.is_active,
            products: d.products
                .filter((l) => l.product_id !== '')
                .map((l) => ({
                    product_id: Number(l.product_id),
                    supplier_sku: l.supplier_sku,
                    lead_time_days: l.lead_time_days,
                    is_preferred: l.is_preferred,
                })),
        }));
        if (editing) {
            form.put(`/supplier/${supplier.id}`, { preserveScroll: true });
        } else {
            form.post('/supplier', { preserveScroll: true });
        }
    };

    const title = editing ? `Ubah · ${supplier.name}` : 'Tambah Supplier';

    const field = (
        key:
            | 'name'
            | 'tax_id'
            | 'contact_name'
            | 'phone'
            | 'email'
            | 'bank_name'
            | 'bank_account_name',
        label: string,
        props: Record<string, unknown> = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`supplier-${key}`}>{label}</Label>
            <Input
                id={`supplier-${key}`}
                value={data[key]}
                onChange={(e) => setData(key, e.target.value)}
                aria-invalid={!!err[key] || undefined}
                {...props}
            />
            <InputError message={err[key]} />
        </div>
    );

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <Link
                    href={editing ? `/supplier/${supplier.id}` : '/supplier'}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {editing ? 'Kembali ke supplier' : 'Daftar supplier'}
                </Link>
                <h1 className="text-[1.4rem] leading-tight font-bold">
                    {title}
                </h1>

                <SettingsSection
                    title="Identitas"
                    description={
                        editing
                            ? `Kode ${supplier.code} dibuat sistem dan tidak bisa diubah.`
                            : 'Kode supplier dibuat otomatis saat disimpan (mis. SUP-0001).'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('name', 'Nama supplier', { maxLength: 150 })}
                        <div className="grid gap-2">
                            <Label htmlFor="supplier-principal">
                                Prinsipal (opsional)
                            </Label>
                            <AsyncSelect
                                id="supplier-principal"
                                source="principals"
                                value={one(data.principal_id)}
                                onChange={(v) =>
                                    setData('principal_id', first(v))
                                }
                                placeholder="Isi bila supplier ini pabriknya sendiri / agen resmi"
                            />
                            <InputError message={err.principal_id} />
                        </div>
                        {field('tax_id', 'NPWP (opsional)', {
                            maxLength: 30,
                            inputMode: 'numeric',
                        })}
                        <div className="grid gap-2">
                            <Label htmlFor="supplier-term">
                                Tempo bayar (hari)
                            </Label>
                            <Input
                                id="supplier-term"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={365}
                                value={data.payment_term_days}
                                onChange={(e) =>
                                    setData('payment_term_days', e.target.value)
                                }
                            />
                            <p className="text-xs text-muted-foreground">
                                0 = tunai. Dipakai sebagai bawaan di pesanan
                                pembelian.
                            </p>
                            <InputError message={err.payment_term_days} />
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="supplier-active"
                            checked={data.is_active}
                            onCheckedChange={(v) =>
                                setData('is_active', v === true)
                            }
                        />
                        <Label htmlFor="supplier-active">
                            Aktif (bisa dipilih di pesanan pembelian)
                        </Label>
                    </div>
                </SettingsSection>

                <SettingsSection title="Kontak & alamat">
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('contact_name', 'Nama kontak (opsional)', {
                            maxLength: 100,
                        })}
                        {field('phone', 'Telepon (opsional)', {
                            maxLength: 20,
                            inputMode: 'tel',
                        })}
                        {field('email', 'Email (opsional)', {
                            type: 'email',
                            maxLength: 150,
                        })}
                        <div className="grid gap-2">
                            <Label htmlFor="province">Provinsi</Label>
                            <AsyncSelect
                                id="province"
                                source="regions"
                                params={{ level: 'province' }}
                                value={one(data.province)}
                                onChange={(v) =>
                                    setRegion('province', first(v))
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="regency">Kab/Kota</Label>
                            <AsyncSelect
                                id="regency"
                                source="regions"
                                params={{
                                    level: 'regency',
                                    parent: data.province,
                                }}
                                value={one(data.regency)}
                                disabled={data.province === ''}
                                onChange={(v) => setRegion('regency', first(v))}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="district">Kecamatan</Label>
                            <AsyncSelect
                                id="district"
                                source="regions"
                                params={{
                                    level: 'district',
                                    parent: data.regency,
                                }}
                                value={one(data.district)}
                                disabled={data.regency === ''}
                                onChange={(v) =>
                                    setRegion('district', first(v))
                                }
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="village_code">Desa/Kelurahan</Label>
                            <AsyncSelect
                                id="village_code"
                                source="regions"
                                params={{
                                    level: 'village',
                                    parent: data.district,
                                }}
                                value={one(data.village_code)}
                                disabled={data.district === ''}
                                onChange={(v) =>
                                    setRegion('village_code', first(v))
                                }
                            />
                            <InputError message={err.village_code} />
                        </div>
                        <div className="grid gap-2 sm:col-span-2">
                            <Label htmlFor="supplier-address">
                                Alamat jalan (opsional)
                            </Label>
                            <textarea
                                id="supplier-address"
                                value={data.address}
                                onChange={(e) =>
                                    setData('address', e.target.value)
                                }
                                rows={2}
                                maxLength={2000}
                                className={TEXTAREA}
                            />
                            <InputError message={err.address} />
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Rekening bank"
                    description="Nomor rekening disimpan terenkripsi dan hanya tampil termasking. Membuka nomor lengkap tercatat di log keamanan."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {field('bank_name', 'Nama bank (opsional)', {
                            maxLength: 60,
                            placeholder: 'mis. BRI',
                        })}
                        {field('bank_account_name', 'Atas nama (opsional)', {
                            maxLength: 150,
                        })}
                        <div className="grid gap-2">
                            <Label htmlFor="supplier-account">
                                {editing && supplier.bank_account_masked
                                    ? `Nomor rekening (saat ini ${supplier.bank_account_masked})`
                                    : 'Nomor rekening (opsional)'}
                            </Label>
                            <Input
                                id="supplier-account"
                                value={data.bank_account_no}
                                onChange={(e) =>
                                    setData('bank_account_no', e.target.value)
                                }
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={40}
                                disabled={data.clear_bank_account}
                                placeholder={
                                    editing && supplier.bank_account_masked
                                        ? 'Kosongkan bila tidak diganti'
                                        : ''
                                }
                                aria-invalid={
                                    !!err.bank_account_no || undefined
                                }
                            />
                            <InputError message={err.bank_account_no} />
                        </div>
                        {editing && supplier.bank_account_masked && (
                            <div className="flex items-center gap-3 pt-6">
                                <Checkbox
                                    id="supplier-clear-account"
                                    checked={data.clear_bank_account}
                                    onCheckedChange={(v) =>
                                        setData((d) => ({
                                            ...d,
                                            clear_bank_account: v === true,
                                            bank_account_no: '',
                                        }))
                                    }
                                />
                                <Label htmlFor="supplier-clear-account">
                                    Hapus rekening tersimpan
                                </Label>
                            </div>
                        )}
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Produk dipasok"
                    description="Harga beli terakhir terisi otomatis dari penerimaan barang. Lead time = perkiraan hari dari pesan sampai barang datang."
                >
                    {data.products.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Belum ada produk. Tambahkan produk yang biasa dibeli
                            dari supplier ini.
                        </p>
                    )}
                    <ul className="space-y-3">
                        {data.products.map((line, index) => (
                            <li
                                key={index}
                                className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_7rem_auto_auto] sm:items-end"
                            >
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`sp-product-${index}`}>
                                        Produk
                                    </Label>
                                    <AsyncSelect
                                        id={`sp-product-${index}`}
                                        source="products"
                                        value={one(line.product_id)}
                                        onChange={(v) =>
                                            setLine(index, {
                                                product_id: first(v),
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[`products.${index}.product_id`]
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`sp-sku-${index}`}>
                                        Kode di supplier
                                    </Label>
                                    <Input
                                        id={`sp-sku-${index}`}
                                        value={line.supplier_sku}
                                        maxLength={40}
                                        onChange={(e) =>
                                            setLine(index, {
                                                supplier_sku: e.target.value,
                                            })
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={`sp-lead-${index}`}>
                                        Lead time (hari)
                                    </Label>
                                    <Input
                                        id={`sp-lead-${index}`}
                                        type="number"
                                        inputMode="numeric"
                                        min={0}
                                        max={365}
                                        value={line.lead_time_days}
                                        onChange={(e) =>
                                            setLine(index, {
                                                lead_time_days: e.target.value,
                                            })
                                        }
                                    />
                                    <InputError
                                        message={
                                            err[
                                                `products.${index}.lead_time_days`
                                            ]
                                        }
                                    />
                                </div>
                                <div className="flex items-center gap-2 pb-2">
                                    <Checkbox
                                        id={`sp-pref-${index}`}
                                        checked={line.is_preferred}
                                        onCheckedChange={(v) =>
                                            setLine(index, {
                                                is_preferred: v === true,
                                            })
                                        }
                                    />
                                    <Label htmlFor={`sp-pref-${index}`}>
                                        Utama
                                    </Label>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    aria-label="Hapus baris produk"
                                    onClick={() =>
                                        setData(
                                            'products',
                                            data.products.filter(
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
                        onClick={() =>
                            setData('products', [
                                ...data.products,
                                {
                                    product_id: '',
                                    supplier_sku: '',
                                    lead_time_days: '',
                                    is_preferred: false,
                                },
                            ])
                        }
                    >
                        <Plus aria-hidden="true" />
                        Tambah Produk
                    </Button>
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                editing
                                    ? `/supplier/${supplier.id}`
                                    : '/supplier'
                            }
                        >
                            Batal
                        </Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {editing ? 'Simpan Supplier' : 'Tambah Supplier'}
                    </Button>
                </div>
            </form>
        </>
    );
}

SupplierForm.layout = {
    breadcrumbs: [{ title: 'Supplier', href: '/supplier' }],
};
