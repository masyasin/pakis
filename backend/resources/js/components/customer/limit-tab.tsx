import { Link, useForm } from '@inertiajs/react';
import { Clock, CreditCard, ShieldAlert, TrendingUp } from 'lucide-react';
import type { FormEvent } from 'react';
import type { ChangeState } from '@/components/customer/customer-ui';
import { DetailCard, Field, FieldGrid } from '@/components/employee/detail-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { MoneyInput, trimZeroFraction } from '@/components/pakis/money-input';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTimeIn } from '@/lib/approval-format';
import { formatRupiah } from '@/lib/money';
import { show as approvalShow } from '@/routes/admin/approvals';
import { creditLimit, show } from '@/routes/admin/customers';

type Props = {
    customerId: number;
    /** Limit tersimpan, rupiah kanonik ("5000000.00"). */
    current: string;
    active: boolean;
    termDays: number;
    state: ChangeState['credit_limit'];
    timezone: string;
};

/**
 * Pemeriksaan awal pengajuan limit (BR-125) sebelum dikirim: nilai wajib diisi, beda dari limit
 * sekarang, dan alasan minimal 5 karakter. Batas rantai persetujuan tidak diperiksa di sini (aturan
 * persetujuan bisa diubah tanpa rilis).
 */
export function creditLimitIssues(
    value: string,
    reason: string,
    current: string,
): Record<string, string> {
    const issues: Record<string, string> = {};
    if (value === '') {
        issues.credit_limit = 'Isi limit kredit baru (0 = tanpa limit).';
    } else if (value.startsWith('-')) {
        issues.credit_limit = 'Limit kredit tidak boleh negatif.';
    } else if (trimZeroFraction(value) === trimZeroFraction(current)) {
        issues.credit_limit = 'Limit baru sama dengan limit sekarang.';
    }
    if (reason.trim().length < 5) {
        issues.reason = 'Tulis alasan perubahan limit, minimal 5 karakter.';
    }

    return issues;
}

/** Tab Limit detail kios (15 §7.1 `?tab=limit`, `admin.customers.credit-limit`, BR-125, T-074). */
export function LimitTab({
    customerId,
    current,
    active,
    termDays,
    state,
    timezone,
}: Props) {
    const pending = state.pending;

    return (
        <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <div className="space-y-4">
                <DetailCard title="Limit sekarang" icon={CreditCard}>
                    <FieldGrid>
                        <Field
                            label="Limit kredit"
                            value={
                                <span className="text-base font-semibold tabular-nums">
                                    {formatRupiah(current)}
                                </span>
                            }
                        />
                        <Field
                            label="Status limit"
                            value={
                                <StatusBadge
                                    status={
                                        active
                                            ? 'credit_limit.active'
                                            : 'credit_limit.inactive'
                                    }
                                    label={active ? 'Aktif' : 'Belum aktif'}
                                    tone={active ? 'success' : 'muted'}
                                />
                            }
                        />
                        <Field
                            label="Tempo standar"
                            value={`${termDays} hari`}
                        />
                    </FieldGrid>
                    {!active && (
                        <p className="mt-3 text-xs text-muted-foreground">
                            Limit baru berlaku setelah kios terverifikasi.
                            Sebelum itu kios hanya bisa order tunai.
                        </p>
                    )}
                </DetailCard>
                {pending && (
                    <DetailCard
                        title="Menunggu persetujuan"
                        icon={Clock}
                        action={
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={approvalShow(
                                        pending.approval_request_id,
                                    )}
                                >
                                    Buka persetujuan
                                </Link>
                            </Button>
                        }
                    >
                        <FieldGrid>
                            <Field
                                label="Limit diajukan"
                                value={formatRupiah(pending.amount)}
                            />
                            <Field
                                label="Diajukan oleh"
                                value={pending.requested_by?.name}
                                hint={
                                    pending.created_at
                                        ? formatDateTimeIn(
                                              pending.created_at,
                                              timezone,
                                          )
                                        : undefined
                                }
                            />
                        </FieldGrid>
                        <p className="mt-3 text-xs text-muted-foreground">
                            Limit sekarang tetap berlaku sampai persetujuan
                            selesai.
                        </p>
                    </DetailCard>
                )}
            </div>
            <div className="space-y-4">
                {state.blocked_reason === 'not_verified' ? (
                    <div className="flex items-start gap-3 rounded-xl border border-dashed bg-card/60 p-4 text-sm sm:p-5">
                        <ShieldAlert
                            className="mt-0.5 size-5 shrink-0 text-status-warning"
                            aria-hidden="true"
                        />
                        <div className="space-y-2">
                            <p className="font-medium">
                                Kios belum terverifikasi
                            </p>
                            <p className="text-muted-foreground">
                                Limit kredit hanya bisa diajukan untuk kios yang
                                sudah terverifikasi. Ajukan limit awal bersama
                                verifikasi kios.
                            </p>
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={show(customerId, {
                                        query: { tab: 'verifikasi' },
                                    })}
                                >
                                    Buka tab Verifikasi
                                </Link>
                            </Button>
                        </div>
                    </div>
                ) : state.can_request ? (
                    <RequestForm customerId={customerId} current={current} />
                ) : (
                    !pending && (
                        <p className="rounded-xl border border-dashed bg-card/60 p-4 text-sm text-muted-foreground sm:p-5">
                            Limit kredit hanya bisa diajukan oleh pengelola kios
                            untuk kios berstatus Aktif atau Tahan kredit.
                        </p>
                    )
                )}
            </div>
        </div>
    );
}

function RequestForm({
    customerId,
    current,
}: {
    customerId: number;
    current: string;
}) {
    const form = useForm({ credit_limit: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const local = creditLimitIssues(
            form.data.credit_limit,
            form.data.reason,
            current,
        );
        if (Object.keys(local).length > 0) {
            form.clearErrors();
            form.setError(local);

            return;
        }
        form.post(creditLimit(customerId).url, {
            preserveScroll: true,
            onSuccess: () => form.reset(),
        });
    };

    return (
        <DetailCard title="Ajukan limit baru" icon={TrendingUp}>
            <form onSubmit={submit} className="grid gap-4" noValidate>
                <FormStatus tone="error" message={errors.form} />
                <div className="grid gap-2">
                    <Label htmlFor="limit-amount">Limit kredit baru</Label>
                    <MoneyInput
                        id="limit-amount"
                        value={form.data.credit_limit}
                        onChange={(v) => form.setData('credit_limit', v)}
                        invalid={Boolean(errors.credit_limit)}
                        placeholder="0"
                        describedBy="limit-amount-hint"
                    />
                    <p
                        id="limit-amount-hint"
                        className="text-xs text-muted-foreground"
                    >
                        Isi 0 untuk mencabut limit. Persetujuan mengikuti aturan
                        limit kredit; nominal besar bisa butuh persetujuan
                        berlapis.
                    </p>
                    <InputError message={errors.credit_limit} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="limit-reason">Alasan</Label>
                    <Input
                        id="limit-reason"
                        maxLength={255}
                        placeholder="mis. omzet 3 bulan terakhir naik, bayar selalu tepat waktu"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        aria-invalid={errors.reason ? true : undefined}
                    />
                    <InputError message={errors.reason} />
                </div>
                <div className="flex justify-end">
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Ajukan Limit
                    </Button>
                </div>
            </form>
        </DetailCard>
    );
}
