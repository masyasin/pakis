import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { NativeSelect } from '@/components/pakis/native-select';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { rememberLabels } from '@/lib/lookup';
import { manual } from '@/routes/admin/attendance-corrections';

/** Data form dari server (`AttendanceCorrectionController::manualProps`). */
export type ManualAttendanceOptions = {
    reasons: { value: string; label: string }[];
    min_date: string;
    max_date: string;
};

type Preset = {
    employee: { id: number; label: string };
    work_date: string;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: ManualAttendanceOptions;
    /** Isian awal dari detail presensi: pegawai & tanggal terkunci. */
    preset?: Preset;
};

const TYPES = [
    { value: 'both', label: 'Masuk & pulang' },
    { value: 'check_in', label: 'Masuk saja' },
    { value: 'check_out', label: 'Pulang saja' },
] as const;

const textareaClass =
    'min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

/**
 * "Isi presensi manual" (keputusan Mas 16 Nov 2026, BR-069): admin/atasan mengisi jam masuk/pulang
 * pegawai yang tidak bisa presensi dari HP. Hasilnya koreksi yang langsung disetujui; semua aturan
 * (batas tanggal, jendela shift, wewenang 06 §6) diperiksa server.
 */
export function ManualAttendanceDialog({
    open,
    onOpenChange,
    options,
    preset,
}: Props) {
    const form = useForm({
        employee_id: preset?.employee.id ?? (null as number | null),
        work_date: preset?.work_date ?? options.max_date,
        type: 'both' as (typeof TYPES)[number]['value'],
        proposed_check_in: '',
        proposed_check_out: '',
        reason_code: options.reasons[0]?.value ?? '',
        note: '',
    });
    const { data, errors } = form;

    useEffect(() => {
        if (preset) {
            rememberLabels('employees', [
                { value: preset.employee.id, label: preset.employee.label },
            ]);
        }
    }, [preset]);

    const close = (next: boolean) => {
        if (!next) {
            form.reset();
            form.clearErrors();
        }
        onOpenChange(next);
    };

    const submit = () => {
        form.post(manual().url, {
            preserveScroll: true,
            onSuccess: () => close(false),
        });
    };

    const showIn = data.type !== 'check_out';
    const showOut = data.type !== 'check_in';

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <DialogTitle>Isi presensi manual</DialogTitle>
                <DialogDescription>
                    Untuk pegawai yang tidak bisa presensi dari HP (rusak,
                    hilang, ketinggalan). Jam langsung tercatat sebagai koreksi
                    yang disetujui atas nama Anda.
                </DialogDescription>

                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="manual-employee">Pegawai</Label>
                        <AsyncSelect
                            id="manual-employee"
                            source="employees"
                            value={
                                data.employee_id === null
                                    ? []
                                    : [data.employee_id]
                            }
                            onChange={(value) =>
                                form.setData(
                                    'employee_id',
                                    value.length ? Number(value[0]) : null,
                                )
                            }
                            disabled={preset !== undefined}
                            placeholder="Ketik nama atau nomor pegawai…"
                            aria-label="Pegawai"
                        />
                        <InputError message={errors.employee_id} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="manual-date">Tanggal</Label>
                            <Input
                                id="manual-date"
                                type="date"
                                min={options.min_date}
                                max={options.max_date}
                                value={data.work_date}
                                disabled={preset !== undefined}
                                onChange={(e) =>
                                    form.setData('work_date', e.target.value)
                                }
                                aria-invalid={
                                    errors.work_date ? true : undefined
                                }
                            />
                            <InputError message={errors.work_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="manual-type">Yang diisi</Label>
                            <NativeSelect
                                id="manual-type"
                                value={data.type}
                                onChange={(e) =>
                                    form.setData(
                                        'type',
                                        e.target
                                            .value as (typeof TYPES)[number]['value'],
                                    )
                                }
                            >
                                {TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.type} />
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        {showIn && (
                            <div className="grid gap-2">
                                <Label htmlFor="manual-in">Jam masuk</Label>
                                <Input
                                    id="manual-in"
                                    type="time"
                                    value={data.proposed_check_in}
                                    onChange={(e) =>
                                        form.setData(
                                            'proposed_check_in',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.proposed_check_in
                                            ? true
                                            : undefined
                                    }
                                />
                                <InputError
                                    message={errors.proposed_check_in}
                                />
                            </div>
                        )}
                        {showOut && (
                            <div className="grid gap-2">
                                <Label htmlFor="manual-out">Jam pulang</Label>
                                <Input
                                    id="manual-out"
                                    type="time"
                                    value={data.proposed_check_out}
                                    onChange={(e) =>
                                        form.setData(
                                            'proposed_check_out',
                                            e.target.value,
                                        )
                                    }
                                    aria-invalid={
                                        errors.proposed_check_out
                                            ? true
                                            : undefined
                                    }
                                />
                                <InputError
                                    message={errors.proposed_check_out}
                                />
                            </div>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="manual-reason">Alasan</Label>
                        <NativeSelect
                            id="manual-reason"
                            value={data.reason_code}
                            onChange={(e) =>
                                form.setData('reason_code', e.target.value)
                            }
                        >
                            {options.reasons.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.reason_code} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="manual-note">Catatan</Label>
                        <textarea
                            id="manual-note"
                            className={textareaClass}
                            rows={2}
                            maxLength={500}
                            value={data.note}
                            placeholder="Mis. HP pegawai rusak, presensi dicatat di pos satpam."
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                            aria-invalid={errors.note ? true : undefined}
                        />
                        <InputError message={errors.note} />
                    </div>
                </div>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="outline">Batal</Button>
                    </DialogClose>
                    <Button disabled={form.processing} onClick={submit}>
                        {form.processing && <Spinner />}
                        Simpan Presensi
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
