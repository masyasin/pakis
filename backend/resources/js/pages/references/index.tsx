import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    GripVertical,
    ListOrdered,
    Lock,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { DragEvent, FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { NativeSelect } from '@/components/pakis/native-select';
import { tabChipClass } from '@/components/pakis/page-tabs';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { ListingProps } from '@/types/listing';

type ReferenceRow = {
    id: number;
    code: string;
    name: string;
    description: string | null;
    sort_order: number;
    is_active: boolean;
    is_system: boolean;
};

type OrderItem = Pick<
    ReferenceRow,
    'id' | 'code' | 'name' | 'is_active' | 'is_system'
>;

type Option = { value: string; label: string };

type Props = ListingProps<ReferenceRow> & {
    group: string;
    groups: Option[];
    orderItems: OrderItem[];
    canManage: boolean;
};

type Editing = { mode: 'create' } | { mode: 'edit'; item: ReferenceRow };

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

/** Referensi dinamis (07 §4): pilih grup → daftar, item sistem bergembok, atur urutan. */
export default function ReferencesIndex({
    rows,
    listing,
    group,
    groups,
    orderItems,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<Editing | null>(null);
    const [deleting, setDeleting] = useState<ReferenceRow | null>(null);
    const [ordering, setOrdering] = useState(false);
    const groupLabel = groups.find((g) => g.value === group)?.label ?? group;

    const columns: DataTableColumn<ReferenceRow>[] = [
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
            // Gembok cukup di kolom Sistem; di sini hanya kode (mono).
            cell: (r) => <span className="font-mono text-xs">{r.code}</span>,
        },
        {
            key: 'name',
            header: 'Nama',
            sortKey: 'name',
            primary: true,
            cell: (r) => r.name,
        },
        {
            key: 'description',
            header: 'Deskripsi',
            cell: (r) => (
                <span className="text-muted-foreground">
                    {r.description ?? '–'}
                </span>
            ),
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
        {
            key: 'is_system',
            header: 'Sistem',
            cell: (r) =>
                r.is_system ? (
                    <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <Lock className="size-3.5" aria-hidden="true" />
                        Sistem
                    </span>
                ) : (
                    <span className="text-muted-foreground">–</span>
                ),
        },
    ];

    return (
        <>
            <Head title={`Referensi · ${groupLabel}`} />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Referensi
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Pilihan yang dipakai di form (tipe lokasi, status kerja,
                        alasan). Item bergembok dipakai program: kodenya
                        terkunci dan tidak bisa dihapus.
                    </p>
                </div>

                {/* HP: select (tab aktif bisa tersembunyi di luar layar); ≥ sm: tab. 07 §4 "tab/select". */}
                <div className="sm:hidden">
                    <Label htmlFor="reference-group" className="sr-only">
                        Grup referensi
                    </Label>
                    <NativeSelect
                        id="reference-group"
                        value={group}
                        onChange={(e) =>
                            router.get(
                                '/referensi',
                                { filter: { group: e.target.value } },
                                { preserveScroll: true },
                            )
                        }
                    >
                        {groups.map((g) => (
                            <option key={g.value} value={g.value}>
                                {g.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>

                <nav
                    aria-label="Grup referensi"
                    className="hidden gap-1.5 sm:flex sm:flex-wrap"
                >
                    {groups.map((g) => (
                        <Link
                            key={g.value}
                            href={`/referensi?filter[group]=${g.value}`}
                            preserveScroll
                            aria-current={
                                g.value === group ? 'page' : undefined
                            }
                            className={tabChipClass(g.value === group)}
                        >
                            {g.label}
                        </Link>
                    ))}
                </nav>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title={`Daftar ${groupLabel.toLowerCase()}`}
                    toolbarActions={
                        canManage ? (
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setOrdering(true)}
                                >
                                    <ListOrdered aria-hidden="true" />
                                    Atur Urutan
                                </Button>
                                <Button
                                    onClick={() =>
                                        setEditing({ mode: 'create' })
                                    }
                                >
                                    <Plus aria-hidden="true" />
                                    Tambah Item
                                </Button>
                            </div>
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
                                      {!r.is_system && (
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
                                      )}
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            <ItemDialog
                key={
                    editing?.mode === 'edit'
                        ? `ubah-${editing.item.id}`
                        : (editing?.mode ?? 'tutup')
                }
                editing={editing}
                group={group}
                groupLabel={groupLabel}
                onClose={() => setEditing(null)}
            />
            <DeleteDialog
                key={deleting?.id ?? 'tutup-hapus'}
                item={deleting}
                onClose={() => setDeleting(null)}
            />
            <OrderDialog
                key={ordering ? `urut-${group}` : 'tutup-urut'}
                open={ordering}
                group={group}
                groupLabel={groupLabel}
                items={orderItems}
                onClose={() => setOrdering(false)}
            />
        </>
    );
}

function ItemDialog({
    editing,
    group,
    groupLabel,
    onClose,
}: {
    editing: Editing | null;
    group: string;
    groupLabel: string;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const item = editing?.mode === 'edit' ? editing.item : null;
    const [code, setCode] = useState(item?.code ?? '');
    const [name, setName] = useState(item?.name ?? '');
    const [description, setDescription] = useState(item?.description ?? '');
    const [active, setActive] = useState(item?.is_active ?? true);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload = { code, name, description, is_active: active };
        const options = {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };

        if (item) {
            router.put(`/referensi/${item.id}`, payload, options);
        } else {
            router.post('/referensi', { ...payload, group }, options);
        }
    };

    return (
        <Dialog open={editing !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader className="pr-6">
                        <DialogTitle>
                            {item ? `Ubah · ${item.name}` : 'Tambah item'}
                        </DialogTitle>
                        <DialogDescription>
                            Grup {groupLabel}.
                        </DialogDescription>
                    </DialogHeader>

                    <FormStatus tone="error" message={errors.group} />

                    <div className="space-y-2">
                        <Label htmlFor="reference-code">Kode</Label>
                        <Input
                            id="reference-code"
                            value={code}
                            onChange={(e) => setCode(e.target.value)}
                            disabled={item?.is_system}
                            className="font-mono"
                            autoComplete="off"
                            spellCheck={false}
                            maxLength={50}
                            aria-invalid={!!errors.code || undefined}
                            aria-describedby="reference-code-help"
                        />
                        <p
                            id="reference-code-help"
                            className="text-xs text-muted-foreground"
                        >
                            {item?.is_system
                                ? 'Kode item sistem dipakai program, jadi terkunci.'
                                : 'Huruf kecil, angka, dan garis bawah. Contoh: gudang_cabang.'}
                        </p>
                        <InputError message={errors.code} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference-name">Nama</Label>
                        <Input
                            id="reference-name"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={150}
                            aria-invalid={!!errors.name || undefined}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="reference-description">
                            Deskripsi{' '}
                            <span className="font-normal text-muted-foreground">
                                (opsional)
                            </span>
                        </Label>
                        <Input
                            id="reference-description"
                            value={description}
                            onChange={(e) => setDescription(e.target.value)}
                            maxLength={255}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="reference-active"
                            checked={active}
                            onCheckedChange={(v) => setActive(v === true)}
                        />
                        <div className="space-y-0.5">
                            <Label htmlFor="reference-active">Aktif</Label>
                            <p className="text-xs text-muted-foreground">
                                Item nonaktif tidak muncul di pilihan baru,
                                tetapi tetap tampil di data lama.
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Batal
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {item ? 'Simpan Item' : 'Tambah Item'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function DeleteDialog({
    item,
    onClose,
}: {
    item: ReferenceRow | null;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (!item) {
            return;
        }

        router.delete(`/referensi/${item.id}`, {
            ...SAVE_OPTIONS,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={item !== null} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="pr-6">
                    <DialogTitle>Hapus “{item?.name}”?</DialogTitle>
                    <DialogDescription>
                        Item yang sudah dipakai data lain tidak bisa dihapus;
                        nonaktifkan saja agar data lama tetap terbaca.
                    </DialogDescription>
                </DialogHeader>
                <FormStatus tone="error" message={errors.item} />
                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={processing}
                        onClick={confirm}
                    >
                        Hapus Item
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Pindahkan elemen `from` ke posisi `to` (salinan baru). */
function move<T>(list: T[], from: number, to: number): T[] {
    const next = [...list];
    const [picked] = next.splice(from, 1);
    next.splice(to, 0, picked);

    return next;
}

function OrderDialog({
    open,
    group,
    groupLabel,
    items: initial,
    onClose,
}: {
    open: boolean;
    group: string;
    groupLabel: string;
    items: OrderItem[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [items, setItems] = useState(initial);
    const [dragging, setDragging] = useState<number | null>(null);
    const [processing, setProcessing] = useState(false);

    const onDragOver = (event: DragEvent<HTMLLIElement>, index: number) => {
        event.preventDefault();
        if (dragging === null || dragging === index) {
            return;
        }
        setItems((list) => move(list, dragging, index));
        setDragging(index);
    };

    const save = () => {
        router.post(
            '/referensi/urutan',
            { group, ids: items.map((i) => i.id) },
            {
                ...SAVE_OPTIONS,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader className="pr-6">
                    <DialogTitle>Atur urutan · {groupLabel}</DialogTitle>
                    <DialogDescription>
                        Seret item, atau pakai tombol panah. Urutan ini dipakai
                        di semua pilihan.
                    </DialogDescription>
                </DialogHeader>

                <FormStatus tone="error" message={errors.ids} />

                <ol className="max-h-[55svh] space-y-1.5 overflow-y-auto pr-1">
                    {items.map((item, index) => (
                        <li
                            key={item.id}
                            draggable
                            onDragStart={() => setDragging(index)}
                            onDragOver={(e) => onDragOver(e, index)}
                            onDragEnd={() => setDragging(null)}
                            className={cn(
                                'flex items-center gap-2 rounded-lg border bg-card px-2 py-1.5 text-sm transition-colors',
                                dragging === index &&
                                    'border-primary/50 bg-primary/5',
                            )}
                        >
                            <GripVertical
                                className="size-4 shrink-0 cursor-grab text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span className="w-6 text-right text-xs text-muted-foreground tabular-nums">
                                {index + 1}
                            </span>
                            <span
                                className={cn(
                                    'min-w-0 flex-1 truncate',
                                    !item.is_active && 'text-muted-foreground',
                                )}
                            >
                                {item.name}
                                {!item.is_active && ' (nonaktif)'}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                disabled={index === 0}
                                onClick={() =>
                                    setItems((l) => move(l, index, index - 1))
                                }
                                aria-label={`Naikkan ${item.name}`}
                            >
                                <ArrowUp aria-hidden="true" />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                disabled={index === items.length - 1}
                                onClick={() =>
                                    setItems((l) => move(l, index, index + 1))
                                }
                                aria-label={`Turunkan ${item.name}`}
                            >
                                <ArrowDown aria-hidden="true" />
                            </Button>
                        </li>
                    ))}
                </ol>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="button" disabled={processing} onClick={save}>
                        Simpan Urutan
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

ReferencesIndex.layout = {
    breadcrumbs: [{ title: 'Referensi', href: '/referensi' }],
};
