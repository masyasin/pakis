import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
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
import { index, store, update } from '@/routes/admin/vehicles';

type VehicleData = {
    id: number;
    plate_no: string;
    name: string;
    vehicle_type: string;
    is_canvas: boolean;
    home_location_id: number | null;
    is_active: boolean;
    stock_location_code: string | null;
};

type Props = {
    vehicle: VehicleData | null;
    types: { value: string; label: string }[];
    homeLabel: LookupOption | null;
};

/** Form kendaraan (15 §5, FR-A05). Centang canvas → lokasi stok kendaraan dibuat otomatis. */
export default function VehicleForm({ vehicle, types, homeLabel }: Props) {
    useState(() => rememberLabels('locations', homeLabel ? [homeLabel] : []));

    const form = useForm({
        plate_no: vehicle?.plate_no ?? '',
        name: vehicle?.name ?? '',
        vehicle_type: vehicle?.vehicle_type ?? types[0]?.value ?? 'pickup',
        is_canvas: vehicle?.is_canvas ?? false,
        home_location_id:
            vehicle?.home_location_id != null
                ? String(vehicle.home_location_id)
                : '',
        is_active: vehicle?.is_active ?? true,
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.submit(vehicle ? update(vehicle.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = vehicle
        ? `Ubah kendaraan ${vehicle.plate_no}`
        : 'Tambah kendaraan';
    useBreadcrumbTail([
        { title: vehicle ? `Ubah ${vehicle.plate_no}` : 'Tambah', href: '#' },
    ]);
    const err = form.errors as Record<string, string | undefined>;
    // Pratinjau kode lokasi stok canvas, sama dengan normalisasi plat di server.
    const plate = form.data.plate_no.replace(/\s+/g, '').toUpperCase();

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
                            Daftar kendaraan
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                </div>

                <FormStatus
                    tone="error"
                    message={
                        err.form ??
                        (form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined)
                    }
                />

                <SettingsSection
                    title="Data kendaraan"
                    description="Data armada lengkap (STNK, servis, BBM) menyusul di modul Armada."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="plate_no"
                            label="Plat nomor"
                            error={err.plate_no}
                        >
                            <Input
                                id="plate_no"
                                value={form.data.plate_no}
                                maxLength={20}
                                required
                                className="font-mono uppercase"
                                placeholder="K 9021 AB"
                                autoComplete="off"
                                spellCheck={false}
                                onChange={(e) =>
                                    form.setData(
                                        'plate_no',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                aria-invalid={err.plate_no ? true : undefined}
                                aria-describedby="plate_no_help"
                            />
                            <p
                                id="plate_no_help"
                                className="text-xs text-muted-foreground"
                            >
                                Spasi dibuang otomatis: disimpan sebagai{' '}
                                <span className="font-mono">
                                    {plate || 'K9021AB'}
                                </span>
                                .
                            </p>
                        </Field>
                        <Field id="name" label="Nama" error={err.name}>
                            <Input
                                id="name"
                                value={form.data.name}
                                maxLength={100}
                                required
                                placeholder="Pikap Canvas Undaan 1"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={err.name ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="vehicle_type"
                            label="Jenis"
                            error={err.vehicle_type}
                        >
                            <NativeSelect
                                id="vehicle_type"
                                value={form.data.vehicle_type}
                                onChange={(e) =>
                                    form.setData('vehicle_type', e.target.value)
                                }
                            >
                                {types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="home_location_id"
                            label={
                                form.data.is_canvas
                                    ? 'Lokasi asal'
                                    : 'Lokasi asal (opsional)'
                            }
                            error={err.home_location_id}
                        >
                            <AsyncSelect
                                id="home_location_id"
                                source="locations"
                                value={
                                    form.data.home_location_id === ''
                                        ? []
                                        : [form.data.home_location_id]
                                }
                                placeholder="Cari nama atau kode lokasi"
                                onChange={(v) =>
                                    form.setData(
                                        'home_location_id',
                                        String(v[0] ?? ''),
                                    )
                                }
                            />
                        </Field>
                    </div>

                    <div className="mt-4 grid gap-3">
                        <CheckField
                            id="is_canvas"
                            checked={form.data.is_canvas}
                            onChange={(v) => form.setData('is_canvas', v)}
                            label="Kendaraan canvas"
                            help={
                                form.data.is_canvas
                                    ? `Lokasi stok KDR-${plate || 'plat'} dibuat/diaktifkan otomatis di lokasi asal. Mencabut centang menonaktifkannya (ditolak bila masih ada muatan).`
                                    : 'Centang bila kendaraan membawa barang untuk dijual keliling; lokasi stoknya dibuat otomatis.'
                            }
                            error={err.is_canvas}
                        />
                        <CheckField
                            id="is_active"
                            checked={form.data.is_active}
                            onChange={(v) => form.setData('is_active', v)}
                            label="Aktif"
                            help="Kendaraan nonaktif ikut menonaktifkan lokasi stok canvasnya."
                            error={err.is_active}
                        />
                    </div>
                </SettingsSection>

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

VehicleForm.layout = {
    breadcrumbs: [{ title: 'Kendaraan', href: index() }],
};
