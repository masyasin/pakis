import { router } from '@inertiajs/react';
import { Ban, CircleCheck, CircleX } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { approve, cancel, reject } from '@/routes/admin/approvals';

export type ApprovalDecisionKind = 'approve' | 'reject' | 'cancel';

/** Bagian permintaan yang dibutuhkan dialog (sama di kartu kotak masuk & halaman detail). */
export type ApprovalDecisionTarget = {
    id: number;
    document_type_label: string;
    summary: string;
    amount_label: string | null;
    current_step: number;
    steps_total: number;
    requires_password: boolean;
    /**
     * PA-38: pembatalan oleh pemegang `approvals.configure` setelah ada keputusan wajib beralasan.
     * Belum dikirim server = false.
     */
    cancel_requires_note?: boolean;
};

export type ApprovalDecision = {
    kind: ApprovalDecisionKind;
    approval: ApprovalDecisionTarget;
};

type Props = {
    pending: ApprovalDecision | null;
    onClose: () => void;
    /** Label `approval.reauth_amount` ("Rp. 25.000.000") untuk menjelaskan kenapa password diminta. */
    reauthAmount: string | null;
};

const COPY = {
    approve: {
        title: 'Setujui permintaan',
        note: 'Catatan (opsional)',
        button: 'Setujui',
    },
    reject: {
        title: 'Tolak permintaan',
        note: 'Alasan penolakan',
        button: 'Tolak Permintaan',
    },
    cancel: {
        title: 'Batalkan permintaan',
        note: 'Alasan pembatalan',
        button: 'Batalkan Permintaan',
    },
} as const;

const NOTE_MAX = 500;

/**
 * Setujui / Tolak / Batalkan permintaan persetujuan (15 §5, BR-153, SEC-101). `step` = langkah yang
 * dilihat pengguna, sehingga keputusan atas langkah yang sudah berganti ditolak server (409).
 * Bila nominal ≥ `approval.reauth_amount` dan password belum dikonfirmasi baru-baru ini, password
 * dikirim BERSAMA keputusan (satu form), bukan lewat ConfirmPasswordDialog yang hanya mengirim
 * password. Galat field tampil di sini; galat `form` (APPROVAL_SELF_DECISION, STATE_CONFLICT)
 * sudah menjadi toast dari server (PA-16), jadi dialog cukup ditutup — tidak ada toast kedua.
 */
export function ApprovalDecisionDialog({
    pending,
    onClose,
    reauthAmount,
}: Props) {
    const [note, setNote] = useState('');
    const [password, setPassword] = useState('');
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [processing, setProcessing] = useState(false);
    const kind = pending?.kind ?? 'approve';
    const copy = COPY[kind];
    const approval = pending?.approval;
    const needsPassword =
        kind !== 'cancel' && (approval?.requires_password ?? false);
    const cancelNote =
        kind === 'cancel' && (approval?.cancel_requires_note ?? false);
    const showNote = kind !== 'cancel' || cancelNote;
    const noteRequired = kind === 'reject' || cancelNote;

    const close = () => {
        setNote('');
        setPassword('');
        setErrors({});
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!pending || !approval) {
            return;
        }
        const target =
            kind === 'approve'
                ? approve(approval.id)
                : kind === 'reject'
                  ? reject(approval.id)
                  : cancel(approval.id);
        const data =
            kind === 'cancel'
                ? cancelNote
                    ? { note }
                    : {}
                : {
                      step: approval.current_step,
                      note: note.trim() === '' ? null : note,
                      ...(needsPassword ? { current_password: password } : {}),
                  };

        router.post(target.url, data, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onSuccess: close,
            onError: (errs) => {
                const fields = {
                    note: errs.note,
                    current_password: errs.current_password,
                    step: errs.step,
                };
                if (fields.note || fields.current_password || fields.step) {
                    setErrors(fields);
                    if (fields.current_password) {
                        setPassword('');
                    }

                    return;
                }
                // `errors.form` & galat lain: toast sudah dari server.
                close();
            },
            onFinish: () => setProcessing(false),
        });
    };

    const description = approval ? (
        <>
            {approval.document_type_label} · {approval.summary}
            {approval.amount_label ? ` · ${approval.amount_label}` : ''}.{' '}
            {kind === 'cancel'
                ? cancelNote
                    ? 'Permintaan berhenti walau sebagian langkah sudah diputuskan. Alasan wajib dan tercatat di audit.'
                    : 'Permintaan berhenti dan dokumen kembali ke pengaju. Hanya bisa selama belum ada langkah yang diputuskan.'
                : `Langkah ${approval.current_step} dari ${approval.steps_total}.`}
        </>
    ) : undefined;

    return (
        <FormDialog
            open={pending !== null}
            onOpenChange={(isOpen) => !isOpen && close()}
            icon={
                kind === 'approve'
                    ? CircleCheck
                    : kind === 'reject'
                      ? CircleX
                      : Ban
            }
            tone={kind === 'approve' ? 'default' : 'danger'}
            title={`${copy.title}?`}
            description={description}
            onSubmit={submit}
            noValidate
            // Batal tanpa isian: badan kosong disembunyikan agar tidak ada ruang kosong.
            bodyClassName={showNote ? undefined : 'hidden'}
            footer={
                <>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {kind === 'cancel' ? 'Tidak' : 'Batal'}
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        variant={kind === 'approve' ? 'default' : 'destructive'}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {copy.button}
                    </Button>
                </>
            }
        >
            {showNote && (
                <div className="grid gap-2">
                    <Label htmlFor="approval-decision-note">{copy.note}</Label>
                    <textarea
                        id="approval-decision-note"
                        className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        value={note}
                        maxLength={NOTE_MAX}
                        rows={3}
                        required={noteRequired}
                        placeholder={
                            kind === 'reject'
                                ? 'Minimal 5 karakter, agar pengaju tahu yang perlu diperbaiki'
                                : noteRequired
                                  ? 'Minimal 5 karakter'
                                  : undefined
                        }
                        onChange={(e) => setNote(e.target.value)}
                        aria-invalid={errors.note ? true : undefined}
                    />
                    <InputError message={errors.note} />
                </div>
            )}

            {needsPassword && (
                <div className="grid gap-2">
                    <Label htmlFor="approval-decision-password">
                        Password Anda
                    </Label>
                    <p className="text-xs text-muted-foreground">
                        Nominal {reauthAmount ? `≥ ${reauthAmount}` : 'besar'}:
                        konfirmasi dengan password akun Anda.
                    </p>
                    <Input
                        id="approval-decision-password"
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        aria-invalid={
                            errors.current_password ? true : undefined
                        }
                    />
                    <InputError message={errors.current_password} />
                </div>
            )}

            <InputError message={errors.step} />
        </FormDialog>
    );
}
