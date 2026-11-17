import { Head, Link, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    BookmarkCheck,
    CheckCircle2,
    Circle,
    FileSpreadsheet,
} from 'lucide-react';
import { ImportSteps } from '@/components/import/import-steps';
import type {
    ImportBatchSummary,
    ImportField,
} from '@/components/import/import-steps';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/pakis/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index } from '@/routes/admin/imports';
import { update } from '@/routes/admin/imports/mapping';

type Props = {
    batch: ImportBatchSummary;
    columns: { index: number; header: string }[];
    samples: string[][];
    /** Indeks kolom (string, dari JSON) → field. */
    mapping: Record<string, string>;
    profile: { id: number; name: string } | null;
    fields: ImportField[];
};

type Fields = {
    columns: Record<string, string>;
    save_profile: boolean;
    profile_name: string;
};

const card =
    'rounded-xl border bg-card shadow-[0_1px_2px_rgb(0_0_0/0.04),0_4px_12px_-6px_rgb(0_0_0/0.08)]';

/** Impor pegawai langkah 2: pemetaan kolom berkas → field sistem (07 §4, BR-054). */
export default function ImportMapping({
    batch,
    columns,
    samples,
    mapping,
    profile,
    fields,
}: Props) {
    const form = useForm<Fields>({
        columns: Object.fromEntries(
            columns.map((c) => [String(c.index), mapping[c.index] ?? '']),
        ),
        save_profile: false,
        profile_name: profile?.name ?? '',
    });
    const chosen = form.data.columns;
    const usedBy = new Map(
        Object.entries(chosen)
            .filter(([, field]) => field !== '')
            .map(([col, field]) => [field, Number(col)]),
    );
    const labelOf = (field: string) =>
        fields.find((f) => f.key === field)?.label ?? field;
    const headerOf = (i: number) =>
        columns[i]?.header || `Kolom ${i + 1} (tanpa judul)`;
    const missing = fields.filter((f) => f.required && !usedBy.has(f.key));
    const errors = form.errors as Record<string, string | undefined>;

    // "Abaikan kolom ini" dikirim sebagai null, bukan string kosong.
    const submit = () => {
        form.transform((data) => ({
            ...data,
            columns: Object.fromEntries(
                Object.entries(data.columns).map(([k, v]) => [k, v || null]),
            ),
        }));
        form.put(update(batch.id).url, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Pemetaan kolom" />
            <div className="mx-auto w-full max-w-[1440px] space-y-5 p-4 md:px-7 md:py-6">
                <Link
                    href={index()}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    Riwayat impor
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            Pemetaan kolom
                        </h1>
                        <p className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
                            <FileSpreadsheet
                                className="size-4 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                {batch.original_name}
                            </span>
                            <span className="shrink-0">
                                · judul di baris {batch.header_row} ·{' '}
                                {batch.total_rows.toLocaleString('id-ID')} baris
                                data
                            </span>
                        </p>
                    </div>
                    {profile && (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
                            <BookmarkCheck
                                className="size-3.5"
                                aria-hidden="true"
                            />
                            Profil: {profile.name}
                        </span>
                    )}
                </div>

                <ImportSteps current={2} />

                {batch.failure && (
                    <p className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                        {batch.failure}
                    </p>
                )}

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <section className={cn(card, 'overflow-hidden')}>
                        <header className="hidden grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)] gap-4 border-b bg-muted/40 px-5 py-2.5 text-xs font-medium text-muted-foreground md:grid">
                            <span>Kolom di berkas</span>
                            <span>Contoh isi</span>
                            <span>Masuk ke field</span>
                        </header>
                        <ul className="divide-y">
                            {columns.map((c) => {
                                const value = chosen[String(c.index)] ?? '';
                                const id = `col-${c.index}`;

                                return (
                                    <li
                                        key={c.index}
                                        className={cn(
                                            'grid gap-2 px-4 py-3 md:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,1fr)] md:items-center md:gap-4 md:px-5',
                                            value === '' && 'bg-muted/20',
                                        )}
                                    >
                                        <label
                                            htmlFor={id}
                                            className="min-w-0 font-medium break-words"
                                        >
                                            {headerOf(c.index)}
                                        </label>
                                        <span className="min-w-0 truncate text-sm text-muted-foreground">
                                            {(samples[c.index] ?? []).length > 0
                                                ? samples[c.index].join(' · ')
                                                : 'kosong'}
                                        </span>
                                        <div className="space-y-1">
                                            <NativeSelect
                                                id={id}
                                                value={value}
                                                onChange={(e) =>
                                                    form.setData('columns', {
                                                        ...chosen,
                                                        [String(c.index)]:
                                                            e.target.value,
                                                    })
                                                }
                                                className={cn(
                                                    value === '' &&
                                                        'text-muted-foreground',
                                                )}
                                                aria-invalid={
                                                    errors[`columns.${c.index}`]
                                                        ? true
                                                        : undefined
                                                }
                                            >
                                                <option value="">
                                                    Abaikan kolom ini
                                                </option>
                                                {fields.map((f) => {
                                                    const owner = usedBy.get(
                                                        f.key,
                                                    );
                                                    const taken =
                                                        owner !== undefined &&
                                                        owner !== c.index;

                                                    return (
                                                        <option
                                                            key={f.key}
                                                            value={f.key}
                                                            disabled={taken}
                                                        >
                                                            {f.label}
                                                            {f.required
                                                                ? ' *'
                                                                : ''}
                                                            {taken
                                                                ? ` (dipakai: ${headerOf(owner)})`
                                                                : ''}
                                                        </option>
                                                    );
                                                })}
                                            </NativeSelect>
                                            <InputError
                                                message={
                                                    errors[`columns.${c.index}`]
                                                }
                                            />
                                        </div>
                                    </li>
                                );
                            })}
                        </ul>
                    </section>

                    <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
                        <div className={cn(card, 'space-y-3 p-4 sm:p-5')}>
                            <h2 className="text-sm font-semibold">
                                Field wajib
                            </h2>
                            <ul className="space-y-1.5">
                                {fields
                                    .filter((f) => f.required)
                                    .map((f) => {
                                        const ok = usedBy.has(f.key);

                                        return (
                                            <li
                                                key={f.key}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                {ok ? (
                                                    <CheckCircle2
                                                        className="size-4 text-primary"
                                                        aria-hidden="true"
                                                    />
                                                ) : (
                                                    <Circle
                                                        className="size-4 text-muted-foreground"
                                                        aria-hidden="true"
                                                    />
                                                )}
                                                <span
                                                    className={cn(
                                                        !ok &&
                                                            'text-muted-foreground',
                                                    )}
                                                >
                                                    {f.label}
                                                </span>
                                                <span className="sr-only">
                                                    {ok
                                                        ? 'sudah dipetakan'
                                                        : 'belum dipetakan'}
                                                </span>
                                            </li>
                                        );
                                    })}
                            </ul>
                            <InputError message={errors.columns} />
                        </div>

                        <div className={cn(card, 'space-y-3 p-4 sm:p-5')}>
                            <div className="flex items-start gap-2">
                                <Checkbox
                                    id="save_profile"
                                    checked={form.data.save_profile}
                                    onCheckedChange={(v) =>
                                        form.setData('save_profile', v === true)
                                    }
                                />
                                <Label
                                    htmlFor="save_profile"
                                    className="grid gap-0.5 text-sm font-normal"
                                >
                                    Simpan sebagai profil
                                    <span className="text-xs text-muted-foreground">
                                        Berkas berikutnya dengan judul kolom
                                        yang sama otomatis terpetakan.
                                    </span>
                                </Label>
                            </div>
                            {form.data.save_profile && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="profile_name">
                                        Nama profil
                                    </Label>
                                    <Input
                                        id="profile_name"
                                        value={form.data.profile_name}
                                        maxLength={100}
                                        placeholder="Mis. Format HRD 2026"
                                        onChange={(e) =>
                                            form.setData(
                                                'profile_name',
                                                e.target.value,
                                            )
                                        }
                                        aria-invalid={
                                            errors.profile_name
                                                ? true
                                                : undefined
                                        }
                                    />
                                    <InputError message={errors.profile_name} />
                                </div>
                            )}
                        </div>

                        <Button
                            className="w-full"
                            disabled={form.processing || missing.length > 0}
                            onClick={submit}
                        >
                            {form.processing && <Spinner />}
                            Simpan & validasi
                        </Button>
                        {missing.length > 0 && (
                            <p className="text-center text-xs text-muted-foreground">
                                Petakan dulu:{' '}
                                {missing.map((f) => labelOf(f.key)).join(', ')}.
                            </p>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}

ImportMapping.layout = {
    breadcrumbs: [
        { title: 'Impor', href: index() },
        { title: 'Pemetaan kolom', href: '#' },
    ],
};
