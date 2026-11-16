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
import { approve, reject } from '@/routes/admin/field-trips';

export type FieldTripDecision = {
    kind: 'approve' | 'reject';
    trip: { id: number; number: string; destination: string; who: string };
};

type Props = {
    pending: FieldTripDecision | null;
    onClose: () => void;
};

const COPY = {
    approve: {
        title: 'Setujui tugas',
        description:
            'Tugas langsung berlaku: selama tanggal tugas pegawai boleh presensi sesuai aturan tugas di samping lokasi biasanya.',
        label: 'Catatan (opsional)',
        button: 'Setujui Tugas',
    },
    reject: {
        title: 'Tolak pengajuan',
        description:
            'Pegawai tetap hanya bisa presensi di lokasi biasanya. Tulis alasannya agar pegawai tahu.',
        label: 'Alasan penolakan',
        button: 'Tolak Pengajuan',
    },
} as const;

/**
 * Setujui / Tolak pengajuan tugas luar kota dari Pakis Pro (T-036A bagian 2, 06 §7.2a). Wewenang &
 * bentrok tanggal diperiksa server; galat selain `note` (sudah diputuskan, bentrok) jadi toast.
 */
export function FieldTripDecisionDialog({ pending, onClose }: Props) {
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
                ? approve(pending.trip.id)
                : reject(pending.trip.id);

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
                            'Pengajuan tidak bisa diproses.',
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
                    {copy?.title} {pending?.trip.number}?
                </>
            }
            description={
                <>
                    {pending?.trip.who} · {pending?.trip.destination}.{' '}
                    {copy?.description}
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
                <Label htmlFor="trip-decision-note">{copy?.label}</Label>
                <textarea
                    id="trip-decision-note"
                    className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    value={note}
                    maxLength={255}
                    rows={2}
                    onChange={(e) => setNote(e.target.value)}
                    aria-invalid={error ? true : undefined}
                />
                <InputError message={error} />
            </div>
        </FormDialog>
    );
}
