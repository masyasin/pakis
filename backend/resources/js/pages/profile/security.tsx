import { Head, router, useForm } from '@inertiajs/react';
import { ExternalLink, LogOut, Monitor, Send, Unlink } from 'lucide-react';
import { useEffect, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Admin/ProfileController';
import InputError from '@/components/input-error';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import { ConfirmPasswordDialog } from '@/components/pakis/confirm-password-dialog';
import { DeviceSummaryList } from '@/components/pakis/device-summary-list';
import type { DeviceSummary } from '@/components/pakis/device-summary-list';
import { PasswordRuleList } from '@/components/pakis/password-rules';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime, formatRelativeTime } from '@/lib/relative-time';
import { edit, security } from '@/routes/admin/profile';

type WebSession = {
    key: string;
    browser: string;
    platform: string;
    ip: string | null;
    last_active_at: string;
    is_current: boolean;
};

type Props = {
    passwordRules: string;
    minLength: number;
    maxLength: number;
    username: string;
    sessions: WebSession[];
    devices: DeviceSummary[];
    telegram: { available: boolean; linked_at: string | null };
} & ManageTwoFactorProps;

export default function ProfileSecurity(props: Props) {
    return (
        <>
            <Head title="Keamanan" />
            <h2 className="sr-only">Keamanan</h2>

            <ChangePasswordSection {...props} />

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
                mfaRequired={props.mfaRequired}
                recoveryCodesViewable={props.recoveryCodesViewable}
                recoveryCodesRemaining={props.recoveryCodesRemaining}
            />

            <SessionsSection sessions={props.sessions} />

            <SettingsSection
                title="Perangkat Pakis Pro"
                description="HP yang terdaftar untuk presensi lewat aplikasi Pakis Pro."
            >
                <DeviceSummaryList
                    devices={props.devices}
                    emptyText="Belum ada perangkat. Perangkat muncul di sini setelah Anda masuk di aplikasi Pakis Pro. HP pertama langsung aktif; HP berikutnya perlu disetujui admin."
                />
            </SettingsSection>

            <TelegramSection telegram={props.telegram} />
        </>
    );
}

function ChangePasswordSection({
    passwordRules,
    minLength,
    maxLength,
    username,
}: Props) {
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    return (
        <SettingsSection
            title="Ubah password"
            description="Pakai password panjang yang tidak Anda pakai di tempat lain. Sesi di perangkat lain ikut dikeluarkan."
        >
            <form
                className="space-y-5"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.submit(ProfileController.password(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset(),
                        onError: () =>
                            form.reset(
                                'current_password',
                                'password',
                                'password_confirmation',
                            ),
                    });
                }}
            >
                <div className="grid gap-2">
                    <Label htmlFor="current_password">Password saat ini</Label>
                    <PasswordInput
                        id="current_password"
                        autoComplete="current-password"
                        value={form.data.current_password}
                        onChange={(e) =>
                            form.setData('current_password', e.target.value)
                        }
                        aria-invalid={
                            form.errors.current_password ? true : undefined
                        }
                    />
                    <InputError message={form.errors.current_password} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid content-start gap-2">
                        <Label htmlFor="password">Password baru</Label>
                        <PasswordInput
                            id="password"
                            autoComplete="new-password"
                            maxLength={maxLength}
                            passwordrules={passwordRules}
                            value={form.data.password}
                            onChange={(e) =>
                                form.setData('password', e.target.value)
                            }
                            aria-invalid={
                                form.errors.password ? true : undefined
                            }
                            aria-describedby="password-rules"
                        />
                        <InputError message={form.errors.password} />
                    </div>
                    <div className="grid content-start gap-2">
                        <Label htmlFor="password_confirmation">
                            Ulangi password baru
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            autoComplete="new-password"
                            maxLength={maxLength}
                            value={form.data.password_confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                    </div>
                </div>

                <PasswordRuleList
                    password={form.data.password}
                    confirmation={form.data.password_confirmation}
                    minLength={minLength}
                    username={username}
                />

                <Button
                    disabled={form.processing}
                    data-test="update-password-button"
                >
                    {form.processing && <Spinner />}
                    Simpan Password
                </Button>
            </form>
        </SettingsSection>
    );
}

function SessionsSection({ sessions }: { sessions: WebSession[] }) {
    const others = sessions.filter((s) => !s.is_current);

    return (
        <SettingsSection
            title="Sesi aktif"
            description="Browser yang sedang masuk ke akun Anda. Keluarkan sesi yang tidak Anda kenali, lalu ganti password."
        >
            <ul className="divide-y rounded-lg border">
                {sessions.map((s) => (
                    <li
                        key={s.key}
                        className="flex flex-wrap items-center gap-x-3 gap-y-2 p-3.5"
                    >
                        <Monitor
                            className="size-5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div className="min-w-0 flex-1">
                            <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                {s.browser} · {s.platform}
                                {s.is_current && (
                                    <Badge variant="secondary">Sesi ini</Badge>
                                )}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {s.ip ?? 'IP tidak diketahui'} ·{' '}
                                <time
                                    dateTime={s.last_active_at}
                                    title={formatDateTime(s.last_active_at)}
                                >
                                    {s.is_current
                                        ? 'aktif sekarang'
                                        : `aktif ${formatRelativeTime(s.last_active_at)}`}
                                </time>
                            </p>
                        </div>
                        {!s.is_current && (
                            <ConfirmPasswordDialog
                                trigger={
                                    <Button variant="outline" size="sm">
                                        <LogOut aria-hidden="true" />
                                        Keluarkan
                                    </Button>
                                }
                                title="Keluarkan sesi ini?"
                                description={`${s.browser} · ${s.platform} akan diminta masuk lagi. Masukkan password Anda untuk melanjutkan.`}
                                confirmLabel="Keluarkan Sesi"
                                destructive
                                action={ProfileController.destroySession(s.key)}
                            />
                        )}
                    </li>
                ))}
            </ul>

            {others.length > 0 && (
                <div className="mt-4">
                    <ConfirmPasswordDialog
                        trigger={
                            <Button variant="outline">
                                <LogOut aria-hidden="true" />
                                Keluarkan semua sesi lain
                            </Button>
                        }
                        title="Keluarkan semua sesi lain?"
                        description={`${others.length} sesi lain akan diminta masuk lagi. Sesi ini tetap masuk.`}
                        confirmLabel="Keluarkan Semua"
                        destructive
                        action={ProfileController.destroyOtherSessions()}
                    />
                </div>
            )}
        </SettingsSection>
    );
}

function TelegramSection({ telegram }: Pick<Props, 'telegram'>) {
    const [link, setLink] = useState<string | null>(null);

    // Tautan sekali pakai dikirim lewat flash (bukan prop) agar tidak tersimpan di riwayat halaman.
    useEffect(
        () =>
            router.on('flash', (event) => {
                const flash = (event as CustomEvent).detail?.flash as
                    | { telegram_link?: string }
                    | undefined;
                if (flash?.telegram_link) {
                    setLink(flash.telegram_link);
                }
            }),
        [],
    );

    if (!telegram.available && !telegram.linked_at) {
        return null;
    }

    return (
        <SettingsSection
            title="Telegram"
            description="Hubungkan akun ke bot Telegram agar bisa menerima kode reset password lewat Telegram."
        >
            {telegram.linked_at ? (
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm">
                        <Badge variant="secondary" className="mr-2">
                            Terhubung
                        </Badge>
                        sejak {formatDateTime(telegram.linked_at)}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            router.delete(
                                ProfileController.unlinkTelegram().url,
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Unlink aria-hidden="true" />
                        Putuskan
                    </Button>
                </div>
            ) : link ? (
                <div className="space-y-3">
                    <p className="text-sm text-muted-foreground">
                        Buka bot, lalu tekan <strong>Start</strong>. Tautan
                        berlaku 10 menit dan hanya bisa dipakai sekali. Setelah
                        bot membalas, muat ulang halaman ini.
                    </p>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild>
                            <a href={link} target="_blank" rel="noreferrer">
                                <ExternalLink aria-hidden="true" />
                                Buka Telegram
                            </a>
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() =>
                                router.reload({ only: ['telegram'] })
                            }
                        >
                            Sudah, muat ulang
                        </Button>
                    </div>
                </div>
            ) : (
                <ConfirmPasswordDialog
                    trigger={
                        <Button>
                            <Send aria-hidden="true" />
                            Hubungkan Telegram
                        </Button>
                    }
                    title="Hubungkan Telegram"
                    description="Masukkan password Anda. Setelah itu buka bot dan tekan Start."
                    confirmLabel="Buat Tautan"
                    action={ProfileController.linkTelegram()}
                />
            )}
        </SettingsSection>
    );
}

ProfileSecurity.layout = {
    breadcrumbs: [
        { title: 'Profil & keamanan', href: edit() },
        { title: 'Keamanan', href: security() },
    ],
};
