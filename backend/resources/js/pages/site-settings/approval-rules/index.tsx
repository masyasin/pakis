import { Head, Link, router } from '@inertiajs/react';
import { Archive, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ApprovalChainPreview } from '@/components/pakis/approval-chain-preview';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { DialogClose } from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { create, destroy, edit, index } from '@/routes/admin/approval-rules';
import type { ListingProps } from '@/types/listing';

export type DocumentTypeOption = {
    value: string;
    label: string;
    conditions: string[];
};

type RuleRow = {
    id: number;
    document_type: string;
    document_type_label: string;
    name: string;
    min_amount: string | null;
    max_amount: string | null;
    min_amount_label: string | null;
    max_amount_label: string | null;
    conditions: Record<string, Array<string | number>> | null;
    priority: number;
    is_active: boolean;
    used: boolean;
    steps: { step_order: number; label: string }[];
};

type Props = ListingProps<RuleRow> & {
    documentTypes: DocumentTypeOption[];
};

/**
 * Rentang nominal aturan: batas bawah inklusif, batas atas eksklusif (15 §4.2) → "≥ Rp 25.000.000",
 * "< Rp 5.000.000", "Rp 5.000.000 s.d. < Rp 25.000.000"; tanpa batas = "Semua nominal".
 */
export function amountRange(min: string | null, max: string | null): string {
    if (min && max) {
        return `${min} s.d. < ${max}`;
    }
    if (min) {
        return `≥ ${min}`;
    }
    if (max) {
        return `< ${max}`;
    }

    return 'Semua nominal';
}

const columns: DataTableColumn<RuleRow>[] = [
    {
        key: 'name',
        header: 'Aturan',
        sortKey: 'name',
        primary: true,
        cell: (r) => <span className="font-medium">{r.name}</span>,
    },
    {
        key: 'document_type',
        header: 'Jenis dokumen',
        sortKey: 'document_type',
        cell: (r) => <span className="text-sm">{r.document_type_label}</span>,
    },
    {
        key: 'amount',
        header: 'Nominal',
        cell: (r) => (
            <span className="text-sm whitespace-nowrap tabular-nums">
                {amountRange(r.min_amount_label, r.max_amount_label)}
            </span>
        ),
    },
    {
        key: 'steps',
        header: 'Rantai penyetuju',
        cell: (r) => (
            <ol className="grid gap-0.5 text-sm">
                {r.steps.map((s) => (
                    <li key={s.step_order} className="break-words">
                        <span className="text-muted-foreground tabular-nums">
                            {s.step_order}.
                        </span>{' '}
                        {s.label}
                    </li>
                ))}
            </ol>
        ),
    },
    {
        key: 'priority',
        header: 'Prioritas',
        sortKey: 'priority',
        className: 'tabular-nums',
        cell: (r) => r.priority,
    },
    {
        key: 'status',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={
                    r.is_active
                        ? 'approval_rule.active'
                        : 'approval_rule.inactive'
                }
            />
        ),
    },
];

/**
 * Aturan Persetujuan (15 §5, FR-A02; listing `approval_rules`). Aturan dicek per jenis dokumen
 * urut prioritas (kecil dulu); aturan yang sudah dipakai permintaan hanya bisa dinonaktifkan.
 */
export default function ApprovalRulesIndex({
    rows,
    listing,
    documentTypes,
}: Props) {
    const [removing, setRemoving] = useState<RuleRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const addButton = (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Aturan
            </Link>
        </Button>
    );

    const remove = () => {
        if (!removing) {
            return;
        }
        router.delete(destroy(removing.id).url, {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    };

    return (
        <>
            <Head title="Aturan Persetujuan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Aturan Persetujuan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Siapa menyetujui dokumen apa, pada nominal berapa.
                        Perubahan hanya berlaku untuk pengajuan baru; permintaan
                        yang sedang berjalan tetap memakai aturan lama.
                    </p>
                </div>

                <ApprovalChainPreview documentTypes={documentTypes} />

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar aturan"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={(r) => (
                        <div className="flex justify-end gap-1">
                            <Button variant="ghost" size="sm" asChild>
                                <Link
                                    href={edit(r.id)}
                                    aria-label={`Ubah aturan ${r.name}`}
                                >
                                    <Pencil aria-hidden="true" />
                                    Ubah
                                </Link>
                            </Button>
                            {(!r.used || r.is_active) && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    aria-label={`${r.used ? 'Nonaktifkan' : 'Hapus'} aturan ${r.name}`}
                                    onClick={() => setRemoving(r)}
                                >
                                    {r.used ? (
                                        <Archive aria-hidden="true" />
                                    ) : (
                                        <Trash2 aria-hidden="true" />
                                    )}
                                </Button>
                            )}
                        </div>
                    )}
                />
            </div>

            <FormDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                icon={removing?.used ? Archive : Trash2}
                tone="danger"
                title={
                    removing?.used
                        ? `Nonaktifkan aturan "${removing.name}"?`
                        : `Hapus aturan "${removing?.name ?? ''}"?`
                }
                description={
                    removing?.used
                        ? 'Aturan ini sudah pernah dipakai permintaan persetujuan, jadi tidak dihapus melainkan dinonaktifkan: tidak dipakai untuk pengajuan baru, riwayatnya tetap utuh.'
                        : 'Aturan belum pernah dipakai, jadi dihapus permanen. Tindakan ini tercatat di audit.'
                }
                bodyClassName="hidden"
                footer={
                    <>
                        <DialogClose asChild>
                            <Button variant="outline">Batal</Button>
                        </DialogClose>
                        <Button
                            variant="destructive"
                            disabled={processing}
                            onClick={remove}
                        >
                            {processing && <Spinner />}
                            {removing?.used
                                ? 'Nonaktifkan Aturan'
                                : 'Hapus Aturan'}
                        </Button>
                    </>
                }
            >
                {null}
            </FormDialog>
        </>
    );
}

ApprovalRulesIndex.layout = {
    // Tanpa remah "Pengaturan": halaman itu butuh `settings.view`, yang belum tentu dimiliki.
    breadcrumbs: [{ title: 'Aturan Persetujuan', href: index() }],
};
