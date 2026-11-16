import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { FormStatus } from '@/components/form-status';
import {
    DestinationsEditor,
    emptyDestination,
} from '@/components/field-trip/destinations-editor';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { civilDayCount } from '@/lib/local-date';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/admin/field-trips';

type TripData = {
    id: number;
    number: string;
    start_date: string;
    end_date: string;
    destination: string;
    purpose: string | null;
    attendance_rule: string;
    destinations: {
        name: string;
        latitude: string;
        longitude: string;
        radius_m: number | null;
    }[];
    employee_ids: number[];
};

type Props = {
    trip: TripData | null;
    employeeLabels: LookupOption[];
    rules: { value: string; label: string }[];
    maxDays: number;
    defaultRadius: number;
    maxDestinations: number;
};

/** Penjelasan singkat tiap aturan lokasi (BR-071). */
const RULE_HELP: Record<string, string> = {
    destination:
        'Presensi sah di sekitar titik tujuan; bisa beberapa tempat (tempel tautan Google Maps).',
    any_location:
        'Presensi sah di lokasi Pakis aktif mana pun (kantor, gudang, toko).',
    anywhere: 'Presensi sah di mana saja; lokasi & jarak tetap dicatat.',
};

/** Form tugas luar kota (BR-071, T-036A bagian 1): langsung berlaku setelah disimpan. */
export default function FieldTripForm({
    trip,
    employeeLabels,
    rules,
    maxDays,
    defaultRadius,
    maxDestinations,
}: Props) {
    // Label pegawai terpilih dari server, agar select menampilkan nama, bukan ID.
    useState(() => rememberLabels('employees', employeeLabels));

    const form = useForm({
        start_date: trip?.start_date ?? '',
        // Satu hari disimpan dengan selesai = mulai; tampilkan kosong agar maksudnya jelas.
        end_date:
            trip && trip.end_date !== trip.start_date ? trip.end_date : '',
        destination: trip?.destination ?? '',
        purpose: trip?.purpose ?? '',
        attendance_rule: trip?.attendance_rule ?? 'destination',
        destinations: trip?.destinations.length
            ? trip.destinations.map((d) => ({
                  name: d.name,
                  latitude: d.latitude,
                  longitude: d.longitude,
                  radius_m: d.radius_m ? String(d.radius_m) : '',
              }))
            : [emptyDestination()],
        employee_ids: trip?.employee_ids ?? ([] as number[]),
    });
    const { data, errors } = form;
    const days = data.start_date
        ? civilDayCount(data.start_date, data.end_date || data.start_date)
        : null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(trip ? update(trip.id) : store(), { preserveScroll: true });
    };

    const title = trip ? `Ubah tugas ${trip.number}` : 'Tambah tugas luar kota';
    useBreadcrumbTail(
        trip
            ? [{ title: `Ubah ${trip.number}`, href: '#' }]
            : [{ title: 'Tambah', href: '#' }],
    );

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
                            Daftar tugas
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                </div>

                <FormStatus
                    tone="error"
                    message={
                        form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined
                    }
                />

                <SettingsSection
                    title="Tugas"
                    description={`Langsung berlaku setelah disimpan. Paling lama ${maxDays} hari; jadwal & shift pegawai tidak berubah.`}
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="start_date">Mulai tanggal</Label>
                            <Input
                                id="start_date"
                                type="date"
                                value={data.start_date}
                                required
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                                aria-invalid={
                                    errors.start_date ? true : undefined
                                }
                            />
                            <InputError message={errors.start_date} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="end_date">
                                Sampai tanggal{' '}
                                <span className="font-normal text-muted-foreground">
                                    (kosong = 1 hari)
                                </span>
                            </Label>
                            <Input
                                id="end_date"
                                type="date"
                                value={data.end_date}
                                min={data.start_date || undefined}
                                onChange={(e) =>
                                    form.setData('end_date', e.target.value)
                                }
                                aria-invalid={
                                    errors.end_date ? true : undefined
                                }
                            />
                            {days !== null && !errors.end_date && (
                                <p className="text-xs text-muted-foreground">
                                    {days} hari
                                </p>
                            )}
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <div className="mt-4 grid content-start gap-2">
                        <Label htmlFor="destination">Tujuan</Label>
                        <Input
                            id="destination"
                            value={data.destination}
                            maxLength={150}
                            required
                            autoComplete="off"
                            placeholder="Mis. Semarang — toko mitra Jl. Pandanaran"
                            onChange={(e) =>
                                form.setData('destination', e.target.value)
                            }
                            aria-invalid={errors.destination ? true : undefined}
                        />
                        <InputError message={errors.destination} />
                    </div>
                    <div className="mt-4 grid content-start gap-2">
                        <Label htmlFor="purpose">
                            Keperluan{' '}
                            <span className="font-normal text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="purpose"
                            value={data.purpose}
                            maxLength={500}
                            autoComplete="off"
                            placeholder="Mis. kirim pupuk & penagihan"
                            onChange={(e) =>
                                form.setData('purpose', e.target.value)
                            }
                            aria-invalid={errors.purpose ? true : undefined}
                        />
                        <InputError message={errors.purpose} />
                    </div>
                    <div className="mt-4 grid content-start gap-2">
                        <Label htmlFor="employees">Pegawai</Label>
                        <AsyncSelect
                            id="employees"
                            source="employees"
                            multiple
                            value={data.employee_ids}
                            onChange={(value) =>
                                form.setData(
                                    'employee_ids',
                                    value.map((v) => Number(v)),
                                )
                            }
                            placeholder="Ketik nama atau nomor pegawai…"
                            aria-label="Pegawai"
                        />
                        <InputError message={errors.employee_ids} />
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Lokasi presensi selama tugas"
                    description="Berlaku di samping lokasi biasa: presensi di kantor sebelum berangkat tetap sah."
                >
                    <fieldset className="grid gap-2">
                        <legend className="sr-only">Aturan lokasi</legend>
                        {rules.map((rule) => (
                            <label
                                key={rule.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors',
                                    data.attendance_rule === rule.value
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:border-primary/60',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="attendance_rule"
                                    value={rule.value}
                                    checked={
                                        data.attendance_rule === rule.value
                                    }
                                    onChange={() =>
                                        form.setData(
                                            'attendance_rule',
                                            rule.value,
                                        )
                                    }
                                    className="mt-0.5 accent-primary"
                                />
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        {rule.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {RULE_HELP[rule.value]}
                                    </span>
                                </span>
                            </label>
                        ))}
                        <InputError message={errors.attendance_rule} />
                    </fieldset>

                    {data.attendance_rule === 'destination' && (
                        <div className="mt-4">
                            <DestinationsEditor
                                value={data.destinations}
                                onChange={(value) =>
                                    form.setData('destinations', value)
                                }
                                errors={
                                    errors as Record<string, string | undefined>
                                }
                                max={maxDestinations}
                                defaultRadius={defaultRadius}
                            />
                        </div>
                    )}
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {trip ? 'Simpan Tugas' : 'Buat Tugas'}
                    </Button>
                </div>
            </form>
        </>
    );
}

FieldTripForm.layout = {
    breadcrumbs: [{ title: 'Tugas Luar Kota', href: index() }],
};
