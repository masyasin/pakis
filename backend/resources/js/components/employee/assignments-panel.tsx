import { useForm } from '@inertiajs/react';
import {
    ArrowRightLeft,
    CalendarX2,
    Network,
    Pencil,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import {
    DetailCard,
    EmptyPanel,
    Timeline,
    TimelineItem,
} from '@/components/employee/detail-ui';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDate } from '@/lib/employee-format';
import { rememberLabels } from '@/lib/lookup';
import { cn } from '@/lib/utils';
import { end, store, update } from '@/routes/admin/assignments';

export type AssignmentRow = {
    id: number;
    position: string | null;
    position_id: number;
    department: string | null;
    location: string | null;
    location_id: number;
    supervisor: { id: number; name: string } | null;
    attendance_mode: Mode | null;
    effective_mode_label: string | null;
    mode_from_position: boolean;
    note: string | null;
    is_primary: boolean;
    start_date: string;
    end_date: string | null;
    running: boolean;
    /** Pelaku boleh mengubah/mengakhiri penugasan ini (lokasinya dalam scope). */
    can_manage?: boolean;
};

export type Mode = 'location' | 'any_location' | 'anywhere';
export type ModeOption = { value: Mode; label: string; help: string };

/** `YYYY-MM-DD` ± n hari, dihitung sebagai tanggal sipil (bebas zona perangkat). */
function shiftDate(iso: string, days: number): string {
    const [y, m, d] = iso.split('-').map(Number);
    const date = new Date(Date.UTC(y, m - 1, d + days));

    return date.toISOString().slice(0, 10);
}

/**
 * Riwayat penugasan (garis waktu) + aksi T-025: Mutasi (BR-052), penugasan tambahan (BR-051),
 * Ubah (atasan/mode/catatan), Akhiri (hanya tambahan — keputusan Mas 15 Nov 2026).
 */
export function AssignmentsPanel({
    employeeId,
    rows,
    canManage,
    modes,
    today,
    backdateDays,
}: {
    employeeId: number;
    rows: AssignmentRow[];
    canManage: boolean;
    modes: ModeOption[];
    today: string;
    backdateDays: number;
}) {
    const [dialog, setDialog] = useState<
        | { kind: 'mutation' | 'extra' }
        | { kind: 'edit' | 'end'; row: AssignmentRow }
        | null
    >(null);
    const close = () => setDialog(null);
    // Penugasan utama yang masih terbuka (bisa terjadwal) = patokan tanggal mutasi.
    const openPrimary = rows.find((r) => r.is_primary && r.end_date === null);
    const minMutation = [
        shiftDate(today, -backdateDays),
        openPrimary ? shiftDate(openPrimary.start_date, 1) : '',
    ].sort()[1];

    const actions = canManage ? (
        <div className="flex flex-wrap gap-2">
            <Button
                size="sm"
                variant="outline"
                onClick={() => setDialog({ kind: 'extra' })}
            >
                <Plus aria-hidden="true" />
                <span className="hidden sm:inline">Penugasan tambahan</span>
                <span className="sm:hidden">Tambahan</span>
            </Button>
            <Button size="sm" onClick={() => setDialog({ kind: 'mutation' })}>
                <ArrowRightLeft aria-hidden="true" />
                Mutasi
            </Button>
        </div>
    ) : undefined;

    return (
        <>
            {rows.length === 0 ? (
                <EmptyPanel
                    icon={Network}
                    title="Belum ada penugasan"
                    text="Penugasan menentukan jabatan, lokasi tugas, dan atasan pegawai."
                    action={actions}
                />
            ) : (
                <DetailCard
                    title="Riwayat penugasan"
                    icon={Network}
                    action={actions}
                >
                    <Timeline>
                        {rows.map((a, i) => (
                            <TimelineItem
                                key={a.id}
                                active={a.running && a.start_date <= today}
                                last={i === rows.length - 1}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-semibold">
                                            {a.position ?? '–'}
                                        </span>
                                        {a.department && (
                                            <span className="text-muted-foreground">
                                                · {a.department}
                                            </span>
                                        )}
                                        {a.is_primary ? (
                                            <Badge variant="secondary">
                                                Utama
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                Tambahan
                                            </Badge>
                                        )}
                                        <RunState row={a} today={today} />
                                    </div>
                                    {canManage &&
                                        a.running &&
                                        a.can_manage !== false && (
                                            <div className="flex gap-1">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-7"
                                                    onClick={() =>
                                                        setDialog({
                                                            kind: 'edit',
                                                            row: a,
                                                        })
                                                    }
                                                    aria-label={`Ubah penugasan ${a.position ?? ''}`}
                                                >
                                                    <Pencil aria-hidden="true" />
                                                    Ubah
                                                </Button>
                                                {!a.is_primary && (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-7 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                        onClick={() =>
                                                            setDialog({
                                                                kind: 'end',
                                                                row: a,
                                                            })
                                                        }
                                                        aria-label={`Akhiri penugasan tambahan ${a.position ?? ''}`}
                                                    >
                                                        <CalendarX2 aria-hidden="true" />
                                                        Akhiri
                                                    </Button>
                                                )}
                                            </div>
                                        )}
                                </div>
                                <dl className="mt-2 grid gap-x-6 gap-y-1 text-muted-foreground sm:grid-cols-2 lg:grid-cols-4">
                                    <Fact label="Lokasi" value={a.location} />
                                    <Fact
                                        label="Atasan"
                                        value={
                                            a.supervisor?.name ?? 'Tidak ada'
                                        }
                                    />
                                    <Fact
                                        label="Mode absen"
                                        value={
                                            a.effective_mode_label
                                                ? `${a.effective_mode_label}${a.mode_from_position ? ' (ikut jabatan)' : ''}`
                                                : null
                                        }
                                    />
                                    <Fact
                                        label="Periode"
                                        value={`${formatDate(a.start_date)} – ${a.end_date ? formatDate(a.end_date) : 'sekarang'}`}
                                    />
                                </dl>
                                {a.note && (
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        Catatan: {a.note}
                                    </p>
                                )}
                            </TimelineItem>
                        ))}
                    </Timeline>
                </DetailCard>
            )}

            {dialog?.kind === 'mutation' || dialog?.kind === 'extra' ? (
                <NewAssignmentDialog
                    kind={dialog.kind}
                    employeeId={employeeId}
                    modes={modes}
                    today={today}
                    minDate={
                        dialog.kind === 'mutation'
                            ? minMutation
                            : shiftDate(today, -backdateDays)
                    }
                    onClose={close}
                />
            ) : null}
            {dialog?.kind === 'edit' && (
                <EditAssignmentDialog
                    row={dialog.row}
                    employeeId={employeeId}
                    modes={modes}
                    onClose={close}
                />
            )}
            {dialog?.kind === 'end' && (
                <EndAssignmentDialog
                    row={dialog.row}
                    today={today}
                    minDate={
                        [
                            dialog.row.start_date,
                            shiftDate(today, -backdateDays),
                        ].sort()[1]
                    }
                    onClose={close}
                />
            )}
        </>
    );
}

function RunState({ row, today }: { row: AssignmentRow; today: string }) {
    if (row.start_date > today) {
        return (
            <StatusBadge
                status="assignment.scheduled"
                label={`Mulai ${formatDate(row.start_date)}`}
                tone="info"
            />
        );
    }

    return row.running ? (
        <StatusBadge
            status="assignment.active"
            label="Berjalan"
            tone="success"
        />
    ) : null;
}

function Fact({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="min-w-0">
            <dt className="text-xs">{label}</dt>
            <dd className="truncate text-foreground/85">{value ?? '–'}</dd>
        </div>
    );
}

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');

/** Pilihan mode absen: "Ikut jabatan" (kosong) + tiga mode, dengan penjelasan. */
export function ModePicker({
    value,
    modes,
    onChange,
    error,
}: {
    value: string;
    modes: ModeOption[];
    onChange: (value: string) => void;
    error?: string;
}) {
    const options = [
        {
            value: '',
            label: 'Ikut jabatan',
            help: 'Memakai mode absen bawaan jabatan.',
        },
        ...modes,
    ];

    return (
        <fieldset className="grid gap-2 sm:grid-cols-2">
            <legend className="mb-1 text-sm font-medium">Mode absen</legend>
            {options.map((m) => (
                <label
                    key={m.value || 'ikut'}
                    className={cn(
                        'flex cursor-pointer gap-3 rounded-lg border p-2.5 text-sm transition-colors has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                        value === m.value &&
                            'border-primary/50 bg-sidebar-accent',
                    )}
                >
                    <input
                        type="radio"
                        name="attendance_mode"
                        className="mt-0.5 accent-primary"
                        checked={value === m.value}
                        onChange={() => onChange(m.value)}
                    />
                    <span>
                        <span className="block font-medium">{m.label}</span>
                        <span className="block text-xs text-muted-foreground">
                            {m.help}
                        </span>
                    </span>
                </label>
            ))}
            <div className="sm:col-span-2">
                <InputError message={error} />
            </div>
        </fieldset>
    );
}

function NewAssignmentDialog({
    kind,
    employeeId,
    modes,
    today,
    minDate,
    onClose,
}: {
    kind: 'mutation' | 'extra';
    employeeId: number;
    modes: ModeOption[];
    today: string;
    minDate: string;
    onClose: () => void;
}) {
    const form = useForm({
        is_primary: kind === 'mutation',
        position_id: '',
        location_id: '',
        supervisor_employee_id: '',
        attendance_mode: '',
        start_date: today < minDate ? minDate : today,
        note: '',
    });
    const err = form.errors as Record<string, string | undefined>;
    const mutation = kind === 'mutation';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(store(employeeId).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogTitle>
                        {mutation ? 'Mutasi pegawai' : 'Penugasan tambahan'}
                    </DialogTitle>
                    <DialogDescription>
                        {mutation
                            ? 'Penugasan utama yang sekarang otomatis berakhir sehari sebelum tanggal mulai di bawah.'
                            : 'Pegawai merangkap di lokasi lain. Penugasan utama tidak berubah.'}
                    </DialogDescription>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="as_position">Jabatan</Label>
                            <AsyncSelect
                                id="as_position"
                                source="positions"
                                value={one(form.data.position_id)}
                                onChange={(v) =>
                                    form.setData('position_id', first(v))
                                }
                            />
                            <InputError message={err.position_id} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="as_location">Lokasi tugas</Label>
                            <AsyncSelect
                                id="as_location"
                                source="locations"
                                value={one(form.data.location_id)}
                                onChange={(v) =>
                                    form.setData('location_id', first(v))
                                }
                            />
                            <InputError message={err.location_id} />
                        </div>
                        <div className="grid content-start gap-2 sm:col-span-2">
                            <Label htmlFor="as_supervisor">
                                Atasan langsung{' '}
                                <span className="font-normal text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <AsyncSelect
                                id="as_supervisor"
                                source="employees"
                                params={{ exclude: String(employeeId) }}
                                value={one(form.data.supervisor_employee_id)}
                                placeholder="Cari nama atau nomor pegawai"
                                onChange={(v) =>
                                    form.setData(
                                        'supervisor_employee_id',
                                        first(v),
                                    )
                                }
                            />
                            <InputError message={err.supervisor_employee_id} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="as_start">Tanggal mulai</Label>
                            <Input
                                id="as_start"
                                type="date"
                                min={minDate}
                                value={form.data.start_date}
                                onChange={(e) =>
                                    form.setData('start_date', e.target.value)
                                }
                                aria-invalid={err.start_date ? true : undefined}
                            />
                            <InputError message={err.start_date} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="as_note">
                                Catatan{' '}
                                <span className="font-normal text-muted-foreground">
                                    (opsional)
                                </span>
                            </Label>
                            <Input
                                id="as_note"
                                value={form.data.note}
                                maxLength={255}
                                placeholder={
                                    mutation
                                        ? 'Mis. promosi'
                                        : 'Mis. bantu toko'
                                }
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                        </div>
                    </div>

                    {mutation && form.data.start_date !== '' && (
                        <p className="rounded-lg bg-status-info-bg px-3 py-2 text-xs text-status-info">
                            Penugasan utama sekarang berakhir{' '}
                            <strong>
                                {formatDate(
                                    shiftDate(form.data.start_date, -1),
                                )}
                            </strong>
                            .
                            {form.data.start_date > today &&
                                ' Sampai tanggal itu pegawai tetap di penugasan lama (mutasi terjadwal).'}
                        </p>
                    )}

                    <ModePicker
                        value={form.data.attendance_mode}
                        modes={modes}
                        onChange={(v) => form.setData('attendance_mode', v)}
                        error={err.attendance_mode}
                    />

                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {mutation ? 'Simpan Mutasi' : 'Simpan Penugasan'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EditAssignmentDialog({
    row,
    employeeId,
    modes,
    onClose,
}: {
    row: AssignmentRow;
    employeeId: number;
    modes: ModeOption[];
    onClose: () => void;
}) {
    // Label atasan terpilih agar select menampilkan nama, bukan ID.
    useState(() =>
        rememberLabels(
            'employees',
            row.supervisor
                ? [{ value: row.supervisor.id, label: row.supervisor.name }]
                : [],
        ),
    );
    const form = useForm({
        supervisor_employee_id: row.supervisor ? String(row.supervisor.id) : '',
        attendance_mode: row.attendance_mode ?? '',
        note: row.note ?? '',
    });
    const err = form.errors as Record<string, string | undefined>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(update(row.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogTitle>Ubah penugasan</DialogTitle>
                    <DialogDescription>
                        {row.position} · {row.location}. Jabatan, lokasi, dan
                        tanggal diubah lewat Mutasi agar riwayatnya utuh.
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor="ea_supervisor">Atasan langsung</Label>
                        <AsyncSelect
                            id="ea_supervisor"
                            source="employees"
                            params={{ exclude: String(employeeId) }}
                            value={one(form.data.supervisor_employee_id)}
                            placeholder="Kosongkan bila tidak ada atasan"
                            onChange={(v) =>
                                form.setData('supervisor_employee_id', first(v))
                            }
                        />
                        <InputError message={err.supervisor_employee_id} />
                    </div>
                    <ModePicker
                        value={form.data.attendance_mode}
                        modes={modes}
                        onChange={(v) => form.setData('attendance_mode', v)}
                        error={err.attendance_mode}
                    />
                    <div className="grid gap-2">
                        <Label htmlFor="ea_note">Catatan</Label>
                        <Input
                            id="ea_note"
                            value={form.data.note}
                            maxLength={255}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Simpan
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function EndAssignmentDialog({
    row,
    today,
    minDate,
    onClose,
}: {
    row: AssignmentRow;
    today: string;
    minDate: string;
    onClose: () => void;
}) {
    const form = useForm({ end_date: today < minDate ? minDate : today });
    const err = form.errors as Record<string, string | undefined>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(end(row.id).url, {
            preserveScroll: true,
            onSuccess: onClose,
        });
    };

    return (
        <Dialog open onOpenChange={(v) => !v && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogTitle>Akhiri penugasan tambahan</DialogTitle>
                    <DialogDescription>
                        {row.position} · {row.location}. Setelah tanggal ini
                        pegawai tidak lagi merangkap di lokasi tersebut.
                    </DialogDescription>
                    <div className="grid gap-2">
                        <Label htmlFor="end_date">Tanggal terakhir</Label>
                        <Input
                            id="end_date"
                            type="date"
                            min={minDate}
                            value={form.data.end_date}
                            onChange={(e) =>
                                form.setData('end_date', e.target.value)
                            }
                            aria-invalid={err.end_date ? true : undefined}
                        />
                        <InputError message={err.end_date} />
                    </div>
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {form.processing && <Spinner />}
                            Akhiri
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
