import { CircleAlert, CircleCheck, Route, Search } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { NativeSelect } from '@/components/pakis/native-select';
import { MoneyInput } from '@/components/pakis/setting-field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { xsrfToken } from '@/lib/approval-format';
import { preview } from '@/routes/admin/approval-rules';

type DocumentType = { value: string; label: string; conditions: string[] };

export type ChainPreview = {
    rule: { id: number; name: string; priority: number } | null;
    auto_approve: boolean;
    missing: boolean;
    steps: {
        step_order: number;
        approver_type: string;
        approver_label: string;
        escalate_after_hours: number;
        escalation_label: string;
        holders: string[];
        holders_count: number | null;
    }[];
};

type ErrorBody = {
    error?: { message?: string; details?: Record<string, string[]> };
    message?: string;
    errors?: Record<string, string[]>;
};

/**
 * Panel "Pratinjau rantai" (FR-A02): siapa menyetujui dokumen jenis X senilai Y menurut aturan yang
 * berlaku SEKARANG. Memakai RuleMatcher yang sama dengan pengajuan sungguhan (server), jadi hasilnya
 * tidak bisa berbeda dengan yang terjadi nanti. Hanya membaca: tanpa konfirmasi password.
 */
export function ApprovalChainPreview({
    documentTypes,
}: {
    documentTypes: DocumentType[];
}) {
    const [documentType, setDocumentType] = useState(
        documentTypes[0]?.value ?? '',
    );
    const [amount, setAmount] = useState('');
    const [conditions, setConditions] = useState<Record<string, string>>({});
    const [result, setResult] = useState<ChainPreview | null>(null);
    const [errors, setErrors] = useState<Record<string, string | undefined>>(
        {},
    );
    const [failed, setFailed] = useState<string | undefined>();
    const [loading, setLoading] = useState(false);
    const keys =
        documentTypes.find((t) => t.value === documentType)?.conditions ?? [];

    const submit = (e: FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        setFailed(undefined);
        const filled = Object.fromEntries(
            keys
                .map((k) => [k, (conditions[k] ?? '').trim()] as const)
                .filter(([, v]) => v !== ''),
        );

        fetch(preview().url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrfToken(),
            },
            body: JSON.stringify({
                document_type: documentType,
                amount: amount === '' ? null : amount,
                conditions: filled,
            }),
        })
            .then(async (res) => {
                const body = (await res.json().catch(() => ({}))) as
                    | { data: ChainPreview }
                    | ErrorBody;
                if (res.ok && 'data' in body) {
                    setResult(body.data);

                    return;
                }
                const err = body as ErrorBody;
                const details = err.error?.details ?? err.errors ?? {};
                setErrors(
                    Object.fromEntries(
                        Object.entries(details).map(([k, v]) => [k, v[0]]),
                    ),
                );
                setResult(null);
                if (Object.keys(details).length === 0) {
                    setFailed(
                        err.error?.message ??
                            err.message ??
                            'Pratinjau tidak bisa dimuat. Coba lagi.',
                    );
                }
            })
            .catch(() => setFailed('Pratinjau tidak bisa dimuat. Coba lagi.'))
            .finally(() => setLoading(false));
    };

    return (
        <section
            className="rounded-xl border bg-card p-4 shadow-md"
            aria-labelledby="chain-preview-title"
        >
            <h2
                id="chain-preview-title"
                className="flex items-center gap-2 text-base font-bold"
            >
                <Route className="size-4 text-primary" aria-hidden="true" />
                Pratinjau rantai
            </h2>
            <p className="mb-3 text-sm text-muted-foreground">
                Cek siapa yang akan menyetujui dokumen dengan nominal tertentu
                menurut aturan aktif.
            </p>
            <form
                onSubmit={submit}
                noValidate
                className="flex flex-wrap items-end gap-3"
            >
                <div className="grid min-w-52 flex-1 gap-2 sm:max-w-xs">
                    <Label htmlFor="preview-document-type">Jenis dokumen</Label>
                    <NativeSelect
                        id="preview-document-type"
                        value={documentType}
                        onChange={(e) => {
                            setDocumentType(e.target.value);
                            setConditions({});
                            setResult(null);
                        }}
                    >
                        {documentTypes.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.document_type} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="preview-amount">
                        Nominal{' '}
                        <span className="font-normal text-muted-foreground">
                            (opsional)
                        </span>
                    </Label>
                    <MoneyInput
                        id="preview-amount"
                        value={amount}
                        onChange={(v) =>
                            setAmount(typeof v === 'string' ? v : '')
                        }
                        invalid={Boolean(errors.amount)}
                    />
                    <InputError message={errors.amount} />
                </div>
                {keys.map((key) => (
                    <div key={key} className="grid gap-2">
                        <Label htmlFor={`preview-condition-${key}`}>
                            <span className="font-mono text-xs">{key}</span>
                        </Label>
                        <Input
                            id={`preview-condition-${key}`}
                            className="w-40"
                            value={conditions[key] ?? ''}
                            onChange={(e) =>
                                setConditions({
                                    ...conditions,
                                    [key]: e.target.value,
                                })
                            }
                        />
                    </div>
                ))}
                <Button
                    type="submit"
                    variant="outline"
                    disabled={loading || documentType === ''}
                >
                    {loading ? <Spinner /> : <Search aria-hidden="true" />}
                    Lihat rantai
                </Button>
            </form>

            <FormStatus tone="error" message={failed} className="mt-3" />

            {result && <PreviewResult result={result} />}
        </section>
    );
}

function PreviewResult({ result }: { result: ChainPreview }) {
    if (result.rule === null) {
        return (
            <div
                role="status"
                className="mt-3 flex items-start gap-2 rounded-lg bg-muted/60 px-3 py-2.5 text-sm"
            >
                {result.auto_approve ? (
                    <>
                        <CircleCheck
                            className="mt-0.5 size-4 shrink-0 text-status-success"
                            aria-hidden="true"
                        />
                        Tidak ada aturan yang cocok: dokumen langsung disetujui
                        otomatis (pengaturan persetujuan).
                    </>
                ) : (
                    <>
                        <CircleAlert
                            className="mt-0.5 size-4 shrink-0 text-status-danger"
                            aria-hidden="true"
                        />
                        Tidak ada aturan yang cocok: dokumen seperti ini belum
                        bisa diajukan sampai ada aturan yang cocok.
                    </>
                )}
            </div>
        );
    }

    return (
        <div className="mt-3 grid gap-2" role="status">
            <p className="text-sm">
                Aturan terpakai:{' '}
                <span className="font-semibold">{result.rule.name}</span>{' '}
                <span className="text-muted-foreground">
                    (prioritas {result.rule.priority})
                </span>
            </p>
            <ol className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                {result.steps.map((s) => (
                    <li
                        key={s.step_order}
                        className="grid gap-1 rounded-lg border p-3 text-sm"
                    >
                        <span className="text-xs font-semibold text-muted-foreground">
                            Langkah {s.step_order}
                        </span>
                        <span className="font-medium break-words">
                            {s.approver_label}
                        </span>
                        {s.holders_count !== null && (
                            <span
                                className={
                                    s.holders_count === 0
                                        ? 'text-xs text-status-danger'
                                        : 'text-xs text-muted-foreground'
                                }
                            >
                                {s.holders_count === 0
                                    ? 'Belum ada pengguna aktif yang memegang izin ini.'
                                    : `${s.holders.join(', ')}${s.holders_count > s.holders.length ? ` +${s.holders_count - s.holders.length} lainnya` : ''}`}
                            </span>
                        )}
                        <span className="text-xs text-muted-foreground">
                            Eskalasi setelah {s.escalate_after_hours} jam ·{' '}
                            {s.escalation_label.replace(/^Eskalasi: /, '')}
                        </span>
                    </li>
                ))}
            </ol>
        </div>
    );
}
