import { Form, Head } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { email } from '@/routes/password';

export default function ForgotPassword({ status }: { status?: string }) {
    return (
        <>
            <Head title="Lupa password" />

            <div className="space-y-6">
                <FormStatus tone="success" message={status} />

                <Form {...email.form()} className="grid gap-5">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                {/* Kanal username/WA datang di T-014; saat ini backend memvalidasi email. */}
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="off"
                                    autoFocus
                                    inputMode="email"
                                    aria-invalid={
                                        errors.email ? true : undefined
                                    }
                                />
                                <InputError message={errors.email} />
                            </div>

                            <Button
                                size="lg"
                                className="w-full"
                                disabled={processing}
                                data-test="email-password-reset-link-button"
                            >
                                {processing && <Spinner />}
                                Kirim tautan reset
                            </Button>
                        </>
                    )}
                </Form>

                <p className="rounded-lg bg-muted px-3.5 py-3 text-sm leading-relaxed text-muted-foreground">
                    Akun tanpa email? Hubungi admin/HRD untuk reset password.
                </p>

                <TextLink
                    href={login()}
                    className="inline-flex items-center gap-1.5 text-sm no-underline hover:underline"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Kembali ke halaman masuk
                </TextLink>
            </div>
        </>
    );
}

ForgotPassword.layout = {
    title: 'Lupa password',
    description:
        'Masukkan email akun Anda. Kami kirim tautan untuk membuat password baru.',
};
