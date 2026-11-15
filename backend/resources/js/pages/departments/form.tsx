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
import { index, store, update } from '@/routes/admin/departments';

type DepartmentData = {
    id: number;
    code: string;
    name: string;
    parent_id: number | null;
    is_active: boolean;
};

type Props = {
    department: DepartmentData | null;
    parentLabel: LookupOption | null;
};

/** Form departemen (07 §4, FR-023). Induk tidak boleh membentuk lingkaran (dicek server). */
export default function DepartmentForm({ department, parentLabel }: Props) {
    // Label induk terpilih dari server, agar select menampilkan nama, bukan ID.
    useState(() => parentLabel && rememberLabels('departments', [parentLabel]));

    const form = useForm({
        code: department?.code ?? '',
        name: department?.name ?? '',
        parent_id: department?.parent_id ?? null,
        is_active: department?.is_active ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.submit(department ? update(department.id) : store(), {
            preserveScroll: true,
        });
    };

    const title = department
        ? `Ubah departemen ${department.name}`
        : 'Tambah departemen';
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
                            Daftar departemen
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
                    title="Data departemen"
                    description="Kode singkat dipakai di laporan & impor, mis. OPS atau GUDANG."
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
                                placeholder="OPS"
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
                                placeholder="Operasional"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={err.name ? true : undefined}
                            />
                            <InputError message={err.name} />
                        </div>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="parent_id">Induk (opsional)</Label>
                            <AsyncSelect
                                id="parent_id"
                                source="departments"
                                params={
                                    department
                                        ? { exclude: String(department.id) }
                                        : undefined
                                }
                                value={
                                    form.data.parent_id === null
                                        ? []
                                        : [form.data.parent_id]
                                }
                                placeholder="Kosongkan bila departemen paling atas"
                                onChange={(v) =>
                                    form.setData(
                                        'parent_id',
                                        v.length > 0 ? Number(v[0]) : null,
                                    )
                                }
                            />
                            <InputError message={err.parent_id} />
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
                            Aktif — departemen nonaktif tidak bisa dipilih untuk
                            jabatan atau pegawai baru.
                        </Label>
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

DepartmentForm.layout = {
    breadcrumbs: [{ title: 'Departemen', href: index() }],
};
