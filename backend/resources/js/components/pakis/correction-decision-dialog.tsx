import { router } from '@inertiajs/react';
import { ClipboardCheck, ClipboardX } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { approve, reject } from '@/routes/admin/attendance-corrections';

export type CorrectionDecision = {
    kind: 'approve' | 'reject';
    correction: { id: number; number: string; employee: { name: string } };
};

type Props = {
    pending: CorrectionDecision | null;
    onClose: () => void;
};

const COPY = {
    approve: {
        title: 'Setujui koreksi',
        description:
            'Jam presensi pegawai diganti dengan jam usulan dan statusnya dihitung ulang. Log presensi asli tetap tersimpan.',
        label: 'Catatan (opsional)',
        button: 'Setujui Koreksi',
    },
    reject: {
        title: 'Tolak koreksi',
        description:
            'Presensi pegawai tidak berubah. Tulis alasannya agar pegawai tahu.',
        label: 'Alasan penolakan',
        button: 'Tolak Koreksi',
    },
} as const;

/** Setujui / Tolak koreksi (07 §4 "Koreksi (detail)"; alasan wajib saat menolak, BR-069). */
export function CorrectionDecisionDialog({ pending, onClose }: Props) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const copy = pending ? COPY[pending.kind] : null;

    const close = () => {
        setNote('');
        setError(undefined);
        onClose();
    };

    const submit = () => {
        if (!pending) {
            return;
        }
        const target =
            pending.kind === 'approve'
                ? approve(pending.correction.id)
                : reject(pending.correction.id);

        router.post(
            target.url,
            { note },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onSuccess: close,
                onError: (errors) => {
                    if (errors.note) {
                        setError(errors.note);

                        return;
                    }
                    toast.error(
                        Object.values(errors)[0] ??
                            'Koreksi tidak bisa diproses.',
                    );
                    close();
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <FormDialog
            open={pending !== null}
            onOpenChange={(isOpen) => !isOpen && close()}
            icon={pending?.kind === 'reject' ? ClipboardX : ClipboardCheck}
            tone={pending?.kind === 'reject' ? 'danger' : 'default'}
            title={
                <>
                    {copy?.title} {pending?.correction.number}?
                </>
            }
            description={
                <>
                    {pending?.correction.employee.name}. {copy?.description}
                </>
            }
            footer={
                <>
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button
                        variant={
                            pending?.kind === 'approve'
                                ? 'default'
                                : 'destructive'
                        }
                        disabled={processing}
                        onClick={submit}
                    >
                        {processing && <Spinner />}
                        {copy?.button}
                    </Button>
                </>
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="decision-note">{copy?.label}</Label>
                <textarea
                    id="decision-note"
                    className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    value={note}
                    maxLength={500}
                    rows={2}
                    onChange={(e) => setNote(e.target.value)}
                    aria-invalid={error ? true : undefined}
                />
                <InputError message={error} />
            </div>
        </FormDialog>
    );
}
