import { Head, useForm } from '@inertiajs/react';
import { ArrowLeft, Mail, MessageCircle, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { login } from '@/routes';

type Channel = { value: string; label: string };

const ICONS = { email: Mail, whatsapp: MessageCircle, telegram: Send } as const;

const HINT: Record<string, string> = {
    email: 'Tautan reset ke email akun',
    whatsapp: 'Kode ke nomor WhatsApp akun',
    telegram: 'Kode ke Telegram yang sudah dihubungkan',
};

/**
 * Lupa password (07 §4, FR-005, FR-006). Respons server selalu sama apa pun akunnya; kalimat
 * "akun tanpa email" sengaja teks tetap agar tidak membocorkan keberadaan akun.
 */
export default function ForgotPassword({
    status,
    channels,
}: {
    status?: string;
    channels: Channel[];
}) {
    const form = useForm({
        login: '',
        channel: channels[0]?.value ?? 'email',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password', { preserveScroll: true });
    };

    const isEmail = form.data.channel === 'email';

    return (
        <>
            <Head title="Lupa password" />

            <div className="space-y-6">
                <FormStatus tone="success" message={status} />

                <form onSubmit={submit} className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="login">Username atau email</Label>
                        <Input
                            id="login"
                            name="login"
                            autoComplete="username"
                            autoCapitalize="none"
                            spellCheck={false}
                            autoFocus
                            required
                            value={form.data.login}
                            onChange={(e) =>
                                form.setData('login', e.target.value)
                            }
                            aria-invalid={form.errors.login ? true : undefined}
                        />
                        <InputError message={form.errors.login} />
                    </div>

                    {channels.length > 1 && (
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-medium">
                                Kirim lewat
                            </legend>
                            <div className="grid gap-2">
                                {channels.map((c) => {
                                    const Icon =
                                        ICONS[c.value as keyof typeof ICONS] ??
                                        Mail;
                                    const checked =
                                        form.data.channel === c.value;

                                    return (
                                        <label
                                            key={c.value}
                                            className={cn(
                                                'flex cursor-pointer items-start gap-2.5 rounded-lg border bg-card p-3 text-sm transition-colors has-focus-visible:ring-[3px] has-focus-visible:ring-ring/50',
                                                checked
                                                    ? 'border-primary bg-primary/5'
                                                    : 'hover:bg-muted/50',
                                            )}
                                        >
                                            <input
                                                type="radio"
                                                name="channel"
                                                value={c.value}
                                                checked={checked}
                                                onChange={() =>
                                                    form.setData(
                                                        'channel',
                                                        c.value,
                                                    )
                                                }
                                                className="sr-only"
                                            />
                                            <Icon
                                                className={cn(
                                                    'mt-0.5 size-4 shrink-0',
                                                    checked
                                                        ? 'text-primary'
                                                        : 'text-muted-foreground',
                                                )}
                                                aria-hidden="true"
                                            />
                                            <span className="grid gap-0.5">
                                                <span className="font-medium">
                                                    {c.label}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {HINT[c.value]}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <InputError message={form.errors.channel} />
                        </fieldset>
                    )}

                    <Button
                        size="lg"
                        className="w-full"
                        disabled={form.processing}
                        data-test="email-password-reset-link-button"
                    >
                        {form.processing && <Spinner />}
                        {isEmail ? 'Kirim tautan reset' : 'Kirim kode'}
                    </Button>
                </form>

                <p className="rounded-lg bg-muted px-3.5 py-3 text-sm leading-relaxed text-muted-foreground">
                    Akun tanpa email, nomor WhatsApp, atau Telegram? Hubungi
                    admin/HRD untuk reset password.
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
        'Masukkan username atau email akun Anda, lalu pilih cara menerima petunjuk reset.',
};
