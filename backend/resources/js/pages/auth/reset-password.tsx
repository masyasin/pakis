import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { PasswordRuleList } from '@/components/pakis/password-rules';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    token: string;
    email: string;
    minLength: number;
    passwordRules: string;
};

/** Buat password baru dari tautan email (BR-012). Token & email dari tautan, tidak diketik. */
export default function ResetPassword({
    token,
    email,
    minLength,
    passwordRules,
}: Props) {
    const form = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password', {
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Buat password baru" />

            <form onSubmit={submit} className="grid gap-5">
                {/* Galat token (`email`) sengaja generik: tautan salah/kedaluwarsa/dipakai. */}
                <FormStatus tone="error" message={form.errors.email} />

                <div className="grid gap-2">
                    <Label htmlFor="password">Password baru</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        passwordrules={passwordRules}
                        value={form.data.password}
                        onChange={(e) =>
                            form.setData('password', e.target.value)
                        }
                        aria-invalid={form.errors.password ? true : undefined}
                        aria-describedby="password-rules"
                    />
                    <InputError message={form.errors.password} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">
                        Ulangi password baru
                    </Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        required
                        value={form.data.password_confirmation}
                        onChange={(e) =>
                            form.setData(
                                'password_confirmation',
                                e.target.value,
                            )
                        }
                    />
                </div>

                <PasswordRuleList
                    password={form.data.password}
                    confirmation={form.data.password_confirmation}
                    minLength={minLength}
                />

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={form.processing}
                    data-test="reset-password-button"
                >
                    {form.processing && <Spinner />}
                    Simpan password baru
                </Button>
            </form>
        </>
    );
}

ResetPassword.layout = {
    title: 'Buat password baru',
    description: 'Pilih password yang belum pernah Anda pakai di tempat lain.',
};
