import { Link, useForm, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    Camera,
    CircleCheck,
    Clock,
    History,
    MessageSquareText,
    PhoneCall,
    Send,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { VERIFICATION_STATUS_TONE } from '@/components/customer/customer-ui';
import type {
    CustomerStatus,
    VerificationRow,
} from '@/components/customer/customer-ui';
import {
    DetailCard,
    EmptyPanel,
    Field,
    FieldGrid,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { LocationPointInput } from '@/components/pakis/location-point-input';
import { MoneyInput } from '@/components/pakis/money-input';
import { OtpCodeInput } from '@/components/pakis/otp-code-input';
import { PhotoCapture } from '@/components/pakis/photo-capture';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTimeIn } from '@/lib/approval-format';
import { postJson } from '@/lib/json-post';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import { show as approvalShow } from '@/routes/admin/approvals';
import { officeCall, verify } from '@/routes/admin/customers';
import {
    check as otpCheck,
    send as otpSend,
} from '@/routes/admin/customers/otp';

export type OfficeCallDraft = {
    submitted_by: { id: number; name: string } | null;
    submitted_at: string;
    requested_credit_limit: string;
    /** Pengguna ini boleh mencatat telepon (bukan pengaju, BR-165b). */
    can_record: boolean;
};

/** Prop `verification` detail kios (`VerificationState::for`, T-074). */
export type VerificationState = {
    otp_available: boolean;
    otp_length: number;
    max_accuracy_m: number;
    can_submit: boolean;
    pending: VerificationRow | null;
    office_call_draft: OfficeCallDraft | null;
    history: VerificationRow[];
};

type Method = 'otp' | 'office_call';

type Props = {
    customerId: number;
    status: CustomerStatus;
    isVerified: boolean;
    verifiedAt: string | null;
    state: VerificationState;
};

/**
 * Tab Verifikasi detail kios (15 §7.1 `?tab=verifikasi`, BR-165, T-074): keadaan menunggu, kirim
 * verifikasi (foto toko, titik GPS, bukti nomor lewat OTP WhatsApp atau telepon kantor), catat
 * telepon kantor oleh petugas lain, dan riwayat. Semua aturan tetap diperiksa server; layar ini
 * hanya mencegah kiriman yang pasti ditolak.
 */
export function VerificationTab({
    customerId,
    status,
    isVerified,
    verifiedAt,
    state,
}: Props) {
    const timezone = usePage().props.timezone;
    const draft = state.office_call_draft;
    // Draf telepon kantor milik pengguna sendiri: form kirim ulang disembunyikan sampai diminta.
    const [resubmit, setResubmit] = useState(false);
    const showForm = state.can_submit && (draft === null || resubmit);

    return (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div className="space-y-5">
                {isVerified && (
                    <FormStatus
                        tone="success"
                        message={`Kios sudah terverifikasi${verifiedAt ? ` sejak ${formatDateTimeIn(verifiedAt, timezone)}` : ''}.`}
                    />
                )}
                {state.pending && (
                    <PendingCard row={state.pending} timezone={timezone} />
                )}
                {draft && (
                    <DraftCard
                        customerId={customerId}
                        draft={draft}
                        timezone={timezone}
                        canResubmit={state.can_submit && !resubmit}
                        onResubmit={() => setResubmit(true)}
                    />
                )}
                {showForm ? (
                    <SubmitForm customerId={customerId} state={state} />
                ) : (
                    !state.pending &&
                    !draft &&
                    !isVerified && (
                        <EmptyPanel
                            icon={ShieldCheck}
                            title="Belum bisa mengirim verifikasi"
                            text={
                                status === 'prospect'
                                    ? 'Verifikasi dikirim oleh pengelola kios (izin kelola pelanggan) yang berada di lokasi toko.'
                                    : 'Verifikasi aktivasi hanya untuk kios berstatus Prospek.'
                            }
                        />
                    )
                )}
            </div>
            <HistoryCard rows={state.history} timezone={timezone} />
        </div>
    );
}

function PendingCard({
    row,
    timezone,
}: {
    row: VerificationRow;
    timezone: string;
}) {
    return (
        <DetailCard
            title="Menunggu persetujuan"
            icon={Clock}
            action={
                row.approval_request_id !== null ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={approvalShow(row.approval_request_id)}>
                            Buka persetujuan
                        </Link>
                    </Button>
                ) : undefined
            }
        >
            <FieldGrid>
                <Field label="Bukti nomor" value={row.method_label} />
                <Field
                    label="Limit kredit diminta"
                    value={formatRupiah(row.requested_credit_limit)}
                />
                <Field label="Diajukan oleh" value={row.submitted_by?.name} />
                <Field
                    label="Diajukan"
                    value={
                        row.created_at
                            ? formatDateTimeIn(row.created_at, timezone)
                            : null
                    }
                />
                {row.office_call_note && (
                    <Field
                        label="Catatan telepon kantor"
                        value={row.office_call_note}
                        wide
                    />
                )}
            </FieldGrid>
            <p className="mt-3 text-xs text-muted-foreground">
                Kios aktif dan limit kreditnya berlaku setelah persetujuan
                selesai.
            </p>
        </DetailCard>
    );
}

function DraftCard({
    customerId,
    draft,
    timezone,
    canResubmit,
    onResubmit,
}: {
    customerId: number;
    draft: OfficeCallDraft;
    timezone: string;
    canResubmit: boolean;
    onResubmit: () => void;
}) {
    const [open, setOpen] = useState(false);
    const mine = !draft.can_record;

    return (
        <DetailCard
            title="Menunggu telepon kantor"
            icon={PhoneCall}
            action={
                draft.can_record ? (
                    <Button size="sm" onClick={() => setOpen(true)}>
                        <PhoneCall aria-hidden="true" />
                        Catat Telepon Kantor
                    </Button>
                ) : undefined
            }
        >
            <FieldGrid>
                <Field label="Diajukan oleh" value={draft.submitted_by?.name} />
                <Field
                    label="Diajukan"
                    value={formatDateTimeIn(draft.submitted_at, timezone)}
                />
                <Field
                    label="Limit kredit diminta"
                    value={formatRupiah(draft.requested_credit_limit)}
                />
            </FieldGrid>
            <p className="mt-3 text-sm text-muted-foreground">
                {draft.can_record
                    ? 'Telepon pemilik kios dari telepon kantor ke nomor yang terdaftar, pastikan nomor itu memang miliknya, lalu catat ringkasannya. Setelah dicatat, verifikasi masuk persetujuan.'
                    : 'Petugas lain (bukan pengaju) harus menelepon pemilik kios dari telepon kantor dan mencatat hasilnya. Pengaju tidak boleh mencatat teleponnya sendiri.'}
            </p>
            {mine && canResubmit && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="mt-2"
                    onClick={onResubmit}
                >
                    Kirim ulang verifikasi
                </Button>
            )}
            {open && (
                <OfficeCallDialog
                    customerId={customerId}
                    onOpenChange={setOpen}
                />
            )}
        </DetailCard>
    );
}

function OfficeCallDialog({
    customerId,
    onOpenChange,
}: {
    customerId: number;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ office_call_note: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(officeCall(customerId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title="Catat telepon kantor"
            description="Tulis ringkasan telepon dengan pemilik kios: siapa yang menjawab dan apa yang dikonfirmasi."
            icon={PhoneCall}
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
                        Simpan Catatan
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form} />
            <div className="grid gap-2">
                <Label htmlFor="office_call_note">Ringkasan telepon</Label>
                <textarea
                    id="office_call_note"
                    className="min-h-24 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    value={form.data.office_call_note}
                    maxLength={500}
                    rows={4}
                    placeholder="Dijawab Pak Budi (pemilik). Membenarkan nomor ini miliknya dan alamat toko di Jl. Raya Undaan."
                    onChange={(e) =>
                        form.setData('office_call_note', e.target.value)
                    }
                    aria-invalid={errors.office_call_note ? true : undefined}
                />
                <p className="text-xs text-muted-foreground tabular-nums">
                    {form.data.office_call_note.trim().length}/500, minimal 10
                    karakter.
                </p>
                <InputError message={errors.office_call_note} />
            </div>
        </FormDialog>
    );
}

type OtpPhase = 'idle' | 'sending' | 'sent' | 'checking' | 'valid';

function SubmitForm({
    customerId,
    state,
}: {
    customerId: number;
    state: VerificationState;
}) {
    const timezone = usePage().props.timezone;
    const form = useForm({
        method: (state.otp_available ? 'otp' : 'office_call') as Method,
        photo: null as File | null,
        latitude: '',
        longitude: '',
        accuracy_m: '',
        requested_credit_limit: '',
        otp_code: '',
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [otpPhase, setOtpPhase] = useState<OtpPhase>('idle');
    const [otpSentTo, setOtpSentTo] = useState<{
        phone: string;
        expires_at: string;
    } | null>(null);
    const [otpError, setOtpError] = useState<string | null>(null);
    const [otpInput, setOtpInput] = useState('');

    const method = form.data.method;
    const accuracy =
        form.data.accuracy_m === '' ? null : Number(form.data.accuracy_m);
    const accuracyTooLow = accuracy !== null && accuracy > state.max_accuracy_m;

    const setMethod = (next: Method) => {
        form.setData('method', next);
        form.clearErrors('method', 'otp_code');
    };

    const sendCode = async () => {
        setOtpPhase('sending');
        setOtpError(null);
        setOtpInput('');
        form.setData('otp_code', '');
        const result = await postJson<{
            phone: string;
            expires_at: string;
            length: number;
        }>(otpSend(customerId).url, {});
        if (result.ok) {
            setOtpSentTo(result.data);
            setOtpPhase('sent');

            return;
        }
        setOtpError(result.message);
        setOtpPhase(otpSentTo ? 'sent' : 'idle');
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
        // Pemeriksaan awal agar kiriman yang pasti ditolak tidak mengunggah foto dulu.
        const local: Record<string, string> = {};
        if (!form.data.photo) {
            local.photo = 'Ambil foto toko lebih dulu.';
        }
        if (form.data.latitude === '' || form.data.longitude === '') {
            local.latitude = 'Ambil titik lokasi toko dari HP.';
        } else if (accuracy === null) {
            local.accuracy_m =
                'Tekan "Ambil lokasi HP" di depan toko: akurasi GPS wajib terbaca, titik dari tautan atau ketikan tidak diterima.';
        } else if (accuracyTooLow) {
            local.accuracy_m = `Akurasi lokasi belum cukup (lebih dari ${state.max_accuracy_m} m). Pindah ke tempat terbuka lalu ambil ulang lokasi.`;
        }
        if (method === 'otp' && otpPhase !== 'valid') {
            local.otp_code =
                'Kirim kode ke WhatsApp pemilik kios lalu isi kodenya.';
        }
        if (Object.keys(local).length > 0) {
            form.clearErrors();
            form.setError(local);

            return;
        }

        form.transform((d) => ({
            ...d,
            otp_code: d.method === 'otp' ? d.otp_code : '',
        }));
        form.post(verify(customerId).url, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOtpPhase('idle');
                setOtpSentTo(null);
                setOtpInput('');
            },
            onError: (errs) => {
                // OTP kedaluwarsa/salah saat kirim: kode harus diminta ulang.
                if (errs.code || errs.otp_code) {
                    setOtpPhase(otpSentTo ? 'sent' : 'idle');
                    setOtpInput('');
                }
            },
        });
    };

    const generalError =
        errors.form ??
        errors.owner_phone ??
        errors.method ??
        (form.hasErrors
            ? 'Periksa lagi isian yang ditandai merah.'
            : undefined);

    return (
        <DetailCard title="Kirim verifikasi" icon={BadgeCheck}>
            <form onSubmit={submit} className="space-y-6" noValidate>
                <p className="text-sm text-muted-foreground">
                    Lakukan di lokasi toko: foto tampak depan, titik lokasi dari
                    HP, lalu buktikan nomor pemilik. Setelah disetujui kios
                    menjadi Aktif dan limit kreditnya berlaku.
                </p>
                <FormStatus tone="error" message={generalError} />

                <PhotoCapture
                    id="verification-photo"
                    label="Foto toko"
                    value={form.data.photo}
                    onChange={(file) => {
                        form.setData('photo', file);
                        form.clearErrors('photo');
                    }}
                    error={errors.photo}
                    hint="Foto tampak depan dengan papan nama terlihat. Di HP kamera langsung terbuka."
                />

                <div className="space-y-2">
                    <p className="text-sm font-medium">Titik lokasi toko</p>
                    <p className="text-xs text-muted-foreground">
                        Tekan "Ambil lokasi HP" di depan toko. Akurasi harus
                        paling jauh {state.max_accuracy_m} m.
                    </p>
                    <LocationPointInput
                        idPrefix="verification-point"
                        label="Titik toko"
                        emptyText="Belum ada titik. Titik wajib diambil dari HP di lokasi toko."
                        latitude={form.data.latitude}
                        longitude={form.data.longitude}
                        errors={{
                            latitude: errors.latitude,
                            longitude: errors.longitude,
                        }}
                        onChange={(p) => {
                            form.setData({
                                ...form.data,
                                latitude: p.latitude,
                                longitude: p.longitude,
                                accuracy_m:
                                    p.accuracyM === null
                                        ? ''
                                        : String(p.accuracyM),
                            });
                            form.clearErrors(
                                'latitude',
                                'longitude',
                                'accuracy_m',
                            );
                        }}
                    />
                    {accuracyTooLow && !errors.accuracy_m && (
                        <p
                            className="text-xs text-status-warning"
                            role="status"
                        >
                            Akurasi {accuracy} m melebihi batas{' '}
                            {state.max_accuracy_m} m. Ambil ulang di tempat
                            terbuka.
                        </p>
                    )}
                    <InputError message={errors.accuracy_m} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="verification-limit">
                        Limit kredit yang diminta
                    </Label>
                    <MoneyInput
                        id="verification-limit"
                        value={form.data.requested_credit_limit}
                        onChange={(v) =>
                            form.setData('requested_credit_limit', v)
                        }
                        invalid={Boolean(errors.requested_credit_limit)}
                        placeholder="0"
                        describedBy="verification-limit-hint"
                    />
                    <p
                        id="verification-limit-hint"
                        className="text-xs text-muted-foreground"
                    >
                        Kosongkan bila kios cukup order tunai. Limit besar bisa
                        butuh persetujuan berlapis sesuai aturan persetujuan.
                    </p>
                    <InputError message={errors.requested_credit_limit} />
                </div>

                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">
                        Bukti nomor pemilik
                    </legend>
                    <MethodOption
                        value="otp"
                        checked={method === 'otp'}
                        disabled={!state.otp_available}
                        onSelect={setMethod}
                        title="OTP WhatsApp"
                        text={
                            state.otp_available
                                ? 'Kode dikirim ke WhatsApp pemilik kios; pemilik membacakan kodenya.'
                                : 'Belum tersedia: OTP WhatsApp kios belum diaktifkan atau layanan WhatsApp belum terpasang.'
                        }
                    />
                    <MethodOption
                        value="office_call"
                        checked={method === 'office_call'}
                        onSelect={setMethod}
                        title="Telepon kantor"
                        text="Petugas lain menelepon pemilik dari telepon kantor dan mencatat hasilnya. Verifikasi menunggu catatan itu sebelum masuk persetujuan."
                    />
                </fieldset>

                {method === 'otp' && (
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
                                {otpSentTo
                                    ? 'Kirim ulang kode'
                                    : 'Kirim kode ke WhatsApp pemilik'}
                            </Button>
                            {otpSentTo && (
                                <span className="text-xs text-muted-foreground">
                                    Dikirim ke{' '}
                                    <span className="font-mono tabular-nums">
                                        {otpSentTo.phone}
                                    </span>
                                    , berlaku sampai{' '}
                                    {formatDateTimeIn(
                                        otpSentTo.expires_at,
                                        timezone,
                                    )}
                                    .
                                </span>
                            )}
                        </div>
                        {otpSentTo && otpPhase !== 'valid' && (
                            <div className="grid justify-items-start gap-2">
                                <Label htmlFor="verification-otp">
                                    Kode dari pemilik kios
                                </Label>
                                <OtpCodeInput
                                    id="verification-otp"
                                    length={state.otp_length}
                                    value={otpInput}
                                    onChange={setOtpInput}
                                    onComplete={(code) => void checkCode(code)}
                                    disabled={otpPhase === 'checking'}
                                    invalid={otpError !== null}
                                    aria-label="Kode OTP pemilik kios"
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
                                Kode benar. Lanjutkan dengan Kirim Verifikasi.
                            </p>
                        )}
                        {otpError && (
                            <p
                                className="text-sm text-destructive"
                                role="alert"
                            >
                                {otpError}
                            </p>
                        )}
                        <InputError
                            message={
                                errors.otp_code ??
                                // Galat OTP dari kiriman sebelumnya; hilang begitu kode baru benar.
                                (otpPhase === 'valid' ? undefined : errors.code)
                            }
                        />
                    </div>
                )}

                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing ? (
                            <Spinner />
                        ) : (
                            <Camera aria-hidden="true" />
                        )}
                        Kirim Verifikasi
                    </Button>
                </div>
            </form>
        </DetailCard>
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
                name="verification-method"
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

function HistoryCard({
    rows,
    timezone,
}: {
    rows: VerificationRow[];
    timezone: string;
}) {
    return (
        <DetailCard title="Riwayat verifikasi" icon={History}>
            {rows.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    Belum ada verifikasi untuk kios ini.
                </p>
            ) : (
                <Timeline>
                    {rows.map((r, i) => (
                        <TimelineItem
                            key={r.id}
                            active={r.status === 'pending'}
                            last={i === rows.length - 1}
                        >
                            <div className="space-y-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="font-medium">
                                        {r.type_label} · {r.method_label}
                                    </p>
                                    <StatusBadge
                                        status={`customer_verification.${r.status}`}
                                        label={r.status_label}
                                        tone={
                                            VERIFICATION_STATUS_TONE[r.status]
                                        }
                                    />
                                </div>
                                <p className="text-sm">
                                    Limit diminta{' '}
                                    {formatRupiah(r.requested_credit_limit)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {r.submitted_by?.name ??
                                        'Pengguna terhapus'}
                                    {r.created_at
                                        ? ` · ${formatDateTimeIn(r.created_at, timezone)}`
                                        : ''}
                                </p>
                                {r.office_call_note && (
                                    <p className="flex gap-1.5 text-sm">
                                        <MessageSquareText
                                            className="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        {r.office_call_note}
                                    </p>
                                )}
                                {r.approval_request_id !== null && (
                                    <Link
                                        href={approvalShow(
                                            r.approval_request_id,
                                        )}
                                        className="text-xs font-medium text-primary hover:underline"
                                    >
                                        Lihat persetujuan
                                    </Link>
                                )}
                            </div>
                        </TimelineItem>
                    ))}
                </Timeline>
            )}
        </DetailCard>
    );
}
