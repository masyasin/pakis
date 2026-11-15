import { Head, Link, router, useHttp, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    BriefcaseBusiness,
    CalendarClock,
    CalendarDays,
    Contact,
    Fingerprint,
    History,
    Info,
    KeyRound,
    MapPin,
    Network,
    Smartphone,
    UserPlus,
    UserRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    DetailCard,
    EmptyPanel,
    Field,
    FieldGrid,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import { EmployeeHero } from '@/components/employee/employee-hero';
import { EmployeeTabs, tabLabel } from '@/components/employee/employee-tabs';
import type { EmployeeTab } from '@/components/employee/employee-tabs';
import InputError from '@/components/input-error';
import { MaskedValue } from '@/components/pakis/masked-value';
import { NativeSelect } from '@/components/pakis/native-select';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';
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
import {
    ageOn,
    formatDate,
    formatPhone,
    formatTenure,
    todayIn,
} from '@/lib/employee-format';
import { formatRelativeTime } from '@/lib/relative-time';
import {
    account as accountRoute,
    destroy,
    edit,
    index,
    revealNik,
    show,
} from '@/routes/admin/employees';

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
    tab: EmployeeTab;
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

/** Detail pegawai (07 §4 "Pegawai (detail)", FR-025). Data di luar scope tidak pernah sampai sini (404). */
export default function EmployeeShow(props: Props) {
    const { employee, primary, can, tab, account } = props;
    const { timezone } = usePage<{ timezone: string }>().props;
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    // Masa kerja dihitung sampai tanggal keluar (pegawai nonaktif) atau hari ini di zona kantor.
    const today = todayIn(timezone);
    const tenure = formatTenure(
        employee.join_date,
        employee.exit_date ?? today,
    );
    const hrefFor = (key: EmployeeTab) =>
        show(employee.id, { query: { tab: key } }).url;

    return (
        <>
            <Head title={employee.full_name} />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Button variant="ghost" size="sm" className="-ml-2" asChild>
                    <Link href={index()}>
                        <ArrowLeft aria-hidden="true" />
                        Daftar pegawai
                    </Link>
                </Button>

                <EmployeeHero
                    name={employee.full_name}
                    number={employee.employee_number}
                    status={employee.status}
                    photoUrl={employee.photo_url}
                    position={primary?.position ?? null}
                    location={primary?.location ?? null}
                    department={primary?.department ?? null}
                    joinDate={employee.join_date}
                    editHref={can.update ? edit(employee.id) : null}
                    onDelete={can.delete ? () => setConfirmDelete(true) : null}
                    summary={[
                        { label: 'Masa kerja', value: tenure },
                        {
                            label: 'Status kerja',
                            value: employee.employment_status ?? '–',
                        },
                        {
                            label: 'Atasan langsung',
                            value: primary?.supervisor?.name ?? 'Tidak ada',
                        },
                        {
                            label: 'Akun login',
                            value: account ? account.username : 'Belum ada',
                        },
                    ]}
                />

                {props.deleteBlockers.length > 0 && (
                    <p className="flex items-start gap-2.5 rounded-xl bg-status-info-bg px-4 py-3 text-sm text-status-info">
                        <Info
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            Pegawai ini tidak bisa dihapus karena{' '}
                            {props.deleteBlockers.join(', ')}. Bila sudah
                            keluar, gunakan Nonaktifkan (menyusul).
                        </span>
                    </p>
                )}

                <EmployeeTabs active={tab} hrefFor={hrefFor} />

                <section aria-label={tabLabel(tab)}>
                    {tab === 'profil' && (
                        <ProfileTab
                            employee={employee}
                            canReveal={can.revealNik}
                            tenure={tenure}
                            age={ageOn(employee.birth_date, today)}
                        />
                    )}
                    {tab === 'alamat' && <AddressTab employee={employee} />}
                    {tab === 'penugasan' && (
                        <AssignmentsTab rows={props.assignments} />
                    )}
                    {tab === 'jadwal' && (
                        <EmptyPanel
                            icon={CalendarDays}
                            title="Jadwal kerja belum tersedia"
                            text="Pola shift dan jadwal pegawai akan tampil di sini setelah modul Jadwal aktif."
                        />
                    )}
                    {tab === 'presensi' && (
                        <EmptyPanel
                            icon={CalendarClock}
                            title="Presensi belum tersedia"
                            text="Riwayat absen 30 hari terakhir akan tampil di sini setelah modul Absensi aktif."
                        />
                    )}
                    {tab === 'akun' && (
                        <AccountTab
                            employee={employee}
                            account={account}
                            canCreate={can.createAccount}
                            suggestedUsername={props.suggestedUsername}
                            roleOptions={props.roleOptions}
                        />
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

function ProfileTab({
    employee,
    canReveal,
    tenure,
    age,
}: {
    employee: EmployeeDetail;
    canReveal: boolean;
    tenure: string;
    age: number | null;
}) {
    return (
        <div className="grid gap-4 lg:grid-cols-2">
            <DetailCard
                title="Identitas"
                icon={Fingerprint}
                className="lg:row-span-2"
            >
                <FieldGrid>
                    <Field
                        label="Nama lengkap"
                        value={employee.full_name}
                        wide
                    />
                    <Field
                        label="No. pegawai"
                        value={employee.employee_number}
                        mono
                    />
                    <Field
                        label="NIK"
                        value={
                            employee.nik_masked ? (
                                <MaskedValue
                                    masked={employee.nik_masked}
                                    reveal={
                                        canReveal
                                            ? revealNik(employee.id)
                                            : null
                                    }
                                />
                            ) : null
                        }
                    />
                    <Field
                        label="Jenis kelamin"
                        value={employee.gender_label}
                    />
                    <Field
                        label="Tanggal lahir"
                        value={
                            employee.birth_date
                                ? formatDate(employee.birth_date)
                                : null
                        }
                        hint={age !== null ? `· ${age} tahun` : undefined}
                    />
                </FieldGrid>
            </DetailCard>

            <DetailCard title="Kontak" icon={Contact}>
                <FieldGrid>
                    <Field
                        label="HP"
                        value={
                            employee.phone ? (
                                <a
                                    href={`tel:+${employee.phone}`}
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    {formatPhone(employee.phone)}
                                </a>
                            ) : null
                        }
                    />
                    <Field
                        label="Email"
                        value={
                            employee.email ? (
                                <a
                                    href={`mailto:${employee.email}`}
                                    className="font-medium text-primary underline-offset-4 hover:underline"
                                >
                                    {employee.email}
                                </a>
                            ) : null
                        }
                    />
                </FieldGrid>
            </DetailCard>

            <DetailCard title="Kepegawaian" icon={BriefcaseBusiness}>
                <FieldGrid>
                    <Field
                        label="Status kerja"
                        value={employee.employment_status}
                    />
                    <Field
                        label="Tanggal masuk"
                        value={formatDate(employee.join_date)}
                        hint={`· ${tenure}`}
                    />
                    {employee.exit_date && (
                        <Field
                            label="Tanggal keluar"
                            value={formatDate(employee.exit_date)}
                        />
                    )}
                </FieldGrid>
            </DetailCard>
        </div>
    );
}

function AddressTab({ employee }: { employee: EmployeeDetail }) {
    return (
        <DetailCard title="Alamat tinggal" icon={MapPin}>
            <FieldGrid>
                <Field label="Alamat jalan" value={employee.address} wide />
                <Field
                    label="Desa, kecamatan, kab/kota"
                    value={employee.village_label}
                    wide
                />
            </FieldGrid>
        </DetailCard>
    );
}

function AssignmentsTab({ rows }: { rows: AssignmentRow[] }) {
    if (rows.length === 0) {
        return (
            <EmptyPanel
                icon={Network}
                title="Belum ada penugasan"
                text="Penugasan menentukan jabatan, lokasi tugas, dan atasan pegawai."
            />
        );
    }

    return (
        <DetailCard title="Riwayat penugasan" icon={Network}>
            <Timeline>
                {rows.map((a, i) => (
                    <TimelineItem
                        key={a.id}
                        active={a.end_date === null}
                        last={i === rows.length - 1}
                    >
                        <div className="flex flex-wrap items-center gap-2">
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
                        </div>
                        <dl className="mt-2 grid gap-x-6 gap-y-1 text-muted-foreground sm:grid-cols-2 lg:grid-cols-4">
                            <TimelineFact label="Lokasi" value={a.location} />
                            <TimelineFact
                                label="Atasan"
                                value={a.supervisor?.name ?? 'Tidak ada'}
                            />
                            <TimelineFact
                                label="Mode absen"
                                value={
                                    a.effective_mode_label
                                        ? `${a.effective_mode_label}${a.mode_from_position ? ' (ikut jabatan)' : ''}`
                                        : null
                                }
                            />
                            <TimelineFact
                                label="Periode"
                                value={`${formatDate(a.start_date)} – ${a.end_date ? formatDate(a.end_date) : 'sekarang'}`}
                            />
                        </dl>
                    </TimelineItem>
                ))}
            </Timeline>
        </DetailCard>
    );
}

function TimelineFact({
    label,
    value,
}: {
    label: string;
    value: string | null;
}) {
    return (
        <div className="min-w-0">
            <dt className="text-xs">{label}</dt>
            <dd className="truncate text-foreground/85">{value ?? '–'}</dd>
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
        <div className="grid gap-4 lg:grid-cols-2">
            {account ? (
                <DetailCard
                    title="Akun login"
                    icon={KeyRound}
                    action={
                        account.show_url ? (
                            <Button variant="ghost" size="sm" asChild>
                                <Link href={account.show_url}>Kelola akun</Link>
                            </Button>
                        ) : undefined
                    }
                >
                    <FieldGrid>
                        <Field label="Username" value={account.username} mono />
                        <Field
                            label="Status akun"
                            value={
                                <StatusBadge
                                    status={
                                        account.is_active
                                            ? 'user.active'
                                            : 'user.inactive'
                                    }
                                />
                            }
                        />
                        <Field
                            label="Role"
                            value={
                                account.roles.length > 0 ? (
                                    <span className="flex flex-wrap gap-1.5">
                                        {account.roles.map((r) => (
                                            <Badge key={r} variant="secondary">
                                                {r}
                                            </Badge>
                                        ))}
                                    </span>
                                ) : null
                            }
                        />
                        <Field
                            label="Login terakhir"
                            value={
                                account.last_login_at
                                    ? formatRelativeTime(account.last_login_at)
                                    : 'Belum pernah'
                            }
                        />
                    </FieldGrid>
                </DetailCard>
            ) : (
                <EmptyPanel
                    icon={UserRound}
                    title="Belum punya akun login"
                    text="Akun dipakai untuk absen lewat Pakis Pro dan, sesuai role, masuk ke web admin."
                    action={
                        canCreate ? (
                            <Button size="sm" onClick={() => setOpen(true)}>
                                <UserPlus aria-hidden="true" />
                                Buat Akun
                            </Button>
                        ) : undefined
                    }
                />
            )}

            <EmptyPanel
                icon={Smartphone}
                title="Perangkat Pakis Pro"
                text="HP yang terdaftar untuk absen akan tampil di sini setelah modul Perangkat aktif."
            />

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
        <DetailCard
            title="Riwayat perubahan"
            icon={History}
            action={
                <div className="flex items-center gap-2">
                    <Label htmlFor="history-event" className="sr-only">
                        Saring event
                    </Label>
                    <NativeSelect
                        id="history-event"
                        className="h-8 w-auto min-w-40 text-xs"
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
                </div>
            }
        >
            <p
                className="mb-3 text-xs text-muted-foreground"
                aria-live="polite"
            >
                {history.pagination.total.toLocaleString('id-ID')} catatan
            </p>

            {history.rows.length === 0 ? (
                <EmptyPanel
                    icon={History}
                    title="Belum ada riwayat"
                    text="Tidak ada perubahan yang cocok dengan filter ini."
                />
            ) : (
                <Timeline>
                    {history.rows.map((h, i) => (
                        <TimelineItem
                            key={h.id}
                            last={i === history.rows.length - 1}
                        >
                            <p className="flex flex-wrap items-center gap-2">
                                <Badge variant="secondary">
                                    {h.event_label}
                                </Badge>
                                <span className="text-muted-foreground">
                                    oleh{' '}
                                    <span className="font-medium text-foreground">
                                        {h.actor}
                                    </span>
                                    {h.occurred_at &&
                                        ` · ${formatRelativeTime(h.occurred_at)}`}
                                </span>
                            </p>
                            {h.new_values && (
                                <p className="mt-1.5 text-xs break-words text-muted-foreground">
                                    {describeChanges(h.new_values)}
                                </p>
                            )}
                        </TimelineItem>
                    ))}
                </Timeline>
            )}

            {history.pagination.last_page > 1 && (
                <nav
                    className="mt-2 flex items-center justify-between gap-2"
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
        </DetailCard>
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

EmployeeShow.layout = {
    breadcrumbs: [{ title: 'Pegawai', href: index() }],
};
