import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Camera, MailWarning, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Admin/ProfileController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useInitials } from '@/hooks/use-initials';
import { edit } from '@/routes/admin/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = { auth: Auth };

/** Profil saya (FR-010): nama, HP, email (verifikasi ulang), foto. */
export default function ProfileEdit({ status }: { status?: string }) {
    const { auth } = usePage<PageProps>().props;
    const user = auth.user;
    const getInitials = useInitials();

    const [email, setEmail] = useState(user.email ?? '');
    const [phone, setPhone] = useState(user.phone ?? '');
    const [preview, setPreview] = useState<string | null>(null);
    const [removeAvatar, setRemoveAvatar] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    // Email & HP adalah kanal pemulihan akun: server meminta password saat ini bila berubah.
    const normalizedPhone = phone.replace(/[\s.\-()]/g, '');
    const recoveryChanged =
        email.trim().toLowerCase() !== (user.email ?? '') ||
        normalizedPhone !== (user.phone ?? '');

    useEffect(
        () => () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        },
        [preview],
    );

    const shownAvatar = removeAvatar ? null : (preview ?? user.avatar);

    const resetAvatar = () => {
        setPreview(null);
        setRemoveAvatar(false);
        if (fileInput.current) {
            fileInput.current.value = '';
        }
    };

    return (
        <>
            <Head title="Profil" />
            <h1 className="sr-only">Profil</h1>

            <SettingsSection
                title="Data diri"
                description="Nama, kontak, dan foto yang tampil di PAKIS ONE. Username diatur admin."
            >
                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess={['current_password']}
                    onSuccess={resetAvatar}
                    onError={(errors) => {
                        // Berkas ditolak server: jangan biarkan pratinjaunya seolah sudah terpasang.
                        if (errors.avatar) {
                            setPreview(null);
                            if (fileInput.current) {
                                fileInput.current.value = '';
                            }
                        }
                    }}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-center">
                                <Avatar className="size-20 shrink-0 overflow-hidden rounded-full ring-1 ring-border">
                                    {shownAvatar && (
                                        <AvatarImage
                                            src={shownAvatar}
                                            alt={`Foto ${user.name}`}
                                            className="object-cover"
                                        />
                                    )}
                                    <AvatarFallback className="rounded-full bg-sidebar-accent text-lg font-semibold text-sidebar-accent-foreground">
                                        {getInitials(user.name)}
                                    </AvatarFallback>
                                </Avatar>

                                <div className="grid gap-2">
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                fileInput.current?.click()
                                            }
                                        >
                                            <Camera aria-hidden="true" />
                                            {user.avatar || preview
                                                ? 'Ganti foto'
                                                : 'Pilih foto'}
                                        </Button>
                                        {(user.avatar || preview) &&
                                            !removeAvatar && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => {
                                                        resetAvatar();
                                                        setRemoveAvatar(
                                                            Boolean(
                                                                user.avatar,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    <Trash2 aria-hidden="true" />
                                                    Hapus foto
                                                </Button>
                                            )}
                                        {removeAvatar && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={resetAvatar}
                                            >
                                                Batal hapus
                                            </Button>
                                        )}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        PNG, JPG, atau WebP, maksimal 2 MB.
                                        Dipotong persegi otomatis.
                                    </p>
                                    <input
                                        ref={fileInput}
                                        id="avatar"
                                        name="avatar"
                                        type="file"
                                        accept="image/png,image/jpeg,image/webp"
                                        className="sr-only"
                                        tabIndex={-1}
                                        aria-label="Foto profil"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0];
                                            setRemoveAvatar(false);
                                            setPreview(
                                                file
                                                    ? URL.createObjectURL(file)
                                                    : null,
                                            );
                                        }}
                                    />
                                    {removeAvatar && (
                                        <input
                                            type="hidden"
                                            name="remove_avatar"
                                            value="1"
                                        />
                                    )}
                                    <InputError message={errors.avatar} />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama lengkap</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={user.name}
                                    required
                                    maxLength={150}
                                    autoComplete="name"
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    value={user.username}
                                    readOnly
                                    disabled
                                    autoComplete="username"
                                />
                            </div>

                            <div className="@container grid gap-4 sm:grid-cols-2">
                                <div className="grid content-start gap-2">
                                    <Label htmlFor="email">
                                        Email{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="email"
                                        name="email"
                                        type="email"
                                        value={email}
                                        onChange={(e) =>
                                            setEmail(e.target.value)
                                        }
                                        maxLength={150}
                                        autoComplete="email"
                                        aria-invalid={
                                            errors.email ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid content-start gap-2">
                                    <Label htmlFor="phone">
                                        Nomor HP{' '}
                                        <span className="font-normal text-muted-foreground">
                                            (opsional)
                                        </span>
                                    </Label>
                                    <Input
                                        id="phone"
                                        name="phone"
                                        type="tel"
                                        inputMode="tel"
                                        value={phone}
                                        onChange={(e) =>
                                            setPhone(e.target.value)
                                        }
                                        placeholder="0812xxxxxxxx"
                                        autoComplete="tel"
                                        aria-invalid={
                                            errors.phone ? true : undefined
                                        }
                                    />
                                    <InputError message={errors.phone} />
                                </div>
                            </div>

                            {user.email && user.email_verified_at === null && (
                                <div className="flex items-start gap-2.5 rounded-lg bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning">
                                    <MailWarning
                                        className="mt-px size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <div className="space-y-1">
                                        <p>
                                            Email belum diverifikasi, jadi belum
                                            dipakai untuk reset password.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="font-medium underline underline-offset-4"
                                            >
                                                Kirim ulang email verifikasi
                                            </Link>
                                        </p>
                                        {status ===
                                            'verification-link-sent' && (
                                            <p className="font-medium">
                                                Tautan verifikasi baru sudah
                                                dikirim.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {(recoveryChanged || errors.current_password) && (
                                <div className="grid gap-2 rounded-lg border border-dashed p-4">
                                    <Label htmlFor="current_password">
                                        Password saat ini
                                    </Label>
                                    <p className="-mt-1 text-xs text-muted-foreground">
                                        Email dan nomor HP dipakai untuk reset
                                        password, jadi perubahannya perlu
                                        dikonfirmasi.
                                    </p>
                                    <PasswordInput
                                        id="current_password"
                                        name="current_password"
                                        autoComplete="current-password"
                                        aria-invalid={
                                            errors.current_password
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError
                                        message={errors.current_password}
                                    />
                                </div>
                            )}

                            <div className="flex items-center gap-4 pt-1">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner />}
                                    Simpan Profil
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>
        </>
    );
}

ProfileEdit.layout = {
    breadcrumbs: [
        { title: 'Profil & keamanan', href: edit() },
        { title: 'Profil', href: edit() },
    ],
};
