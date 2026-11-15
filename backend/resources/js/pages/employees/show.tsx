import { Head, Link, router, useHttp } from '@inertiajs/react';
import { ArrowLeft, Pencil, Trash2, UserPlus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { MaskedValue } from '@/components/pakis/masked-value';
import { NativeSelect } from '@/components/pakis/native-select';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';
import { PageTabs } from '@/components/pakis/page-tabs';
import type { PageTab } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate, formatPhone } from '@/lib/employee-format';
import { formatRelativeTime } from '@/lib/relative-time';
import { cn } from '@/lib/utils';
import {
    account as accountRoute,
    destroy,
    edit,
    index,
    revealNik,
    show,
} from '@/routes/admin/employees';

type Tab =
    | 'profil'
    | 'alamat'
    | 'penugasan'
    | 'jadwal'
    | 'presensi'
    | 'akun'
    | 'riwayat';

type EmployeeDetail = {
    id: number;
    employee_number: string;
    full_name: string;
    gender_label: string;
    nik_masked: string | null;
    birth_date: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    village_label: string | null;
    photo_url: string | null;
    employment_status: string | null;
    join_date: string;
    exit_date: string | null;
    status: 'active' | 'inactive';
};

type AssignmentRow = {
    id: number;
    position: string | null;
    department: string | null;
    location: string | null;
    supervisor: { id: number; name: string } | null;
    effective_mode_label: string | null;
    mode_from_position: boolean;
    is_primary: boolean;
    start_date: string;
    end_date: string | null;
};

type AccountInfo = {
    id: number;
    username: string;
    is_active: boolean;
    last_login_at: string | null;
    roles: string[];
    show_url: string | null;
};

type RoleOption = {
    name: string;
    label: string;
    data_scope_label: string;
    assignable: boolean;
};

type HistoryRow = {
    id: number;
    occurred_at: string | null;
    actor: string;
    event: string;
    event_label: string;
    new_values: Record<string, unknown> | null;
};

type HistoryPage = {
    event: string | null;
    rows: HistoryRow[];
    pagination: {
        page: number;
        last_page: number;
        total: number;
        prev_url: string | null;
        next_url: string | null;
    };
};

type Props = {
    tab: Tab;
    employee: EmployeeDetail;
    primary: AssignmentRow | null;
    assignments: AssignmentRow[];
    account: AccountInfo | null;
    suggestedUsername: string | null;
    roleOptions: RoleOption[];
    can: {
        update: boolean;
        delete: boolean;
        revealNik: boolean;
        createAccount: boolean;
    };
    deleteBlockers: string[];
    history: HistoryPage | null;
    historyEvents: { value: string; label: string }[];
};

/**
 * Tab detail pegawai dikelompokkan: grup di baris pertama, sub-tab di baris kedua (dropdown di HP).
 * Modul riwayat kepegawaian berikutnya (status kerja, pangkat, pendidikan, diklat, …) cukup menambah
 * sub-tab di grupnya; grup tanpa isi tidak ditampilkan.
 */
const GROUPS: {
    key: string;
    label: string;
    tabs: { key: Tab; label: string }[];
}[] = [
    {
        key: 'data-diri',
        label: 'Data Diri',
        tabs: [
            { key: 'profil', label: 'Profil' },
            { key: 'alamat', label: 'Alamat' },
        ],
    },
    {
        key: 'kepegawaian',
        label: 'Kepegawaian',
        tabs: [{ key: 'penugasan', label: 'Penugasan & Jabatan' }],
    },
    {
        key: 'kehadiran',
        label: 'Kehadiran',
        tabs: [
            { key: 'jadwal', label: 'Jadwal' },
            { key: 'presensi', label: 'Presensi' },
        ],
    },
    {
        key: 'akun',
        label: 'Akun & Perangkat',
        tabs: [{ key: 'akun', label: 'Akun & Perangkat' }],
    },
    {
        key: 'riwayat',
        label: 'Riwayat Perubahan',
        tabs: [{ key: 'riwayat', label: 'Riwayat Perubahan' }],
    },
];

const Dash = () => <span className="text-muted-foreground">–</span>;

/** Detail pegawai (07 §4 "Pegawai (detail)", FR-025). Data di luar scope tidak pernah sampai sini (404). */
export default function EmployeeShow(props: Props) {
    const { employee, primary, can, tab } = props;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const group =
        GROUPS.find((g) => g.tabs.some((t) => t.key === tab)) ?? GROUPS[0];
    const current = group.tabs.find((t) => t.key === tab) ?? group.tabs[0];
    const groupTabs: PageTab[] = GROUPS.map((g) => ({
        key: g.key,
        label: g.label,
        href: show(employee.id, { query: { tab: g.tabs[0].key } }),
    }));

    const initials = employee.full_name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

    return (
        <>
            <Head title={employee.full_name} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:px-7 md:py-6">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={index()}>
                        <ArrowLeft aria-hidden="true" />
                        Daftar pegawai
                    </Link>
                </Button>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 items-center gap-4">
                        {employee.photo_url ? (
                            <img
                                src={employee.photo_url}
                                alt={`Foto ${employee.full_name}`}
                                className="size-16 shrink-0 rounded-full object-cover"
                            />
                        ) : (
                            <span
                                aria-hidden="true"
                                className="grid size-16 shrink-0 place-items-center rounded-full bg-sidebar-accent text-lg font-bold text-primary"
                            >
                                {initials}
                            </span>
                        )}
                        <div className="min-w-0 space-y-1">
                            <h1 className="text-[1.4rem] leading-tight font-bold break-words">
                                {employee.full_name}
                            </h1>
                            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span className="font-mono">
                                    {employee.employee_number}
                                </span>
                                <StatusBadge
                                    status={`employee.${employee.status}`}
                                />
                            </div>
                            <p className="text-sm">
                                {primary ? (
                                    <>
                                        {primary.position ?? '–'}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {primary.location ?? '–'}
                                        </span>
                                    </>
                                ) : (
                                    <span className="text-muted-foreground">
                                        Belum ada penugasan aktif hari ini
                                    </span>
                                )}
                            </p>
                        </div>
                    </div>

                    {(can.update || can.delete) && (
                        <div className="flex flex-wrap gap-2">
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(employee.id)}>
                                        <Pencil aria-hidden="true" />
                                        Ubah
                                    </Link>
                                </Button>
                            )}
                            {can.delete && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setConfirmDelete(true)}
                                >
                                    <Trash2 aria-hidden="true" />
                                    Hapus
                                </Button>
                            )}
                        </div>
                    )}
                </header>

                {props.deleteBlockers.length > 0 && (
                    <p className="rounded-lg bg-status-info-bg px-3.5 py-3 text-sm text-status-info">
                        Pegawai ini tidak bisa dihapus karena{' '}
                        {props.deleteBlockers.join(', ')}. Bila sudah keluar,
                        gunakan Nonaktifkan (menyusul).
                    </p>
                )}

                <PageTabs
                    tabs={groupTabs}
                    active={group.key}
                    label="Kelompok data pegawai"
                />

                {group.tabs.length > 1 && (
                    <SubTabs
                        employeeId={employee.id}
                        tabs={group.tabs}
                        active={current.key}
                        label={group.label}
                    />
                )}

                <section aria-label={current.label}>
                    {tab === 'profil' && (
                        <DataTab
                            employee={employee}
                            canReveal={can.revealNik}
                        />
                    )}
                    {tab === 'alamat' && <AddressTab employee={employee} />}
                    {tab === 'penugasan' && (
                        <AssignmentsTab rows={props.assignments} />
                    )}
                    {tab === 'akun' && (
                        <AccountTab
                            employee={employee}
                            account={props.account}
                            canCreate={can.createAccount}
                            suggestedUsername={props.suggestedUsername}
                            roleOptions={props.roleOptions}
                        />
                    )}
                    {tab === 'jadwal' && (
                        <Empty text="Jadwal kerja pegawai akan tampil di sini setelah modul Jadwal aktif." />
                    )}
                    {tab === 'presensi' && (
                        <Empty text="Absensi 30 hari terakhir akan tampil di sini setelah modul Absensi aktif." />
                    )}
                    {tab === 'riwayat' && props.history && (
                        <HistoryTab
                            employeeId={employee.id}
                            history={props.history}
                            events={props.historyEvents}
                        />
                    )}
                </section>
            </div>

            <Dialog open={confirmDelete} onOpenChange={setConfirmDelete}>
                <DialogContent>
                    <DialogTitle>Hapus {employee.full_name}?</DialogTitle>
                    <DialogDescription>
                        Hapus hanya untuk data yang salah input. Akun yang belum
                        pernah dipakai ikut dihapus. Tercatat di audit.
                    </DialogDescription>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={deleting}
                            onClick={() =>
                                router.delete(destroy(employee.id).url, {
                                    onStart: () => setDeleting(true),
                                    onError: (errors) =>
                                        toast.error(
                                            errors.employee ??
                                                'Pegawai tidak bisa dihapus.',
                                        ),
                                    onFinish: () => {
                                        setDeleting(false);
                                        setConfirmDelete(false);
                                    },
                                })
                            }
                        >
                            {deleting && <Spinner />}
                            Hapus Pegawai
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function Item({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-0.5 sm:grid-cols-[12rem_1fr] sm:gap-4">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="min-w-0 text-sm break-words">{children}</dd>
        </div>
    );
}

function DataTab({
    employee,
    canReveal,
}: {
    employee: EmployeeDetail;
    canReveal: boolean;
}) {
    return (
        <dl className="grid gap-3 rounded-xl border bg-card p-4 sm:p-5">
            <Item label="Nama lengkap">{employee.full_name}</Item>
            <Item label="No. pegawai">
                <span className="font-mono">{employee.employee_number}</span>
            </Item>
            <Item label="NIK">
                <MaskedValue
                    masked={employee.nik_masked}
                    reveal={canReveal ? revealNik(employee.id) : null}
                />
            </Item>
            <Item label="Jenis kelamin">{employee.gender_label}</Item>
            <Item label="Tanggal lahir">
                {employee.birth_date ? (
                    formatDate(employee.birth_date)
                ) : (
                    <Dash />
                )}
            </Item>
            <Item label="HP">
                {employee.phone ? formatPhone(employee.phone) : <Dash />}
            </Item>
            <Item label="Email">{employee.email ?? <Dash />}</Item>
            <Item label="Status kerja">
                {employee.employment_status ?? <Dash />}
            </Item>
            <Item label="Tanggal masuk">{formatDate(employee.join_date)}</Item>
            {employee.exit_date && (
                <Item label="Tanggal keluar">
                    {formatDate(employee.exit_date)}
                </Item>
            )}
        </dl>
    );
}

function AddressTab({ employee }: { employee: EmployeeDetail }) {
    return (
        <dl className="grid gap-3 rounded-xl border bg-card p-4 sm:p-5">
            <Item label="Alamat jalan">{employee.address ?? <Dash />}</Item>
            <Item label="Desa, kecamatan, kab/kota">
                {employee.village_label ?? <Dash />}
            </Item>
        </dl>
    );
}

/**
 * Sub-tab dalam satu grup: deretan tautan di layar lebar, dropdown di HP (hemat ruang, picker
 * bawaan OS). Keduanya mengubah `?tab=` sehingga posisi bisa dibagikan lewat URL.
 */
function SubTabs({
    employeeId,
    tabs,
    active,
    label,
}: {
    employeeId: number;
    tabs: { key: Tab; label: string }[];
    active: Tab;
    label: string;
}) {
    const href = (key: Tab) => show(employeeId, { query: { tab: key } }).url;

    return (
        <>
            <nav
                aria-label={`Bagian ${label}`}
                className="hidden gap-1 border-b sm:flex"
            >
                {tabs.map((t) => (
                    <Link
                        key={t.key}
                        href={href(t.key)}
                        preserveScroll
                        aria-current={t.key === active ? 'page' : undefined}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-2 text-sm transition-colors',
                            t.key === active
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {t.label}
                    </Link>
                ))}
            </nav>
            <div className="sm:hidden">
                <Label htmlFor="employee-subtab" className="sr-only">
                    Bagian {label}
                </Label>
                <NativeSelect
                    id="employee-subtab"
                    value={active}
                    onChange={(e) =>
                        router.visit(href(e.target.value as Tab), {
                            preserveScroll: true,
                        })
                    }
                >
                    {tabs.map((t) => (
                        <option key={t.key} value={t.key}>
                            {t.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>
        </>
    );
}

function AssignmentsTab({ rows }: { rows: AssignmentRow[] }) {
    if (rows.length === 0) {
        return <Empty text="Belum ada penugasan." />;
    }

    return (
        <div className="space-y-3">
            <p className="text-sm text-muted-foreground">
                Mutasi dan pengakhiran penugasan menyusul (modul Penugasan).
            </p>
            <ol className="divide-y rounded-xl border bg-card">
                {rows.map((a) => (
                    <li key={a.id} className="grid gap-1.5 p-4 text-sm">
                        <p className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold">
                                {a.position ?? '–'}
                            </span>
                            {a.department && (
                                <span className="text-muted-foreground">
                                    · {a.department}
                                </span>
                            )}
                            {a.is_primary ? (
                                <Badge variant="secondary">Utama</Badge>
                            ) : (
                                <Badge variant="outline">Tambahan</Badge>
                            )}
                            {a.end_date === null && (
                                <StatusBadge
                                    status="assignment.active"
                                    label="Berjalan"
                                    tone="success"
                                />
                            )}
                        </p>
                        <p className="text-muted-foreground">
                            {a.location ?? '–'} · atasan{' '}
                            {a.supervisor?.name ?? 'tidak ada'}
                        </p>
                        <p className="text-muted-foreground">
                            Mode absen: {a.effective_mode_label ?? '–'}
                            {a.mode_from_position && ' (ikut jabatan)'} ·{' '}
                            {formatDate(a.start_date)} –{' '}
                            {a.end_date ? formatDate(a.end_date) : 'sekarang'}
                        </p>
                    </li>
                ))}
            </ol>
        </div>
    );
}

function AccountTab({
    employee,
    account,
    canCreate,
    suggestedUsername,
    roleOptions,
}: {
    employee: EmployeeDetail;
    account: AccountInfo | null;
    canCreate: boolean;
    suggestedUsername: string | null;
    roleOptions: RoleOption[];
}) {
    const [open, setOpen] = useState(false);
    const [secret, setSecret] = useState<string | null>(null);

    return (
        <div className="space-y-4">
            {account ? (
                <dl className="grid gap-3 rounded-xl border bg-card p-4 sm:p-5">
                    <Item label="Username">
                        {account.show_url ? (
                            <Link
                                href={account.show_url}
                                className="font-mono font-medium text-primary underline-offset-4 hover:underline"
                            >
                                {account.username}
                            </Link>
                        ) : (
                            <span className="font-mono">
                                {account.username}
                            </span>
                        )}
                    </Item>
                    <Item label="Status akun">
                        <StatusBadge
                            status={
                                account.is_active
                                    ? 'user.active'
                                    : 'user.inactive'
                            }
                        />
                    </Item>
                    <Item label="Role">
                        {account.roles.length > 0 ? (
                            account.roles.join(', ')
                        ) : (
                            <Dash />
                        )}
                    </Item>
                    <Item label="Login terakhir">
                        {account.last_login_at ? (
                            formatRelativeTime(account.last_login_at)
                        ) : (
                            <span className="text-muted-foreground">
                                Belum pernah
                            </span>
                        )}
                    </Item>
                </dl>
            ) : (
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed p-5 text-sm">
                    <span className="text-muted-foreground">
                        Pegawai ini belum punya akun login.
                    </span>
                    {canCreate && (
                        <Button size="sm" onClick={() => setOpen(true)}>
                            <UserPlus aria-hidden="true" />
                            Buat Akun
                        </Button>
                    )}
                </div>
            )}

            <Empty text="Perangkat Pakis Pro akan tampil di sini setelah modul Perangkat aktif." />

            {canCreate && (
                <CreateAccountDialog
                    open={open}
                    employee={employee}
                    suggestedUsername={suggestedUsername ?? ''}
                    roleOptions={roleOptions}
                    onClose={() => setOpen(false)}
                    onCreated={(password) => {
                        setOpen(false);
                        if (password) {
                            setSecret(password);
                        } else {
                            toast.success(
                                'Akun dibuat. Tautan atur password dikirim ke email pegawai.',
                            );
                            router.reload();
                        }
                    }}
                />
            )}

            <OneTimeSecretDialog
                secret={secret}
                title="Akun dibuat"
                description={`Password sementara untuk ${employee.full_name} masuk pertama kali. Pegawai akan diminta menggantinya.`}
                onDone={() => {
                    setSecret(null);
                    router.reload();
                }}
            />
        </div>
    );
}

function CreateAccountDialog({
    open,
    employee,
    suggestedUsername,
    roleOptions,
    onClose,
    onCreated,
}: {
    open: boolean;
    employee: EmployeeDetail;
    suggestedUsername: string;
    roleOptions: RoleOption[];
    onClose: () => void;
    onCreated: (temporaryPassword: string | null) => void;
}) {
    const assignable = roleOptions.filter((r) => r.assignable);
    const http = useHttp<
        {
            username: string;
            roles: string[];
            password_mode: 'generate' | 'email_link';
        },
        { temporary_password: string | null }
    >({
        username: suggestedUsername,
        roles: assignable.some((r) => r.name === 'pegawai') ? ['pegawai'] : [],
        password_mode: 'generate',
    });
    const hasEmail = employee.email !== null;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        http.submit(accountRoute(employee.id), {
            onSuccess: (res) => {
                http.reset();
                onCreated(res.temporary_password);
            },
            onHttpException: () => {
                toast.error(
                    'Akun belum dibuat. Muat ulang halaman lalu coba lagi.',
                );
            },
        }).catch(() => {
            // Ditangani callback di atas.
        });
    };

    return (
        <Dialog open={open} onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogTitle>
                        Buat akun untuk {employee.full_name}
                    </DialogTitle>
                    <DialogDescription>
                        Nama, HP, dan email akun diambil dari data pegawai.
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor="new_username">Username</Label>
                        <Input
                            id="new_username"
                            value={http.data.username}
                            maxLength={50}
                            autoComplete="off"
                            onChange={(e) =>
                                http.setData(
                                    'username',
                                    e.target.value.toLowerCase(),
                                )
                            }
                            aria-invalid={
                                http.errors.username ? true : undefined
                            }
                        />
                        <InputError message={http.errors.username} />
                    </div>
                    <fieldset className="grid gap-2">
                        <legend className="mb-1 text-sm font-medium">
                            Role
                        </legend>
                        {assignable.map((r) => (
                            <div
                                key={r.name}
                                className="flex items-center gap-2"
                            >
                                <Checkbox
                                    id={`acc_role_${r.name}`}
                                    checked={http.data.roles.includes(r.name)}
                                    onCheckedChange={(v) =>
                                        http.setData(
                                            'roles',
                                            v === true
                                                ? [...http.data.roles, r.name]
                                                : http.data.roles.filter(
                                                      (n) => n !== r.name,
                                                  ),
                                        )
                                    }
                                />
                                <Label
                                    htmlFor={`acc_role_${r.name}`}
                                    className="text-sm font-normal"
                                >
                                    {r.label}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {r.data_scope_label}
                                    </span>
                                </Label>
                            </div>
                        ))}
                        <InputError message={http.errors.roles} />
                    </fieldset>
                    <div className="grid gap-2">
                        <Label htmlFor="new_password_mode">Password awal</Label>
                        <NativeSelect
                            id="new_password_mode"
                            value={
                                hasEmail ? http.data.password_mode : 'generate'
                            }
                            onChange={(e) =>
                                http.setData(
                                    'password_mode',
                                    e.target.value as 'generate' | 'email_link',
                                )
                            }
                        >
                            <option value="generate">
                                Buat password sementara (tampil sekali)
                            </option>
                            <option value="email_link" disabled={!hasEmail}>
                                Kirim tautan atur password ke email
                            </option>
                        </NativeSelect>
                        <InputError message={http.errors.password_mode} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={http.processing}>
                            {http.processing && <Spinner />}
                            Buat Akun
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** Nama kolom audit pegawai → label layar (09 §7.1 `employee.*`, `assignment.*`). */
const FIELD_LABELS: Record<string, string> = {
    full_name: 'Nama',
    employee_number: 'No. pegawai',
    gender: 'Jenis kelamin',
    birth_date: 'Tanggal lahir',
    phone: 'HP',
    email: 'Email',
    address: 'Alamat',
    village_code: 'Desa',
    employment_status_id: 'Status kerja',
    join_date: 'Tanggal masuk',
    nik: 'NIK',
    photo: 'Foto',
    position_id: 'Jabatan',
    location_id: 'Lokasi',
    supervisor_employee_id: 'Atasan',
    attendance_mode: 'Mode absen',
    start_date: 'Mulai',
    end_date: 'Selesai',
};

/** Kunci teknis yang tidak berguna dibaca di riwayat. */
const HIDDEN_KEYS = new Set(['employee_id', 'is_primary', 'note']);

function formatValue(v: unknown): string {
    if (v === null || v === undefined || v === '') {
        return '–';
    }
    if (typeof v === 'boolean') {
        return v ? 'Ya' : 'Tidak';
    }
    if (typeof v === 'string' || typeof v === 'number') {
        return String(v);
    }

    return JSON.stringify(v);
}

function describeChanges(values: Record<string, unknown>): string {
    return Object.entries(values)
        .filter(([k]) => !HIDDEN_KEYS.has(k))
        .map(([k, v]) =>
            k === 'photo'
                ? `${FIELD_LABELS[k]}: ${v ? 'diganti' : 'dihapus'}`
                : `${FIELD_LABELS[k] ?? k}: ${formatValue(v)}`,
        )
        .join(' · ');
}

function HistoryTab({
    employeeId,
    history,
    events,
}: {
    employeeId: number;
    history: HistoryPage;
    events: { value: string; label: string }[];
}) {
    const filter = (event: string) =>
        router.get(
            show(employeeId, {
                query: { tab: 'riwayat', ...(event ? { event } : {}) },
            }).url,
            {},
            { preserveScroll: true, preserveState: true },
        );

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
                <Label htmlFor="history-event" className="text-sm">
                    Event
                </Label>
                <NativeSelect
                    id="history-event"
                    className="w-auto min-w-48"
                    value={history.event ?? ''}
                    onChange={(e) => filter(e.target.value)}
                >
                    <option value="">Semua event</option>
                    {events.map((e) => (
                        <option key={e.value} value={e.value}>
                            {e.label}
                        </option>
                    ))}
                </NativeSelect>
                <span
                    className="text-sm text-muted-foreground"
                    aria-live="polite"
                >
                    {history.pagination.total.toLocaleString('id-ID')} catatan
                </span>
            </div>

            {history.rows.length === 0 ? (
                <Empty text="Belum ada riwayat untuk filter ini." />
            ) : (
                <ol className="divide-y rounded-xl border bg-card">
                    {history.rows.map((h) => (
                        <li key={h.id} className="grid gap-1 p-4 text-sm">
                            <p className="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {h.event_label}
                                </Badge>
                                <span className="text-muted-foreground">
                                    oleh {h.actor}
                                    {h.occurred_at &&
                                        ` · ${formatRelativeTime(h.occurred_at)}`}
                                </span>
                            </p>
                            {h.new_values && (
                                <p className="text-xs break-words text-muted-foreground">
                                    {describeChanges(h.new_values)}
                                </p>
                            )}
                        </li>
                    ))}
                </ol>
            )}

            {history.pagination.last_page > 1 && (
                <nav
                    className="flex items-center justify-between gap-2"
                    aria-label="Halaman riwayat"
                >
                    <PageLink url={history.pagination.prev_url}>
                        Sebelumnya
                    </PageLink>
                    <span className="text-sm text-muted-foreground">
                        Halaman {history.pagination.page} dari{' '}
                        {history.pagination.last_page}
                    </span>
                    <PageLink url={history.pagination.next_url}>
                        Berikutnya
                    </PageLink>
                </nav>
            )}
        </div>
    );
}

function PageLink({
    url,
    children,
}: {
    url: string | null;
    children: React.ReactNode;
}) {
    return (
        <Button variant="outline" size="sm" disabled={!url} asChild={!!url}>
            {url ? (
                <Link href={url} preserveScroll>
                    {children}
                </Link>
            ) : (
                <span>{children}</span>
            )}
        </Button>
    );
}

function Empty({ text }: { text: string }) {
    return (
        <p
            className={cn(
                'rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground',
            )}
        >
            {text}
        </p>
    );
}

EmployeeShow.layout = {
    breadcrumbs: [{ title: 'Pegawai', href: index() }],
};
