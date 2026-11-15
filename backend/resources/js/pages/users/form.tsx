import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { ArrowLeft, Lock } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { index, show, store, update } from '@/routes/admin/users';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';

type RoleOption = {
    name: string;
    label: string;
    description: string | null;
    data_scope_label: string;
    assignable: boolean;
};

type UserData = {
    id: number;
    name: string;
    username: string;
    email: string | null;
    phone: string | null;
    roles: { name: string; label: string }[];
    employee: {
        id: number;
        name: string;
        number: string;
        url: string | null;
    } | null;
};

type Props = {
    user: UserData | null;
    roleOptions: RoleOption[];
    canAssignRoles: boolean;
};

type FormData = {
    name: string;
    username: string;
    email: string;
    phone: string;
    roles: string[];
    employee_id: string;
    is_active: boolean;
    password_mode: 'generate' | 'email_link';
};

type StoreResponse = {
    id: number;
    username: string;
    temporary_password: string | null;
    show_url: string;
};

/** Form pengguna (07 §4 "Pengguna (form)", FR-016). */
export default function UserForm({ user, roleOptions, canAssignRoles }: Props) {
    // Label pegawai terkait dari server, agar select menampilkan nama, bukan ID.
    useState(() =>
        rememberLabels(
            'employees',
            user?.employee
                ? [
                      {
                          value: user.employee.id,
                          label: user.employee.name,
                          hint: user.employee.number,
                      },
                  ]
                : [],
        ),
    );

    const initial: FormData = {
        name: user?.name ?? '',
        username: user?.username ?? '',
        email: user?.email ?? '',
        phone: user?.phone ?? '',
        roles: user?.roles.map((r) => r.name) ?? [],
        employee_id: user?.employee ? String(user.employee.id) : '',
        is_active: true,
        password_mode: 'generate',
    };
    // Buat akun: respons JSON berisi password sementara (tidak lewat flash/sesi, SEC-006).
    const http = useHttp<FormData, StoreResponse>(initial);
    // Ubah akun: alur Inertia biasa (redirect + toast).
    const form = useForm<FormData>(initial);
    const active = user ? form : http;
    const { data, errors, processing } = active;
    const setData = active.setData as (
        key: keyof FormData,
        value: unknown,
    ) => void;

    const [created, setCreated] = useState<StoreResponse | null>(null);

    const original = new Set(user?.roles.map((r) => r.name) ?? []);
    const selected = new Set(data.roles);
    const hasEmail = data.email.trim() !== '';

    const toggleRole = (name: string, on: boolean) => {
        const next = new Set(data.roles);
        if (on) {
            next.add(name);
        } else {
            next.delete(name);
        }
        setData('roles', [...next]);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (user) {
            form.submit(update(user.id), { preserveScroll: true });

            return;
        }

        http.transform((d) => ({
            ...d,
            password_mode: hasEmail ? d.password_mode : 'generate',
        }));
        http.submit(store(), {
            onSuccess: (res) => {
                if (res.temporary_password) {
                    setCreated(res);
                } else {
                    toast.success(
                        `Akun ${res.username} dibuat. Tautan atur password dikirim ke email.`,
                    );
                    router.visit(res.show_url);
                }
            },
            // 422 ditampilkan per field oleh useHttp; galat lain (403, 429, 500) diberi tahu umum.
            onHttpException: () => {
                toast.error('Akun belum tersimpan. Coba lagi.');
            },
            onNetworkError: () => {
                toast.error('Koneksi terputus. Coba lagi.');
            },
        }).catch(() => {
            // Penolakan sudah ditangani callback di atas.
        });
    };

    const title = user ? `Ubah ${user.name}` : 'Tambah pengguna';
    useBreadcrumbTail(
        user
            ? [
                  { title: user.name, href: show(user.id).url },
                  { title: 'Ubah', href: '#' },
              ]
            : [{ title: 'Tambah', href: '#' }],
    );

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                className="mx-auto w-full max-w-4xl space-y-6 p-4"
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={user ? show(user.id) : index()}>
                            <ArrowLeft aria-hidden="true" />
                            {user ? 'Detail pengguna' : 'Daftar pengguna'}
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                </div>

                <FormStatus
                    tone="error"
                    message={
                        Object.keys(errors).length > 0
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined
                    }
                />

                <SettingsSection
                    title="Data akun"
                    description="Username dipakai untuk masuk dan selalu huruf kecil. Email & HP juga jalur pemulihan password."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="name"
                            label="Nama lengkap"
                            error={errors.name}
                        >
                            <Input
                                id="name"
                                value={data.name}
                                maxLength={150}
                                required
                                autoComplete="off"
                                onChange={(e) =>
                                    setData('name', e.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="username"
                            label="Username"
                            help="3–50 karakter: huruf kecil, angka, titik, garis bawah, tanda hubung."
                            error={errors.username}
                        >
                            <Input
                                id="username"
                                value={data.username}
                                maxLength={50}
                                required
                                autoComplete="off"
                                autoCapitalize="none"
                                spellCheck={false}
                                className="font-mono"
                                onChange={(e) =>
                                    setData(
                                        'username',
                                        e.target.value.toLowerCase(),
                                    )
                                }
                                aria-describedby="username-help"
                                aria-invalid={
                                    errors.username ? true : undefined
                                }
                            />
                        </Field>
                        <Field
                            id="email"
                            label="Email"
                            optional
                            error={errors.email}
                        >
                            <Input
                                id="email"
                                type="email"
                                value={data.email}
                                maxLength={150}
                                autoComplete="off"
                                onChange={(e) =>
                                    setData('email', e.target.value)
                                }
                                aria-invalid={errors.email ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="phone"
                            label="HP"
                            optional
                            error={errors.phone}
                        >
                            <Input
                                id="phone"
                                type="tel"
                                inputMode="tel"
                                value={data.phone}
                                maxLength={25}
                                autoComplete="off"
                                placeholder="0812 3456 7890"
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                                aria-invalid={errors.phone ? true : undefined}
                            />
                        </Field>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="employee">
                            Pegawai terkait{' '}
                            <span className="font-normal text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        {/* Hanya pegawai aktif yang belum punya akun; server memeriksa ulang. */}
                        <AsyncSelect
                            id="employee"
                            source="employees"
                            params={{ without_account: '1' }}
                            value={
                                data.employee_id === ''
                                    ? []
                                    : [Number(data.employee_id)]
                            }
                            placeholder="Cari nama atau nomor pegawai"
                            onChange={(v) =>
                                setData(
                                    'employee_id',
                                    v.length > 0 ? String(v[0]) : '',
                                )
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            Akun pegawai ikut nonaktif saat pegawai
                            dinonaktifkan, dan datanya mengikuti scope pegawai.
                        </p>
                        <InputError message={errors.employee_id} />
                    </div>

                    {!user && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(v) =>
                                    setData('is_active', v === true)
                                }
                            />
                            Akun aktif (bisa langsung dipakai masuk)
                        </label>
                    )}
                </SettingsSection>

                <SettingsSection
                    title="Role"
                    description="Hak akses akun ini. Anda hanya bisa menetapkan role yang seluruh hak aksesnya juga Anda miliki."
                >
                    {!canAssignRoles && (
                        <p className="flex items-start gap-2 rounded-lg bg-status-info-bg px-3 py-2.5 text-sm text-status-info">
                            <Lock
                                className="mt-px size-4 shrink-0"
                                aria-hidden="true"
                            />
                            Anda tidak bisa mengubah role akun ini.
                        </p>
                    )}
                    <fieldset
                        disabled={!canAssignRoles}
                        className="grid gap-2 sm:grid-cols-2"
                        aria-label="Role"
                    >
                        {roleOptions.map((r) => {
                            // Role yang sudah dipegang tapi tak berwenang dilepas tetap terkunci tercentang.
                            const locked = !r.assignable;
                            const checked = selected.has(r.name);

                            return (
                                <label
                                    key={r.name}
                                    title={
                                        locked
                                            ? 'Role ini memuat hak akses yang tidak Anda miliki.'
                                            : undefined
                                    }
                                    className={cn(
                                        'flex gap-3 rounded-lg border p-3 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                        checked &&
                                            'border-primary/50 bg-sidebar-accent',
                                        locked
                                            ? 'cursor-not-allowed opacity-60'
                                            : 'cursor-pointer',
                                    )}
                                >
                                    <Checkbox
                                        className="mt-0.5"
                                        checked={checked}
                                        disabled={locked}
                                        onCheckedChange={(v) =>
                                            toggleRole(r.name, v === true)
                                        }
                                    />
                                    <span className="min-w-0">
                                        <span className="flex flex-wrap items-center gap-1.5 font-medium">
                                            {r.label}
                                            {locked && (
                                                <Lock
                                                    className="size-3.5 text-muted-foreground"
                                                    aria-label="Terkunci"
                                                />
                                            )}
                                            {original.has(r.name) && user && (
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    (saat ini)
                                                </span>
                                            )}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Cakupan: {r.data_scope_label}
                                            {r.description
                                                ? ` · ${r.description}`
                                                : ''}
                                        </span>
                                    </span>
                                </label>
                            );
                        })}
                    </fieldset>
                    <InputError message={errors.roles} />
                </SettingsSection>

                {!user && (
                    <SettingsSection
                        title="Password awal"
                        description="Pengguna wajib mengganti password sementara saat pertama masuk."
                    >
                        <div
                            role="radiogroup"
                            aria-label="Password awal"
                            className="grid gap-2 sm:grid-cols-2"
                        >
                            <ModeOption
                                checked={
                                    !hasEmail ||
                                    data.password_mode === 'generate'
                                }
                                onSelect={() =>
                                    setData('password_mode', 'generate')
                                }
                                title="Buat password sementara"
                                help="Ditampilkan sekali setelah disimpan. Sampaikan langsung ke pengguna."
                            />
                            <ModeOption
                                checked={
                                    hasEmail &&
                                    data.password_mode === 'email_link'
                                }
                                disabled={!hasEmail}
                                onSelect={() =>
                                    setData('password_mode', 'email_link')
                                }
                                title="Kirim tautan atur password"
                                help={
                                    hasEmail
                                        ? 'Tautan dikirim ke email di atas; pengguna mengatur password sendiri.'
                                        : 'Isi email dulu untuk memakai pilihan ini.'
                                }
                            />
                        </div>
                        <InputError message={errors.password_mode} />
                    </SettingsSection>
                )}

                <div className="flex flex-wrap justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={user ? show(user.id) : index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {user ? 'Simpan Perubahan' : 'Simpan Pengguna'}
                    </Button>
                </div>
            </form>

            <OneTimeSecretDialog
                secret={created?.temporary_password ?? null}
                title={`Akun ${created?.username ?? ''} dibuat`}
                description="Password sementara untuk masuk pertama kali. Pengguna akan diminta menggantinya."
                doneLabel="Selesai, buka detail akun"
                onDone={() => {
                    const target = created?.show_url;
                    setCreated(null);
                    if (target) {
                        router.visit(target);
                    }
                }}
            />
        </>
    );
}

function Field({
    id,
    label,
    help,
    optional = false,
    error,
    children,
}: {
    id: string;
    label: string;
    help?: string;
    optional?: boolean;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>
                {label}
                {optional && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        (opsional)
                    </span>
                )}
            </Label>
            {children}
            {help && (
                <p id={`${id}-help`} className="text-xs text-muted-foreground">
                    {help}
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

function ModeOption({
    checked,
    disabled = false,
    onSelect,
    title,
    help,
}: {
    checked: boolean;
    disabled?: boolean;
    onSelect: () => void;
    title: string;
    help: string;
}) {
    return (
        <label
            className={cn(
                'flex gap-3 rounded-lg border p-3 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                checked && 'border-primary/50 bg-sidebar-accent',
                disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
            )}
        >
            <input
                type="radio"
                name="password_mode"
                className="mt-0.5 accent-primary"
                checked={checked}
                disabled={disabled}
                onChange={onSelect}
            />
            <span>
                <span className="block font-medium">{title}</span>
                <span className="block text-xs text-muted-foreground">
                    {help}
                </span>
            </span>
        </label>
    );
}

UserForm.layout = {
    breadcrumbs: [{ title: 'Pengguna', href: index() }],
};
