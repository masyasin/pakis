import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { civilDayCount } from '@/lib/local-date';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/admin/holidays';

type HolidayData = {
    id: number;
    start_date: string;
    end_date: string;
    name: string;
    description: string | null;
    is_national: boolean;
    applies_to_all: boolean;
    location_ids: number[];
};

type Props = {
    holiday: HolidayData | null;
    locationLabels: LookupOption[];
};

const SCOPES = [
    {
        value: true,
        label: 'Semua lokasi',
        help: 'Berlaku untuk semua pegawai, mis. libur nasional.',
    },
    {
        value: false,
        label: 'Lokasi tertentu',
        help: 'Hanya pegawai dengan penugasan utama di lokasi terpilih.',
    },
] as const;

/** Form hari libur (FR-033, BR-062). */
export default function HolidayForm({ holiday, locationLabels }: Props) {
    // Label lokasi terpilih dari server, agar select menampilkan nama, bukan ID.
    useState(() => rememberLabels('locations', locationLabels));

    const form = useForm({
        start_date: holiday?.start_date ?? '',
        // Satu hari disimpan dengan selesai = mulai; tampilkan kosong agar maksudnya jelas.
        end_date:
            holiday && holiday.end_date !== holiday.start_date
                ? holiday.end_date
                : '',
        name: holiday?.name ?? '',
        description: holiday?.description ?? '',
        is_national: holiday?.is_national ?? true,
        applies_to_all: holiday?.applies_to_all ?? true,
        location_ids: holiday?.location_ids ?? [],
    });
    const { data, errors } = form;
    const days = data.end_date
        ? civilDayCount(data.start_date, data.end_date)
        : null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(holiday ? update(holiday.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = holiday
        ? `Ubah hari libur ${holiday.name}`
        : 'Tambah hari libur';

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-3xl space-y-6 p-4 md:px-7 md:py-6"
                noValidate
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar hari libur
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
                    title="Data hari libur"
                    description="Daftar libur nasional & cuti bersama resmi diisi HRD tiap tahun."
                >
                    <div className="grid content-start gap-2">
                        <Label htmlFor="name">Nama</Label>
                        <Input
                            id="name"
                            value={data.name}
                            maxLength={150}
                            required
                            autoComplete="off"
                            placeholder="Idul Fitri & cuti bersama"
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            aria-invalid={errors.name ? true : undefined}
                        />
                        <InputError message={errors.name} />
                    </div>
                    <div className="mt-4 grid gap-4 sm:grid-cols-2">
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
                            <Label htmlFor="end_date">Sampai tanggal</Label>
                            <Input
                                id="end_date"
                                type="date"
                                value={data.end_date}
                                min={data.start_date || undefined}
                                onChange={(e) =>
                                    form.setData('end_date', e.target.value)
                                }
                                aria-describedby="end_date-help"
                                aria-invalid={
                                    errors.end_date ? true : undefined
                                }
                            />
                            <p
                                id="end_date-help"
                                className="text-xs text-muted-foreground"
                                aria-live="polite"
                            >
                                {days === null
                                    ? 'Kosongkan bila libur hanya satu hari.'
                                    : `Total ${days} hari, termasuk tanggal mulai & selesai.`}
                            </p>
                            <InputError message={errors.end_date} />
                        </div>
                    </div>
                    <div className="mt-4 grid content-start gap-2">
                        <Label htmlFor="description">
                            Keterangan (opsional)
                        </Label>
                        <textarea
                            id="description"
                            className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            value={data.description}
                            maxLength={500}
                            rows={2}
                            placeholder="Mis. 1–2 Syawal + cuti bersama sesuai SKB 3 Menteri."
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            aria-invalid={errors.description ? true : undefined}
                        />
                        <InputError message={errors.description} />
                    </div>
                    {/* Checkbox di LUAR <label> (pola form departemen): hindari toggle ganda. */}
                    <div className="mt-4 flex items-start gap-2">
                        <Checkbox
                            id="is_national"
                            className="mt-0.5"
                            checked={data.is_national}
                            onCheckedChange={(v) =>
                                form.setData('is_national', v === true)
                            }
                        />
                        <Label
                            htmlFor="is_national"
                            className="grid gap-0.5 text-sm font-normal"
                        >
                            <span className="font-medium">
                                Libur nasional / cuti bersama
                            </span>
                            <span className="text-xs text-muted-foreground">
                                Kosongkan untuk libur khusus perusahaan.
                            </span>
                        </Label>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Berlaku untuk"
                    description="Pegawai di lokasi yang terkena libur berstatus Libur selama rentang tanggal tersebut."
                >
                    <fieldset className="grid gap-3 sm:grid-cols-2">
                        <legend className="sr-only">Berlaku untuk</legend>
                        {SCOPES.map((scope) => (
                            <label
                                key={scope.label}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 text-sm transition-colors',
                                    data.applies_to_all === scope.value
                                        ? 'border-primary bg-primary/5'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="applies_to_all"
                                    className="mt-0.5 size-4 accent-primary"
                                    checked={
                                        data.applies_to_all === scope.value
                                    }
                                    onChange={() =>
                                        form.setData(
                                            'applies_to_all',
                                            scope.value,
                                        )
                                    }
                                />
                                <span className="grid gap-0.5">
                                    <span className="font-medium">
                                        {scope.label}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {scope.help}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>

                    {!data.applies_to_all && (
                        <div className="mt-4 grid gap-2">
                            <Label htmlFor="location_ids">Lokasi</Label>
                            <AsyncSelect
                                id="location_ids"
                                source="locations"
                                multiple
                                value={data.location_ids}
                                placeholder="Ketik nama atau kode lokasi…"
                                onChange={(v) =>
                                    form.setData(
                                        'location_ids',
                                        v.map((id) => Number(id)),
                                    )
                                }
                            />
                            <InputError message={errors.location_ids} />
                        </div>
                    )}
                </SettingsSection>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan Hari Libur
                    </Button>
                </div>
            </form>
        </>
    );
}

HolidayForm.layout = {
    breadcrumbs: [{ title: 'Hari Libur', href: index() }],
};
