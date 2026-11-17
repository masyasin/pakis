import { CopyCheck, ExternalLink, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CUSTOMER_STATUS_TONE } from '@/components/customer/customer-ui';
import type { CustomerStatus } from '@/components/customer/customer-ui';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhone } from '@/lib/employee-format';
import { postJson } from '@/lib/json-post';
import { duplicates, show } from '@/routes/admin/customers';

export type DuplicateCandidate = {
    code: string;
    store_name: string;
    status: CustomerStatus;
    status_label: string;
    /** `phone`, `name_nearby`, `name_same_village`. */
    reasons: string[];
    in_scope: boolean;
    id: number | null;
    // Hanya untuk kios dalam scope pengguna (PA-52): di luar scope server tidak mengirimnya.
    similarity?: number;
    distance_m?: number | null;
    owner_name?: string;
    owner_phone?: string;
    village?: string | null;
    sales_area?: string | null;
};

export type DuplicateResult = {
    phone_conflict: { code: string; message: string } | null;
    candidates: DuplicateCandidate[];
};

export type DuplicateInput = {
    store_name: string;
    owner_phone: string;
    latitude: string;
    longitude: string;
    village_code: string;
    customer_id: number | null;
};

const REASON_LABELS: Record<string, string> = {
    phone: 'HP pemilik sama',
    name_nearby: 'Nama mirip & lokasinya berdekatan',
    name_same_village: 'Nama mirip di desa yang sama',
};

/**
 * Jeda ketik sebelum memeriksa (teknis UI, bukan parameter bisnis): cukup lama agar tidak memanggil
 * server tiap huruf — endpoint juga dibatasi laju per pengguna.
 */
const DEBOUNCE_MS = 700;
const VILLAGE_PATTERN = /^\d{2}\.\d{2}\.\d{2}\.\d{4}$/;

/**
 * Bentuk HP yang akan lolos `PhoneNormalizer` di server (`62` + 8–13 digit). HP yang masih diketik
 * setengah tidak dikirim: server akan menolak seluruh permintaan (422) dan kandidat nama ikut hilang.
 */
export function plausiblePhone(input: string): string | null {
    const digits = input.replace(/\D/g, '');
    const normalized = digits.startsWith('0') ? `62${digits.slice(1)}` : digits;

    return /^62\d{8,13}$/.test(normalized) ? normalized : null;
}

/** Isian form → body `admin.customers.duplicates`; null bila nama toko belum cukup untuk dicek. */
export function duplicateRequestBody(
    input: DuplicateInput,
): Record<string, string | number | null> | null {
    const name = input.store_name.trim();
    if (name.length < 2) {
        return null;
    }
    const lat = input.latitude.trim();
    const lng = input.longitude.trim();
    const hasPoint =
        lat !== '' &&
        lng !== '' &&
        Number.isFinite(Number(lat)) &&
        Number.isFinite(Number(lng));

    return {
        store_name: name,
        owner_phone: plausiblePhone(input.owner_phone),
        latitude: hasPoint ? lat : null,
        longitude: hasPoint ? lng : null,
        village_code: VILLAGE_PATTERN.test(input.village_code)
            ? input.village_code
            : null,
        customer_id: input.customer_id,
    };
}

/**
 * Cek duplikat kios sambil mengisi form (BR-166(2), T-074): dipanggil ulang setelah jeda ketik saat
 * nama, HP, titik, atau desa berubah. Server tetap memeriksa ulang saat simpan (DuplicateGuard).
 */
export function useDuplicateCheck(input: DuplicateInput) {
    const [result, setResult] = useState<DuplicateResult | null>(null);
    const [checking, setChecking] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const body = duplicateRequestBody(input);
    const key = body === null ? '' : JSON.stringify(body);
    const latest = useRef(0);

    useEffect(() => {
        if (key === '') {
            setResult(null);
            setNotice(null);

            return;
        }
        const ticket = ++latest.current;
        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setChecking(true);
            postJson<DuplicateResult>(
                duplicates().url,
                JSON.parse(key),
                controller.signal,
            )
                .then((res) => {
                    // Jawaban lama yang datang belakangan tidak boleh menimpa yang baru.
                    if (ticket !== latest.current) {
                        return;
                    }
                    if (res.ok) {
                        setResult(res.data);
                        setNotice(null);
                    } else if (res.status === 429) {
                        setNotice(
                            'Pemeriksaan kios mirip terlalu sering. Tunggu sebentar; saat disimpan kios tetap diperiksa.',
                        );
                    } else if (res.status !== 422) {
                        // 422 = isian belum lengkap/sah; galatnya muncul saat simpan.
                        setNotice(
                            'Kios mirip belum bisa diperiksa sekarang. Saat disimpan kios tetap diperiksa.',
                        );
                    }
                })
                .catch(() => undefined)
                .finally(() => {
                    if (ticket === latest.current) {
                        setChecking(false);
                    }
                });
        }, DEBOUNCE_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [key]);

    return { result, checking, notice };
}

/** Ringkasan di form: jumlah kios mirip + tombol membuka dialog. */
export function DuplicateNotice({
    count,
    confirmed,
    checking,
    notice,
    error,
    onReview,
}: {
    count: number;
    confirmed: boolean;
    checking: boolean;
    notice: string | null;
    error?: string;
    onReview: () => void;
}) {
    if (count === 0) {
        return notice ? (
            <p className="text-xs text-muted-foreground" role="status">
                {notice}
            </p>
        ) : checking ? (
            <p className="text-xs text-muted-foreground" role="status">
                Memeriksa kios mirip…
            </p>
        ) : null;
    }

    return (
        <div
            className={
                confirmed
                    ? 'flex flex-wrap items-start gap-3 rounded-lg border bg-card px-3.5 py-3 text-sm'
                    : 'flex flex-wrap items-start gap-3 rounded-lg bg-status-warning-bg px-3.5 py-3 text-sm text-status-warning'
            }
            role="status"
        >
            {confirmed ? (
                <CopyCheck
                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            ) : (
                <TriangleAlert
                    className="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
            )}
            <div className="min-w-0 flex-1 space-y-1">
                <p className="font-medium">
                    {confirmed
                        ? `Dikonfirmasi bukan duplikat dari ${count} kios mirip.`
                        : `Ada ${count} kios yang mirip dengan isian ini.`}
                </p>
                {!confirmed && (
                    <p className="text-xs">
                        Periksa dulu agar satu toko tidak tercatat dua kali.
                        Bila memang toko berbeda, tandai "Bukan duplikat" dan
                        tulis alasannya.
                    </p>
                )}
                <InputError message={error} />
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={onReview}
            >
                Periksa Kios Mirip
            </Button>
        </div>
    );
}

/** Dialog daftar kandidat + konfirmasi "bukan duplikat" + alasan (BR-166(3)). */
export function DuplicateDialog({
    open,
    onOpenChange,
    candidates,
    notDuplicate,
    reason,
    onConfirmChange,
    errors,
    onSave,
    saving,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    candidates: DuplicateCandidate[];
    notDuplicate: boolean;
    reason: string;
    onConfirmChange: (value: { notDuplicate: boolean; reason: string }) => void;
    errors: { not_duplicate?: string; duplicate_reason?: string };
    /** Simpan form sekarang (setelah konfirmasi). */
    onSave: () => void;
    saving: boolean;
}) {
    const [localError, setLocalError] = useState<string | null>(null);
    const ready = notDuplicate && reason.trim().length >= 5;

    return (
        <FormDialog
            open={open}
            onOpenChange={onOpenChange}
            size="lg"
            icon={TriangleAlert}
            title="Kios mirip ditemukan"
            description="Pastikan toko ini belum tercatat. Bila salah satu di bawah adalah toko yang sama, batalkan dan pakai kios yang sudah ada."
            footer={
                <>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Kembali ke Form
                    </Button>
                    <Button
                        type="button"
                        disabled={saving}
                        onClick={() => {
                            if (!ready) {
                                setLocalError(
                                    'Centang "Bukan duplikat" dan tulis alasan minimal 5 karakter.',
                                );

                                return;
                            }
                            setLocalError(null);
                            onSave();
                        }}
                    >
                        Bukan Duplikat, Simpan
                    </Button>
                </>
            }
        >
            <ul className="grid gap-3">
                {candidates.map((c) => (
                    <li
                        key={c.code}
                        className="space-y-1.5 rounded-lg border bg-card p-3 text-sm"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{c.store_name}</span>
                            <span className="font-mono text-xs text-muted-foreground">
                                {c.code}
                            </span>
                            <StatusBadge
                                status={`customer.${c.status}`}
                                label={c.status_label}
                                tone={CUSTOMER_STATUS_TONE[c.status]}
                            />
                        </div>
                        <ul className="flex flex-wrap gap-1.5">
                            {c.reasons.map((r) => (
                                <li
                                    key={r}
                                    className="rounded-full bg-status-warning-bg px-2 py-0.5 text-xs text-status-warning"
                                >
                                    {REASON_LABELS[r] ?? r}
                                </li>
                            ))}
                        </ul>
                        {c.in_scope ? (
                            <p className="text-xs text-muted-foreground">
                                {[
                                    c.owner_name,
                                    c.owner_phone
                                        ? c.owner_phone.includes('•')
                                            ? c.owner_phone
                                            : formatPhone(c.owner_phone)
                                        : null,
                                    c.village,
                                    c.sales_area,
                                    c.distance_m !== null &&
                                    c.distance_m !== undefined
                                        ? `± ${c.distance_m} m dari titik ini`
                                        : null,
                                    c.similarity !== undefined
                                        ? `nama mirip ${Math.round(c.similarity * 100)}%`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        ) : (
                            <p className="text-xs text-muted-foreground">
                                Kios di luar wilayah Anda. Tanyakan ke admin
                                penjualan bila ragu.
                            </p>
                        )}
                        {c.in_scope && c.id !== null && (
                            <a
                                href={show(c.id).url}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline"
                            >
                                Buka kios (tab baru)
                                <ExternalLink
                                    className="size-3"
                                    aria-hidden="true"
                                />
                            </a>
                        )}
                    </li>
                ))}
            </ul>

            <div className="space-y-3 rounded-lg border p-3">
                <div className="flex items-start gap-2.5">
                    <Checkbox
                        id="not_duplicate"
                        checked={notDuplicate}
                        onCheckedChange={(v) =>
                            onConfirmChange({
                                notDuplicate: v === true,
                                reason,
                            })
                        }
                    />
                    <Label htmlFor="not_duplicate" className="leading-snug">
                        Bukan duplikat: toko ini berbeda dari semua kios di
                        atas.
                    </Label>
                </div>
                <InputError message={errors.not_duplicate} />
                <div className="grid gap-2">
                    <Label htmlFor="duplicate_reason">Alasan</Label>
                    <textarea
                        id="duplicate_reason"
                        className="min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        value={reason}
                        maxLength={255}
                        rows={2}
                        placeholder="Pemilik berbeda, toko cabang di desa sebelah."
                        onChange={(e) =>
                            onConfirmChange({
                                notDuplicate,
                                reason: e.target.value,
                            })
                        }
                        aria-invalid={
                            errors.duplicate_reason ? true : undefined
                        }
                    />
                    <InputError message={errors.duplicate_reason} />
                </div>
                {localError && (
                    <p className="text-sm text-destructive" role="alert">
                        {localError}
                    </p>
                )}
            </div>
        </FormDialog>
    );
}
