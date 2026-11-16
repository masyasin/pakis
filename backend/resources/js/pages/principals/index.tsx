import { Head, router, usePage } from '@inertiajs/react';
import { Factory, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import InputError from '@/components/input-error';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ListingProps } from '@/types/listing';

type PrincipalRow = {
    id: number;
    code: string;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    website: string | null;
    is_active: boolean;
    suppliers_count: number;
};

type Props = ListingProps<PrincipalRow> & { canManage: boolean };

const SAVE_OPTIONS = { preserveScroll: true, preserveState: true } as const;

/** Daftar prinsipal (15 §7.3 `principals`, FR-A11): produsen / pemilik merek. */
export default function PrincipalsIndex({ rows, listing, canManage }: Props) {
    const [editing, setEditing] = useState<PrincipalRow | 'new' | null>(null);

    const columns: DataTableColumn<PrincipalRow>[] = [
        {
            key: 'name',
            header: 'Prinsipal',
            sortKey: 'name',
            primary: true,
            cell: (r) => (
                <span>
                    <span className="block font-medium">{r.name}</span>
                    <span className="block font-mono text-xs text-muted-foreground">
                        {r.code}
                    </span>
                </span>
            ),
        },
        {
            key: 'contact',
            header: 'Kontak',
            cell: (r) => (
                <span>
                    {r.contact_name ?? '–'}
                    {r.phone && (
                        <span className="block text-xs text-muted-foreground">
                            {r.phone}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'suppliers_count',
            header: 'Supplier',
            cell: (r) =>
                r.suppliers_count > 0 ? `${r.suppliers_count} supplier` : '–',
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
                      cell: (r: PrincipalRow) => (
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
            <Head title="Prinsipal" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Prinsipal
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Produsen atau pemilik merek produk. Supplier boleh
                        menunjuk prinsipalnya bila Pakis membeli langsung dari
                        pabrik.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar prinsipal"
                    toolbarActions={
                        canManage && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus aria-hidden="true" />
                                Tambah Prinsipal
                            </Button>
                        )
                    }
                />
            </div>

            {editing !== null && (
                <PrincipalDialog
                    item={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function PrincipalDialog({
    item,
    onClose,
}: {
    item: PrincipalRow | null;
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [form, setForm] = useState({
        code: item?.code ?? '',
        name: item?.name ?? '',
        contact_name: item?.contact_name ?? '',
        phone: item?.phone ?? '',
        email: item?.email ?? '',
        address: item?.address ?? '',
        website: item?.website ?? '',
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
            router.put(`/prinsipal/${item.id}`, form, options);
        } else {
            router.post('/prinsipal', form, options);
        }
    };

    const text = (
        key: Exclude<keyof typeof form, 'is_active'>,
        label: string,
        props: Record<string, unknown> = {},
    ) => (
        <div className="grid gap-2">
            <Label htmlFor={`principal-${key}`}>{label}</Label>
            <Input
                id={`principal-${key}`}
                value={form[key]}
                onChange={(e) => set(key, e.target.value)}
                aria-invalid={!!errors[key] || undefined}
                {...props}
            />
            <InputError message={errors[key]} />
        </div>
    );

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={Factory}
            size="lg"
            title={item ? `Ubah · ${item.name}` : 'Tambah prinsipal'}
            description="Kode singkat huruf besar, mis. AGRO-DEMO. Prinsipal tidak dihapus; nonaktifkan bila tidak dipakai lagi."
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {item ? 'Simpan Prinsipal' : 'Tambah Prinsipal'}
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                {text('code', 'Kode', {
                    className: 'font-mono',
                    maxLength: 20,
                    onChange: (e: React.ChangeEvent<HTMLInputElement>) =>
                        set('code', e.target.value.toUpperCase()),
                })}
                {text('name', 'Nama', { maxLength: 150 })}
                {text('contact_name', 'Nama kontak (opsional)', {
                    maxLength: 100,
                })}
                {text('phone', 'Telepon (opsional)', {
                    maxLength: 20,
                    inputMode: 'tel',
                })}
                {text('email', 'Email (opsional)', {
                    type: 'email',
                    maxLength: 150,
                })}
                {text('website', 'Situs (opsional)', {
                    maxLength: 150,
                    placeholder: 'www.contoh.co.id',
                })}
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="principal-address">Alamat (opsional)</Label>
                    <textarea
                        id="principal-address"
                        value={form.address}
                        onChange={(e) => set('address', e.target.value)}
                        rows={2}
                        maxLength={2000}
                        className="min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                    <InputError message={errors.address} />
                </div>
                <div className="flex items-center gap-3">
                    <Checkbox
                        id="principal-active"
                        checked={form.is_active}
                        onCheckedChange={(v) => set('is_active', v === true)}
                    />
                    <Label htmlFor="principal-active">Aktif</Label>
                </div>
            </div>
        </FormDialog>
    );
}

PrincipalsIndex.layout = {
    breadcrumbs: [{ title: 'Prinsipal', href: '/prinsipal' }],
};
