import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import InputError from '@/components/input-error';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit } from '@/routes/profile';
import { send } from '@/routes/verification';
import type { Auth } from '@/types';

type PageProps = {
    auth: Auth;
};

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Profil" />

            <h1 className="sr-only">Profil</h1>

            <SettingsSection
                title="Profil"
                description="Nama dan email yang tampil di PAKIS ONE."
            >
                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-5"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="name">Nama lengkap</Label>

                                <Input
                                    id="name"
                                    defaultValue={auth.user.name}
                                    name="name"
                                    required
                                    autoComplete="name"
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />

                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>

                                <Input
                                    id="email"
                                    type="email"
                                    defaultValue={auth.user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />

                                <InputError message={errors.email} />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div className="rounded-lg bg-muted px-3.5 py-3 text-sm text-muted-foreground">
                                        <p>
                                            Email Anda belum diverifikasi.{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="font-medium text-primary underline decoration-primary/30 underline-offset-4 hover:decoration-current"
                                            >
                                                Kirim ulang email verifikasi
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <p className="mt-2 font-medium text-status-success">
                                                Tautan verifikasi baru sudah
                                                dikirim ke email Anda.
                                            </p>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4 pt-1">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {processing && <Spinner />}
                                    Simpan profil
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        { title: 'Pengaturan akun', href: edit() },
        { title: 'Profil', href: edit() },
    ],
};
