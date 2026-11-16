import { Head, Link } from '@inertiajs/react';
import { Lock, Pencil, Plus, Rows3 } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { index as binsIndex } from '@/routes/admin/stock-bins';
import { create, edit, index } from '@/routes/admin/stock-locations';
import type { ListingProps } from '@/types/listing';

type StockLocationRow = {
    id: number;
    code: string;
    name: string;
    type: 'warehouse' | 'store' | 'vehicle' | 'in_transit';
    type_label: string;
    location: string | null;
    vehicle_plate: string | null;
    has_bins: boolean;
    bins_count: number;
    is_sellable: boolean;
    is_system: boolean;
    is_active: boolean;
};

type Props = ListingProps<StockLocationRow> & {
    can: { manage: boolean; viewVehicles: boolean };
};

const binsHref = (id: number) =>
    binsIndex({ query: { filter: { stock_location_id: id } } }).url;

const columns: DataTableColumn<StockLocationRow>[] = [
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
        cell: (r) => <span className="font-medium">{r.name}</span>,
    },
    { key: 'type', header: 'Tipe', sortKey: 'type', cell: (r) => r.type_label },
    {
        key: 'link',
        header: 'Lokasi / Kendaraan',
        cell: (r) =>
            r.vehicle_plate ? (
                <span>
                    <span className="font-mono text-xs">{r.vehicle_plate}</span>
                    {r.location && (
                        <span className="text-muted-foreground">
                            {' '}
                            · {r.location}
                        </span>
                    )}
                </span>
            ) : (
                (r.location ?? <span className="text-muted-foreground">–</span>)
            ),
    },
    {
        key: 'bins',
        header: 'Rak',
        className: 'tabular-nums',
        cell: (r) =>
            r.has_bins ? (
                <Link
                    href={binsHref(r.id)}
                    className="font-medium text-primary underline-offset-4 hover:underline"
                >
                    {r.bins_count.toLocaleString('id-ID')} rak
                </Link>
            ) : (
                // 15 §4.8: kendaraan & TRANSIT tidak berak.
                <span className="text-muted-foreground">–</span>
            ),
    },
    {
        key: 'is_sellable',
        header: 'Bisa dijual',
        cell: (r) => (r.is_sellable ? 'Ya' : 'Tidak'),
    },
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <span className="flex flex-wrap gap-1">
                {r.is_system && <StatusBadge status="stock_location.system" />}
                <StatusBadge
                    status={
                        r.is_active
                            ? 'stock_location.active'
                            : 'stock_location.inactive'
                    }
                />
            </span>
        ),
    },
];

/** Lokasi stok (15 §5, FR-A03): gudang, toko, kendaraan canvas, dan TRANSIT. */
export default function StockLocationsIndex({ rows, listing, can }: Props) {
    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Lokasi Stok
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Lokasi Stok" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Lokasi Stok
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Tempat barang disimpan: gudang dan toko (punya rak),
                        kendaraan canvas (dibuat dari halaman Kendaraan), dan
                        TRANSIT untuk barang yang sedang dimutasi.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar lokasi stok"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={(r) => (
                        <div className="flex justify-end gap-1">
                            {r.has_bins && (
                                <Button variant="ghost" size="sm" asChild>
                                    <Link
                                        href={binsHref(r.id)}
                                        aria-label={`Rak di ${r.name}`}
                                    >
                                        <Rows3 aria-hidden="true" />
                                        Rak
                                    </Link>
                                </Button>
                            )}
                            {r.is_system ? (
                                <span
                                    className="inline-flex items-center gap-1 px-2 text-xs text-muted-foreground"
                                    title="Lokasi sistem tidak bisa diubah"
                                >
                                    <Lock
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Terkunci
                                </span>
                            ) : (
                                can.manage && (
                                    <Button variant="ghost" size="sm" asChild>
                                        <Link
                                            href={edit(r.id)}
                                            aria-label={`Ubah lokasi stok ${r.name}`}
                                        >
                                            <Pencil aria-hidden="true" />
                                            Ubah
                                        </Link>
                                    </Button>
                                )
                            )}
                        </div>
                    )}
                />
            </div>
        </>
    );
}

StockLocationsIndex.layout = {
    breadcrumbs: [{ title: 'Lokasi Stok', href: index() }],
};
