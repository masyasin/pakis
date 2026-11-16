import { Head, Link } from '@inertiajs/react';
import { ArrowRightLeft, Plus } from 'lucide-react';
import { useState } from 'react';
import {
    CUSTOMER_STATUS_TONE,
    CustomerPhone,
} from '@/components/customer/customer-ui';
import type { CustomerStatus } from '@/components/customer/customer-ui';
import { ReassignDialog } from '@/components/customer/reassign-dialog';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { formatDate, formatNumber } from '@/lib/listing-query';
import { create, exportMethod, index, show } from '@/routes/admin/customers';
import type { ListingProps } from '@/types/listing';

type CustomerRow = {
    id: number;
    code: string;
    store_name: string;
    owner_name: string;
    owner_phone: string;
    customer_type: string | null;
    sales_area: { id: number; code: string; name: string } | null;
    assigned_sales: { id: number; name: string } | null;
    village: string | null;
    status: CustomerStatus;
    status_label: string;
    is_verified: boolean;
    created_at: string | null;
};

type Props = ListingProps<CustomerRow> & {
    phoneMasked: boolean;
    can: { create: boolean; reassign: boolean; export: boolean };
    maxReassign: number;
    today: string;
};

/** Daftar kios (15 §7.3 `customers`, FR-A13/FR-A17): cari kode, nama toko, pemilik, atau HP. */
export default function CustomersIndex({
    rows,
    listing,
    phoneMasked,
    can,
    maxReassign,
    today,
}: Props) {
    // Pilihan bertahan saat pindah halaman/filter: pengalihan massal bisa lintas halaman (≤ maxReassign).
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [reassignOpen, setReassignOpen] = useState(false);

    const toggle = (id: number, on: boolean) =>
        setSelected((prev) => {
            const next = new Set(prev);
            if (on) {
                next.add(id);
            } else {
                next.delete(id);
            }

            return next;
        });

    const pageIds = rows.map((r) => r.id);
    const pageAllSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.has(id));

    const columns: DataTableColumn<CustomerRow>[] = [
        ...(can.reassign
            ? [
                  {
                      key: 'select',
                      header: 'Pilih',
                      className: 'w-12',
                      cell: (r: CustomerRow) => (
                          <Checkbox
                              checked={selected.has(r.id)}
                              onCheckedChange={(v) => toggle(r.id, v === true)}
                              onClick={(e) => e.stopPropagation()}
                              aria-label={`Pilih ${r.store_name}`}
                          />
                      ),
                  },
              ]
            : []),
        {
            key: 'store_name',
            header: 'Kios',
            sortKey: 'store_name',
            primary: true,
            cell: (r) => (
                <span className="grid">
                    <Link
                        href={show(r.id)}
                        className="font-medium hover:underline"
                    >
                        {r.store_name}
                    </Link>
                    <span className="text-xs text-muted-foreground">
                        {r.customer_type ?? 'Jenis belum diisi'}
                        {r.village ? ` · ${r.village}` : ''}
                    </span>
                </span>
            ),
        },
        {
            key: 'code',
            header: 'Kode',
            sortKey: 'code',
            cell: (r) => <span className="font-mono text-xs">{r.code}</span>,
        },
        {
            key: 'owner',
            header: 'Pemilik',
            cell: (r) => (
                <span className="grid">
                    <span>{r.owner_name}</span>
                    <CustomerPhone
                        phone={r.owner_phone}
                        masked={phoneMasked}
                        className="text-xs text-muted-foreground"
                    />
                </span>
            ),
        },
        {
            key: 'area',
            header: 'Area · Sales',
            cell: (r) => (
                <span className="grid">
                    <span>{r.sales_area?.name ?? '–'}</span>
                    <span className="text-xs text-muted-foreground">
                        {r.assigned_sales?.name ?? 'Belum ada sales'}
                    </span>
                </span>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (r) => (
                <span className="flex flex-wrap gap-1">
                    <StatusBadge
                        status={`customer.${r.status}`}
                        label={r.status_label}
                        tone={CUSTOMER_STATUS_TONE[r.status]}
                    />
                    {!r.is_verified && (
                        <StatusBadge
                            status="customer.unverified"
                            label="Belum verifikasi"
                            tone="warning"
                        />
                    )}
                </span>
            ),
        },
        {
            key: 'created_at',
            header: 'Dibuat',
            sortKey: 'created_at',
            className: 'whitespace-nowrap tabular-nums',
            // Server mengirim waktu lokal (`+07:00`), jadi 10 karakter awal = tanggal lokal.
            cell: (r) =>
                r.created_at ? formatDate(r.created_at.slice(0, 10)) : '–',
        },
    ];

    const addButton = can.create ? (
        <Button asChild>
            <Link href={create()}>
                <Plus aria-hidden="true" />
                Tambah Kios
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Pelanggan" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Pelanggan
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Kios dan toko pembeli. Kios baru berstatus Prospek
                        sampai terverifikasi. Cari dengan kode, nama toko,
                        pemilik, atau nomor HP.
                    </p>
                </div>

                {can.reassign && selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm">
                        <span>
                            <strong className="tabular-nums">
                                {formatNumber(selected.size)}
                            </strong>{' '}
                            kios dipilih
                            {selected.size > maxReassign && (
                                <span className="text-destructive">
                                    {' '}
                                    (maksimal {formatNumber(maxReassign)})
                                </span>
                            )}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected(new Set())}
                        >
                            Hapus pilihan
                        </Button>
                    </div>
                )}

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    title="Daftar pelanggan"
                    emptyAction={addButton}
                    toolbarActions={
                        <>
                            {can.reassign && (
                                <>
                                    <Button
                                        variant="outline"
                                        disabled={pageIds.length === 0}
                                        onClick={() =>
                                            setSelected((prev) => {
                                                const next = new Set(prev);
                                                pageIds.forEach((id) =>
                                                    pageAllSelected
                                                        ? next.delete(id)
                                                        : next.add(id),
                                                );

                                                return next;
                                            })
                                        }
                                    >
                                        {pageAllSelected
                                            ? 'Batal pilih halaman'
                                            : 'Pilih halaman ini'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        disabled={selected.size === 0}
                                        onClick={() => setReassignOpen(true)}
                                    >
                                        <ArrowRightLeft aria-hidden="true" />
                                        Alihkan
                                        {selected.size > 0 && (
                                            <span className="rounded-full bg-muted px-1.5 text-xs tabular-nums">
                                                {formatNumber(selected.size)}
                                            </span>
                                        )}
                                    </Button>
                                </>
                            )}
                            {can.export && (
                                <ExportButton
                                    href={exportMethod().url}
                                    total={listing.pagination.total}
                                />
                            )}
                            {addButton}
                        </>
                    }
                />
            </div>

            {can.reassign && reassignOpen && (
                <ReassignDialog
                    open
                    onOpenChange={setReassignOpen}
                    customerIds={[...selected]}
                    today={today}
                    maxReassign={maxReassign}
                    onSaved={() => setSelected(new Set())}
                />
            )}
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'Pelanggan', href: index() }],
};
