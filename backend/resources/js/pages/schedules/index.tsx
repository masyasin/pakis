import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CalendarClock, CalendarPlus, Copy } from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import {
    FormDialog,
    FormDialogBody,
    FormDialogFooter,
    FormDialogForm,
} from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { ScheduleTabs } from '@/components/schedule/schedule-tabs';
import { DayOffLabel, ShiftChip } from '@/components/schedule/shift-chip';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogClose } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { useShiftOptions } from '@/hooks/use-shift-options';
import { todayIn } from '@/lib/employee-format';
import { formatCivilDate } from '@/lib/local-date';
import { formatNumber } from '@/lib/listing-query';
import { DAYS, summarizePattern } from '@/lib/schedule';
import type {
    DayKey,
    EmployeeRef,
    PatternDays,
    ShiftSummary,
} from '@/lib/schedule';
import { cn } from '@/lib/utils';
import { show as showEmployee } from '@/routes/admin/employees';
import { index } from '@/routes/admin/schedules';
import { store as storePatterns } from '@/routes/admin/schedules/patterns';
import type { ListingProps } from '@/types/listing';

type PatternRow = {
    id: number;
    employee: EmployeeRef;
    location: string | null;
    position: string | null;
    effective_from: string | null;
    days: PatternDays | null;
};

type Props = ListingProps<PatternRow> & {
    can: { manage: boolean };
    selectionLimit: number;
    /** Hanya ada setelah partial reload `only: ['filteredIds']`; null = melebihi batas. */
    filteredIds?: number[] | null;
};

function PatternCells({ days }: { days: PatternDays | null }) {
    if (!days) {
        return (
            <span className="text-sm text-muted-foreground">
                Belum ada pola
            </span>
        );
    }

    return (
        <>
            {/* HP (kartu): ringkasan satu-dua baris; layar lebar: tujuh kolom hari. */}
            <span className="text-sm md:hidden">{summarizePattern(days)}</span>
            <ul
                className="hidden flex-wrap gap-x-3 gap-y-1 md:flex"
                aria-label="Pola mingguan"
            >
                {DAYS.map((d) => (
                    <li key={d.key} className="grid min-w-9 gap-0.5">
                        <span className="text-[0.7rem] text-muted-foreground">
                            {d.short}
                        </span>
                        <DayCell shift={days[d.key]} />
                    </li>
                ))}
            </ul>
        </>
    );
}

function DayCell({ shift }: { shift: ShiftSummary | null }) {
    return shift ? <ShiftChip shift={shift} /> : <DayOffLabel />;
}

/** Jadwal · Pola (05 §4.9, 07 §3.4 `schedules`, FR-032, BR-061). */
export default function SchedulesIndex({
    rows,
    listing,
    can,
    selectionLimit,
    filteredIds,
}: Props) {
    const [selected, setSelected] = useState<Set<number>>(new Set());
    useBreadcrumbTail([{ title: 'Pola', href: '#' }]);
    const [dialogOpen, setDialogOpen] = useState(false);

    const toggle = (id: number, on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const pageIds = rows.map((r) => r.id);
    const pageAllSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const columns: DataTableColumn<PatternRow>[] = [
        ...(can.manage
            ? [
                  {
                      key: 'select',
                      header: 'Pilih',
                      className: 'w-12',
                      cell: (r: PatternRow) => (
                          <Checkbox
                              checked={selected.has(r.id)}
                              onCheckedChange={(v) => toggle(r.id, v === true)}
                              onClick={(e) => e.stopPropagation()}
                              aria-label={`Pilih ${r.employee.name}`}
                          />
                      ),
                  },
              ]
            : []),
        {
            key: 'employee_name',
            header: 'Pegawai',
            sortKey: 'employee_name',
            primary: true,
            cell: (r) => (
                <span className="grid">
                    <Link
                        href={showEmployee(
                            { employee: r.id },
                            { query: { tab: 'jadwal' } },
                        )}
                        className="font-medium hover:underline"
                    >
                        {r.employee.name}
                    </Link>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.employee.number}
                    </span>
                </span>
            ),
        },
        {
            key: 'location',
            header: 'Lokasi · jabatan',
            cell: (r) => (
                <span className="grid text-sm">
                    <span>{r.location ?? '—'}</span>
                    <span className="text-xs text-muted-foreground">
                        {r.position ?? '—'}
                    </span>
                </span>
            ),
        },
        {
            key: 'pattern',
            header: 'Pola Sen–Min',
            cell: (r) => <PatternCells days={r.days} />,
        },
        {
            key: 'effective_from',
            header: 'Berlaku sejak',
            sortKey: 'effective_from',
            cell: (r) =>
                r.effective_from ? (
                    <span className="text-sm whitespace-nowrap tabular-nums">
                        {formatCivilDate(r.effective_from)}
                    </span>
                ) : (
                    <span className="text-sm text-muted-foreground">—</span>
                ),
        },
    ];

    const setButton = can.manage ? (
        <Button onClick={() => setDialogOpen(true)}>
            <CalendarPlus aria-hidden="true" />
            Tetapkan Pola
            {selected.size > 0 && (
                <span className="rounded-full bg-primary-foreground/20 px-1.5 text-xs tabular-nums">
                    {formatNumber(selected.size)}
                </span>
            )}
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Jadwal · Pola" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="space-y-3">
                    <div>
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Jadwal
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Pola mingguan yang berlaku hari ini. Pola baru
                            berlaku mulai tanggalnya; pola lama tetap tersimpan
                            sebagai riwayat.
                        </p>
                    </div>
                    <ScheduleTabs active="pola" />
                </div>

                {can.manage && selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm">
                        <span>
                            <strong className="tabular-nums">
                                {formatNumber(selected.size)}
                            </strong>{' '}
                            pegawai dipilih
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected(new Set())}
                        >
                            Hapus pilihan
                        </Button>
                    </div>
                )}

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pola jadwal pegawai"
                    toolbarActions={
                        can.manage ? (
                            <>
                                <Button
                                    variant="outline"
                                    disabled={pageIds.length === 0}
                                    onClick={() =>
                                        setSelected((prev) => {
                                            const next = new Set(prev);
                                            pageIds.forEach((id) =>
                                                pageAllSelected
                                                    ? next.delete(id)
                                                    : next.add(id),
                                            );

                                            return next;
                                        })
                                    }
                                >
                                    {pageAllSelected
                                        ? 'Batal pilih halaman'
                                        : 'Pilih halaman ini'}
                                </Button>
                                {setButton}
                            </>
                        ) : undefined
                    }
                />
            </div>

            {can.manage && (
                <PatternDialog
                    open={dialogOpen}
                    onOpenChange={setDialogOpen}
                    selectedIds={[...selected]}
                    filteredTotal={listing.pagination.total}
                    filteredIds={filteredIds}
                    limit={selectionLimit}
                    onSaved={() => setSelected(new Set())}
                />
            )}
        </>
    );
}

type PatternDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selectedIds: number[];
    filteredTotal: number;
    filteredIds: number[] | null | undefined;
    limit: number;
    onSaved: () => void;
};

/** Isi dialog baru di-mount saat dibuka: target awal mengikuti pilihan terbaru. */
function PatternDialog({ open, onOpenChange, ...props }: PatternDialogProps) {
    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            icon={CalendarClock}
            size="lg"
            title="Tetapkan pola jadwal"
            description="Pola berlaku mulai tanggal yang dipilih. Jadwal sebelum tanggal itu tidak berubah. Hari tanpa shift = libur."
        >
            {open && (
                <PatternForm {...props} onClose={() => onOpenChange(false)} />
            )}
        </FormDialog>
    );
}

/** Daftar ID "semua hasil filter" diminta dengan filter di URL saat ini (partial reload). */
function reloadFilteredIds(setLoading: (loading: boolean) => void) {
    router.reload({
        only: ['filteredIds'],
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    });
}

function PatternForm({
    selectedIds,
    filteredTotal,
    filteredIds,
    limit,
    onSaved,
    onClose,
}: Omit<PatternDialogProps, 'open' | 'onOpenChange'> & {
    onClose: () => void;
}) {
    const { timezone } = usePage().props;
    const shifts = useShiftOptions(true);
    const [initialTarget] = useState<'selected' | 'filtered'>(
        selectedIds.length > 0 ? 'selected' : 'filtered',
    );
    const [target, setTarget] = useState(initialTarget);
    const [loadingIds, setLoadingIds] = useState(false);

    // Dibuka tanpa pegawai tercentang = "semua hasil filter": muat daftar ID-nya sekali.
    useEffect(() => {
        if (initialTarget === 'filtered') {
            reloadFilteredIds(setLoadingIds);
        }
    }, [initialTarget]);

    const form = useForm({
        // Diisi saat kirim (transform) dari target yang dipilih; ada di sini agar galatnya bertipe.
        employee_ids: [] as number[],
        effective_from: todayIn(timezone),
        days: Object.fromEntries(DAYS.map((d) => [d.key, ''])) as Record<
            DayKey,
            string
        >,
    });
    const { data, errors } = form;

    const chooseTarget = (value: 'selected' | 'filtered') => {
        setTarget(value);
        if (value === 'filtered') {
            reloadFilteredIds(setLoadingIds);
        }
    };

    const tooMany = filteredTotal > limit || filteredIds === null;
    const employeeIds =
        target === 'selected' ? selectedIds : (filteredIds ?? []);
    const shiftLabel = (value: string) =>
        shifts.options
            .find((o) => String(o.value) === value)
            ?.label.split(' · ')[0] ?? 'Libur';

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, employee_ids: employeeIds }));
        form.submit(storePatterns(), {
            preserveScroll: true,
            onSuccess: () => {
                onSaved();
                onClose();
            },
        });
    };

    const copyMonday = () =>
        form.setData('days', {
            ...data.days,
            tue: data.days.mon,
            wed: data.days.mon,
            thu: data.days.mon,
            fri: data.days.mon,
            sat: data.days.mon,
        });

    const errorFor = (day: DayKey) =>
        (errors as Record<string, string | undefined>)[`days.${day}`];

    return (
        <FormDialogForm onSubmit={submit} noValidate>
            <FormDialogBody>
                <fieldset className="grid gap-2">
                    <legend className="mb-2 text-sm font-medium">Untuk</legend>
                    <TargetOption
                        checked={target === 'selected'}
                        disabled={selectedIds.length === 0}
                        onChange={() => chooseTarget('selected')}
                        label={`Pegawai terpilih (${formatNumber(selectedIds.length)})`}
                        help={
                            selectedIds.length === 0
                                ? 'Centang pegawai di daftar lebih dulu.'
                                : 'Hanya pegawai yang dicentang.'
                        }
                    />
                    <TargetOption
                        checked={target === 'filtered'}
                        onChange={() => chooseTarget('filtered')}
                        label={`Semua hasil filter (${formatNumber(filteredTotal)})`}
                        help={
                            tooMany
                                ? `Lebih dari ${formatNumber(limit)} pegawai. Persempit filter lalu ulangi.`
                                : 'Semua pegawai yang tampil dengan pencarian & filter saat ini, di semua halaman.'
                        }
                    />
                    <InputError message={errors.employee_ids} />
                </fieldset>

                <div className="grid gap-2 sm:max-w-xs">
                    <Label htmlFor="effective_from">Berlaku mulai</Label>
                    <Input
                        id="effective_from"
                        type="date"
                        value={data.effective_from}
                        required
                        onChange={(e) =>
                            form.setData('effective_from', e.target.value)
                        }
                        aria-invalid={errors.effective_from ? true : undefined}
                    />
                    <InputError message={errors.effective_from} />
                </div>

                <fieldset className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <legend className="text-sm font-medium">
                            Shift per hari
                        </legend>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={copyMonday}
                            disabled={data.days.mon === ''}
                        >
                            <Copy aria-hidden="true" />
                            Salin Senin ke Selasa–Sabtu
                        </Button>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {DAYS.map((d) => (
                            <div key={d.key} className="grid gap-1.5">
                                <Label htmlFor={`day-${d.key}`}>{d.long}</Label>
                                <NativeSelect
                                    id={`day-${d.key}`}
                                    value={data.days[d.key]}
                                    disabled={shifts.loading}
                                    onChange={(e) =>
                                        form.setData('days', {
                                            ...data.days,
                                            [d.key]: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Libur</option>
                                    {shifts.options.map((o) => (
                                        <option
                                            key={o.value}
                                            value={String(o.value)}
                                        >
                                            {o.label}
                                            {o.hint ? ` (${o.hint})` : ''}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={errorFor(d.key)} />
                            </div>
                        ))}
                    </div>
                    {shifts.failed && (
                        <p className="text-sm text-destructive">
                            Daftar shift gagal dimuat. Tutup lalu buka lagi
                            dialog ini.
                        </p>
                    )}
                    <InputError message={errors.days} />
                </fieldset>

                <div className="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                    <span className="text-muted-foreground">Pratinjau: </span>
                    {DAYS.map(
                        (d) => `${d.short} ${shiftLabel(data.days[d.key])}`,
                    ).join(' · ')}
                </div>
            </FormDialogBody>

            <FormDialogFooter>
                <DialogClose asChild>
                    <Button type="button" variant="outline">
                        Batal
                    </Button>
                </DialogClose>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        loadingIds ||
                        employeeIds.length === 0 ||
                        (target === 'filtered' && tooMany)
                    }
                >
                    {(form.processing || loadingIds) && <Spinner />}
                    Tetapkan untuk {formatNumber(employeeIds.length)} pegawai
                </Button>
            </FormDialogFooter>
        </FormDialogForm>
    );
}

function TargetOption({
    checked,
    disabled = false,
    onChange,
    label,
    help,
}: {
    checked: boolean;
    disabled?: boolean;
    onChange: () => void;
    label: string;
    help: string;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-3 rounded-md border px-3 py-2.5 text-sm transition-colors',
                checked
                    ? 'border-primary bg-primary/5'
                    : 'hover:border-primary/60',
                disabled && 'cursor-not-allowed opacity-60 hover:border-border',
            )}
        >
            <input
                type="radio"
                name="pattern-target"
                checked={checked}
                disabled={disabled}
                onChange={onChange}
                className="mt-0.5 accent-primary"
            />
            <span className="grid gap-0.5">
                <span className="font-medium">{label}</span>
                <span className="text-xs text-muted-foreground">{help}</span>
            </span>
        </label>
    );
}

SchedulesIndex.layout = {
    breadcrumbs: [{ title: 'Jadwal', href: index() }],
};
