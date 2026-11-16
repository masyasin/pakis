import { Head, router, usePage } from '@inertiajs/react';
import { Hash, Pencil } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ListingProps } from '@/types/listing';

type SequenceRow = {
    id: number;
    code: string;
    name: string;
    pattern: string;
    reset_period: string;
    reset_label: string;
    last_number: number | null;
    next: string;
};

type Option = { value: string; label: string };

type Props = ListingProps<SequenceRow> & {
    resetOptions: Option[];
    canUpdate: boolean;
};

/** Token pola (BR-025). Teks literal (prefix, pemisah) ditulis langsung. */
const TOKENS: Array<{ token: string; help: string }> = [
    { token: '{YYYY}', help: 'Tahun 4 digit' },
    { token: '{YY}', help: 'Tahun 2 digit' },
    { token: '{MM}', help: 'Bulan 01–12' },
    { token: '{DD}', help: 'Tanggal 01–31' },
    { token: '{LOC}', help: 'Kode lokasi' },
    { token: '{SEQ:4}', help: 'Nomor urut, 3–8 digit (wajib satu)' },
];

const PREVIEW_DELAY_MS = 300;

/** Cookie XSRF-TOKEN Laravel untuk permintaan JSON di luar Inertia. */
function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

/** Penomoran dokumen (05 §4.3, 07 §4). */
export default function SequencesIndex({
    rows,
    listing,
    resetOptions,
    canUpdate,
}: Props) {
    const [editing, setEditing] = useState<SequenceRow | null>(null);

    const columns: DataTableColumn<SequenceRow>[] = [
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            primary: true,
            cell: (r) => r.name,
        },
        {
            key: 'code',
            header: 'Kode',
            sortKey: 'code',
            // Gaya mono di isi sel, bukan className kolom (yang ikut ke judul kolom).
            cell: (r) => (
                <span className="font-mono text-xs text-muted-foreground">
                    {r.code}
                </span>
            ),
        },
        {
            key: 'pattern',
            header: 'Pola',
            cell: (r) => <span className="font-mono text-xs">{r.pattern}</span>,
        },
        { key: 'reset', header: 'Periode reset', cell: (r) => r.reset_label },
        {
            key: 'last',
            header: 'Nomor terakhir',
            className: 'tabular-nums',
            cell: (r) =>
                r.last_number === null
                    ? '–'
                    : r.last_number.toLocaleString('id-ID'),
        },
        {
            key: 'next',
            header: 'Contoh berikut',
            cell: (r) => <span className="font-mono text-xs">{r.next}</span>,
        },
    ];

    return (
        <>
            <Head title="Penomoran" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Penomoran
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pola nomor dokumen yang dibuat otomatis oleh sistem.
                        Nomor yang sudah terbit tidak ikut berubah.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar penomoran"
                    rowActions={
                        canUpdate
                            ? (r) => (
                                  <Button
                                      variant="ghost"
                                      size="sm"
                                      onClick={() => setEditing(r)}
                                      aria-label={`Ubah pola ${r.name}`}
                                  >
                                      <Pencil aria-hidden="true" />
                                      Ubah pola
                                  </Button>
                              )
                            : undefined
                    }
                />
            </div>
            <EditPatternDialog
                key={editing?.id ?? 'tertutup'}
                sequence={editing}
                resetOptions={resetOptions}
                onClose={() => setEditing(null)}
            />
        </>
    );
}

function EditPatternDialog({
    sequence,
    resetOptions,
    onClose,
}: {
    sequence: SequenceRow | null;
    resetOptions: Option[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [pattern, setPattern] = useState(sequence?.pattern ?? '');
    const [reset, setReset] = useState(sequence?.reset_period ?? 'never');
    const [preview, setPreview] = useState<{
        numbers: string[];
        errors: string[];
    } | null>(null);
    const [processing, setProcessing] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);

    // Pratinjau langsung saat mengetik, ditunda sebentar agar tidak membanjiri server.
    useEffect(() => {
        if (!sequence) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch('/pengaturan/penomoran/pratinjau', {
                method: 'POST',
                signal: controller.signal,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({
                    sequence_id: sequence.id,
                    pattern,
                    reset_period: reset,
                }),
            })
                .then((res) => (res.ok ? res.json() : null))
                .then((data) => {
                    if (data) {
                        setPreview(data);
                    }
                })
                .catch(() => {
                    // Dibatalkan karena pengguna masih mengetik; abaikan.
                });
        }, PREVIEW_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [sequence, pattern, reset]);

    const insertToken = (token: string) => {
        const input = inputRef.current;
        const start = input?.selectionStart ?? pattern.length;
        const end = input?.selectionEnd ?? pattern.length;
        const next = pattern.slice(0, start) + token + pattern.slice(end);
        setPattern(next);

        requestAnimationFrame(() => {
            input?.focus();
            input?.setSelectionRange(
                start + token.length,
                start + token.length,
            );
        });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!sequence) {
            return;
        }

        router.put(
            `/pengaturan/penomoran/${sequence.id}`,
            { pattern, reset_period: reset },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    const patternErrors = preview?.errors ?? [];
    const invalid = patternErrors.length > 0;

    return (
        <FormDialog
            open={sequence !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Hash}
            title={`Ubah pola · ${sequence?.name ?? ''}`}
            description="Perubahan berlaku untuk nomor berikutnya. Nomor yang sudah terbit tidak berubah."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing || invalid}>
                        Simpan Pola
                    </Button>
                </>
            }
        >
            <FormStatus
                tone="error"
                message={errors.pattern ?? errors.reset_period}
            />

            <div className="grid gap-2">
                <Label htmlFor="sequence-pattern">Pola</Label>
                <Input
                    id="sequence-pattern"
                    ref={inputRef}
                    value={pattern}
                    onChange={(e) => setPattern(e.target.value)}
                    className="font-mono"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={100}
                    aria-invalid={invalid || undefined}
                    aria-describedby="sequence-pattern-help"
                />
                <p
                    id="sequence-pattern-help"
                    className="text-xs text-muted-foreground"
                >
                    Teks tetap ditulis langsung (huruf besar, angka, atau - / .
                    _). Klik token untuk menyisipkannya.
                </p>
                <div
                    className="flex flex-wrap gap-1.5"
                    aria-label="Token yang tersedia"
                >
                    {TOKENS.map((t) => (
                        <button
                            key={t.token}
                            type="button"
                            onClick={() => insertToken(t.token)}
                            title={t.help}
                            className="rounded-md border bg-muted/50 px-2 py-1 font-mono text-xs transition-colors hover:border-primary/40 hover:bg-primary/10 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                        >
                            {t.token}
                            <span className="sr-only">: {t.help}</span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="sequence-reset">Periode reset</Label>
                <NativeSelect
                    id="sequence-reset"
                    value={reset}
                    onChange={(e) => setReset(e.target.value)}
                >
                    {resetOptions.map((o) => (
                        <option key={o.value} value={o.value}>
                            {o.label}
                        </option>
                    ))}
                </NativeSelect>
            </div>

            <section
                aria-live="polite"
                className="rounded-lg border bg-muted/40 p-3.5"
            >
                <h3 className="mb-2 flex items-center gap-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    <Hash className="size-3.5" aria-hidden="true" />
                    Pratinjau 3 nomor berikutnya
                </h3>
                {invalid ? (
                    <ul className="space-y-1 text-sm text-status-danger">
                        {patternErrors.map((message) => (
                            <li key={message}>{message}</li>
                        ))}
                    </ul>
                ) : preview ? (
                    <ol className="flex flex-wrap gap-2">
                        {preview.numbers.map((n) => (
                            <li
                                key={n}
                                className="rounded-md bg-card px-2.5 py-1 font-mono text-sm shadow-xs"
                            >
                                {n}
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Memuat pratinjau…
                    </p>
                )}
            </section>
        </FormDialog>
    );
}

SequencesIndex.layout = {
    breadcrumbs: [
        { title: 'Pengaturan', href: '/pengaturan' },
        { title: 'Penomoran', href: '/pengaturan/penomoran' },
    ],
};
