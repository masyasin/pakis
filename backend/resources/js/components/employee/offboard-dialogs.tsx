import { Link, useForm } from '@inertiajs/react';
import { AlertTriangle, UserCheck, UserX } from 'lucide-react';
import { ModePicker } from '@/components/employee/assignments-panel';
import type { ModeOption } from '@/components/employee/assignments-panel';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { offboard, rehire, show } from '@/routes/admin/employees';

export type OffboardImpact = {
    account: { username: string; active: boolean } | null;
    sessions: number;
    tokens: number;
    devices_active: number;
    devices_pending: number;
    assignments: number;
    headed_locations: string[];
    subordinates: { id: number; name: string; number: string }[];
    /** Batas bawah tanggal keluar (tanggal masuk / penugasan yang sudah berjalan), BR-053. */
    earliest_exit: string;
};

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');

/** Kalimat dampak 07 §4: "Akun dinonaktifkan, 2 sesi web dan 1 HP dicabut, …". */
function impactLines(impact: OffboardImpact): string[] {
    const lines: string[] = [];
    if (impact.account) {
        lines.push(
            impact.account.active
                ? `Akun ${impact.account.username} dinonaktifkan`
                : `Akun ${impact.account.username} sudah nonaktif`,
        );
    } else {
        lines.push('Pegawai ini belum punya akun login');
    }
    if (impact.sessions > 0) {
        lines.push(`${impact.sessions} sesi web dihapus`);
    }
    if (impact.tokens > 0) {
        lines.push(`${impact.tokens} token Pakis Pro dicabut`);
    }
    if (impact.devices_active > 0) {
        lines.push(`${impact.devices_active} HP Pakis Pro dicabut`);
    }
    if (impact.devices_pending > 0) {
        lines.push(`${impact.devices_pending} HP menunggu persetujuan ditolak`);
    }
    if (impact.assignments > 0) {
        lines.push(
            `${impact.assignments} penugasan ditutup per tanggal keluar`,
        );
    }
    impact.headed_locations.forEach((l) =>
        lines.push(`Kepala lokasi ${l} dikosongkan`),
    );

    return lines;
}

/**
 * Dialog Nonaktifkan pegawai (07 §4, BR-053): tanggal keluar, alasan, catatan, ringkasan dampak,
 * daftar bawahan yang masih menunjuk pegawai ini, ketik nama, dan password pelaku.
 */
export function OffboardDialog({
    employeeId,
    employeeName,
    impact,
    reasons,
    today,
    onClose,
}: {
    employeeId: number;
    employeeName: string;
    impact: OffboardImpact;
    reasons: { value: number; label: string }[];
    today: string;
    onClose: () => void;
}) {
    const form = useForm({
        exit_date: today,
        exit_reason_id: '',
        exit_note: '',
        confirm_name: '',
        current_password: '',
    });
    const err = form.errors as Record<string, string | undefined>;
    const nameMatches =
        form.data.confirm_name.trim().toLowerCase() ===
        employeeName.trim().toLowerCase();

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(offboard(employeeId).url, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: () => form.reset('current_password'),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(v) => !v && onClose()}
            icon={UserX}
            tone="danger"
            size="lg"
            title={`Nonaktifkan ${employeeName}`}
            description="Data pegawai, riwayat penugasan, presensi, dan riwayat perubahan tetap tersimpan. Akses login dan Pakis Pro dicabut saat itu juga."
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={
                            form.processing ||
                            !nameMatches ||
                            form.data.current_password === '' ||
                            form.data.exit_reason_id === ''
                        }
                    >
                        {form.processing && <Spinner />}
                        Nonaktifkan Pegawai
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="exit_date">Tanggal keluar</Label>
                    <Input
                        id="exit_date"
                        type="date"
                        min={impact.earliest_exit}
                        max={today}
                        value={form.data.exit_date}
                        onChange={(e) =>
                            form.setData('exit_date', e.target.value)
                        }
                        aria-invalid={err.exit_date ? true : undefined}
                    />
                    <InputError message={err.exit_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="exit_reason_id">Alasan</Label>
                    <NativeSelect
                        id="exit_reason_id"
                        value={form.data.exit_reason_id}
                        onChange={(e) =>
                            form.setData('exit_reason_id', e.target.value)
                        }
                        aria-invalid={err.exit_reason_id ? true : undefined}
                    >
                        <option value="">Pilih alasan…</option>
                        {reasons.map((r) => (
                            <option key={r.value} value={r.value}>
                                {r.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={err.exit_reason_id} />
                </div>
                <div className="grid content-start gap-2 sm:col-span-2">
                    <Label htmlFor="exit_note">
                        Catatan{' '}
                        <span className="font-normal text-muted-foreground">
                            (opsional)
                        </span>
                    </Label>
                    <Input
                        id="exit_note"
                        value={form.data.exit_note}
                        maxLength={255}
                        placeholder="Mis. surat pengunduran diri 1 Nov 2026"
                        onChange={(e) =>
                            form.setData('exit_note', e.target.value)
                        }
                    />
                    <InputError message={err.exit_note} />
                </div>
            </div>

            <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm">
                <p className="mb-1.5 flex items-center gap-2 font-semibold text-destructive">
                    <AlertTriangle className="size-4" aria-hidden="true" />
                    Dampak
                </p>
                <ul className="list-disc space-y-0.5 pl-5 text-foreground/85">
                    {impactLines(impact).map((line) => (
                        <li key={line}>{line}</li>
                    ))}
                </ul>
            </div>

            {impact.subordinates.length > 0 && (
                <div className="rounded-lg border bg-status-info-bg p-3 text-sm text-status-info">
                    <p className="mb-1.5 font-semibold">
                        {impact.subordinates.length} bawahan masih menunjuk
                        pegawai ini sebagai atasan
                    </p>
                    <p className="mb-2 text-xs">
                        Sebaiknya pindahkan atasan mereka lebih dulu (Ubah
                        penugasan di detail pegawai masing-masing).
                    </p>
                    <ul className="flex flex-wrap gap-1.5">
                        {impact.subordinates.map((s) => (
                            <li key={s.id}>
                                <Link
                                    href={
                                        show(s.id, {
                                            query: { tab: 'penugasan' },
                                        }).url
                                    }
                                    className="inline-flex items-center gap-1 rounded-full border bg-card px-2.5 py-0.5 text-xs text-foreground hover:border-primary"
                                >
                                    {s.name}
                                    <span className="font-mono text-muted-foreground">
                                        {s.number}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="confirm_name">
                        Ketik nama pegawai:{' '}
                        <span className="font-semibold">{employeeName}</span>
                    </Label>
                    <Input
                        id="confirm_name"
                        value={form.data.confirm_name}
                        autoComplete="off"
                        onChange={(e) =>
                            form.setData('confirm_name', e.target.value)
                        }
                        aria-invalid={err.confirm_name ? true : undefined}
                    />
                    <InputError message={err.confirm_name} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="offboard_password">Password Anda</Label>
                    <PasswordInput
                        id="offboard_password"
                        value={form.data.current_password}
                        autoComplete="current-password"
                        onChange={(e) =>
                            form.setData('current_password', e.target.value)
                        }
                        aria-invalid={err.current_password ? true : undefined}
                    />
                    <InputError message={err.current_password} />
                </div>
            </div>
        </FormDialog>
    );
}

/**
 * Dialog Aktifkan kembali (06 §7.3): tanggal aktif kembali (menjadi tanggal masuk baru), penugasan
 * utama baru, centang aktifkan akun, password pelaku.
 */
export function RehireDialog({
    employeeId,
    employeeName,
    exitDate,
    hasAccount,
    canActivateAccount,
    modes,
    today,
    onClose,
}: {
    employeeId: number;
    employeeName: string;
    exitDate: string | null;
    hasAccount: boolean;
    canActivateAccount: boolean;
    modes: ModeOption[];
    today: string;
    onClose: () => void;
}) {
    const form = useForm({
        rehire_date: today,
        position_id: '',
        location_id: '',
        supervisor_employee_id: '',
        attendance_mode: '',
        note: '',
        activate_account: canActivateAccount,
        current_password: '',
    });
    const err = form.errors as Record<string, string | undefined>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(rehire(employeeId).url, {
            preserveScroll: true,
            onSuccess: onClose,
            onError: () => form.reset('current_password'),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(v) => !v && onClose()}
            icon={UserCheck}
            size="lg"
            title={`Aktifkan kembali ${employeeName}`}
            description="Tanggal aktif kembali menjadi tanggal masuk baru (masa kerja dihitung ulang). Tanggal masuk & keluar lama tetap ada di Riwayat Perubahan."
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing || form.data.current_password === ''
                        }
                    >
                        {form.processing && <Spinner />}
                        Aktifkan Kembali
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="rehire_date">Tanggal aktif kembali</Label>
                    <Input
                        id="rehire_date"
                        type="date"
                        max={today}
                        min={exitDate ?? undefined}
                        value={form.data.rehire_date}
                        onChange={(e) =>
                            form.setData('rehire_date', e.target.value)
                        }
                        aria-invalid={err.rehire_date ? true : undefined}
                    />
                    <InputError message={err.rehire_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="rh_position">Jabatan</Label>
                    <AsyncSelect
                        id="rh_position"
                        source="positions"
                        value={one(form.data.position_id)}
                        onChange={(v) => form.setData('position_id', first(v))}
                    />
                    <InputError message={err.position_id} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="rh_location">Lokasi tugas</Label>
                    <AsyncSelect
                        id="rh_location"
                        source="locations"
                        value={one(form.data.location_id)}
                        onChange={(v) => form.setData('location_id', first(v))}
                    />
                    <InputError message={err.location_id} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="rh_supervisor">
                        Atasan langsung{' '}
                        <span className="font-normal text-muted-foreground">
                            (opsional)
                        </span>
                    </Label>
                    <AsyncSelect
                        id="rh_supervisor"
                        source="employees"
                        params={{ exclude: String(employeeId) }}
                        value={one(form.data.supervisor_employee_id)}
                        placeholder="Cari nama atau nomor pegawai"
                        onChange={(v) =>
                            form.setData('supervisor_employee_id', first(v))
                        }
                    />
                    <InputError message={err.supervisor_employee_id} />
                </div>
            </div>

            <ModePicker
                value={form.data.attendance_mode}
                modes={modes}
                onChange={(v) => form.setData('attendance_mode', v)}
                error={err.attendance_mode}
            />

            {hasAccount && (
                <div className="flex items-start gap-2">
                    <Checkbox
                        id="activate_account"
                        checked={form.data.activate_account}
                        disabled={!canActivateAccount}
                        onCheckedChange={(v) =>
                            form.setData('activate_account', v === true)
                        }
                    />
                    <Label
                        htmlFor="activate_account"
                        className="grid gap-0.5 text-sm font-normal"
                    >
                        Aktifkan juga akun login
                        <span className="text-xs text-muted-foreground">
                            {canActivateAccount
                                ? 'HP Pakis Pro perlu didaftarkan & disetujui ulang.'
                                : 'Anda tidak berwenang mengaktifkan akun; minta Administrator.'}
                        </span>
                    </Label>
                </div>
            )}

            <div className="grid gap-2 sm:max-w-sm">
                <Label htmlFor="rehire_password">Password Anda</Label>
                <PasswordInput
                    id="rehire_password"
                    value={form.data.current_password}
                    autoComplete="current-password"
                    onChange={(e) =>
                        form.setData('current_password', e.target.value)
                    }
                    aria-invalid={err.current_password ? true : undefined}
                />
                <InputError message={err.current_password} />
            </div>
        </FormDialog>
    );
}
