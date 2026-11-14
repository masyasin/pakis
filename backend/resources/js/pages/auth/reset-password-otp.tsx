import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { PasswordRuleList } from '@/components/pakis/password-rules';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    status?: string;
    login: string;
    codeLength: number;
    minLength: number;
    passwordRules: string;
};

const RESEND_SECONDS = 60;

/** Reset password dengan kode OTP WhatsApp/Telegram (BR-013). */
export default function ResetPasswordOtp({
    status,
    login,
    codeLength,
    minLength,
    passwordRules,
}: Props) {
    const form = useForm({
        login,
        code: '',
        password: '',
        password_confirmation: '',
    });
    const [wait, setWait] = useState(status ? RESEND_SECONDS : 0);

    // Hitung mundur sebelum boleh meminta kode lagi (server juga menolak < 60 detik).
    useEffect(() => {
        if (wait <= 0) {
            return;
        }
        const timer = window.setTimeout(() => setWait((w) => w - 1), 1000);

        return () => window.clearTimeout(timer);
    }, [wait]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password-otp', {
            preserveScroll: true,
            onFinish: () => form.reset('password', 'password_confirmation'),
        });
    };

    return (
        <>
            <Head title="Masukkan kode" />

            <form onSubmit={submit} className="grid gap-5">
                <FormStatus tone="success" message={status} />
                <FormStatus tone="error" message={form.errors.code} />

                <div className="grid gap-2">
                    <Label htmlFor="login">Username atau email</Label>
                    <Input
                        id="login"
                        name="login"
                        autoComplete="username"
                        autoCapitalize="none"
                        spellCheck={false}
                        required
                        value={form.data.login}
                        onChange={(e) => form.setData('login', e.target.value)}
                    />
                    <InputError message={form.errors.login} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="code">Kode {codeLength} digit</Label>
                    <InputOTP
                        id="code"
                        maxLength={codeLength}
                        autoFocus
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        value={form.data.code}
                        onChange={(value) => form.setData('code', value)}
                        containerClassName="justify-start"
                    >
                        <InputOTPGroup>
                            {Array.from({ length: codeLength }, (_, i) => (
                                <InputOTPSlot key={i} index={i} />
                            ))}
                        </InputOTPGroup>
                    </InputOTP>
                    <p className="text-xs text-muted-foreground">
                        {wait > 0
                            ? `Belum menerima kode? Minta lagi dalam ${wait} detik.`
                            : 'Belum menerima kode?'}{' '}
                        {wait <= 0 && (
                            <Link href="/forgot-password" className="underline">
                                Minta kode baru
                            </Link>
                        )}
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password">Password baru</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        autoComplete="new-password"
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
                    username={
                        form.data.login.includes('@')
                            ? undefined
                            : form.data.login
                    }
                />

                <Button
                    type="submit"
                    size="lg"
                    className="w-full"
                    disabled={
                        form.processing || form.data.code.length !== codeLength
                    }
                >
                    {form.processing && <Spinner />}
                    Simpan password baru
                </Button>
            </form>

            <Link
                href="/login"
                className="mt-6 inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" aria-hidden="true" />
                Kembali ke halaman masuk
            </Link>
        </>
    );
}

ResetPasswordOtp.layout = {
    title: 'Masukkan kode reset',
    description:
        'Kode dikirim ke WhatsApp atau Telegram akun Anda dan hanya berlaku beberapa menit.',
};
