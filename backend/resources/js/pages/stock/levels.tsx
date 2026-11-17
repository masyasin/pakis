import { Head, useForm } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type StockLevelRow = {
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    qty_on_hand: string;
    min_qty_base: string | null;
    max_qty_base: string | null;
    below_min: boolean;
};

type Props = ListingProps<StockLevelRow> & { canManage: boolean };

/** "48.000" → "48" (isian awal, koma desimal Indonesia). */
const toInput = (q: string | null) =>
    q === null
        ? ''
        : q
              .replace(/\.0+$/, '')
              .replace(/(\.\d*?)0+$/, '$1')
              .replace('.', ',');

/**
 * Stok minimum/maksimum (15 §7.3 `stock_levels`): satu lokasi stok per tampilan (filter wajib),
 * semua produk aktif, termasuk yang belum diberi batas. Ubah lewat dialog (`stock_locations.manage`);
 * impor massal tetap lewat Impor Master tipe `stock_levels`.
 */
export default function StockLevels({ rows, listing, canManage }: Props) {
    const [editing, setEditing] = useState<StockLevelRow | null>(null);
    const raw = listing.state.filters.stock_location_id;
    const first: unknown = Array.isArray(raw) ? raw[0] : raw;
    const locationId =
        typeof first === 'string' || typeof first === 'number'
            ? String(first)
            : '';

    const columns: DataTableColumn<StockLevelRow>[] = [
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
            key: 'on_hand',
            header: 'Stok',
            className: 'text-right tabular-nums',
            cell: (r) => (
                <span className={r.below_min ? 'text-destructive' : undefined}>
                    {formatQty(r.qty_on_hand)} {r.unit.toLowerCase()}
                </span>
            ),
        },
        {
            key: 'min',
            header: 'Minimum',
            className: 'text-right tabular-nums',
            cell: (r) => (r.min_qty_base ? formatQty(r.min_qty_base) : '–'),
        },
        {
            key: 'max',
            header: 'Maksimum',
            className: 'text-right tabular-nums',
            cell: (r) => (r.max_qty_base ? formatQty(r.max_qty_base) : '–'),
        },
        ...(canManage && locationId !== ''
            ? [
                  {
                      key: 'actions',
                      header: 'Aksi',
                      cell: (r: StockLevelRow) => (
                          <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setEditing(r)}
                          >
                              Ubah
                          </Button>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Stok Minimum" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Stok Minimum
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Batas minimum memicu peringatan stok di dasbor. Pilih
                        lokasi stok di filter; angka dalam satuan dasar produk.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    rowKey={(r) => r.product_id}
                    title="Stok minimum per produk"
                />
            </div>
            {editing && (
                <LevelDialog
                    row={editing}
                    locationId={locationId}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function LevelDialog({
    row,
    locationId,
    onClose,
}: {
    row: StockLevelRow;
    locationId: string;
    onClose: () => void;
}) {
    const form = useForm({
        product_id: row.product_id,
        stock_location_id: locationId,
        min_qty_base: toInput(row.min_qty_base),
        max_qty_base: toInput(row.max_qty_base),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/stok/minimum', {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Gauge}
            title="Ubah stok minimum"
            description={`${row.name} (${row.sku}), satuan ${row.unit.toLowerCase()}.`}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Kembali
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Simpan
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="min_qty_base">Minimum</Label>
                    <Input
                        id="min_qty_base"
                        inputMode="decimal"
                        value={form.data.min_qty_base}
                        onChange={(e) =>
                            form.setData('min_qty_base', e.target.value)
                        }
                        required
                    />
                    <InputError message={form.errors.min_qty_base} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="max_qty_base">Maksimum (opsional)</Label>
                    <Input
                        id="max_qty_base"
                        inputMode="decimal"
                        value={form.data.max_qty_base}
                        onChange={(e) =>
                            form.setData('max_qty_base', e.target.value)
                        }
                    />
                    <InputError message={form.errors.max_qty_base} />
                </div>
            </div>
            <InputError
                message={
                    (form.errors as Record<string, string | undefined>)
                        .stock_location_id
                }
            />
        </FormDialog>
    );
}

StockLevels.layout = {
    breadcrumbs: [{ title: 'Stok Minimum', href: '/stok/minimum' }],
};
