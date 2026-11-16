import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, Lock, Pencil, Plus, Rows3 } from 'lucide-react';
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
import { Spinner } from '@/components/ui/spinner';
import { useBreadcrumbTail } from '@/hooks/use-breadcrumb-tail';
import { store, update } from '@/routes/admin/stock-bins';
import { index as locationsIndex } from '@/routes/admin/stock-locations';
import type { ListingProps } from '@/types/listing';

type BinRow = {
    id: number;
    code: string;
    name: string | null;
    zone: string;
    zone_label: string;
    is_active: boolean;
    is_default_quarantine: boolean;
};

type Props = ListingProps<BinRow> & {
    stockLocation: {
        id: number;
        code: string;
        name: string;
        type_label: string;
        is_active: boolean;
    } | null;
    zones: { value: string; label: string }[];
    canManage: boolean;
};

type Editing = { mode: 'create' } | { mode: 'edit'; bin: BinRow };

const columns: DataTableColumn<BinRow>[] = [
    {
        key: 'code',
        header: 'Kode',
        sortKey: 'code',
        primary: true,
        cell: (r) => (
            <span className="inline-flex items-center gap-1.5 font-mono text-sm font-medium">
                {r.code}
                {r.is_default_quarantine && (
                    <Lock
                        className="size-3.5 text-muted-foreground"
                        aria-label="Rak karantina bawaan"
                    />
                )}
            </span>
        ),
    },
    {
        key: 'name',
        header: 'Nama',
        cell: (r) => r.name ?? <span className="text-muted-foreground">–</span>,
    },
    {
        key: 'zone',
        header: 'Zona',
        sortKey: 'zone',
        cell: (r) => (
            <StatusBadge status={`bin_zone.${r.zone}`} label={r.zone_label} />
        ),
    },
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={r.is_active ? 'stock_bin.active' : 'stock_bin.inactive'}
            />
        ),
    },
];

/** Rak per lokasi stok (15 §5, FR-A04). Tambah/ubah di dialog; Q-01 terkunci. */
export default function StockBinsIndex({
    rows,
    listing,
    stockLocation,
    zones,
    canManage,
}: Props) {
    const [editing, setEditing] = useState<Editing | null>(null);
    // Ekor pendek: nama lokasi stok sudah ada di judul halaman, dan di HP breadcrumb panjang
    // tertimpa tombol kepala halaman.
    useBreadcrumbTail(stockLocation ? [{ title: 'Rak', href: '#' }] : []);

    const addButton =
        canManage && stockLocation ? (
            <Button onClick={() => setEditing({ mode: 'create' })}>
                <Plus aria-hidden="true" />
                Tambah Rak
            </Button>
        ) : undefined;

    return (
        <>
            <Head
                title={stockLocation ? `Rak · ${stockLocation.name}` : 'Rak'}
            />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div className="flex flex-wrap items-start gap-3">
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={locationsIndex()}>
                            <ArrowLeft aria-hidden="true" />
                            Lokasi stok
                        </Link>
                    </Button>
                    <div className="min-w-0">
                        <h1 className="text-[1.4rem] leading-tight font-bold">
                            {stockLocation
                                ? `Rak · ${stockLocation.name}`
                                : 'Rak'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {stockLocation ? (
                                <>
                                    <span className="font-mono">
                                        {stockLocation.code}
                                    </span>{' '}
                                    · {stockLocation.type_label}
                                    {!stockLocation.is_active && ' · nonaktif'}.
                                    Rak karantina Q-01 dibuat otomatis dan
                                    menjadi tujuan barang kedaluwarsa.
                                </>
                            ) : (
                                'Belum ada gudang atau toko yang bisa Anda lihat. Rak hanya ada di lokasi stok gudang/toko.'
                            )}
                        </p>
                    </div>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar rak"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={
                        canManage
                            ? (r) => (
                                  <div className="flex justify-end">
                                      <Button
                                          variant="ghost"
                                          size="sm"
                                          onClick={() =>
                                              setEditing({
                                                  mode: 'edit',
                                                  bin: r,
                                              })
                                          }
                                          aria-label={`Ubah rak ${r.code}`}
                                      >
                                          <Pencil aria-hidden="true" />
                                          Ubah
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>

            {stockLocation && (
                <BinDialog
                    key={
                        editing?.mode === 'edit'
                            ? `ubah-${editing.bin.id}`
                            : (editing?.mode ?? 'tutup')
                    }
                    editing={editing}
                    stockLocationId={stockLocation.id}
                    stockLocationName={stockLocation.name}
                    zones={zones}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

function BinDialog({
    editing,
    stockLocationId,
    stockLocationName,
    zones,
    onClose,
}: {
    editing: Editing | null;
    stockLocationId: number;
    stockLocationName: string;
    zones: { value: string; label: string }[];
    onClose: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const bin = editing?.mode === 'edit' ? editing.bin : null;
    const locked = bin?.is_default_quarantine ?? false;
    const [code, setCode] = useState(bin?.code ?? '');
    const [name, setName] = useState(bin?.name ?? '');
    const [zone, setZone] = useState(bin?.zone ?? 'general');
    const [active, setActive] = useState(bin?.is_active ?? true);
    const [processing, setProcessing] = useState(false);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const payload = { code, name, zone, is_active: active };
        const options = {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onClose(),
        };

        if (bin) {
            router.put(update(bin.id).url, payload, options);
        } else {
            router.post(
                store().url,
                { ...payload, stock_location_id: stockLocationId },
                options,
            );
        }
    };

    return (
        <FormDialog
            open={editing !== null}
            onOpenChange={(o) => !o && onClose()}
            icon={Rows3}
            title={bin ? `Ubah rak ${bin.code}` : 'Tambah rak'}
            description={`Lokasi stok ${stockLocationName}.`}
            onSubmit={submit}
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {processing && <Spinner />}
                        {bin ? 'Simpan Rak' : 'Tambah Rak'}
                    </Button>
                </>
            }
        >
            {/* Galat domain (mis. rak masih bersaldo) juga sudah tampil sebagai toast. */}
            <FormStatus
                tone="error"
                message={errors.form ?? errors.stock_location_id}
            />
            {locked && (
                <p className="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
                    Q-01 adalah rak karantina bawaan: kode, zona, dan statusnya
                    terkunci karena menjadi tujuan karantina otomatis. Hanya
                    nama yang bisa diubah.
                </p>
            )}

            <div className="grid gap-2">
                <Label htmlFor="bin-code">Kode</Label>
                <Input
                    id="bin-code"
                    value={code}
                    onChange={(e) => setCode(e.target.value.toUpperCase())}
                    disabled={locked}
                    className="font-mono uppercase"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={20}
                    placeholder="R2-04"
                    aria-invalid={!!errors.code || undefined}
                />
                <InputError message={errors.code} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="bin-name">
                    Nama{' '}
                    <span className="font-normal text-muted-foreground">
                        (opsional)
                    </span>
                </Label>
                <Input
                    id="bin-name"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    maxLength={100}
                    placeholder="Rak pestisida baris 2"
                    aria-invalid={!!errors.name || undefined}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="bin-zone">Zona</Label>
                <NativeSelect
                    id="bin-zone"
                    value={zone}
                    disabled={locked}
                    onChange={(e) => setZone(e.target.value)}
                >
                    {zones.map((z) => (
                        <option key={z.value} value={z.value}>
                            {z.label}
                        </option>
                    ))}
                </NativeSelect>
                <InputError message={errors.zone} />
            </div>

            <div className="flex items-start gap-3">
                <Checkbox
                    id="bin-active"
                    checked={active}
                    disabled={locked}
                    onCheckedChange={(v) => setActive(v === true)}
                />
                <div className="grid gap-0.5">
                    <Label htmlFor="bin-active">Aktif</Label>
                    <p className="text-xs text-muted-foreground">
                        Rak nonaktif tidak bisa dipilih sebagai tujuan barang.
                        Tidak bisa dinonaktifkan selama masih menyimpan barang.
                    </p>
                    <InputError message={errors.is_active} />
                </div>
            </div>
        </FormDialog>
    );
}

StockBinsIndex.layout = {
    breadcrumbs: [{ title: 'Lokasi Stok', href: locationsIndex() }],
};
