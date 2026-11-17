import { Link, useForm } from '@inertiajs/react';
import { CircleCheck, Clock, Send, Smartphone } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { CustomerPhone } from '@/components/customer/customer-ui';
import type { ChangeState } from '@/components/customer/customer-ui';
import { plausiblePhone } from '@/components/customer/duplicate-check';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { OtpCodeInput } from '@/components/pakis/otp-code-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTimeIn } from '@/lib/approval-format';
import { postJson } from '@/lib/json-post';
import { cn } from '@/lib/utils';
import { show as approvalShow } from '@/routes/admin/approvals';
import { changePhone } from '@/routes/admin/customers';
import {
    check as otpCheck,
    send as otpSend,
} from '@/routes/admin/customers/change-phone/otp';

type PhoneChangeState = ChangeState['phone_change'];
type Method = 'otp' | 'office_call';

export type PhoneChangeInput = {
    new_phone: string;
    method: Method;
    office_call_note: string;
    reason: string;
};

const TEXTAREA =
    'min-h-24 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * Pemeriksaan awal ganti HP (BR-166(4), PA-57a) sebelum dikirim: hanya kiriman yang pasti ditolak
 * server. `currentPhone` = HP sekarang dalam bentuk penuh (null bila pengguna hanya melihat versi
 * termasking, jadi tidak bisa dibandingkan di layar).
 */
export function phoneChangeIssues(
    data: PhoneChangeInput,
    otpValid: boolean,
    currentPhone: string | null,
): Record<string, string> {
    const issues: Record<string, string> = {};
    const next = plausiblePhone(data.new_phone);
    if (data.new_phone.trim() === '') {
        issues.new_phone = 'Isi HP baru pemilik kios.';
    } else if (next === null) {
        issues.new_phone =
            'HP baru belum sah. Tulis nomor Indonesia, mis. 0812 3456 7890.';
    } else if (currentPhone !== null && plausiblePhone(currentPhone) === next) {
        issues.new_phone = 'HP baru sama dengan HP sekarang.';
    }
    if (data.method === 'otp' && !otpValid) {
        issues.otp_code =
            'Kirim kode ke WhatsApp HP lama pemilik lalu isi kodenya.';
    }
    if (
        data.method === 'office_call' &&
        data.office_call_note.trim().length < 10
    ) {
        issues.office_call_note =
            'Tulis ringkasan telepon ke HP lama, minimal 10 karakter.';
    }
    if (data.reason.trim().length < 5) {
        issues.reason = 'Tulis alasan ganti HP, minimal 5 karakter.';
    }

    return issues;
}

/**
 * Kartu ganti HP pemilik di tab Verifikasi (15 §5 `admin.customers.change-phone`, BR-166(4)): permintaan
 * yang menunggu persetujuan (kedua HP termasking) atau tombol untuk mengajukan. HP prospek yang
 * belum terverifikasi tidak lewat jalur ini, cukup diubah di Ubah Kios.
 */
export function PhoneChangeCard({
    customerId,
    ownerPhone,
    phoneMasked,
    state,
    allowed,
    timezone,
}: {
    customerId: number;
    ownerPhone: string;
    phoneMasked: boolean;
    state: PhoneChangeState;
    /** Pengguna memegang `customers.change_phone` untuk kios ini. */
    allowed: boolean;
    timezone: string;
}) {
    const [open, setOpen] = useState(false);
    const pending = state.pending;

    if (pending) {
        return (
            <DetailCard
                title="Ganti HP menunggu persetujuan"
                icon={Clock}
                action={
                    pending.approval_request_id !== null ? (
                        <Button variant="outline" size="sm" asChild>
                            <Link
                                href={approvalShow(pending.approval_request_id)}
                            >
                                Buka persetujuan
                            </Link>
                        </Button>
                    ) : undefined
                }
            >
                <FieldGrid>
                    <Field
                        label="HP lama"
                        value={
                            <CustomerPhone
                                phone={pending.phone_previous}
                                masked
                            />
                        }
                    />
                    <Field
                        label="HP baru"
                        value={
                            <CustomerPhone
                                phone={pending.phone_target}
                                masked
                            />
                        }
                    />
                    <Field
                        label="Bukti nomor lama"
                        value={pending.method_label}
                    />
                    <Field
                        label="Diajukan oleh"
                        value={pending.submitted_by?.name}
                        hint={
                            pending.created_at
                                ? formatDateTimeIn(pending.created_at, timezone)
                                : undefined
                        }
                    />
                </FieldGrid>
                <p className="mt-3 text-xs text-muted-foreground">
                    HP pemilik baru berganti setelah disetujui Owner.
                </p>
            </DetailCard>
        );
    }

    if (!allowed) {
        return null;
    }

    return (
        <DetailCard
            title="HP pemilik"
            icon={Smartphone}
            action={
                state.can_request ? (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setOpen(true)}
                    >
                        <Smartphone aria-hidden="true" />
                        Ganti HP
                    </Button>
                ) : undefined
            }
        >
            <FieldGrid>
                <Field
                    label="HP sekarang"
                    value={
                        <CustomerPhone
                            phone={ownerPhone}
                            masked={phoneMasked}
                        />
                    }
                />
            </FieldGrid>
            <p className="mt-3 text-xs text-muted-foreground">
                {state.can_request
                    ? 'Ganti HP butuh bukti nomor LAMA (OTP WhatsApp ke HP lama atau telepon kantor) dan persetujuan Owner.'
                    : 'Kios prospek yang HP-nya belum terverifikasi: ubah HP lewat Ubah Kios.'}
            </p>
            {open && (
                <PhoneChangeDialog
                    customerId={customerId}
                    currentPhone={phoneMasked ? null : ownerPhone}
                    state={state}
                    onOpenChange={setOpen}
                />
            )}
        </DetailCard>
    );
}

type OtpPhase = 'idle' | 'sending' | 'sent' | 'checking' | 'valid';

/** Dialog ganti HP. Di-mount hanya saat dibuka agar setiap pembukaan mulai kosong. */
export function PhoneChangeDialog({
    customerId,
    currentPhone,
    state,
    onOpenChange,
}: {
    customerId: number;
    currentPhone: string | null;
    state: PhoneChangeState;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({
        new_phone: '',
        method: (state.otp_available ? 'otp' : 'office_call') as Method,
        otp_code: '',
        office_call_note: '',
        reason: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [otpPhase, setOtpPhase] = useState<OtpPhase>('idle');
    const [sentTo, setSentTo] = useState<{
        phone: string;
        expires_at: string;
    } | null>(null);
    const [otpError, setOtpError] = useState<string | null>(null);
    const [otpInput, setOtpInput] = useState('');
    const method = form.data.method;

    const sendCode = async () => {
        setOtpPhase('sending');
        setOtpError(null);
        setOtpInput('');
        form.setData('otp_code', '');
        const result = await postJson<{ phone: string; expires_at: string }>(
            otpSend(customerId).url,
            {},
        );
        if (result.ok) {
            setSentTo(result.data);
            setOtpPhase('sent');

            return;
        }
        setOtpError(result.message);
        setOtpPhase(sentTo ? 'sent' : 'idle');
    };

    const checkCode = async (code: string) => {
        setOtpPhase('checking');
        setOtpError(null);
        const result = await postJson<{ valid: boolean }>(
            otpCheck(customerId).url,
            { code },
        );
        if (result.ok && result.data.valid) {
            form.setData('otp_code', code);
            form.clearErrors('otp_code');
            setOtpPhase('valid');

            return;
        }
        setOtpError(
            result.ok
                ? 'Kode OTP salah.'
                : (result.fields.code ?? result.message),
        );
        setOtpInput('');
        setOtpPhase('sent');
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const local = phoneChangeIssues(
            form.data,
            otpPhase === 'valid',
            currentPhone,
        );
        if (Object.keys(local).length > 0) {
            form.clearErrors();
            form.setError(local);

            return;
        }
        form.transform((d) => ({
            new_phone: d.new_phone,
            method: d.method,
            reason: d.reason,
            ...(d.method === 'otp'
                ? { otp_code: d.otp_code }
                : { office_call_note: d.office_call_note }),
        }));
        form.post(changePhone(customerId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onError: (errs) => {
                // Kode kedaluwarsa/salah saat kirim: kode harus diminta ulang.
                if (errs.code || errs.otp_code) {
                    setOtpPhase(sentTo ? 'sent' : 'idle');
                    setOtpInput('');
                }
            },
        });
    };

    const setMethod = (next: Method) => {
        form.setData('method', next);
        form.clearErrors('method', 'otp_code', 'office_call_note');
    };

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title="Ganti HP pemilik"
            description="Buktikan dulu bahwa pemilik masih memegang HP LAMA. Setelah disetujui Owner, HP baru menjadi nomor WhatsApp resmi kios."
            icon={Smartphone}
            size="lg"
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Batal
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Ajukan Ganti HP
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form ?? errors.method} />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="phone-change-new">HP baru (WhatsApp)</Label>
                    <Input
                        id="phone-change-new"
                        type="tel"
                        inputMode="tel"
                        maxLength={20}
                        className="tabular-nums"
                        placeholder="0812 3456 7890"
                        value={form.data.new_phone}
                        onChange={(e) =>
                            form.setData('new_phone', e.target.value)
                        }
                        aria-invalid={errors.new_phone ? true : undefined}
                    />
                    <InputError message={errors.new_phone} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="phone-change-reason">Alasan</Label>
                    <Input
                        id="phone-change-reason"
                        maxLength={255}
                        placeholder="mis. HP lama hilang, pemilik ganti nomor"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        aria-invalid={errors.reason ? true : undefined}
                    />
                    <InputError message={errors.reason} />
                </div>
            </div>

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">
                    Bukti nomor lama
                </legend>
                <MethodOption
                    value="otp"
                    checked={method === 'otp'}
                    disabled={!state.otp_available}
                    onSelect={setMethod}
                    title="OTP WhatsApp ke HP lama"
                    text={
                        state.otp_available
                            ? 'Kode dikirim ke WhatsApp HP lama; pemilik membacakan kodenya.'
                            : 'Belum tersedia: OTP WhatsApp kios belum diaktifkan atau layanan WhatsApp belum terpasang.'
                    }
                />
                <MethodOption
                    value="office_call"
                    checked={method === 'office_call'}
                    onSelect={setMethod}
                    title="Telepon kantor"
                    text="Anda menelepon HP lama pemilik dari telepon kantor dan mencatat hasilnya di sini. Owner memeriksa catatan itu saat menyetujui."
                />
            </fieldset>

            {method === 'otp' ? (
                <div className="space-y-3 rounded-lg border bg-muted/30 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            disabled={
                                otpPhase === 'sending' ||
                                otpPhase === 'checking'
                            }
                            onClick={() => void sendCode()}
                        >
                            {otpPhase === 'sending' ? (
                                <Spinner />
                            ) : (
                                <Send aria-hidden="true" />
                            )}
                            {sentTo
                                ? 'Kirim ulang kode'
                                : 'Kirim kode ke HP lama'}
                        </Button>
                        {sentTo && (
                            <span className="text-xs text-muted-foreground">
                                Dikirim ke{' '}
                                <span className="font-mono tabular-nums">
                                    {sentTo.phone}
                                </span>
                                .
                            </span>
                        )}
                    </div>
                    {sentTo && otpPhase !== 'valid' && (
                        <div className="grid justify-items-start gap-2">
                            <Label htmlFor="phone-change-otp">
                                Kode dari pemilik kios
                            </Label>
                            <OtpCodeInput
                                id="phone-change-otp"
                                length={state.otp_length}
                                value={otpInput}
                                onChange={setOtpInput}
                                onComplete={(code) => void checkCode(code)}
                                disabled={otpPhase === 'checking'}
                                invalid={otpError !== null}
                                aria-label="Kode OTP HP lama"
                            />
                        </div>
                    )}
                    {otpPhase === 'valid' && (
                        <p
                            className="flex items-center gap-2 text-sm text-status-success"
                            role="status"
                        >
                            <CircleCheck
                                className="size-4"
                                aria-hidden="true"
                            />
                            Kode benar. Lanjutkan dengan Ajukan Ganti HP.
                        </p>
                    )}
                    {otpError && (
                        <p className="text-sm text-destructive" role="alert">
                            {otpError}
                        </p>
                    )}
                    <InputError
                        message={
                            errors.otp_code ??
                            (otpPhase === 'valid' ? undefined : errors.code)
                        }
                    />
                </div>
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="phone-change-note">
                        Ringkasan telepon ke HP lama
                    </Label>
                    <textarea
                        id="phone-change-note"
                        className={TEXTAREA}
                        rows={4}
                        maxLength={500}
                        placeholder="Dijawab Pak Budi (pemilik) di HP lama. Membenarkan pindah ke nomor baru karena HP lama rusak."
                        value={form.data.office_call_note}
                        onChange={(e) =>
                            form.setData('office_call_note', e.target.value)
                        }
                        aria-invalid={
                            errors.office_call_note ? true : undefined
                        }
                    />
                    <p className="text-xs text-muted-foreground tabular-nums">
                        {form.data.office_call_note.trim().length}/500, minimal
                        10 karakter.
                    </p>
                    <InputError message={errors.office_call_note} />
                </div>
            )}
        </FormDialog>
    );
}

function MethodOption({
    value,
    checked,
    disabled,
    onSelect,
    title,
    text,
}: {
    value: Method;
    checked: boolean;
    disabled?: boolean;
    onSelect: (value: Method) => void;
    title: string;
    text: string;
}) {
    return (
        <label
            className={cn(
                'flex items-start gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors',
                disabled
                    ? 'cursor-not-allowed opacity-60'
                    : 'cursor-pointer hover:border-primary/60',
                checked && 'border-primary bg-primary/5',
            )}
        >
            <input
                type="radio"
                name="phone-change-method"
                value={value}
                checked={checked}
                disabled={disabled}
                onChange={() => onSelect(value)}
                className="mt-0.5 accent-primary"
            />
            <span className="grid gap-0.5">
                <span className="font-medium">{title}</span>
                <span className="text-xs text-muted-foreground">{text}</span>
            </span>
        </label>
    );
}
