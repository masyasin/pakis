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
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { index, store, update } from '@/routes/admin/positions';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';

type Mode = 'location' | 'any_location' | 'anywhere';

type PositionData = {
    id: number;
    code: string;
    name: string;
    department_id: number;
    parent_position_id: number | null;
    level: number;
    attendance_mode: Mode;
    is_active: boolean;
};

type Props = {
    position: PositionData | null;
    modes: { value: Mode; label: string; help: string }[];
    levelRange: [number, number];
    labels: Partial<Record<'departments' | 'positions', LookupOption[]>>;
};

const one = (value: number | null) => (value === null ? [] : [value]);
const first = (value: Array<number | string>) =>
    value.length > 0 ? Number(value[0]) : null;

/** Form jabatan (07 §4, FR-024, BR-042). Atasan tidak boleh membentuk lingkaran (dicek server). */
export default function PositionForm({
    position,
    modes,
    levelRange,
    labels,
}: Props) {
    // Label terpilih dari server, agar select menampilkan nama, bukan ID.
    useState(() =>
        Object.entries(labels).forEach(([source, options]) =>
            rememberLabels(source, options ?? []),
        ),
    );

    const form = useForm({
        code: position?.code ?? '',
        name: position?.name ?? '',
        department_id: position?.department_id ?? null,
        parent_position_id: position?.parent_position_id ?? null,
        level: String(position?.level ?? 5),
        attendance_mode: position?.attendance_mode ?? ('location' as Mode),
        is_active: position?.is_active ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(position ? update(position.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = position ? `Ubah jabatan ${position.name}` : 'Tambah jabatan';
    useBreadcrumbTail(
        position
            ? [{ title: `Ubah ${position.name}`, href: '#' }]
            : [{ title: 'Tambah', href: '#' }],
    );
    const err = form.errors;

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
                            Daftar jabatan
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
                    title="Data jabatan"
                    description="Level 1 = paling tinggi. Atasan dipakai untuk struktur & alur persetujuan."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="code">Kode</Label>
                            <Input
                                id="code"
                                value={form.data.code}
                                maxLength={20}
                                required
                                className="font-mono uppercase"
                                placeholder="KA-GDG"
                                onChange={(e) =>
                                    form.setData(
                                        'code',
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                aria-invalid={err.code ? true : undefined}
                            />
                            <InputError message={err.code} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="name">Nama</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                maxLength={150}
                                required
                                placeholder="Kepala Gudang"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={err.name ? true : undefined}
                            />
                            <InputError message={err.name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="department_id">Departemen</Label>
                            <AsyncSelect
                                id="department_id"
                                source="departments"
                                value={one(form.data.department_id)}
                                onChange={(v) =>
                                    form.setData('department_id', first(v))
                                }
                            />
                            <InputError message={err.department_id} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="level">Level</Label>
                            <Input
                                id="level"
                                type="number"
                                inputMode="numeric"
                                min={levelRange[0]}
                                max={levelRange[1]}
                                value={form.data.level}
                                onChange={(e) =>
                                    form.setData('level', e.target.value)
                                }
                                aria-invalid={err.level ? true : undefined}
                            />
                            <InputError message={err.level} />
                        </div>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="parent_position_id">
                                Jabatan atasan (opsional)
                            </Label>
                            <AsyncSelect
                                id="parent_position_id"
                                source="positions"
                                params={
                                    position
                                        ? { exclude: String(position.id) }
                                        : undefined
                                }
                                value={one(form.data.parent_position_id)}
                                placeholder="Kosongkan bila jabatan paling atas"
                                onChange={(v) =>
                                    form.setData('parent_position_id', first(v))
                                }
                            />
                            <InputError message={err.parent_position_id} />
                        </div>
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
                            Aktif — jabatan nonaktif tidak bisa dipilih untuk
                            penugasan baru.
                        </Label>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Mode absen bawaan"
                    description="Berlaku untuk pegawai berjabatan ini, kecuali ditimpa di penugasannya."
                >
                    <fieldset className="grid gap-3">
                        <legend className="sr-only">Mode absen bawaan</legend>
                        {modes.map((m) => (
                            <label
                                key={m.value}
                                className={cn(
                                    'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50',
                                    form.data.attendance_mode === m.value &&
                                        'border-primary bg-sidebar-accent',
                                )}
                            >
                                <input
                                    type="radio"
                                    name="attendance_mode"
                                    value={m.value}
                                    checked={
                                        form.data.attendance_mode === m.value
                                    }
                                    onChange={() =>
                                        form.setData('attendance_mode', m.value)
                                    }
                                    className="mt-1 size-4 accent-[var(--primary)]"
                                />
                                <span>
                                    <span className="block text-sm font-semibold">
                                        {m.label}
                                    </span>
                                    <span className="block text-sm text-muted-foreground">
                                        {m.help}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <InputError message={err.attendance_mode} />
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

PositionForm.layout = {
    breadcrumbs: [{ title: 'Jabatan', href: index() }],
};
