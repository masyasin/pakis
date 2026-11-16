import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/pakis/native-select';
import { MoneyInput } from '@/components/pakis/setting-field';
import { SettingsSection } from '@/components/settings-section';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { index, store, update } from '@/routes/admin/approval-rules';
import type { DocumentTypeOption } from './index';

type Option = { value: string; label: string };

type PermissionGroup = {
    module: string;
    label: string;
    permissions: { name: string; label: string }[];
};

type StepData = {
    approver_type: string;
    permission_name: string;
    escalate_after_hours: string;
    escalation_permission: string;
};

type RuleData = {
    id: number;
    document_type: string;
    name: string;
    min_amount: string | null;
    max_amount: string | null;
    conditions: Record<string, Array<string | number>> | null;
    priority: number;
    is_active: boolean;
    used: boolean;
    steps: {
        approver_type: string;
        permission_name: string | null;
        escalate_after_hours: number | null;
        escalation_permission: string | null;
    }[];
};

type Props = {
    rule: RuleData | null;
    documentTypes: DocumentTypeOption[];
    approverTypes: Option[];
    permissions: PermissionGroup[];
    defaultEscalationHours: number;
    maxSteps: number;
};

const emptyStep = (): StepData => ({
    approver_type: 'permission',
    permission_name: '',
    escalate_after_hours: '',
    escalation_permission: '',
});

/** Penjelasan singkat tiap jenis penyetuju (15 §4.2, BR-153). */
const APPROVER_HELP: Record<string, string> = {
    permission:
        'Siapa saja yang memegang izin terpilih dan dokumennya dalam cakupan datanya.',
    direct_supervisor:
        'Atasan langsung pegawai terkait dokumen, dari penugasan utamanya.',
    location_head: 'Kepala lokasi tempat dokumen berada.',
};

function PermissionOptions({ groups }: { groups: PermissionGroup[] }) {
    return groups.map((g) => (
        <optgroup key={g.module} label={g.label}>
            {g.permissions.map((p) => (
                <option key={p.name} value={p.name}>
                    {p.label} ({p.name})
                </option>
            ))}
        </optgroup>
    ));
}

/**
 * Form aturan persetujuan (15 §4.2, FR-A02). Konfirmasi password sudah diminta saat halaman dibuka
 * (RequirePassword). Rantai 1–`maxSteps` langkah berurutan; nominal string desimal kanonik (tanpa
 * float), batas bawah inklusif, batas atas eksklusif.
 */
export default function ApprovalRuleForm({
    rule,
    documentTypes,
    approverTypes,
    permissions,
    defaultEscalationHours,
    maxSteps,
}: Props) {
    const form = useForm({
        document_type: rule?.document_type ?? documentTypes[0]?.value ?? '',
        name: rule?.name ?? '',
        min_amount: rule?.min_amount ?? '',
        max_amount: rule?.max_amount ?? '',
        // Kondisi diketik sebagai daftar dipisah koma per kunci; diubah ke array saat dikirim.
        conditions: Object.fromEntries(
            Object.entries(rule?.conditions ?? {}).map(([k, v]) => [
                k,
                v.join(', '),
            ]),
        ) as Record<string, string>,
        priority: String(rule?.priority ?? 100),
        is_active: rule?.is_active ?? true,
        steps: rule?.steps.length
            ? rule.steps.map((s) => ({
                  approver_type: s.approver_type,
                  permission_name: s.permission_name ?? '',
                  escalate_after_hours:
                      s.escalate_after_hours === null
                          ? ''
                          : String(s.escalate_after_hours),
                  escalation_permission: s.escalation_permission ?? '',
              }))
            : [emptyStep()],
    });
    const { data } = form;
    const errors = form.errors as Record<string, string | undefined>;
    const conditionKeys =
        documentTypes.find((t) => t.value === data.document_type)?.conditions ??
        [];
    const conditionError =
        errors.conditions ??
        Object.entries(errors).find(([k]) => k.startsWith('conditions.'))?.[1];

    const setStep = (i: number, patch: Partial<StepData>) =>
        form.setData(
            'steps',
            data.steps.map((s, idx) => (idx === i ? { ...s, ...patch } : s)),
        );
    const moveStep = (i: number, dir: -1 | 1) => {
        const next = [...data.steps];
        [next[i], next[i + dir]] = [next[i + dir], next[i]];
        form.setData('steps', next);
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => {
            const conditions = Object.fromEntries(
                conditionKeys
                    .map((k) => [
                        k,
                        (d.conditions[k] ?? '')
                            .split(',')
                            .map((v) => v.trim())
                            .filter(Boolean),
                    ])
                    .filter(([, v]) => v.length > 0),
            ) as Record<string, string[]>;

            return {
                ...d,
                conditions:
                    Object.keys(conditions).length > 0 ? conditions : null,
                steps: d.steps.map((s) => ({
                    ...s,
                    // Izin hanya bermakna untuk penyetuju "pemegang izin".
                    permission_name:
                        s.approver_type === 'permission'
                            ? s.permission_name
                            : '',
                })),
            };
        });
        form.submit(rule ? update(rule.id) : store(), { preserveScroll: true });
    };

    const title = rule
        ? `Ubah aturan "${rule.name}"`
        : 'Tambah aturan persetujuan';
    useBreadcrumbTail([{ title: rule ? 'Ubah' : 'Tambah', href: '#' }]);

    return (
        <>
            <Head title={title} />
            <form
                onSubmit={submit}
                noValidate
                className="mx-auto w-full max-w-[1440px] space-y-6 p-4 md:px-7 md:py-6"
            >
                <div className="flex flex-wrap items-center gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>
                            <ArrowLeft aria-hidden="true" />
                            Daftar aturan
                        </Link>
                    </Button>
                    <h1 className="text-[1.4rem] leading-tight font-bold break-words">
                        {title}
                    </h1>
                </div>

                <FormStatus
                    tone="error"
                    message={
                        errors.form ??
                        (form.hasErrors
                            ? 'Periksa lagi isian yang ditandai merah.'
                            : undefined)
                    }
                />

                {rule?.used && (
                    <p className="rounded-lg bg-status-info-bg px-3.5 py-3 text-sm text-status-info">
                        Aturan ini sudah pernah dipakai. Perubahan hanya berlaku
                        untuk pengajuan baru; permintaan yang sedang berjalan
                        tetap memakai rantai lama.
                    </p>
                )}

                <SettingsSection
                    title="Kapan aturan berlaku"
                    description="Untuk satu jenis dokumen, aturan aktif dicek urut prioritas (angka kecil dulu); yang pertama cocok dipakai."
                >
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid content-start gap-2">
                            <Label htmlFor="document_type">Jenis dokumen</Label>
                            <NativeSelect
                                id="document_type"
                                value={data.document_type}
                                onChange={(e) => {
                                    form.setData((d) => ({
                                        ...d,
                                        document_type: e.target.value,
                                        conditions: {},
                                    }));
                                }}
                                aria-invalid={
                                    errors.document_type ? true : undefined
                                }
                            >
                                {documentTypes.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.document_type} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="name">Nama aturan</Label>
                            <Input
                                id="name"
                                value={data.name}
                                maxLength={120}
                                autoComplete="off"
                                placeholder="Mis. PO ≥ Rp 25 jt"
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                aria-invalid={errors.name ? true : undefined}
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="min_amount">
                                Nominal mulai{' '}
                                <span className="font-normal text-muted-foreground">
                                    (≥, kosong = tanpa batas)
                                </span>
                            </Label>
                            <MoneyInput
                                id="min_amount"
                                value={data.min_amount}
                                onChange={(v) =>
                                    form.setData(
                                        'min_amount',
                                        typeof v === 'string' ? v : '',
                                    )
                                }
                                invalid={Boolean(errors.min_amount)}
                            />
                            <InputError message={errors.min_amount} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="max_amount">
                                Nominal di bawah{' '}
                                <span className="font-normal text-muted-foreground">
                                    (&lt;, kosong = tanpa batas)
                                </span>
                            </Label>
                            <MoneyInput
                                id="max_amount"
                                value={data.max_amount}
                                onChange={(v) =>
                                    form.setData(
                                        'max_amount',
                                        typeof v === 'string' ? v : '',
                                    )
                                }
                                invalid={Boolean(errors.max_amount)}
                            />
                            <InputError message={errors.max_amount} />
                        </div>
                        <div className="grid content-start gap-2">
                            <Label htmlFor="priority">Prioritas</Label>
                            <Input
                                id="priority"
                                type="number"
                                inputMode="numeric"
                                min={1}
                                max={65535}
                                className="w-32"
                                value={data.priority}
                                onChange={(e) =>
                                    form.setData('priority', e.target.value)
                                }
                                aria-invalid={
                                    errors.priority ? true : undefined
                                }
                            />
                            <InputError message={errors.priority} />
                        </div>
                        <div className="grid content-start gap-2">
                            <span className="text-sm font-medium">Status</span>
                            <label
                                htmlFor="is_active"
                                className="flex min-h-9 cursor-pointer items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    id="is_active"
                                    checked={data.is_active}
                                    onCheckedChange={(on) =>
                                        form.setData('is_active', on === true)
                                    }
                                />
                                Aktif (dipakai untuk pengajuan baru)
                            </label>
                        </div>
                    </div>

                    {conditionKeys.length > 0 && (
                        <fieldset className="mt-4 grid gap-3">
                            <legend className="mb-1 text-sm font-medium">
                                Kondisi tambahan{' '}
                                <span className="font-normal text-muted-foreground">
                                    (opsional; beberapa nilai dipisah koma)
                                </span>
                            </legend>
                            <div className="grid gap-4 sm:grid-cols-2">
                                {conditionKeys.map((key) => (
                                    <div key={key} className="grid gap-2">
                                        <Label htmlFor={`condition-${key}`}>
                                            <span className="font-mono text-xs">
                                                {key}
                                            </span>
                                        </Label>
                                        <Input
                                            id={`condition-${key}`}
                                            value={data.conditions[key] ?? ''}
                                            onChange={(e) =>
                                                form.setData('conditions', {
                                                    ...data.conditions,
                                                    [key]: e.target.value,
                                                })
                                            }
                                        />
                                    </div>
                                ))}
                            </div>
                            <InputError message={conditionError} />
                        </fieldset>
                    )}
                </SettingsSection>

                <SettingsSection
                    title="Rantai penyetuju"
                    description={`Langkah diputuskan berurutan, maksimal ${maxSteps}. Langkah yang tidak diputuskan dalam ${defaultEscalationHours} jam (atau jam yang diisi) dieskalasi.`}
                >
                    <InputError message={errors.steps} />
                    <ol className="grid gap-3">
                        {data.steps.map((step, i) => {
                            const err = (field: string) =>
                                errors[`steps.${i}.${field}`];

                            return (
                                <li
                                    key={i}
                                    className="grid gap-3 rounded-lg border p-3 sm:p-4"
                                >
                                    <div className="flex items-center gap-2">
                                        <span className="text-sm font-semibold">
                                            Langkah {i + 1}
                                        </span>
                                        <div className="ml-auto flex gap-1">
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                disabled={i === 0}
                                                aria-label={`Naikkan langkah ${i + 1}`}
                                                onClick={() => moveStep(i, -1)}
                                            >
                                                <ArrowUp aria-hidden="true" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                disabled={
                                                    i === data.steps.length - 1
                                                }
                                                aria-label={`Turunkan langkah ${i + 1}`}
                                                onClick={() => moveStep(i, 1)}
                                            >
                                                <ArrowDown aria-hidden="true" />
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                disabled={
                                                    data.steps.length === 1
                                                }
                                                aria-label={`Hapus langkah ${i + 1}`}
                                                onClick={() =>
                                                    form.setData(
                                                        'steps',
                                                        data.steps.filter(
                                                            (_, idx) =>
                                                                idx !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 aria-hidden="true" />
                                            </Button>
                                        </div>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <div className="grid content-start gap-2">
                                            <Label htmlFor={`step-${i}-type`}>
                                                Penyetuju
                                            </Label>
                                            <NativeSelect
                                                id={`step-${i}-type`}
                                                value={step.approver_type}
                                                onChange={(e) =>
                                                    setStep(i, {
                                                        approver_type:
                                                            e.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    err('approver_type')
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                {approverTypes.map((t) => (
                                                    <option
                                                        key={t.value}
                                                        value={t.value}
                                                    >
                                                        {t.label}
                                                    </option>
                                                ))}
                                            </NativeSelect>
                                            <p className="text-xs text-muted-foreground">
                                                {
                                                    APPROVER_HELP[
                                                        step.approver_type
                                                    ]
                                                }
                                            </p>
                                            <InputError
                                                message={err('approver_type')}
                                            />
                                        </div>
                                        {step.approver_type ===
                                            'permission' && (
                                            <div className="grid content-start gap-2">
                                                <Label
                                                    htmlFor={`step-${i}-permission`}
                                                >
                                                    Izin penyetuju
                                                </Label>
                                                <NativeSelect
                                                    id={`step-${i}-permission`}
                                                    value={step.permission_name}
                                                    onChange={(e) =>
                                                        setStep(i, {
                                                            permission_name:
                                                                e.target.value,
                                                        })
                                                    }
                                                    aria-invalid={
                                                        err('permission_name')
                                                            ? true
                                                            : undefined
                                                    }
                                                >
                                                    <option value="">
                                                        Pilih izin…
                                                    </option>
                                                    <PermissionOptions
                                                        groups={permissions}
                                                    />
                                                </NativeSelect>
                                                <InputError
                                                    message={err(
                                                        'permission_name',
                                                    )}
                                                />
                                            </div>
                                        )}
                                        <div className="grid content-start gap-2">
                                            <Label htmlFor={`step-${i}-hours`}>
                                                Eskalasi setelah (jam)
                                            </Label>
                                            <Input
                                                id={`step-${i}-hours`}
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                className="w-32"
                                                placeholder={String(
                                                    defaultEscalationHours,
                                                )}
                                                value={
                                                    step.escalate_after_hours
                                                }
                                                onChange={(e) =>
                                                    setStep(i, {
                                                        escalate_after_hours:
                                                            e.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    err('escalate_after_hours')
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Kosong = bawaan{' '}
                                                {defaultEscalationHours} jam.
                                            </p>
                                            <InputError
                                                message={err(
                                                    'escalate_after_hours',
                                                )}
                                            />
                                        </div>
                                        <div className="grid content-start gap-2">
                                            <Label
                                                htmlFor={`step-${i}-escalation`}
                                            >
                                                Eskalasi ke
                                            </Label>
                                            <NativeSelect
                                                id={`step-${i}-escalation`}
                                                value={
                                                    step.escalation_permission
                                                }
                                                onChange={(e) =>
                                                    setStep(i, {
                                                        escalation_permission:
                                                            e.target.value,
                                                    })
                                                }
                                                aria-invalid={
                                                    err('escalation_permission')
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                <option value="">
                                                    Bawaan: pengatur persetujuan
                                                </option>
                                                <PermissionOptions
                                                    groups={permissions}
                                                />
                                            </NativeSelect>
                                            <InputError
                                                message={err(
                                                    'escalation_permission',
                                                )}
                                            />
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ol>
                    {data.steps.length < maxSteps && (
                        <Button
                            type="button"
                            variant="outline"
                            className="mt-3"
                            onClick={() =>
                                form.setData('steps', [
                                    ...data.steps,
                                    emptyStep(),
                                ])
                            }
                        >
                            <Plus aria-hidden="true" />
                            Tambah Langkah
                        </Button>
                    )}
                </SettingsSection>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        {rule ? 'Simpan Aturan' : 'Buat Aturan'}
                    </Button>
                </div>
            </form>
        </>
    );
}

ApprovalRuleForm.layout = {
    breadcrumbs: [{ title: 'Aturan Persetujuan', href: index() }],
};
