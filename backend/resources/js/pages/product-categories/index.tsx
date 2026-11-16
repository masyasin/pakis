import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Tags, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
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

type CategoryRow = {
    id: number;
    code: string;
    name: string;
    parent_id: number | null;
    parent_name: string | null;
    kind: string;
    kind_label: string;
    requires_batch: boolean;
    requires_expiry: boolean;
    requires_serial: boolean;
    requires_registration: boolean;
    default_bin_zone: string;
    sort_order: number;
    is_active: boolean;
};

type Option = { value: string; label: string };
type ParentOption = { value: number; label: string; is_child: boolean };

type Props = ListingProps<CategoryRow> & {
    kinds: Option[];
    binZones: Option[];
    parents: ParentOption[];
    canManage: boolean;
};

type Editing = { mode: 'create' } | { mode: 'edit'; item: CategoryRow };

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

type FlagKey =
    | 'requires_batch'
    | 'requires_expiry'
    | 'requires_serial'
    | 'requires_registration';

/** Penanda perilaku kategori (BR-151) sebagai label pendek di daftar. */
const FLAGS: { key: FlagKey; label: string; hint: string }[] = [
    {
        key: 'requires_batch',
        label: 'Batch',
        hint: 'Stok dicatat per nomor batch',
    },
    {
        key: 'requires_expiry',
        label: 'ED',
        hint: 'Tanggal kedaluwarsa wajib (butuh batch)',
    },
    {
        key: 'requires_serial',
        label: 'Seri',
        hint: 'Nomor seri per unit (alat)',
    },
    {
        key: 'requires_registration',
        label: 'Izin edar',
        hint: 'Produk wajib punya izin edar berlaku',
    },
];

/** Kategori produk (15 §5/§7, FR-A06, BR-151): pohon maks 3 tingkat dengan penanda perilaku. */
export default function ProductCategoriesIndex({
    rows,
    listing,
    kinds,
    binZones,
    parents,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [deleting, setDeleting] = useState<CategoryRow | null>(null);

    const columns: DataTableColumn<CategoryRow>[] = [
        {
            key: 'sort_order',
            header: 'Urutan',
            sortKey: 'sort_order',
            className: 'w-20 tabular-nums',
            cell: (r) => r.sort_order,
        },
        {
            key: 'code',
            header: 'Kode',
            sortKey: 'code',
            cell: (r) => <span className="font-mono text-xs">{r.code}</span>,
        },
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <span>
                    {r.name}
                    {r.parent_name && (
                        <span className="block text-xs text-muted-foreground">
                            di bawah {r.parent_name}
                        </span>
                    )}
                </span>
            ),
        },
        { key: 'kind', header: 'Jenis', cell: (r) => r.kind_label },
        {
            key: 'flags',
            header: 'Wajib',
            cell: (r) => {
                const on = FLAGS.filter((f) => r[f.key]);

                return on.length === 0 ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    <span className="flex flex-wrap gap-1">
                        {on.map((f) => (
                            <span
                                key={f.key}
                                title={f.hint}
                                className="rounded-md border bg-muted/50 px-1.5 py-0.5 text-xs"
                            >
                                {f.label}
                            </span>
                        ))}
                    </span>
                );
            },
        },
        {
            key: 'is_active',
            header: 'Aktif',
            cell: (r) => (
                <StatusBadge
                    status={r.is_active ? 'active' : 'inactive'}
                    label={r.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={r.is_active ? 'success' : 'muted'}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="Kategori Produk" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Kategori Produk
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kelompok produk sampai 3 tingkat. Penanda wajib batch,
                        ED, nomor seri, dan izin edar disalin ke produk baru di
                        kategori ini.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar kategori"
                    toolbarActions={
                        canManage ? (
                            <Button
                                onClick={() => setEditing({ mode: 'create' })}
                            >
                                <Plus aria-hidden="true" />
                                Tambah Kategori
                            </Button>
                        ) : undefined
                    }
                    rowActions={
                        canManage
                            ? (r) => (
                                  <div className="flex justify-end gap-1">
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          onClick={() =>
                                              setEditing({
                                                  mode: 'edit',
                                                  item: r,
                                              })
                                          }
                                          aria-label={`Ubah ${r.name}`}
                                      >
                                          <Pencil aria-hidden="true" />
                                          Ubah
                                      </Button>
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          className="text-status-danger hover:text-status-danger"
                                          onClick={() => setDeleting(r)}
                                          aria-label={`Hapus ${r.name}`}
                                      >
                                          <Trash2 aria-hidden="true" />
                                          Hapus
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            <CategoryDialog
                key={
                    editing?.mode === 'edit'
                        ? `ubah-${editing.item.id}`
                        : (editing?.mode ?? 'tutup')
                }
                editing={editing}
                kinds={kinds}
                binZones={binZones}
                parents={parents}
                onClose={() => setEditing(null)}
            />
            <DeleteDialog
                key={deleting?.id ?? 'tutup-hapus'}
                item={deleting}
                onClose={() => setDeleting(null)}
            />
        </>
    );
}

function CategoryDialog({
    editing,
    kinds,
    binZones,
    parents,
    onClose,
}: {
    editing: Editing | null;
    kinds: Option[];
    binZones: Option[];
    parents: ParentOption[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const item = editing?.mode === 'edit' ? editing.item : null;
    const [form, setForm] = useState({
        code: item?.code ?? '',
        name: item?.name ?? '',
        parent_id: item?.parent_id ? String(item.parent_id) : '',
        kind: item?.kind ?? kinds[0]?.value ?? 'other',
        requires_batch: item?.requires_batch ?? true,
        requires_expiry: item?.requires_expiry ?? true,
        requires_serial: item?.requires_serial ?? false,
        requires_registration: item?.requires_registration ?? true,
        default_bin_zone: item?.default_bin_zone ?? 'general',
        sort_order: String(item?.sort_order ?? 0),
        is_active: item?.is_active ?? true,
    });
    const [processing, setProcessing] = useState(false);
    const set = <K extends keyof typeof form>(
        key: K,
        value: (typeof form)[K],
    ) => setForm((f) => ({ ...f, [key]: value }));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload = {
            ...form,
            parent_id: form.parent_id === '' ? null : Number(form.parent_id),
            sort_order: form.sort_order === '' ? 0 : Number(form.sort_order),
        };
        const options = {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };

        if (item) {
            router.put(`/kategori-produk/${item.id}`, payload, options);
        } else {
            router.post('/kategori-produk', payload, options);
        }
    };

    // Kategori tidak bisa menjadi induk dirinya sendiri; aturan pohon lengkap dicek server.
    const parentOptions = parents.filter((p) => p.value !== item?.id);

    return (
        <FormDialog
            open={editing !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Tags}
            size="lg"
            title={item ? `Ubah · ${item.name}` : 'Tambah kategori'}
            description="Maksimal 3 tingkat. Penanda berlaku untuk produk baru di kategori ini."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {item ? 'Simpan Kategori' : 'Tambah Kategori'}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="category-code">Kode</Label>
                    <Input
                        id="category-code"
                        value={form.code}
                        onChange={(e) =>
                            set('code', e.target.value.toUpperCase())
                        }
                        className="font-mono"
                        autoComplete="off"
                        spellCheck={false}
                        maxLength={30}
                        aria-invalid={!!errors.code || undefined}
                    />
                    <InputError message={errors.code} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="category-name">Nama</Label>
                    <Input
                        id="category-name"
                        value={form.name}
                        onChange={(e) => set('name', e.target.value)}
                        maxLength={100}
                        aria-invalid={!!errors.name || undefined}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="category-parent">
                        Induk{' '}
                        <span className="font-normal text-muted-foreground">
                            (kosong = tingkat teratas)
                        </span>
                    </Label>
                    <NativeSelect
                        id="category-parent"
                        value={form.parent_id}
                        onChange={(e) => set('parent_id', e.target.value)}
                        aria-invalid={!!errors.parent_id || undefined}
                    >
                        <option value="">Tanpa induk</option>
                        {parentOptions.map((p) => (
                            <option key={p.value} value={String(p.value)}>
                                {p.is_child ? `— ${p.label}` : p.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.parent_id} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="category-kind">Jenis</Label>
                    <NativeSelect
                        id="category-kind"
                        value={form.kind}
                        onChange={(e) => set('kind', e.target.value)}
                    >
                        {kinds.map((k) => (
                            <option key={k.value} value={k.value}>
                                {k.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.kind} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="category-zone">Zona simpan bawaan</Label>
                    <NativeSelect
                        id="category-zone"
                        value={form.default_bin_zone}
                        onChange={(e) =>
                            set('default_bin_zone', e.target.value)
                        }
                    >
                        {binZones.map((z) => (
                            <option key={z.value} value={z.value}>
                                {z.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <InputError message={errors.default_bin_zone} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="category-sort">Urutan</Label>
                    <Input
                        id="category-sort"
                        type="number"
                        inputMode="numeric"
                        min={0}
                        value={form.sort_order}
                        onChange={(e) => set('sort_order', e.target.value)}
                        aria-invalid={!!errors.sort_order || undefined}
                    />
                    <InputError message={errors.sort_order} />
                </div>
            </div>

            <fieldset className="grid gap-3 rounded-lg border p-3">
                <legend className="px-1 text-sm font-medium">
                    Wajib untuk produk
                </legend>
                {FLAGS.map((f) => (
                    <div key={f.key} className="flex items-start gap-3">
                        <Checkbox
                            id={`category-${f.key}`}
                            checked={form[f.key]}
                            onCheckedChange={(v) => set(f.key, v === true)}
                        />
                        <div className="space-y-0.5">
                            <Label htmlFor={`category-${f.key}`}>
                                {f.label}
                            </Label>
                            <p className="text-xs text-muted-foreground">
                                {f.hint}
                            </p>
                        </div>
                    </div>
                ))}
                <InputError message={errors.requires_expiry} />
            </fieldset>

            <div className="flex items-start gap-3">
                <Checkbox
                    id="category-active"
                    checked={form.is_active}
                    onCheckedChange={(v) => set('is_active', v === true)}
                />
                <div className="space-y-0.5">
                    <Label htmlFor="category-active">Aktif</Label>
                    <p className="text-xs text-muted-foreground">
                        Kategori nonaktif tidak muncul di pilihan produk baru,
                        tetapi produk lama tetap memakainya.
                    </p>
                </div>
            </div>
        </FormDialog>
    );
}

function DeleteDialog({
    item,
    onClose,
}: {
    item: CategoryRow | null;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (!item) {
            return;
        }

        router.delete(`/kategori-produk/${item.id}`, {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open={item !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Trash2}
            tone="danger"
            title={`Hapus “${item?.name ?? ''}”?`}
            description="Kategori yang masih punya sub-kategori atau dipakai produk tidak bisa dihapus; nonaktifkan saja."
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={confirm}
                    >
                        Hapus Kategori
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.category} />
        </FormDialog>
    );
}

ProductCategoriesIndex.layout = {
    breadcrumbs: [{ title: 'Kategori Produk', href: '/kategori-produk' }],
};
