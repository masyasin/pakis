import { Head, Link, useForm } from '@inertiajs/react';
import { MapPinned, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { FormStatus } from '@/components/form-status';
import InputError from '@/components/input-error';
import { AsyncSelect } from '@/components/pakis/async-select';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { FormDialog } from '@/components/pakis/form-dialog';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatNumber } from '@/lib/listing-query';
import { rememberLabels } from '@/lib/lookup';
import type { LookupOption } from '@/lib/lookup';
import { index as customersIndex } from '@/routes/admin/customers';
import { index, store, update } from '@/routes/admin/sales-areas';
import type { ListingProps } from '@/types/listing';

type SalesAreaRow = {
    id: number;
    code: string;
    name: string;
    parent_id: number | null;
    parent: LookupOption | null;
    depth: number;
    supervisor_employee_id: number | null;
    supervisor: LookupOption | null;
    home_stock_location_id: number | null;
    home_stock_location: LookupOption | null;
    district_codes: string[];
    districts: LookupOption[];
    districts_count: number;
    customers_count: number;
    is_active: boolean;
};

type Props = ListingProps<SalesAreaRow> & {
    can: { manage: boolean };
    maxDepth: number;
    maxDistricts: number;
};

/** Kecamatan pertama yang ditampilkan di sel; sisanya diringkas "+N lainnya". */
const DISTRICT_PREVIEW = 3;

const Muted = () => <span className="text-muted-foreground">–</span>;

/** Area penjualan bertingkat (15 §4.6/§5, FR-A12). Tambah/ubah lewat dialog; tanpa hapus (nonaktif). */
export default function SalesAreasIndex({
    rows,
    listing,
    can,
    maxDepth,
    maxDistricts,
}: Props) {
    const [editing, setEditing] = useState<SalesAreaRow | 'new' | null>(null);

    const columns: DataTableColumn<SalesAreaRow>[] = [
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
                <span className="grid">
                    <span className="font-medium">{r.name}</span>
                    {r.parent && (
                        <span className="text-xs text-muted-foreground">
                            Sub-area {r.parent.label} · tingkat {r.depth}
                        </span>
                    )}
                </span>
            ),
        },
        {
            key: 'supervisor',
            header: 'Supervisor',
            cell: (r) => r.supervisor?.label ?? <Muted />,
        },
        {
            key: 'home_stock_location',
            header: 'Gudang asal',
            cell: (r) => r.home_stock_location?.label ?? <Muted />,
        },
        {
            key: 'districts',
            header: 'Kecamatan',
            cell: (r) =>
                r.districts_count === 0 ? (
                    <Muted />
                ) : (
                    <span className="text-sm">
                        {r.districts
                            .slice(0, DISTRICT_PREVIEW)
                            .map((d) => d.label)
                            .join(', ')}
                        {r.districts_count > DISTRICT_PREVIEW && (
                            <span className="text-muted-foreground">
                                {' '}
                                +
                                {formatNumber(
                                    r.districts_count - DISTRICT_PREVIEW,
                                )}{' '}
                                lainnya
                            </span>
                        )}
                    </span>
                ),
        },
        {
            key: 'customers_count',
            header: 'Kios',
            className: 'tabular-nums',
            cell: (r) =>
                r.customers_count > 0 ? (
                    <Link
                        href={
                            customersIndex({
                                query: { filter: { sales_area_id: r.id } },
                            }).url
                        }
                        className="font-medium text-primary underline-offset-4 hover:underline"
                    >
                        {formatNumber(r.customers_count)} kios
                    </Link>
                ) : (
                    <span className="text-muted-foreground">0 kios</span>
                ),
        },
        {
            key: 'is_active',
            header: 'Status',
            cell: (r) => (
                <StatusBadge
                    status={r.is_active ? 'active' : 'inactive'}
                    label={r.is_active ? 'Aktif' : 'Nonaktif'}
                    tone={r.is_active ? 'success' : 'muted'}
                />
            ),
        },
    ];

    const addButton = can.manage ? (
        <Button onClick={() => setEditing('new')}>
            <Plus aria-hidden="true" />
            Tambah Area
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Area Penjualan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Area Penjualan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Wilayah kerja sales dan supervisor, bertingkat sampai{' '}
                        {maxDepth} tingkat. Setiap kios berada di satu area.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar area penjualan"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={(r) =>
                        can.manage ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setEditing(r)}
                                aria-label={`Ubah area ${r.name}`}
                            >
                                <Pencil aria-hidden="true" />
                                Ubah
                            </Button>
                        ) : null
                    }
                />
            </div>

            {editing !== null && (
                <SalesAreaDialog
                    area={editing === 'new' ? null : editing}
                    maxDepth={maxDepth}
                    maxDistricts={maxDistricts}
                    onClose={() => setEditing(null)}
                />
            )}
        </>
    );
}

const one = (value: string) => (value === '' ? [] : [value]);
const first = (value: Array<number | string>) => String(value[0] ?? '');
const idString = (id: number | null) => (id === null ? '' : String(id));

function SalesAreaDialog({
    area,
    maxDepth,
    maxDistricts,
    onClose,
}: {
    area: SalesAreaRow | null;
    maxDepth: number;
    maxDistricts: number;
    onClose: () => void;
}) {
    // Label pilihan terpilih dari baris, agar select menampilkan nama, bukan ID/kode.
    useState(() => {
        if (area) {
            rememberLabels('sales_areas', area.parent ? [area.parent] : []);
            rememberLabels(
                'sales_employees',
                area.supervisor ? [area.supervisor] : [],
            );
            rememberLabels(
                'stock_locations',
                area.home_stock_location ? [area.home_stock_location] : [],
            );
            rememberLabels('regions', area.districts);
        }
    });

    const form = useForm({
        code: area?.code ?? '',
        name: area?.name ?? '',
        parent_id: idString(area?.parent_id ?? null),
        supervisor_employee_id: idString(area?.supervisor_employee_id ?? null),
        home_stock_location_id: idString(area?.home_stock_location_id ?? null),
        district_codes: area?.district_codes ?? [],
        is_active: area?.is_active ?? true,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const districtsError =
        errors.district_codes ??
        Object.entries(errors).find(([k]) =>
            k.startsWith('district_codes.'),
        )?.[1];
    const tooManyDistricts = form.data.district_codes.length > maxDistricts;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(area ? update(area.id) : store(), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <FormDialog
            open
            onOpenChange={(o) => !o && onClose()}
            icon={MapPinned}
            size="lg"
            title={area ? `Ubah area · ${area.name}` : 'Tambah area penjualan'}
            description={`Area boleh berinduk ke area lain, maksimal ${maxDepth} tingkat.`}
            onSubmit={submit}
            noValidate
            footer={
                <>
                    <Button type="button" variant="outline" onClick={onClose}>
                        Batal
                    </Button>
                    <Button
                        type="submit"
                        disabled={form.processing || tooManyDistricts}
                    >
                        {form.processing && <Spinner />}
                        {area ? 'Simpan Area' : 'Tambah Area'}
                    </Button>
                </>
            }
        >
            <FormStatus tone="error" message={errors.form} />
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid content-start gap-2">
                    <Label htmlFor="area-code">Kode</Label>
                    <Input
                        id="area-code"
                        value={form.data.code}
                        maxLength={20}
                        className="font-mono uppercase"
                        placeholder="AR-KUDUS"
                        onChange={(e) =>
                            form.setData('code', e.target.value.toUpperCase())
                        }
                        aria-invalid={errors.code ? true : undefined}
                    />
                    <InputError message={errors.code} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="area-name">Nama</Label>
                    <Input
                        id="area-name"
                        value={form.data.name}
                        maxLength={100}
                        placeholder="Kudus Utara"
                        onChange={(e) => form.setData('name', e.target.value)}
                        aria-invalid={errors.name ? true : undefined}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="area-parent">Induk (opsional)</Label>
                    <AsyncSelect
                        id="area-parent"
                        source="sales_areas"
                        // Area ini & sub-areanya disembunyikan agar tidak membentuk siklus.
                        params={area ? { exclude: String(area.id) } : undefined}
                        value={one(form.data.parent_id)}
                        placeholder="Tanpa induk (area utama)"
                        onChange={(v) => form.setData('parent_id', first(v))}
                    />
                    <InputError message={errors.parent_id} />
                </div>
                <div className="grid content-start gap-2">
                    <Label htmlFor="area-supervisor">
                        Supervisor (opsional)
                    </Label>
                    <AsyncSelect
                        id="area-supervisor"
                        source="sales_employees"
                        value={one(form.data.supervisor_employee_id)}
                        placeholder="Cari nama atau nomor pegawai"
                        onChange={(v) =>
                            form.setData('supervisor_employee_id', first(v))
                        }
                    />
                    <InputError message={errors.supervisor_employee_id} />
                </div>
                <div className="grid content-start gap-2 sm:col-span-2">
                    <Label htmlFor="area-home">Gudang asal (opsional)</Label>
                    <AsyncSelect
                        id="area-home"
                        source="stock_locations"
                        params={{ binnable: '1' }}
                        value={one(form.data.home_stock_location_id)}
                        placeholder="Cari gudang atau toko"
                        onChange={(v) =>
                            form.setData('home_stock_location_id', first(v))
                        }
                    />
                    <p className="text-xs text-muted-foreground">
                        Dipakai kios di area ini yang tidak punya gudang asal
                        sendiri.
                    </p>
                    <InputError message={errors.home_stock_location_id} />
                </div>
                <div className="grid content-start gap-2 sm:col-span-2">
                    <Label htmlFor="area-districts">Kecamatan cakupan</Label>
                    <AsyncSelect
                        id="area-districts"
                        source="regions"
                        params={{ level: 'district' }}
                        multiple
                        value={form.data.district_codes}
                        placeholder="Ketik nama kecamatan"
                        onChange={(v) =>
                            form.setData('district_codes', v.map(String))
                        }
                    />
                    <p
                        className={
                            tooManyDistricts
                                ? 'text-xs text-destructive'
                                : 'text-xs text-muted-foreground'
                        }
                    >
                        {formatNumber(form.data.district_codes.length)} dari
                        maksimal {formatNumber(maxDistricts)} kecamatan.
                    </p>
                    <InputError message={districtsError} />
                </div>
            </div>
            {/* Checkbox di LUAR <label>: klik ikon centang di dalam label memicu dua kali toggle. */}
            <div className="flex items-center gap-2">
                <Checkbox
                    id="area-active"
                    checked={form.data.is_active}
                    onCheckedChange={(v) =>
                        form.setData('is_active', v === true)
                    }
                />
                <Label htmlFor="area-active" className="text-sm font-normal">
                    Aktif — area nonaktif tidak bisa dipilih untuk kios baru
                    atau pengalihan.
                </Label>
            </div>
        </FormDialog>
    );
}

SalesAreasIndex.layout = {
    breadcrumbs: [{ title: 'Area Penjualan', href: index() }],
};
