import { Form, Head } from '@inertiajs/react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Masuk" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <FormStatus tone="success" message={status} />
                        {/*
                         * Galat kredensial & throttle dikirim Fortify di field `email`.
                         * Teksnya generik (lang/id/auth.php), jadi tidak membocorkan
                         * apakah akun ada. Tampil sebagai banner, bukan di bawah satu field.
                         */}
                        <FormStatus tone="error" message={errors.email} />

                        <div className="grid gap-5">
                            <div className="grid gap-2">
                                {/* Label sudah final; backend masih menerima email sampai T-013. */}
                                <Label htmlFor="email">
                                    Username atau email
                                </Label>
                                <Input
                                    id="email"
                                    type="text"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="username"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">Password</Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={request()}
                                            className="ml-auto text-sm"
                                            tabIndex={5}
                                        >
                                            Lupa password?
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    aria-invalid={
                                        errors.password ? true : undefined
                                    }
                                />
                                <InputError message={errors.password} />
                            </div>

                            {/* Tanpa "ingat saya" (SEC-005). Input remember juga dibuang di server. */}
                            <Button
                                type="submit"
                                size="lg"
                                className="mt-2 w-full"
                                tabIndex={3}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Masuk
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Masuk ke PAKIS ONE',
    description: 'Gunakan akun yang diberikan admin atau HRD kantor.',
};
