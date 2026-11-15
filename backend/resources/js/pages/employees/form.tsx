import { Head, Link, router, useForm, useHttp } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { index, show, store, update } from '@/routes/admin/employees';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';

type Mode = 'location' | 'any_location' | 'anywhere';

type EmployeeData = {
    id: number;
    employee_number: string;
    full_name: string;
    gender: 'L' | 'P';
    nik_masked: string | null;
    birth_date: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    village_code: string | null;
    district_code: string | null;
    regency_code: string | null;
    province_code: string | null;
    photo_url: string | null;
    employment_status_id: number;
    join_date: string;
};

type RoleOption = {
    name: string;
    label: string;
    description: string | null;
    data_scope_label: string;
    assignable: boolean;
};

type Props = {
    employee: EmployeeData | null;
    genders: { value: 'L' | 'P'; label: string }[];
    modes: { value: Mode; label: string; help: string }[];
    employmentStatuses: { value: number; label: string }[];
    labels: LookupOption[];
    canCreateAccount: boolean;
    roleOptions: RoleOption[];
};

type StoreResponse = {
    id: number;
    full_name: string;
    username: string | null;
    temporary_password: string | null;
    show_url: string;
};

type Fields = {
    full_name: string;
    gender: 'L' | 'P' | '';
    nik: string;
    remove_nik: boolean;
    birth_date: string;
    phone: string;
    email: string;
    address: string;
    province: string;
    regency: string;
    district: string;
    village_code: string;
    employment_status_id: string;
    join_date: string;
    photo: File | null;
    remove_photo: boolean;
    assignment: {
        position_id: string;
        location_id: string;
        supervisor_employee_id: string;
        attendance_mode: Mode | '';
    };
    account: {
        create: boolean;
        username: string;
        roles: string[];
        password_mode: 'generate' | 'email_link';
    };
};

/** Saran username di browser (server tetap memeriksa unik): dua kata pertama, huruf kecil. */
function suggestUsername(name: string): string {
    const words = name
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .split(/\s+/)
        .map((w) => w.replace(/[^a-z0-9]/g, ''))
        .filter((w) => w !== '');

    return words.slice(0, 2).join('.').slice(0, 46);
}

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const today = () => new Date().toISOString().slice(0, 10);

/**
 * Form pegawai (07 §4, FR-025, BR-050). Saat tambah: form gabungan data diri + penugasan pertama
 * (+ akun opsional), disimpan dalam satu transaksi. Respons tambah berupa JSON karena bisa memuat
 * password sementara akun baru yang hanya boleh tampil sekali (SEC-006).
 */
export default function EmployeeForm({
    employee,
    genders,
    modes,
    employmentStatuses,
    labels,
    canCreateAccount,
    roleOptions,
}: Props) {
    // Label wilayah terpilih dari server, agar select menampilkan nama, bukan kode.
    useState(() => rememberLabels('regions', labels));

    const assignable = roleOptions.filter((r) => r.assignable);
    const initial: Fields = {
        full_name: employee?.full_name ?? '',
        gender: employee?.gender ?? '',
        nik: '',
        remove_nik: false,
        birth_date: employee?.birth_date ?? '',
        phone: employee?.phone ? `0${employee.phone.slice(2)}` : '',
        email: employee?.email ?? '',
        address: employee?.address ?? '',
        province: employee?.province_code ?? '',
        regency: employee?.regency_code ?? '',
        district: employee?.district_code ?? '',
        village_code: employee?.village_code ?? '',
        employment_status_id: String(
            employee?.employment_status_id ??
                employmentStatuses[0]?.value ??
                '',
        ),
        join_date: employee?.join_date ?? today(),
        photo: null,
        remove_photo: false,
        assignment: {
            position_id: '',
            location_id: '',
            supervisor_employee_id: '',
            attendance_mode: '',
        },
        account: {
            create: false,
            username: '',
            roles: assignable.some((r) => r.name === 'pegawai')
                ? ['pegawai']
                : [],
            password_mode: 'generate',
        },
    };

    // Tambah: JSON (password sementara). Ubah: alur Inertia biasa (redirect + toast).
    const http = useHttp<Fields, StoreResponse>(initial);
    const form = useForm<Fields>(initial);
    const active = employee ? form : http;
    const { data, errors, processing } = active;
    const setData = active.setData as <K extends keyof Fields>(
        key: K,
        value: Fields[K],
    ) => void;
    const err = errors as Record<string, string | undefined>;

    const [created, setCreated] = useState<StoreResponse | null>(null);
    const [usernameTouched, setUsernameTouched] = useState(false);

    const setName = (value: string) => {
        const next: Fields = { ...data, full_name: value };
        if (!usernameTouched) {
            next.account = {
                ...data.account,
                username: suggestUsername(value),
            };
        }
        (active.setData as (d: Fields) => void)(next);
    };

    const setAssignment = <K extends keyof Fields['assignment']>(
        key: K,
        value: Fields['assignment'][K],
    ) => setData('assignment', { ...data.assignment, [key]: value });

    const setAccount = <K extends keyof Fields['account']>(
        key: K,
        value: Fields['account'][K],
    ) => setData('account', { ...data.account, [key]: value });

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
        const next = { ...data, [level]: value };
        order.slice(order.indexOf(level) + 1).forEach((l) => (next[l] = ''));
        (active.setData as (d: Fields) => void)(next);
    };

    const hasEmail = data.email.trim() !== '';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (employee) {
            // PHP tidak membaca berkas dari PUT multipart: kirim POST + `_method`.
            form.transform((d) => ({ ...d, _method: 'put' }));
            form.post(update(employee.id).url, { preserveScroll: true });

            return;
        }

        http.transform((d) => ({
            ...d,
            account: {
                ...d.account,
                password_mode: hasEmail ? d.account.password_mode : 'generate',
            },
        }));
        http.submit(store(), {
            onSuccess: (res) => {
                if (res.temporary_password) {
                    setCreated(res);
                } else {
                    toast.success(
                        res.username
                            ? `Pegawai ${res.full_name} ditambahkan. Tautan atur password dikirim ke email.`
                            : `Pegawai ${res.full_name} ditambahkan.`,
                    );
                    router.visit(res.show_url);
                }
            },
            // 422 ditampilkan per field; galat lain diberi tahu umum.
            onHttpException: () => {
                toast.error('Pegawai belum tersimpan. Coba lagi.');
            },
            onNetworkError: () => {
                toast.error('Koneksi terputus. Coba lagi.');
            },
        }).catch(() => {
            // Ditangani callback di atas.
        });
    };

    const title = employee ? `Ubah ${employee.full_name}` : 'Tambah pegawai';
    useBreadcrumbTail(
        employee
            ? [
                  { title: employee.full_name, href: show(employee.id).url },
                  { title: 'Ubah', href: '#' },
              ]
            : [{ title: 'Tambah', href: '#' }],
    );

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
                        <Link href={employee ? show(employee.id) : index()}>
                            <ArrowLeft aria-hidden="true" />
                            {employee ? 'Detail pegawai' : 'Daftar pegawai'}
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        {title}
                    </h1>
                    {employee && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {employee.employee_number}
                        </span>
                    )}
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
                    title="Data pribadi"
                    description={
                        employee
                            ? 'Nomor pegawai dibuat sistem dan tidak bisa diubah.'
                            : 'Nomor pegawai dibuat otomatis saat disimpan.'
                    }
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="full_name"
                            label="Nama lengkap"
                            error={err.full_name}
                            className="sm:col-span-2"
                        >
                            <Input
                                id="full_name"
                                value={data.full_name}
                                maxLength={150}
                                required
                                autoComplete="off"
                                placeholder="Sesuai KTP"
                                onChange={(e) => setName(e.target.value)}
                                aria-invalid={err.full_name ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="gender"
                            label="Jenis kelamin"
                            error={err.gender}
                        >
                            <NativeSelect
                                id="gender"
                                value={data.gender}
                                onChange={(e) =>
                                    setData(
                                        'gender',
                                        e.target.value as Fields['gender'],
                                    )
                                }
                                aria-invalid={err.gender ? true : undefined}
                            >
                                <option value="">Pilih…</option>
                                {genders.map((g) => (
                                    <option key={g.value} value={g.value}>
                                        {g.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="birth_date"
                            label="Tanggal lahir"
                            optional
                            error={err.birth_date}
                        >
                            <Input
                                id="birth_date"
                                type="date"
                                value={data.birth_date}
                                max={today()}
                                onChange={(e) =>
                                    setData('birth_date', e.target.value)
                                }
                                aria-invalid={err.birth_date ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="nik"
                            label="NIK"
                            optional
                            error={err.nik}
                            help={
                                employee?.nik_masked
                                    ? `Tersimpan: ${employee.nik_masked}. Kosongkan bila tidak diubah.`
                                    : '16 digit sesuai KTP. Disimpan terenkripsi.'
                            }
                        >
                            <Input
                                id="nik"
                                value={data.nik}
                                inputMode="numeric"
                                autoComplete="off"
                                maxLength={20}
                                disabled={data.remove_nik}
                                placeholder={
                                    employee?.nik_masked
                                        ? 'Isi untuk mengganti NIK'
                                        : '3319xxxxxxxxxxxx'
                                }
                                onChange={(e) => setData('nik', e.target.value)}
                                aria-invalid={err.nik ? true : undefined}
                            />
                            {employee?.nik_masked && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="remove_nik"
                                        checked={data.remove_nik}
                                        onCheckedChange={(v) => {
                                            setData('remove_nik', v === true);
                                        }}
                                    />
                                    <Label
                                        htmlFor="remove_nik"
                                        className="text-sm font-normal"
                                    >
                                        Hapus NIK
                                    </Label>
                                </div>
                            )}
                        </Field>
                        <Field id="phone" label="HP" optional error={err.phone}>
                            <Input
                                id="phone"
                                type="tel"
                                inputMode="tel"
                                value={data.phone}
                                maxLength={20}
                                placeholder="0812-3456-7890"
                                onChange={(e) =>
                                    setData('phone', e.target.value)
                                }
                                aria-invalid={err.phone ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="email"
                            label="Email"
                            optional
                            error={err.email}
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
                                aria-invalid={err.email ? true : undefined}
                            />
                        </Field>
                        <Field
                            id="photo"
                            label="Foto"
                            optional
                            error={err.photo}
                            help="PNG, JPG, atau WebP, maksimal 2 MB. Dipotong persegi."
                        >
                            <div className="flex items-center gap-3">
                                {employee?.photo_url && !data.remove_photo && (
                                    <img
                                        src={employee.photo_url}
                                        alt=""
                                        className="size-12 rounded-full object-cover"
                                    />
                                )}
                                <Input
                                    id="photo"
                                    type="file"
                                    accept="image/png,image/jpeg,image/webp"
                                    onChange={(e) =>
                                        setData(
                                            'photo',
                                            e.target.files?.[0] ?? null,
                                        )
                                    }
                                    aria-invalid={err.photo ? true : undefined}
                                />
                            </div>
                            {employee?.photo_url && (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="remove_photo"
                                        checked={data.remove_photo}
                                        onCheckedChange={(v) =>
                                            setData('remove_photo', v === true)
                                        }
                                    />
                                    <Label
                                        htmlFor="remove_photo"
                                        className="text-sm font-normal"
                                    >
                                        Hapus foto
                                    </Label>
                                </div>
                            )}
                        </Field>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Alamat"
                    description="Opsional. Pilih wilayah berurutan dari provinsi sampai desa/kelurahan."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="province" label="Provinsi">
                            <AsyncSelect
                                id="province"
                                source="regions"
                                params={{ level: 'province' }}
                                value={one(data.province)}
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
                                    parent: data.province,
                                }}
                                value={one(data.regency)}
                                disabled={data.province === ''}
                                onChange={(v) => setRegion('regency', first(v))}
                            />
                        </Field>
                        <Field id="district" label="Kecamatan">
                            <AsyncSelect
                                id="district"
                                source="regions"
                                params={{
                                    level: 'district',
                                    parent: data.regency,
                                }}
                                value={one(data.district)}
                                disabled={data.regency === ''}
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
                                    parent: data.district,
                                }}
                                value={one(data.village_code)}
                                disabled={data.district === ''}
                                onChange={(v) =>
                                    setRegion('village_code', first(v))
                                }
                            />
                        </Field>
                        <Field
                            id="address"
                            label="Alamat jalan"
                            optional
                            error={err.address}
                            className="sm:col-span-2"
                        >
                            <textarea
                                id="address"
                                className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                value={data.address}
                                maxLength={500}
                                onChange={(e) =>
                                    setData('address', e.target.value)
                                }
                            />
                        </Field>
                    </div>
                </SettingsSection>

                <SettingsSection
                    title="Kepegawaian"
                    description="Status pegawai (aktif/nonaktif) diubah lewat Nonaktifkan, bukan di sini."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field
                            id="employment_status_id"
                            label="Status kerja"
                            error={err.employment_status_id}
                        >
                            <NativeSelect
                                id="employment_status_id"
                                value={data.employment_status_id}
                                onChange={(e) =>
                                    setData(
                                        'employment_status_id',
                                        e.target.value,
                                    )
                                }
                            >
                                {employmentStatuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field
                            id="join_date"
                            label="Tanggal masuk"
                            error={err.join_date}
                        >
                            <Input
                                id="join_date"
                                type="date"
                                value={data.join_date}
                                onChange={(e) =>
                                    setData('join_date', e.target.value)
                                }
                                aria-invalid={err.join_date ? true : undefined}
                            />
                        </Field>
                    </div>
                </SettingsSection>

                {!employee && (
                    <SettingsSection
                        title="Penugasan pertama"
                        description="Wajib untuk pegawai baru. Mutasi berikutnya dilakukan dari halaman detail pegawai."
                    >
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                id="position_id"
                                label="Jabatan"
                                error={err['assignment.position_id']}
                            >
                                <AsyncSelect
                                    id="position_id"
                                    source="positions"
                                    value={one(data.assignment.position_id)}
                                    onChange={(v) =>
                                        setAssignment('position_id', first(v))
                                    }
                                />
                            </Field>
                            <Field
                                id="location_id"
                                label="Lokasi tugas"
                                error={err['assignment.location_id']}
                            >
                                <AsyncSelect
                                    id="location_id"
                                    source="locations"
                                    value={one(data.assignment.location_id)}
                                    onChange={(v) =>
                                        setAssignment('location_id', first(v))
                                    }
                                />
                            </Field>
                            <Field
                                id="supervisor_employee_id"
                                label="Atasan langsung"
                                optional
                                error={err['assignment.supervisor_employee_id']}
                                className="sm:col-span-2"
                            >
                                <AsyncSelect
                                    id="supervisor_employee_id"
                                    source="employees"
                                    value={one(
                                        data.assignment.supervisor_employee_id,
                                    )}
                                    placeholder="Cari nama atau nomor pegawai"
                                    onChange={(v) =>
                                        setAssignment(
                                            'supervisor_employee_id',
                                            first(v),
                                        )
                                    }
                                />
                            </Field>
                        </div>
                        <fieldset className="mt-4 grid gap-3">
                            <legend className="mb-2 text-sm font-medium">
                                Mode presensi
                            </legend>
                            <ModeOption
                                checked={data.assignment.attendance_mode === ''}
                                onSelect={() =>
                                    setAssignment('attendance_mode', '')
                                }
                                title="Ikut jabatan"
                                help="Memakai mode presensi bawaan jabatan yang dipilih. Pilihan paling umum."
                            />
                            {modes.map((m) => (
                                <ModeOption
                                    key={m.value}
                                    checked={
                                        data.assignment.attendance_mode ===
                                        m.value
                                    }
                                    onSelect={() =>
                                        setAssignment(
                                            'attendance_mode',
                                            m.value,
                                        )
                                    }
                                    title={m.label}
                                    help={m.help}
                                />
                            ))}
                        </fieldset>
                        <InputError
                            message={err['assignment.attendance_mode']}
                        />
                    </SettingsSection>
                )}

                {!employee && canCreateAccount && (
                    <SettingsSection
                        title="Akun Pakis Pro & web"
                        description="Opsional. Akun bisa juga dibuat nanti dari halaman detail pegawai."
                    >
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="account_create"
                                checked={data.account.create}
                                onCheckedChange={(v) =>
                                    setAccount('create', v === true)
                                }
                            />
                            <Label
                                htmlFor="account_create"
                                className="text-sm font-normal"
                            >
                                Buat akun login untuk pegawai ini
                            </Label>
                        </div>

                        {data.account.create && (
                            <div className="mt-4 grid gap-4">
                                <Field
                                    id="account_username"
                                    label="Username"
                                    error={err['account.username']}
                                    help="Disarankan dari nama. Huruf kecil, angka, titik, garis bawah, atau tanda hubung."
                                >
                                    <Input
                                        id="account_username"
                                        value={data.account.username}
                                        maxLength={50}
                                        autoComplete="off"
                                        onChange={(e) => {
                                            setUsernameTouched(true);
                                            setAccount(
                                                'username',
                                                e.target.value.toLowerCase(),
                                            );
                                        }}
                                        aria-invalid={
                                            err['account.username']
                                                ? true
                                                : undefined
                                        }
                                    />
                                </Field>
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Role
                                    </legend>
                                    {assignable.map((r) => (
                                        <div
                                            key={r.name}
                                            className="flex items-start gap-2"
                                        >
                                            <Checkbox
                                                id={`role_${r.name}`}
                                                checked={data.account.roles.includes(
                                                    r.name,
                                                )}
                                                onCheckedChange={(v) =>
                                                    setAccount(
                                                        'roles',
                                                        v === true
                                                            ? [
                                                                  ...data
                                                                      .account
                                                                      .roles,
                                                                  r.name,
                                                              ]
                                                            : data.account.roles.filter(
                                                                  (n) =>
                                                                      n !==
                                                                      r.name,
                                                              ),
                                                    )
                                                }
                                            />
                                            <Label
                                                htmlFor={`role_${r.name}`}
                                                className="grid gap-0.5 text-sm font-normal"
                                            >
                                                <span className="font-medium">
                                                    {r.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    Scope: {r.data_scope_label}
                                                </span>
                                            </Label>
                                        </div>
                                    ))}
                                    <InputError
                                        message={err['account.roles']}
                                    />
                                </fieldset>
                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Password awal
                                    </legend>
                                    <ModeOption
                                        name="password_mode"
                                        checked={
                                            data.account.password_mode ===
                                                'generate' || !hasEmail
                                        }
                                        onSelect={() =>
                                            setAccount(
                                                'password_mode',
                                                'generate',
                                            )
                                        }
                                        title="Buat password sementara"
                                        help="Ditampilkan sekali setelah disimpan. Pegawai wajib menggantinya saat masuk pertama."
                                    />
                                    <ModeOption
                                        name="password_mode"
                                        checked={
                                            data.account.password_mode ===
                                                'email_link' && hasEmail
                                        }
                                        disabled={!hasEmail}
                                        onSelect={() =>
                                            setAccount(
                                                'password_mode',
                                                'email_link',
                                            )
                                        }
                                        title="Kirim tautan atur password"
                                        help={
                                            hasEmail
                                                ? 'Dikirim ke email pegawai.'
                                                : 'Isi email pegawai untuk memakai pilihan ini.'
                                        }
                                    />
                                    <InputError
                                        message={err['account.password_mode']}
                                    />
                                </fieldset>
                            </div>
                        )}
                    </SettingsSection>
                )}

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={employee ? show(employee.id) : index()}>
                            Batal
                        </Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {employee ? 'Simpan Perubahan' : 'Simpan Pegawai'}
                    </Button>
                </div>
            </form>

            <OneTimeSecretDialog
                secret={created?.temporary_password ?? null}
                title={`Akun ${created?.username ?? ''} dibuat`}
                description={`Password sementara untuk ${created?.full_name ?? 'pegawai'} masuk pertama kali. Pegawai akan diminta menggantinya.`}
                doneLabel="Selesai, buka detail pegawai"
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
    optional = false,
    help,
    error,
    className,
    children,
}: {
    id: string;
    label: string;
    optional?: boolean;
    help?: string;
    error?: string;
    className?: string;
    children: React.ReactNode;
}) {
    return (
        <div className={cn('grid content-start gap-2', className)}>
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
            {help && <p className="text-xs text-muted-foreground">{help}</p>}
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
    name = 'attendance_mode',
}: {
    checked: boolean;
    disabled?: boolean;
    onSelect: () => void;
    title: string;
    help: string;
    name?: string;
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
                name={name}
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

EmployeeForm.layout = {
    breadcrumbs: [{ title: 'Pegawai', href: index() }],
};
