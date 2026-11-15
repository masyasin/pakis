import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Clock } from 'lucide-react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { shiftWindowSummary } from '@/lib/shift-time';
import { index, store, update } from '@/routes/admin/shifts';
import type { ShiftRow } from './index';

type Props = { shift: Omit<ShiftRow, 'employees_count'> | null };

/** Form shift (FR-031, BR-060). */
export default function ShiftForm({ shift }: Props) {
    const form = useForm({
        code: shift?.code ?? '',
        name: shift?.name ?? '',
        start_time: shift?.start_time ?? '08:00',
        end_time: shift?.end_time ?? '16:00',
        crosses_midnight: shift?.crosses_midnight ?? false,
        late_tolerance_min: shift?.late_tolerance_min ?? 0,
        checkin_open_min: shift?.checkin_open_min ?? 120,
        checkout_close_min: shift?.checkout_close_min ?? 360,
        color: shift?.color ?? '#2F6B3B',
        is_active: shift?.is_active ?? true,
    });
    const { data, errors } = form;
    const summary = shiftWindowSummary(data);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(shift ? update(shift.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = shift ? `Ubah shift ${shift.name}` : 'Tambah shift';

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-4xl space-y-6 p-4"
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar shift
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
                    title="Data shift"
                    description="Kode tampil di kalender jadwal, jadi buat singkat (mis. PAGI, MLM)."
                >
                    <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
                        <Field id="code" label="Kode" error={errors.code}>
                            <Input
                                id="code"
                                value={data.code}
                                maxLength={20}
                                required
                                autoComplete="off"
                                className="font-mono uppercase"
                                onChange={(e) =>
                                    form.setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                aria-invalid={errors.code ? true : undefined}
                            />
                        </Field>
                        <Field id="name" label="Nama" error={errors.name}>
                            <Input
                                id="name"
                                value={data.name}
                                maxLength={100}
                                required
                                autoComplete="off"
                                placeholder="Shift Pagi Gudang"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                            />
                        </Field>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="start_time"
                            label="Jam masuk"
                            error={errors.start_time}
                        >
                            <Input
                                id="start_time"
                                type="time"
                                value={data.start_time}
                                required
                                onChange={(e) =>
                                    form.setData('start_time', e.target.value)
                                }
                                aria-invalid={
                                    errors.start_time ? true : undefined
                                }
                            />
                        </Field>
                        <Field
                            id="end_time"
                            label="Jam pulang"
                            error={errors.end_time}
                        >
                            <Input
                                id="end_time"
                                type="time"
                                value={data.end_time}
                                required
                                onChange={(e) =>
                                    form.setData('end_time', e.target.value)
                                }
                                aria-invalid={
                                    errors.end_time ? true : undefined
                                }
                            />
                        </Field>
                    </div>

                    <label className="flex items-start gap-2 text-sm">
                        <Checkbox
                            className="mt-0.5"
                            checked={data.crosses_midnight}
                            onCheckedChange={(v) =>
                                form.setData('crosses_midnight', v === true)
                            }
                        />
                        <span>
                            <span className="font-medium">Lintas hari</span>
                            <span className="block text-xs text-muted-foreground">
                                Shift malam yang selesai keesokan harinya (mis.
                                22.00 → 06.00).
                            </span>
                        </span>
                    </label>
                </SettingsSection>

                <SettingsSection
                    title="Aturan absen"
                    description="Batas waktu pegawai bisa absen dari Pakis Pro untuk shift ini."
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <MinutesField
                            id="late_tolerance_min"
                            label="Toleransi telat"
                            help="Masuk setelah jam masuk + toleransi = telat."
                            value={data.late_tolerance_min}
                            error={errors.late_tolerance_min}
                            onChange={(v) =>
                                form.setData('late_tolerance_min', v)
                            }
                        />
                        <MinutesField
                            id="checkin_open_min"
                            label="Absen masuk dibuka"
                            help="Menit sebelum jam masuk."
                            value={data.checkin_open_min}
                            error={errors.checkin_open_min}
                            onChange={(v) =>
                                form.setData('checkin_open_min', v)
                            }
                        />
                        <MinutesField
                            id="checkout_close_min"
                            label="Absen pulang ditutup"
                            help="Menit setelah jam pulang."
                            value={data.checkout_close_min}
                            error={errors.checkout_close_min}
                            onChange={(v) =>
                                form.setData('checkout_close_min', v)
                            }
                        />
                    </div>

                    {summary && (
                        <p
                            className="flex items-start gap-2 rounded-lg bg-status-info-bg px-3 py-2.5 text-sm text-status-info"
                            aria-live="polite"
                        >
                            <Clock
                                className="mt-px size-4 shrink-0"
                                aria-hidden="true"
                            />
                            {summary}
                        </p>
                    )}
                </SettingsSection>

                <SettingsSection
                    title="Tampilan & status"
                    description="Warna membedakan shift di kalender jadwal. Shift nonaktif tidak bisa dipilih di jadwal baru."
                >
                    <div className="flex flex-wrap items-end gap-4">
                        <Field id="color" label="Warna" error={errors.color}>
                            <div className="flex items-center gap-2">
                                <input
                                    id="color"
                                    type="color"
                                    value={data.color}
                                    onChange={(e) =>
                                        form.setData(
                                            'color',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    className="h-9 w-12 cursor-pointer rounded-md border bg-transparent p-1"
                                    aria-label="Pilih warna"
                                />
                                <Input
                                    value={data.color}
                                    maxLength={7}
                                    className="w-28 font-mono uppercase"
                                    aria-label="Kode warna"
                                    onChange={(e) =>
                                        form.setData(
                                            'color',
                                            e.target.value.toUpperCase(),
                                        )
                                    }
                                    aria-invalid={
                                        errors.color ? true : undefined
                                    }
                                />
                            </div>
                        </Field>
                        <label className="flex items-center gap-2 pb-2 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v === true)
                                }
                            />
                            Shift aktif
                        </label>
                    </div>
                </SettingsSection>

                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan Shift
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

function MinutesField({
    id,
    label,
    help,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    help: string;
    value: number;
    error?: string;
    onChange: (v: number) => void;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="relative">
                <Input
                    id={id}
                    type="number"
                    inputMode="numeric"
                    min={0}
                    max={1440}
                    value={value}
                    className="pr-14 tabular-nums"
                    onChange={(e) => onChange(Number(e.target.value))}
                    aria-describedby={`${id}-help`}
                    aria-invalid={error ? true : undefined}
                />
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                    menit
                </span>
            </div>
            <p id={`${id}-help`} className="text-xs text-muted-foreground">
                {help}
            </p>
            <InputError message={error} />
        </div>
    );
}

ShiftForm.layout = {
    breadcrumbs: [{ title: 'Shift', href: index() }],
};
