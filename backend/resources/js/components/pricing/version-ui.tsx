import { router, usePage } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDateTimeIn } from '@/lib/approval-format';

export type PriceVersion = {
    id: number;
    version_no: number;
    effective_from: string;
    effective_to: string | null;
    status:
        | 'draft'
        | 'pending_approval'
        | 'published'
        | 'superseded'
        | 'rejected';
    status_label: string;
    note: string | null;
    items_count: number;
    published_at: string | null;
    approval_request_id: number | null;
};

const TONE = {
    draft: 'muted',
    pending_approval: 'warning',
    published: 'success',
    superseded: 'muted',
    rejected: 'danger',
} as const;

export function VersionBadge({
    version,
    current = false,
}: {
    version: PriceVersion;
    current?: boolean;
}) {
    return current ? (
        <StatusBadge status="current" label="Berlaku sekarang" tone="success" />
    ) : (
        <StatusBadge
            status={version.status}
            label={version.status_label}
            tone={TONE[version.status]}
        />
    );
}

/** "16 Nov 2026 10.00 – 1 Des 2026 00.00" di zona kantor; tanpa akhir = "sampai versi berikutnya". */
export function VersionPeriod({ version }: { version: PriceVersion }) {
    const timezone = usePage().props.timezone;

    return (
        <span>
            {formatDateTimeIn(version.effective_from, timezone)}
            <span className="text-muted-foreground">
                {' – '}
                {version.effective_to
                    ? formatDateTimeIn(version.effective_to, timezone)
                    : 'sampai ada versi berikutnya'}
            </span>
        </span>
    );
}

/**
 * Ajukan / terbitkan versi draf (BR-168, BR-153). Pemegang `prices.publish` langsung menerbitkan;
 * selain itu menjadi permintaan persetujuan. Alasan hanya diminta bila versi berlaku surut dan
 * pengaturan mengizinkan.
 */
export function SubmitVersionDialog({
    version,
    canPublish,
    canBackdate,
    onClose,
}: {
    version: PriceVersion;
    canPublish: boolean;
    canBackdate: boolean;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);
    const backdated = new Date(version.effective_from).getTime() < Date.now();

    const submit = (event: FormEvent) => {
        event.preventDefault();
        router.post(
            `/daftar-harga/versi/${version.id}/ajukan`,
            { backdate_reason: reason },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Send}
            title={
                canPublish
                    ? `Terbitkan versi ${version.version_no}?`
                    : `Ajukan versi ${version.version_no}?`
            }
            description={
                canPublish
                    ? 'Setelah terbit, harga di versi ini tidak bisa diubah lagi. Perubahan berikutnya dibuat sebagai versi baru.'
                    : 'Versi dikirim ke pemegang izin terbit harga untuk disetujui. Selama menunggu, harga tidak bisa diubah.'
            }
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {canPublish ? 'Terbitkan Versi' : 'Ajukan Versi'}
                    </Button>
                </>
            }
        >
            <FormStatus
                tone="error"
                message={errors.version ?? errors.effective_from}
            />
            {backdated && canBackdate && (
                <div className="grid gap-2">
                    <Label htmlFor="backdate-reason">
                        Alasan berlaku surut
                    </Label>
                    <Input
                        id="backdate-reason"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                        maxLength={200}
                    />
                    <p className="text-xs text-muted-foreground">
                        Tanggal berlaku versi ini sudah lewat. Alasan tercatat
                        di catatan versi dan riwayat audit.
                    </p>
                    <InputError message={errors.backdate_reason} />
                </div>
            )}
            {backdated && !canBackdate && (
                <p className="text-sm text-muted-foreground">
                    Tanggal berlaku sudah lewat. Buat versi baru dengan tanggal
                    yang akan datang.
                </p>
            )}
        </FormDialog>
    );
}
