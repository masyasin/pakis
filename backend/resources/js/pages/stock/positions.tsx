import { Head, Link } from '@inertiajs/react';
import { DataTable } from '@/components/pakis/data-table';
import type { DataTableColumn } from '@/components/pakis/data-table';
import { ExportButton } from '@/components/pakis/export-button';
import { StatusBadge } from '@/components/pakis/status-badge';
import { formatCivilDate } from '@/lib/local-date';
import { formatRupiah } from '@/lib/money';
import { formatQty } from '@/lib/qty';
import type { ListingProps } from '@/types/listing';

export type StockPositionRow = {
    key: string;
    product_id: number;
    sku: string;
    name: string;
    unit: string;
    location: string;
    qty_on_hand: string;
    qty_available: string;
    // Mode per produk
    qty_quarantine?: string;
    qty_expired?: string;
    next_expiry?: string | null;
    // Mode per batch
    bin?: string | null;
    batch_no?: string;
    expiry_date?: string | null;
    status?: string;
    status_label?: string;
    /** Hanya ada bila `stock.view_cost` (SEC-107). */
    avg_cost?: string;
    value?: string;
};

type Props = ListingProps<StockPositionRow> & {
    mode: 'per_product' | 'per_batch';
    canExport: boolean;
    canViewCost: boolean;
};

/**
 * Posisi stok (15 §7.3 `stock_positions`, FR-A30): per produk per lokasi, atau per batch (filter
 * "Tampilan"). Hanya lokasi dalam scope Stok. HPP & nilai hanya untuk `stock.view_cost` (SEC-107);
 * tanpa izin itu kuncinya tidak dikirim server sama sekali.
 */
export default function StockPositions({
    rows,
    listing,
    mode,
    canExport,
    canViewCost,
}: Props) {
    const unit = (r: StockPositionRow) => r.unit.toLowerCase();
    const columns: DataTableColumn<StockPositionRow>[] = [
        {
            key: 'product',
            header: 'Produk',
            sortKey: 'product',
            primary: true,
            cell: (r) => (
                <span className="grid gap-0.5">
                    <Link
                        href={`/produk/${r.product_id}`}
                        className="font-medium break-words hover:underline"
                    >
                        {r.name}
                    </Link>
                    <span className="font-mono text-xs text-muted-foreground">
                        {r.sku}
                    </span>
                </span>
            ),
        },
        {
            key: 'location',
            header: mode === 'per_batch' ? 'Lokasi · rak' : 'Lokasi',
            cell: (r) => (
                <span className="grid gap-0.5">
                    <span>{r.location}</span>
                    {mode === 'per_batch' && (
                        <span className="text-xs text-muted-foreground">
                            {r.bin ? `Rak ${r.bin}` : 'Tanpa rak'}
                        </span>
                    )}
                </span>
            ),
        },
        ...(mode === 'per_batch'
            ? [
                  {
                      key: 'batch',
                      header: 'Batch',
                      cell: (r: StockPositionRow) => (
                          <span className="grid gap-0.5">
                              <span className="font-mono text-sm">
                                  {r.batch_no}
                              </span>
                              {r.expiry_date && (
                                  <span className="text-xs text-muted-foreground">
                                      ED {formatCivilDate(r.expiry_date)}
                                  </span>
                              )}
                          </span>
                      ),
                  },
                  {
                      key: 'status',
                      header: 'Status',
                      cell: (r: StockPositionRow) => (
                          <StatusBadge
                              status={`stock_batch.${r.status}`}
                              label={r.status_label}
                          />
                      ),
                  },
              ]
            : [
                  {
                      key: 'next_expiry',
                      header: 'ED terdekat',
                      cell: (r: StockPositionRow) =>
                          r.next_expiry ? formatCivilDate(r.next_expiry) : '–',
                  },
              ]),
        {
            key: 'on_hand',
            header: 'Fisik',
            className: 'text-right tabular-nums',
            cell: (r) => `${formatQty(r.qty_on_hand)} ${unit(r)}`,
        },
        {
            key: 'available',
            header: 'Tersedia',
            className: 'text-right tabular-nums',
            cell: (r) => `${formatQty(r.qty_available)} ${unit(r)}`,
        },
        ...(mode === 'per_product'
            ? [
                  {
                      key: 'held',
                      header: 'Karantina · ED lewat',
                      className: 'text-right tabular-nums',
                      cell: (r: StockPositionRow) =>
                          `${formatQty(r.qty_quarantine ?? '0')} · ${formatQty(r.qty_expired ?? '0')}`,
                  },
              ]
            : []),
        ...(canViewCost
            ? [
                  {
                      key: 'value',
                      header: 'Nilai (HPP)',
                      className: 'text-right tabular-nums',
                      cell: (r: StockPositionRow) => (
                          <span className="grid gap-0.5">
                              <span>{formatRupiah(r.value ?? '0')}</span>
                              <span className="text-xs text-muted-foreground">
                                  @ {formatRupiah(r.avg_cost ?? '0')}
                              </span>
                          </span>
                      ),
                  },
              ]
            : []),
    ];

    return (
        <>
            <Head title="Posisi Stok" />
            <div className="mx-auto w-full max-w-[1440px] space-y-4 p-4 md:px-7 md:py-6">
                <div>
                    <h1 className="text-[1.4rem] leading-tight font-bold">
                        Posisi Stok
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Saldo per produk per lokasi, atau per batch lewat filter
                        Tampilan. Tersedia = fisik dikurangi yang sudah dipesan,
                        dikarantina, atau lewat ED.
                    </p>
                </div>

                <DataTable
                    rows={rows}
                    listing={listing}
                    columns={columns}
                    rowKey={(r) => r.key}
                    title="Posisi stok"
                    toolbarActions={
                        canExport && (
                            <ExportButton
                                href="/stok/ekspor"
                                total={listing.pagination.total}
                            />
                        )
                    }
                />
            </div>
        </>
    );
}

StockPositions.layout = {
    breadcrumbs: [{ title: 'Posisi Stok', href: '/stok' }],
};
