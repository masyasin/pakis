import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Pencil, Plus, Send, Tag, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { FormDialog } from '@/components/pakis/form-dialog';
import {
    SubmitVersionDialog,
    VersionBadge,
    VersionPeriod,
} from '@/components/pricing/version-ui';
import type { PriceVersion } from '@/components/pricing/version-ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { rememberLabels } from '@/lib/lookup';
import { formatRupiah, moneyInputValue } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';
import type { PriceListRow } from './index';

type ItemRow = {
    id: number;
    product_id: number;
    sku: string;
    name: string;
    product_unit_id: number;
    unit: string;
    min_qty: string;
    price: string;
};

type Props = ListingProps<ItemRow> & {
    list: PriceListRow;
    version: PriceVersion;
    can: { edit: boolean; publish: boolean; backdate: boolean };
};

type Tier = { min_qty: string; price: string };

/** Harga satu versi (15 §7.3 `price_list_items`). Versi draf bisa diisi; versi lain hanya dibaca. */
export default function PriceListItems({
    rows,
    listing,
    list,
    version,
    can,
}: Props) {
    const [editing, setEditing] = useState<ItemRow | 'new' | null>(null);
    const [submitting, setSubmitting] = useState(false);

    const remove = (row: ItemRow) =>
        router.put(
            `/daftar-harga/versi/${version.id}/item`,
            { items: [], remove: [row.id] },
            { preserveScroll: true },
        );

    const columns: DataTableColumn<ItemRow>[] = [
        {
            key: 'name',
            header: 'Produk',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <span>
                    <span className="block font-medium">{r.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.sku}
                    </span>
                </span>
            ),
        },
        { key: 'unit', header: 'Satuan', cell: (r) => r.unit },
        {
            key: 'min_qty',
            header: 'Mulai jumlah',
            cell: (r) => `≥ ${formatQty(r.min_qty)} ${r.unit.toLowerCase()}`,
        },
        {
            key: 'price',
            header: 'Harga',
            cell: (r) => (
                <span className="font-medium tabular-nums">
                    {formatRupiah(r.price)}
                </span>
            ),
        },
        ...(can.edit
            ? [
                  {
                      key: 'actions',
                      header: '',
                      cell: (r: ItemRow) => (
                          <span className="flex gap-1">
                              <Button
                                  size="sm"
                                  variant="outline"
                                  onClick={() => setEditing(r)}
                                  aria-label={`Ubah harga ${r.name}`}
                              >
                                  <Pencil aria-hidden="true" />
                              </Button>
                              <Button
                                  size="sm"
                                  variant="ghost"
                                  onClick={() => remove(r)}
                                  aria-label={`Hapus baris ${r.name}`}
                              >
                                  <Trash2 aria-hidden="true" />
                              </Button>
                          </span>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title={`${list.name} · versi ${version.version_no}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <Link
                    href={`/daftar-harga/${list.id}`}
                    className="inline-flex items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" aria-hidden="true" />
                    {list.name}
                </Link>
                <header className="flex flex-wrap items-start gap-4">
                    <div className="min-w-0 flex-1 space-y-1">
                        <h1 className="flex flex-wrap items-center gap-2 text-[1.4rem] leading-tight font-bold">
                            Versi {version.version_no}
                            <VersionBadge version={version} />
                        </h1>
                        <p className="text-sm">
                            <VersionPeriod version={version} />
                        </p>
                        {!can.edit && version.status === 'draft' && (
                            <p className="text-xs text-muted-foreground">
                                Anda hanya bisa melihat harga versi ini.
                            </p>
                        )}
                        {version.status !== 'draft' && (
                            <p className="text-xs text-muted-foreground">
                                Versi ini sudah diajukan atau terbit sehingga
                                harganya terkunci. Buat versi baru untuk
                                perubahan.
                            </p>
                        )}
                    </div>
                    {can.edit && (
                        <Button onClick={() => setSubmitting(true)}>
                            <Send aria-hidden="true" />
                            {can.publish ? 'Terbitkan Versi' : 'Ajukan Versi'}
                        </Button>
                    )}
                </header>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Harga per produk"
                    toolbarActions={
                        <>
                            <ExportButton
                                href={`/daftar-harga/versi/${version.id}/item/ekspor`}
                                total={listing.pagination.total}
                            />
                            {can.edit && (
                                <Button onClick={() => setEditing('new')}>
                                    <Plus aria-hidden="true" />
                                    Tambah Harga
                                </Button>
                            )}
                        </>
                    }
                />
            </div>

            {editing !== null && (
                <PriceEditor
                    versionId={version.id}
                    row={editing === 'new' ? null : editing}
                    tiers={
                        editing === 'new'
                            ? []
                            : rows.filter(
                                  (r) =>
                                      r.product_unit_id ===
                                      editing.product_unit_id,
                              )
                    }
                    onClose={() => setEditing(null)}
                />
            )}
            {submitting && (
                <SubmitVersionDialog
                    version={version}
                    canPublish={can.publish}
                    canBackdate={can.backdate}
                    onClose={() => setSubmitting(false)}
                />
            )}
        </>
    );
}

/**
 * Isi harga satu satuan produk: beberapa baris tier (`min_qty` → harga). Baris lama satuan itu yang
 * dihapus dari editor ikut dihapus di server.
 */
function PriceEditor({
    versionId,
    row,
    tiers,
    onClose,
}: {
    versionId: number;
    row: ItemRow | null;
    tiers: ItemRow[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    useState(() => {
        if (row) {
            rememberLabels('products', [
                { value: row.product_id, label: `${row.sku} · ${row.name}` },
            ]);
            rememberLabels('product_units', [
                { value: row.product_unit_id, label: row.unit },
            ]);
        }

        return true;
    });
    const [productId, setProductId] = useState(
        row ? String(row.product_id) : '',
    );
    const [unitId, setUnitId] = useState(
        row ? String(row.product_unit_id) : '',
    );
    const [lines, setLines] = useState<Tier[]>(
        tiers.length > 0
            ? tiers
                  .slice()
                  .sort((a, b) => Number(a.min_qty) - Number(b.min_qty))
                  .map((t) => ({
                      min_qty: formatQty(t.min_qty),
                      price: moneyInputValue(t.price),
                  }))
            : [{ min_qty: '1', price: '' }],
    );
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const kept = new Set(lines.map((l) => l.min_qty));
        router.put(
            `/daftar-harga/versi/${versionId}/item`,
            {
                items: lines.map((l) => ({
                    product_unit_id: Number(unitId),
                    min_qty: l.min_qty,
                    price: l.price,
                })),
                // Tier lama yang dihapus dari editor (dibandingkan dari teks yang sama).
                remove: tiers
                    .filter((t) => !kept.has(formatQty(t.min_qty)))
                    .map((t) => t.id),
            },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Tag}
            size="lg"
            title={row ? `Ubah harga · ${row.name}` : 'Tambah harga produk'}
            description="Harga per satuan. Tambahkan baris untuk harga bertingkat, mis. mulai 10 botol lebih murah. Harga dipilih dari baris dengan jumlah minimal terbesar yang terpenuhi."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        disabled={processing || unitId === ''}
                    >
                        Simpan Harga
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="pi-product">Produk</Label>
                    <AsyncSelect
                        id="pi-product"
                        source="products"
                        value={productId === '' ? [] : [productId]}
                        disabled={row !== null}
                        onChange={(v) => {
                            setProductId(String(v[0] ?? ''));
                            setUnitId('');
                        }}
                    />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="pi-unit">Satuan</Label>
                    <AsyncSelect
                        id="pi-unit"
                        source="product_units"
                        params={{ parent: productId }}
                        value={unitId === '' ? [] : [unitId]}
                        disabled={row !== null || productId === ''}
                        onChange={(v) => setUnitId(String(v[0] ?? ''))}
                    />
                    <InputError message={errors['items.0.product_unit_id']} />
                </div>
            </div>
            <ul className="space-y-2">
                {lines.map((line, index) => (
                    <li
                        key={index}
                        className="grid grid-cols-[1fr_1.5fr_auto] items-end gap-2"
                    >
                        <div className="grid gap-1.5">
                            <Label htmlFor={`pi-min-${index}`}>
                                Mulai jumlah
                            </Label>
                            <Input
                                id={`pi-min-${index}`}
                                inputMode="decimal"
                                value={line.min_qty}
                                onChange={(e) =>
                                    setLines(
                                        lines.map((l, i) =>
                                            i === index
                                                ? {
                                                      ...l,
                                                      min_qty: e.target.value,
                                                  }
                                                : l,
                                        ),
                                    )
                                }
                            />
                            <InputError
                                message={errors[`items.${index}.min_qty`]}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={`pi-price-${index}`}>
                                Harga (Rp)
                            </Label>
                            <Input
                                id={`pi-price-${index}`}
                                inputMode="decimal"
                                value={line.price}
                                placeholder="mis. 12.500"
                                onChange={(e) =>
                                    setLines(
                                        lines.map((l, i) =>
                                            i === index
                                                ? {
                                                      ...l,
                                                      price: e.target.value,
                                                  }
                                                : l,
                                        ),
                                    )
                                }
                            />
                            <InputError
                                message={errors[`items.${index}.price`]}
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            disabled={lines.length === 1}
                            aria-label="Hapus baris tier"
                            onClick={() =>
                                setLines(lines.filter((_, i) => i !== index))
                            }
                        >
                            <Trash2 aria-hidden="true" />
                        </Button>
                    </li>
                ))}
            </ul>
            <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setLines([...lines, { min_qty: '', price: '' }])}
            >
                <Plus aria-hidden="true" />
                Tambah Tingkat Harga
            </Button>
        </FormDialog>
    );
}

PriceListItems.layout = {
    breadcrumbs: [{ title: 'Daftar Harga', href: '/daftar-harga' }],
};
