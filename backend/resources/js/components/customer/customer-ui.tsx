import { router } from '@inertiajs/react';
import { ImageOff, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import type { StatusTone } from '@/components/pakis/status-badge';
import { formatPhone } from '@/lib/employee-format';
import { cn } from '@/lib/utils';

/** Nilai `customers.status` (15 §4.6); label datang dari server (`status_label`). */
export type CustomerStatus =
    | 'prospect'
    | 'active'
    | 'credit_hold'
    | 'inactive'
    | 'merged';

export const CUSTOMER_STATUS_TONE: Record<CustomerStatus, StatusTone> = {
    prospect: 'info',
    active: 'success',
    credit_hold: 'warning',
    inactive: 'muted',
    merged: 'muted',
};

/** Nilai `customer_verifications.status` (15 §4.6, T-074); label dari server (`status_label`). */
export type VerificationStatus =
    | 'pending'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export const VERIFICATION_STATUS_TONE: Record<VerificationStatus, StatusTone> =
    {
        pending: 'warning',
        approved: 'success',
        rejected: 'danger',
        cancelled: 'muted',
    };

/** Satu baris verifikasi dari server (`VerificationState::row`). */
export type VerificationRow = {
    id: number;
    type: 'activation' | 'phone_change';
    type_label: string;
    method: 'otp' | 'office_call';
    method_label: string;
    status: VerificationStatus;
    status_label: string;
    /** Rupiah kanonik ("5000000.00"). */
    requested_credit_limit: string;
    office_call_note: string | null;
    approval_request_id: number | null;
    /** URL bertanda tangan 5 menit (SEC-108); null = tanpa foto. Selalu dibaca dari props terbaru. */
    photo_url: string | null;
    submitted_by: { id: number; name: string } | null;
    created_at: string | null;
};

type UserRef = { id: number; name: string } | null;
type CustomerRef = { id: number; code: string; store_name: string };

/** Ringkasan permintaan approval yang masih menunggu (`CustomerChangeState::approvalRef`). */
export type PendingApproval = {
    approval_request_id: number;
    /** Nominal aturan (limit yang diajukan), rupiah kanonik; null bila bukan nominal. */
    amount: string | null;
    requested_by: UserRef;
    created_at: string | null;
};

export type ContactRow = {
    id: number;
    name: string;
    role: string | null;
    /** Sudah termasking dari server bila tanpa `customers.manage` (SEC-108). */
    phone: string | null;
};

export type StatusActionValue =
    | 'hold'
    | 'release'
    | 'deactivate'
    | 'reactivate';

/** Prop `changes` detail kios (`CustomerChangeState::for`, T-074 bagian 2). */
export type ChangeState = {
    contacts: { can_manage: boolean; rows: ContactRow[] };
    phone_change: {
        can_request: boolean;
        otp_available: boolean;
        otp_length: number;
        pending: {
            id: number;
            approval_request_id: number | null;
            method: 'otp' | 'office_call';
            method_label: string;
            phone_previous: string | null;
            phone_target: string | null;
            submitted_by: UserRef;
            created_at: string | null;
        } | null;
    };
    credit_limit: {
        can_request: boolean;
        blocked_reason: 'not_verified' | null;
        pending: PendingApproval | null;
    };
    merge: {
        can_request: boolean;
        pending: (PendingApproval & { target: CustomerRef | null }) | null;
        merged_into: CustomerRef | null;
    };
    status: {
        hold_reason: string | null;
        actions: { value: StatusActionValue; label: string }[];
    };
};

/**
 * HP pemilik kios. Server sudah memutuskan masking (SEC-108: penuh hanya untuk `customers.manage`),
 * jadi nilai termasking (`6281•••••789`) ditampilkan apa adanya tanpa tombol buka; nilai penuh
 * diformat `0812-3456-7890` agar mudah dibaca.
 */
export function CustomerPhone({
    phone,
    masked,
    className,
}: {
    phone: string | null;
    masked: boolean;
    className?: string;
}) {
    if (phone === null || phone === '') {
        return (
            <span className={cn('text-muted-foreground', className)}>–</span>
        );
    }

    return (
        <span className={cn('font-mono tabular-nums', className)}>
            {masked ? phone : formatPhone(phone)}
        </span>
    );
}

/**
 * Foto berkas privat lewat URL bertanda tangan 5 menit (SEC-108/109): thumbnail yang membuka foto
 * penuh di tab baru. URL selalu dari props render ini; galat muat (tautan sudah lewat 5 menit)
 * dicatat per URL, jadi props baru setelah "Muat ulang" langsung dicoba lagi.
 */
export function SignedPhoto({
    url,
    alt,
    emptyText = 'Belum ada foto.',
    className,
}: {
    url: string | null;
    alt: string;
    emptyText?: string;
    className?: string;
}) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    const box = cn(
        'grid aspect-[4/3] w-full max-w-64 place-items-center overflow-hidden rounded-lg border bg-muted/30 text-center text-xs text-muted-foreground',
        className,
    );

    if (url === null) {
        return (
            <div className={box}>
                <span className="grid justify-items-center gap-1 p-3">
                    <ImageOff className="size-5" aria-hidden="true" />
                    {emptyText}
                </span>
            </div>
        );
    }

    if (failedUrl === url) {
        return (
            <div className={box}>
                <span className="grid justify-items-center gap-2 p-3">
                    <ImageOff className="size-5" aria-hidden="true" />
                    Tautan foto sudah kedaluwarsa.
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 font-medium text-primary hover:underline"
                        onClick={() => router.reload()}
                    >
                        <RefreshCw className="size-3.5" aria-hidden="true" />
                        Muat ulang
                    </button>
                </span>
            </div>
        );
    }

    return (
        <a
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className={cn(box, 'group bg-muted')}
            title="Buka foto di tab baru"
        >
            <img
                src={url}
                alt={alt}
                loading="lazy"
                referrerPolicy="no-referrer"
                className="size-full object-cover transition-opacity group-hover:opacity-90"
                onError={() => setFailedUrl(url)}
            />
        </a>
    );
}
