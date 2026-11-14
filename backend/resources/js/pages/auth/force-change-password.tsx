import { Head, Link, useForm } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { PasswordRuleList } from '@/components/pakis/password-rules';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';

type Props = {
    username: string;
    minLength: number;
    maxLength: number;
    passwordRules: string;
};

/**
 * Wajib ganti password (07 §4, FR-007, SEC-006): tidak bisa dilewati; satu-satunya jalan keluar
 * selain menyimpan password baru adalah Keluar. Aturan dari server (BR-011) ditampilkan langsung.
 */
export default function ForceChangePassword({
    username,
    minLength,
    maxLength,
    passwordRules,
}: Props) {
    const form = useForm({ password: '', password_confirmation: '' });
    const { password, password_confirmation: confirmation } = form.data;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/ganti-password', {
            preserveScroll: true,
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Ganti password" />

            <form onSubmit={submit} className="flex flex-col gap-6">
                <FormStatus
                    tone="error"
                    message={form.errors.password_confirmation}
                />

                <div className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="password">Password baru</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            autoComplete="new-password"
                            autoFocus
                            required
                            maxLength={maxLength}
                            passwordrules={passwordRules}
                            value={password}
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

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">
                            Ulangi password baru
                        </Label>
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            autoComplete="new-password"
                            required
                            maxLength={maxLength}
                            value={confirmation}
                            onChange={(e) =>
                                form.setData(
                                    'password_confirmation',
                                    e.target.value,
                                )
                            }
                        />
                    </div>

                    <PasswordRuleList
                        password={password}
                        confirmation={confirmation}
                        minLength={minLength}
                        username={username}
                    />

                    <Button
                        type="submit"
                        size="lg"
                        className="w-full"
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        Simpan Password
                    </Button>
                </div>
            </form>

            <Link
                href={logout()}
                as="button"
                className="mx-auto mt-6 flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <LogOut className="size-4" aria-hidden="true" />
                Keluar
            </Link>
        </>
    );
}

ForceChangePassword.layout = {
    title: 'Ganti password dulu',
    description:
        'Demi keamanan, akun Anda wajib memakai password baru sebelum melanjutkan.',
};
