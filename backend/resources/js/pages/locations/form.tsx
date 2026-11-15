import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, MapPin } from 'lucide-react';
import { useState } from 'react';
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
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import {
    googleMapsUrl,
    inIndonesia,
    MAPS_PARSE_MESSAGES,
    parseMapsLink,
} from '@/lib/maps-link';
import { index, store, update } from '@/routes/admin/locations';

type LocationData = {
    id: number;
    code: string;
    name: string;
    location_type_id: number;
    address: string | null;
    village_code: string | null;
    district_code: string | null;
    regency_code: string | null;
    province_code: string | null;
    latitude: string | null;
    longitude: string | null;
    attendance_radius_m: number | null;
    phone: string | null;
    is_active: boolean;
};

type Props = {
    location: LocationData | null;
    types: { value: number; label: string }[];
    defaultRadius: number;
    regionLabels: LookupOption[];
};

/** Satu nilai untuk AsyncSelect tunggal. */
const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');

/** Form lokasi (07 §4, FR-022, BR-041). */
export default function LocationForm({
    location,
    types,
    defaultRadius,
    regionLabels,
}: Props) {
    // Label wilayah terpilih dari server, agar select menampilkan nama, bukan kode.
    useState(() => rememberLabels('regions', regionLabels));

    const form = useForm({
        code: location?.code ?? '',
        name: location?.name ?? '',
        location_type_id: String(
            location?.location_type_id ?? types[0]?.value ?? '',
        ),
        address: location?.address ?? '',
        province: location?.province_code ?? '',
        regency: location?.regency_code ?? '',
        district: location?.district_code ?? '',
        village_code: location?.village_code ?? '',
        latitude: location?.latitude ?? '',
        longitude: location?.longitude ?? '',
        attendance_radius_m:
            location?.attendance_radius_m != null
                ? String(location.attendance_radius_m)
                : '',
        phone: location?.phone ?? '',
        is_active: location?.is_active ?? true,
    });
    const [mapsLink, setMapsLink] = useState('');
    const [mapsMessage, setMapsMessage] = useState<string | null>(null);

    const lat = Number(form.data.latitude);
    const lng = Number(form.data.longitude);
    const hasValidPoint =
        form.data.latitude !== '' &&
        form.data.longitude !== '' &&
        Number.isFinite(lat) &&
        Number.isFinite(lng) &&
        inIndonesia(lat, lng);

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

    const applyMapsLink = () => {
        const result = parseMapsLink(mapsLink);
        if (!result.ok) {
            setMapsMessage(MAPS_PARSE_MESSAGES[result.reason]);

            return;
        }
        form.setData({
            ...form.data,
            latitude: result.latitude,
            longitude: result.longitude,
        });
        setMapsMessage(null);
        setMapsLink('');
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        // Hanya desa yang dikirim; provinsi–kecamatan hanya alat bantu memilih.
        form.transform(({ province, regency, district, ...data }) => {
            void province;
            void regency;
            void district;

            return data;
        });
        form.submit(location ? update(location.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = location ? `Ubah lokasi ${location.name}` : 'Tambah lokasi';
    const err = form.errors;

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-4xl space-y-6 p-4 md:px-7 md:py-6"
                noValidate
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar lokasi
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
                    title="Data lokasi"
                    description="Kode singkat dipakai di laporan & impor, mis. GD-UNDAAN."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="code" label="Kode" error={err.code}>
                            <Input
                                id="code"
                                value={form.data.code}
                                maxLength={20}
                                required
                                className="font-mono uppercase"
                                placeholder="GD-UNDAAN"
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
                                placeholder="Gudang Undaan"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={err.name ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="location_type_id"
                            label="Tipe"
                            error={err.location_type_id}
                        >
                            <NativeSelect
                                id="location_type_id"
                                value={form.data.location_type_id}
                                onChange={(e) =>
                                    form.setData(
                                        'location_type_id',
                                        e.target.value,
                                    )
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
                            id="phone"
                            label="Telepon (opsional)"
                            error={err.phone}
                        >
                            <Input
                                id="phone"
                                type="tel"
                                inputMode="tel"
                                value={form.data.phone}
                                maxLength={20}
                                placeholder="0291 123456"
                                onChange={(e) =>
                                    form.setData('phone', e.target.value)
                                }
                                aria-invalid={err.phone ? true : undefined}
                            />
                        </Field>
                    </div>
                    {/* Checkbox di LUAR <label>: klik ikon centang di dalam label memicu dua kali toggle. */}
                    <div className="mt-4 flex items-center gap-2">
                        <Checkbox
                            id="is_active"
                            checked={form.data.is_active}
                            onCheckedChange={(v) =>
                                form.setData('is_active', v === true)
                            }
                        />
                        <Label
                            htmlFor="is_active"
                            className="text-sm font-normal"
                        >
                            Aktif — lokasi nonaktif tidak bisa dipilih untuk
                            penugasan baru.
                        </Label>
                    </div>
                </SettingsSection>

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
                            error={err.village_code}
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
                    </div>
                    <div className="mt-4">
                        <Field
                            id="address"
                            label="Alamat jalan (opsional)"
                            error={err.address}
                        >
                            <textarea
                                id="address"
                                className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                value={form.data.address}
                                maxLength={500}
                                rows={2}
                                placeholder="Jl. Raya Undaan No. 12"
                                onChange={(e) =>
                                    form.setData('address', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Titik lokasi & radius absen"
                    description="Tanpa koordinat, absen pegawai di lokasi ini tidak bisa diperiksa jaraknya (BR-041)."
                >
                    <div className="space-y-2">
                        <Label htmlFor="maps_link">
                            Tempel tautan Google Maps (opsional)
                        </Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="maps_link"
                                value={mapsLink}
                                placeholder="https://www.google.com/maps/place/…"
                                onChange={(e) => setMapsLink(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        applyMapsLink();
                                    }
                                }}
                                aria-describedby="maps_link_help"
                            />
                            <Button
                                type="button"
                                variant="outline"
                                disabled={mapsLink.trim() === ''}
                                onClick={applyMapsLink}
                            >
                                <MapPin aria-hidden="true" />
                                Ambil koordinat
                            </Button>
                        </div>
                        <p
                            id="maps_link_help"
                            className={
                                mapsMessage
                                    ? 'text-xs text-destructive'
                                    : 'text-xs text-muted-foreground'
                            }
                            role={mapsMessage ? 'alert' : undefined}
                        >
                            {mapsMessage ??
                                'Buka lokasi di Google Maps, salin alamat dari address bar, lalu tempel di sini. Bisa juga menempel angka koordinat "-6.85, 110.84".'}
                        </p>
                    </div>

                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                        <Field
                            id="latitude"
                            label="Latitude"
                            error={err.latitude}
                        >
                            <Input
                                id="latitude"
                                inputMode="decimal"
                                value={form.data.latitude}
                                placeholder="-6.8512345"
                                className="tabular-nums"
                                onChange={(e) =>
                                    form.setData('latitude', e.target.value)
                                }
                                aria-invalid={err.latitude ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="longitude"
                            label="Longitude"
                            error={err.longitude}
                        >
                            <Input
                                id="longitude"
                                inputMode="decimal"
                                value={form.data.longitude}
                                placeholder="110.8423456"
                                className="tabular-nums"
                                onChange={(e) =>
                                    form.setData('longitude', e.target.value)
                                }
                                aria-invalid={err.longitude ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="attendance_radius_m"
                            label="Radius absen (meter)"
                            error={err.attendance_radius_m}
                        >
                            <Input
                                id="attendance_radius_m"
                                type="number"
                                inputMode="numeric"
                                min={20}
                                max={5000}
                                value={form.data.attendance_radius_m}
                                placeholder={`${defaultRadius} (bawaan)`}
                                onChange={(e) =>
                                    form.setData(
                                        'attendance_radius_m',
                                        e.target.value,
                                    )
                                }
                                aria-invalid={
                                    err.attendance_radius_m ? true : undefined
                                }
                            />
                        </Field>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center gap-3 text-sm">
                        {hasValidPoint ? (
                            // Tautan biasa (bukan Maps Platform/API): gratis, tanpa kunci, tab baru.
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                asChild
                            >
                                <a
                                    href={googleMapsUrl(
                                        form.data.latitude,
                                        form.data.longitude,
                                    )}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink aria-hidden="true" />
                                    Lihat di Google Maps
                                </a>
                            </Button>
                        ) : (
                            <span className="text-muted-foreground">
                                Isi koordinat untuk memeriksa titiknya di Google
                                Maps.
                            </span>
                        )}
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

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            {children}
            <InputError message={error} />
        </div>
    );
}

LocationForm.layout = {
    breadcrumbs: [{ title: 'Lokasi', href: index() }],
};
