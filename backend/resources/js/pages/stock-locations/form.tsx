import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Truck } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { index, store, update } from '@/routes/admin/stock-locations';

type StockLocationData = {
    id: number;
    code: string;
    name: string;
    type: string;
    type_label: string;
    location_id: number | null;
    vehicle_id: number | null;
    vehicle_plate: string | null;
    is_sellable: boolean;
    is_active: boolean;
};

type Props = {
    stockLocation: StockLocationData | null;
    types: { value: string; label: string }[];
    locationLabel: LookupOption | null;
};

/** Form lokasi stok gudang/toko (15 §5, FR-A03). Lokasi stok kendaraan: hanya "bisa dijual". */
export default function StockLocationForm({
    stockLocation,
    types,
    locationLabel,
}: Props) {
    // Label lokasi terpilih dari server, agar select menampilkan nama, bukan ID.
    useState(() =>
        rememberLabels('locations', locationLabel ? [locationLabel] : []),
    );

    const isVehicle = stockLocation?.type === 'vehicle';
    const form = useForm({
        code: stockLocation?.code ?? '',
        name: stockLocation?.name ?? '',
        type: stockLocation?.type ?? types[0]?.value ?? 'warehouse',
        location_id:
            stockLocation?.location_id != null
                ? String(stockLocation.location_id)
                : '',
        is_sellable: stockLocation?.is_sellable ?? true,
        is_active: stockLocation?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.submit(stockLocation ? update(stockLocation.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = stockLocation
        ? `Ubah lokasi stok ${stockLocation.name}`
        : 'Tambah lokasi stok';
    useBreadcrumbTail([
        {
            title: stockLocation ? `Ubah ${stockLocation.name}` : 'Tambah',
            href: '#',
        },
    ]);
    const err = form.errors as Record<string, string | undefined>;

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
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar lokasi stok
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                </div>

                {/* Galat domain (mis. masih bersaldo) sudah muncul sebagai toast; di sini tetap
                    ditampilkan agar tidak hilang saat toast tertutup. */}
                <FormStatus
                    tone="error"
                    message={
                        err.form ??
                        (form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined)
                    }
                />

                {isVehicle ? (
                    <SettingsSection
                        title="Lokasi stok kendaraan canvas"
                        description="Kode, nama, lokasi asal, dan status mengikuti data kendaraannya. Ubah lewat halaman Kendaraan."
                    >
                        <div className="flex items-start gap-3 rounded-lg border bg-muted/40 p-4 text-sm">
                            <Truck
                                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div className="grid gap-1">
                                <span className="font-medium">
                                    {stockLocation?.name}
                                </span>
                                <span className="font-mono text-xs text-muted-foreground">
                                    {stockLocation?.code} · plat{' '}
                                    {stockLocation?.vehicle_plate}
                                </span>
                            </div>
                        </div>
                        <SellableField form={form} />
                    </SettingsSection>
                ) : (
                    <SettingsSection
                        title="Data lokasi stok"
                        description="Gudang atau toko di salah satu lokasi kerja. Satu lokasi boleh punya beberapa lokasi stok. Rak karantina Q-01 dibuat otomatis."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="code" label="Kode" error={err.code}>
                                <Input
                                    id="code"
                                    value={form.data.code}
                                    maxLength={20}
                                    required
                                    className="font-mono uppercase"
                                    placeholder="GD-UTAMA"
                                    onChange={(e) =>
                                        form.setData(
                                            'code',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    aria-invalid={err.code ? true : undefined}
                                />
                            </Field>
                            <Field id="name" label="Nama" error={err.name}>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    maxLength={150}
                                    required
                                    placeholder="Gudang Utama Undaan"
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    aria-invalid={err.name ? true : undefined}
                                />
                            </Field>
                            <Field id="type" label="Tipe" error={err.type}>
                                {stockLocation ? (
                                    // Tipe tidak bisa diubah setelah dibuat (PA-43).
                                    <Input
                                        id="type"
                                        value={stockLocation.type_label}
                                        readOnly
                                        disabled
                                    />
                                ) : (
                                    <NativeSelect
                                        id="type"
                                        value={form.data.type}
                                        onChange={(e) =>
                                            form.setData('type', e.target.value)
                                        }
                                    >
                                        {types.map((t) => (
                                            <option
                                                key={t.value}
                                                value={t.value}
                                            >
                                                {t.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                )}
                            </Field>
                            <Field
                                id="location_id"
                                label="Lokasi"
                                error={err.location_id}
                            >
                                <AsyncSelect
                                    id="location_id"
                                    source="locations"
                                    value={
                                        form.data.location_id === ''
                                            ? []
                                            : [form.data.location_id]
                                    }
                                    placeholder="Cari nama atau kode lokasi"
                                    onChange={(v) =>
                                        form.setData(
                                            'location_id',
                                            String(v[0] ?? ''),
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <div className="mt-4 grid gap-3">
                            <SellableField form={form} />
                            <CheckField
                                id="is_active"
                                checked={form.data.is_active}
                                onChange={(v) => form.setData('is_active', v)}
                                label="Aktif"
                                help="Lokasi stok nonaktif tidak bisa dipilih di dokumen baru. Tidak bisa dinonaktifkan selama masih menyimpan barang."
                                error={err.is_active}
                            />
                        </div>
                    </SettingsSection>
                )}

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan
                    </Button>
                </div>
            </form>
        </>
    );
}

function SellableField({
    form,
}: {
    form: {
        data: { is_sellable: boolean };
        setData: (key: 'is_sellable', value: boolean) => void;
        errors: Partial<Record<string, string>>;
    };
}) {
    return (
        <CheckField
            id="is_sellable"
            checked={form.data.is_sellable}
            onChange={(v) => form.setData('is_sellable', v)}
            label="Bisa dijual"
            help="Stok di sini boleh dialokasikan untuk penjualan."
            error={form.errors.is_sellable}
        />
    );
}

function CheckField({
    id,
    checked,
    onChange,
    label,
    help,
    error,
}: {
    id: string;
    checked: boolean;
    onChange: (value: boolean) => void;
    label: string;
    help: string;
    error?: string;
}) {
    return (
        // Checkbox di LUAR <label>: klik ikon centang di dalam label memicu dua kali toggle.
        <div className="flex items-start gap-3">
            <Checkbox
                id={id}
                checked={checked}
                onCheckedChange={(v) => onChange(v === true)}
            />
            <div className="grid gap-0.5">
                <Label htmlFor={id}>{label}</Label>
                <p className="text-xs text-muted-foreground">{help}</p>
                <InputError message={error} />
            </div>
        </div>
    );
}

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

StockLocationForm.layout = {
    breadcrumbs: [{ title: 'Lokasi Stok', href: index() }],
};
