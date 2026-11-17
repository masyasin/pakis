import { Link, useForm } from '@inertiajs/react';
import {
    Ban,
    Clock,
    Combine,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import type {
    ChangeState,
    CustomerStatus,
    StatusActionValue,
} from '@/components/customer/customer-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTimeIn } from '@/lib/approval-format';
import { show as approvalShow } from '@/routes/admin/approvals';
import { merge, show, status } from '@/routes/admin/customers';

const TEXTAREA =
    'min-h-20 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

type StatusActionCopy = {
    icon: LucideIcon;
    /** Aksi yang mengurangi kemampuan kios bertransaksi diberi warna bahaya. */
    danger: boolean;
    text: string;
    placeholder: string;
};

/** Penjelasan tiap transisi status manual kios (15 §6.6) untuk dialog alasan. */
export const STATUS_ACTION_COPY: Record<StatusActionValue, StatusActionCopy> = {
    hold: {
        icon: PauseCircle,
        danger: true,
        text: 'Pesanan kredit baru tertahan sampai tahanan dilepas. Pesanan yang sudah disetujui tidak dibatalkan.',
        placeholder:
            'mis. tagihan lewat jatuh tempo, menunggu konfirmasi pemilik',
    },
    release: {
        icon: PlayCircle,
        danger: false,
        text: 'Kios kembali Aktif dan bisa order kredit sesuai limitnya.',
        placeholder: 'mis. tunggakan sudah dilunasi',
    },
    deactivate: {
        icon: Ban,
        danger: true,
        text: 'Kios tidak bisa bertransaksi sampai diaktifkan kembali. Permintaan persetujuan kios ini yang masih menunggu ditarik.',
        placeholder: 'mis. toko tutup permanen',
    },
    reactivate: {
        icon: RotateCcw,
        danger: false,
        text: 'HP pemilik diperiksa ulang. Kios yang sudah terverifikasi kembali Aktif; yang belum kembali Prospek.',
        placeholder: 'mis. toko buka kembali',
    },
};

/** Ubah status kios dengan alasan wajib (`admin.customers.status`, 15 §6.6). */
export function StatusDialog({
    customerId,
    storeName,
    action,
    onOpenChange,
}: {
    customerId: number;
    storeName: string;
    action: { value: StatusActionValue; label: string };
    onOpenChange: (open: boolean) => void;
}) {
    const copy = STATUS_ACTION_COPY[action.value];
    const form = useForm({ action: action.value, reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (form.data.reason.trim().length < 5) {
            form.setError('reason', 'Tulis alasan, minimal 5 karakter.');

            return;
        }
        form.post(status(customerId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title={`${action.label}: ${storeName}`}
            description={copy.text}
            icon={copy.icon}
            tone={copy.danger ? 'danger' : 'default'}
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
                    <Button
                        type="submit"
                        variant={copy.danger ? 'destructive' : 'default'}
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        {action.label}
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form ?? errors.action} />
            <div className="grid gap-2">
                <Label htmlFor="status-reason">Alasan</Label>
                <textarea
                    id="status-reason"
                    className={TEXTAREA}
                    rows={3}
                    maxLength={255}
                    placeholder={copy.placeholder}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                />
                <InputError message={errors.reason} />
            </div>
        </FormDialog>
    );
}

const one = (value: string) => (value === '' ? [] : [value]);

/**
 * Gabungkan kios duplikat ke kios lain (`admin.customers.merge`, BR-166(5)). Kios INI = sumber
 * (menjadi Digabung), pilihan = kios yang dipertahankan. Server memeriksa ulang scope & status.
 */
export function MergeDialog({
    customerId,
    storeName,
    onOpenChange,
}: {
    customerId: number;
    storeName: string;
    onOpenChange: (open: boolean) => void;
}) {
    const form = useForm({ target_customer_id: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const local: Record<string, string> = {};
        if (form.data.target_customer_id === '') {
            local.target_customer_id =
                'Pilih kios tujuan (kios yang dipertahankan).';
        } else if (form.data.target_customer_id === String(customerId)) {
            local.target_customer_id = 'Pilih kios lain, bukan kios ini.';
        }
        if (form.data.reason.trim().length < 5) {
            local.reason = 'Tulis alasan, minimal 5 karakter.';
        }
        if (Object.keys(local).length > 0) {
            form.clearErrors();
            form.setError(local);

            return;
        }
        form.post(merge(customerId).url, {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={onOpenChange}
            title={`Gabung ${storeName} ke kios lain`}
            description="Untuk kios yang ternyata tercatat dua kali. Kios yang dipilih dipertahankan; kios ini ditutup."
            icon={Combine}
            tone="danger"
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
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={form.processing}
                    >
                        {form.processing && <Spinner />}
                        Ajukan Gabung
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form} />
            <div
                className="flex gap-2.5 rounded-lg bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning"
                role="note"
            >
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <ul className="list-disc space-y-1 pl-4">
                    <li>
                        Kontak dan riwayat verifikasi pindah ke kios tujuan.
                    </li>
                    <li>
                        Kios ini berstatus Digabung, tidak bisa diubah lagi, dan
                        hanya tampil untuk riwayat. Riwayat penugasannya tetap
                        di sini.
                    </li>
                    <li>
                        Permintaan persetujuan kios ini yang masih menunggu
                        ditarik. Penggabungan berlaku setelah disetujui.
                    </li>
                </ul>
            </div>
            <div className="grid gap-2">
                <Label htmlFor="merge-target">Kios tujuan</Label>
                <AsyncSelect
                    id="merge-target"
                    source="customers"
                    exclude={[customerId]}
                    value={one(form.data.target_customer_id)}
                    placeholder="Cari kode, nama toko, atau pemilik"
                    onChange={(v) =>
                        form.setData('target_customer_id', String(v[0] ?? ''))
                    }
                />
                <InputError message={errors.target_customer_id} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="merge-reason">Alasan</Label>
                <textarea
                    id="merge-reason"
                    className={TEXTAREA}
                    rows={3}
                    maxLength={255}
                    placeholder="mis. kios sama didaftarkan dua sales, HP pemilik sama"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errors.reason ? true : undefined}
                />
                <InputError message={errors.reason} />
            </div>
        </FormDialog>
    );
}

/**
 * Pemberitahuan keadaan kios di bawah kepala halaman (semua tab): sudah digabung, gabung menunggu
 * persetujuan, atau alasan tahan kredit/nonaktif. Null bila tidak ada yang perlu diberitahukan.
 */
export function CustomerNotices({
    status: customerStatus,
    merge: mergeState,
    holdReason,
    timezone,
}: {
    status: CustomerStatus;
    merge: ChangeState['merge'];
    holdReason: string | null;
    timezone: string;
}) {
    const target = mergeState.merged_into;
    const pending = mergeState.pending;

    return (
        <>
            {target && (
                <Notice tone="muted" icon={Combine}>
                    Kios ini sudah digabung ke{' '}
                    <Link
                        href={show(target.id)}
                        className="font-medium text-primary hover:underline"
                    >
                        {target.store_name} ({target.code})
                    </Link>
                    . Data di sini hanya untuk riwayat dan tidak bisa diubah.
                </Notice>
            )}
            {!target && pending && (
                <Notice tone="warning" icon={Clock}>
                    Penggabungan ke{' '}
                    <span className="font-medium">
                        {pending.target
                            ? `${pending.target.store_name} (${pending.target.code})`
                            : 'kios lain'}
                    </span>{' '}
                    menunggu persetujuan
                    {pending.requested_by
                        ? `, diajukan ${pending.requested_by.name}`
                        : ''}
                    {pending.created_at
                        ? ` ${formatDateTimeIn(pending.created_at, timezone)}`
                        : ''}
                    .{' '}
                    <Link
                        href={approvalShow(pending.approval_request_id)}
                        className="font-medium text-primary hover:underline"
                    >
                        Buka persetujuan
                    </Link>
                </Notice>
            )}
            {holdReason &&
                (customerStatus === 'credit_hold' ||
                    customerStatus === 'inactive') && (
                    <Notice
                        tone="warning"
                        icon={customerStatus === 'inactive' ? Ban : PauseCircle}
                    >
                        {customerStatus === 'inactive'
                            ? 'Alasan dinonaktifkan: '
                            : 'Alasan tahan kredit: '}
                        <span className="font-medium">{holdReason}</span>
                    </Notice>
                )}
        </>
    );
}

function Notice({
    tone,
    icon: Icon,
    children,
}: {
    tone: 'warning' | 'muted';
    icon: LucideIcon;
    children: ReactNode;
}) {
    return (
        <div
            role="status"
            className={
                tone === 'warning'
                    ? 'flex items-start gap-2.5 rounded-lg bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning'
                    : 'flex items-start gap-2.5 rounded-lg border bg-muted/40 px-3.5 py-3 text-sm'
            }
        >
            <Icon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p className="min-w-0 break-words">{children}</p>
        </div>
    );
}
