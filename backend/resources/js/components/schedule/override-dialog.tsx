import { router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
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
import { useShiftOptions } from '@/hooks/use-shift-options';
import { formatCivilDate } from '@/lib/local-date';
import { rememberLabels } from '@/lib/lookup';
import type { EmployeeRef } from '@/lib/schedule';
import { cn } from '@/lib/utils';
import { destroy, store } from '@/routes/admin/schedule-overrides';

export type OverrideTarget = {
    /** Pegawai tetap (sel kalender / detail pegawai); null = dipilih lewat pencarian (massal). */
    employee: EmployeeRef | null;
    /** Tanggal tetap (sel kalender); null = diisi pengguna. */
    date: string | null;
    /** Pengecualian yang sudah ada pada tanggal itu (bisa dihapus). */
    existing: {
        id: number;
        shiftId: number | null;
        note: string | null;
    } | null;
    /** Ringkasan jadwal saat ini, mis. "Shift PAGI (Pagi) · pola". */
    current?: string | null;
};

type Props = {
    target: OverrideTarget | null;
    onClose: () => void;
    /** Tanggal awal untuk mode massal (hari ini di zona kantor). */
    defaultDate: string;
};

/**
 * Dialog pengecualian jadwal (FR-032, BR-061/BR-062): ganti shift atau libur pada satu tanggal,
 * untuk satu pegawai (klik sel kalender) atau banyak sekaligus (halaman Pengecualian).
 */
export function OverrideDialog({ target, onClose, defaultDate }: Props) {
    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent>
                {target && (
                    <OverrideForm
                        key={`${target.employee?.id ?? 'massal'}:${target.date ?? ''}`}
                        target={target}
                        onClose={onClose}
                        defaultDate={defaultDate}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function OverrideForm({
    target,
    onClose,
    defaultDate,
}: {
    target: OverrideTarget;
    onClose: () => void;
    defaultDate: string;
}) {
    const { employee, existing } = target;
    const shifts = useShiftOptions(true);
    const [deleting, setDeleting] = useState(false);
    useState(() => {
        if (employee) {
            rememberLabels('employees', [
                { value: employee.id, label: employee.name },
            ]);
        }
    });

    const form = useForm({
        employee_ids: employee ? [employee.id] : ([] as number[]),
        date: target.date ?? defaultDate,
        mode: existing && existing.shiftId === null ? 'off' : 'shift',
        shift_id: existing?.shiftId ? String(existing.shiftId) : '',
        note: existing?.note ?? '',
    });
    const { data, errors } = form;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            employee_ids: d.employee_ids,
            date: d.date,
            // "Libur" = shift kosong (BR-061: override tanpa shift).
            shift_id: d.mode === 'off' ? '' : d.shift_id,
            note: d.note,
        }));
        form.submit(store(), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    const remove = () => {
        if (!existing) {
            return;
        }
        router.delete(destroy(existing.id).url, {
            preserveScroll: true,
            onStart: () => setDeleting(true),
            onSuccess: () => onClose(),
            onError: () => toast.error('Pengecualian tidak bisa dihapus.'),
            onFinish: () => setDeleting(false),
        });
    };

    const title = existing
        ? 'Ubah pengecualian jadwal'
        : 'Tambah pengecualian jadwal';

    return (
        <form onSubmit={submit} noValidate className="grid gap-4">
            <div className="grid gap-1">
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>
                    {employee && target.date ? (
                        <>
                            {employee.name} · {formatCivilDate(target.date)}
                            {target.current && (
                                <span className="block text-xs">
                                    Saat ini: {target.current}
                                </span>
                            )}
                        </>
                    ) : (
                        'Ganti shift atau liburkan pegawai pada satu tanggal. Pengecualian menang atas pola; menetapkan shift di hari libur berarti pegawai tetap masuk.'
                    )}
                </DialogDescription>
            </div>

            {!target.date && (
                <div className="grid gap-2">
                    <Label htmlFor="override-date">Tanggal</Label>
                    <Input
                        id="override-date"
                        type="date"
                        value={data.date}
                        required
                        onChange={(e) => form.setData('date', e.target.value)}
                        aria-invalid={errors.date ? true : undefined}
                    />
                    <InputError message={errors.date} />
                </div>
            )}

            {!employee && (
                <div className="grid gap-2">
                    <Label htmlFor="override-employees">Pegawai</Label>
                    <AsyncSelect
                        id="override-employees"
                        source="employees"
                        multiple
                        value={data.employee_ids}
                        onChange={(value) =>
                            form.setData(
                                'employee_ids',
                                value.map((v) => Number(v)),
                            )
                        }
                        placeholder="Ketik nama atau nomor pegawai…"
                        aria-label="Pegawai"
                    />
                    <InputError message={errors.employee_ids} />
                </div>
            )}

            <fieldset className="grid gap-2">
                <legend className="mb-2 text-sm font-medium">Jadwal</legend>
                <div className="grid grid-cols-2 gap-2">
                    {(
                        [
                            { value: 'shift', label: 'Ganti shift' },
                            { value: 'off', label: 'Libur' },
                        ] as const
                    ).map((option) => (
                        <label
                            key={option.value}
                            className={cn(
                                'flex min-h-10 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm transition-colors',
                                data.mode === option.value
                                    ? 'border-primary bg-primary/5 font-medium'
                                    : 'hover:border-primary/60',
                            )}
                        >
                            <input
                                type="radio"
                                name="override-mode"
                                value={option.value}
                                checked={data.mode === option.value}
                                onChange={() =>
                                    form.setData('mode', option.value)
                                }
                                className="accent-primary"
                            />
                            {option.label}
                        </label>
                    ))}
                </div>
                {data.mode === 'shift' && (
                    <div className="grid gap-2">
                        <Label htmlFor="override-shift" className="sr-only">
                            Shift pengganti
                        </Label>
                        <NativeSelect
                            id="override-shift"
                            value={data.shift_id}
                            required
                            disabled={shifts.loading}
                            onChange={(e) =>
                                form.setData('shift_id', e.target.value)
                            }
                            aria-invalid={errors.shift_id ? true : undefined}
                        >
                            <option value="">
                                {shifts.loading
                                    ? 'Memuat shift…'
                                    : 'Pilih shift'}
                            </option>
                            {shifts.options.map((o) => (
                                <option key={o.value} value={String(o.value)}>
                                    {o.label}
                                    {o.hint ? ` (${o.hint})` : ''}
                                </option>
                            ))}
                        </NativeSelect>
                        {shifts.failed && (
                            <p className="text-sm text-destructive">
                                Daftar shift gagal dimuat. Tutup lalu buka lagi
                                dialog ini.
                            </p>
                        )}
                        <InputError message={errors.shift_id} />
                    </div>
                )}
            </fieldset>

            <div className="grid gap-2">
                <Label htmlFor="override-note">
                    Catatan{' '}
                    <span className="text-muted-foreground">(opsional)</span>
                </Label>
                <Input
                    id="override-note"
                    value={data.note}
                    maxLength={255}
                    autoComplete="off"
                    placeholder="Mis. tukar shift, stok opname, izin"
                    onChange={(e) => form.setData('note', e.target.value)}
                    aria-invalid={errors.note ? true : undefined}
                />
                <InputError message={errors.note} />
            </div>

            <DialogFooter className="gap-2 sm:justify-between">
                {existing ? (
                    <Button
                        type="button"
                        variant="ghost"
                        className="text-destructive hover:text-destructive"
                        disabled={deleting || form.processing}
                        onClick={remove}
                    >
                        {deleting ? <Spinner /> : <Trash2 aria-hidden="true" />}
                        Hapus pengecualian
                    </Button>
                ) : (
                    <span className="hidden sm:block" />
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            deleting ||
                            (data.mode === 'shift' && data.shift_id === '')
                        }
                    >
                        {form.processing && <Spinner />}
                        Simpan
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
