import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { create, edit, index } from '@/routes/admin/vehicles';
import type { ListingProps } from '@/types/listing';

type VehicleRow = {
    id: number;
    plate_no: string;
    name: string;
    vehicle_type: string;
    vehicle_type_label: string;
    is_canvas: boolean;
    home_location: string | null;
    stock_location_code: string | null;
    is_active: boolean;
};

type Props = ListingProps<VehicleRow> & { can: { manage: boolean } };

const columns: DataTableColumn<VehicleRow>[] = [
    {
        key: 'plate_no',
        header: 'Plat',
        sortKey: 'plate_no',
        cell: (r) => (
            <span className="font-mono text-sm font-medium">{r.plate_no}</span>
        ),
    },
    {
        key: 'name',
        header: 'Nama',
        sortKey: 'name',
        primary: true,
        cell: (r) => <span className="font-medium">{r.name}</span>,
    },
    { key: 'vehicle_type', header: 'Jenis', cell: (r) => r.vehicle_type_label },
    {
        key: 'home_location',
        header: 'Lokasi asal',
        cell: (r) =>
            r.home_location ?? <span className="text-muted-foreground">–</span>,
    },
    {
        key: 'canvas',
        header: 'Canvas',
        cell: (r) =>
            r.is_canvas ? (
                <span>
                    Ya
                    {r.stock_location_code && (
                        <span className="font-mono text-xs text-muted-foreground">
                            {' '}
                            · {r.stock_location_code}
                        </span>
                    )}
                </span>
            ) : (
                'Tidak'
            ),
    },
    {
        key: 'is_active',
        header: 'Status',
        cell: (r) => (
            <StatusBadge
                status={r.is_active ? 'vehicle.active' : 'vehicle.inactive'}
            />
        ),
    },
];

/** Kendaraan minimal (15 §5, FR-A05). Kendaraan canvas otomatis punya lokasi stok. */
export default function VehiclesIndex({ rows, listing, can }: Props) {
    const addButton = can.manage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Kendaraan
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Kendaraan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Kendaraan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kendaraan perusahaan. Kendaraan canvas punya lokasi stok
                        sendiri (kode KDR-plat) untuk barang yang dibawa
                        berjualan.
                    </p>
                </div>
                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar kendaraan"
                    toolbarActions={addButton}
                    emptyAction={addButton}
                    rowActions={
                        can.manage
                            ? (r) => (
                                  <div className="flex justify-end">
                                      <Button variant="ghost" size="sm" asChild>
                                          <Link
                                              href={edit(r.id)}
                                              aria-label={`Ubah kendaraan ${r.plate_no}`}
                                          >
                                              <Pencil aria-hidden="true" />
                                              Ubah
                                          </Link>
                                      </Button>
                                  </div>
                              )
                            : undefined
                    }
                />
            </div>
        </>
    );
}

VehiclesIndex.layout = {
    breadcrumbs: [{ title: 'Kendaraan', href: index() }],
};
