import { REGEXP_ONLY_DIGITS } from 'input-otp';
import { useEffect, useRef } from 'react';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { cn } from '@/lib/utils';

/**
 * Isian kode numerik (TOTP 2FA, OTP lupa password): kotak per digit yang besar, dipisah di
 * tengah agar mudah dibaca, dan `onComplete` dipanggil begitu semua digit terisi — formulir
 * dikirim otomatis tanpa klik (permintaan Mas). Tempel kode dari clipboard juga langsung terkirim.
 */
export function OtpCodeInput({
    length = 6,
    name,
    value,
    onChange,
    onComplete,
    disabled,
    invalid,
    autoFocus = true,
    id,
    'aria-label': ariaLabel = 'Kode verifikasi',
}: {
    length?: number;
    name?: string;
    value?: string;
    onChange?: (value: string) => void;
    onComplete?: (value: string) => void;
    disabled?: boolean;
    invalid?: boolean;
    autoFocus?: boolean;
    id?: string;
    'aria-label'?: string;
}) {
    const inputRef = useRef<HTMLInputElement>(null);

    // Kode salah → isian dikosongkan; kembalikan kursor agar bisa langsung mengetik ulang.
    useEffect(() => {
        if (invalid && !disabled) {
            inputRef.current?.focus();
        }
    }, [invalid, disabled]);

    const half = Math.ceil(length / 2);
    const slot = (index: number) => (
        <InputOTPSlot
            key={index}
            index={index}
            className={cn(
                // Kotak terpisah, bukan deret menyatu: lebih mudah dibaca & disentuh di HP.
                'size-11 rounded-xl border bg-card text-xl font-semibold tabular-nums shadow-xs first:rounded-xl first:border-l last:rounded-xl sm:size-12',
                invalid && 'border-status-danger/60',
                'data-[active=true]:border-primary data-[active=true]:ring-[3px] data-[active=true]:ring-primary/20',
            )}
        />
    );

    return (
        <InputOTP
            ref={inputRef}
            id={id}
            name={name}
            maxLength={length}
            value={value}
            onChange={onChange}
            onComplete={(code: string) => {
                // Tunda satu tick: nilai input tersembunyi sudah terisi saat formulir membaca data.
                if (onComplete) {
                    setTimeout(() => onComplete(code), 0);
                }
            }}
            disabled={disabled}
            pattern={REGEXP_ONLY_DIGITS}
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus={autoFocus}
            aria-label={ariaLabel}
            aria-invalid={invalid ? true : undefined}
            containerClassName="justify-center gap-0"
        >
            <InputOTPGroup className="gap-1.5 sm:gap-2">
                {Array.from({ length: half }, (_, i) => slot(i))}
            </InputOTPGroup>
            <span
                className="mx-1.5 h-0.5 w-3 rounded-full bg-border sm:mx-2"
                aria-hidden="true"
            />
            <InputOTPGroup className="gap-1.5 sm:gap-2">
                {Array.from({ length: length - half }, (_, i) =>
                    slot(half + i),
                )}
            </InputOTPGroup>
        </InputOTP>
    );
}
