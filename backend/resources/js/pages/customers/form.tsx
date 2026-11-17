import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    DuplicateDialog,
    DuplicateNotice,
    useDuplicateCheck,
} from '@/components/customer/duplicate-check';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { LocationPointInput } from '@/components/pakis/location-point-input';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { index, show, store, update } from '@/routes/admin/customers';

type CustomerData = {
    id: number;
    code: string;
    store_name: string;
    owner_name: string;
    owner_phone: string;
    customer_type_id: number | null;
    address: string | null;
    village_code: string | null;
    district_code: string | null;
    regency_code: string | null;
    province_code: string | null;
    latitude: string | null;
    longitude: string | null;
    home_stock_location_id: number | null;
    sales_area_id: number;
    assigned_sales_employee_id: number | null;
    term_days: number;
    status: string;
    is_verified: boolean;
    phone_editable: boolean;
};

type Props = {
    customer: CustomerData | null;
    customerTypes: { value: number; label: string }[];
    codeMode: string;
    defaultTermDays: number;
    regionLabels: LookupOption[];
    homeStockLocationLabel: LookupOption | null;
};

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const idString = (id: number | null | undefined) =>
    id === null || id === undefined ? '' : String(id);

/** Form kios inti (15 §5 `admin.customers.create/edit`, FR-A13 data inti, T-073). */
export default function CustomerForm({
    customer,
    customerTypes,
    codeMode,
    defaultTermDays,
    regionLabels,
    homeStockLocationLabel,
}: Props) {
    const creating = customer === null;
    const manualCode = creating && codeMode === 'manual';
    const phoneLocked = customer !== null && !customer.phone_editable;

    // Label wilayah & gudang terpilih dari server, agar select menampilkan nama, bukan kode/ID.
    useState(() => {
        rememberLabels('regions', regionLabels);
        rememberLabels(
            'stock_locations',
            homeStockLocationLabel ? [homeStockLocationLabel] : [],
        );
    });

    const form = useForm({
        code: '',
        store_name: customer?.store_name ?? '',
        owner_name: customer?.owner_name ?? '',
        owner_phone: customer?.owner_phone ?? '',
        customer_type_id: idString(
            customer?.customer_type_id ?? customerTypes[0]?.value,
        ),
        term_days: String(customer?.term_days ?? defaultTermDays),
        sales_area_id: '',
        assigned_sales_employee_id: '',
        province: customer?.province_code ?? '',
        regency: customer?.regency_code ?? '',
        district: customer?.district_code ?? '',
        village_code: customer?.village_code ?? '',
        address: customer?.address ?? '',
        home_stock_location_id: idString(customer?.home_stock_location_id),
        latitude: customer?.latitude ?? '',
        longitude: customer?.longitude ?? '',
        not_duplicate: false,
        duplicate_reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;

    // Cek kios mirip & HP bentrok sambil mengisi (BR-166, T-074); server memeriksa ulang saat simpan.
    const duplicateCheck = useDuplicateCheck({
        store_name: form.data.store_name,
        owner_phone: form.data.owner_phone,
        latitude: form.data.latitude,
        longitude: form.data.longitude,
        village_code: form.data.village_code,
        customer_id: customer?.id ?? null,
    });
    const candidates = duplicateCheck.result?.candidates ?? [];
    const candidateKey = candidates.map((c) => c.code).join(',');
    // Konfirmasi "bukan duplikat" hanya berlaku untuk daftar kandidat yang dilihat; daftar berubah →
    // harus diperiksa ulang.
    const [confirmedKey, setConfirmedKey] = useState<string | null>(null);
    const confirmed =
        form.data.not_duplicate &&
        confirmedKey === candidateKey &&
        form.data.duplicate_reason.trim().length >= 5;
    const [duplicateOpen, setDuplicateOpen] = useState(false);
    const phoneError =
        errors.owner_phone ??
        (phoneLocked
            ? undefined
            : duplicateCheck.result?.phone_conflict?.message);

    // Mengganti wilayah induk mengosongkan turunannya (pola filter berjenjang T-012).
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
        const next = { ...form.data, [level]: value };
        order.slice(order.indexOf(level) + 1).forEach((l) => (next[l] = ''));
        form.setData(next);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (candidates.length > 0 && !confirmed) {
            setDuplicateOpen(true);

            return;
        }
        send();
    };

    const send = () => {
        // Provinsi–kecamatan hanya alat bantu memilih desa. Kode hanya dikirim saat mode manual;
        // area & sales hanya saat tambah (setelahnya lewat Alihkan, BR-167).
        form.transform(
            ({
                province,
                regency,
                district,
                code,
                sales_area_id,
                assigned_sales_employee_id,
                not_duplicate,
                duplicate_reason,
                ...data
            }) => {
                void province;
                void regency;
                void district;
                const sure = not_duplicate && confirmedKey === candidateKey;

                return {
                    ...data,
                    not_duplicate: sure,
                    duplicate_reason: sure ? duplicate_reason.trim() : null,
                    ...(manualCode ? { code } : {}),
                    ...(creating
                        ? { sales_area_id, assigned_sales_employee_id }
                        : {}),
                };
            },
        );
        form.submit(customer ? update(customer.id) : store(), {
            preserveScroll: true,
            onSuccess: () => setDuplicateOpen(false),
            onError: (errs) => {
                // Server menemukan kandidat yang belum dikonfirmasi (mis. pemeriksaan layar belum
                // selesai): tampilkan dialognya bila daftarnya sudah ada.
                const dup = errs.not_duplicate ?? errs.duplicate_reason;
                setDuplicateOpen(Boolean(dup) && candidates.length > 0);
            },
        });
    };

    const title = customer ? `Ubah kios ${customer.store_name}` : 'Tambah kios';
    useBreadcrumbTail(
        customer
            ? [
                  { title: customer.store_name, href: show(customer.id).url },
                  { title: 'Ubah', href: '#' },
              ]
            : [{ title: 'Tambah', href: '#' }],
    );
    const backHref = customer ? show(customer.id) : index();

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
                noValidate
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={backHref}>
                            <ArrowLeft aria-hidden="true" />
                            {customer ? 'Detail kios' : 'Daftar pelanggan'}
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                </div>

                <FormStatus
                    tone="error"
                    message={
                        errors.form ??
                        (form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined)
                    }
                />

                <SettingsSection
                    title="Data kios"
                    description={
                        creating
                            ? 'Kios baru berstatus Prospek: boleh dikunjungi dan order tunai, tanpa tempo, sampai terverifikasi.'
                            : `Kode ${customer.code}. Kode tidak bisa diubah.`
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        {manualCode ? (
                            <Field
                                id="code"
                                label="Kode kios"
                                error={errors.code}
                            >
                                <Input
                                    id="code"
                                    value={form.data.code}
                                    maxLength={20}
                                    className="font-mono uppercase"
                                    placeholder="KIO-UNDAAN-01"
                                    onChange={(e) =>
                                        form.setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    aria-invalid={
                                        errors.code ? true : undefined
                                    }
                                />
                            </Field>
                        ) : (
                            creating && (
                                <Field id="code-auto" label="Kode kios">
                                    <p
                                        id="code-auto"
                                        className="text-sm text-muted-foreground"
                                    >
                                        Dibuat otomatis saat disimpan.
                                    </p>
                                </Field>
                            )
                        )}
                        <Field
                            id="store_name"
                            label="Nama toko"
                            error={errors.store_name}
                        >
                            <Input
                                id="store_name"
                                value={form.data.store_name}
                                maxLength={150}
                                placeholder="Toko Tani Makmur"
                                onChange={(e) =>
                                    form.setData('store_name', e.target.value)
                                }
                                aria-invalid={
                                    errors.store_name ? true : undefined
                                }
                            />
                        </Field>
                        <Field
                            id="owner_name"
                            label="Nama pemilik"
                            error={errors.owner_name}
                        >
                            <Input
                                id="owner_name"
                                value={form.data.owner_name}
                                maxLength={150}
                                placeholder="Budi Santoso"
                                onChange={(e) =>
                                    form.setData('owner_name', e.target.value)
                                }
                                aria-invalid={
                                    errors.owner_name ? true : undefined
                                }
                            />
                        </Field>
                        <Field
                            id="owner_phone"
                            label="HP pemilik (WhatsApp)"
                            error={phoneError}
                            hint={
                                phoneLocked ? (
                                    <span className="inline-flex items-center gap-1">
                                        <Lock
                                            className="size-3"
                                            aria-hidden="true"
                                        />
                                        HP terkunci setelah kios terverifikasi.
                                        Ganti lewat alur Ganti HP.
                                    </span>
                                ) : (
                                    'Nomor WhatsApp resmi kios, mis. 0812 3456 7890.'
                                )
                            }
                        >
                            <Input
                                id="owner_phone"
                                type="tel"
                                inputMode="tel"
                                value={form.data.owner_phone}
                                maxLength={20}
                                disabled={phoneLocked}
                                placeholder="0812 3456 7890"
                                className="tabular-nums"
                                onChange={(e) =>
                                    form.setData('owner_phone', e.target.value)
                                }
                                aria-invalid={phoneError ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="customer_type_id"
                            label="Jenis pelanggan"
                            error={errors.customer_type_id}
                        >
                            <NativeSelect
                                id="customer_type_id"
                                value={form.data.customer_type_id}
                                onChange={(e) =>
                                    form.setData(
                                        'customer_type_id',
                                        e.target.value,
                                    )
                                }
                            >
                                {customerTypes.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="term_days"
                            label="Tempo standar (hari)"
                            error={errors.term_days}
                            hint="Berlaku setelah kios terverifikasi dan punya limit kredit."
                        >
                            <Input
                                id="term_days"
                                type="number"
                                inputMode="numeric"
                                min={0}
                                max={365}
                                value={form.data.term_days}
                                onChange={(e) =>
                                    form.setData('term_days', e.target.value)
                                }
                                aria-invalid={
                                    errors.term_days ? true : undefined
                                }
                            />
                        </Field>
                    </div>
                </SettingsSection>

                {creating ? (
                    <SettingsSection
                        title="Area & sales"
                        description="Penugasan pertama kios. Perubahan berikutnya lewat Alihkan agar riwayatnya tercatat."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="sales_area_id"
                                label="Area penjualan"
                                error={errors.sales_area_id}
                            >
                                <AsyncSelect
                                    id="sales_area_id"
                                    source="sales_areas"
                                    params={{ assignable: '1' }}
                                    value={one(form.data.sales_area_id)}
                                    placeholder="Cari kode atau nama area"
                                    onChange={(v) =>
                                        form.setData('sales_area_id', first(v))
                                    }
                                />
                            </Field>
                            <Field
                                id="assigned_sales_employee_id"
                                label="Sales (opsional)"
                                error={errors.assigned_sales_employee_id}
                            >
                                <AsyncSelect
                                    id="assigned_sales_employee_id"
                                    source="sales_employees"
                                    value={one(
                                        form.data.assigned_sales_employee_id,
                                    )}
                                    placeholder="Cari nama atau nomor pegawai"
                                    onChange={(v) =>
                                        form.setData(
                                            'assigned_sales_employee_id',
                                            first(v),
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </SettingsSection>
                ) : null}

                <SettingsSection
                    title="Alamat"
                    description="Pilih wilayah berurutan dari provinsi sampai desa/kelurahan."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="province" label="Provinsi">
                            <AsyncSelect
                                id="province"
                                source="regions"
                                params={{ level: 'province' }}
                                value={one(form.data.province)}
                                onChange={(v) =>
                                    setRegion('province', first(v))
                                }
                            />
                        </Field>
                        <Field id="regency" label="Kab/Kota">
                            <AsyncSelect
                                id="regency"
                                source="regions"
                                params={{
                                    level: 'regency',
                                    parent: form.data.province,
                                }}
                                value={one(form.data.regency)}
                                disabled={form.data.province === ''}
                                onChange={(v) => setRegion('regency', first(v))}
                            />
                        </Field>
                        <Field id="district" label="Kecamatan">
                            <AsyncSelect
                                id="district"
                                source="regions"
                                params={{
                                    level: 'district',
                                    parent: form.data.regency,
                                }}
                                value={one(form.data.district)}
                                disabled={form.data.regency === ''}
                                onChange={(v) =>
                                    setRegion('district', first(v))
                                }
                            />
                        </Field>
                        <Field
                            id="village_code"
                            label="Desa/Kelurahan"
                            error={errors.village_code}
                        >
                            <AsyncSelect
                                id="village_code"
                                source="regions"
                                params={{
                                    level: 'village',
                                    parent: form.data.district,
                                }}
                                value={one(form.data.village_code)}
                                disabled={form.data.district === ''}
                                onChange={(v) =>
                                    setRegion('village_code', first(v))
                                }
                            />
                        </Field>
                        <div className="sm:col-span-2">
                            <Field
                                id="address"
                                label="Alamat jalan (opsional)"
                                error={errors.address}
                            >
                                <textarea
                                    id="address"
                                    className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    value={form.data.address}
                                    maxLength={1000}
                                    rows={2}
                                    placeholder="Jl. Raya Undaan No. 12, depan balai desa"
                                    onChange={(e) =>
                                        form.setData('address', e.target.value)
                                    }
                                />
                            </Field>
                        </div>
                        <div className="sm:col-span-2">
                            <Field
                                id="home_stock_location_id"
                                label="Gudang asal (opsional)"
                                error={errors.home_stock_location_id}
                                hint="Kosongkan untuk mengikuti gudang asal area penjualan."
                            >
                                <AsyncSelect
                                    id="home_stock_location_id"
                                    source="stock_locations"
                                    params={{ binnable: '1' }}
                                    value={one(
                                        form.data.home_stock_location_id,
                                    )}
                                    placeholder="Cari gudang atau toko"
                                    onChange={(v) =>
                                        form.setData(
                                            'home_stock_location_id',
                                            first(v),
                                        )
                                    }
                                />
                            </Field>
                        </div>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Titik lokasi kios"
                    description="Opsional sekarang, wajib saat verifikasi kios. Tanpa peta tile: koordinat hanya dikirim ke Google bila Anda sendiri membuka tautannya."
                >
                    <LocationPointInput
                        idPrefix="customer-point"
                        label="Titik kios"
                        latitude={form.data.latitude}
                        longitude={form.data.longitude}
                        errors={{
                            latitude: errors.latitude,
                            longitude: errors.longitude,
                        }}
                        onChange={(p) =>
                            form.setData({
                                ...form.data,
                                latitude: p.latitude,
                                longitude: p.longitude,
                            })
                        }
                    />
                </SettingsSection>

                <DuplicateNotice
                    count={candidates.length}
                    confirmed={confirmed}
                    checking={duplicateCheck.checking}
                    notice={duplicateCheck.notice}
                    error={
                        candidates.length === 0
                            ? (errors.not_duplicate ?? errors.duplicate_reason)
                            : undefined
                    }
                    onReview={() => setDuplicateOpen(true)}
                />

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={backHref}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {creating ? 'Simpan Kios' : 'Simpan Perubahan'}
                    </Button>
                </div>
            </form>

            <DuplicateDialog
                open={duplicateOpen && candidates.length > 0}
                onOpenChange={setDuplicateOpen}
                candidates={candidates}
                notDuplicate={
                    form.data.not_duplicate && confirmedKey === candidateKey
                }
                reason={form.data.duplicate_reason}
                onConfirmChange={({ notDuplicate, reason }) => {
                    setConfirmedKey(candidateKey);
                    form.setData({
                        ...form.data,
                        not_duplicate: notDuplicate,
                        duplicate_reason: reason,
                    });
                }}
                errors={{
                    not_duplicate: errors.not_duplicate,
                    duplicate_reason: errors.duplicate_reason,
                }}
                onSave={send}
                saving={form.processing}
            />
        </>
    );
}

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

CustomerForm.layout = {
    breadcrumbs: [{ title: 'Pelanggan', href: index() }],
};
