import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { edit as editProfile } from '@/routes/profile';
import { edit } from '@/routes/security';

type Props = {
    passwordRules: string;
} & ManageTwoFactorProps;

export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Keamanan" />

            <h1 className="sr-only">Keamanan</h1>

            <SettingsSection
                title="Ubah password"
                description="Pakai password panjang yang tidak Anda pakai di tempat lain."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="space-y-5"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="current_password">
                                    Password saat ini
                                </Label>

                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    autoComplete="current-password"
                                    aria-invalid={
                                        errors.current_password
                                            ? true
                                            : undefined
                                    }
                                />

                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password baru</Label>

                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    autoComplete="new-password"
                                    passwordrules={props.passwordRules}
                                    aria-invalid={
                                        errors.password ? true : undefined
                                    }
                                />

                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Ulangi password baru
                                </Label>

                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    autoComplete="new-password"
                                    passwordrules={props.passwordRules}
                                    aria-invalid={
                                        errors.password_confirmation
                                            ? true
                                            : undefined
                                    }
                                />

                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <div className="flex items-center gap-4 pt-1">
                                <Button
                                    disabled={processing}
                                    data-test="update-password-button"
                                >
                                    {processing && <Spinner />}
                                    Simpan password
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </SettingsSection>

            <ManageTwoFactor
                canManageTwoFactor={props.canManageTwoFactor}
                requiresConfirmation={props.requiresConfirmation}
                twoFactorEnabled={props.twoFactorEnabled}
            />
        </>
    );
}

Security.layout = {
    breadcrumbs: [
        { title: 'Pengaturan akun', href: editProfile() },
        { title: 'Keamanan', href: edit() },
    ],
};
