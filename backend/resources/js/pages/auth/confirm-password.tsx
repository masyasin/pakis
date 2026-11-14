import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Konfirmasi password" />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="current-password"
                                autoFocus
                                aria-invalid={
                                    errors.password ? true : undefined
                                }
                            />
                            <InputError message={errors.password} />
                        </div>

                        <Button
                            size="lg"
                            className="w-full"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner />}
                            Konfirmasi
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Konfirmasi password',
    description:
        'Bagian ini berisi pengaturan penting. Masukkan password Anda untuk melanjutkan.',
};
