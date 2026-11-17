import { Head, useForm } from '@inertiajs/react';
import { ArrowRightLeft, Biohazard, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { NativeSelect } from '@/components/pakis/native-select';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatCivilDate } from '@/lib/local-date';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type StockBatchRow = {
    id: number;
    sku: string;
    name: string;
    unit: string;
    batch_no: string;
    expiry_date: string | null;
    location: { id: number; name: string };
    bin: { id: number; code: string; zone: string } | null;
    status: string;
    status_label: string;
    qty_on_hand: string;
    qty_available: string;
    pending_release: boolean;
};

type Props = ListingProps<StockBatchRow> & {
    reasons: Array<{ value: number; label: string }>;
};

type Action = 'quarantine' | 'move' | 'release';

/** "48.000" → "48" (isian awal, koma desimal Indonesia). */
const toInput = (q: string) =>
    q
        .replace(/\.0+$/, '')
        .replace(/(\.\d*?)0+$/, '$1')
        .replace('.', ',');

/**
 * Halaman Karantina (BR-164, keputusan Mas PA-69): batch ber-stok di gudang/toko dalam scope Stok.
 * Karantina memindah qty ke rak karantina (tanpa approval); lepas karantina diajukan dan baru
 * dipindah setelah disetujui; batch kedaluwarsa tidak bisa dilepas, hanya dimusnahkan/diretur.
 */
export default function QuarantineIndex({ rows, listing, reasons }: Props) {
    const [open, setOpen] = useState<{
        action: Action;
        row: StockBatchRow;
    } | null>(null);

    const columns: DataTableColumn<StockBatchRow>[] = [
        {
            key: 'product',
            header: 'Produk',
            sortKey: 'product',
            primary: true,
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-medium break-words">{r.name}</span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku}
                    </span>
                </span>
            ),
        },
        {
            key: 'batch',
            header: 'Batch',
            sortKey: 'batch_no',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span className="font-mono text-sm">{r.batch_no}</span>
                    {r.expiry_date && (
                        <span className="text-xs text-muted-foreground">
                            ED {formatCivilDate(r.expiry_date)}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'place',
            header: 'Lokasi · rak',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span>{r.location.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {r.bin ? `Rak ${r.bin.code}` : 'Tanpa rak'}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <span className="grid justify-items-start gap-1">
                    <StatusBadge
                        status={`stock_batch.${r.status}`}
                        label={r.status_label}
                    />
                    {r.pending_release && (
                        <span className="text-xs text-muted-foreground">
                            Menunggu persetujuan lepas
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'qty',
            header: 'Tersedia',
            className: 'text-right tabular-nums',
            cell: (r) =>
                `${formatQty(r.qty_available)} / ${formatQty(r.qty_on_hand)} ${r.unit.toLowerCase()}`,
        },
        {
            key: 'actions',
            header: 'Aksi',
            cell: (r) => (
                <span className="flex flex-wrap gap-1.5">
                    {r.status === 'available' && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setOpen({ action: 'quarantine', row: r })
                                }
                            >
                                <Biohazard aria-hidden="true" />
                                Karantina
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setOpen({ action: 'move', row: r })
                                }
                            >
                                <ArrowRightLeft aria-hidden="true" />
                                Pindah Rak
                            </Button>
                        </>
                    )}
                    {r.status === 'quarantine' && !r.pending_release && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setOpen({ action: 'release', row: r })
                            }
                        >
                            <ShieldCheck aria-hidden="true" />
                            Lepas
                        </Button>
                    )}
                </span>
            ),
        },
    ];

    return (
        <>
            <Head title="Karantina" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Karantina
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pisahkan batch rusak atau meragukan ke rak karantina,
                        pindahkan antar rak, atau ajukan pelepasan karantina.
                        Batch karantina tidak pernah dijual.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar batch"
                />
            </div>
            {open && (
                <ActionDialog
                    action={open.action}
                    row={open.row}
                    reasons={reasons}
                    onClose={() => setOpen(null)}
                />
            )}
        </>
    );
}

const COPY = {
    quarantine: {
        title: 'Karantina batch',
        description:
            'Qty dipindah ke rak karantina lokasi ini dan tidak bisa dijual sampai dilepas lewat persetujuan.',
        button: 'Karantina',
        icon: Biohazard,
    },
    move: {
        title: 'Pindah rak',
        description:
            'Pindah ke rak lain di lokasi yang sama. Untuk rak karantina, pakai aksi Karantina.',
        button: 'Pindahkan',
        icon: ArrowRightLeft,
    },
    release: {
        title: 'Ajukan lepas karantina',
        description:
            'Qty dipindah ke rak pilihan setelah disetujui penanggung jawab gudang.',
        button: 'Ajukan',
        icon: ShieldCheck,
    },
} as const;

function ActionDialog({
    action,
    row,
    reasons,
    onClose,
}: {
    action: Action;
    row: StockBatchRow;
    reasons: Props['reasons'];
    onClose: () => void;
}) {
    const copy = COPY[action];
    const form = useForm({
        stock_batch_id: row.id,
        qty_base: toInput(row.qty_available),
        reason_id: '',
        stock_bin_id: '',
        target_bin_id: '',
        reason: '',
        note: '',
    });
    const err = form.errors as Record<string, string | undefined>;
    const binKey = action === 'release' ? 'target_bin_id' : 'stock_bin_id';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const url =
            action === 'quarantine'
                ? '/stok/karantina'
                : action === 'move'
                  ? '/stok/pindah-rak'
                  : `/stok/karantina/${row.id}/lepas`;
        form.post(url, { preserveScroll: true, onSuccess: () => onClose() });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={copy.icon}
            title={copy.title}
            description={copy.description}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {copy.button}
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                <span className="font-medium">{row.name}</span> · batch{' '}
                <span className="font-mono">{row.batch_no}</span> · tersedia{' '}
                {formatQty(row.qty_available)} {row.unit.toLowerCase()}
            </p>
            <InputError message={err.stock_batch_id ?? err.form} />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="q-qty">Jumlah</Label>
                    <Input
                        id="q-qty"
                        inputMode="decimal"
                        autoComplete="off"
                        className="text-right tabular-nums"
                        value={form.data.qty_base}
                        onChange={(e) =>
                            form.setData('qty_base', e.target.value)
                        }
                    />
                    <InputError message={err.qty_base} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="q-bin">
                        {action === 'quarantine'
                            ? 'Rak karantina (kosong = Q-01)'
                            : 'Rak tujuan'}
                    </Label>
                    <AsyncSelect
                        id="q-bin"
                        source="stock_bins"
                        params={{ parent: String(row.location.id) }}
                        value={
                            form.data[binKey] === '' ? [] : [form.data[binKey]]
                        }
                        onChange={(v) =>
                            form.setData(binKey, String(v[0] ?? ''))
                        }
                    />
                    <InputError message={err[binKey]} />
                </div>
                {action === 'quarantine' && (
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="q-reason">Alasan karantina</Label>
                        <NativeSelect
                            id="q-reason"
                            value={form.data.reason_id}
                            onChange={(e) =>
                                form.setData('reason_id', e.target.value)
                            }
                        >
                            <option value="">Pilih alasan</option>
                            {reasons.map((r) => (
                                <option key={r.value} value={r.value}>
                                    {r.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={err.reason_id} />
                    </div>
                )}
                {action === 'release' && (
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="q-release-reason">Alasan lepas</Label>
                        <Input
                            id="q-release-reason"
                            maxLength={255}
                            placeholder="Mis. hasil uji laboratorium sesuai standar"
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                        />
                        <InputError message={err.reason} />
                    </div>
                )}
                {action !== 'release' && (
                    <div className="grid gap-2 sm:col-span-2">
                        <Label htmlFor="q-note">Catatan (opsional)</Label>
                        <Input
                            id="q-note"
                            maxLength={255}
                            value={form.data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </div>
                )}
            </div>
        </FormDialog>
    );
}

QuarantineIndex.layout = {
    breadcrumbs: [{ title: 'Karantina', href: '/stok/karantina' }],
};
