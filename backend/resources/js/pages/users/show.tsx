import { Head, Link, router, useHttp } from '@inertiajs/react';
import {
    ArrowLeft,
    History,
    KeyRound,
    Lock,
    LogOut,
    Monitor,
    Pencil,
    Power,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    UserRound,
    UsersRound,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/pakis/native-select';
import { DeviceSummaryList } from '@/components/pakis/device-summary-list';
import type { DeviceSummary } from '@/components/pakis/device-summary-list';
import { OneTimeSecretDialog } from '@/components/pakis/one-time-secret-dialog';
import { PageTabs } from '@/components/pakis/page-tabs';
import type { PageTab } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import PasswordInput from '@/components/password-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatRelativeTime } from '@/lib/relative-time';
import {
    edit,
    index,
    resetMfa,
    resetPassword,
    revokeSessions,
    show,
    toggle,
} from '@/routes/admin/users';

type Tab = 'profil' | 'role' | 'sesi' | 'perangkat' | 'riwayat';

type UserDetail = {
    id: number;
    name: string;
    username: string;
    email: string | null;
    email_verified: boolean;
    phone: string | null;
    employee_id: number | null;
    employee: {
        id: number;
        name: string;
        number: string;
        url: string | null;
    } | null;
    is_active: boolean;
    is_locked: boolean;
    must_change_password: boolean;
    mfa_enabled: boolean;
    mfa_required: boolean;
    last_login_at: string | null;
    last_login_ip: string | null;
    created_at: string | null;
    roles: {
        name: string;
        label: string;
        data_scope_label: string | null;
        mfa_required: boolean;
    }[];
};

type WebSession = {
    key: string;
    browser: string;
    platform: string;
    ip: string | null;
    last_active_at: string;
};

type HistoryRow = {
    id: number;
    occurred_at: string | null;
    actor: string;
    event: string;
    event_label: string;
    old_values: Record<string, unknown> | null;
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
    user: UserDetail;
    tab: Tab;
    can: {
        update: boolean;
        toggle: boolean;
        revokeSessions: boolean;
        resetPassword: boolean;
        resetMfa: boolean;
    };
    protectedReason: 'self' | 'owner' | 'beyond' | null;
    sessions: WebSession[];
    devices: DeviceSummary[];
    devicesUrl: string | null;
    history: HistoryPage | null;
    historyEvents: { value: string; label: string }[];
};

const PROTECTED_MESSAGE: Record<'self' | 'owner' | 'beyond', string> = {
    self: 'Ini akun Anda sendiri. Ubah data, password, MFA, dan sesi lewat menu Profil & Keamanan.',
    owner: 'Akun Owner hanya bisa diubah oleh Owner.',
    beyond: 'Akun ini memiliki hak akses yang tidak Anda miliki, jadi hanya bisa dikelola oleh Owner atau pemegang hak yang setara.',
};

type Pending = 'toggle' | 'reset-mfa' | 'revoke' | 'reset-password' | null;

/** Detail pengguna (05 §4.4 `admin.users.show`: tab profil, role, sesi, perangkat, riwayat). */
export default function UserShow(props: Props) {
    const { user, tab, can, protectedReason } = props;
    const [pending, setPending] = useState<Pending>(null);
    const [processing, setProcessing] = useState(false);
    const [temporary, setTemporary] = useState<string | null>(null);

    const tabs: PageTab[] = [
        { key: 'profil', label: 'Profil', icon: UserRound },
        { key: 'role', label: 'Role', icon: UsersRound },
        { key: 'sesi', label: 'Sesi', icon: Monitor },
        { key: 'perangkat', label: 'Perangkat', icon: Smartphone },
        { key: 'riwayat', label: 'Riwayat', icon: History },
    ].map((t) => ({
        ...t,
        href: show(user.id, { query: { tab: t.key } }),
    }));

    const post = (
        url: string,
        method: 'post' | 'patch',
        data: Record<string, boolean> = {},
    ) => {
        router[method](url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: () =>
                toast.error('Aksi gagal. Muat ulang halaman lalu coba lagi.'),
            onFinish: () => {
                setProcessing(false);
                setPending(null);
            },
        });
    };

    const hasActions =
        can.update ||
        can.toggle ||
        can.resetPassword ||
        can.resetMfa ||
        can.revokeSessions;

    return (
        <>
            <Head title={user.name} />
            <div className="mx-auto w-full max-w-5xl space-y-5 p-4 md:px-7 md:py-6">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={index()}>
                        <ArrowLeft aria-hidden="true" />
                        Daftar pengguna
                    </Link>
                </Button>

                <header className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1.5">
                        <h1 className="text-[1.4rem] leading-tight font-bold break-words">
                            {user.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span className="font-mono">{user.username}</span>
                            <StatusBadge
                                status={
                                    user.is_active
                                        ? 'user.active'
                                        : 'user.inactive'
                                }
                            />
                            {user.mfa_enabled ? (
                                <span className="inline-flex items-center gap-1 text-status-success">
                                    <ShieldCheck
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    MFA aktif
                                </span>
                            ) : (
                                <span className="inline-flex items-center gap-1">
                                    <ShieldOff
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                    {user.mfa_required
                                        ? 'MFA wajib, belum aktif'
                                        : 'MFA belum aktif'}
                                </span>
                            )}
                            {user.must_change_password && (
                                <Badge variant="outline">
                                    Wajib ganti password
                                </Badge>
                            )}
                            {user.is_locked && (
                                <Badge variant="destructive">
                                    Terkunci sementara
                                </Badge>
                            )}
                        </div>
                    </div>

                    {hasActions && (
                        <div className="flex flex-wrap gap-2">
                            {can.update && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={edit(user.id)}>
                                        <Pencil aria-hidden="true" />
                                        Ubah
                                    </Link>
                                </Button>
                            )}
                            {can.resetPassword && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPending('reset-password')}
                                >
                                    <KeyRound aria-hidden="true" />
                                    Reset Password
                                </Button>
                            )}
                            {can.resetMfa && user.mfa_enabled && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPending('reset-mfa')}
                                >
                                    <ShieldOff aria-hidden="true" />
                                    Reset MFA
                                </Button>
                            )}
                            {can.revokeSessions && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setPending('revoke')}
                                >
                                    <LogOut aria-hidden="true" />
                                    Cabut Semua Sesi
                                </Button>
                            )}
                            {can.toggle && (
                                <Button
                                    variant={
                                        user.is_active
                                            ? 'destructive'
                                            : 'default'
                                    }
                                    size="sm"
                                    onClick={() => setPending('toggle')}
                                >
                                    <Power aria-hidden="true" />
                                    {user.is_active
                                        ? 'Nonaktifkan'
                                        : 'Aktifkan'}
                                </Button>
                            )}
                        </div>
                    )}
                </header>

                {protectedReason && (
                    <p className="flex items-start gap-2.5 rounded-lg bg-status-info-bg px-3.5 py-3 text-sm text-status-info">
                        <Lock
                            className="mt-px size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{PROTECTED_MESSAGE[protectedReason]}</span>
                    </p>
                )}

                <PageTabs
                    tabs={tabs}
                    active={tab}
                    label="Bagian detail pengguna"
                />

                <section aria-label={tabs.find((t) => t.key === tab)?.label}>
                    {tab === 'profil' && <ProfileTab user={user} />}
                    {tab === 'role' && <RolesTab user={user} />}
                    {tab === 'sesi' && (
                        <SessionsTab sessions={props.sessions} />
                    )}
                    {tab === 'perangkat' && (
                        <DeviceSummaryList
                            devices={props.devices}
                            manageHref={props.devicesUrl}
                            emptyText={
                                props.devicesUrl
                                    ? 'Akun ini belum pernah masuk di aplikasi Pakis Pro.'
                                    : 'Anda tidak punya akses melihat perangkat.'
                            }
                        />
                    )}
                    {tab === 'riwayat' && props.history && (
                        <HistoryTab
                            userId={user.id}
                            history={props.history}
                            events={props.historyEvents}
                        />
                    )}
                </section>
            </div>

            <ConfirmAction
                open={pending === 'toggle'}
                title={
                    user.is_active
                        ? `Nonaktifkan akun ${user.username}?`
                        : `Aktifkan akun ${user.username}?`
                }
                description={
                    user.is_active
                        ? 'Akun tidak bisa dipakai masuk, dan semua sesi yang sedang aktif langsung dicabut. Tercatat di audit.'
                        : 'Akun bisa dipakai masuk lagi dengan password yang sama. Tercatat di audit.'
                }
                confirmLabel={
                    user.is_active ? 'Nonaktifkan Akun' : 'Aktifkan Akun'
                }
                destructive={user.is_active}
                processing={processing}
                onCancel={() => setPending(null)}
                onConfirm={() =>
                    post(toggle(user.id).url, 'patch', {
                        active: !user.is_active,
                    })
                }
            />
            <ConfirmAction
                open={pending === 'reset-mfa'}
                title={`Reset MFA ${user.username}?`}
                description="Aplikasi autentikator & kode pemulihan lama tidak berlaku lagi, dan semua sesinya dicabut. Bila role-nya mewajibkan MFA, ia harus mengaktifkan ulang saat login berikutnya."
                confirmLabel="Reset MFA"
                destructive
                processing={processing}
                onCancel={() => setPending(null)}
                onConfirm={() => post(resetMfa(user.id).url, 'post')}
            />
            <ConfirmAction
                open={pending === 'revoke'}
                title={`Cabut semua sesi ${user.username}?`}
                description="Semua peramban yang sedang masuk dengan akun ini keluar dan harus login ulang."
                confirmLabel="Cabut Semua Sesi"
                destructive
                processing={processing}
                onCancel={() => setPending(null)}
                onConfirm={() => post(revokeSessions(user.id).url, 'post')}
            />
            <ResetPasswordDialog
                open={pending === 'reset-password'}
                user={user}
                onClose={() => setPending(null)}
                onDone={(password) => {
                    setPending(null);
                    setTemporary(password);
                }}
            />
            <OneTimeSecretDialog
                secret={temporary}
                title={`Password sementara ${user.username}`}
                description="Semua sesi pengguna ini sudah dicabut. Ia wajib mengganti password ini saat masuk berikutnya."
                onDone={() => {
                    setTemporary(null);
                    router.reload();
                }}
            />
        </>
    );
}

function ConfirmAction({
    open,
    title,
    description,
    confirmLabel,
    destructive = false,
    processing,
    onCancel,
    onConfirm,
}: {
    open: boolean;
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
    processing: boolean;
    onCancel: () => void;
    onConfirm: () => void;
}) {
    return (
        <Dialog open={open} onOpenChange={(o) => !o && onCancel()}>
            <DialogContent>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>{description}</DialogDescription>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Reset password (FR-007): konfirmasi password admin di request yang sama, hasil tampil sekali. */
function ResetPasswordDialog({
    open,
    user,
    onClose,
    onDone,
}: {
    open: boolean;
    user: UserDetail;
    onClose: () => void;
    onDone: (password: string) => void;
}) {
    const http = useHttp<
        { current_password: string },
        { temporary_password: string }
    >({
        current_password: '',
    });

    const close = () => {
        http.reset();
        http.clearErrors();
        onClose();
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        http.submit(resetPassword(user.id), {
            onSuccess: (res) => {
                http.reset();
                onDone(res.temporary_password);
            },
            onHttpException: () => {
                toast.error(
                    'Password belum direset. Muat ulang halaman lalu coba lagi.',
                );
            },
        }).catch(() => {
            // Ditangani callback di atas.
        });
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4">
                    <DialogTitle>Reset password {user.username}?</DialogTitle>
                    <DialogDescription>
                        Sistem membuat password sementara yang ditampilkan
                        sekali, mencabut semua sesi akun ini, dan mewajibkan
                        ganti password saat masuk. Masukkan password Anda
                        sendiri untuk melanjutkan.
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor="admin_current_password">
                            Password Anda
                        </Label>
                        <PasswordInput
                            id="admin_current_password"
                            autoComplete="current-password"
                            autoFocus
                            value={http.data.current_password}
                            onChange={(e) =>
                                http.setData('current_password', e.target.value)
                            }
                            aria-invalid={
                                http.errors.current_password ? true : undefined
                            }
                        />
                        <InputError message={http.errors.current_password} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={close}>
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={
                                http.processing ||
                                http.data.current_password === ''
                            }
                        >
                            {http.processing && <Spinner />}
                            Reset Password
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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

const Dash = () => <span className="text-muted-foreground">–</span>;

function ProfileTab({ user }: { user: UserDetail }) {
    return (
        <dl className="grid gap-3 rounded-xl border bg-card p-4 sm:p-5">
            <Item label="Nama">{user.name}</Item>
            <Item label="Username">
                <span className="font-mono">{user.username}</span>
            </Item>
            <Item label="Email">
                {user.email ? (
                    <>
                        {user.email}{' '}
                        <span className="text-xs text-muted-foreground">
                            (
                            {user.email_verified
                                ? 'terverifikasi'
                                : 'belum terverifikasi'}
                            )
                        </span>
                    </>
                ) : (
                    <Dash />
                )}
            </Item>
            <Item label="HP">{user.phone ?? <Dash />}</Item>
            <Item label="Pegawai terkait">
                {user.employee ? (
                    user.employee.url ? (
                        <Link
                            href={user.employee.url}
                            className="font-medium text-primary underline-offset-4 hover:underline"
                        >
                            {user.employee.name} · {user.employee.number}
                        </Link>
                    ) : (
                        `${user.employee.name} · ${user.employee.number}`
                    )
                ) : (
                    <Dash />
                )}
            </Item>
            <Item label="Login terakhir">
                {user.last_login_at ? (
                    <>
                        {formatRelativeTime(user.last_login_at)}
                        {user.last_login_ip && (
                            <span className="text-muted-foreground">
                                {' '}
                                · {user.last_login_ip}
                            </span>
                        )}
                    </>
                ) : (
                    'Belum pernah'
                )}
            </Item>
            <Item label="Dibuat">
                {user.created_at ? (
                    formatRelativeTime(user.created_at)
                ) : (
                    <Dash />
                )}
            </Item>
        </dl>
    );
}

function RolesTab({ user }: { user: UserDetail }) {
    if (user.roles.length === 0) {
        return (
            <Empty text="Akun ini belum punya role, jadi belum bisa membuka menu apa pun." />
        );
    }

    return (
        <ul className="grid gap-2 sm:grid-cols-2">
            {user.roles.map((r) => (
                <li key={r.name} className="rounded-xl border bg-card p-4">
                    <p className="font-medium">{r.label}</p>
                    <p className="text-sm text-muted-foreground">
                        Cakupan data: {r.data_scope_label ?? '–'}
                        {r.mfa_required ? ' · wajib MFA' : ''}
                    </p>
                </li>
            ))}
        </ul>
    );
}

function SessionsTab({ sessions }: { sessions: WebSession[] }) {
    if (sessions.length === 0) {
        return <Empty text="Tidak ada sesi web yang aktif." />;
    }

    return (
        <ul className="divide-y rounded-xl border bg-card">
            {sessions.map((s) => (
                <li key={s.key} className="flex items-start gap-3 p-4">
                    <Monitor
                        className="mt-0.5 size-5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <div className="min-w-0 text-sm">
                        <p className="font-medium">
                            {s.browser} · {s.platform}
                        </p>
                        <p className="text-muted-foreground">
                            {s.ip ?? 'IP tidak diketahui'} · aktif{' '}
                            {formatRelativeTime(s.last_active_at)}
                        </p>
                    </div>
                </li>
            ))}
        </ul>
    );
}

function HistoryTab({
    userId,
    history,
    events,
}: {
    userId: number;
    history: HistoryPage;
    events: { value: string; label: string }[];
}) {
    const filter = (event: string) =>
        router.get(
            show(userId, {
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
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!history.pagination.prev_url}
                        asChild={!!history.pagination.prev_url}
                    >
                        {history.pagination.prev_url ? (
                            <Link
                                href={history.pagination.prev_url}
                                preserveScroll
                            >
                                Sebelumnya
                            </Link>
                        ) : (
                            <span>Sebelumnya</span>
                        )}
                    </Button>
                    <span className="text-sm text-muted-foreground">
                        Halaman {history.pagination.page} dari{' '}
                        {history.pagination.last_page}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        disabled={!history.pagination.next_url}
                        asChild={!!history.pagination.next_url}
                    >
                        {history.pagination.next_url ? (
                            <Link
                                href={history.pagination.next_url}
                                preserveScroll
                            >
                                Berikutnya
                            </Link>
                        ) : (
                            <span>Berikutnya</span>
                        )}
                    </Button>
                </nav>
            )}
        </div>
    );
}

/** Nama kolom audit akun → label layar (09 §7.1 `user.*`). Kunci lain tampil apa adanya. */
const FIELD_LABELS: Record<string, string> = {
    name: 'Nama',
    username: 'Username',
    email: 'Email',
    phone: 'HP',
    is_active: 'Aktif',
    roles: 'Role',
    initial_password: 'Password awal',
    must_change_password: 'Wajib ganti password',
    mfa: 'MFA',
};

function formatValue(v: unknown): string {
    if (v === null || v === undefined || v === '') {
        return '–';
    }
    if (typeof v === 'boolean') {
        return v ? 'Ya' : 'Tidak';
    }
    if (Array.isArray(v)) {
        return v.length > 0 ? v.join(', ') : '–';
    }
    if (typeof v === 'string' || typeof v === 'number') {
        return String(v);
    }

    return JSON.stringify(v);
}

function describeChanges(values: Record<string, unknown>): string {
    return Object.entries(values)
        .map(([k, v]) => `${FIELD_LABELS[k] ?? k}: ${formatValue(v)}`)
        .join(' · ');
}

function Empty({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}

UserShow.layout = {
    breadcrumbs: [{ title: 'Pengguna', href: index() }],
};
