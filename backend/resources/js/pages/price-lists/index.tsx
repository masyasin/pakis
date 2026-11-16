import { Head, Link, router, usePage } from '@inertiajs/react';
import { BadgeDollarSign, Pencil, Plus } from 'lucide-react';
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
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ListingProps } from '@/types/listing';

export type PriceListRow = {
    id: number;
    code: string;
    name: string;
    kind: 'tier' | 'area' | 'store' | 'default';
    kind_label: string;
    target: string | null;
    description: string | null;
    is_active: boolean;
    current_version?: number | null;
};

type Props = ListingProps<PriceListRow> & {
    kinds: { value: string; label: string }[];
    canManage: boolean;
};

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

/** Daftar harga (15 §7.3 `price_lists`, FR-A19). Harga ada di versi; buka list untuk melihat versinya. */
export default function PriceListsIndex({
    rows,
    listing,
    kinds,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<PriceListRow | 'new' | null>(null);

    const columns: DataTableColumn<PriceListRow>[] = [
        {
            key: 'name',
            header: 'Daftar harga',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <Link
                    href={`/daftar-harga/${r.id}`}
                    className="hover:underline"
                >
                    <span className="block font-medium">{r.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.code}
                    </span>
                </Link>
            ),
        },
        {
            key: 'kind',
            header: 'Jenis',
            sortKey: 'kind',
            cell: (r) => (
                <span>
                    {r.kind_label}
                    {r.target && (
                        <span className="block text-xs text-muted-foreground">
                            {r.target}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'current_version',
            header: 'Versi berlaku',
            cell: (r) =>
                r.current_version ? (
                    `Versi ${r.current_version}`
                ) : (
                    <span className="text-muted-foreground">Belum ada</span>
                ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={r.is_active ? 'active' : 'inactive'}
                    label={r.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={r.is_active ? 'success' : 'muted'}
                />
            ),
        },
        ...(canManage
            ? [
                  {
                      key: 'actions',
                      header: '',
                      cell: (r: PriceListRow) => (
                          <Button
                              size="sm"
                              variant="outline"
                              onClick={() => setEditing(r)}
                              aria-label={`Ubah ${r.name}`}
                          >
                              <Pencil aria-hidden="true" />
                              Ubah
                          </Button>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Daftar Harga" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Daftar Harga
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Harga dipilih berurutan: toko (kasir), daftar harga
                        kios, area kios, lalu bawaan. Harga yang sudah terbit
                        tidak bisa diubah; perubahan dibuat sebagai versi baru
                        dengan tanggal berlaku.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar harga"
                    toolbarActions={
                        canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus aria-hidden="true" />
                                Tambah Daftar Harga
                            </Button>
                        )
                    }
                />
            </div>

            {editing !== null && (
                <PriceListDialog
                    item={editing === 'new' ? null : editing}
                    kinds={kinds}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

export function PriceListDialog({
    item,
    kinds,
    onClose,
}: {
    item: PriceListRow | null;
    kinds: { value: string; label: string }[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [form, setForm] = useState({
        code: item?.code ?? '',
        name: item?.name ?? '',
        kind: item?.kind ?? 'tier',
        sales_area_id: '',
        stock_location_id: '',
        description: item?.description ?? '',
        is_active: item?.is_active ?? true,
    });
    const [processing, setProcessing] = useState(false);
    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((f) => ({ ...f, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };
        if (item) {
            router.put(
                `/daftar-harga/${item.id}`,
                {
                    code: form.code,
                    name: form.name,
                    description: form.description,
                    is_active: form.is_active,
                },
                options,
            );
        } else {
            router.post(
                '/daftar-harga',
                {
                    ...form,
                    sales_area_id:
                        form.kind === 'area' && form.sales_area_id !== ''
                            ? Number(form.sales_area_id)
                            : null,
                    stock_location_id:
                        form.kind === 'store' && form.stock_location_id !== ''
                            ? Number(form.stock_location_id)
                            : null,
                },
                options,
            );
        }
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={BadgeDollarSign}
            size="lg"
            title={item ? `Ubah · ${item.name}` : 'Tambah daftar harga'}
            description="Jenis daftar harga tidak bisa diubah setelah dibuat. Hanya boleh ada satu daftar harga bawaan."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {item ? 'Simpan Daftar Harga' : 'Tambah Daftar Harga'}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="pl-code">Kode</Label>
                    <Input
                        id="pl-code"
                        value={form.code}
                        onChange={(e) =>
                            set('code', e.target.value.toUpperCase())
                        }
                        className="font-mono"
                        maxLength={20}
                        aria-invalid={!!errors.code || undefined}
                    />
                    <InputError message={errors.code} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="pl-name">Nama</Label>
                    <Input
                        id="pl-name"
                        value={form.name}
                        onChange={(e) => set('name', e.target.value)}
                        maxLength={100}
                        aria-invalid={!!errors.name || undefined}
                    />
                    <InputError message={errors.name} />
                </div>
                {!item && (
                    <div className="grid gap-2">
                        <Label htmlFor="pl-kind">Jenis</Label>
                        <NativeSelect
                            id="pl-kind"
                            value={form.kind}
                            onChange={(e) =>
                                set(
                                    'kind',
                                    e.target.value as PriceListRow['kind'],
                                )
                            }
                        >
                            {kinds.map((k) => (
                                <option key={k.value} value={k.value}>
                                    {k.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <InputError message={errors.kind} />
                    </div>
                )}
                {!item && form.kind === 'area' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pl-area">Area penjualan</Label>
                        <AsyncSelect
                            id="pl-area"
                            source="sales_areas"
                            value={
                                form.sales_area_id === ''
                                    ? []
                                    : [form.sales_area_id]
                            }
                            onChange={(v) =>
                                set('sales_area_id', String(v[0] ?? ''))
                            }
                        />
                        <InputError message={errors.sales_area_id} />
                    </div>
                )}
                {!item && form.kind === 'store' && (
                    <div className="grid gap-2">
                        <Label htmlFor="pl-store">Toko</Label>
                        <AsyncSelect
                            id="pl-store"
                            source="stock_locations"
                            params={{ type: 'store' }}
                            value={
                                form.stock_location_id === ''
                                    ? []
                                    : [form.stock_location_id]
                            }
                            onChange={(v) =>
                                set('stock_location_id', String(v[0] ?? ''))
                            }
                        />
                        <InputError message={errors.stock_location_id} />
                    </div>
                )}
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="pl-desc">Keterangan (opsional)</Label>
                    <Input
                        id="pl-desc"
                        value={form.description}
                        onChange={(e) => set('description', e.target.value)}
                        maxLength={255}
                    />
                </div>
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="pl-active"
                        checked={form.is_active}
                        onCheckedChange={(v) => set('is_active', v === true)}
                    />
                    <Label htmlFor="pl-active">Aktif</Label>
                </div>
            </div>
        </FormDialog>
    );
}

PriceListsIndex.layout = {
    breadcrumbs: [{ title: 'Daftar Harga', href: '/daftar-harga' }],
};
