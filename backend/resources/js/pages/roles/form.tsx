import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import RoleController from '@/actions/App/Http/Controllers/Admin/RoleController';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/roles';

type Module = {
    module: string;
    label: string;
    permissions: { name: string; label: string }[];
};

type Scope = { value: string; label: string; help: string };

type RoleData = {
    id: number;
    name: string;
    label: string;
    description: string | null;
    data_scope: string;
    mfa_required: boolean;
    is_system: boolean;
    permissions: string[];
    users_count: number;
};

type Props = {
    modules: Module[];
    scopes: Scope[];
    role: RoleData | null;
    readOnly: 'locked' | 'own' | null;
};

const READ_ONLY_MESSAGE: Record<'locked' | 'own', string> = {
    locked: 'Role ini terkunci penuh (Owner / Super Admin) dan tidak bisa diubah siapa pun.',
    own: 'Anda sedang memegang role ini, jadi tidak bisa mengubahnya sendiri. Minta Owner atau pemegang role lain.',
};

/** Form role + matriks permission per modul (07 §4 "Role (form)", FR-017, BR-030). */
export default function RoleForm({ modules, scopes, role, readOnly }: Props) {
    const form = useForm({
        label: role?.label ?? '',
        name: role?.name ?? '',
        description: role?.description ?? '',
        data_scope: role?.data_scope ?? 'self',
        mfa_required: role?.mfa_required ?? false,
        permissions: role?.permissions ?? ([] as string[]),
    });
    const disabled = readOnly !== null;
    const selected = new Set(form.data.permissions);

    const toggle = (names: string[], on: boolean) => {
        const next = new Set(form.data.permissions);
        names.forEach((n) => (on ? next.add(n) : next.delete(n)));
        form.setData('permissions', [...next]);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (role) {
            form.submit(RoleController.update(role.id), {
                preserveScroll: true,
            });
        } else {
            form.submit(RoleController.store(), { preserveScroll: true });
        }
    };

    const title = role
        ? disabled
            ? `Role ${role.label}`
            : `Ubah role ${role.label}`
        : 'Tambah role';

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-5xl space-y-6 p-4"
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar role
                        </Link>
                    </Button>
                    <h1 className="text-xl font-semibold">{title}</h1>
                </div>

                {readOnly && (
                    <div className="flex items-start gap-2.5 rounded-lg bg-status-info-bg px-3.5 py-3 text-sm text-status-info">
                        <Lock
                            className="mt-px size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{READ_ONLY_MESSAGE[readOnly]}</span>
                    </div>
                )}

                <FormStatus
                    tone="error"
                    message={
                        form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined
                    }
                />

                <SettingsSection
                    title="Data role"
                    description="Nama tampil di layar; kode dipakai sistem dan tidak bisa diganti untuk role sistem."
                >
                    <fieldset disabled={disabled} className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid content-start gap-2">
                                <Label htmlFor="label">Nama role</Label>
                                <Input
                                    id="label"
                                    value={form.data.label}
                                    maxLength={60}
                                    required
                                    onChange={(e) =>
                                        form.setData('label', e.target.value)
                                    }
                                    placeholder="Kepala Toko Khusus"
                                    aria-invalid={
                                        form.errors.label ? true : undefined
                                    }
                                />
                                <InputError message={form.errors.label} />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="name">Kode</Label>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    maxLength={40}
                                    required
                                    readOnly={role?.is_system}
                                    className="font-mono"
                                    onChange={(e) =>
                                        form.setData(
                                            'name',
                                            e.target.value.toLowerCase(),
                                        )
                                    }
                                    placeholder="kepala_toko_khusus"
                                    aria-describedby="name-help"
                                    aria-invalid={
                                        form.errors.name ? true : undefined
                                    }
                                />
                                <p
                                    id="name-help"
                                    className="text-xs text-muted-foreground"
                                >
                                    Huruf kecil, angka, garis bawah (3–40).
                                </p>
                                <InputError message={form.errors.name} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="description">
                                Deskripsi{' '}
                                <span className="font-normal text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="description"
                                value={form.data.description}
                                maxLength={255}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>

                        <div className="grid gap-2">
                            <span className="text-sm font-medium">
                                Cakupan data
                            </span>
                            <div
                                role="radiogroup"
                                aria-label="Cakupan data"
                                className="grid gap-2 sm:grid-cols-2"
                            >
                                {scopes.map((s) => (
                                    <label
                                        key={s.value}
                                        className={cn(
                                            'flex cursor-pointer gap-3 rounded-lg border p-3 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                            form.data.data_scope === s.value &&
                                                'border-primary/50 bg-sidebar-accent',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="data_scope"
                                            value={s.value}
                                            checked={
                                                form.data.data_scope === s.value
                                            }
                                            onChange={() =>
                                                form.setData(
                                                    'data_scope',
                                                    s.value,
                                                )
                                            }
                                            className="mt-0.5 accent-primary"
                                        />
                                        <span>
                                            <span className="block font-medium">
                                                {s.label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {s.help}
                                            </span>
                                        </span>
                                    </label>
                                ))}
                            </div>
                            <InputError message={form.errors.data_scope} />
                        </div>

                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={form.data.mfa_required}
                                onCheckedChange={(v) =>
                                    form.setData('mfa_required', v === true)
                                }
                                className="mt-0.5"
                            />
                            <span>
                                <span className="block font-medium">
                                    Wajib verifikasi dua langkah (MFA)
                                </span>
                                <span className="text-muted-foreground">
                                    Pemegang role ini harus mengaktifkan MFA
                                    sebelum bisa bekerja.
                                </span>
                            </span>
                        </label>
                    </fieldset>
                </SettingsSection>

                <SettingsSection
                    title="Matriks permission"
                    description={`${selected.size} permission dipilih. Menu yang tampil mengikuti permission ini.`}
                >
                    <fieldset
                        disabled={disabled}
                        className="@container grid gap-3 sm:grid-cols-2"
                    >
                        {modules.map((m) => {
                            const names = m.permissions.map((p) => p.name);
                            const count = names.filter((n) =>
                                selected.has(n),
                            ).length;
                            const all = count === names.length;

                            return (
                                <div
                                    key={m.module}
                                    className="rounded-lg border p-3"
                                >
                                    <label className="flex items-center gap-2.5 border-b pb-2 text-sm font-semibold">
                                        <Checkbox
                                            checked={
                                                all
                                                    ? true
                                                    : count > 0
                                                      ? 'indeterminate'
                                                      : false
                                            }
                                            onCheckedChange={() =>
                                                toggle(names, !all)
                                            }
                                            aria-label={`Pilih semua permission ${m.label}`}
                                        />
                                        {m.label}
                                        <span className="ml-auto text-xs font-normal text-muted-foreground tabular-nums">
                                            {count}/{names.length}
                                        </span>
                                    </label>
                                    <ul className="mt-2 space-y-1.5">
                                        {m.permissions.map((p) => (
                                            <li key={p.name}>
                                                <label className="flex items-start gap-2.5 text-sm">
                                                    <Checkbox
                                                        checked={selected.has(
                                                            p.name,
                                                        )}
                                                        onCheckedChange={(v) =>
                                                            toggle(
                                                                [p.name],
                                                                v === true,
                                                            )
                                                        }
                                                        className="mt-0.5"
                                                    />
                                                    <span>
                                                        {p.label}
                                                        <span className="block font-mono text-xs text-muted-foreground">
                                                            {p.name}
                                                        </span>
                                                    </span>
                                                </label>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            );
                        })}
                    </fieldset>
                    <InputError message={form.errors.permissions} />
                </SettingsSection>

                {!disabled && (
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Simpan Role
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={index()}>Batal</Link>
                        </Button>
                        {role && role.users_count > 0 && (
                            <span className="text-sm text-muted-foreground">
                                Perubahan langsung berlaku untuk{' '}
                                {role.users_count} pengguna.
                            </span>
                        )}
                    </div>
                )}
            </form>
        </>
    );
}

RoleForm.layout = {
    breadcrumbs: [{ title: 'Role & Hak Akses', href: index() }],
};
