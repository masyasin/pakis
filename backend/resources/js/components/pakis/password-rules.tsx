import { Check, Circle } from 'lucide-react';
import { cn } from '@/lib/utils';

/**
 * Indikator aturan password (BR-011) yang dicentang langsung saat mengetik. Aturan yang hanya bisa
 * dicek server (password lama, password bocor) ditulis sebagai catatan.
 */
export function PasswordRuleList({
    password,
    confirmation,
    minLength,
    username,
    id = 'password-rules',
}: {
    password: string;
    confirmation: string;
    minLength: number;
    /** Bila diketahui (wajib ganti / OTP), cek "tidak memuat username" juga ditampilkan. */
    username?: string;
    id?: string;
}) {
    const rules = [
        {
            ok: password.length >= minLength,
            label: `Minimal ${minLength} karakter`,
        },
        ...(username
            ? [
                  {
                      ok:
                          password !== '' &&
                          !password
                              .toLowerCase()
                              .includes(username.toLowerCase()),
                      label: 'Tidak memuat username Anda',
                  },
              ]
            : []),
        {
            ok: password !== '' && password === confirmation,
            label: 'Konfirmasi sama dengan password baru',
        },
    ];

    return (
        <ul
            id={id}
            className="grid gap-1.5 text-sm"
            aria-label="Syarat password"
        >
            {rules.map((rule) => (
                <li
                    key={rule.label}
                    className={cn(
                        'flex items-center gap-2',
                        rule.ok
                            ? 'text-status-success'
                            : 'text-muted-foreground',
                    )}
                >
                    {rule.ok ? (
                        <Check className="size-4 shrink-0" aria-hidden="true" />
                    ) : (
                        <Circle
                            className="size-4 shrink-0"
                            aria-hidden="true"
                        />
                    )}
                    <span>
                        {rule.label}
                        <span className="sr-only">
                            {rule.ok ? ' (terpenuhi)' : ' (belum)'}
                        </span>
                    </span>
                </li>
            ))}
            <li className="pl-6 text-xs text-muted-foreground">
                Juga tidak boleh sama dengan password lama dan tidak boleh
                password yang pernah bocor di internet.
            </li>
        </ul>
    );
}
