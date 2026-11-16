import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Plus, UserMinus, UsersRound } from 'lucide-react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { DialogClose } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatCivilRange } from '@/lib/local-date';
import { destroy, index, store } from '@/routes/admin/approval-delegations';
import { index as approvalsIndex } from '@/routes/admin/approvals';
import type { ListingProps } from '@/types/listing';

type Option = { value: string; label: string };

type DelegationRow = {
    id: number;
    delegator: { id: number; name: string } | null;
    delegate: { id: number; name: string } | null;
    start_date: string;
    end_date: string;
    document_types: Option[] | null;
    reason: string;
    state: 'active' | 'scheduled' | 'expired' | 'revoked';
    can: { revoke: boolean };
};

type Props = ListingProps<DelegationRow> & {
    documentTypes: Option[];
    today: string;
    can: { configure: boolean };
};

const textareaClass =
    'min-h-16 w-full rounded-md border border-input bg-card px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30';

const columns: DataTableColumn<DelegationRow>[] = [
    {
        key: 'delegate',
        header: 'Penerima',
        primary: true,
        cell: (r) => (
            <span className="grid">
                <span className="font-medium">{r.delegate?.name ?? '–'}</span>
                <span className="text-xs text-muted-foreground">
                    dari {r.delegator?.name ?? '–'}
                </span>
            </span>
        ),
    },
    {
        key: 'start_date',
        header: 'Masa berlaku',
        sortKey: 'start_date',
        cell: (r) => (
            <span className="whitespace-nowrap tabular-nums">
                {formatCivilRange(r.start_date, r.end_date)}
            </span>
        ),
    },
    {
        key: 'document_types',
        header: 'Jenis dokumen',
        cell: (r) =>
            r.document_types === null ? (
                <span className="text-muted-foreground">Semua jenis</span>
            ) : (
                <span className="text-sm">
                    {r.document_types.map((t) => t.label).join(', ')}
                </span>
            ),
    },
    {
        key: 'reason',
        header: 'Alasan',
        cell: (r) => (
            <span className="line-clamp-2 text-sm break-words">{r.reason}</span>
        ),
    },
    {
        key: 'state',
        header: 'Status',
        cell: (r) => <StatusBadge status={`delegation.${r.state}`} />,
    },
];

/**
 * Delegasi persetujuan (15 §5, BR-153(7); listing `approval_delegations`). Selama masa berlaku,
 * penerima boleh memutuskan langkah yang menunggu pemberi; keputusan tercatat "atas nama" pemberi.
 */
export default function ApprovalDelegations({
    rows,
    listing,
    documentTypes,
    today,
    can,
}: Props) {
    const [creating, setCreating] = useState(false);
    const [revoking, setRevoking] = useState<DelegationRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addButton = (
        <Button onClick={() => setCreating(true)}>
            <Plus aria-hidden="true" />
            Buat Delegasi
        </Button>
    );

    const revoke = () => {
        if (!revoking) {
            return;
        }
        router.delete(destroy(revoking.id).url, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            // Galat (mis. bukan pemberi) sudah menjadi toast dari server.
            onFinish: () => {
                setProcessing(false);
                setRevoking(null);
            },
        });
    };

    return (
        <>
            <Head title="Delegasi Persetujuan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <Button variant="ghost" size="sm" asChild>
                    <Link href={approvalsIndex()}>
                        <ArrowLeft aria-hidden="true" />
                        Kotak persetujuan
                    </Link>
                </Button>
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Delegasi Persetujuan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Saat Anda cuti atau dinas, serahkan hak memutuskan ke
                        rekan selama tanggal tertentu.{' '}
                        {can.configure
                            ? 'Anda melihat semua delegasi karena memegang izin atur persetujuan.'
                            : 'Daftar ini berisi delegasi yang Anda beri atau terima.'}
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar delegasi"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={(r) =>
                        r.can.revoke &&
                        (r.state === 'active' || r.state === 'scheduled') ? (
                            <div className="flex justify-end">
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Cabut delegasi ke ${r.delegate?.name ?? ''}`}
                                    onClick={() => setRevoking(r)}
                                >
                                    <UserMinus aria-hidden="true" />
                                    Cabut
                                </Button>
                            </div>
                        ) : null
                    }
                />
            </div>

            {creating && (
                <CreateDelegationDialog
                    documentTypes={documentTypes}
                    today={today}
                    canPickDelegator={can.configure}
                    onClose={() => setCreating(false)}
                />
            )}

            <FormDialog
                open={revoking !== null}
                onOpenChange={(open) => !open && setRevoking(null)}
                icon={UserMinus}
                tone="danger"
                title="Cabut delegasi?"
                description={
                    revoking && (
                        <>
                            {revoking.delegate?.name} tidak lagi bisa memutuskan
                            atas nama {revoking.delegator?.name} (
                            {formatCivilRange(
                                revoking.start_date,
                                revoking.end_date,
                            )}
                            ). Keputusan yang sudah diambil tetap berlaku.
                        </>
                    )
                }
                bodyClassName="hidden"
                footer={
                    <>
                        <DialogClose asChild>
                            <Button variant="outline">Tidak</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={revoke}
                        >
                            {processing && <Spinner />}
                            Cabut Delegasi
                        </Button>
                    </>
                }
            >
                {null}
            </FormDialog>
        </>
    );
}

function CreateDelegationDialog({
    documentTypes,
    today,
    canPickDelegator,
    onClose,
}: {
    documentTypes: Option[];
    today: string;
    canPickDelegator: boolean;
    onClose: () => void;
}) {
    const form = useForm<{
        delegator_user_id: number | null;
        delegate_user_id: number | null;
        start_date: string;
        end_date: string;
        document_types: string[];
        reason: string;
    }>({
        delegator_user_id: null,
        delegate_user_id: null,
        start_date: today,
        end_date: '',
        document_types: [],
        reason: '',
    });
    const { data, errors } = form;
    const errs = errors as Record<string, string | undefined>;
    const typesError =
        errs.document_types ??
        Object.entries(errs).find(([k]) =>
            k.startsWith('document_types.'),
        )?.[1];

    const toggleType = (value: string, on: boolean) =>
        form.setData(
            'document_types',
            on
                ? [...data.document_types, value]
                : data.document_types.filter((t) => t !== value),
        );

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d,
            // Kosong = berlaku untuk semua jenis dokumen (server menyimpan null).
            document_types: d.document_types.length ? d.document_types : null,
        }));
        form.post(store().url, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <FormDialog
            open
            onOpenChange={(open) => !open && onClose()}
            icon={UsersRound}
            size="lg"
            title="Buat delegasi persetujuan"
            description="Penerima bisa memutuskan langkah yang menunggu pemberi selama tanggal ini. Pemberi tetap bisa memutuskan sendiri."
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            Batal
                        </Button>
                    </DialogClose>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Simpan Delegasi
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errs.form} />
            <div className="grid gap-4 sm:grid-cols-2">
                {canPickDelegator && (
                    <div className="grid content-start gap-2">
                        <Label htmlFor="delegator_user_id">
                            Pemberi{' '}
                            <span className="font-normal text-muted-foreground">
                                (kosong = Anda sendiri)
                            </span>
                        </Label>
                        <AsyncSelect
                            id="delegator_user_id"
                            source="approval_delegates"
                            value={
                                data.delegator_user_id === null
                                    ? []
                                    : [data.delegator_user_id]
                            }
                            onChange={(v) =>
                                form.setData(
                                    'delegator_user_id',
                                    v.length ? Number(v[0]) : null,
                                )
                            }
                            placeholder="Ketik nama atau username…"
                            aria-label="Pemberi delegasi"
                        />
                        <InputError message={errs.delegator_user_id} />
                    </div>
                )}
                <div className="grid content-start gap-2">
                    <Label htmlFor="delegate_user_id">Penerima</Label>
                    <AsyncSelect
                        id="delegate_user_id"
                        source="approval_delegates"
                        value={
                            data.delegate_user_id === null
                                ? []
                                : [data.delegate_user_id]
                        }
                        onChange={(v) =>
                            form.setData(
                                'delegate_user_id',
                                v.length ? Number(v[0]) : null,
                            )
                        }
                        placeholder="Ketik nama atau username…"
                        aria-label="Penerima delegasi"
                    />
                    <InputError message={errs.delegate_user_id} />
                </div>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="start_date">Mulai tanggal</Label>
                    <Input
                        id="start_date"
                        type="date"
                        value={data.start_date}
                        min={today}
                        onChange={(e) =>
                            form.setData('start_date', e.target.value)
                        }
                        aria-invalid={errs.start_date ? true : undefined}
                    />
                    <InputError message={errs.start_date} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="end_date">Sampai tanggal</Label>
                    <Input
                        id="end_date"
                        type="date"
                        value={data.end_date}
                        min={data.start_date || today}
                        onChange={(e) =>
                            form.setData('end_date', e.target.value)
                        }
                        aria-invalid={errs.end_date ? true : undefined}
                    />
                    <InputError message={errs.end_date} />
                </div>
            </div>
            <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">
                    Jenis dokumen{' '}
                    <span className="font-normal text-muted-foreground">
                        (tidak dipilih = semua jenis)
                    </span>
                </legend>
                <div className="grid gap-x-4 gap-y-2 sm:grid-cols-2">
                    {documentTypes.map((t) => {
                        const id = `delegation-type-${t.value}`;

                        return (
                            <label
                                key={t.value}
                                htmlFor={id}
                                className="flex cursor-pointer items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    id={id}
                                    checked={data.document_types.includes(
                                        t.value,
                                    )}
                                    onCheckedChange={(on) =>
                                        toggleType(t.value, on === true)
                                    }
                                />
                                {t.label}
                            </label>
                        );
                    })}
                </div>
                <InputError message={typesError} />
            </fieldset>
            <div className="grid gap-2">
                <Label htmlFor="reason">Alasan</Label>
                <textarea
                    id="reason"
                    className={textareaClass}
                    value={data.reason}
                    maxLength={255}
                    rows={2}
                    placeholder="Mis. cuti tahunan 20–24 Nov"
                    onChange={(e) => form.setData('reason', e.target.value)}
                    aria-invalid={errs.reason ? true : undefined}
                />
                <InputError message={errs.reason} />
            </div>
        </FormDialog>
    );
}

ApprovalDelegations.layout = {
    breadcrumbs: [
        { title: 'Persetujuan', href: approvalsIndex() },
        { title: 'Delegasi', href: index() },
    ],
};
