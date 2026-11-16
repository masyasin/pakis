import { router, usePage } from '@inertiajs/react';
import {
    Ban,
    FileText,
    Pencil,
    Plus,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import {
    daysLeftLabel,
    RegistrationBadge,
} from '@/components/catalog/registration-ui';
import type { RegistrationRow } from '@/components/catalog/registration-ui';
import { DetailCard, EmptyPanel } from '@/components/employee/detail-ui';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCivilDate } from '@/lib/local-date';

export type RegistrationState = 'active' | 'expiring' | 'expired' | 'none';

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

type Props = {
    productId: number;
    requiresRegistration: boolean;
    state: RegistrationState;
    rows: RegistrationRow[];
    canManage: boolean;
};

/**
 * Tab Izin Edar di detail produk (FR-A10, BR-152). Perpanjangan = izin baru; izin lama tetap
 * tercatat. Produk wajib izin tanpa izin berlaku diberi peringatan (tidak bisa dialokasikan jual).
 */
export function RegistrationsPanel({
    productId,
    requiresRegistration,
    state,
    rows,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<RegistrationRow | 'new' | null>(
        null,
    );
    const [revoking, setRevoking] = useState<RegistrationRow | null>(null);
    const blocked =
        requiresRegistration && (state === 'expired' || state === 'none');

    return (
        <div className="space-y-5">
            {blocked && (
                <div
                    role="alert"
                    className="flex items-start gap-3 rounded-xl border border-destructive/40 bg-destructive/5 p-4 text-sm"
                >
                    <ShieldAlert
                        className="mt-0.5 size-5 shrink-0 text-destructive"
                        aria-hidden="true"
                    />
                    <div className="space-y-0.5">
                        <p className="font-semibold">
                            Produk wajib izin edar tanpa izin berlaku
                        </p>
                        <p className="text-muted-foreground">
                            Stok produk ini tidak bisa dialokasikan untuk dijual
                            atau dimutasi ke toko. Penerimaan ke karantina,
                            retur, dan pemusnahan tetap bisa.
                        </p>
                    </div>
                </div>
            )}

            <DetailCard
                title="Izin edar"
                icon={ShieldCheck}
                action={
                    canManage && (
                        <Button size="sm" onClick={() => setEditing('new')}>
                            <Plus aria-hidden="true" />
                            Tambah Izin
                        </Button>
                    )
                }
            >
                {rows.length === 0 ? (
                    <EmptyPanel
                        icon={ShieldCheck}
                        title="Belum ada izin edar"
                        text={
                            requiresRegistration
                                ? 'Produk ini wajib punya izin edar berlaku sebelum bisa dijual.'
                                : 'Produk ini tidak wajib izin edar, tetapi izin tetap bisa dicatat.'
                        }
                    />
                ) : (
                    <ul className="divide-y rounded-lg border">
                        {rows.map((r) => (
                            <li
                                key={r.id}
                                className="flex flex-wrap items-start justify-between gap-3 p-3 sm:p-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="font-mono text-sm font-semibold">
                                        {r.registration_no}
                                    </p>
                                    <p className="text-sm">
                                        Berlaku sampai{' '}
                                        {formatCivilDate(r.valid_until)}
                                        {r.days_left !== null && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {daysLeftLabel(r.days_left)}
                                            </span>
                                        )}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {r.holder_name ??
                                            'Pemegang belum diisi'}
                                        {r.issued_at &&
                                            ` · terbit ${formatCivilDate(r.issued_at)}`}
                                    </p>
                                    {r.status === 'revoked' && r.note && (
                                        <p className="text-xs">
                                            Alasan dicabut: {r.note}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap items-center gap-2 pt-0.5">
                                        <RegistrationBadge row={r} />
                                        {r.document_url && (
                                            <a
                                                href={r.document_url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex items-center gap-1 text-sm text-primary hover:underline"
                                            >
                                                <FileText
                                                    className="size-4"
                                                    aria-hidden="true"
                                                />
                                                Lihat berkas
                                            </a>
                                        )}
                                    </div>
                                </div>
                                {canManage && r.status === 'active' && (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setEditing(r)}
                                        >
                                            <Pencil aria-hidden="true" />
                                            Ubah
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => setRevoking(r)}
                                        >
                                            <Ban aria-hidden="true" />
                                            Cabut
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                <p className="mt-3 text-xs text-muted-foreground">
                    Untuk perpanjangan, tambahkan izin baru. Izin lama tidak
                    diubah agar riwayatnya utuh.
                </p>
            </DetailCard>

            {editing !== null && (
                <RegistrationDialog
                    productId={productId}
                    row={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
            <RevokeDialog
                productId={productId}
                row={revoking}
                onClose={() => setRevoking(null)}
            />
        </div>
    );
}

function RegistrationDialog({
    productId,
    row,
    onClose,
}: {
    productId: number;
    row: RegistrationRow | null;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [form, setForm] = useState({
        registration_no: row?.registration_no ?? '',
        holder_name: row?.holder_name ?? '',
        issued_at: row?.issued_at ?? '',
        valid_until: row?.valid_until ?? '',
        note: row?.note ?? '',
    });
    const [file, setFile] = useState<File | null>(null);
    const [processing, setProcessing] = useState(false);
    const set = (key: keyof typeof form, value: string) =>
        setForm((f) => ({ ...f, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload: Record<string, string | File> = { ...form };
        if (file) {
            payload.document = file;
        }
        const options = {
            ...SAVE_OPTIONS,
            forceFormData: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };

        if (row) {
            router.post(
                `/produk/${productId}/izin-edar/${row.id}`,
                { ...payload, _method: 'put' },
                options,
            );
        } else {
            router.post(`/produk/${productId}/izin-edar`, payload, options);
        }
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={ShieldCheck}
            size="lg"
            title={
                row ? `Ubah izin · ${row.registration_no}` : 'Tambah izin edar'
            }
            description="Nomor izin dari Kementan. Berkas PDF atau foto (JPG/PNG/WebP) disimpan privat."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {row ? 'Simpan Izin' : 'Tambah Izin'}
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.registration} />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="reg-no">Nomor izin</Label>
                    <Input
                        id="reg-no"
                        value={form.registration_no}
                        onChange={(e) =>
                            set('registration_no', e.target.value.toUpperCase())
                        }
                        className="font-mono"
                        maxLength={60}
                        autoComplete="off"
                        aria-invalid={!!errors.registration_no || undefined}
                    />
                    <InputError message={errors.registration_no} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="reg-holder">Pemegang izin (opsional)</Label>
                    <Input
                        id="reg-holder"
                        value={form.holder_name}
                        onChange={(e) => set('holder_name', e.target.value)}
                        maxLength={150}
                    />
                    <InputError message={errors.holder_name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="reg-issued">
                        Tanggal terbit (opsional)
                    </Label>
                    <Input
                        id="reg-issued"
                        type="date"
                        value={form.issued_at}
                        onChange={(e) => set('issued_at', e.target.value)}
                    />
                    <InputError message={errors.issued_at} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="reg-until">Berlaku sampai</Label>
                    <Input
                        id="reg-until"
                        type="date"
                        value={form.valid_until}
                        onChange={(e) => set('valid_until', e.target.value)}
                        aria-invalid={!!errors.valid_until || undefined}
                    />
                    <InputError message={errors.valid_until} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="reg-file">
                        Berkas izin{' '}
                        {row ? '(kosongkan bila tidak diganti)' : '(opsional)'}
                    </Label>
                    <Input
                        id="reg-file"
                        type="file"
                        accept="application/pdf,image/jpeg,image/png,image/webp"
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                    <InputError message={errors.document} />
                </div>
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="reg-note">Catatan (opsional)</Label>
                    <Input
                        id="reg-note"
                        value={form.note}
                        onChange={(e) => set('note', e.target.value)}
                        maxLength={255}
                    />
                </div>
            </div>
        </FormDialog>
    );
}

function RevokeDialog({
    productId,
    row,
    onClose,
}: {
    productId: number;
    row: RegistrationRow | null;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [reason, setReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!row) {
            return;
        }
        router.post(
            `/produk/${productId}/izin-edar/${row.id}/cabut`,
            { reason },
            {
                ...SAVE_OPTIONS,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => {
                    setReason('');
                    onClose();
                },
            },
        );
    };

    return (
        <FormDialog
            open={row !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Ban}
            tone="danger"
            title={`Cabut izin ${row?.registration_no ?? ''}?`}
            description="Izin yang dicabut tidak berlaku lagi dan tidak bisa dipulihkan. Alasan tercatat di riwayat."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing || reason.trim().length < 5}
                    >
                        Cabut Izin
                    </Button>
                </>
            }
        >
            <div className="grid gap-2">
                <Label htmlFor="revoke-reason">Alasan</Label>
                <Input
                    id="revoke-reason"
                    value={reason}
                    onChange={(e) => setReason(e.target.value)}
                    maxLength={255}
                    autoFocus
                    aria-invalid={!!errors.reason || undefined}
                />
                <InputError message={errors.reason} />
            </div>
        </FormDialog>
    );
}
